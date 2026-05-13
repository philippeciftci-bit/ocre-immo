<?php
// M/2026/05/13/26 — Launcher app.ocre.immo : page racine, 4 cartes apps Oi.
require_once __DIR__ . '/_lib.php';
launcher_security_headers();

$user = launcher_current_user();
if (!$user) {
    header('Location: /login');
    exit;
}

$prenom = launcher_h((string)($user['prenom'] ?? $user['email']));
$tenantSlug = (string)$user['current_tenant'];
$agentHref = $tenantSlug && preg_match('/^[a-z0-9-]+$/', $tenantSlug)
    ? 'https://' . $tenantSlug . '.ocre.immo/'
    : '#';

$cards = [
    [
        'key' => 'oi-agent', 'label' => 'Oi Agent', 'tagline' => 'CRM dossiers clients',
        'href' => $agentHref, 'available' => $tenantSlug !== '' && $agentHref !== '#',
    ],
    [
        'key' => 'oi-scan', 'label' => 'Oi Scan', 'tagline' => 'Lecture documents IA',
        'href' => null, 'available' => false,
    ],
    [
        'key' => 'oi-book', 'label' => 'Oi Book', 'tagline' => 'Carnet de rendez-vous',
        'href' => null, 'available' => false,
    ],
    [
        'key' => 'oi-demande', 'label' => 'Oi Demande', 'tagline' => 'Devis et bons de commande',
        'href' => null, 'available' => false,
    ],
];

echo launcher_render_head('Mes outils');
?>
<main class="launcher-root">
  <header class="launcher-top">
    <div class="launcher-brand">
      <span class="launcher-brand-wordmark">Ocre</span>
      <span class="launcher-brand-sep">·</span>
      <span class="launcher-brand-sub">Mes outils</span>
    </div>
    <div class="launcher-user">
      <span class="launcher-greet">Bonjour, <?= $prenom ?></span>
      <a class="launcher-logout" href="/logout" data-action="logout">Se déconnecter</a>
    </div>
  </header>

  <section class="launcher-grid" aria-label="Apps disponibles">
    <?php foreach ($cards as $c): ?>
      <?php if ($c['available']): ?>
        <a class="launcher-card launcher-card-active" href="<?= launcher_h($c['href']) ?>" data-card="<?= launcher_h($c['key']) ?>">
          <span class="launcher-card-label"><?= launcher_h($c['label']) ?></span>
          <span class="launcher-card-tagline"><?= launcher_h($c['tagline']) ?></span>
          <span class="launcher-card-cta">Ouvrir</span>
        </a>
      <?php else: ?>
        <div class="launcher-card launcher-card-soon" data-card="<?= launcher_h($c['key']) ?>" aria-disabled="true">
          <span class="launcher-card-label"><?= launcher_h($c['label']) ?></span>
          <span class="launcher-card-tagline"><?= launcher_h($c['tagline']) ?></span>
          <span class="launcher-card-soon-badge">Bientôt</span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </section>
</main>
<?= launcher_render_foot() ?>
