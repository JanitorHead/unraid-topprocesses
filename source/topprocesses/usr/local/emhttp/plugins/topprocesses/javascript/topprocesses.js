/* Top Processes — Dashboard tile client.
 * Polls getprocs.php on a user-selectable interval and renders process rows as
 * plain <div>s (no tables/tbodies — those break Unraid's dashboard JS).
 *
 * Features: CPU/MEM metric toggle that doubles as the sort key; click the active
 * metric again to reverse direction (htop "r"); the top consumer is highlighted;
 * absolute RSS shown next to MEM%; honest empty/stale states; basic a11y.
 * Vanilla JS, no deps, no dollar signs (safe to inline from a PHP heredoc). */
(function () {
  'use strict';

  var tile = document.getElementById('tp_tile');
  if (!tile || tile.dataset.tpInit) { return; }
  tile.dataset.tpInit = '1';

  var ENDPOINT = '/plugins/topprocesses/include/getprocs.php';
  var sort     = tile.dataset.sort === 'mem' ? 'mem' : 'cpu';
  var dir      = 'desc';                 // 'desc' (busiest first) | 'asc'
  var interval = parseInt(tile.dataset.interval || '5', 10);
  if (isNaN(interval)) { interval = 5; }

  var timer = null;
  var busy  = false;
  var lastTotal = 0;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
  }

  /* green -> orange -> red, mirroring Unraid's load colouring */
  function level(pct) {
    if (pct >= 90) { return 'crit'; }
    if (pct >= 60) { return 'warn'; }
    return 'ok';
  }

  /* KiB -> human readable */
  function fmtKb(kb) {
    kb = kb || 0;
    if (kb >= 1048576) { return (kb / 1048576).toFixed(1) + ' GiB'; }
    if (kb >= 1024)    { return Math.round(kb / 1024) + ' MiB'; }
    return kb + ' KiB';
  }

  /* one metric line: label, bar, %, and an aligned secondary value (RSS for mem) */
  function metric(pct, kind, extra) {
    var w  = Math.max(0, Math.min(100, pct));
    var lv = level(pct);
    var label = (kind === 'cpu') ? 'CPU' : 'MEM';
    return "<div class='tp-metric'>"
         + "<span class='tp-k'>" + label + "</span>"
         + "<span class='tp-bar' aria-hidden='true'><span class='tp-fill tp-" + kind + " tp-" + lv + "' style='width:" + w + "%'></span></span>"
         + "<span class='tp-pct tp-" + lv + "'>" + pct.toFixed(1) + "%</span>"
         + "<span class='tp-rss'>" + esc(extra || '') + "</span>"
         + "</div>";
  }

  function render(list) {
    var box = document.getElementById('tp_rows');
    if (!box) { return; }
    if (!list || !list.length) {
      box.innerHTML = "<div class='tp-empty'>No active processes</div>";
      return;
    }
    var rows = (dir === 'asc') ? list.slice().reverse() : list;
    var html = '';
    for (var i = 0; i < rows.length; i++) {
      var p = rows[i];
      var top = (i === 0 && dir === 'desc') ? ' tp-top' : '';
      html += "<div class='tp-row" + top + "'>"
            + "<div class='tp-head'>"
            +   "<span class='tp-name' title='" + esc(p.full || p.cmd) + "'>" + esc(p.cmd) + "</span>"
            +   "<span class='tp-user'>" + esc(p.user) + "</span>"
            +   "<span class='tp-pid'>" + esc(p.pid) + "</span>"
            + "</div>"
            + metric(p.cpu, 'cpu', '')
            + metric(p.mem, 'mem', fmtKb(p.rss))
            + "</div>";
    }
    box.innerHTML = html;
  }

  function updateSubtitle() {
    var sub = document.getElementById('tp_subtitle');
    if (!sub) { return; }
    var caret = (dir === 'desc') ? '▾' : '▴';   // ▾ / ▴
    var txt = (sort === 'cpu' ? 'by CPU' : 'by MEM') + ' ' + caret;
    if (lastTotal) { txt += ' · ' + lastTotal + ' processes'; }
    sub.textContent = txt;
  }

  function setStale(on) {
    var box = document.getElementById('tp_rows');
    if (box) { box.classList.toggle('tp-stale', !!on); }
  }

  function poll() {
    if (busy) { return; }
    busy = true;
    fetch(ENDPOINT, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d) {
          if (typeof d.total === 'number') { lastTotal = d.total; }
          render(d[sort] || []);
          updateSubtitle();
          setStale(false);
        } else {
          setStale(true);
        }
      })
      .catch(function () { setStale(true); })
      .then(function () { busy = false; });
  }

  function arm() {
    if (timer) { clearInterval(timer); timer = null; }
    poll();
    if (interval > 0) { timer = setInterval(poll, interval * 1000); }
  }

  function syncToggle() {
    var btns = tile.querySelectorAll('#tp_sort button');
    for (var i = 0; i < btns.length; i++) {
      var on = btns[i].dataset.k === sort;
      btns[i].classList.toggle('tp-on', on);
      btns[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  function onSortClick(k) {
    if (k === sort) {
      dir = (dir === 'desc') ? 'asc' : 'desc';   // reverse when re-clicking the active metric
    } else {
      sort = k;
      dir = 'desc';
    }
    syncToggle();
    updateSubtitle();
    poll();
  }

  var btns = tile.querySelectorAll('#tp_sort button');
  for (var i = 0; i < btns.length; i++) {
    (function (b) {
      b.addEventListener('click', function () { onSortClick(b.dataset.k); });
    })(btns[i]);
  }
  syncToggle();

  var sel = document.getElementById('tp_interval');
  if (sel) {
    sel.value = String(interval);
    sel.addEventListener('change', function () {
      interval = parseInt(sel.value, 10);
      if (isNaN(interval)) { interval = 0; }
      arm();
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (timer) { clearInterval(timer); timer = null; }
    } else {
      arm();
    }
  });

  arm();
})();
