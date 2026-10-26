{**
 * plugins/importexport/rsciexport/templates/report.tpl
 *
 * Problems found during an export (see RSCIExportPlugin::createIssueZip()).
 * Shared by the export results tab and the "last export" block of the plugin page.
 *
 * @uses $problems string[]
 *}
{if $problems}
	<ul style="list-style: disc; padding-left: 1.5em;">
		{foreach from=$problems item=problem}
			<li style="margin-bottom: 0.5em;">{$problem|escape}</li>
		{/foreach}
	</ul>
{else}
	<p>{translate key="plugins.importexport.rsciexport.report.noProblems"}</p>
{/if}
