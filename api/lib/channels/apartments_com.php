<?php
// M105 — Driver Apartments.com (US, USD) — mode STUB.
// Format reel : XML feed ILS (Internet Listing Standards) push S3 ou FTP.
// Mode stub : ecrit XML dans /tmp/mock-apartments-feed/<tenant>/<dossier>.xml
// + POST mock pour tracking external_id.

require_once __DIR__ . '/ChannelDriver.php';
require_once __DIR__ . '/i18n_helper.php';

class ApartmentsComDriver implements ChannelDriver {
    private const MOCK_BASE = 'http://127.0.0.1:8888/mock/apartments_com/listings';
    private const MOCK_FEED_DIR = '/tmp/mock-apartments-feed';

    public function getName(): string { return 'apartments_com'; }

    public function getRequiredFields(): array {
        return ['title', 'description', 'price', 'photos', 'bedrooms', 'bathrooms', 'surface_sqft', 'real_estate_type', 'location'];
    }

    public function getMaxLengths(): array {
        return ['title' => 100, 'description' => 4000];
    }

    public function validateListing(array $listing): array {
        $missing = [];
        $warnings = [];
        if (empty($listing['title'])) $missing[] = 'title';
        $desc = trim($listing['description'] ?? '');
        if (mb_strlen($desc) < 150) $missing[] = 'description (150 chars min, ' . mb_strlen($desc) . ' current)';
        if ((float) ($listing['price'] ?? 0) <= 0) $missing[] = 'price > 0';
        $photos = $listing['photos'] ?? [];
        if (!is_array($photos) || count($photos) < 5) $missing[] = 'photos (5 min, ' . (is_array($photos) ? count($photos) : 0) . ' current)';
        if (empty($listing['bedrooms']) && $listing['bedrooms'] !== 0) $missing[] = 'bedrooms';
        if (empty($listing['bathrooms']) && $listing['bathrooms'] !== 0) $missing[] = 'bathrooms';
        $sqft = $listing['surface_sqft'] ?? null;
        $sqm = $listing['surface_m2'] ?? null;
        if (empty($sqft) && empty($sqm)) $missing[] = 'sqft or surface_m2 (auto-converted)';
        if (empty($listing['location']['city'] ?? null)) $missing[] = 'location.city';
        $locale = $listing['locale'] ?? 'fr';
        if ($locale !== 'en') {
            $warnings[] = 'Auto-translation from ' . $locale . ' (partial — keyword mapping only)';
        }
        return ['ok' => empty($missing), 'missing_fields' => $missing, 'warnings' => $warnings];
    }

    private function mapToILS(array $listing): array {
        $locale = $listing['locale'] ?? 'fr';
        $titleTrans = ChannelI18n::translateField($listing['title'] ?? '', $locale, 'en');
        $descTrans = ChannelI18n::translateField($listing['description'] ?? '', $locale, 'en');
        $title = $titleTrans['translated'];
        $desc = $descTrans['translated'];
        if (!empty($descTrans['partial'])) {
            $desc .= "\n\n[Auto-translated. Contact agent for full details.]";
        }
        // USD obligatoire
        $currency = strtoupper($listing['currency'] ?? 'EUR');
        $price = (float) ($listing['price'] ?? 0);
        $priceConv = ChannelI18n::convertCurrency($price, $currency, 'USD');
        // Surface m2 → sqft conversion (1 m2 = 10.7639 sqft)
        $sqft = $listing['surface_sqft'] ?? null;
        if (!$sqft && !empty($listing['surface_m2'])) {
            $sqft = (int) round($listing['surface_m2'] * 10.7639);
        }
        return [
            'title' => mb_substr($title, 0, 100),
            'description' => mb_substr($desc, 0, 4000),
            'price' => $priceConv['amount'],
            'currency' => 'USD',
            'price_original' => $priceConv['converted'] ? ['amount' => $price, 'currency' => $currency, 'rate' => $priceConv['rate']] : null,
            'photos' => array_map(fn($p) => is_array($p) ? $p['url'] : $p, $listing['photos'] ?? []),
            'bedrooms' => (int) ($listing['bedrooms'] ?? 0),
            'bathrooms' => (float) ($listing['bathrooms'] ?? 0),
            'sqft' => $sqft,
            'property_type' => ChannelI18n::mapPropertyType($listing['real_estate_type'] ?? 'apartment', 'apartments_com'),
            'layout' => ($listing['bedrooms'] ?? 1) . 'BR/' . ($listing['bathrooms'] ?? 1) . 'BA',
            'pet_policy' => $listing['pet_policy'] ?? 'unknown',
            'parking_available' => !empty($listing['parking']),
            'washer_dryer' => !empty($listing['washer_dryer']),
            'utilities_included' => !empty($listing['utilities_included']),
            'lease_term_months' => $listing['lease_term_months'] ?? 12,
            'location' => [
                'city' => $listing['location']['city'],
                'state' => $listing['location']['state'] ?? '',
                'zipcode' => $listing['location']['zipcode'] ?? '',
                'country' => $listing['location']['country'] ?? 'US',
                'lat' => $listing['location']['lat'] ?? null,
                'lng' => $listing['location']['lng'] ?? null,
            ],
            '_meta' => [
                'translation_partial' => !empty($descTrans['partial']),
                'currency_converted' => $priceConv['converted'],
                'sqft_auto_converted' => empty($listing['surface_sqft']) && !empty($listing['surface_m2']),
            ],
        ];
    }

    private function generateILSXml(string $tenant, int $dossierId, string $extId, array $payload): string {
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_XML1, 'UTF-8');
        $photos = '';
        foreach (($payload['photos'] ?? []) as $idx => $url) {
            $photos .= "    <Photo Order=\"" . ($idx + 1) . "\" URL=\"" . $esc($url) . "\"/>\n";
        }
        $loc = $payload['location'];
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<PhysicalProperty xmlns="http://www.realtyincome.org/2010/ils-namespace">' . "\n"
            . '  <Property MarketingName="' . $esc($payload['title']) . '" ListingId="' . $esc($extId) . '">' . "\n"
            . '    <PropertyID>' . $esc($extId) . '</PropertyID>' . "\n"
            . '    <Address>' . "\n"
            . '      <City>' . $esc($loc['city']) . '</City>' . "\n"
            . '      <State>' . $esc($loc['state']) . '</State>' . "\n"
            . '      <PostalCode>' . $esc($loc['zipcode']) . '</PostalCode>' . "\n"
            . '      <Country>' . $esc($loc['country']) . '</Country>' . "\n"
            . '    </Address>' . "\n"
            . '    <PropertyType>' . $esc($payload['property_type']) . '</PropertyType>' . "\n"
            . '    <Description>' . $esc($payload['description']) . '</Description>' . "\n"
            . '    <Bedrooms>' . $esc($payload['bedrooms']) . '</Bedrooms>' . "\n"
            . '    <Bathrooms>' . $esc($payload['bathrooms']) . '</Bathrooms>' . "\n"
            . '    <SquareFeet>' . $esc($payload['sqft']) . '</SquareFeet>' . "\n"
            . '    <Rent Currency="USD">' . $esc($payload['price']) . '</Rent>' . "\n"
            . '    <LeaseTerm Months="' . $esc($payload['lease_term_months']) . '"/>' . "\n"
            . '    <Photos>' . "\n" . $photos . '    </Photos>' . "\n"
            . '  </Property>' . "\n"
            . '</PhysicalProperty>' . "\n";
    }

    private function ensureFeedDir(string $tenant): string {
        $dir = self::MOCK_FEED_DIR . '/' . $tenant;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    public function publish(array $listing, array $credentials): array {
        $payload = $this->mapToILS($listing);
        // 1. POST mock pour external_id
        $r = ChannelHttpClient::request('POST', self::MOCK_BASE, $payload);
        if ($r['status_code'] !== 201 || empty($r['body']['id'])) {
            $err = $r['body']['error'] ?? $r['error'] ?? 'unknown';
            return ['ok' => false, 'error' => $err, 'status_code' => $r['status_code'], 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
        }
        $extId = $r['body']['id'];
        if (strpos($extId, 'AC_') !== 0) $extId = 'AC_' . substr($extId, 3);
        // 2. Genere XML feed ILS local pour traceabilite
        $tenant = $listing['_tenant_slug'] ?? 'unknown';
        $dossierId = (int) ($listing['_dossier_id'] ?? 0);
        $dir = $this->ensureFeedDir($tenant);
        $xmlPath = $dir . '/' . $extId . '.xml';
        $xml = $this->generateILSXml($tenant, $dossierId, $extId, $payload);
        @file_put_contents($xmlPath, $xml);
        return ['ok' => true, 'external_id' => $extId, 'duration_ms' => $r['duration_ms'], 'response' => $r['body'], 'xml_feed' => $xmlPath, 'meta' => $payload['_meta']];
    }

    public function update(string $external_id, array $changes, array $credentials): array {
        $payload = $this->mapToILS($changes);
        $r = ChannelHttpClient::request('PUT', self::MOCK_BASE . '/' . urlencode($external_id), $payload);
        $ok = $r['status_code'] === 200;
        // Update XML feed
        if ($ok) {
            $tenant = $changes['_tenant_slug'] ?? 'unknown';
            $dir = $this->ensureFeedDir($tenant);
            @file_put_contents($dir . '/' . $external_id . '.xml', $this->generateILSXml($tenant, (int) ($changes['_dossier_id'] ?? 0), $external_id, $payload));
        }
        return ['ok' => $ok, 'error' => $ok ? null : ($r['body']['error'] ?? 'http_' . $r['status_code']), 'duration_ms' => $r['duration_ms']];
    }

    public function delete(string $external_id, array $credentials): array {
        $r = ChannelHttpClient::request('DELETE', self::MOCK_BASE . '/' . urlencode($external_id));
        $ok = in_array($r['status_code'], [200, 204]);
        // Cleanup feed file
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
