<?php

/**
 * @file classes/Migration.inc.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Migration
 * @ingroup plugins_generic_fullTextSearch
 *
 * @brief Create and upgrade the full-text search index table.
 */

namespace APP\plugins\generic\fullTextSearch\classes;

use APP\plugins\generic\fullTextSearch\FullTextSearchPlugin;
use Application;
use Doctrine\DBAL\Schema\Index;
use Exception;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\Migration as BaseMigration;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;

class Migration extends BaseMigration
{
	/**
	 * Schema version.
	 */
	private const SCHEMA_VERSION = 1;

	/** @var FullTextSearchPlugin */
	private $plugin;

	public function __construct(FullTextSearchPlugin $plugin)
	{
		$this->plugin = $plugin;
	}

	/**
	 * Create the index table, or add missing indexes and foreign keys on an existing one.
	 */
	public function up(): void
	{
		$table = Dao::TABLE_NAME;
		if ((int) $this->plugin->getSetting(CONTEXT_SITE, 'schemaVersion') >= self::SCHEMA_VERSION) {
			return;
		}

		if (!Manager::schema()->hasTable($table)) {
			$this->createTable($table);
		} else {
			$this->ensureRelationalKeys($table);
		}

		$this->plugin->updateSetting(CONTEXT_SITE, 'schemaVersion', self::SCHEMA_VERSION, 'int');
	}

	/**
	 * Create the index table, including indexes and foreign keys
	 */
	private function createTable(string $table): void
	{
		[$contextTable, $contextColumn] = $this->contextReference();
		Manager::schema()->create($table, function (Blueprint $table) use ($contextTable, $contextColumn) {
			$table->bigIncrements('id');
			$table->bigInteger('context_id')->index();
			$table->foreign('context_id')
				->references($contextColumn)
				->on($contextTable)
				->onDelete('cascade');
			$table->bigInteger('submission_id')->unique();
			$table->foreign('submission_id')
				->references('submission_id')
				->on('submissions')
				->onDelete('cascade');
			$table->text('title')->nullable();
			$table->text('abstract')->nullable();
			$table->text('authors')->nullable();
			$table->text('keywords')->nullable();
			$table->text('subjects')->nullable();
			$table->text('disciplines')->nullable();
			$table->text('coverage')->nullable();
			$table->longText('galley_text')->nullable();
			$table->text('type')->nullable();
			$table->timestamp('created_at')->nullable();
			$table->timestamp('updated_at')->nullable();
		});
		$indexFormat = Manager::connection() instanceof PostgresConnection
			? "CREATE INDEX {$table}_%s ON {$table} USING GIN (to_tsvector('simple', coalesce(%s,'')))"
			: "ALTER TABLE {$table} ADD FULLTEXT {$table}_%s (%s)";
		foreach (['title', 'abstract', 'authors', 'keywords', 'subjects', 'disciplines', 'coverage', 'galley_text', 'type'] as $field) {
			Manager::statement(sprintf($indexFormat, $field, $field));
		}
	}

	/**
	 * Add the context index and foreign keys on a table created before they existed.
	 */
	private function ensureRelationalKeys(string $table): void
	{
		[$contextTable, $contextColumn] = $this->contextReference();
		if (!$this->hasColumnIndex($table, 'context_id')) {
			Manager::schema()->table($table, function (Blueprint $blueprint) {
				$blueprint->index('context_id');
			});
		}
		$this->retryConstraint(
			function () use ($table, $contextTable, $contextColumn) {
				if ($this->hasForeignKey($table, 'context_id', $contextTable, $contextColumn)) {
					return;
				}
				Manager::schema()->table($table, function (Blueprint $blueprint) use ($contextTable, $contextColumn) {
					$blueprint->foreign('context_id')
						->references($contextColumn)
						->on($contextTable)
						->onDelete('cascade');
				});
			},
			function () use ($table, $contextTable, $contextColumn) {
				return $this->deleteOrphanRows($table, 'context_id', $contextTable, $contextColumn);
			}
		);
		$this->retryConstraint(
			function () use ($table) {
				if ($this->hasForeignKey($table, 'submission_id', 'submissions', 'submission_id')) {
					return;
				}
				Manager::schema()->table($table, function (Blueprint $blueprint) {
					$blueprint->foreign('submission_id')
						->references('submission_id')
						->on('submissions')
						->onDelete('cascade');
				});
			},
			function () use ($table) {
				return $this->deleteOrphanRows($table, 'submission_id', 'submissions', 'submission_id');
			}
		);
	}

	/**
	 * Apply a constraint. On failure, run the cleanup and try again while it still removes rows.
	 */
	private function retryConstraint(callable $apply, callable $cleanup): void
	{
		while (true) {
			try {
				$apply();
				break;
			} catch (Exception $e) {
				if (!$cleanup()) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Whether an index already covers this column. A unique index satisfies a non-unique lookup.
	 */
	private function hasColumnIndex(string $table, string $column): bool
	{
		$expected = new Index($column, [$column]);
		foreach (Manager::connection()->getDoctrineSchemaManager()->listTableIndexes($table) as $index) {
			if ($expected->isFullfilledBy($index)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a foreign key already covers this column.
	 */
	private function hasForeignKey(string $table, string $column, string $referencedTable, string $referencedColumn): bool
	{
		foreach (Manager::connection()->getDoctrineSchemaManager()->listTableForeignKeys($table) as $foreignKey) {
			$local = array_map('strtolower', $foreignKey->getUnquotedLocalColumns());
			$foreign = array_map('strtolower', $foreignKey->getUnquotedForeignColumns());
			if ($local === [strtolower($column)]
				&& $foreign === [strtolower($referencedColumn)]
				&& strtolower($foreignKey->getUnqualifiedForeignTableName()) === strtolower($referencedTable)
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Context table and primary key for this application (presses.press_id, journals.journal_id, ...)
	 * @return array{0:string,1:string}
	 */
	private function contextReference(): array
	{
		$contextDao = Application::getContextDAO();
		return [$contextDao->tableName, $contextDao->primaryKeyColumn];
	}

	/**
	 * Delete one batch of index rows that reference a missing parent
	 */
	private function deleteOrphanRows(string $table, string $column, string $referencedTable, string $referencedColumn): int
	{
		$ids = Manager::table($table)
			->whereNotIn($column, function (Builder $query) use ($referencedTable, $referencedColumn) {
				$query->select($referencedColumn)->from($referencedTable);
			})
			->orderBy('id')
			->limit(1000)
			->pluck('id');
		return $ids->isEmpty() ? 0 : Manager::table($table)->whereIn('id', $ids->all())->delete();
	}
}
