<?php
/* Top Processes — JSON data endpoint (stateful, low-cost).
 *
 * %CPU is computed as a delta of utime+stime against the PREVIOUS sample stored
 * in a tmpfs snapshot, over the real elapsed time — so a normal request does a
 * SINGLE /proc walk and NO blocking usleep (only a one-shot 150 ms sample on a
 * cold start when there is no prior snapshot). RSS comes from field 24 of
 * /proc/<pid>/stat (no extra statm read). The same tmpfs file also caches the
 * rendered JSON for ~1s so concurrent dashboard viewers share one sampler.
 * A deliberate request-driven design — no background daemon (which would burn
 * CPU 24/7 even when nobody is looking). Read-only. PHP 8.3/8.4 clean. */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfg  = @parse_ini_file('/boot/config/plugins/topprocesses/topprocesses.cfg') ?: [];
$topN = (int) ($cfg['TOPN'] ?? 8);
if ($topN < 1)  { $topN = 8; }
if ($topN > 50) { $topN = 50; }

// Show kernel threads by default — on Unraid the CPU is often pegged by kernel
// threads (ZFS z_*/txg_sync, md parity, kcompactd, kworker), so hiding them
// would hide the real culprit. Set KTHREADS="0" to show only userspace.
$showKt = (($cfg['KTHREADS'] ?? '1') !== '0');

$CACHE       = "/dev/shm/topprocesses-$topN-" . ($showKt ? 'k' : 'u') . ".json";
$RESULT_TTL  = 1.0;   // serve the rendered JSON without resampling within this window
$SNAP_MAXAGE = 15.0;  // older snapshot -> treat as cold start (brief instantaneous sample)

$now = microtime(true);

$state = null;
$raw = @file_get_contents($CACHE);
if ($raw !== false && $raw !== '') {
    $d = json_decode($raw, true);
    if (is_array($d)) { $state = $d; }
}
/* still-fresh rendered JSON: return it untouched (no sampling) */
if ($state && isset($state['ts'], $state['json']) && ($now - $state['ts']) < $RESULT_TTL) {
    echo $state['json'];
    exit;
}

/* aggregate cpu jiffies (excl. guest/guest_nice) + cpu count + idle + iowait */
function cpu_snapshot(): array {
    $total = 0; $ncpu = 0; $idle = 0; $iowait = 0;
    foreach (explode("\n", (string) @file_get_contents('/proc/stat')) as $l) {
        if (strncmp($l, 'cpu', 3) !== 0) { break; }      // cpu* lines come first
        if (isset($l[3]) && $l[3] === ' ') {              // aggregate "cpu " line
            $f = preg_split('/\s+/', trim($l));
            array_shift($f);
            $n = min(8, count($f));
            for ($i = 0; $i < $n; $i++) { $total += (int) $f[$i]; }
            $idle   = (int) ($f[3] ?? 0);                 // idle
            $iowait = (int) ($f[4] ?? 0);                 // iowait
        } else {
            $ncpu++;                                      // cpuN
        }
    }
    return [$total, max(1, $ncpu), $idle, $iowait];
}

/* one /proc walk -> pid => [comm, j(=utime+stime), rss(KiB)] */
function walk_procs(bool $showKt): array {
    $out = [];
    $dh = @opendir('/proc');
    if (!$dh) { return $out; }
    while (($d = readdir($dh)) !== false) {
        if (!ctype_digit($d)) { continue; }
        $stat = @file_get_contents("/proc/$d/stat");
        if ($stat === false) { continue; }
        $rp = strrpos($stat, ')');
        if ($rp === false) { continue; }
        $rest = explode(' ', trim(substr($stat, $rp + 1)));
        // idx0=state(f3); flags(f9)=6; utime(f14)=11; stime(f15)=12; rss(f24)=21
        if (!isset($rest[21])) { continue; }
        if (!$showKt && (((int) $rest[6]) & 0x00200000)) { continue; }  // PF_KTHREAD
        $lp = strpos($stat, '(');
        $comm = ($lp !== false && $rp > $lp) ? substr($stat, $lp + 1, $rp - $lp - 1) : '?';
        $out[$d] = ['comm' => $comm, 'j' => (int) $rest[11] + (int) $rest[12], 'rss' => (int) $rest[21] * 4];
    }
    closedir($dh);
    return $out;
}

[$total1, $ncpu, $idle1, $iowait1] = cpu_snapshot();
$cur = walk_procs($showKt);

$cpu = [];
$haveSnap = $state && isset($state['per'], $state['total'], $state['ts'])
         && ($now - $state['ts']) > 0.2 && ($now - $state['ts']) < $SNAP_MAXAGE;

if ($haveSnap) {
    $dtotal  = max(1, $total1 - (int) $state['total']);
    $didle   = $idle1   - (int) ($state['idle']   ?? $idle1);
    $diowait = $iowait1 - (int) ($state['iowait'] ?? $iowait1);
    $prev = $state['per'];
    foreach ($cur as $pid => $info) {
        $p0 = isset($prev[$pid]) ? (int) $prev[$pid] : $info['j'];   // new pid -> 0 this round
        $c = 100.0 * ($info['j'] - $p0) / $dtotal * $ncpu;
        $cpu[$pid] = $c > 0 ? round($c, 1) : 0.0;
    }
} else {
    usleep(150000); // cold start only: one short instantaneous sample
    [$total2, , $idle2, $iowait2] = cpu_snapshot();
    $cur2 = walk_procs($showKt);
    $dtotal  = max(1, $total2 - $total1);
    $didle   = $idle2   - $idle1;
    $diowait = $iowait2 - $iowait1;
    foreach ($cur2 as $pid => $info) {
        $p0 = isset($cur[$pid]) ? $cur[$pid]['j'] : $info['j'];
        $c = 100.0 * ($info['j'] - $p0) / $dtotal * $ncpu;
        $cpu[$pid] = $c > 0 ? round($c, 1) : 0.0;
    }
    $cur = $cur2;
    $total1 = $total2; $idle1 = $idle2; $iowait1 = $iowait2;
}

/* overall CPU context (matches the Processor tile's "Overall Load") so the list
 * reconciles with it: busy includes iowait; iowait is surfaced separately
 * because it belongs to no process. */
$loadBusy = (int) round(100.0 * max(0, $dtotal - $didle) / $dtotal);
$loadIo   = (int) round(100.0 * max(0, $diowait) / $dtotal);

$memTotalKb = (int) ($state['mem'] ?? 0);
if ($memTotalKb <= 0) {
    preg_match('/MemTotal:\s+(\d+)/', (string) @file_get_contents('/proc/meminfo'), $mt);
    $memTotalKb = max(1, (int) ($mt[1] ?? 0));
}

$rows = [];
foreach ($cur as $pid => $info) {
    $rows[$pid] = ['pid' => (int) $pid, 'cmd' => $info['comm'], 'cpu' => $cpu[$pid] ?? 0.0, 'rss' => $info['rss']];
}

$byCpu = $rows; uasort($byCpu, fn($a, $b) => $b['cpu'] <=> $a['cpu']);
$byMem = $rows; uasort($byMem, fn($a, $b) => $b['rss'] <=> $a['rss']);
$topCpu = array_slice($byCpu, 0, $topN, true);
$topMem = array_slice($byMem, 0, $topN, true);

$userCache = [];
$resolveUser = function (int $pid) use (&$userCache): string {
    $uid = @fileowner("/proc/$pid");
    if ($uid === false) { return '?'; }
    if (isset($userCache[$uid])) { return $userCache[$uid]; }
    $name = (string) $uid;
    if (function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid($uid);
        if (is_array($pw) && isset($pw['name'])) { $name = $pw['name']; }
    }
    return $userCache[$uid] = $name;
};
$fullCmd = function (int $pid, string $fallback): string {
    $cl = @file_get_contents("/proc/$pid/cmdline");
    if ($cl === false || $cl === '') { return $fallback; }
    $cl = trim(str_replace("\0", ' ', $cl));
    if ($cl === '') { return $fallback; }
    if (strlen($cl) > 256) { $cl = substr($cl, 0, 255) . '…'; }
    return $cl;
};

$enriched = [];
foreach (($topCpu + $topMem) as $pid => $r) {     // union by pid key, enrich once
    $r['user'] = $resolveUser((int) $pid);
    $r['full'] = $fullCmd((int) $pid, $r['cmd']);
    $r['mem']  = round(100.0 * $r['rss'] / $memTotalKb, 1);
    $enriched[$pid] = $r;
}
$pick = fn($set) => array_values(array_map(fn($r) => $enriched[$r['pid']], $set));

$json = json_encode([
    'total' => count($rows),
    'load'  => ['busy' => $loadBusy, 'io' => $loadIo],
    'cpu'   => $pick($topCpu),
    'mem'   => $pick($topMem),
]);
echo $json;

/* persist snapshot (per-pid jiffies for the next delta) + rendered JSON, atomically */
$per = [];
foreach ($cur as $pid => $info) { $per[$pid] = $info['j']; }
$blob = json_encode(['ts' => $now, 'total' => $total1, 'idle' => $idle1, 'iowait' => $iowait1, 'ncpu' => $ncpu, 'mem' => $memTotalKb, 'per' => $per, 'json' => $json]);
$tmp = $CACHE . '.' . getmypid();
if (@file_put_contents($tmp, $blob) !== false) { @rename($tmp, $CACHE); } else { @unlink($tmp); }
