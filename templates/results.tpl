{**
 * plugins/importexport/rsciexport/templates/results.tpl
 *
 * The "Export results" tab: the report of this export and the button to download the archive.
 * The same idea as the core "Native XML" plugin (plugins/importexport/resultsExport.tpl).
 *}
<div class="pkp_form">
	{if $issueId}
		<p><strong>{translate key="plugins.importexport.rsciexport.results.ready" issue=$issueIdentification|escape}</strong></p>

		{include file=$reportTemplate problems=$problems}

		{* A plain form: it only downloads the file (with the OJS form handler the spinner
		   next to the button would keep spinning after the download). *}
		<form class="pkp_form" action="{plugin_url path="downloadExportFile"}" method="post">
			{csrf}
			<input type="hidden" name="issueId" value="{$issueId|escape}" />
			<input type="hidden" name="exportedFileDatePart" value="{$exportedFileDatePart|escape}" />
			{* Several results tabs can be open: unique ids, as in the core template *}
			{assign var="uuid" value=""|uniqid|escape}
			{fbvFormArea id="rsciexportDownload-$uuid"}
				{fbvFormButtons submitText="plugins.importexport.rsciexport.results.download" hideCancel="true"}
			{/fbvFormArea}
		</form>
	{else}
		<p>{translate key="plugins.importexport.rsciexport.export.selectIssue"}</p>
	{/if}
</div>
