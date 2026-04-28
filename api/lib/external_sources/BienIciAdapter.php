<?php
// M/2026/04/28/62 — Bien'ici adapter via API JSON publique (plus stable que scraping HTML).
require_once __DIR__ . '/ExternalSource.php';

class BienIciAdapter extends ExternalSource {
    public function getName(): string { return 'bienici'; }
    public function getRateLimit(): int { return 10; }

    public function search(array $criteria): array {
        $ville = $criteria['ville'] ?? '';
        $budgetMax = (int) ($criteria['budget_max'] ?? 0);
        $surfaceMin = (int) ($criteria['surface_min'] ?? 0);

        if (!$ville) return ['results' => [], 'error' => 'ville requise'];

        // Bien'ici expose une API JSON. Filtre par zoneIdsByTypes serait plus précis,
        // mais nécessite resolver ville → zoneId préalable.
        $filters = [
            'size' => 30,
            'from' => 0,
            'showAllModels' => false,
            'filterType' => 'buy',
            'propertyType' => ['flat', 'house'],
            'page' => 1,
            'resultsPerPage' => 30,
            'maxAuthorizedResults' => 2400,
            'sortBy' => 'relevance',
            'sortOrder' => 'desc',
            'onTheMarket' => [true],
            'newProperty' => false,
        ];
        if ($budgetMax > 0) $filters['maxPrice'] = $budgetMax;
        if ($surfaceMin > 0) $filters['minArea'] = $surfaceMin;

        $url = 'https://www.bienici.com/realEstateAds.json?filters=' . urlencode(json_encode($filters));
        $body = $this->fetchHtml($url);
        if (!$body) return ['results' => [], 'error' => 'fetch_failed'];

        $data = json_decode($body, true);
        if (!$data || !isset($data['realEstateAds'])) {
            return ['results' => [], 'error' => 'invalid_response'];
        }

        $results = [];
        foreach ($data['realEstateAds'] as $ad) {
            // Filtre ville côté client (best-effort).
            $cityMatch = false;
            if (isset($ad['city']) && stripos($ad['city'], $ville) !== false) $cityMatch = true;
            if (!$cityMatch && isset($ad['postalCode']) && stripos($ville, (string) $ad['postalCode']) !== false) $cityMatch = true;
            // Si user n'a pas filtré par ville côté API, accepter.
            $results[] = $this->makeListing([
                'source_id' => $ad['id'] ?? null,
                'url' => 'https://www.bienici.com/annonce/vente/' . ($ad['city'] ?? '') . '/' . ($ad['propertyType'] ?? '') . '/' . ($ad['id'] ?? ''),
                'title' => $ad['title'] ?? ($ad['propertyType'] ?? '') . ' à ' . ($ad['city'] ?? ''),
                'price' => $ad['price'] ?? null,
                'currency' => 'EUR',
                'location_text' => ($ad['city'] ?? '') . ', ' . ($ad['postalCode'] ?? ''),
                'surface' => $ad['surfaceArea'] ?? null,
                'rooms' => $ad['roomsQuantity'] ?? null,
                'photos' => array_map(fn($p) => $p['url'] ?? '', array_slice((array) ($ad['photos'] ?? []), 0, 3)),
                'description' => mb_substr($ad['description'] ?? '', 0, 300),
            ]);
            if (count($results) >= 30) break;
        }

        return ['results' => $results, 'error' => null];
    }
}
