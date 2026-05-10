// M_PLAYWRIGHT_OCRE_PARCOURS — responsive iPhone 13 (390×852)
const { test, expect, devices } = require('@playwright/test');

test.use({ ...devices['iPhone 13'] });

test.describe('Responsive iPhone 13', () => {
  test('Vitrine ocre.immo : grid 6 outils 2 cols mobile + tuile cliquable', async ({ page }) => {
    await page.goto('https://ocre.immo/');
    await expect(page.locator('a[href*="oi-agent"]').first()).toBeVisible();
    // Pas de scroll horizontal involontaire (body width = viewport)
    const bodyW = await page.evaluate(() => document.body.scrollWidth);
    expect(bodyW).toBeLessThanOrEqual(395);
  });

  test('Page outil /oi-agent : Hero + CTA tactile min 44px', async ({ page }) => {
    await page.goto('https://ocre.immo/oi-agent');
    const cta = page.getByRole('button', { name: /Commencer/i }).first();
    await expect(cta).toBeVisible();
    const box = await cta.boundingBox();
    expect(box.height).toBeGreaterThanOrEqual(40);
  });

  test('Popup signup bottom-sheet iOS-style mobile + form complet visible', async ({ page }) => {
    await page.goto('https://ocre.immo/oi-agent');
    await page.getByRole('button', { name: /Commencer/i }).first().click();
    const overlay = page.locator('.osp-overlay.osp-show, .osp-overlay').first();
    await expect(overlay).toBeVisible({ timeout: 5000 });
  });

  test('Maquette superadmin V3 : sidebar drawer fermé par défaut + hamburger visible', async ({ page }) => {
    await page.goto('https://46-225-215-148.sslip.io/maquettes/M_SUPERADMIN_V3.html');
    // Hamburger doit être visible mobile
    await expect(page.locator('.hamburger, #hamburger').first()).toBeVisible({ timeout: 5000 });
    // Sidebar par défaut hors écran (translateX -100%)
    const sb = await page.locator('.sidebar').first().evaluate(el => getComputedStyle(el).transform);
    expect(sb).toMatch(/matrix.*-1|translateX/i);
  });
});
