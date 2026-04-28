<?php
// M/2026/04/28/62 — Interface abstraite pour adapters scan web.

abstract class ExternalSource {
    abstract public function search(array $criteria): array;
    abstract public function getName(): string;
    abstract public function getRateLimit(): int;

    protected function fetchHtml(string $url, int $timeout = 8): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OcreImmo/1.0; +https://ocre.immo)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9',
                'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) return null;
        return $body;
    }

    protected function makeListing(array $data): array {
        return [
            'source' => $data['source'] ?? $this->getName(),
            'source_id' => $data['source_id'] ?? null,
            'url' => $data['url'] ?? '',
            'title' => $data['title'] ?? '',
            'price' => $data['price'] ?? null,
            'currency' => $data['currency'] ?? 'EUR',
            'location_text' => $data['location_text'] ?? '',
            'surface' => $data['surface'] ?? null,
            'rooms' => $data['rooms'] ?? null,
            'photos' => $data['photos'] ?? [],
            'description' => $data['description'] ?? '',
            'scraped_at' => date('c'),
        ];
    }
}
