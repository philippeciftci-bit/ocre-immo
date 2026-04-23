<?php
// V18.17 — one-shot IP-whitelist VPS : supprime les dossiers existants
// avec d.from_url_import=true qui prédatent le staging. Philippe a pré-validé
// la suppression ("supprimer si question se présente").
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['46.225.215.148'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'IP refusée (' . $ip . ')']);
    exit;
}

$pdo = db();
// Migration douce du schéma (idempotent).
foreach ([
    "ALTER TABLE clients ADD COLUMN is_staged TINYINT NOT NULL DEFAULT 0",
    "ALTER TABLE clients ADD COLUMN promoted_at DATETIME NULL",
    "ALTER TABLE clients ADD INDEX idx_staged (user_id, is_staged)",
] as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) { /* colonne / index présent */ }
}

// Liste avant suppression (pour logs).
$stmt = $pdo->query(
    "SELECT id, user_id, prenom, nom, societe_nom
       FROM clients
      WHERE JSON_EXTRACT(data, '$.from_url_import') = CAST('true' AS JSON)
         OR JSON_EXTRACT(data, '$.from_url_import') = TRUE"
);
$before = $stmt->fetchAll();

// Suppression.
$del = $pdo->exec(
    "DELETE FROM clients
      WHERE JSON_EXTRACT(data, '$.from_url_import') = CAST('true' AS JSON)
         OR JSON_EXTRACT(data, '$.from_url_import') = TRUE"
);

echo json_encode([
    'ok' => true,
    'deleted_count' => (int) $del,
    'deleted_ids' => array_map(fn($r) => (int) $r['id'], $before),
    'schema_migration' => 'is_staged/promoted_at/idx_staged appliqués',
], JSON_UNESCAPED_UNICODE);
