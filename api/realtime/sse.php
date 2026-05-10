<?php
// M_OCRE_V19_COLLAB — SSE endpoint EventSource keep-alive
// Stream events realtime_events filtres par tenant_slug + topic suivi.
// GET ?topic=presence:dossier:42 ou comments:dossier:42 ou versions:dossier:42 (multi separe ,)
// Polling DB toutes les 2s, ferme connection apres 50s (le client EventSource auto-reconnecte).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/collab.php';

$user = requireAuth();
$tenant = collab_tenant_slug($user);
if (!$tenant) { http_response_code(400); echo "Tenant inconnu"; exit; }

collab_ensure_schema();

$topicsRaw = (string)($_GET['topic'] ?? '');
$topics = array_filter(array_map('trim', explode(',', $topicsRaw)));
if (!$topics) { http_response_code(400); echo "topic requis"; exit; }
$lastIdParam = (int)($_GET['last_id'] ?? 0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) ob_end_flush();

// Send retry directive (client reconnect 3s if connection drops)
echo "retry: 3000\n\n";
@flush();

$placeholders = implode(',', array_fill(0, count($topics), '?'));
$lastId = $lastIdParam;
$startTs = time();
$tickMax = 25; // 25 ticks * 2s = 50s
for ($tick = 0; $tick < $tickMax; $tick++) {
    if (connection_aborted()) break;
    try {
        $args = array_merge([$tenant, $lastId], $topics);
        $sql = "SELECT id, topic, payload, UNIX_TIMESTAMP(created_at) AS ts FROM realtime_events
                WHERE tenant_slug = ? AND id > ? AND topic IN ($placeholders)
                ORDER BY id ASC LIMIT 50";
        $st = db()->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();
        foreach ($rows as $r) {
            echo "id: " . $r['id'] . "\n";
            echo "event: " . preg_replace('/[^a-zA-Z0-9_:.-]/', '_', $r['topic']) . "\n";
            echo "data: " . $r['payload'] . "\n\n";
            $lastId = max($lastId, (int)$r['id']);
        }
        // Heartbeat keep-alive (commentaire SSE)
        echo ": ping " . time() . "\n\n";
        @flush();
    } catch (Throwable $e) {
        echo "event: error\ndata: " . json_encode(['msg' => $e->getMessage()]) . "\n\n";
        @flush();
        break;
    }
    sleep(2);
}
exit;
