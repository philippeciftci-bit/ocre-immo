<?php
// M/2026/05/07/7 — Helper privé provisionnement workspace agent.
// Préfixe `_` = pas servi par le routing public (regex nginx /api/*.php exclut _*.php).
// Convention OVH/MariaDB : user master `ocre_app` partagé. CREATE DATABASE + GRANT ALL sur la nouvelle DB.

require_once __DIR__ . '/db.php';

const PROVISION_TEMPLATE_SQL = __DIR__ . '/../schema/ocre_wsp_template.sql';
const PROVISION_EXPECTED_TABLES = ['clients', 'logs', 'sessions', 'settings'];

/**
 * Provisionne une nouvelle DB workspace pour un agent.
 *
 * @param string $slug   Slug agent (regex strict /^[a-z0-9-]{3,40}$/).
 * @param PDO    $pdoMeta Connexion meta (admin, doit avoir CREATE/DROP/GRANT).
 * @return array {ok, database?, tables?, error?, rollback_done?}
 */
function provision_agent_workspace(string $slug, PDO $pdoMeta): array {
    // Validation regex STRICTE anti-injection (slug exclusivement [a-z0-9-] 3-40 chars).
    if (!preg_match('/^[a-z0-9-]{3,40}$/', $slug)) {
        return ['ok' => false, 'error' => 'slug_invalid', 'detail' => 'Slug doit matcher /^[a-z0-9-]{3,40}$/.'];
    }
    $dbName = 'ocre_wsp_' . $slug;

    if (!is_readable(PROVISION_TEMPLATE_SQL)) {
        return ['ok' => false, 'error' => 'template_missing', 'detail' => 'Schema template introuvable.'];
    }
    $templateSql = file_get_contents(PROVISION_TEMPLATE_SQL);
    if ($templateSql === false || trim($templateSql) === '') {
        return ['ok' => false, 'error' => 'template_empty'];
    }

    // Anti-collision : refus si DB existe deja.
    try {
        $existsStmt = $pdoMeta->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $existsStmt->execute([$dbName]);
        if ($existsStmt->fetchColumn() !== false) {
            return ['ok' => false, 'error' => 'database_already_exists', 'database' => $dbName];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'preflight_failed', 'detail' => $e->getMessage()];
    }

    $rollbackDone = false;
    try {
        // CREATE DATABASE (charset utf8mb4_unicode_ci).
        // Backticks autour du nom (DB validee regex), pas de placeholder PDO sur DDL.
        $pdoMeta->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // M/2026/05/07/7 — Pas de GRANT explicite. Le user master ocre_app a deja un wildcard
        // `GRANT ALL PRIVILEGES ON ocre\_%.* TO ocre_app@localhost` qui couvre toutes les DBs
        // ocre_*. CREATE DATABASE est dans ce scope donc le user peut immediatement SELECT/INSERT
        // sans GRANT additionnel. Tentative GRANT explicit echoue (ocre_app n a pas GRANT OPTION).

        // Connexion a la nouvelle DB pour executer le template.
        $pdoNew = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]
        );

        // Decoupage statements par ';' suivi de fin de ligne (preserve ; dans json_valid).
        // Les CREATE TABLE et INSERT du template ne contiennent pas de ; dans des strings.
        $statements = preg_split('/;\s*\n/', $templateSql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) continue;
            $pdoNew->exec($stmt);
        }

        // Verification post-creation : SHOW TABLES doit retourner exactement les tables attendues.
        $tablesStmt = $pdoNew->query("SHOW TABLES");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        sort($tables);
        $expected = PROVISION_EXPECTED_TABLES;
        sort($expected);
        if ($tables !== $expected) {
            // Tables manquantes ou en surplus.
            throw new RuntimeException('post_create_tables_mismatch: got=' . implode(',', $tables) . ' expected=' . implode(',', $expected));
        }

        return [
            'ok' => true,
            'database' => $dbName,
            'tables' => $tables,
        ];
    } catch (Throwable $e) {
        // Rollback : DROP DATABASE (slug deja valide regex en haut, safe).
        try {
            $pdoMeta->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            $rollbackDone = true;
        } catch (Throwable $_) {
            $rollbackDone = false;
        }
        return [
            'ok' => false,
            'error' => 'provision_failed',
            'detail' => $e->getMessage(),
            'database' => $dbName,
            'rollback_done' => $rollbackDone,
        ];
    }
}
