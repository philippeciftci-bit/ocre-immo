<?php
// M/2026/04/28/62 — Instagram stub (API officielle payante, scraping bloqué).
require_once __DIR__ . '/ExternalSource.php';

class InstagramAdapter extends ExternalSource {
    public function getName(): string { return 'instagram'; }
    public function getRateLimit(): int { return 1; }

    public function search(array $criteria): array {
        return ['results' => [], 'error' => 'instagram_coming_soon'];
    }
}
