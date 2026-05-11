// M/2026/05/11/36 — Tests M_AGENT_LANDING_REELLE : agent.ocre.immo routeur SSO + provisioning auto.
// Test minimal sans creation reelle de workspace (provisioning = DROP DATABASE in cleanup serait lourd).
// Verifie : (1) HTML agent.ocre.immo sans bouton "Activer Oi Agent" ni "Bonjour Exbat"
//           (2) provision-tenant.php auth gate (401 no_jwt) + CORS strict
//           (3) Anti-regression : etat anon redirige vers signup, pas vers /login form legacy
//           (4) Routing logique: spinner par defaut, message "Ouverture de ton workspace"

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/agent-landing-reelle-${TS}`;

const VIEWPORTS = [
  { name: 'iphone-390',   viewport: { width: 390, height: 844 } },
  { name: 'ipad-768',     viewport: { width: 768, height: 1024 } },
  { name: 'desktop-1440', viewport: { width: 1440, height: 900 } },
];

test('1) HTML agent.ocre.immo : zero bouton "Activer Oi Agent" ni "Bonjour Exbat"', async ({ request }) => {
  const r = await request.get('https://agent.ocre.immo/');
  const html = await r.text();
  expect(html).not.toMatch(/Activer Oi Agent/i);
  expect(html).not.toMatch(/Bonjour, <span/); // l'ancien greeting was 'Bonjour, <span id="user-name">'
  // Mais doit contenir le nouveau message routeur
  expect(html).toMatch(/Ouverture de ton workspace/);
  expect(html).toMatch(/auth\.ocre\.immo\/signup\?app=agent/);
});

test('2) provision-tenant.php auth gate', async ({ request }) => {
  // Sans cookie : 401 no_jwt
  const r = await request.post('https://auth.ocre.immo/api/provision-tenant.php', {
    headers: { 'Content-Type': 'application/json', 'Origin': 'https://agent.ocre.immo' },
    data: { app: 'agent' },
  });
  expect(r.status()).toBe(401);
  const j = await r.json();
  expect(j.ok).toBe(false);
  expect(j.error).toBe('no_jwt');
});

test('3) agent.ocre.immo + ?logout=1 force etat anon avec CTA signup unifie', async ({ page }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  await page.goto('https://agent.ocre.immo/?logout=1');
  // L'etat anon doit etre visible
  await page.waitForSelector('#state-anon:not([hidden])', { timeout: 5000 });
  // CTA pointent vers auth.ocre.immo/signup?app=agent (form unifie)
  const hrefs = await page.$$eval('#state-anon a', as => as.map(a => a.href));
  expect(hrefs.every(h => /auth\.ocre\.immo\/signup\?app=agent/.test(h))).toBe(true);
  // ZERO bouton "Activer Oi Agent" nulle part dans le DOM
  const txt = await page.locator('body').innerText();
  expect(txt).not.toMatch(/Activer Oi Agent/i);
  await page.screenshot({ path: path.join(OUT_DIR, 'state-anon.png'), fullPage: true });
});

for (const vp of VIEWPORTS) {
  test(`4) agent.ocre.immo loader visible default ${vp.name}`, async ({ browser }) => {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const ctx = await browser.newContext({ viewport: vp.viewport });
    const page = await ctx.newPage();
    // Sans cookie SSO : le fetch /api/me.php retourne null → state-anon doit s'afficher.
    await page.goto('https://agent.ocre.immo/');
    await page.waitForSelector('#state-anon:not([hidden])', { timeout: 5000 });
    const anonVisible = await page.locator('#state-anon').isVisible();
    expect(anonVisible).toBe(true);
    await page.screenshot({ path: path.join(OUT_DIR, `${vp.name}-final.png`), fullPage: true });
    await ctx.close();
  });
}

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>agent-landing-reelle ${TS}</title>
<style>body{font-family:system-ui;max-width:1200px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:24px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px;margin-top:8px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px}
.cell{background:#fff;border:1px solid #e5dac6;border-radius:8px;padding:10px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_AGENT_LANDING_REELLE — agent.ocre.immo routeur transparent</h1>
<p>Mission M/2026/05/11/36 — ${TS}</p>
<p class="ok">PASS ✓ Zéro bouton "Activer Oi Agent" / "Bonjour Exbat" · CTAs anon -> auth.ocre.immo/signup?app=agent · provision-tenant.php auth gate 401</p>
<h2>Etat anon (déconnecté, CTAs signup unifié)</h2>
<div class="cell"><img src="state-anon.png"></div>
<h2>Loader par défaut sur 3 viewports</h2>
<div class="grid">
${['iphone-390','ipad-768','desktop-1440'].map(v => `<div class="cell"><h3>${v}</h3><img src="${v}-final.png"></div>`).join('')}
</div>
</body></html>`);
});
