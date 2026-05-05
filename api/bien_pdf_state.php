<?php
// M/2026/05/05/52 — endpoint persistance editeur PDF inline.
// M/2026/05/05/61 — etendu pour accepter pages : {p1,p2,p3} en plus de blocks.
// POST {bien_id, blocks?, pages?} -> JSON_SET data.bien.pdf_editor_state, merge avec etat existant.
// blocks = {title:{visible,value}, subtitle:{...}, descriptif_lead:{...}, descriptif_texte:{...},
//           agent:{visible,name,phone,email}, price:{visible,amount,currency}, ...}
// pages  = {p1: bool, p2: bool, p3: bool} — selection pages actives dans PDF final.

require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$input = getInput();
$bien_id = (int) ($input['bien_id'] ?? 0);
$blocks = $input['blocks'] ?? null;
$pages = $input['pages'] ?? null;

if (!$bien_id) jsonError('bien_id requis', 400);
if (!is_array($blocks) && !is_array($pages)) jsonError('blocks ou pages requis', 400);

// Ownership : meme tenant + user_id match.
$chk = db()->prepare("SELECT data FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
$chk->execute([$bien_id, $user['id']]);
$row = $chk->fetch(PDO::FETCH_ASSOC);
if (!$row) jsonError('Acces refuse', 403);

// Lire l etat existant pour merger sans ecraser l autre cle.
$existing = ['blocks' => [], 'pages' => null];
try {
    $dataObj = json_decode($row['data'] ?? '{}', true) ?: [];
    if (isset($dataObj['bien']['pdf_editor_state']) && is_array($dataObj['bien']['pdf_editor_state'])) {
        $existing = array_merge($existing, $dataObj['bien']['pdf_editor_state']);
    }
} catch (Throwable $e) { /* fallback empty */ }

$state = $existing;
if (is_array($blocks)) $state['blocks'] = $blocks;
if (is_array($pages))  $state['pages']  = ['p1' => !empty($pages['p1']), 'p2' => !empty($pages['p2']), 'p3' => !empty($pages['p3'])];
$state['updated_at'] = gmdate('c');

$json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) jsonError('Encode JSON impossible', 500);

try {
    db()->prepare("UPDATE clients SET data = JSON_SET(IFNULL(data, JSON_OBJECT()), '$.bien', IFNULL(JSON_EXTRACT(data, '$.bien'), JSON_OBJECT())) WHERE id = ?")->execute([$bien_id]);
    db()->prepare("UPDATE clients SET data = JSON_SET(data, '$.bien.pdf_editor_state', JSON_EXTRACT(?, '$')) WHERE id = ?")->execute([$json, $bien_id]);
} catch (Throwable $e) {
    jsonError('Persistance echouee: ' . $e->getMessage(), 500);
}

jsonOk(['saved' => true, 'state' => $state]);
