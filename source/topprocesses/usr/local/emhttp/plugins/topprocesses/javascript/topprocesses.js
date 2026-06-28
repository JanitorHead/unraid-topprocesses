/* Top Processes — Dashboard tile client.
 * Polls getprocs.php on a user-selectable interval, renders rows with
 * grey-track/green-fill mini bars, handles the CPU/MEM sort toggle and the
 * interval selector, and pauses while the tab is hidden. Vanilla JS, no deps. */
(function () {
  'use strict';

  var tile = document.getElementById('tp_tile');
  if (!tile || tile.dataset.tpInit) { return; }
  tile.dataset.tpInit = '1';

  var ENDPOINT = '/plugins/topprocesses/include/getprocs.php';
  var sort     = tile.dataset.sort === 'mem' ? 'mem' : 'cpu';
  var interval = parseInt(tile.dataset.interval || '5', 10);
  if (isNaN(interval)) { interval = 5; }

  var timer = null;
  var busy  = false;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
  }

  /* threshold class mirrors Unraid's green -> orange -> red load colouring */
  function level(pct) {
    if (pct >= 90) { return 'crit'; }
    if (pct >= 60) { return 'warn'; }
    return 'ok';
  }

  function bar(pct, kind) {
    var w  = Math.max(0, Math.min(100, pct));
    var lv = level(pct);
    return "<div class='tp-bar'>"
         + "<span class='tp-fill tp-" + kind + " tp-" + lv + "' style='width:" + w + "%'></span>"
         + "<em class='tp-" + lv + "'>" + pct.toFixed(1) + "%</em>"
         + "</div>";
  }

  function render(list) {
    var body = document.getElementById('tp_rows');
    if (!body) { return; }
    if (!list || !list.length) {
      body.innerHTML = "<tr><td colspan='4' class='tp-empty'>—</td></tr>";
      return;
    }
    var html = '';
    for (var i = 0; i < list.length; i++) {
      var p = list[i];
      html += "<tr>"
            + "<td class='tp-name' title='" + esc(p.cmd) + "'>" + esc(p.cmd) + "</td>"
            + "<td class='tp-user'>" + esc(p.user) + "</td>"
            + "<td class='tp-pid'>" + esc(p.pid) + "</td>"
            + "<td class='tp-bars'>" + bar(p.cpu, 'cpu') + bar(p.mem, 'mem') + "</td>"
            + "</tr>";
    }
    body.innerHTML = html;
  }

  function poll() {
    if (busy) { return; }
    busy = true;
    fetch(ENDPOINT, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d) { render(d[sort] || []); } })
      .catch(function () { /* transient; next tick retries */ })
      .then(function () { busy = false; });
  }

  function arm() {
    if (timer) { clearInterval(timer); timer = null; }
    poll();
    if (interval > 0) { timer = setInterval(poll, interval * 1000); }
  }

  function setSort(k) {
    sort = (k === 'mem') ? 'mem' : 'cpu';
    var links = tile.querySelectorAll('#tp_sort a');
    for (var i = 0; i < links.length; i++) {
      links[i].classList.toggle('tp-on', links[i].dataset.k === sort);
    }
    var sub = document.getElementById('tp_subtitle');
    if (sub) { sub.textContent = (sort === 'cpu') ? 'by CPU' : 'by MEM'; }
    poll();
  }

  /* wire the CPU/MEM toggle */
  var toggle = tile.querySelectorAll('#tp_sort a');
  for (var i = 0; i < toggle.length; i++) {
    (function (a) {
      a.addEventListener('click', function () { setSort(a.dataset.k); });
    })(toggle[i]);
  }
  setSort(sort); // set initial active state

  /* wire the interval selector */
  var sel = document.getElementById('tp_interval');
  if (sel) {
    sel.value = String(interval);
    sel.addEventListener('change', function () {
      interval = parseInt(sel.value, 10);
      if (isNaN(interval)) { interval = 0; }
      arm();
    });
  }

  /* pause polling when the tab is hidden (avoids needless load / leaks) */
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (timer) { clearInterval(timer); timer = null; }
    } else {
      arm();
    }
  });

  arm();
})();
