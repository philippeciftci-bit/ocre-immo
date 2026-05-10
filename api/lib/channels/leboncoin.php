<?php
// M104 — Driver LeBonCoin Pro Immo (mode STUB).
// Quand Philippe aura les vrais credentials API LBC :
//   - changer MOCK_BASE par https://api.leboncoin.fr/...
//   - ajouter les headers Auth Bearer dans request()
//   - mapping fields actuel reste valide

require_once __DIR__ . '/ChannelDriver.php';

class LeBonCoinDriver implements ChannelDriver {
    private const MOCK_BASE = 'http://127.0.0.1:8888/mock/leboncoin/listings';

    public function getName(): string { return 'leboncoin'; }

    public function getRequiredFields(): array {
        return ['title', 'description', 'price', 'photos', 'category', 'location'];
    }

    public function getMaxLengths(): array {
        return ['title' => 50, 'description' => 4000];
    }

    public function validateListing(array $listing): array {
        $missing = [];
        $warnings = [];
        $title = trim($listing['title'] ?? '');
        $desc = trim($listing['description'] ?? '');
        $price = (float) ($listing['price'] ?? 0);
        $photos = $listing['photos'] ?? [];
        if (mb_strlen($title) < 30) $missing[] = 'title (30 chars min, ' . mb_strlen($title) . ' actuels)';
        if (mb_strlen($title) > 50) $missing[] = 'title (50 chars max depasse)';
        if (mb_strlen($desc) < 200) $missing[] = 'description (200 chars min, ' . mb_strlen($desc) . ' actuels)';
        if ($price <= 0) $missing[] = 'price > 0';
        if (!is_array($photos) || count($photos) < 3) $missing[] = 'photos (3 minimum, ' . (is_array($photos) ? count($photos) : 0) . ' actuelles)';
        if (empty($listing['category'])) $missing[] = 'category';
        if (empty($listing['location'])) $missing[] = 'location';
        if (empty($listing['dpe'])) $warnings[] = 'DPE absent (recommande)';
        return ['ok' => empty($missing), 'missing_fields' => $missing, 'warnings' => $warnings];
    }

    private function mapToLbc(array $listing): array {
        return [
            'title' => mb_substr($listing['title'], 0, 50),
            'body' => mb_substr($listing['description'], 0, 4000),
            'price' => (int) $listing['price'],
            'currency' => $listing['currency'] ?? 'EUR',
            'images' => array_map(fn($p) => is_array($p) ? $p['url'] : $p, $listing['photos'] ?? []),
            'category' => $listing['category'],
            'location' => [
                'city' => $listing['location']['city'] ?? '',
                'zipcode' => $listing['location']['zipcode'] ?? '',
                'lat' => $listing['location']['lat'] ?? null,
                'lng' => $listing['location']['lng'] ?? null,
            ],
            'attributes' => [
                'real_estate_type' => $listing['real_estate_type'] ?? 'apartment',
                'square' => $listing['surface_m2'] ?? null,
                'rooms' => $listing['rooms'] ?? null,
                'energy_rate' => $listing['dpe'] ?? null,
            ],
        ];
    }

    public function publish(array $listing, array $credentials): array {
        $payload = $this->mapToLbc($listing);
        $r = ChannelHttpClient::request('POST', self::MOCK_BASE, $payload);
        if ($r['status_code'] === 201 && isset($r['body']['id'])) {
            return ['ok' => true, 'external_id' => $r['body']['id'], 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
        }
        $err = $r['body']['error'] ?? $r['error'] ?? 'unknown';
        return ['ok' => false, 'error' => $err, 'status_code' => $r['status_code'], 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
    }

    public function update(string $external_id, array $changes, array $credentials): array {
        $payload = $this->mapToLbc($changes);
        $r = ChannelHttpClient::request('PUT', self::MOCK_BASE . '/' . urlencode($external_id), $payload);
        $ok = $r['status_code'] === 200;
        return ['ok' => $ok, 'error' => $ok ? null : ($r['body']['error'] ?? 'http_' . $r['status_code']), 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
    }

    public function delete(string $external_id, array $credentials): array {
        $r = ChannelHttpClient::request('DELETE', self::MOCK_BASE . '/' . urlencode($external_id));
        $ok = in_array($r['status_code'], [200, 204]);
        return ['ok' => $ok, 'error' => $ok ? null : 'http_' . $r['status_code'], 'duration_ms' => $r['duration_ms']];
    }

    public function getStatus(string $external_id, array $credentials): array {
        $r = ChannelHttpClient::request('GET', self::MOCK_BASE . '/' . urlencode($external_id));
        if ($r['status_code'] !== 200) {
            return ['ok' => false, 'status' => 'unknown', 'error' => 'http_' . $r['status_code']];
        }
        $b = $r['body'] ?? [];
        return [
            'ok' => true,
            'status' => $b['status'] ?? 'published',
            'views' => $b['views'] ?? 0,
            'last_modif' => $b['updated_at'] ?? null,
        ];
    }
}
