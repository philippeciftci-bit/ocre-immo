<?php
// M/2026/05/12/53 — RGPD Article 15+20 : export portabilite des donnees utilisateur.
// V1 : retourne JSON download direct contenant profil + dossiers + factures + metadata.
// V2 (futur) : packaging ZIP avec /uploads/ inclus + delivery email lien expirant 24h.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];

$action = $_GET['action'] ?? 'request';

if ($action === 'status') {
    // Pas de queue async pour l instant : V1 export synchrone immediat.
    jsonOk(['available' => true, 'mode' => 'sync_json_v1']);
}

if ($action !== 'request') jsonError('Action inconnue', 404);

// Rate-limit : 3 exports max par 24h par utilisateur (anti-abus DB load).
if (function_exists('checkRateLimit')) {
    try { checkRateLimit('rgpd_export', 3, 86400, $uid); } catch (Throwable $e) { jsonError('Limite atteinte (3 exports / 24h). Reessaie demain.', 429); }
}

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Profil utilisateur (data perso uniquement, pas de password_hash ni tokens).
$st = $meta->prepare("SELECT id, email, prenom, nom, display_name, telephone, whatsapp, ville, cp, pays, lang,
    created_at, last_login_at, role, profile_label, subscription_status, trial_started_at
    FROM users WHERE id = ? LIMIT 1");
$st->execute([$uid]);
$profil = $st->fetch() ?: [];

// Dossiers de l utilisateur (clients table dans la DB principale, deja accessible via db()).
$dossiers = [];
try {
    $st = db()->prepare("SELECT id, data, projet, is_investisseur, archived, is_draft, created_at, updated_at
        FROM clients WHERE user_id = ? ORDER BY id DESC LIMIT 5000");
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) {
        $r['data'] = $r['data'] ? json_decode($r['data'], true) : null;
        $dossiers[] = $r;
    }
} catch (Throwable $e) { $dossiers = ['_error' => $e->getMessage()]; }

// Factures billing (table peut ne pas exister sur tous environnements).
$factures = [];
try {
    $st = $meta->prepare("SELECT id, stripe_invoice_id, amount_cents, currency, status, created_at, paid_at
        FROM billing_invoices WHERE user_id = ? ORDER BY id DESC LIMIT 500");
    $st->execute([$uid]);
    $factures = $st->fetchAll() ?: [];
} catch (Throwable $e) { $factures = ['_error' => 'billing_invoices indisponible']; }

// Sessions actives.
$sessions = [];
try {
    $st = $meta->prepare("SELECT id, jti, created_at, expires_at, user_agent, ip, last_activity_at
        FROM auth_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 50");
    $st->execute([$uid]);
    $sessions = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

$metadata = [
    'export_version' => 'v1',
    'export_format' => 'json',
    'mission_id' => 'M/2026/05/12/53',
    'generated_at' => date('c'),
    'generated_for_user_id' => $uid,
    'rgpd_articles' => ['15 (acces)', '20 (portabilite)'],
    'data_inclus' => ['profil', 'dossiers', 'factures', 'sessions_actives'],
    'data_non_inclus_v1' => ['uploads_photos (taille fichiers)', 'logs_audit', 'metadata_systeme'],
    'documentation' => 'Pour un export complet incluant les fichiers uploades, contacter support@ocre.immo.',
];

$payload = [
    'metadata' => $metadata,
    'profil' => $profil,
    'dossiers' => $dossiers,
    'factures' => $factures,
    'sessions_actives' => $sessions,
];

$filename = 'ocre-export-' . $uid . '-' . date('Ymd-His') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
