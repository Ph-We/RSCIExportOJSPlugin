{**
 * plugins/importexport/rsciexport/templates/index.tpl
 *
 * List of operations this plugin can perform
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle}
	</h1>

	<script type="text/javascript">
		// Attach the JS file tab handler.
		$(function() {ldelim}
			$('#importExportTabs').pkpHandler('$.pkp.controllers.TabHandler');
			// The export button opens the "Export results" tab (as in the core "Native XML" plugin).
			$('#exportIssuesXmlForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
			{rdelim});
	</script>

	<div id="importExportTabs">
		<ul>
			<li><a href="#settings-tab">{translate key="plugins.importexport.common.settings"}</a></li>
			<li><a href="#exportIssues-tab">{translate key="plugins.importexport.common.export.issues"}</a></li>
		</ul>

		<div id="settings-tab">
			{capture assign=rsciexportSettingsGridUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.settings.plugins.settingsPluginGridHandler" op="manage" plugin="RSCIExportPlugin" category="importexport" verb="index" escape=false}{/capture}
			{load_url_in_div id="rsciexportSettingsGridContainer" url=$rsciexportSettingsGridUrl}
		</div>

		<div id="exportIssues-tab">
			<form id="exportIssuesXmlForm" class="pkp_form" action="{plugin_url path="exportIssueBounce"}" method="post">
				{csrf}
				{fbvFormArea id="issuesXmlForm"}
					{* One issue: published ones first (the newest preselected), unpublished ones at the bottom, marked *}
					{fbvFormSection label="plugins.importexport.rsciexport.issueSelect.label" for="issueId"}
						{if $selectedIssueId}
							{fbvElement type="select" id="issueId" from=$issueOptions selected=$selectedIssueId translate=false size=$fbvStyles.size.LARGE required=true}
						{else}
							{fbvElement type="select" id="issueId" from=$issueOptions translate=false size=$fbvStyles.size.LARGE required=true defaultValue="" defaultLabel="plugins.importexport.rsciexport.issueSelect.choose"|translate}
						{/if}
					{/fbvFormSection}
				{fbvFormButtons submitText="plugins.importexport.rsciexport.export.issue" hideCancel="true"}
				{/fbvFormArea}
			</form>

			{* Report of the last export: what did not get into the archive and why *}
			{if $lastExportReport}
				<div id="rsciexportLastReport">
					<h3>{translate key="plugins.importexport.rsciexport.report.title"}</h3>
					<p>{translate key="plugins.importexport.rsciexport.report.issue" issue=$lastExportReport.issue|escape date=$lastExportReport.date|date_format:$datetimeFormatShort}</p>
					{include file=$reportTemplate problems=$lastExportReport.problems}
				</div>
			{/if}
		</div>
	</div>
{/block}