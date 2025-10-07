<!-- Block virtualproductinfo -->
{*{dump($files)}*}
{if !empty($files)}
<div id="virtualproductinfo_block_home" class="block">
	{*<h4>Virtual product <!--content--> details:</h4>*}
	<br/><br/><p><b>Files included:</b></p>
	<div class="block_content">
	{foreach from=$files key=i item=f}
		<p>
		{$f.type}
		{if !empty($f.additional_info)}
			({join(', ', $f.additional_info)})
		{/if}
		- {$f.size}
		{if array_key_exists('compressed_size', $f)} 
			({$f.compressed_size} compressed)
		{/if}
		</p>
			
		{if $i == 0 and count($files) > 1}
			<hr/>
		{/if}
    {/foreach}
	</div>
	<br/>
</div>
{/if}
<!-- /Block virtualproductinfo -->
