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

	<div class="description">
		<p>{translate key="plugins.generic.fullTextSearch.settings.description"}</p>
	</div>

	{fbvFormSection title="navigation.settings" list="true"}
		{fbvElement type="checkbox" id="useFullTextSearch" checked=$useFullTextSearch label="plugins.generic.fullTextSearch.settings.useFullTextSearch" translate="true"}
		{fbvElement type="checkbox" id="disableStandardIndexing" checked=$disableStandardIndexing label="plugins.generic.fullTextSearch.settings.disableStandardIndexing" translate="true"}
	{/fbvFormSection}

	{fbvFormSection title="plugins.generic.fullTextSearch.settings.rebuildButton" list="true"}
		<p>{translate key="plugins.generic.fullTextSearch.settings.selectContextsDescription"}</p>

		{fbvElement type="checkbox" id="selectAllContexts" name="selectAllContexts" label="common.selectAll"}
		{fbvElement type="checkboxgroup" name="selectedContexts" id="selectedContexts" from=$contexts selected=[] translate=false}
	{/fbvFormSection}

	{fbvFormSection title="plugins.generic.fullTextSearch.settings.clearStandardSearch" list="true"}
		<p>{translate key="plugins.generic.fullTextSearch.settings.clearStandardSearchDescription"}</p>

		{fbvElement type="checkbox" id="clearStandardSearch" checked=$clearStandardSearch label="plugins.generic.fullTextSearch.settings.clearStandardSearchLabel" translate="true"}
	{/fbvFormSection}

	{fbvFormButtons submitText="common.save"}
</form>

<style>
.section {
	margin: 20px 0;
	padding: 15px;
	border: 1px solid #ddd;
	border-radius: 4px;
	background-color: #f9f9f9;
}

.description {
	background-color: #e7f3ff;
	padding: 15px;
	border-radius: 4px;
	border-left: 4px solid #007cba;
}
</style>
