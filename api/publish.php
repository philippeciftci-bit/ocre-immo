<?php
// V18.47 — endpoints publication vitrine. Actions : publish / unpublish / toggle_visibility /
// update / preview_data. Isolation user_id stricte (un user ne peut publier QUE ses dossiers).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_security.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

function slugify(string $s): string {
    $s = (string) $s;
    // Translittération basique utf8 → ascii
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if ($t !== false && $t !== null) $s = $t;
    } else {
        $s = strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($s));
    $s = trim($s, '-');
    return substr($s, 0, 80) ?: 'annonce';
}

function fetchClientOwned(int $id, int $uid): ?array {
    $st = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
    $st->execute([$id, $uid]);
    $row = $st->fetch();
    return $row ?: null;
}

function normProjet(?string $p): string {
    return strtolower(trim((string) $p));
}

function isOfferDossier(?string $projet): bool {
    return in_array(normProjet($projet), ['vendeur', 'bailleur'], true);
}

function defaultVerticalFor(?string $projet): ?string {
    $p = normProjet($projet);
    if ($p === 'vendeur') return 'vente';
    if ($p === 'bailleur') return 'location_longue';
    return null;
}

function buildPublicUrl(string $vertical, string $slug): string {
    $seg = match ($vertical) {
        'vente' => 'vente',
        'location_longue' => 'location',
        'sejour_court' => 'sejours',
        default => 'annonce',
    };
    return 'https://ocre.immo/' . $seg . '/' . $slug;
}

switch ($action) {

    case 'preview_data': {
        $id = (int) ($_GET['client_id'] ?? ($input['client_id'] ?? 0));
        if (!$id) jsonError('client_id requis');
        $c = fetchClientOwned($id, (int) $user['id']);
        if (!$c) jsonError('Dossier introuvable', 404);
        $data = json_decode((string) ($c['data'] ?? '{}'), true) ?: [];
        $projet = normProjet($c['projet']);
        jsonOk([
            'client_id' => (int) $c['id'],
            'is_published' => (bool) ($c['is_published'] ?? 0),
            'public_visible' => (bool) ($c['public_visible'] ?? 1),
            'published_at' => $c['published_at'],
            'public_slug' => $c['public_slug'],
            'public_title' => $c['public_title'],
            'public_description' => $c['public_description'],
            'vertical' => $c['vertical'] ?? defaultVerticalFor($projet),
            'projet' => $projet,
            'is_draft' => (bool) ($c['is_draft'] ?? 0),
            'is_archived' => (bool) ($c['archived'] ?? 0),
            'is_publishable' => isOfferDossier($projet),
            'data' => $data,
            'agent' => [
                'prenom' => $user['prenom'] ?? '',
                'nom' => $user['nom'] ?? '',
                'email' => $user['email'] ?? '',
            ],
        ]);
    }

    case 'publish': {
        checkRateLimit('publish_bien', 10, 3600, (int) $user['id']);
        $id = (int) ($input['client_id'] ?? 0);
        if (!$id) jsonError('client_id requis');
        $c = fetchClientOwned($id, (int) $user['id']);
        if (!$c) jsonError('Dossier introuvable', 404);
        if (!empty($c['is_draft'])) jsonError('Complète le dossier avant publication (brouillon)', 400);
        if (!isOfferDossier($c['projet'])) jsonError('Seuls les dossiers Vendeur ou Bailleur peuvent être publiés', 400);

        $title = trim((string) ($input['public_title'] ?? ''));
        $desc = trim((string) ($input['public_description'] ?? ''));
        if ($title === '') jsonError('Titre requis');

        $vertical = (string) ($input['vertical'] ?? defaultVerticalFor($c['projet']));
        if (!in_array($vertical, ['vente', 'location_longue', 'sejour_court'], true)) {
            jsonError('vertical invalide', 400);
        }
        if (normProjet($c['projet']) === 'vendeur' && $vertical !== 'vente') {
            jsonError('Un dossier Vendeur ne peut être publié qu\'en vertical "vente"', 400);
        }

        $slug = trim((string) ($input['public_slug'] ?? ''));
        if ($slug === '') $slug = slugify($title) . '-' . $id;
        else $slug = slugify($slug);

        // Collision : si public_slug déjà pris par un autre dossier, suffixer.
        $chk = db()->prepare("SELECT id FROM clients WHERE public_slug = ? AND id != ? LIMIT 1");
        $chk->execute([$slug, $id]);
        if ($chk->fetch()) $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 4);

        $up = db()->prepare("UPDATE clients SET is_published = 1, public_visible = 1, published_at = NOW(),
            public_slug = ?, public_title = ?, public_description = ?, vertical = ?
            WHERE id = ? AND user_id = ?");
        $up->execute([$slug, $title, $desc, $vertical, $id, (int) $user['id']]);

        try { logAccess((int) $user['id'], 'publish_bien', ['client_id' => $id, 'slug' => $slug]); } catch (Exception $e) {}

        jsonOk([
            'client_id' => $id,
            'public_slug' => $slug,
            'vertical' => $vertical,
            'public_url' => buildPublicUrl($vertical, $slug),
            'message' => 'Annonce enregistrée. La vitrine ocre.immo est en cours de création.',
        ]);
    }

    case 'unpublish': {
        $id = (int) ($input['client_id'] ?? 0);
        if (!$id) jsonError('client_id requis');
        $c = fetchClientOwned($id, (int) $user['id']);
        if (!$c) jsonError('Dossier introuvable', 404);
        $up = db()->prepare("UPDATE clients SET is_published = 0, published_at = NULL WHERE id = ? AND user_id = ?");
        $up->execute([$id, (int) $user['id']]);
        try { logAccess((int) $user['id'], 'unpublish_bien', ['client_id' => $id]); } catch (Exception $e) {}
        jsonOk(['client_id' => $id, 'is_published' => false]);
    }

    case 'toggle_visibility': {
        $id = (int) ($input['client_id'] ?? 0);
        $visible = !empty($input['public_visible']) ? 1 : 0;
        if (!$id) jsonError('client_id requis');
        $c = fetchClientOwned($id, (int) $user['id']);
        if (!$c) jsonError('Dossier introuvable', 404);
        $up = db()->prepare("UPDATE clients SET public_visible = ? WHERE id = ? AND user_id = ?");
        $up->execute([$visible, $id, (int) $user['id']]);
        try { logAccess((int) $user['id'], 'toggle_visibility_bien', ['client_id' => $id, 'visible' => $visible]); } catch (Exception $e) {}
        jsonOk(['client_id' => $id, 'public_visible' => (bool) $visible]);
    }

    case 'update': {
        checkRateLimit('update_publication', 30, 3600, (int) $user['id']);
        $id = (int) ($input['client_id'] ?? 0);
        if (!$id) jsonError('client_id requis');
        $c = fetchClientOwned($id, (int) $user['id']);
        if (!$c) jsonError('Dossier introuvable', 404);
        if (empty($c['is_published'])) jsonError('Dossier non publié — utilise publish à la place', 400);

        $fields = [];
        $params = [];
        if (array_key_exists('public_title', $input)) {
            $t = trim((string) $input['public_title']);
            if ($t === '') jsonError('Titre requis');
            $fields[] = 'public_title = ?'; $params[] = $t;
        }
        if (array_key_exists('public_description', $input)) {
            $fields[] = 'public_description = ?'; $params[] = trim((string) $input['public_description']);
        }
        if (array_key_exists('vertical', $input)) {
            $v = (string) $input['vertical'];
            if (!in_array($v, ['vente', 'location_longue', 'sejour_court'], true)) jsonError('vertical invalide');
            if (normProjet($c['projet']) === 'vendeur' && $v !== 'vente') {
                jsonError('Un dossier Vendeur ne peut être publié qu\'en vertical "vente"', 400);
            }
            $fields[] = 'vertical = ?'; $params[] = $v;
        }
        if (!$fields) jsonError('Aucun champ à mettre à jour');

        $params[] = $id; $params[] = (int) $user['id'];
        $sql = 'UPDATE clients SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?';
        db()->prepare($sql)->execute($params);

        try { logAccess((int) $user['id'], 'update_publication', ['client_id' => $id]); } catch (Exception $e) {}

        $fresh = fetchClientOwned($id, (int) $user['id']);
        jsonOk([
            'client_id' => $id,
            'public_url' => buildPublicUrl($fresh['vertical'] ?: 'vente', $fresh['public_slug'] ?: ''),
            'message' => 'Publication mise à jour',
        ]);
    }

    default:
        jsonError('Action inconnue', 404);
}
