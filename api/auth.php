<?php
// V20 — Redirection legacy auth.php → auth_v20.php (multi-tenant).
// Préserve méthode + body + query string + headers + cookies (pas de HTTP loop, simple require).
// Backup du legacy : auth_legacy_pre_v20.php.
require_once __DIR__ . '/auth_v20.php';
