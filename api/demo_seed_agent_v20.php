<?php
// V20 demo — seed profil agent Philippe. Bio contient [DEMO-2026-04-24] pour détection purge.
require_once __DIR__ . '/db.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

$pdo = db();
$u = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$u->execute(['philippe.ciftci@gmail.com']);
$row = $u->fetch();
if (!$row) { http_response_code(500); exit('Philippe not found'); }
$PID = (int) $row['id'];

$zones = ['Marrakech', 'Palmeraie', 'Ourika', 'Médina', 'Hivernage', 'Guéliz', 'Essaouira'];
$specs = ['vente_residentiel', 'location_saisonniere', 'luxe', 'riad', 'villa', 'investissement_locatif'];

$photoUrl = '/api/agent_photo.php?uid=' . $PID . '&size=400&v=' . time();

$stmt = $pdo->prepare("UPDATE users SET
    photo_url = ?,
    slug = ?,
    tagline = ?,
    bio = ?,
    telephone_pro = ?,
    email_pro = ?,
    whatsapp_pro = ?,
    zones_intervention = ?,
    specialites = ?,
    carte_pro_numero = ?,
    carte_pro_prefecture = ?,
    carte_pro_date_fin = ?,
    rcp_assureur = ?,
    rcp_numero_police = ?,
    rcp_montant_garantie = ?,
    statut_public = 'actif'
    WHERE id = ?");
$stmt->execute([
    $photoUrl,
    'philippe-ciftci',
    'Agent immobilier — Marrakech & environs',
    "Spécialiste de l'immobilier haut de gamme à Marrakech et sa région depuis plus de 10 ans. J'accompagne acheteurs, vendeurs et investisseurs dans leurs projets — riads de la médina, villas de la Palmeraie, locations saisonnières premium. Approche personnalisée, discrétion, réseau local solide. [DEMO-2026-04-24 — profil fictif pour tests]",
    '+212 6 61 23 45 67',
    'philippe@ocre.immo',
    '+212 6 61 23 45 67',
    json_encode($zones, JSON_UNESCAPED_UNICODE),
    json_encode($specs, JSON_UNESCAPED_UNICODE),
    'T-2024-MA-4578',
    'Préfecture de Marrakech',
    '2027-12-31',
    'AXA Assurances Maroc',
    'RCP-2024-78451',
    '500 000 €',
    $PID,
]);

// Retourne le profil mis à jour pour vérif.
$chk = $pdo->prepare("SELECT id, email, prenom, nom, slug, tagline, photo_url, statut_public FROM users WHERE id = ?");
$chk->execute([$PID]);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'profile' => $chk->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
