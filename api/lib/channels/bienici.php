<?php
// M104 — Driver Bien'ici (mode STUB).
// Vrai Bien'ici : API REST avec OAuth2. En mode stub, mock server local.

require_once __DIR__ . '/ChannelDriver.php';

class BienIciDriver implements ChannelDriver {
    private const MOCK_BASE = 'http://127.0.0.1:8888/mock/bienici/listings';

    public function getName(): string { return 'bienici'; }

    public function getRequiredFields(): array {
        return ['title', 'description', 'price', 'transaction_type', 'photos', 'real_estate_type', 'location'];
    }

    public function getMaxLengths(): array {
        return ['title' => 100, 'description' => 6000];
    }

    public function validateListing(array $listing): array {
        $missing = [];
        $warnings = [];
        if (empty($listing['title'])) $missing[] = 'title';
        if (empty($listing['description'])) $missing[] = 'description';
        if ((float) ($listing['price'] ?? 0) <= 0) $missing[] = 'price > 0';
        if (empty($listing['transaction_type'])) $missing[] = 'transaction_type (vente/location)';
        $photos = $listing['photos'] ?? [];
        if (!is_array($photos) || count($photos) < 2) $missing[] = 'photos (2 minimum)';
        if (empty($listing['real_estate_type'])) $missing[] = 'real_estate_type';
        if (empty($listing['location']['city'] ?? null)) $missing[] = 'location.city';
        // Geoloc precise = warning si absente
        if (empty($listing['location']['lat'] ?? null) || empty($listing['location']['lng'] ?? null)) {
            $warnings[] = 'Geolocalisation precise (lat/lng) recommandee';
        }
        return ['ok' => empty($missing), 'missing_fields' => $missing, 'warnings' => $warnings];
    }

    private function mapToBienIci(array $listing): array {
        return [
            'reference' => $listing['reference'] ?? null,
            'title' => mb_substr($listing['title'], 0, 100),
            'description' => mb_substr($listing['description'], 0, 6000),
            'price' => (int) $listing['price'],
            'currency' => $listing['currency'] ?? 'EUR',
            'transaction' => $listing['transaction_type'] === 'rent' ? 'location' : 'vente',
            'propertyType' => $listing['real_estate_type'] ?? 'flat',
            'rooms' => $listing['rooms'] ?? null,
            'bedrooms' => $listing['bedrooms'] ?? null,
            'surface' => $listing['surface_m2'] ?? null,
            'photos' => array_map(fn($p) => is_array($p) ? $p['url'] : $p, $listing['photos']),
            'location' => [
                'city' => $listing['location']['city'],
                'postalCode' => $listing['location']['zipcode'] ?? null,
                'coordinates' => isset($listing['location']['lat']) ? [
                    'lat' => $listing['location']['lat'],
                    'lng' => $listing['location']['lng'],
                ] : null,
            ],
            'energyClass' => $listing['dpe'] ?? null,
        ];
    }

    public function publish(array $listing, array $credentials): array {
        $payload = $this->mapToBienIci($listing);
        $r = ChannelHttpClient::request('POST', self::MOCK_BASE, $payload);
        if ($r['status_code'] === 201 && isset($r['body']['id'])) {
            return ['ok' => true, 'external_id' => $r['body']['id'], 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
        }
        $err = $r['body']['error'] ?? $r['error'] ?? 'unknown';
        return ['ok' => false, 'error' => $err, 'status_code' => $r['status_code'], 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
    }

    public function update(string $external_id, array $changes, array $credentials): array {
        $payload = $this->mapToBienIci($changes);
        $r = ChannelHttpClient::request('PUT', self::MOCK_BASE . '/' . urlencode($external_id), $payload);
        $ok = $r['status_code'] === 200;
        return ['ok' => $ok, 'error' => $ok ? null : ($r['body']['error'] ?? 'http_' . $r['status_code']), 'duration_ms' => $r['duration_ms']];
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
        return ['ok' => true, 'status' => $b['status'] ?? 'published', 'views' => $b['views'] ?? 0, 'last_modif' => $b['updated_at'] ?? null];
    }
}
