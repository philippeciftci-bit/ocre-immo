<?php
// M/2026/05/13/12 — Aide FAQ v2 : ticket support depuis modale HelpPage.
// Table : support_tickets (id, user_id nullable, email, subject ENUM, message TEXT, tenant_slug, created_at, status).
// POST ?action=submit body {subject, message, email?, attachment_data?}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/email_sender.php';
setCorsHeaders();

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    $meta->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        email VARCHAR(191) NOT NULL,
        subject ENUM('bug','question','feature','autre') NOT NULL DEFAULT 'question',
        message TEXT NOT NULL,
        tenant_slug VARCHAR(64) NULL,
        attachment_name VARCHAR(255) NULL,
        status ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_email (email),
        KEY idx_status (status),
        KEY idx_created (created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$action = $_GET['action'] ?? 'submit';
if ($action !== 'submit') jsonError('Action inconnue', 404);

$input = getInput();
$subject = strtolower(trim((string)($input['subject'] ?? '')));
$message = trim((string)($input['message'] ?? ''));
$emailFromForm = trim((string)($input['email'] ?? ''));
$attachmentName = trim((string)($input['attachment_name'] ?? ''));

if (!in_array($subject, ['bug','question','feature','autre'], true)) jsonError('subject invalide', 400);
if (mb_strlen($message) < 5 || mb_strlen($message) > 500) jsonError('Message doit avoir 5-500 caracteres', 400);

$uid = null; $email = $emailFromForm;
$u = currentUser();
if ($u && isset($u['id'])) { $uid = (int)$u['id']; $email = $u['email'] ?? $email; }

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalide', 400);

// Rate-limit anti-spam : max 5 tickets / 1h par email.
$st = $meta->prepare("SELECT COUNT(*) AS n FROM support_tickets WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$st->execute([$email]);
if ((int)($st->fetch()['n'] ?? 0) >= 5) jsonError('Trop de tickets recents (max 5/1h). Reessaie plus tard.', 429);

$tenant = $_SERVER['HTTP_HOST'] ?? null;
if ($tenant) $tenant = strtolower(preg_replace('/^([a-z0-9-]+)\..*$/', '$1', $tenant));

$ins = $meta->prepare("INSERT INTO support_tickets (user_id, email, subject, message, tenant_slug, attachment_name, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())");
$ins->execute([$uid, $email, $subject, $message, $tenant, $attachmentName ?: null]);
$ticketId = (int)$meta->lastInsertId();

// Envoi email a support@ocre.immo
$subjectMap = [
    'bug' => '🐛 Bug', 'question' => '❓ Question', 'feature' => '💡 Demande feature', 'autre' => '✉️ Autre',
];
$subjectLabel = $subjectMap[$subject] ?? $subject;
$mailSubject = '[Ocre Support #' . $ticketId . '] ' . $subjectLabel . ' - ' . substr($message, 0, 60);
$mailBody = '<h3>Nouveau ticket support Ocre Immo</h3>'
    . '<p><b>Ticket ID</b> : #' . $ticketId . '</p>'
    . '<p><b>De</b> : ' . htmlspecialchars($email) . ($uid ? ' (user #' . $uid . ')' : ' (anonyme)') . '</p>'
    . '<p><b>Tenant</b> : ' . htmlspecialchars($tenant ?: '—') . '</p>'
    . '<p><b>Categorie</b> : ' . htmlspecialchars($subjectLabel) . '</p>'
    . '<p><b>Message</b> :</p>'
    . '<pre style="background:#f5f5f5;padding:10px;border-radius:6px;white-space:pre-wrap;font-family:sans-serif">' . htmlspecialchars($message) . '</pre>'
    . ($attachmentName ? '<p><b>Piece jointe declaree</b> : ' . htmlspecialchars($attachmentName) . '</p>' : '')
    . '<p style="color:#888;font-size:11px">Genere automatiquement par /api/support_ticket.php (M/2026/05/13/12)</p>';

$mailSent = false;
try { $mailSent = ocre_send_email('support@ocre.immo', $mailSubject, $mailBody); } catch (Throwable $e) {}

jsonOk(['ok' => true, 'ticket_id' => $ticketId, 'mail_sent' => $mailSent, 'expected_response_hours' => 24]);
