<?php
/* Top Processes — builds the Dashboard tile HTML into the supported
 * $mytiles[...]['column1'] slot. Echoed by dynamix customTiles() inside
 * <table class='dashboard'>. Defaults come from the flash cfg. */

$cfg      = @parse_ini_file('/boot/config/plugins/topprocesses/topprocesses.cfg') ?: [];
$topN     = (int) ($cfg['TOPN'] ?? 8);
$sort     = (($cfg['SORT'] ?? 'cpu') === 'mem') ? 'mem' : 'cpu';
$interval = (int) ($cfg['INTERVAL'] ?? 5);

$subtitle = $sort === 'cpu' ? _('by CPU') : _('by MEM');

$pluginname = 'topprocesses';
$mytiles[$pluginname]['column1'] = "
<tbody title='"._('Top Processes')."' id='tp_tile'
       data-topn='$topN' data-sort='$sort' data-interval='$interval'>
  <tr><td>
    <span class='tile-header'>
      <span class='tile-header-left'>
        <i class='fa fa-tasks tp-glyph'></i>
        <div class='section'>
          <h3 class='tile-header-main'>"._('Top Processes')."</h3>
          <span id='tp_subtitle' class='tp-subtitle'>$subtitle</span>
        </div>
      </span>
      <span class='tile-header-right'>
        <span class='tile-header-right-controls'>
          <span id='tp_sort' class='tp-toggle' title='"._('Toggle CPU / MEM sort')."'>
            <a data-k='cpu'>CPU</a><a data-k='mem'>MEM</a>
          </span>
          <select id='tp_interval' class='tp-interval' title='"._('Refresh interval')."'>
            <option value='2'>2s</option>
            <option value='5'>5s</option>
            <option value='10'>10s</option>
            <option value='0'>"._('off')."</option>
          </select>
          <a href='/Settings/TopProcessesSettings' title='"._('Settings')."'>
            <i class='fa fa-fw fa-cog control'></i>
          </a>
        </span>
      </span>
    </span>
  </td></tr>
  <tr><td>
    <table class='tp-table'><tbody id='tp_rows'>
      <tr><td colspan='4' class='tp-empty'>"._('loading…')."</td></tr>
    </tbody></table>
  </td></tr>
</tbody>";
