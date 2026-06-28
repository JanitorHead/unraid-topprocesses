<?php
/* Top Processes — JSON data endpoint.
 *
 * Two /proc samples ~300 ms apart give htop-style %CPU (Irix: 100% = one core);
 * RSS from statm. Two-phase ranking: a cheap walk collects pid/comm/cpu/rss for
 * every process, then the expensive per-pid work (cmdline + owner lookup) runs
 * ONLY for the union of the top-N-by-CPU and top-N-by-MEM sets (~<=2*N). A short
 * shared cache in tmpfs collapses concurrent dashboard viewers onto one sampler.
 * Read-only, no side effects. PHP 8.3/8.4 clean. */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfg  = @parse_ini_file('/boot/config/plugins/topprocesses/topprocesses.cfg') ?: [];
$topN = (int) ($cfg['TOPN'] ?? 8);
if ($topN < 1)  { $topN = 8; }
if ($topN > 50) { $topN = 50; }

$CACHE = "/dev/shm/topprocesses-$topN.json";
$TTL   = 1.5; // seconds; below the 2s minimum poll, so a lone viewer still gets fresh data

/* serve a still-fresh shared sample if one exists */
$blob = @file_get_contents($CACHE);
if ($blob !== false && $blob !== '') {
    $nl = strpos($blob, "\n");
    if ($nl !== false) {
        $ts = (float) substr($blob, 0, $nl);
        if ($ts > 0 && (microtime(true) - $ts) < $TTL) {
            echo substr($blob, $nl + 1);
            exit;
        }
    }
}

/* fields after comm (which may contain spaces/parens); index 0 = state (field 3) */
function stat_after_comm(string $stat): ?array {
    $rp = strrpos($stat, ')');
    if ($rp === false) { return null; }
    $rest = preg_split('/\s+/', trim(substr($stat, $rp + 1)));
    return $rest ?: null;
}
function stat_comm(string $stat): string {
    $lp = strpos($stat, '(');
    $rp = strrpos($stat, ')');
    if ($lp === false || $rp === false || $rp <= $lp) { return '?'; }
    return substr($stat, $lp + 1, $rp - $lp - 1);
}
/* aggregate cpu jiffies, excluding guest + guest_nice (kernel counts them in user) */
function cpu_total(string $statline): int {
    $p = preg_split('/\s+/', trim($statline));
    array_shift($p);                          // "cpu" label
    if (count($p) > 8) { $p = array_slice($p, 0, 8); }
    $sum = 0;
    foreach ($p as $v) { $sum += (int) $v; }
    return $sum;
}

/* ---- pass 1: cheap walk ---- */
$total0 = cpu_total(strtok((string) @file_get_contents('/proc/stat'), "\n"));
$pids = [];
foreach (scandir('/proc') ?: [] as $d) {
    if (!ctype_digit($d)) { continue; }
    $stat = @file_get_contents("/proc/$d/stat");
    if ($stat === false) { continue; }
    $rest = stat_after_comm($stat);
    if ($rest === null || !isset($rest[12])) { continue; }
    if (((int) ($rest[6] ?? 0)) & 0x00200000) { continue; }   // PF_KTHREAD — skip kernel threads
    $pids[$d] = ['comm' => stat_comm($stat), 'j0' => (int) $rest[11] + (int) $rest[12]];
}

usleep(300000); // 300 ms sampling window

/* ---- pass 2 ---- */
$procstat = (string) @file_get_contents('/proc/stat');
$ncpu = 0; $first = '';
foreach (preg_split('/\n/', $procstat) as $l) {
    if ($first === '' && strncmp($l, 'cpu ', 4) === 0) { $first = $l; }
    if (preg_match('/^cpu\d+\s/', $l)) { $ncpu++; }
}
if ($ncpu < 1) { $ncpu = 1; }
$dtotal = max(1, cpu_total($first) - $total0);

preg_match('/MemTotal:\s+(\d+)/', (string) @file_get_contents('/proc/meminfo'), $mt);
$memTotalKb = max(1, (int) ($mt[1] ?? 0));

$rows = [];
foreach ($pids as $pid => $info) {
    $stat = @file_get_contents("/proc/$pid/stat");
    if ($stat === false) { continue; }
    $rest = stat_after_comm($stat);
    if ($rest === null || !isset($rest[12])) { continue; }
    $cpu = 100.0 * ((int) $rest[11] + (int) $rest[12] - $info['j0']) / $dtotal * $ncpu;
    if ($cpu < 0) { $cpu = 0.0; }

    $rssKb = 0;
    $statm = @file_get_contents("/proc/$pid/statm");
    if ($statm !== false) {
        $sm = preg_split('/\s+/', trim($statm));
        $rssKb = (int) ($sm[1] ?? 0) * 4;     // resident pages * 4 KiB (x86_64)
    }
    $rows[$pid] = ['pid' => (int) $pid, 'cmd' => $info['comm'], 'cpu' => round($cpu, 1), 'rss' => $rssKb];
}

/* rank cheaply */
$byCpu = $rows; uasort($byCpu, fn($a, $b) => $b['cpu'] <=> $a['cpu']);
$byMem = $rows; uasort($byMem, fn($a, $b) => $b['rss'] <=> $a['rss']);
$topCpu = array_slice($byCpu, 0, $topN, true);
$topMem = array_slice($byMem, 0, $topN, true);

/* enrich only the union (each unique pid once) */
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
foreach (($topCpu + $topMem) as $pid => $r) {     // + = union by pid key
    $r['user'] = $resolveUser((int) $pid);
    $r['full'] = $fullCmd((int) $pid, $r['cmd']);
    $r['mem']  = round(100.0 * $r['rss'] / $memTotalKb, 1);
    $enriched[$pid] = $r;
}
$pick = fn($set) => array_values(array_map(fn($r) => $enriched[$r['pid']], $set));

$payload = json_encode([
    'total' => count($rows),
    'cpu'   => $pick($topCpu),
    'mem'   => $pick($topMem),
]);
echo $payload;

/* refresh the shared cache atomically; ignore failure (e.g. read-only tmpfs) */
$tmp = $CACHE . '.' . getmypid();
if (@file_put_contents($tmp, microtime(true) . "\n" . $payload) !== false) {
    @rename($tmp, $CACHE);
} else {
    @unlink($tmp);
}
