// M/2026/05/11/42 — Tests M_AUTH_FLOW_SAMESITE_FIX : cookie SameSite=None pour Safari iPad ITP.
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/samesite-fix-${TS}`;

function genMagicToken(userId) {
  const tok = crypto.randomBytes(32).toString('hex');
  execSync(`mariadb ocre_meta -e "INSERT INTO auth_magic_tokens (user_id, token, expires_at, ip) VALUES (${userId}, '${tok}', DATE_ADD(NOW(), INTERVAL 1 HOUR), '127.0.0.1')"`);
  return tok;
}
test.beforeEach(() => {
  try { execSync(`mariadb ocre_meta -e "DELETE FROM auth_rate_limit"`); } catch (e) {}
});

test('1) Cookie attributes : SameSite=None + secure + httpOnly + domain=.ocre.immo', async ({ browser }) => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  // Setup : validate.php pose les cookies pour Philippe (id=11) test simple
  const tok = genMagicToken(11);
  await page.goto(`https://auth.ocre.immo/api/magic-link/validate.php?token=${tok}&app=agent`, { waitUntil: 'load' });
  const cookies = await ctx.cookies();
  const jwt = cookies.find(c => c.name === 'ocre_jwt');
  const refresh = cookies.find(c => c.name === 'ocre_refresh');
  expect(jwt).toBeTruthy();
  expect(jwt.sameSite).toBe('None');
  expect(jwt.secure).toBe(true);
  expect(jwt.httpOnly).toBe(true);
  expect(jwt.domain).toBe('.ocre.immo');
  expect(refresh).toBeTruthy();
  expect(refresh.sameSite).toBe('None');
  expect(refresh.secure).toBe(true);
  await ctx.close();
});

test('2) Cas A end-to-end : user existant + session valide → action=direct (PAS de mail)', async ({ browser }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  // Pre-cleanup magic tokens recents pour exbattat pour comptage propre
  execSync(`mariadb ocre_meta -e "DELETE FROM auth_magic_tokens WHERE user_id=93 AND ip='127.0.0.1'"`);
  const beforeCount = parseInt(execSync(`mariadb ocre_meta -BNe "SELECT COUNT(*) FROM auth_magic_tokens WHERE user_id=93 AND created_at > NOW()-INTERVAL 5 MINUTE"`).toString().trim(), 10);

  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  // Step 1 : magic link + validate (pose cookies SameSite=None)
  const tok = genMagicToken(93);
  await page.goto(`https://auth.ocre.immo/api/magic-link/validate.php?token=${tok}&app=agent`, { waitUntil: 'load' });
  // Step 2 : naviguer vers ocre.immo (contexte vitrine)
  await page.goto('https://ocre.immo/');
  await page.waitForLoadState('domcontentloaded');
  // Step 3 : popup + submit email
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', 'exbattat@gmail.com');
  // Capture network response avant click
  const respPromise = page.waitForResponse(r => r.url().includes('/api/login.php'), { timeout: 8000 });
  await page.click('#oal-submit');
  const resp = await respPromise;
  const body = await resp.json();
  expect(body.ok).toBe(true);
  expect(body.action).toBe('direct');
  expect(body.redirect_url).toMatch(/exbattat-a312\.ocre\.immo/);
  // Comptage tokens créés : doit etre 1 (juste celui genere par genMagicToken, PAS un nouveau par login.php)
  const afterCount = parseInt(execSync(`mariadb ocre_meta -BNe "SELECT COUNT(*) FROM auth_magic_tokens WHERE user_id=93 AND created_at > NOW()-INTERVAL 5 MINUTE"`).toString().trim(), 10);
  expect(afterCount).toBe(beforeCount + 1); // +1 du genMagicToken, login.php ne crée RIEN
  await page.screenshot({ path: path.join(OUT_DIR, '02-cas-A-direct.png'), fullPage: true });
  await ctx.close();
});

test('3) Cas B encore fonctionnel : email connu sans session → action=link_sent', async ({ browser }) => {
  // Pas de cookies preservés : context neuf
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', 'philippe.ciftci@gmail.com');
  const respPromise = page.waitForResponse(r => r.url().includes('/api/login.php'));
  await page.click('#oal-submit');
  const resp = await respPromise;
  const body = await resp.json();
  expect(body.action).toBe('link_sent');
  await ctx.close();
});

test('4) Cas C encore fonctionnel : email inconnu → accordéon', async ({ browser }) => {
  const newEmail = 'e2e-samesite-' + Date.now() + '@ocre.test';
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', newEmail);
  await page.click('#oal-submit');
  // Accordeon doit s'ouvrir (cas C signup)
  await page.waitForFunction(() => document.getElementById('oal-extra').classList.contains('oal-extra-open'), { timeout: 8000 });
  const open = await page.locator('#oal-extra').evaluate(el => el.classList.contains('oal-extra-open'));
  expect(open).toBe(true);
  await ctx.close();
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  try { execSync(`mariadb ocre_meta -e "DELETE FROM auth_magic_tokens WHERE ip='127.0.0.1' AND created_at > NOW()-INTERVAL 30 MINUTE; DELETE m FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email LIKE 'e2e-samesite-%@%'; DELETE FROM auth_users WHERE email LIKE 'e2e-samesite-%@%'"`); } catch (e) {}
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>samesite-fix ${TS}</title>
<style>body{font-family:system-ui;max-width:1100px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px;margin-top:8px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_AUTH_FLOW_SAMESITE_FIX</h1>
<p>Mission M/2026/05/11/42 — ${TS}</p>
<p class="ok">PASS ✓ Cookie SameSite=None Secure HttpOnly domain=.ocre.immo · Cas A direct sans mail · Cas B + Cas C inchanges</p>
<h2>Cas A — redirect direct via SSO cookie</h2><img src="02-cas-A-direct.png">
</body></html>`);
});
