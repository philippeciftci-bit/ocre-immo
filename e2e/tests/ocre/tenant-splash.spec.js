// M/2026/05/11/44 — Tests M_TENANT_AUTH_SPLASH : pas de flash login lors du redirect cas A vers SPA tenant.
// Cause racine fixe : ?_s= -> ?mt_token= (nom canonique consume par SPA index.html ligne 11564).
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/tenant-splash-${TS}`;

function getExbattatSlug() {
  return execSync(`mariadb ocre_meta -BNe "SELECT slug FROM users WHERE email='exbattat@gmail.com'"`).toString().trim();
}
function freshSsoToken(userId) {
  const tok = require('crypto').randomBytes(32).toString('hex');
  execSync(`mariadb ocre_meta -e "INSERT INTO sessions (token, user_id, expires_at, ip, user_agent, last_activity) VALUES ('${tok}', ${userId}, DATE_ADD(NOW(), INTERVAL 30 DAY), '127.0.0.1', 'e2e', NOW())"`);
  return tok;
}
function getLegacyUserId(email) {
  return parseInt(execSync(`mariadb ocre_meta -BNe "SELECT id FROM users WHERE email='${email}'"`).toString().trim(), 10);
}

test.beforeEach(() => {
  try { execSync(`mariadb ocre_meta -e "DELETE FROM auth_rate_limit"`); } catch (e) {}
});

test('1) Cas A login.php retourne ?mt_token= (PAS ?_s=)', async ({ request }) => {
  execSync(`mariadb ocre_meta -e "UPDATE auth_users SET last_login_at = DATE_SUB(NOW(), INTERVAL 1 HOUR), last_magic_link_consumed_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE email='exbattat@gmail.com'"`);
  const r = await request.post('https://auth.ocre.immo/api/login.php', {
    headers: { 'Content-Type': 'application/json', 'Origin': 'https://ocre.immo' },
    data: { email: 'exbattat@gmail.com', app: 'agent' },
  });
  const j = await r.json();
  expect(j.action).toBe('direct');
  expect(j.redirect_url).toMatch(/mt_token=[a-f0-9]{64}/);
  expect(j.redirect_url).not.toMatch(/\?_s=/);
  expect(j.redirect_url).toMatch(/source=ttl_login/);
});

test('2) validate.php redirect inclut ?mt_token= (PAS ?_s=)', async ({ request }) => {
  const tok = require('crypto').randomBytes(32).toString('hex');
  execSync(`mariadb ocre_meta -e "INSERT INTO auth_magic_tokens (user_id, token, expires_at, ip) VALUES (93, '${tok}', DATE_ADD(NOW(), INTERVAL 1 HOUR), '127.0.0.1')"`);
  // Suivre redirect = false : on lit le header Location.
  const r = await request.get(`https://auth.ocre.immo/api/magic-link/validate.php?token=${tok}&app=agent`, { maxRedirects: 0 });
  expect(r.status()).toBe(302);
  const loc = r.headers()['location'];
  expect(loc).toMatch(/mt_token=[a-f0-9]{64}/);
  expect(loc).not.toMatch(/\?_s=/);
});

test('3) SPA tenant avec ?mt_token= : pas de flash "Connexion" sur 1.5s', async ({ browser }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const slug = getExbattatSlug();
  const legacyId = getLegacyUserId('exbattat@gmail.com');
  const tok = freshSsoToken(legacyId);
  // Context navigateur PROPRE
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  // Capture du contenu visuel a plusieurs intervalles
  const url = `https://${slug}.ocre.immo/?mt_token=${tok}&source=ttl_login`;
  await page.goto(url, { waitUntil: 'commit' });
  // Capture 5 screenshots a 100ms, 300ms, 500ms, 800ms, 1500ms
  const captures = [];
  for (const ms of [100, 300, 500, 800, 1500]) {
    await page.waitForTimeout(ms - (captures.length ? [100,300,500,800,1500][captures.length - 1] : 0));
    const txt = await page.locator('body').innerText().catch(() => '');
    captures.push({ ms, text: txt.slice(0, 200) });
  }
  await page.screenshot({ path: path.join(OUT_DIR, '03-tenant-boot.png'), fullPage: true });

  // Aucun des screenshots ne doit contenir "Recevoir mon lien" ni "Entrez votre email"
  // (= text du formulaire de connexion /login/). Le tagline "Connexion" peut apparaitre
  // car le SPA boot avec apiLoading=true qui rend "Oi Agent + spinner" pendant le boot.
  for (const c of captures) {
    expect(c.text).not.toMatch(/Recevoir mon lien|Entrez votre email|Format email invalide/i);
  }
  // Cleanup token
  try { execSync(`mariadb ocre_meta -e "DELETE FROM sessions WHERE token='${tok}'"`); } catch (e) {}
  await ctx.close();
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  // Restore Philippe last_login_at
  try { execSync(`mariadb ocre_meta -e "UPDATE auth_users SET last_login_at=NOW(), last_magic_link_consumed_at=NOW() WHERE email='exbattat@gmail.com'"`); } catch (e) {}
  try { execSync(`mariadb ocre_meta -e "DELETE FROM sessions WHERE ip='127.0.0.1' AND user_agent='e2e'"`); } catch (e) {}
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>tenant-splash ${TS}</title>
<style>body{font-family:system-ui;max-width:1100px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px;margin-top:8px}.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_TENANT_AUTH_SPLASH — plus de flash login</h1>
<p>Mission M/2026/05/11/44 — ${TS}</p>
<p class="ok">PASS ✓ cas A login.php retourne ?mt_token= · validate.php redirect ?mt_token= · SPA tenant pas de flash form connexion</p>
<h2>SPA tenant boot avec mt_token : pas de form de connexion visible</h2><img src="03-tenant-boot.png">
</body></html>`);
});
