<?php
// M106 — Driver Avito.ma (Maroc, MAD) — mode STUB.
// Avito.ma : portail generaliste Maroc avec section immo. API REST cote prod (a confirmer dispo).
// Mode stub : POST/PUT/DELETE/GET vers mock_server local.

require_once __DIR__ . '/ChannelDriver.php';
require_once __DIR__ . '/i18n_helper.php';

class AvitoMaDriver implements ChannelDriver {
    private const MOCK_BASE = 'http://127.0.0.1:8888/mock/avito_ma/listings';

    private const VILLES_MA = [
        'Casablanca', 'Rabat', 'Marrakech', 'Tanger', 'Fès', 'Agadir', 'Oujda',
        'Tétouan', 'Meknès', 'El Jadida', 'Mohammedia', 'Kénitra', 'Salé', 'Béni Mellal',
        'Nador', 'Khouribga', 'Settat', 'Larache', 'Khemisset', 'Errachidia',
        'Ouarzazate', 'Essaouira', 'Chefchaouen', 'Ifrane',
    ];

    public function getName(): string { return 'avito_ma'; }

    public function getRequiredFields(): array {
        return ['title', 'description', 'price', 'photos', 'real_estate_type', 'location', 'transaction_type'];
    }

    public function getMaxLengths(): array {
        return ['title' => 70, 'description' => 4000];
    }

    public function validateListing(array $listing): array {
        $missing = [];
        $warnings = [];
        $title = trim($listing['title'] ?? '');
        $desc = trim($listing['description'] ?? '');
        if (mb_strlen($title) < 20) $missing[] = 'titre (20 caractères min, ' . mb_strlen($title) . ' actuels)';
        if (mb_strlen($desc) < 100) $missing[] = 'description (100 caractères min, ' . mb_strlen($desc) . ' actuels)';
        if ((float) ($listing['price'] ?? 0) <= 0) $missing[] = 'prix > 0';
        $photos = $listing['photos'] ?? [];
        if (!is_array($photos) || count($photos) < 2) $missing[] = 'photos (2 minimum, ' . (is_array($photos) ? count($photos) : 0) . ' actuelles)';
        if (empty($listing['real_estate_type'])) $missing[] = 'type_bien';
        $city = $listing['location']['city'] ?? null;
        if (empty($city)) $missing[] = 'ville (obligatoire au Maroc)';
        if (empty($listing['transaction_type'])) $missing[] = 'transaction_type (vente/location)';
        // Quartier recommande
        if (empty($listing['location']['neighborhood'] ?? null)) {
            $warnings[] = 'Quartier non renseigné (recommandé pour Avito.ma)';
        }
        // Ville hors top 24 → warning
        if ($city && !in_array($city, self::VILLES_MA, true)) {
            $warnings[] = 'Ville "' . $city . '" non standard Avito.ma (peut être acceptée mais référencement réduit)';
        }
        // Locale AR recommandee
        $locale = $listing['locale'] ?? 'fr';
        if ($locale !== 'ar' && $locale !== 'fr') {
            $warnings[] = 'Locale ' . $locale . ' : Avito.ma recommande FR ou AR';
        }
        return ['ok' => empty($missing), 'missing_fields' => $missing, 'warnings' => $warnings];
    }

    private function mapToAvito(array $listing): array {
        $locale = $listing['locale'] ?? 'fr';
        // Si locale=AR → traduire titre/desc, sinon garder FR
        $titleOut = $listing['title'] ?? '';
        $descOut = $listing['description'] ?? '';
        $translationPartial = false;
        if ($locale === 'ar') {
            $titleTrans = ChannelI18n::translateField($titleOut, 'fr', 'ar');
            $descTrans = ChannelI18n::translateField($descOut, 'fr', 'ar');
            $titleOut = $titleTrans['translated'];
            $descOut = $descTrans['translated'];
            $translationPartial = !empty($descTrans['partial']);
            if ($translationPartial) {
                $descOut .= "\n\n[ترجمة آلية جزئية. اتصل بالوكيل للحصول على التفاصيل.]";
            }
        }
        // Devise MAD obligatoire
        $currency = strtoupper($listing['currency'] ?? 'MAD');
        $price = (float) ($listing['price'] ?? 0);
        $priceConv = ChannelI18n::convertCurrency($price, $currency, 'MAD');
        return [
            'titre' => mb_substr($titleOut, 0, 70),
            'description' => mb_substr($descOut, 0, 4000),
            'prix' => $priceConv['amount'],
            'devise' => 'MAD',
            'prix_original' => $priceConv['converted'] ? ['valeur' => $price, 'devise' => $currency, 'taux' => $priceConv['rate']] : null,
            'photos' => array_map(fn($p) => is_array($p) ? $p['url'] : $p, $listing['photos'] ?? []),
            'type_bien' => ChannelI18n::mapPropertyType($listing['real_estate_type'] ?? 'apartment', 'avito_ma'),
            'transaction' => $listing['transaction_type'] === 'rent' ? 'location' : 'vente',
            'surface_m2' => $listing['surface_m2'] ?? null,
            'nb_chambres' => $listing['bedrooms'] ?? $listing['rooms'] ?? null,
            'nb_salles_bain' => $listing['bathrooms'] ?? null,
            'etage' => $listing['floor'] ?? null,
            'ascenseur' => !empty($listing['elevator']),
            'parking' => !empty($listing['parking']),
            'terrasse' => !empty($listing['terrace']),
            'balcon' => !empty($listing['balcony']),
            'climatisation' => !empty($listing['air_conditioning']),
            'meuble' => !empty($listing['furnished']),
            'localisation' => [
                'ville' => $listing['location']['city'] ?? '',
                'quartier' => $listing['location']['neighborhood'] ?? '',
                'lat' => $listing['location']['lat'] ?? null,
                'lng' => $listing['location']['lng'] ?? null,
            ],
            '_meta' => [
                'translation_partial' => $translationPartial,
                'currency_converted' => $priceConv['converted'],
                'locale' => $locale,
            ],
        ];
    }

    public function publish(array $listing, array $credentials): array {
        $payload = $this->mapToAvito($listing);
        $r = ChannelHttpClient::request('POST', self::MOCK_BASE, $payload);
        if ($r['status_code'] === 201 && isset($r['body']['id'])) {
            $eid = $r['body']['id'];
            if (strpos($eid, 'AV_') !== 0) $eid = 'AV_' . substr($eid, 3);
            return ['ok' => true, 'external_id' => $eid, 'duration_ms' => $r['duration_ms'], 'response' => $r['body'], 'meta' => $payload['_meta']];
        }
        $err = $r['body']['error'] ?? $r['error'] ?? 'unknown';
        return ['ok' => false, 'error' => $err, 'status_code' => $r['status_code'], 'duration_ms' => $r['duration_ms'], 'response' => $r['body']];
    }

    public function update(string $external_id, array $changes, array $credentials): array {
        $payload = $this->mapToAvito($changes);
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
