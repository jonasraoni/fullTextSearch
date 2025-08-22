{**
 * templates/settings.tpl
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings page for Full Text Search Plugin
 *}
<script>
	$(function () {ldelim}
		$('#fullTextSearchSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});

	document.getElementById('selectAllContexts').addEventListener('change', function() {ldelim}
		const checkboxes = document.querySelectorAll('input[name="selectedContexts[]"]');
		checkboxes.forEach(checkbox => checkbox.checked = this.checked);
	{rdelim});
</script>

<form class="pkp_form" id="fullTextSearchSettings" method="POST" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}

	<div class="section">
		<h2>{translate key="plugins.generic.fullTextSearch.settings.rebuildButton"}</h2>
		<p>{translate key="plugins.generic.fullTextSearch.settings.selectContextsDescription"}</p>

		<div class="form-group">
			<label>
				<input type="checkbox" id="selectAllContexts" name="selectAllContexts">
				{translate key="common.selectAll"}
			</label>
		</div>

		<div class="form-group">
			{foreach from=$contexts key=contextId item=contextName}
				<div class="checkbox">
					<label>
						<input type="checkbox" name="selectedContexts[]" value="{$contextId}">
						{$contextName}
					</label>
				</div>
			{/foreach}
		</div>
	</div>

	<div class="section">
		<h2>{translate key="plugins.generic.fullTextSearch.settings.clearStandardSearch"}</h2>
		<p>{translate key="plugins.generic.fullTextSearch.settings.clearStandardSearchDescription"}</p>

		<div class="form-group">
			<label>
				<input type="checkbox" id="clearStandardSearch" name="clearStandardSearch">
				{translate key="plugins.generic.fullTextSearch.settings.clearStandardSearchLabel"}
			</label>
		</div>
	</div>

	<div class="form_buttons">
		<button class="pkp_button pkp_button_primary" type="submit">
			{translate key="plugins.generic.fullTextSearch.settings.rebuildButton"}
		</button>
	</div>
</form>

<style>
.pkp_form_file_view {
	padding: 20px;
}

.pkp_form_file_view h3 {
	margin-bottom: 15px;
	color: #333;
}

.pkp_form_file_view h4 {
	margin: 20px 0 10px 0;
	color: #555;
}

.section {
	margin: 20px 0;
	padding: 15px;
	border: 1px solid #ddd;
	border-radius: 4px;
	background-color: #f9f9f9;
}

.form-group {
	margin: 15px 0;
}

.checkbox {
	margin: 8px 0;
}

.checkbox label {
	display: flex;
	align-items: center;
	cursor: pointer;
	font-weight: normal;
}

.checkbox input[type="checkbox"] {
	margin-right: 8px;
}

.form_buttons {
	margin-top: 20px;
	padding-top: 15px;
	border-top: 1px solid #ddd;
}

.pkp_button {
	padding: 8px 16px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
}

.pkp_button_primary {
	background-color: #007cba;
	color: white;
}

.pkp_button_primary:hover {
	background-color: #005a87;
}
</style>
