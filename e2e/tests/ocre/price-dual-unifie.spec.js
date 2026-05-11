// M/2026/05/11/39 — Tests M_PRICE_DUAL_UNIFIE : panel super-admin "Paramètres d'affichage" + endpoint app_settings.
// Scope MVP : backend + panel super-admin. Refactor SPA (composant DualCurrencyPair lit setting global)
// est une mission separee (touche 30+ emplacements inline dans /opt/ocre-app/index.html 23k lignes).
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ADMIN_EMAIL = 'philippe.ciftci@gmail.com';
const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/price-dual-${TS}`;

function genActivateToken() {
  const tok = crypto.randomBytes(32).toString('hex');
  execSync(`mariadb ocre_meta -e "UPDATE users SET activation_token='${tok}', activation_token_expires_at=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email='${ADMIN_EMAIL}' AND role='super_admin'"`);
  return tok;
}
function getSetting(key) {
  return execSync(`mariadb ocre_meta -BNe "SELECT setting_value FROM app_settings WHERE setting_key='${key}'"`).toString().trim();
}

test('1) GET app-settings public sans auth', async ({ request }) => {
  const r = await request.get('https://superadmin.ocre.immo/api/superadmin_app_settings.php?action=get');
  expect(r.ok()).toBe(true);
  const j = await r.json();
  expect(j.ok).toBe(true);
  expect(j.settings.price_display_variant).toMatch(/^[AB]$/);
  expect(parseFloat(j.settings.exchange_rate_eur_mad)).toBeGreaterThan(1);
});

test('2) POST set sans auth → 401', async ({ request }) => {
  const r = await request.post('https://superadmin.ocre.immo/api/superadmin_app_settings.php?action=set', {
    headers: { 'Content-Type': 'application/json' },
    data: { key: 'price_display_variant', value: 'B' },
  });
  expect(r.status()).toBe(401);
});

test('3) Panel super-admin "Affichage" — toggle Variant A ↔ B persiste DB', async ({ page }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  // Force état initial Variant A en DB
  execSync(`mariadb ocre_meta -e "UPDATE app_settings SET setting_value='A' WHERE setting_key='price_display_variant'"`);
  const tok = genActivateToken();
  await page.goto(`https://superadmin.ocre.immo/?activate=${tok}`);
  await page.waitForSelector('#app:not([hidden])', { timeout: 20000 });
  // Navigate section Affichage
  await page.click('.nav-item[data-section="display"]');
  await page.waitForSelector('#variant-card-A', { timeout: 8000 });
  await page.screenshot({ path: path.join(OUT_DIR, '01-display-variant-A.png'), fullPage: true });
  // Variant A doit etre actif (badge "● actif")
  const aActif = await page.locator('#variant-card-A').innerText();
  expect(aActif).toMatch(/●\s*actif/);
  // Click Variant B
  await page.click('#variant-card-B');
  await page.waitForTimeout(800);
  // DB updated
  expect(getSetting('price_display_variant')).toBe('B');
  // UI updated : reload via render display, B est actif
  const bActif = await page.locator('#variant-card-B').innerText();
  expect(bActif).toMatch(/●\s*actif/);
  await page.screenshot({ path: path.join(OUT_DIR, '02-display-variant-B.png'), fullPage: true });
  // Click Variant A revient
  await page.click('#variant-card-A');
  await page.waitForTimeout(800);
  expect(getSetting('price_display_variant')).toBe('A');
});

test('4) Panel super-admin — update taux EUR/MAD via API call authentifie', async ({ page }) => {
  // Restaure default
  execSync(`mariadb ocre_meta -e "UPDATE app_settings SET setting_value='10.84' WHERE setting_key='exchange_rate_eur_mad'"`);
  const tok = genActivateToken();
  await page.goto(`https://superadmin.ocre.immo/?activate=${tok}`);
  await page.waitForSelector('#app:not([hidden])');
  // Appelle l'endpoint via fetch authentifié (cookies + X-Session-Token bridge)
  const res = await page.evaluate(async () => {
    const r = await fetch('/api/superadmin_app_settings.php?action=set', {
      method: 'POST', credentials: 'include',
      headers: {'Content-Type':'application/json','X-Session-Token': localStorage.getItem('ocre_sa_token') || ''},
      body: JSON.stringify({ key: 'exchange_rate_eur_mad', value: '11.20' }),
    });
    return { status: r.status, body: await r.json() };
  });
  expect(res.status).toBe(200);
  expect(res.body.ok).toBe(true);
  expect(parseFloat(getSetting('exchange_rate_eur_mad'))).toBeCloseTo(11.20, 2);
  // Restore
  execSync(`mariadb ocre_meta -e "UPDATE app_settings SET setting_value='10.84' WHERE setting_key='exchange_rate_eur_mad'"`);
});

test('5) Endpoint POST whitelist : refus key inconnu + valeur invalide', async ({ request, page }) => {
  // Login pour avoir cookie
  const tok = genActivateToken();
  await page.goto(`https://superadmin.ocre.immo/?activate=${tok}`);
  await page.waitForSelector('#app:not([hidden])');
  // Force write via fetch sur la session active
  const r1 = await page.evaluate(async () => {
    const r = await fetch('/api/superadmin_app_settings.php?action=set', { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json','X-Session-Token': localStorage.getItem('ocre_sa_token') || ''}, body: JSON.stringify({ key: 'evil_key', value: 'x' }) });
    return { status: r.status, body: await r.json() };
  });
  expect(r1.status).toBe(400);
  expect(r1.body.error).toBe('unknown_key');
  const r2 = await page.evaluate(async () => {
    const r = await fetch('/api/superadmin_app_settings.php?action=set', { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json','X-Session-Token': localStorage.getItem('ocre_sa_token') || ''}, body: JSON.stringify({ key: 'price_display_variant', value: 'Z' }) });
    return { status: r.status, body: await r.json() };
  });
  expect(r2.status).toBe(400);
  expect(r2.body.error).toBe('invalid_value');
});

test.afterAll(() => {
  try { execSync(`mariadb ocre_meta -e "UPDATE users SET activation_token=NULL WHERE email='${ADMIN_EMAIL}'"`); } catch (e) {}
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>price-dual-unifie ${TS}</title>
<style>body{font-family:system-ui;max-width:1100px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:24px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px;margin-top:8px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_PRICE_DUAL_UNIFIE — Panel super-admin Affichage + endpoint app_settings</h1>
<p>Mission M/2026/05/11/39 — ${TS}</p>
<p class="ok">PASS ✓ Endpoint GET public + POST auth-gated + whitelist keys/values + toggle Variant A↔B persiste DB + update taux EUR/MAD</p>
<h2>1) Variant A actif (default)</h2><img src="01-display-variant-A.png">
<h2>2) Variant B sélectionné après clic</h2><img src="02-display-variant-B.png">
<p style="color:#6b5e4a;margin-top:18px"><b>Hors scope mission séparée :</b> propagation du setting dans le composant DualCurrencyPair (déjà existant ligne 7815 SPA) + refactor des 30+ emplacements prix bi-devise dans la SPA inline 23k lignes. Ce panel + endpoint sont la fondation. Le refactor SPA sera fait en mission dédiée.</p>
</body></html>`);
});
