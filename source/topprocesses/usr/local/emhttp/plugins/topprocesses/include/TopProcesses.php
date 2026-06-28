<?php
/* Top Processes — populates $mytiles for the Dashboard tile.
 *
 * The tile is a single <tbody> with TWO <tr> rows: the header row and the data
 * row. Unraid's native "Show/Hide content" chevron hides tr:gt(0), so the data
 * row collapses like every native tile (md5-keyed state persists). Process rows
 * are plain <div>s — NO nested <table>/<tbody> (that breaks addProperties()).
 * CSS/JS are inlined; strings go through _() when available (i18n, safe to
 * interpolate — it just returns a string). */

global $mytiles;

$base = '/usr/local/emhttp/plugins/topprocesses';

$cfg      = @parse_ini_file('/boot/config/plugins/topprocesses/topprocesses.cfg') ?: [];
$sort     = (($cfg['SORT'] ?? 'cpu') === 'mem') ? 'mem' : 'cpu';
$interval = (int) ($cfg['INTERVAL'] ?? 5);

$css = (string) @file_get_contents("$base/styles/topprocesses.css");
$js  = (string) @file_get_contents("$base/javascript/topprocesses.js");

$tr        = function_exists('_');
$t_title   = $tr ? _('Top Processes') : 'Top Processes';
$t_hint    = $tr ? _('Top processes by CPU/MEM. 100% = one full core (htop-style).') : 'Top processes by CPU/MEM. 100% = one full core (htop-style).';
$t_sort    = $tr ? _('Click a metric to sort by it; click the active one again to reverse') : 'Click a metric to sort by it; click the active one again to reverse';
$t_refresh = $tr ? _('Refresh interval') : 'Refresh interval';
$t_set     = $tr ? _('Settings') : 'Settings';
$t_load    = $tr ? _('loading…') : 'loading…';
$intLabel  = $interval > 0 ? $interval . 's' : 'off';

$mytiles['topprocesses']['column1'] = <<<EOT
<tbody title="$t_title" id="tp_tile" data-sort="$sort" data-interval="$interval">
<tr><td>
<style>$css</style>
<span class="tile-header">
<span class="tile-header-left">
<i class="fa fa-tasks tp-glyph"></i>
<div class="section">
<h3 class="tile-header-main" title="$t_hint">$t_title</h3>
<span id="tp_subtitle" class="tp-subtitle"></span>
</div>
</span>
<span class="tile-header-right">
<span class="tile-header-right-controls">
<span id="tp_sort" class="tp-seg" title="$t_sort"><button type="button" data-k="cpu">CPU</button><button type="button" data-k="mem">MEM</button></span>
<button id="tp_int" class="tp-pill" type="button" title="$t_refresh"><i class="fa fa-refresh"></i><span id="tp_int_v">$intLabel</span></button>
<a href="/Settings/TopProcessesSettings" title="$t_set"><i class="fa fa-fw fa-cog control"></i></a>
</span>
</span>
</span>
</td></tr>
<tr><td>
<div id="tp_rows" class="tp-rows"><div class="tp-empty">$t_load</div></div>
<script>$js</script>
</td></tr>
</tbody>
EOT;
