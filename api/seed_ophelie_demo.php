<?php
// V48 — One-shot seed Ophélie : crée l'utilisateur (idempotent) + 6 dossiers démo.
// IP-whitelist VPS atelier uniquement.
require_once __DIR__ . '/db.php';

$allowed = ['46.225.215.148', '127.0.0.1', '::1'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $remote)[0]);
if (!in_array($ip, $allowed, true)) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'forbidden','seen_ip'=>$ip])); }
header('Content-Type: application/json; charset=utf-8');

// Migration colonnes si manquantes
foreach ([
    "ALTER TABLE users ADD COLUMN scope_owner_id INT NULL",
    "ALTER TABLE clients ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0",
] as $sql) {
    try { db()->exec($sql); } catch (Exception $e) {}
}

$out = ['steps' => []];
try {
    $pdo = db();
    $email = 'ophelie@ocre.immo';
    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt->execute([$email]);
    $r = $stmt->fetch();
    if ($r) {
        $user_id = (int)$r['id'];
        $out['steps'][] = "user existant id=$user_id";
    } else {
        $pdo->prepare("INSERT INTO users (email, password_hash, role, prenom, nom, active, created_at) VALUES (?, 'PLACEHOLDER', 'agent', ?, ?, 1, NOW())")
            ->execute([$email, 'Ophélie', '']);
        $user_id = (int)$pdo->lastInsertId();
        $out['steps'][] = "user créé id=$user_id";
    }
    // Lien scope vers Philippe (best-effort)
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER('philippe.ciftci@gmail.com') LIMIT 1");
        $stmt->execute();
        $p = $stmt->fetch();
        if ($p) {
            $pdo->prepare("UPDATE users SET scope_owner_id = ? WHERE id = ?")->execute([(int)$p['id'], $user_id]);
            $out['steps'][] = 'scope_owner_id mis à jour vers philippe id=' . (int)$p['id'];
        }
    } catch (Exception $e) {}

    // Vérifie idempotence : si user a déjà ses dossiers démo, ne rien réinsérer.
    $stmt = $pdo->prepare("SELECT COUNT(*) n FROM clients WHERE user_id = ? AND is_demo = 1");
    $stmt->execute([$user_id]);
    $existing = (int)$stmt->fetch()['n'];
    if ($existing >= 6) {
        $out['steps'][] = "déjà $existing dossiers démo, skip seed";
        $out['ok'] = true;
        $out['user_id'] = $user_id;
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $unsplash = [
        'https://images.unsplash.com/photo-1539020140153-e479b8c5b3ec?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1564013799919-ab600027ffc6?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1505691938895-1758d7feb511?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1542640244-7e672d6cef4e?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1518883240988-d4cdcec1e8e3?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=900&q=70',
        'https://images.unsplash.com/photo-1613490493576-7fde63acd811?auto=format&fit=crop&w=900&q=70',
    ];
    function pickPhotos(array $pool, int $n, int $offset = 0): array {
        $out = [];
        for ($i = 0; $i < $n; $i++) $out[] = $pool[($offset + $i) % count($pool)];
        return $out;
    }

    $dossiers = [
        [
            'prenom' => 'Sophie', 'nom' => 'LAMBERT', 'tel' => '+33612345678', 'email' => 'sophie.lambert@gmail.com',
            'projet' => 'Vendeur',
            'bien' => ['type' => 'Riad', 'pays' => 'MA', 'ville' => 'Marrakech', 'quartier' => 'Médina',
                'secteur' => 'Mouassine', 'surface' => 320, 'chambres' => 5, 'sdb' => 3, 'pieces' => 7,
                'equipements' => ['hammam','climatisation','patio','terrasse','fontaine'],
                'photos' => pickPhotos($unsplash, 8, 0)],
            'prix_affiche' => 4500000,
            'financement' => ['prix_affiche' => 4500000, 'devise' => 'MAD', 'frais_pct' => 3, 'frais_montant' => 135000],
            'payment_plan' => [['id'=>'pp_demo1','amount'=>4500000,'currency'=>'MAD','method'=>'wire']],
            'received_payments' => [],
            'etape' => 'qualifie', 'score' => 68,
            'notes' => 'Conditions :\n• Caution : 1 mois\n• Frais agence : 3%',
        ],
        [
            'prenom' => 'Antoine', 'nom' => 'DUFOUR', 'tel' => '+33688776655', 'email' => 'a.dufour@laposte.net',
            'projet' => 'Vendeur',
            'bien' => ['type' => 'Villa', 'pays' => 'MA', 'ville' => 'Marrakech', 'quartier' => 'Amelkis',
                'surface' => 420, 'chambres' => 5, 'sdb' => 4, 'pieces' => 8,
                'equipements' => ['piscine','jardin','golf','vue_atlas','parking','climatisation'],
                'photos' => pickPhotos($unsplash, 17, 1)],
            'prix_affiche' => 12500000,
            'financement' => ['prix_affiche' => 12500000, 'devise' => 'MAD', 'frais_pct' => 3, 'frais_montant' => 375000],
            'payment_plan' => [['id'=>'pp_demo2','amount'=>12500000,'currency'=>'MAD','method'=>'wire']],
            'received_payments' => [],
            'etape' => 'offre', 'score' => 74,
        ],
        [
            'prenom' => 'Claire', 'nom' => 'LEFEBVRE', 'tel' => '+33614253647', 'email' => 'c.lefebvre@free.fr',
            'projet' => 'Vendeur',
            'bien' => ['type' => 'Riad', 'pays' => 'MA', 'ville' => 'Marrakech', 'quartier' => 'Médina',
                'secteur' => 'Mouassine', 'surface' => 240, 'chambres' => 4, 'sdb' => 2, 'pieces' => 6,
                'equipements' => ['bassin','hammam','terrasse','patio'],
                'photos' => pickPhotos($unsplash, 9, 2)],
            'prix_affiche' => 3800000,
            'financement' => ['prix_affiche' => 3800000, 'devise' => 'MAD', 'frais_pct' => 3, 'frais_montant' => 112500],
            'payment_plan' => [['id'=>'pp_demo3','amount'=>3750000,'currency'=>'MAD','method'=>'wire']],
            'received_payments' => [['id'=>'rp_demo3a','date'=>date('Y-m-d', strtotime('-12 days')),'amount'=>1875000,'currency'=>'MAD','method'=>'wire']],
            'etape' => 'compromis', 'score' => 86,
        ],
        [
            'prenom' => 'Marie & Thomas', 'nom' => 'LEROY', 'tel' => '+33755667788', 'email' => 'm.leroy@orange.fr',
            'projet' => 'Acheteur',
            'bien' => ['type' => 'Appartement', 'pays' => 'MA', 'ville' => 'Marrakech', 'quartier' => 'Hivernage',
                'surface' => 110, 'chambres' => 2, 'sdb' => 2, 'pieces' => 4,
                'equipements' => ['climatisation','parking','terrasse'],
                'photos' => pickPhotos($unsplash, 6, 3)],
            'prix_affiche' => 2100000, 'budget_max' => 2200000,
            'financement' => ['budget_total' => 2200000, 'devise' => 'MAD', 'frais_pct' => 2.5, 'frais_montant' => 52500, 'mode' => 'classique'],
            'payment_plan' => [['id'=>'pp_demo4','amount'=>2100000,'currency'=>'MAD','method'=>'wire']],
            'received_payments' => [],
            'etape' => 'compromis', 'score' => 91,
            'notes' => 'Crédit BMCE en cours.',
        ],
        [
            'prenom' => 'Pierre', 'nom' => 'GASCOIN', 'tel' => '+33692837465', 'email' => 'p.gascoin@bnpparibas.fr',
            'projet' => 'Acheteur',
            'bien' => ['type' => 'Riad', 'pays' => 'MA', 'ville' => 'Marrakech', 'quartier' => 'Kasbah',
                'surface' => 180, 'chambres' => 3, 'sdb' => 2, 'pieces' => 5,
                'equipements' => ['patio','terrasse','climatisation'],
                'photos' => pickPhotos($unsplash, 4, 4)],
            'prix_affiche' => 2900000, 'budget_max' => 2900000,
            'financement' => ['budget_total' => 2900000, 'devise' => 'MAD', 'frais_pct' => 2.5, 'frais_montant' => 72500],
            'payment_plan' => [['id'=>'pp_demo5','amount'=>2900000,'currency'=>'MAD','method'=>'wire']],
            'received_payments' => [],
            'etape' => 'visite', 'score' => 62,
        ],
        [
            'prenom' => 'Emma', 'nom' => 'MARCEAU', 'tel' => '+33722110099', 'email' => 'emma.marceau@hotmail.fr',
            'projet' => 'Acheteur',
            'bien' => ['type' => 'Maison', 'pays' => 'MA', 'ville' => 'Vallée Ourika', 'quartier' => '',
                'surface' => 200, 'surface_terrain' => 2500, 'chambres' => 4, 'sdb' => 2, 'pieces' => 6,
                'equipements' => ['jardin','terrasse','vue_atlas','piscine'],
                'photos' => pickPhotos($unsplash, 12, 5)],
            'prix_affiche' => 3500000, 'budget_max' => 3500000,
            'financement' => ['budget_total' => 3500000, 'devise' => 'MAD', 'frais_pct' => 3, 'frais_montant' => 105000],
            'payment_plan' => [['id'=>'pp_demo6','amount'=>3500000,'currency'=>'MAD','method'=>'wire']],
            'received_payments' => [],
            'etape' => 'prospect', 'score' => 55,
        ],
    ];

    $created = [];
    foreach ($dossiers as $d) {
        $d['tels'] = [['label' => 'Principal', 'valeur' => $d['tel'], 'primary' => true]];
        $d['emails'] = [['label' => 'Principal', 'valeur' => $d['email'], 'primary' => true]];
        $d['is_demo'] = true;
        $is_investisseur = 0;
        $data = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("INSERT INTO clients (user_id, data, projet, is_investisseur, archived, is_draft, is_demo, prenom, nom, tel, email, payment_plan, received_payments, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 0, 1, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $user_id, $data, $d['projet'], $is_investisseur,
            $d['prenom'], $d['nom'], $d['tel'], $d['email'],
            json_encode($d['payment_plan'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($d['received_payments'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        $cid = (int)$pdo->lastInsertId();
        $created[] = ['id' => $cid, 'name' => $d['prenom'] . ' ' . $d['nom']];
    }
    $out['ok'] = true;
    $out['user_id'] = $user_id;
    $out['user_email'] = $email;
    $out['created'] = $created;
    $out['total'] = count($created);
} catch (Exception $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
