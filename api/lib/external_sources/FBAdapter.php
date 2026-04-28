<?php
// M/2026/04/28/62 — FB Marketplace stub (nécessite session cookie Philippe).
require_once __DIR__ . '/ExternalSource.php';

class FBAdapter extends ExternalSource {
    public function getName(): string { return 'fb_marketplace'; }
    public function getRateLimit(): int { return 5; }

    public function search(array $criteria): array {
        $cookieFile = '/root/.secrets/fb_session.json';
        if (!is_file($cookieFile) || filesize($cookieFile) < 10) {
            return ['results' => [], 'error' => 'fb_session_not_configured'];
        }
        return ['results' => [], 'error' => 'not_implemented_v1'];
    }
}
