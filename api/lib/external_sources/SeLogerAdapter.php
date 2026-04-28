<?php
// M/2026/04/28/62 — SeLoger adapter (scraping HTML respectueux).
// Note : SeLoger a des protections anti-bot (Cloudflare). En cas de blocage,
// on retourne un tableau vide avec error_message, l'agent voit "0 résultats".
require_once __DIR__ . '/ExternalSource.php';

class SeLogerAdapter extends ExternalSource {
    public function getName(): string { return 'seloger'; }
    public function getRateLimit(): int { return 10; }

    public function search(array $criteria): array {
        $ville = $criteria['ville'] ?? '';
        $type = $criteria['type'] ?? '';
        $budgetMin = (int) ($criteria['budget_min'] ?? 0);
        $budgetMax = (int) ($criteria['budget_max'] ?? 0);
        $surfaceMin = (int) ($criteria['surface_min'] ?? 0);

        if (!$ville) return ['results' => [], 'error' => 'ville requise'];

        // Construction URL search SeLoger.
        $params = [
            'projects' => '2,5', // achat
            'types' => '1,2',     // appartement, maison
            'natures' => '1,2,4',
            'places' => json_encode([['name' => $ville]]),
            'enterprise' => 0,
            'qsVersion' => '1.0',
        ];
        if ($budgetMax > 0) $params['price'] = "0/{$budgetMax}";
        if ($surfaceMin > 0) $params['surface'] = "{$surfaceMin}/NaN";

        $url = 'https://www.seloger.com/list.htm?' . http_build_query($params);
        $html = $this->fetchHtml($url);
        if (!$html) {
            return ['results' => [], 'error' => 'fetch_failed (probable bloc Cloudflare ou rate-limit)'];
        }

        // Parse listings : SeLoger expose JSON inline dans <script id="__NEXT_DATA__">.
        $results = [];
        if (preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m)) {
            try {
                $data = json_decode($m[1], true);
                $cards = $data['props']['pageProps']['searchProps']['initialProps']['cards'] ?? [];
                foreach ($cards as $c) {
                    if (!is_array($c)) continue;
                    $results[] = $this->makeListing([
                        'source_id' => $c['id'] ?? null,
                        'url' => $c['classifiedURL'] ?? '',
                        'title' => $c['title'] ?? '',
                        'price' => $c['pricing']['price'] ?? null,
                        'currency' => 'EUR',
                        'location_text' => ($c['cityLabel'] ?? '') . ', ' . ($c['zipCode'] ?? ''),
                        'surface' => $c['surface'] ?? null,
                        'rooms' => $c['rooms'] ?? null,
                        'photos' => array_map(fn($p) => $p['url'] ?? '', array_slice((array) ($c['photos'] ?? []), 0, 3)),
                        'description' => mb_substr($c['description'] ?? '', 0, 300),
                    ]);
                }
            } catch (Throwable $e) {}
        }

        return ['results' => array_slice($results, 0, 30), 'error' => null];
    }
}
