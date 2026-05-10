<?php
// M106 — Driver Mubawab (Maroc + Tunisie + Algerie) — mode STUB.
// Mubawab : portail specialiste immo Maghreb. Format reel = XML feed partner program.
// Mode stub : POST mock + ecriture XML feed local /tmp/mock-mubawab-feed/<tenant>/<extId>.xml

require_once __DIR__ . '/ChannelDriver.php';
require_once __DIR__ . '/i18n_helper.php';

class MubawabDriver implements ChannelDriver {
    private const MOCK_BASE = 'http://127.0.0.1:8888/mock/mubawab/listings';
    private const MOCK_FEED_DIR = '/tmp/mock-mubawab-feed';
    private const SUPPORTED_COUNTRIES = ['MA', 'TN', 'DZ'];
    private const COUNTRY_CURRENCY = ['MA' => 'MAD', 'TN' => 'TND', 'DZ' => 'DZD'];

    public function getName(): string { return 'mubawab'; }

    public function getRequiredFields(): array {
        return ['title', 'description', 'price', 'photos', 'real_estate_type', 'transaction_type', 'surface_m2', 'location', 'country_code'];
    }

    public function getMaxLengths(): array {
        return ['title' => 100, 'description' => 6000];
    }

    public function validateListing(array $listing): array {
        $missing = [];
        $warnings = [];
        $title = trim($listing['title'] ?? '');
        $desc = trim($listing['description'] ?? '');
        if (mb_strlen($title) < 25) $missing[] = 'titre (25 caractères min, ' . mb_strlen($title) . ' actuels)';
        if (mb_strlen($desc) < 150) $missing[] = 'description (150 caractères min, ' . mb_strlen($desc) . ' actuels)';
        if ((float) ($listing['price'] ?? 0) <= 0) $missing[] = 'prix > 0';
        $photos = $listing['photos'] ?? [];
        if (!is_array($photos) || count($photos) < 3) $missing[] = 'photos (3 minimum, ' . (is_array($photos) ? count($photos) : 0) . ' actuelles)';
        if (empty($listing['real_estate_type'])) $missing[] = 'type_bien';
        if (empty($listing['transaction_type'])) $missing[] = 'transaction_type (vente/location)';
        if (empty($listing['surface_m2'])) $missing[] = 'surface_m2';
        if (empty($listing['location']['city'] ?? null)) $missing[] = 'location.city';
        // Pays support : MA / TN / DZ uniquement
        $country = strtoupper($listing['country_code'] ?? '');
        if (!$country) {
            $missing[] = 'country_code (pays du bien)';
        } elseif (!in_array($country, self::SUPPORTED_COUNTRIES, true)) {
            $missing[] = 'Mubawab disponible uniquement pour MA / TN / DZ (reçu : ' . $country . ')';
        }
        return ['ok' => empty($missing), 'missing_fields' => $missing, 'warnings' => $warnings];
    }

    private function mapToMubawab(array $listing): array {
        $country = strtoupper($listing['country_code'] ?? 'MA');
        $targetCurrency = self::COUNTRY_CURRENCY[$country] ?? 'MAD';
        $currency = strtoupper($listing['currency'] ?? $targetCurrency);
        $price = (float) ($listing['price'] ?? 0);
        $priceConv = ChannelI18n::convertCurrency($price, $currency, $targetCurrency);
        return [
            'reference' => $listing['reference'] ?? null,
            'title' => mb_substr($listing['title'] ?? '', 0, 100),
            'description' => mb_substr($listing['description'] ?? '', 0, 6000),
            'price' => $priceConv['amount'],
            'currency' => $targetCurrency,
            'price_original' => $priceConv['converted'] ? ['amount' => $price, 'currency' => $currency, 'rate' => $priceConv['rate']] : null,
            'photos' => array_map(fn($p) => is_array($p) ? $p['url'] : $p, $listing['photos'] ?? []),
            'property_type' => ChannelI18n::mapPropertyType($listing['real_estate_type'] ?? 'apartment', 'mubawab'),
            'transaction' => $listing['transaction_type'] === 'rent' ? 'rent' : 'sale',
            'surface_m2' => $listing['surface_m2'] ?? null,
            'rooms' => $listing['rooms'] ?? null,
            'bedrooms' => $listing['bedrooms'] ?? null,
            'bathrooms' => $listing['bathrooms'] ?? null,
            'built_year' => $listing['built_year'] ?? null,
            'parking_spots' => $listing['parking_spots'] ?? (!empty($listing['parking']) ? 1 : 0),
            'garden_size_m2' => $listing['garden_size_m2'] ?? null,
            'pool' => !empty($listing['pool']),
            'security' => !empty($listing['security']),
            'location' => [
                'country' => $country,
                'city' => $listing['location']['city'] ?? '',
                'neighborhood' => $listing['location']['neighborhood'] ?? null,
                'zipcode' => $listing['location']['zipcode'] ?? null,
                'lat' => $listing['location']['lat'] ?? null,
                'lng' => $listing['location']['lng'] ?? null,
            ],
            '_meta' => [
                'currency_converted' => $priceConv['converted'],
                'country' => $country,
            ],
        ];
    }

    private function generateFeedXml(string $extId, array $payload): string {
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_XML1, 'UTF-8');
        $photos = '';
        foreach (($payload['photos'] ?? []) as $idx => $url) {
            $photos .= "    <Photo Order=\"" . ($idx + 1) . "\" Url=\"" . $esc($url) . "\"/>\n";
        }
        $loc = $payload['location'];
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<MubawabListing id="' . $esc($extId) . '" feed="ocre-immo-partner">' . "\n"
            . '  <Country>' . $esc($loc['country']) . '</Country>' . "\n"
            . '  <City>' . $esc($loc['city']) . '</City>' . "\n"
            . '  <Neighborhood>' . $esc($loc['neighborhood'] ?? '') . '</Neighborhood>' . "\n"
            . '  <PropertyType>' . $esc($payload['property_type']) . '</PropertyType>' . "\n"
            . '  <Transaction>' . $esc($payload['transaction']) . '</Transaction>' . "\n"
            . '  <Title>' . $esc($payload['title']) . '</Title>' . "\n"
            . '  <Description><![CDATA[' . $payload['description'] . ']]></Description>' . "\n"
            . '  <Price currency="' . $esc($payload['currency']) . '">' . $esc($payload['price']) . '</Price>' . "\n"
            . '  <Surface unit="m2">' . $esc($payload['surface_m2']) . '</Surface>' . "\n"
            . '  <Bedrooms>' . $esc($payload['bedrooms'] ?? '') . '</Bedrooms>' . "\n"
            . '  <Bathrooms>' . $esc($payload['bathrooms'] ?? '') . '</Bathrooms>' . "\n"
            . '  <ParkingSpots>' . $esc($payload['parking_spots']) . '</ParkingSpots>' . "\n"
            . '  <Pool>' . ($payload['pool'] ? 'true' : 'false') . '</Pool>' . "\n"
            . '  <Security>' . ($payload['security'] ? 'true' : 'false') . '</Security>' . "\n"
            . '  <Photos>' . "\n" . $photos . '  </Photos>' . "\n"
            . '</MubawabListing>' . "\n";
    }

    private function ensureFeedDir(string $tenant): string {
        $dir = self::MOCK_FEED_DIR . '/' . $tenant;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    public function publish(array $listing, array $credentials): array {
        $payload = $this->mapToMubawab($listing);
        $r = ChannelHttpClient::request('POST', self::MOCK_BASE, $payload);
        if ($r['status_code'] !== 201 || empty($r['body']['id'])) {
            $err = $r['body']['error'] ?? $r['error'] ?? 'unknown';
            return ['ok' => false, 'error' => $err, 'status_code' => $r['status_code'], 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
        }
        $extId = $r['body']['id'];
        if (strpos($extId, 'MB_') !== 0) $extId = 'MB_' . substr($extId, 3);
        $tenant = $listing['_tenant_slug'] ?? 'unknown';
        $dir = $this->ensureFeedDir($tenant);
        $xmlPath = $dir . '/' . $extId . '.xml';
        $xml = $this->generateFeedXml($extId, $payload);
        @file_put_contents($xmlPath, $xml);
        return ['ok' => true, 'external_id' => $extId, 'duration_ms' => $r['duration_ms'], 'response' => $r['body'], 'xml_feed' => $xmlPath, 'meta' => $payload['_meta']];
    }

    public function update(string $external_id, array $changes, array $credentials): array {
        $payload = $this->mapToMubawab($changes);
        $r = ChannelHttpClient::request('PUT', self::MOCK_BASE . '/' . urlencode($external_id), $payload);
        $ok = $r['status_code'] === 200;
        if ($ok) {
            $tenant = $changes['_tenant_slug'] ?? 'unknown';
            $dir = $this->ensureFeedDir($tenant);
            @file_put_contents($dir . '/' . $external_id . '.xml', $this->generateFeedXml($external_id, $payload));
        }
        return ['ok' => $ok, 'error' => $ok ? null : ($r['body']['error'] ?? 'http_' . $r['status_code']), 'duration_ms' => $r['duration_ms']];
    }

    public function delete(string $external_id, array $credentials): array {
        $r = ChannelHttpClient::request('DELETE', self::MOCK_BASE . '/' . urlencode($external_id));
        $ok = in_array($r['status_code'], [200, 204]);
        foreach (glob(self::MOCK_FEED_DIR . '/*/' . $external_id . '.xml') as $f) @unlink($f);
        return ['ok' => $ok, 'error' => $ok ? null : 'http_' . $r['status_code'], 'duration_ms' => $r['duration_ms']];
    }

    public function getStatus(string $external_id, array $credentials): array {
        $r = ChannelHttpClient::request('GET', self::MOCK_BASE . '/' . urlencode($external_id));
        if ($r['status_code'] !== 200) return ['ok' => false, 'status' => 'unknown', 'error' => 'http_' . $r['status_code']];
        $b = $r['body'] ?? [];
        return ['ok' => true, 'status' => $b['status'] ?? 'published', 'views' => $b['views'] ?? 0, 'last_modif' => $b['updated_at'] ?? null];
    }
}
