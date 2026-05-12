<?php
// M/2026/05/12/53 — RGPD Article 15+20 : export portabilite des donnees utilisateur.
// V1 : retourne JSON download direct contenant profil + dossiers + factures + metadata.
// M/2026/05/13/1 — V2 : ajout format=zip (ZipArchive PHP + uploads inclus).
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];

$action = $_GET['action'] ?? 'request';
$format = $_GET['format'] ?? 'json';

if ($action === 'status') {
    jsonOk(['available' => true, 'mode' => 'sync_json_v1', 'formats' => ['json', 'zip']]);
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

$dateStamp = date('Ymd-His');

// M/2026/05/13/1 — Format ZIP : packaging avec profil/dossiers/factures + README + uploads photos.
if ($format === 'zip') {
    if (!class_exists('ZipArchive')) jsonError('ZipArchive non disponible sur ce serveur', 500);
    $tmpZip = tempnam(sys_get_temp_dir(), 'ocre-rgpd-');
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) jsonError('Impossible de creer le ZIP', 500);

    $zip->addFromString('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->addFromString('profil.json', json_encode($profil, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->addFromString('dossiers.json', json_encode($dossiers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('factures.json', json_encode($factures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->addFromString('sessions_actives.json', json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Photos uploadees : repertoire /uploads/<dossier_id>/* pour chaque dossier de l utilisateur.
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot) {
        foreach ($dossiers as $d) {
            if (!is_array($d) || empty($d['id'])) continue;
            $dossierDir = $uploadsRoot . '/' . (int) $d['id'];
            if (!is_dir($dossierDir)) continue;
            foreach (glob($dossierDir . '/*') ?: [] as $f) {
                if (!is_file($f)) continue;
                $rel = 'uploads/dossier_' . (int) $d['id'] . '/' . basename($f);
                @$zip->addFile($f, $rel);
            }
        }
    }

    $zip->addFromString('README.txt',
        "Export RGPD Ocre Immo\n" .
        "=====================\n\n" .
        "Genere le : " . date('Y-m-d H:i:s') . "\n" .
        "Utilisateur ID : " . $uid . "\n\n" .
        "Contenu :\n" .
        "- metadata.json : informations sur cet export\n" .
        "- profil.json : tes informations personnelles\n" .
        "- dossiers.json : tous tes dossiers clients\n" .
        "- factures.json : ton historique de facturation\n" .
        "- sessions_actives.json : tes sessions de connexion actives\n" .
        "- uploads/dossier_<id>/ : photos et documents uploadees par dossier\n\n" .
        "Articles RGPD : 15 (droit d acces) + 20 (portabilite).\n" .
        "Questions : support@ocre.immo\n"
    );

    $zip->close();

    $filename = 'ocre-export-' . $uid . '-' . $dateStamp . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

// Default : JSON download direct.
$filename = 'ocre-export-' . $uid . '-' . $dateStamp . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
