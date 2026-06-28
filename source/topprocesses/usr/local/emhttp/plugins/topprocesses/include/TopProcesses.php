<?php
/* Top Processes — populates $mytiles for the Dashboard tile.
 *
 * CRITICAL: a dashboard tile must contain NO nested <tbody> (and no inner
 * <table>). Unraid's addProperties() iterates every <tbody> in the dashboard
 * and calls .md5() on its title; a nested tbody without a title throws and
 * blanks the whole dashboard. So the tile is a single <tbody>; process rows
 * are plain <div>s. CSS and JS are inlined (no autov, no _(), no .page body
 * output) so this can never break the page render. */

global $mytiles;

$base = '/usr/local/emhttp/plugins/topprocesses';

$cfg      = @parse_ini_file('/boot/config/plugins/topprocesses/topprocesses.cfg') ?: [];
$topN     = (int) ($cfg['TOPN'] ?? 8);
$sort     = (($cfg['SORT'] ?? 'cpu') === 'mem') ? 'mem' : 'cpu';
$interval = (int) ($cfg['INTERVAL'] ?? 5);
$subtitle = $sort === 'cpu' ? 'by CPU' : 'by MEM';

$css = (string) @file_get_contents("$base/styles/topprocesses.css");
$js  = (string) @file_get_contents("$base/javascript/topprocesses.js");

$mytiles['topprocesses']['column1'] = <<<EOT
<tbody title="Top Processes" id="tp_tile" data-topn="$topN" data-sort="$sort" data-interval="$interval">
<tr><td>
<style>$css</style>
<span class="tile-header">
<span class="tile-header-left">
<i class="fa fa-tasks tp-glyph"></i>
<div class="section">
<h3 class="tile-header-main" title="Top processes by CPU/MEM. 100% = one full core (htop-style).">Top Processes</h3>
<span id="tp_subtitle" class="tp-subtitle">$subtitle</span>
</div>
</span>
<span class="tile-header-right">
<span class="tile-header-right-controls">
<span id="tp_sort" class="tp-toggle" title="Click a metric to sort by it; click the active one again to reverse"><button type="button" data-k="cpu">CPU</button><button type="button" data-k="mem">MEM</button></span>
<select id="tp_interval" class="tp-interval" title="Refresh interval"><option value="2">2s</option><option value="5">5s</option><option value="10">10s</option><option value="0">off</option></select>
<a href="/Settings/TopProcessesSettings" title="Settings"><i class="fa fa-fw fa-cog control"></i></a>
</span>
</span>
</span>
<div id="tp_rows" class="tp-rows"><div class="tp-empty">loading…</div></div>
<script>$js</script>
</td></tr>
</tbody>
EOT;
