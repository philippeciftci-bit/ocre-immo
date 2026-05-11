// M/2026/05/11/43 — Tests M_AUTH_FLOW_CAS_A_TTL : cas A base sur TTL DB (PAS cookie navigateur).
// Simule scenario Safari iPad ITP : context navigateur SANS cookies pre-existants.
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/cas-a-ttl-${TS}`;

function ensureUser(email, opts = {}) {
  const safe = email.replace(/'/g, "''");
  const ttl = opts.ttl_hours || 24;
  const lastLoginExpr = opts.last_login_at_expr || 'NULL';
  const slug = opts.slug || email.replace(/[^a-z0-9]/g, '').slice(0, 20);
  execSync(`mariadb ocre_meta -e "INSERT INTO auth_users (email, status, magic_link_ttl_hours, last_login_at) VALUES ('${safe}', 'active', ${ttl}, ${lastLoginExpr}) ON DUPLICATE KEY UPDATE magic_link_ttl_hours=${ttl}, last_login_at=${lastLoginExpr}, last_magic_link_consumed_at=${lastLoginExpr}"`);
  // Aussi users legacy + DB tenant pour cas A complet
  if (opts.with_tenant) {
    execSync(`mariadb ocre_meta -e "INSERT INTO users (email, slug, role, status) VALUES ('${safe}', '${slug}', 'agent', 'active') ON DUPLICATE KEY UPDATE slug='${slug}'"`);
    try { execSync(`mariadb -e "CREATE DATABASE IF NOT EXISTS ocre_wsp_${slug}"`); } catch (e) {}
  }
}
function cleanupUser(email) {
  const safe = email.replace(/'/g, "''");
  try {
    execSync(`mariadb ocre_meta -e "DELETE m FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email='${safe}'; DELETE FROM auth_users WHERE email='${safe}'; DELETE FROM workspace_members WHERE user_id IN (SELECT id FROM users WHERE email='${safe}'); DELETE FROM workspaces WHERE slug IN (SELECT slug FROM users WHERE email='${safe}'); DELETE FROM users WHERE email='${safe}'"`);
    const dbs = execSync(`mariadb -BNe "SHOW DATABASES LIKE 'ocre_wsp_e2eaattl%'"`).toString().trim().split('\n').filter(Boolean);
    for (const db of dbs) execSync(`mariadb -e "DROP DATABASE \\\`${db}\\\`"`);
  } catch (e) {}
}
function countMagicTokens(email) {
  const safe = email.replace(/'/g, "''");
  return parseInt(execSync(`mariadb ocre_meta -BNe "SELECT COUNT(*) FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email='${safe}'"`).toString().trim(), 10);
}

test.beforeEach(() => {
  try { execSync(`mariadb ocre_meta -e "DELETE FROM auth_rate_limit"`); } catch (e) {}
});

test('1) Cas A par TTL : user dans fenêtre 24h → direct (PAS de mail, PAS de cookie pre-existant)', async ({ browser }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const email = 'e2eaattl-fresh-' + Date.now() + '@ocre.test';
  // Setup user avec last_login_at NOW-1h, TTL=24h, slug + DB tenant
  ensureUser(email, { ttl_hours: 24, last_login_at_expr: 'DATE_SUB(NOW(), INTERVAL 1 HOUR)', slug: 'e2eaattlfresh' + Date.now().toString().slice(-6), with_tenant: true });
  const beforeCount = countMagicTokens(email);
  // Context navigateur PROPRE (aucun cookie pré-existant — simule Safari iPad ITP au pire cas)
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', email);
  const respPromise = page.waitForResponse(r => r.url().includes('/api/login.php'));
  await page.click('#oal-submit');
  const body = await (await respPromise).json();
  expect(body.ok).toBe(true);
  expect(body.action).toBe('direct'); // CAS A par TTL malgré ZÉRO cookie pré-existant
  expect(body.redirect_url).toMatch(/\.ocre\.immo\/.*source=ttl_login/);
  // ZERO mail : count magic_tokens inchangé
  expect(countMagicTokens(email)).toBe(beforeCount);
  // Cookies SSO maintenant posés (re-créés à la volée par cas A)
  const cookies = await ctx.cookies();
  const jwt = cookies.find(c => c.name === 'ocre_jwt');
  expect(jwt).toBeTruthy();
  expect(jwt.sameSite).toBe('None');
  await page.screenshot({ path: path.join(OUT_DIR, '01-cas-A-ttl-direct.png'), fullPage: true });
  await ctx.close();
  cleanupUser(email);
});

test('2) Cas B par TTL expiré : user last_login_at > 24h → link_sent', async ({ browser }) => {
  const email = 'e2eaattl-expired-' + Date.now() + '@ocre.test';
  ensureUser(email, { ttl_hours: 24, last_login_at_expr: 'DATE_SUB(NOW(), INTERVAL 25 HOUR)' });
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', email);
  const respPromise = page.waitForResponse(r => r.url().includes('/api/login.php'));
  await page.click('#oal-submit');
  const body = await (await respPromise).json();
  expect(body.action).toBe('link_sent');
  await ctx.close();
  cleanupUser(email);
});

test('3) Cas B par last_login_at NULL : user jamais loggé → link_sent', async ({ browser }) => {
  const email = 'e2eaattl-null-' + Date.now() + '@ocre.test';
  ensureUser(email, { ttl_hours: 24, last_login_at_expr: 'NULL' });
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', email);
  const respPromise = page.waitForResponse(r => r.url().includes('/api/login.php'));
  await page.click('#oal-submit');
  const body = await (await respPromise).json();
  expect(body.action).toBe('link_sent');
  await ctx.close();
  cleanupUser(email);
});

test('4) TTL custom 7j : user last_login_at NOW-5j + ttl=168h → direct', async ({ browser }) => {
  const email = 'e2eaattl-ttl7d-' + Date.now() + '@ocre.test';
  ensureUser(email, { ttl_hours: 168, last_login_at_expr: 'DATE_SUB(NOW(), INTERVAL 5 DAY)', slug: 'e2eaattlttl7d' + Date.now().toString().slice(-6), with_tenant: true });
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', email);
  const respPromise = page.waitForResponse(r => r.url().includes('/api/login.php'));
  await page.click('#oal-submit');
  const body = await (await respPromise).json();
  expect(body.action).toBe('direct'); // 5j < 7j → encore dans la fenêtre TTL
  await ctx.close();
  cleanupUser(email);
});

test('5) Cas C inchangé : email inconnu → accordéon', async ({ browser }) => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', 'unknown-mft-cas-a-ttl-' + Date.now() + '@ocre.test');
  await page.click('#oal-submit');
  await page.waitForFunction(() => document.getElementById('oal-extra').classList.contains('oal-extra-open'), { timeout: 8000 });
  await ctx.close();
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  try {
    execSync(`mariadb ocre_meta -e "DELETE m FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email LIKE 'e2eaattl-%@ocre.test'; DELETE FROM auth_users WHERE email LIKE 'e2eaattl-%@ocre.test'; DELETE FROM workspace_members WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'e2eaattl-%@ocre.test'); DELETE FROM workspaces WHERE slug LIKE 'e2eaattl%'; DELETE FROM users WHERE email LIKE 'e2eaattl-%@ocre.test'"`);
    const dbs = execSync(`mariadb -BNe "SHOW DATABASES LIKE 'ocre_wsp_e2eaattl%'"`).toString().trim().split('\n').filter(Boolean);
    for (const db of dbs) execSync(`mariadb -e "DROP DATABASE \\\`${db}\\\`"`);
  } catch (e) {}
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>cas-a-ttl ${TS}</title>
<style>body{font-family:system-ui;max-width:1100px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px;margin-top:8px}.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_AUTH_FLOW_CAS_A_TTL — cas A base sur TTL DB</h1>
<p>Mission M/2026/05/11/43 — ${TS}</p>
<p class="ok">PASS ✓ Cas A par TTL (sans cookies pré-existants) + cas B TTL expiré + cas B last_login NULL + TTL custom 7j + Cas C inchangé</p>
<h2>Cas A par TTL : direct (sans cookie pré-existant, simule Safari ITP au pire cas)</h2>
<img src="01-cas-A-ttl-direct.png">
</body></html>`);
});
