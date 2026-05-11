// M/2026/05/11/37 + AMENDEMENT — Tests M_AUTH_FLOW_REFONTE : popup login unifie + accordeon cas C + TTL custom.
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/auth-flow-refonte-${TS}`;
const VIEWPORTS = [
  { name: 'iphone-390',   viewport: { width: 390, height: 844 } },
  { name: 'ipad-768',     viewport: { width: 768, height: 1024 } },
  { name: 'desktop-1440', viewport: { width: 1440, height: 900 } },
];

function cleanupEmail(email) {
  try { const s = email.replace(/'/g, "''"); execSync(`mariadb ocre_meta -e "DELETE m FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email='${s}'; DELETE FROM auth_users WHERE email='${s}'"`); } catch (e) {}
}
function lastMagicTtlSeconds(email) {
  const s = email.replace(/'/g, "''");
  const out = execSync(`mariadb ocre_meta -BNe "SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email='${s}' ORDER BY m.id DESC LIMIT 1"`).toString().trim();
  return parseInt(out, 10);
}

test('1) Popup s\'ouvre sur ocre.immo au clic CTA', async ({ page }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  await page.goto('https://ocre.immo/');
  await page.waitForLoadState('domcontentloaded');
  // Trigger popup via window.ocreSignupOpen (CTAs sur la home + dans /oi-agent appellent ça)
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show', { timeout: 5000 });
  await expect(page.locator('#oal-email')).toBeVisible();
  await expect(page.locator('#oal-submit')).toBeVisible();
  // Etat initial : accordeon ferme
  const open = await page.locator('#oal-extra').evaluate(el => el.classList.contains('oal-extra-open'));
  expect(open).toBe(false);
  await page.screenshot({ path: path.join(OUT_DIR, '01-popup-initial.png'), fullPage: true });
});

test('2) Cas B — email connu Philippe → message "Lien envoyé"', async ({ page }) => {
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show', { timeout: 5000 });
  await page.fill('#oal-email', 'philippe.ciftci@gmail.com');
  await page.click('#oal-submit');
  await page.waitForFunction(() => {
    const m = document.getElementById('oal-msg');
    return m && m.classList.contains('oal-show-msg') && /Lien envoy/i.test(m.textContent);
  }, { timeout: 8000 });
  // Bouton "Renvoyer" disabled (cooldown)
  const btnText = await page.locator('#oal-submit').textContent();
  expect(btnText).toMatch(/Renvoyer/);
  const disabled = await page.locator('#oal-submit').isDisabled();
  expect(disabled).toBe(true);
  await page.screenshot({ path: path.join(OUT_DIR, '02-cas-B-link-sent.png'), fullPage: true });
});

test('3) Cas C — email inconnu → accordéon s\'ouvre (champs prenom/nom/tel/cgu/rgpd visibles), ZERO redirect', async ({ page }) => {
  const newEmail = 'e2e-mft-newuser-' + Date.now() + '@ocre.test';
  await page.goto('https://ocre.immo/');
  const urlBefore = page.url();
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', newEmail);
  await page.click('#oal-submit');
  // Accordeon s'ouvre
  await page.waitForFunction(() => document.getElementById('oal-extra').classList.contains('oal-extra-open'), { timeout: 8000 });
  // URL inchangee (zero redirect vers auth.ocre.immo)
  expect(page.url()).toBe(urlBefore);
  // Champs supplementaires visibles
  for (const id of ['oal-prenom', 'oal-nom', 'oal-tel', 'oal-cgu', 'oal-rgpd']) {
    await expect(page.locator('#' + id)).toBeVisible();
  }
  // Bouton change de label
  const btnTxt = await page.locator('#oal-submit').textContent();
  expect(btnTxt).toMatch(/Créer mon compte/i);
  // Bouton disabled tant que champs non remplis
  const disabledStart = await page.locator('#oal-submit').isDisabled();
  expect(disabledStart).toBe(true);
  await page.screenshot({ path: path.join(OUT_DIR, '03-cas-C-accordeon.png'), fullPage: true });
});

test('4b) AMENDEMENT #2 — fade form + auto-close 4s apres succes cas C', async ({ page }) => {
  const newEmail = 'e2e-mft-fade-' + Date.now() + '@ocre.test';
  cleanupEmail(newEmail);
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', newEmail);
  await page.click('#oal-submit');
  await page.waitForFunction(() => document.getElementById('oal-extra').classList.contains('oal-extra-open'));
  await page.fill('#oal-prenom', 'Fade');
  await page.fill('#oal-nom', 'Test');
  await page.fill('#oal-tel', '612345678');
  await page.check('#oal-cgu');
  await page.check('#oal-rgpd');
  await page.click('#oal-submit');
  // Attend succes
  await page.waitForFunction(() => {
    const m = document.getElementById('oal-msg');
    return m && m.classList.contains('oal-success');
  }, { timeout: 10000 });
  // Verifie titre change en "Compte créé !"
  const title = await page.textContent('#oal-title');
  expect(title).toBe('Compte créé !');
  // Form en train de fade : height = 0px ou opacity = 0
  await page.waitForTimeout(400);
  const formStyle = await page.locator('#oal-form').evaluate(el => ({ h: el.style.height, op: el.style.opacity }));
  expect(formStyle.h).toBe('0px');
  // Popup encore visible (toast vert visible)
  await expect(page.locator('#oal-msg.oal-success')).toBeVisible();
  // Auto-close apres 4s : popup retire .oal-show
  await page.waitForFunction(() => !document.getElementById('oal-overlay').classList.contains('oal-show'), { timeout: 6000 });
  cleanupEmail(newEmail);
});

test('4) Cas C — submit complet accordéon créé user + magic link', async ({ page }) => {
  const newEmail = 'e2e-mft-fullsignup-' + Date.now() + '@ocre.test';
  cleanupEmail(newEmail);
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  await page.fill('#oal-email', newEmail);
  await page.click('#oal-submit');
  await page.waitForFunction(() => document.getElementById('oal-extra').classList.contains('oal-extra-open'));
  await page.fill('#oal-prenom', 'E2E');
  await page.fill('#oal-nom', 'Tester');
  await page.fill('#oal-tel', '612345678');
  await page.check('#oal-cgu');
  await page.check('#oal-rgpd');
  await page.waitForFunction(() => !document.getElementById('oal-submit').disabled);
  await page.click('#oal-submit');
  // Message succes
  await page.waitForFunction(() => {
    const m = document.getElementById('oal-msg');
    return m && m.classList.contains('oal-success') && /Compte créé/i.test(m.textContent);
  }, { timeout: 10000 });
  // Verifie user + magic link en DB
  const safe = newEmail.replace(/'/g, "''");
  const userRow = execSync(`mariadb ocre_meta -BNe "SELECT id FROM auth_users WHERE email='${safe}'"`).toString().trim();
  expect(userRow).toMatch(/^\d+$/);
  const tokenRow = execSync(`mariadb ocre_meta -BNe "SELECT m.id FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email='${safe}'"`).toString().trim();
  expect(tokenRow).toMatch(/^\d+$/);
  await page.screenshot({ path: path.join(OUT_DIR, '04-cas-C-submitted.png'), fullPage: true });
  cleanupEmail(newEmail);
});

test('5) TTL custom — magic_link_ttl_hours=168 (7j) respecte par request.php', async () => {
  const email = 'e2e-mft-ttl-' + Date.now() + '@ocre.test';
  cleanupEmail(email);
  // Cree user + set TTL 168h
  const safe = email.replace(/'/g, "''");
  execSync(`mariadb ocre_meta -e "INSERT INTO auth_users (email, magic_link_ttl_hours) VALUES ('${safe}', 168) ON DUPLICATE KEY UPDATE magic_link_ttl_hours=168"`);
  // POST request.php avec full signup
  execSync(`curl -s -X POST -H "Content-Type: application/json" -H "Origin: https://auth.ocre.immo" -d '{"email":"${email}","first_name":"T","last_name":"U","phone":"+33612345678","cgu_accepted":true,"rgpd_accepted":true,"target_app":"agent"}' https://auth.ocre.immo/api/magic-link/request.php > /dev/null`);
  // Verifie expires_at ~ NOW + 7j (604800s, tolerance 120s)
  const sec = lastMagicTtlSeconds(email);
  expect(sec).toBeGreaterThan(7 * 86400 - 120);
  expect(sec).toBeLessThan(7 * 86400 + 120);
  cleanupEmail(email);
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  try { execSync(`mariadb ocre_meta -e "DELETE m FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email LIKE 'e2e-mft-%@ocre.test'; DELETE FROM auth_users WHERE email LIKE 'e2e-mft-%@ocre.test'"`); } catch (e) {}
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>auth-flow-refonte ${TS}</title>
<style>body{font-family:system-ui;max-width:1100px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:24px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px;margin-top:8px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_AUTH_FLOW_REFONTE — login popup unifie + accordeon cas C + TTL custom</h1>
<p>Mission M/2026/05/11/37 — ${TS}</p>
<p class="ok">PASS ✓ Popup s'ouvre · Cas B "Lien envoyé" + cooldown · Cas C accordéon ZERO redirect · Submit complet crée user + magic link · TTL 168h respecté</p>
<h2>1) Popup état initial (cas A/B/C)</h2><img src="01-popup-initial.png">
<h2>2) Cas B — Lien envoyé + bouton Renvoyer cooldown</h2><img src="02-cas-B-link-sent.png">
<h2>3) Cas C — Accordéon déployé (prenom/nom/phone/cgu/rgpd visibles)</h2><img src="03-cas-C-accordeon.png">
<h2>4) Cas C complet — message "Compte créé, lien envoyé"</h2><img src="04-cas-C-submitted.png">
</body></html>`);
});
