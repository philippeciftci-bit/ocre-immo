<?php
// M/2026/05/05/52 — endpoint persistance editeur PDF inline.
// POST {bien_id, blocks} -> UPDATE clients SET data = JSON_SET(data, '$.bien.pdf_editor_state', ?)
// blocks = {title:{visible,value}, subtitle:{...}, descriptif_lead:{...}, descriptif_texte:{...},
//           agent:{visible,name,phone,email}, price:{visible,amount,currency}, ...}

require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$input = getInput();
$bien_id = (int) ($input['bien_id'] ?? 0);
$blocks = $input['blocks'] ?? null;

if (!$bien_id) jsonError('bien_id requis', 400);
if (!is_array($blocks)) jsonError('blocks doit etre un objet', 400);

// Ownership : meme tenant + user_id match.
$chk = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
$chk->execute([$bien_id, $user['id']]);
if (!$chk->fetch()) jsonError('Acces refuse', 403);

$state = ['blocks' => $blocks, 'updated_at' => gmdate('c')];
$json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) jsonError('Encode JSON impossible', 500);

try {
    // S assurer que bien existe puis JSON_SET pdf_editor_state.
    db()->prepare("UPDATE clients SET data = JSON_SET(IFNULL(data, JSON_OBJECT()), '$.bien', IFNULL(JSON_EXTRACT(data, '$.bien'), JSON_OBJECT())) WHERE id = ?")->execute([$bien_id]);
    db()->prepare("UPDATE clients SET data = JSON_SET(data, '$.bien.pdf_editor_state', JSON_EXTRACT(?, '$')) WHERE id = ?")->execute([$json, $bien_id]);
} catch (Throwable $e) {
    jsonError('Persistance echouee: ' . $e->getMessage(), 500);
}

jsonOk(['saved' => true, 'state' => $state]);
