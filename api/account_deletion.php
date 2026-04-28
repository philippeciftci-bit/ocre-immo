<?php
// M/2026/04/29/10 — Suppression de compte RGPD (grâce 30j puis anonymisation).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];
$action = $_GET['action'] ?? 'status';
$input = getInput();

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

switch ($action) {

case 'status': {
    $st = $meta->prepare("SELECT deletion_requested_at, anonymized_at FROM users WHERE id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    jsonOk([
        'deletion_requested_at' => $r['deletion_requested_at'] ?? null,
        'anonymized_at' => $r['anonymized_at'] ?? null,
        'grace_remaining_days' => $r['deletion_requested_at']
            ? max(0, 30 - floor((time() - strtotime($r['deletion_requested_at'])) / 86400))
            : null,
    ]);
}

case 'request': {
    $reason = trim($input['reason'] ?? '');
    $meta->prepare("UPDATE users SET deletion_requested_at = NOW() WHERE id = ?")->execute([$uid]);
    // Email confirmation (best-effort)
    if (function_exists('ocre_send_email')) {
        require_once __DIR__ . '/lib/email_sender.php';
        ocre_send_email(
            $user['email'] ?? '',
            'Confirmation suppression de compte Ocre Immo',
            '<p>Votre demande de suppression de compte a été enregistrée.</p>'
            . '<p>Période de grâce : 30 jours. Pendant cette période, vous pouvez annuler la suppression depuis Préférences &rsaquo; Mes données.</p>'
            . '<p>Au-delà de 30 jours, vos données personnelles seront anonymisées (les données comptables resteront 10 ans pour obligations légales).</p>'
            . ($reason ? '<p>Raison : ' . htmlspecialchars($reason) . '</p>' : '')
        );
    }
    jsonOk(['ok' => true, 'grace_days' => 30]);
}

case 'cancel': {
    $meta->prepare("UPDATE users SET deletion_requested_at = NULL WHERE id = ?")->execute([$uid]);
    jsonOk(['ok' => true]);
}

default:
    jsonError('Action inconnue (status | request | cancel)', 400);
}
