// M/2026/05/11/30 — Test mobile drawer superadmin sur iPad portrait (768x1024).
// Vérifie : hamburger FAB top-left visible, drawer fermé par défaut, clic ouvre drawer
// avec overlay, clic overlay ferme, click nav-item navigue + ferme.
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ADMIN_EMAIL = 'philippe.ciftci@gmail.com';
const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/superadmin-mobile-${TS}`;

function genActivateToken() {
  const tok = crypto.randomBytes(32).toString('hex');
  execSync(`mariadb ocre_meta -e "UPDATE users SET activation_token='${tok}', activation_token_expires_at=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email='${ADMIN_EMAIL}' AND role='super_admin'"`);
  return tok;
}

test.use({ viewport: { width: 768, height: 1024 } });

test('iPad portrait : hamburger FAB top-left + drawer', async ({ page }) => {
  test.setTimeout(60000);
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

  const tok = genActivateToken();
  await page.goto(`https://superadmin.ocre.immo/?activate=${tok}`);
  await page.waitForSelector('#app:not([hidden])', { timeout: 20000 });

  // 1. Screenshot initial (drawer fermé)
  await page.screenshot({ path: path.join(OUT_DIR, '01-initial.png'), fullPage: true });

  // 2. Vérif hamburger FAB visible + top-left
  const fab = page.locator('#hamburger-btn');
  await expect(fab).toBeVisible();
  const fabBox = await fab.boundingBox();
  expect(fabBox.y).toBeLessThan(60); // près du haut
  expect(fabBox.x).toBeLessThan(60); // près de la gauche

  // 3. Sidebar masquée (transform translateX(-100%))
  const sidebar = page.locator('#sidebar');
  const sidebarBoxClosed = await sidebar.boundingBox();
  expect(sidebarBoxClosed.x).toBeLessThan(0); // hors écran à gauche

  // 4. Clic hamburger ouvre drawer
  await fab.click();
  await page.waitForTimeout(350); // attendre animation 250ms
  await page.screenshot({ path: path.join(OUT_DIR, '02-drawer-open.png'), fullPage: true });
  await expect(sidebar).toHaveClass(/open/);
  const sidebarBoxOpen = await sidebar.boundingBox();
  expect(sidebarBoxOpen.x).toBeGreaterThanOrEqual(0); // visible

  // 5. Overlay visible
  const backdrop = page.locator('#sidebar-backdrop');
  await expect(backdrop).toHaveClass(/open/);

  // 6. body scroll lock
  const bodyOverflow = await page.evaluate(() => getComputedStyle(document.body).overflow);
  expect(bodyOverflow).toBe('hidden');

  // 7. 8 sections visibles dans le drawer
  const navItems = await page.locator('.nav-item').count();
  expect(navItems).toBe(8);

  // 8. Clic overlay ferme drawer
  await backdrop.click({ position: { x: 600, y: 500 } }); // clic en dehors du drawer
  await page.waitForTimeout(350);
  await expect(sidebar).not.toHaveClass(/open/);
  const bodyOverflowAfter = await page.evaluate(() => getComputedStyle(document.body).overflow);
  expect(bodyOverflowAfter).not.toBe('hidden');

  // 9. Clic close X (re-open puis close via bouton)
  await fab.click();
  await page.waitForTimeout(350);
  await page.click('#drawer-close-btn');
  await page.waitForTimeout(350);
  await expect(sidebar).not.toHaveClass(/open/);

  // 10. Naviguer via une section : ouvre drawer, clique Users, drawer doit fermer auto (closeSidebar appelée dans render)
  await fab.click();
  await page.waitForTimeout(350);
  await page.click('.nav-item[data-section="users"]');
  await page.waitForTimeout(500);
  await expect(sidebar).not.toHaveClass(/open/);
  expect(await page.textContent('#section-title')).toBe('Utilisateurs');
  await page.screenshot({ path: path.join(OUT_DIR, '03-after-nav-click.png'), fullPage: true });

  // 11. Aucune erreur console
  expect(errors).toEqual([]);

  // Cleanup
  try { execSync(`mariadb ocre_meta -e "UPDATE users SET activation_token=NULL WHERE email='${ADMIN_EMAIL}'"`); } catch (e) {}

  // Rapport HTML simple
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>Mobile drawer ${TS}</title>
<style>body{font-family:system-ui;max-width:900px;margin:auto;padding:24px;background:#fafaf7}h1{font-size:22px}h2{font-size:14px;color:#001D3D;margin-top:24px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:8px;margin-top:8px}.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>Superadmin mobile drawer — iPad portrait 768×1024</h1>
<p>Mission M/2026/05/11/30 — ${TS}</p>
<p class="ok">PASS ✓ Hamburger FAB top-left + drawer slide-in + overlay + body scroll lock + close X + auto-close on nav</p>
<h2>1) Initial (drawer fermé)</h2><img src="01-initial.png">
<h2>2) Drawer ouvert</h2><img src="02-drawer-open.png">
<h2>3) Après nav click (drawer auto-fermé)</h2><img src="03-after-nav-click.png">
</body></html>`);
  console.log('Mobile walkthrough : https://46-225-215-148.sslip.io/maquettes/superadmin-mobile-' + TS + '/');
});
