<?php
// Ocre v18.6 — proxy transcription audio OVH → VPS Whisper.
// Auth user session ; envoie audio en multipart vers VPS avec shared secret.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();

$endpoint = getSetting('whisper_endpoint', 'https://46-225-215-148.sslip.io/whisper/transcribe');
$secret = getSetting('whisper_shared_secret', '');
if (!$secret) jsonError('Whisper non configuré');

if (empty($_FILES['audio']) || ($_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jsonError('audio requis (multipart/form-data, champ "audio")');
}
$file = $_FILES['audio'];
$path = $file['tmp_name'];
$name = $file['name'] ?: 'audio.webm';
$type = $file['type'] ?: 'audio/webm';

// Limite taille : 10 MB (≈90s opus)
if (filesize($path) > 10 * 1024 * 1024) {
    jsonError('audio trop volumineux (>10MB)');
}

$ch = curl_init($endpoint);
$cf = new CURLFile($path, $type, $name);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['audio' => $cf],
    CURLOPT_HTTPHEADER => ['X-Atelier-Secret: ' . $secret],
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) jsonError('VPS unreachable: ' . $err, 502);
if ($code >= 400) jsonError('VPS error ' . $code . ': ' . substr((string)$resp, 0, 200), $code);

$j = json_decode($resp, true);
if (!is_array($j)) jsonError('VPS bad response', 502);

logAction((int)$user['id'], 'transcribe',
    sprintf('dur=%.1fs proc=%.1fs bytes=%d',
        (float)($j['duration_sec'] ?? 0),
        (float)($j['processing_time_sec'] ?? 0),
        (int)($j['audio_bytes'] ?? 0)));

jsonOk([
    'text' => (string)($j['text'] ?? ''),
    'duration_sec' => $j['duration_sec'] ?? null,
    'processing_time_sec' => $j['processing_time_sec'] ?? null,
    'language' => $j['language'] ?? null,
]);
