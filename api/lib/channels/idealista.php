<?php
// M105 — Driver Idealista (Espagne, EUR) — mode STUB.
// API REST OAuth2 cote prod. Mode stub : POST/PUT/DELETE/GET vers mock_server local.

require_once __DIR__ . '/ChannelDriver.php';
require_once __DIR__ . '/i18n_helper.php';

class IdealistaDriver implements ChannelDriver {
    private const MOCK_BASE = 'http://127.0.0.1:8888/mock/idealista/listings';

    public function getName(): string { return 'idealista'; }

    public function getRequiredFields(): array {
        return ['title', 'description', 'price', 'photos', 'real_estate_type', 'surface_m2', 'location'];
    }

    public function getMaxLengths(): array {
        return ['title' => 120, 'description' => 8000];
    }

    public function validateListing(array $listing): array {
        $missing = [];
        $warnings = [];
        $title = trim($listing['title'] ?? '');
        $desc = trim($listing['description'] ?? '');
        if (mb_strlen($title) < 25) $missing[] = 'titulo (25 caracteres min, ' . mb_strlen($title) . ' actuales)';
        if (mb_strlen($desc) < 200) $missing[] = 'descripcion (200 caracteres min, ' . mb_strlen($desc) . ' actuales)';
        if ((float) ($listing['price'] ?? 0) <= 0) $missing[] = 'precio > 0';
        $photos = $listing['photos'] ?? [];
        if (!is_array($photos) || count($photos) < 3) $missing[] = 'fotos (3 minimo, ' . (is_array($photos) ? count($photos) : 0) . ' actuales)';
        if (empty($listing['real_estate_type'])) $missing[] = 'tipo_inmueble';
        if (empty($listing['surface_m2'])) $missing[] = 'superficie m²';
        if (empty($listing['location']['city'] ?? null)) $missing[] = 'ubicacion (ciudad)';
        // Warning langue
        $locale = $listing['locale'] ?? 'fr';
        if ($locale !== 'es') {
            $warnings[] = 'Traduccion automatica desde ' . $locale . ' (parcial — terminos clave traducidos, descripcion larga conservada)';
        }
        return ['ok' => empty($missing), 'missing_fields' => $missing, 'warnings' => $warnings];
    }

    private function mapToIdealista(array $listing): array {
        $locale = $listing['locale'] ?? 'fr';
        $titleTrans = ChannelI18n::translateField($listing['title'] ?? '', $locale, 'es');
        $descTrans = ChannelI18n::translateField($listing['description'] ?? '', $locale, 'es');
        $title = $titleTrans['translated'];
        $desc = $descTrans['translated'];
        if (!empty($descTrans['partial'])) {
            $desc .= "\n\n[Traducción automática parcial. Consulte al agente para detalles completos.]";
        }
        // Devise EUR forcee
        $currency = strtoupper($listing['currency'] ?? 'EUR');
        $price = (float) ($listing['price'] ?? 0);
        $priceConv = ChannelI18n::convertCurrency($price, $currency, 'EUR');
        return [
            'titulo' => mb_substr($title, 0, 120),
            'descripcion' => mb_substr($desc, 0, 8000),
            'precio' => $priceConv['amount'],
            'moneda' => 'EUR',
            'precio_original' => $priceConv['converted'] ? ['valor' => $price, 'moneda' => $currency, 'tasa' => $priceConv['rate']] : null,
            'fotos' => array_map(fn($p) => is_array($p) ? $p['url'] : $p, $listing['photos'] ?? []),
            'tipo_inmueble' => ChannelI18n::mapPropertyType($listing['real_estate_type'] ?? 'apartment', 'idealista'),
            'metros_construidos' => $listing['surface_m2'] ?? null,
            'metros_utiles' => $listing['surface_utile_m2'] ?? null,
            'num_habitaciones' => $listing['rooms'] ?? null,
            'num_banos' => $listing['bathrooms'] ?? null,
            'planta' => $listing['floor'] ?? null,
            'ascensor' => !empty($listing['elevator']),
            'calefaccion' => !empty($listing['heating']),
            'aire_acondicionado' => !empty($listing['air_conditioning']),
            'parking' => !empty($listing['parking']),
            'terraza' => !empty($listing['terrace']),
            'balcon' => !empty($listing['balcony']),
            'piscina' => !empty($listing['pool']),
            'jardin' => !empty($listing['garden']),
            'ubicacion' => [
                'ciudad' => $listing['location']['city'],
                'codigo_postal' => $listing['location']['zipcode'] ?? null,
                'comunidad_autonoma' => $listing['location']['region'] ?? null,
                'lat' => $listing['location']['lat'] ?? null,
                'lng' => $listing['location']['lng'] ?? null,
            ],
            '_meta' => [
                'translation_partial' => !empty($descTrans['partial']),
                'currency_converted' => $priceConv['converted'],
            ],
        ];
    }

    public function publish(array $listing, array $credentials): array {
        $payload = $this->mapToIdealista($listing);
        $r = ChannelHttpClient::request('POST', self::MOCK_BASE, $payload);
        if ($r['status_code'] === 201 && isset($r['body']['id'])) {
            // External id prefixe IB_ (Idealista Beta)
            $eid = $r['body']['id'];
            if (strpos($eid, 'IB_') !== 0) $eid = 'IB_' . substr($eid, 3);
            return ['ok' => true, 'external_id' => $eid, 'duration_ms' => $r['duration_ms'], 'response' => $r['body'], 'meta' => $payload['_meta']];
        }
        $err = $r['body']['error'] ?? $r['error'] ?? 'unknown';
        return ['ok' => false, 'error' => $err, 'status_code' => $r['status_code'], 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
    }

    public function update(string $external_id, array $changes, array $credentials): array {
        $payload = $this->mapToIdealista($changes);
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
        if ($r['status_code'] !== 200) return ['ok' => false, 'status' => 'unknown', 'error' => 'http_' . $r['status_code']];
        $b = $r['body'] ?? [];
        return ['ok' => true, 'status' => $b['status'] ?? 'published', 'views' => $b['views'] ?? 0, 'last_modif' => $b['updated_at'] ?? null];
    }
}
