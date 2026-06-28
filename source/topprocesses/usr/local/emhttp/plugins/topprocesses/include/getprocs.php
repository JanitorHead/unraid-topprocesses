<?php
/* Top Processes — JSON data endpoint.
 *
 * Samples /proc twice (~300 ms apart) to compute instantaneous per-process %CPU
 * (htop/Irix semantics: 100% == one full core) and %MEM from resident memory.
 * Read-only, no side effects. PHP 8.3/8.4 clean.
 *
 * Output: { "nproc":N, "cpu":[{pid,user,cmd,cpu,mem}], "mem":[ ... ] }
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfg  = @parse_ini_file('/boot/config/plugins/topprocesses/topprocesses.cfg') ?: [];
$topN = (int) ($cfg['TOPN'] ?? 8);
if ($topN < 1)  { $topN = 8; }
if ($topN > 50) { $topN = 50; }

/* Sum of all jiffies in the aggregate "cpu" line of /proc/stat. */
function cpu_total(): int {
    $data = @file_get_contents('/proc/stat');
    if ($data === false) { return 0; }
    $first = strtok($data, "\n");                 // "cpu  user nice system idle ..."
    $parts = preg_split('/\s+/', trim((string) $first));
    array_shift($parts);                          // drop the "cpu" label
    $sum = 0;
    foreach ($parts as $v) { $sum += (int) $v; }
    return $sum;
}

/* utime+stime (jiffies) from a /proc/<pid>/stat string.
 * comm (field 2) may contain spaces/parens, so split on the text after the last ')'. */
function pid_jiffies(string $stat): ?int {
    $rp = strrpos($stat, ')');
    if ($rp === false) { return null; }
    $rest = preg_split('/\s+/', trim(substr($stat, $rp + 1)));
    // rest[0] = state (field 3); utime = field 14 => index 11, stime = field 15 => index 12
    if (!isset($rest[12])) { return null; }
    return (int) $rest[11] + (int) $rest[12];
}

/* Short command name from the comm field (between the first '(' and last ')'). */
function pid_comm(string $stat): string {
    $lp = strpos($stat, '(');
    $rp = strrpos($stat, ')');
    if ($lp === false || $rp === false || $rp <= $lp) { return '?'; }
    return substr($stat, $lp + 1, $rp - $lp - 1);
}

/* ---- pass 1 ---------------------------------------------------------- */
$pids = [];
foreach (scandir('/proc') ?: [] as $d) {
    if (!ctype_digit($d)) { continue; }
    $stat = @file_get_contents("/proc/$d/stat");
    if ($stat === false) { continue; }
    $j = pid_jiffies($stat);
    if ($j === null) { continue; }
    $pids[$d] = ['j0' => $j, 'comm' => pid_comm($stat)];
}
$total0 = cpu_total();

usleep(300000); // 300 ms sampling window

/* ---- pass 2 ---------------------------------------------------------- */
$total1 = cpu_total();
$dtotal = max(1, $total1 - $total0);

$ncpu = 0;
foreach (preg_split('/\n/', (string) @file_get_contents('/proc/stat')) as $l) {
    if (preg_match('/^cpu\d+\s/', $l)) { $ncpu++; }
}
if ($ncpu < 1) { $ncpu = 1; }

preg_match('/MemTotal:\s+(\d+)/', (string) @file_get_contents('/proc/meminfo'), $mt);
$memTotalKb = max(1, (int) ($mt[1] ?? 0));

$rows = [];
foreach ($pids as $pid => $info) {
    $stat = @file_get_contents("/proc/$pid/stat");
    if ($stat === false) { continue; }            // process exited between samples
    $j1 = pid_jiffies($stat);
    if ($j1 === null) { continue; }

    $cpu = 100.0 * ($j1 - $info['j0']) / $dtotal * $ncpu;
    if ($cpu < 0) { $cpu = 0.0; }

    // resident memory from statm: field 2 = resident pages, 4 KiB each on x86_64
    $rssKb = 0;
    $statm = @file_get_contents("/proc/$pid/statm");
    if ($statm !== false) {
        $sm = preg_split('/\s+/', trim($statm));
        $rssKb = (int) ($sm[1] ?? 0) * 4;
    }
    $mem = 100.0 * $rssKb / $memTotalKb;

    $uid  = @fileowner("/proc/$pid");
    $user = '?';
    if ($uid !== false) {
        $user = (string) $uid;
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($uid);
            if (is_array($pw) && isset($pw['name'])) { $user = $pw['name']; }
        }
    }

    // full command line (for the row tooltip); fall back to the short comm name
    $full = $info['comm'];
    $cl   = @file_get_contents("/proc/$pid/cmdline");
    if ($cl !== false && $cl !== '') {
        $cl = trim(str_replace("\0", ' ', $cl));
        if ($cl !== '') { $full = $cl; }
    }

    $rows[] = [
        'pid'  => (int) $pid,
        'user' => $user,
        'cmd'  => $info['comm'],
        'full' => $full,
        'cpu'  => round($cpu, 1),
        'mem'  => round($mem, 1),
        'rss'  => $rssKb,
    ];
}

$byCpu = $rows;
usort($byCpu, fn($a, $b) => $b['cpu'] <=> $a['cpu']);

$byMem = $rows;
usort($byMem, fn($a, $b) => $b['mem'] <=> $a['mem']);

echo json_encode([
    'nproc' => $ncpu,
    'total' => count($rows),
    'cpu'   => array_slice($byCpu, 0, $topN),
    'mem'   => array_slice($byMem, 0, $topN),
]);
