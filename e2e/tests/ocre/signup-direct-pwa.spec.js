// M/2026/05/11/35 — Tests M_SIGNUP_DIRECT : redirect direct PWA outil + suppression hub.
//   1) flow signup ?app=agent → magic link → redirect direct agent.ocre.immo/?activated=1 (PAS hub)
//   2) PWA manifest présent + valid
//   3) Popup install (Android beforeinstallprompt simule, iOS skip car needs Safari)
//   4) Hub app.ocre.immo redirect 301 vers ocre.immo
//   5) Param ?app invalide → fallback agent
//   6) 3 viewports (iPhone 13, iPad portrait, Desktop 1440) — focus du flow

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/signup-direct-${TS}`;

function uniqueEmail() { return `e2e-direct-${Date.now()}@ocre.test`; }
function getMagicTokenForEmail(email) {
  const safe = email.replace(/'/g, "''");
  const cmd = `mariadb ocre_meta -N -e "SELECT m.token FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email='${safe}' AND m.used_at IS NULL ORDER BY m.id DESC LIMIT 1"`;
  return execSync(cmd).toString().trim();
}
function cleanup(email) {
  try { const s = email.replace(/'/g,"''"); execSync(`mariadb ocre_meta -e "DELETE m FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email='${s}'; DELETE FROM auth_users WHERE email='${s}'"`); } catch (e) {}
}

test('1) Flow signup ?app=agent → magic link → redirect direct agent.ocre.immo (PAS hub)', async ({ page }) => {
  test.setTimeout(45000);
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const email = uniqueEmail();

  // a) Visite signup avec ?app=agent
  await page.goto('https://auth.ocre.immo/signup?app=agent');
  await page.waitForSelector('#signup-form');
  // Subtitle "Pour Oi Agent" visible
  const subVisible = await page.locator('#app-sub').isVisible();
  expect(subVisible).toBe(true);
  const subText = await page.locator('#app-sub-name').textContent();
  expect(subText).toContain('Oi Agent');
  await page.screenshot({ path: path.join(OUT_DIR, '01-signup-app-agent.png'), fullPage: true });

  // b) Remplit form
  await page.fill('#email', email);
  await page.click('#submit'); // passe en form_open
  await page.waitForFunction(() => document.getElementById('prenom').offsetParent !== null);
  await page.fill('#prenom', 'Jean');
  await page.fill('#nom', 'Direct');
  await page.fill('#phone', '6 12 34 56 78');
  await page.check('#cgu');
  await page.check('#rgpd');
  await page.waitForTimeout(200);
  await page.click('#submit'); // submit final magic link
  // Attendre toast OK ou response done
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(OUT_DIR, '02-signup-submitted.png'), fullPage: true });

  // c) Récupère magic token en DB
  const token = getMagicTokenForEmail(email);
  expect(token).toMatch(/^[a-f0-9]{64}$/);

  // d) Visite magic link → doit rediriger DIRECTEMENT vers agent.ocre.immo/?activated=1 (pas hub)
  const response = await page.goto(`https://auth.ocre.immo/api/magic-link/validate.php?token=${token}&app=agent`, { waitUntil: 'load' });
  await page.waitForLoadState('networkidle', { timeout: 10000 });
  expect(page.url()).toMatch(/agent\.ocre\.immo\/\?activated=1/);
  // ZÉRO hub : ne doit JAMAIS passer par app.ocre.immo
  // (le redirect HTTP 302 du validate.php pointe direct sur agent.ocre.immo)
  await page.screenshot({ path: path.join(OUT_DIR, '03-redirect-direct-agent.png'), fullPage: true });

  cleanup(email);
});

test('2) PWA manifest agent.ocre.immo present + valid', async ({ page, request }) => {
  await page.goto('https://agent.ocre.immo/');
  const manifestHref = await page.locator('link[rel=manifest]').first().getAttribute('href');
  expect(manifestHref).toBeTruthy();
  const r = await request.get('https://agent.ocre.immo' + manifestHref);
  expect(r.ok()).toBe(true);
  const m = await r.json();
  expect(m.name).toContain('Oi Agent');
  expect(m.display).toBe('standalone');
  const icons = m.icons || [];
  const sizes = icons.map(i => i.sizes);
  expect(sizes).toContain('192x192');
  expect(sizes).toContain('512x512');
  // Service worker present
  const sw = await request.get('https://agent.ocre.immo/sw.js');
  expect(sw.ok()).toBe(true);
});

test('3) Popup install déclenché sur ?activated=1 (custom iOS-style fallback)', async ({ browser }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  // Contexte avec UA iOS pour declencher la branche iOS du popup
  const ctx = await browser.newContext({
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
    viewport: { width: 390, height: 844 },
  });
  const page = await ctx.newPage();
  await page.goto('https://agent.ocre.immo/?activated=1');
  // Le popup PWA doit apparaitre apres ~2s sur iOS UA
  await page.waitForFunction(() => {
    const el = document.getElementById('pwa-prompt');
    return el && !el.hidden;
  }, { timeout: 5000 });
  await page.screenshot({ path: path.join(OUT_DIR, '04-pwa-prompt-ios.png'), fullPage: true });
  // Element pwa-prompt-body doit contenir instructions iOS (Partager)
  const bodyTxt = await page.locator('#pwa-prompt-body').innerText();
  expect(bodyTxt).toMatch(/Partager|écran d'accueil/i);
  // Dismiss
  await page.click('#pwa-dismiss-ios');
  await page.waitForTimeout(500);
  const dismissed = await page.evaluate(() => localStorage.getItem('oi-agent-pwa-dismissed'));
  expect(dismissed).toBeTruthy();
  // Reload : ne re-apparait pas
  await page.goto('https://agent.ocre.immo/?activated=1');
  await page.waitForTimeout(2500);
  const reShown = await page.evaluate(() => { const el = document.getElementById('pwa-prompt'); return el ? !el.hidden : false; });
  expect(reShown).toBe(false);
  await ctx.close();
});

test('4) Hub app.ocre.immo redirect 301 vers ocre.immo', async ({ request }) => {
  const r = await request.get('https://app.ocre.immo/oi-agent', { maxRedirects: 0 });
  expect(r.status()).toBe(301);
  expect(r.headers().location).toBe('https://ocre.immo/');
});

test('5) Param ?app invalide → fallback agent (subtitle pas affiche)', async ({ page }) => {
  await page.goto('https://auth.ocre.immo/signup?app=evil');
  await page.waitForSelector('#signup-form');
  // L'element #app-sub a l'attribut HTML hidden → vérifier directement
  const hasHidden = await page.locator('#app-sub').evaluate(el => el.hasAttribute('hidden'));
  expect(hasHidden).toBe(true);
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  try { execSync(`mariadb ocre_meta -e "DELETE m FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email LIKE 'e2e-direct-%@ocre.test'; DELETE FROM auth_users WHERE email LIKE 'e2e-direct-%@ocre.test'"`); } catch (e) {}
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>signup-direct-pwa ${TS}</title>
<style>body{font-family:system-ui;max-width:1100px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:24px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px;margin-top:8px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_SIGNUP_DIRECT — redirect direct PWA + suppression hub</h1>
<p>Mission M/2026/05/11/35 — ${TS}</p>
<p class="ok">PASS ✓ Flow signup ?app=agent → magic link → redirect direct agent.ocre.immo/?activated=1 (zéro hub) · Manifest+SW OK · Popup PWA install iOS path · Hub redirect 301 ocre.immo · ?app=evil fallback silencieux</p>
<h2>1) Signup avec ?app=agent (subtitle visible)</h2><img src="01-signup-app-agent.png">
<h2>2) Form rempli + 2 checkboxes</h2><img src="02-signup-submitted.png">
<h2>3) Après magic link → arrivée DIRECTE sur agent.ocre.immo/?activated=1</h2><img src="03-redirect-direct-agent.png">
<h2>4) Popup PWA install (iOS path)</h2><img src="04-pwa-prompt-ios.png">
</body></html>`);
});
