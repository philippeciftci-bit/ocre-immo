<?php
// M/2026/05/13/14 — Limites upload centralisees (audit M/13/3 Axe 5).
// Source unique de verite cote backend. Frontend lit via /api/upload_limits.php?action=get.
return [
    'bien_photos' => [
        'count' => 30,
        'size_bytes' => 15 * 1024 * 1024,
        'formats' => ['image/jpeg','image/png','image/webp','application/pdf'],
    ],
    'identite_photos' => [
        'count' => 3,
        'size_bytes' => 15 * 1024 * 1024,
        'formats' => ['image/jpeg','image/png','image/webp','application/pdf'],
    ],
    'agent_avatar' => [
        'count' => 1,
        'size_bytes' => 5 * 1024 * 1024,
        'formats' => ['image/jpeg','image/png','image/webp'],
    ],
    'document_attachment' => [
        'count' => 30,
        'size_bytes' => 10 * 1024 * 1024,
        'formats' => ['image/jpeg','image/png','application/pdf'],
    ],
];
