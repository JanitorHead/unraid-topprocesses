/* Top Processes — Dashboard tile client.
 * Keyed in-place DOM updates (bars animate, no flicker, no string-attr XSS).
 * One bar for the active sort metric + quiet absolute RSS. CPU/MEM segmented
 * control doubles as the sort key (click active to reverse). Refresh cadence is
 * a click-to-cycle pill (clear affordance). Polls ONLY while the tile is both
 * tab-visible AND on-screen/expanded (visibilitychange + IntersectionObserver),
 * self-heals if the tile is removed. Vanilla JS, no deps, no dollar signs. */
(function () {
  'use strict';

  var tile = document.getElementById('tp_tile');
  if (!tile || tile.dataset.tpInit) { return; }
  tile.dataset.tpInit = '1';

  var ENDPOINT = '/plugins/topprocesses/include/getprocs.php';
  var STORE    = 'tp_view_' + location.host;
  var ALLOWED  = [2, 5, 10, 0];

  var sort = tile.dataset.sort === 'mem' ? 'mem' : 'cpu';
  var dir  = 'desc';
  var interval = parseInt(tile.dataset.interval || '5', 10);
  if (isNaN(interval)) { interval = 5; }

  try {
    var saved = JSON.parse(localStorage.getItem(STORE) || '{}');
    if (saved.sort === 'cpu' || saved.sort === 'mem') { sort = saved.sort; }
    if (saved.dir === 'asc' || saved.dir === 'desc') { dir = saved.dir; }
    if (typeof saved.interval === 'number' && ALLOWED.indexOf(saved.interval) >= 0) { interval = saved.interval; }
  } catch (e) {}
  if (ALLOWED.indexOf(interval) < 0) { interval = 5; }

  var timer = null, busy = false, lastTotal = 0, lastData = null, box = null;
  var tabVis = !document.hidden, tileVis = true;
  var nodes = Object.create(null);

  function save() { try { localStorage.setItem(STORE, JSON.stringify({ sort: sort, dir: dir, interval: interval })); } catch (e) {} }
  function num(x) { x = Number(x); return isFinite(x) ? x : 0; }
  function level(p) { if (p >= 90) { return 'crit'; } if (p >= 60) { return 'warn'; } return 'ok'; }
  function fmtPct(v) { return (v >= 10) ? Math.round(v) + '%' : v.toFixed(1) + '%'; }
  function fmtKb(kb) { kb = num(kb); if (kb >= 1048576) { return (kb / 1048576).toFixed(1) + 'G'; } if (kb >= 1024) { return Math.round(kb / 1024) + 'M'; } return kb + 'K'; }
  function setText(el, v) { v = String(v == null ? '' : v); if (el.textContent !== v) { el.textContent = v; } }
  function rowsBox() { if (!box) { box = document.getElementById('tp_rows'); } return box; }

  function makeRow() {
    var row = document.createElement('div'); row.className = 'tp-row';
    var head = document.createElement('div'); head.className = 'tp-head';
    var name = document.createElement('span'); name.className = 'tp-name';
    var user = document.createElement('span'); user.className = 'tp-user';
    var pid  = document.createElement('span'); pid.className = 'tp-pid';
    head.appendChild(name); head.appendChild(user); head.appendChild(pid);
    var line = document.createElement('div'); line.className = 'tp-metric';
    var bar  = document.createElement('span'); bar.className = 'tp-bar'; bar.setAttribute('aria-hidden', 'true');
    var fill = document.createElement('span'); fill.className = 'tp-fill'; bar.appendChild(fill);
    var pct  = document.createElement('span'); pct.className = 'tp-pct';
    var sec  = document.createElement('span'); sec.className = 'tp-sec';
    line.appendChild(bar); line.appendChild(pct); line.appendChild(sec);
    row.appendChild(head); row.appendChild(line);
    return { row: row, name: name, user: user, pid: pid, fill: fill, pct: pct, sec: sec };
  }

  function updateRow(n, p, isTop) {
    var active = (sort === 'mem') ? num(p.mem) : num(p.cpu);
    var lv = level(active);
    setText(n.name, p.cmd);
    var title = p.full || p.cmd || '';
    if (n.name.title !== title) { n.name.title = title; }
    setText(n.user, p.user);
    setText(n.pid, p.pid);
    var w = Math.max(0, Math.min(100, active)) + '%';
    if (n.fill.style.width !== w) { n.fill.style.width = w; }
    var fc = 'tp-fill tp-' + lv; if (n.fill.className !== fc) { n.fill.className = fc; }
    setText(n.pct, fmtPct(active));
    var pc = 'tp-pct tp-' + lv; if (n.pct.className !== pc) { n.pct.className = pc; }
    setText(n.sec, fmtKb(p.rss));
    n.row.classList.toggle('tp-top', !!isTop);
  }

  function render() {
    var b = rowsBox(); if (!b) { return; }
    var list = (lastData && lastData[sort]) ? lastData[sort].slice() : [];
    if (dir === 'asc') { list.reverse(); }
    if (!list.length) {
      b.textContent = '';
      var e = document.createElement('div'); e.className = 'tp-empty'; e.textContent = 'No active processes';
      b.appendChild(e);
      for (var k in nodes) { delete nodes[k]; }
      return;
    }
    var empty = b.querySelector('.tp-empty'); if (empty) { b.removeChild(empty); }

    var maxI = 0, maxV = -1;
    for (var i = 0; i < list.length; i++) {
      var v = (sort === 'mem') ? num(list[i].mem) : num(list[i].cpu);
      if (v > maxV) { maxV = v; maxI = i; }
    }
    var seen = Object.create(null), prev = null;
    for (i = 0; i < list.length; i++) {
      var p = list[i], key = String(p.pid); seen[key] = 1;
      var n = nodes[key]; if (!n) { n = makeRow(); nodes[key] = n; }
      updateRow(n, p, i === maxI);
      var ref = prev ? prev.nextSibling : b.firstChild;
      if (ref !== n.row) { b.insertBefore(n.row, ref); }
      prev = n.row;
    }
    for (var key2 in nodes) {
      if (!seen[key2]) {
        if (nodes[key2].row.parentNode) { nodes[key2].row.parentNode.removeChild(nodes[key2].row); }
        delete nodes[key2];
      }
    }
  }

  function updateSubtitle() { var s = document.getElementById('tp_subtitle'); if (s) { setText(s, lastTotal ? (lastTotal + ' processes') : ''); } }
  function setStale(on) { var b = rowsBox(); if (b) { b.classList.toggle('tp-stale', !!on); } }

  function poll() {
    if (!document.body.contains(tile)) { teardown(); return; }
    if (busy) { return; }
    busy = true;
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var to = ctrl ? setTimeout(function () { ctrl.abort(); }, Math.min(Math.max((interval || 5) * 1000 - 250, 3000), 10000)) : null;
    fetch(ENDPOINT, { cache: 'no-store', credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d) {
          if (typeof d.total === 'number') { lastTotal = d.total; }
          lastData = d; render(); updateSubtitle(); setStale(false);
        } else { setStale(true); }
      })
      .catch(function () { setStale(true); })
      .then(function () { if (to) { clearTimeout(to); } busy = false; });
  }

  /* run only while tab-visible AND tile on-screen/expanded, and interval > 0 */
  function shouldRun() { return tabVis && tileVis && interval > 0; }
  function reschedule() {
    if (timer) { clearInterval(timer); timer = null; }
    if (shouldRun()) { poll(); timer = setInterval(poll, interval * 1000); }
  }

  function syncToggle() {
    var btns = tile.querySelectorAll('#tp_sort button');
    for (var i = 0; i < btns.length; i++) {
      var on = btns[i].dataset.k === sort;
      var base = btns[i].dataset.k === 'cpu' ? 'CPU' : 'MEM';
      setText(btns[i], on ? (base + ' ' + (dir === 'desc' ? '▾' : '▴')) : base);
      btns[i].classList.toggle('tp-on', on);
      btns[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }
  function onSortClick(k) {
    if (k === sort) { dir = (dir === 'desc') ? 'asc' : 'desc'; }
    else { sort = k; dir = 'desc'; }
    syncToggle(); render(); updateSubtitle(); save(); poll();
  }

  var pill = document.getElementById('tp_int'), pillv = document.getElementById('tp_int_v');
  function syncPill() {
    if (pillv) { setText(pillv, interval > 0 ? interval + 's' : 'off'); }
    if (pill) { pill.classList.toggle('tp-off', interval === 0); pill.setAttribute('aria-label', 'Refresh: ' + (interval > 0 ? interval + ' seconds' : 'off')); }
  }

  function onVis() { tabVis = !document.hidden; reschedule(); }
  function teardown() {
    if (timer) { clearInterval(timer); timer = null; }
    document.removeEventListener('visibilitychange', onVis);
    if (io) { try { io.disconnect(); } catch (e) {} }
  }

  var btns = tile.querySelectorAll('#tp_sort button');
  for (var i = 0; i < btns.length; i++) {
    (function (b) { b.addEventListener('click', function () { onSortClick(b.dataset.k); }); })(btns[i]);
  }
  syncToggle();

  if (pill) {
    syncPill();
    pill.addEventListener('click', function () {
      var idx = ALLOWED.indexOf(interval);
      interval = ALLOWED[(idx + 1) % ALLOWED.length];
      if (idx < 0) { interval = 5; }
      syncPill(); save(); reschedule();
    });
  }

  /* pause when the rows are collapsed (native chevron -> height 0) or scrolled off-screen */
  var io = null;
  if (typeof IntersectionObserver !== 'undefined') {
    io = new IntersectionObserver(function (entries) {
      var vis = !!(entries[0] && entries[0].isIntersecting);
      if (vis !== tileVis) { tileVis = vis; reschedule(); }
    });
    var rb = rowsBox(); if (rb) { io.observe(rb); }
  }

  document.addEventListener('visibilitychange', onVis);
  reschedule();
})();
