<?php
// M/2026/05/09/47 — M93 : santé serveur Hetzner + deploys récents pour superadmin dashboard.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_session.php';
setCorsHeaders();

$user = getCurrentUserFromCookie();
if (!$user || ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'super_admin_required']);
    exit;
}

// Uptime (lecture /proc/uptime)
$uptimeStr = '—';
if (is_readable('/proc/uptime')) {
    $u = explode(' ', trim(file_get_contents('/proc/uptime')));
    $secs = (float) $u[0];
    $days = floor($secs / 86400);
    $uptimeStr = $days . ' j';
}

// CPU usage (lecture /proc/stat 2 fois)
function cpuPct() {
    if (!is_readable('/proc/stat')) return 0;
    $sample = function () { $l = explode(' ', preg_replace('/\s+/', ' ', trim(explode("\n", file_get_contents('/proc/stat'))[0]))); return [array_sum(array_slice(array_map('intval', $l), 1, 7)), (int) $l[4]]; };
    [$tot1, $idle1] = $sample();
    usleep(100000); // 100 ms
    [$tot2, $idle2] = $sample();
    $tot = $tot2 - $tot1; $idle = $idle2 - $idle1;
    return $tot > 0 ? round(($tot - $idle) / $tot * 100) : 0;
}
$cpu = cpuPct();

// RAM
$ram = 0;
if (is_readable('/proc/meminfo')) {
    $mem = [];
    foreach (explode("\n", file_get_contents('/proc/meminfo')) as $line) {
        if (preg_match('/^(\S+):\s+(\d+)/', $line, $m)) $mem[$m[1]] = (int) $m[2];
    }
    $total = $mem['MemTotal'] ?? 1;
    $available = $mem['MemAvailable'] ?? 0;
    $ram = round(($total - $available) / $total * 100);
}

// Disque (df sur /)
$disk = 0;
$df = @shell_exec('df -P / 2>/dev/null | tail -1');
if ($df && preg_match('/\s(\d+)%\s/', $df, $m)) $disk = (int) $m[1];

// Deploys récents (parse /var/log/ocre-deploy.log)
$deploys = [];
$deployLog = '/var/log/ocre-deploy.log';
if (is_readable($deployLog)) {
    $lines = [];
    $fp = @fopen($deployLog, 'r');
    if ($fp) {
        fseek($fp, 0, SEEK_END);
        $size = ftell($fp);
        $tailSize = min(20000, $size);
        fseek($fp, max(0, $size - $tailSize));
        if ($size > $tailSize) fgets($fp);
        while (($l = fgets($fp)) !== false) $lines[] = rtrim($l);
        fclose($fp);
    }
    // Format : [2026-05-09 21:18:09] OK deploy SHA=8294531
    foreach (array_reverse(array_slice($lines, -50)) as $l) {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}):\d{2}\]\s+OK\s+deploy\s+SHA=([0-9a-f]+)/', $l, $m)) {
            $deploys[] = [
                'ts' => $m[1] . ' ' . $m[2],
                'commit' => $m[3],
                'mission' => '', // sera enrichi via git log
            ];
            if (count($deploys) >= 10) break;
        }
    }
}
// Enrichir avec mission depuis git log (si disponible côté worktree)
foreach ($deploys as &$d) {
    $cmd = 'git -C /root/workspace/ocre-immo log -1 --format=%s ' . escapeshellarg($d['commit']) . ' 2>/dev/null';
    $out = @shell_exec($cmd);
    if ($out !== null && trim($out) !== '') $d['mission'] = trim($out);
}

echo json_encode([
    'ok' => true,
    'server' => [
        'uptime' => $uptimeStr,
        'cpu_pct' => $cpu,
        'ram_pct' => $ram,
        'disk_pct' => $disk,
        'host' => 'Hetzner CX22 · Falkenstein DE',
    ],
    'deploys' => array_slice($deploys, 0, 5),
]);
