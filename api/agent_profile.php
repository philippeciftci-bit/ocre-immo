<?php
// V20 — API profil public agent. Actions : get_mine / update_mine / upload_photo /
// delete_photo / publish / unpublish / get_public (sans auth).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_security.php';
setCorsHeaders();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// get_public est sans auth (fiche publique vitrine future).
if ($action === 'get_public') {
    $slug = trim((string) ($_GET['slug'] ?? ''));
    if (!$slug || !preg_match('/^[a-z0-9-]{3,50}$/', $slug)) jsonError('slug invalide', 400);
    $st = db()->prepare("SELECT id, prenom, nom, slug, photo_url, tagline, bio,
        telephone_pro, email_pro, whatsapp_pro, zones_intervention, specialites,
        carte_pro_numero, carte_pro_prefecture, carte_pro_date_fin,
        rcp_assureur, rcp_numero_police, rcp_montant_garantie
        FROM users WHERE slug = ? AND statut_public = 'actif' AND active = 1 LIMIT 1");
    $st->execute([$slug]);
    $row = $st->fetch();
    if (!$row) jsonError('Agent introuvable', 404);
    $row['zones_intervention'] = $row['zones_intervention'] ? json_decode($row['zones_intervention'], true) : [];
    $row['specialites'] = $row['specialites'] ? json_decode($row['specialites'], true) : [];
    jsonOk(['agent' => $row]);
}

$user = requireAuth();

function slugify(string $s): string {
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if ($t !== false && $t !== null) $s = $t;
    } else {
        $s = strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($s));
    $s = trim($s, '-');
    return substr($s, 0, 50);
}

function ensureSlug(array $u): string {
    if (!empty($u['slug'])) return $u['slug'];
    $base = slugify(($u['prenom'] ?? '') . '-' . ($u['nom'] ?? ''));
    if (!$base) $base = 'agent';
    $slug = $base . '-' . (int) $u['id'];
    // Collision ? Suffix hex.
    $chk = db()->prepare("SELECT id FROM users WHERE slug = ? AND id != ? LIMIT 1");
    $chk->execute([$slug, (int) $u['id']]);
    if ($chk->fetch()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 3);
    db()->prepare("UPDATE users SET slug = ? WHERE id = ?")->execute([$slug, (int) $u['id']]);
    return $slug;
}

function fetchProfile(int $uid): array {
    $st = db()->prepare("SELECT id, email, prenom, nom, role, is_admin,
        photo_url, slug, tagline, bio,
        telephone_pro, email_pro, whatsapp_pro,
        zones_intervention, specialites,
        carte_pro_numero, carte_pro_prefecture, carte_pro_date_fin,
        rcp_assureur, rcp_numero_police, rcp_montant_garantie,
        statut_public
        FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row) jsonError('User introuvable', 404);
    $row['zones_intervention'] = $row['zones_intervention'] ? json_decode($row['zones_intervention'], true) : [];
    $row['specialites'] = $row['specialites'] ? json_decode($row['specialites'], true) : [];
    return $row;
}

function uploadsAgentDir(int $uid): string {
    $base = realpath(__DIR__ . '/../uploads');
    if (!$base) {
        @mkdir(__DIR__ . '/../uploads', 0755, true);
        $base = realpath(__DIR__ . '/../uploads');
    }
    $dir = $base . '/agents/' . $uid;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function publishPrereqs(array $p): array {
    $missing = [];
    if (empty($p['photo_url'])) $missing[] = 'photo';
    if (empty($p['tagline'])) $missing[] = 'tagline';
    if (!empty($p['bio']) === false || strlen((string) $p['bio']) < 50) $missing[] = 'bio_50_chars';
    if (empty($p['telephone_pro'])) $missing[] = 'telephone_pro';
    if (empty($p['email_pro'])) $missing[] = 'email_pro';
    if (!is_array($p['zones_intervention']) || !$p['zones_intervention']) $missing[] = 'zones_intervention';
    if (!is_array($p['specialites']) || !$p['specialites']) $missing[] = 'specialites';
    return $missing;
}

switch ($action) {

    case 'get_mine': {
        $p = fetchProfile((int) $user['id']);
        if (empty($p['slug'])) $p['slug'] = ensureSlug($user);
        $p['missing_for_publish'] = publishPrereqs($p);
        jsonOk(['profile' => $p]);
    }

    case 'update_mine': {
        checkRateLimit('agent_profile_update', 60, 3600, (int) $user['id']);
        $input = getInput();
        $fields = [];
        $params = [];

        $strCols = [
            'tagline' => 255, 'bio' => 2000,
            'telephone_pro' => 50, 'email_pro' => 191, 'whatsapp_pro' => 50,
            'carte_pro_numero' => 100, 'carte_pro_prefecture' => 191,
            'rcp_assureur' => 191, 'rcp_numero_police' => 100, 'rcp_montant_garantie' => 50,
        ];
        foreach ($strCols as $col => $max) {
            if (array_key_exists($col, $input)) {
                $v = trim((string) $input[$col]);
                if (strlen($v) > $max) jsonError("$col trop long (max $max)");
                $fields[] = "$col = ?"; $params[] = $v !== '' ? $v : null;
            }
        }
        if (array_key_exists('email_pro', $input) && !empty($input['email_pro']) && !filter_var($input['email_pro'], FILTER_VALIDATE_EMAIL)) {
            jsonError('Email pro invalide');
        }
        if (array_key_exists('carte_pro_date_fin', $input)) {
            $d = trim((string) $input['carte_pro_date_fin']);
            $fields[] = 'carte_pro_date_fin = ?';
            $params[] = $d !== '' ? $d : null;
        }
        if (array_key_exists('slug', $input)) {
            $slug = trim((string) $input['slug']);
            if (!preg_match('/^[a-z0-9-]{3,50}$/', $slug)) jsonError('slug invalide (3-50 chars, a-z 0-9 et tirets uniquement)');
            $chk = db()->prepare("SELECT id FROM users WHERE slug = ? AND id != ? LIMIT 1");
            $chk->execute([$slug, (int) $user['id']]);
            if ($chk->fetch()) jsonError('slug déjà pris', 409);
            $fields[] = 'slug = ?'; $params[] = $slug;
        }
        if (array_key_exists('zones_intervention', $input)) {
            $z = $input['zones_intervention'];
            if (!is_array($z)) jsonError('zones_intervention doit être un tableau');
            if (count($z) > 30) jsonError('Max 30 zones');
            foreach ($z as $item) { if (!is_string($item) || strlen($item) > 50) jsonError('Zone invalide (string max 50)'); }
            $fields[] = 'zones_intervention = ?'; $params[] = json_encode(array_values($z), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('specialites', $input)) {
            $s = $input['specialites'];
            if (!is_array($s)) jsonError('specialites doit être un tableau');
            if (count($s) > 20) jsonError('Max 20 spécialités');
            foreach ($s as $item) { if (!is_string($item) || strlen($item) > 80) jsonError('Spécialité invalide'); }
            $fields[] = 'specialites = ?'; $params[] = json_encode(array_values($s), JSON_UNESCAPED_UNICODE);
        }

        if (!$fields) jsonError('Aucun champ à mettre à jour');
        $params[] = (int) $user['id'];
        db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        $p = fetchProfile((int) $user['id']);
        if (empty($p['slug'])) $p['slug'] = ensureSlug($user);
        $p['missing_for_publish'] = publishPrereqs($p);
        jsonOk(['profile' => $p]);
    }

    case 'upload_photo': {
        checkRateLimit('agent_photo_upload', 10, 3600, (int) $user['id']);
        if (empty($_FILES['photo'])) jsonError('photo manquante');
        $f = $_FILES['photo'];
        if ($f['error'] !== UPLOAD_ERR_OK) jsonError('Upload error ' . $f['error']);
        if ($f['size'] > 5 * 1024 * 1024) jsonError('Max 5 Mo');
        $info = @getimagesize($f['tmp_name']);
        if (!$info) jsonError('Fichier image invalide');
        $mime = $info['mime'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) jsonError('Format non supporté (jpg/png/webp)');

        // Load + resize carré centré.
        $src = null;
        if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($f['tmp_name']);
        elseif ($mime === 'image/png') $src = @imagecreatefrompng($f['tmp_name']);
        elseif ($mime === 'image/webp') $src = @imagecreatefromwebp($f['tmp_name']);
        if (!$src) jsonError('Décodage image échoué');
        $w = imagesx($src); $h = imagesy($src);
        $side = min($w, $h);
        $cx = ($w - $side) / 2; $cy = ($h - $side) / 2;

        $dir = uploadsAgentDir((int) $user['id']);
        foreach ([400, 120] as $target) {
            $dst = imagecreatetruecolor($target, $target);
            imagecopyresampled($dst, $src, 0, 0, (int) $cx, (int) $cy, $target, $target, $side, $side);
            $path = $dir . '/avatar-' . $target . '.jpg';
            imagejpeg($dst, $path, 85);
            imagedestroy($dst);
        }
        imagedestroy($src);

        $url400 = '/api/agent_photo.php?uid=' . (int) $user['id'] . '&size=400&v=' . time();
        db()->prepare("UPDATE users SET photo_url = ? WHERE id = ?")->execute([$url400, (int) $user['id']]);
        jsonOk(['photo_url' => $url400, 'thumb_url' => '/api/agent_photo.php?uid=' . (int) $user['id'] . '&size=120&v=' . time()]);
    }

    case 'delete_photo': {
        $dir = uploadsAgentDir((int) $user['id']);
        @unlink($dir . '/avatar-400.jpg');
        @unlink($dir . '/avatar-120.jpg');
        db()->prepare("UPDATE users SET photo_url = NULL WHERE id = ?")->execute([(int) $user['id']]);
        jsonOk(['photo_url' => null]);
    }

    case 'publish': {
        $p = fetchProfile((int) $user['id']);
        $miss = publishPrereqs($p);
        if ($miss) jsonError('Profil incomplet', 400);
        if (empty($p['slug'])) $slug = ensureSlug($user); else $slug = $p['slug'];
        db()->prepare("UPDATE users SET statut_public = 'actif' WHERE id = ?")->execute([(int) $user['id']]);
        jsonOk(['statut_public' => 'actif', 'slug' => $slug]);
    }

    case 'unpublish': {
        db()->prepare("UPDATE users SET statut_public = 'brouillon' WHERE id = ?")->execute([(int) $user['id']]);
        jsonOk(['statut_public' => 'brouillon']);
    }

    default:
        jsonError('Action inconnue', 404);
}
