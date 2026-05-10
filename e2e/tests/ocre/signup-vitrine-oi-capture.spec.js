// M_PLAYWRIGHT_OCRE_PARCOURS — signup nouveau user via vitrine ocre.immo → Oi Capture
const { test, expect } = require('@playwright/test');
const { getMagicLinkFromDb, cleanupTestUser, genTestEmail, collectConsoleErrors } = require('./helpers/common');

test.describe('Signup vitrine ocre.immo → Oi Capture', () => {
  let email;
  test.beforeEach(() => { email = genTestEmail('-capture'); });
  test.afterEach(async () => { await cleanupTestUser(email); });

  test('Parcours complet : home → tuile → popup → form → magic link → app', async ({ page }) => {
    const errors = collectConsoleErrors(page);
    await page.goto('https://ocre.immo/');
    await expect(page).toHaveTitle(/Ocre/i);
    await page.click('a[href*="oi-capture"]');
    await expect(page).toHaveURL(/oi-capture/);
    await page.getByRole('button', { name: /Commencer/i }).first().click();
    const emailInput = page.locator('input[type=email]').first();
    await expect(emailInput).toBeVisible({ timeout: 5000 });
    await emailInput.fill(email);
    await page.getByRole('button', { name: /Continuer/i }).click();
    await expect(page.locator('input#osp-prenom, input#prenom').first()).toBeVisible({ timeout: 5000 });
    await page.locator('input#osp-prenom, input#prenom').first().fill('TestE2E');
    await page.locator('input#osp-nom, input#nom').first().fill('Playwright');
    await page.locator('input#osp-phone, input#phone').first().fill('612345678');
    await page.locator('input#osp-cgu, input#cgu').first().check();
    const submitBtn = page.getByRole('button', { name: /Recevoir.*lien/i });
    await expect(submitBtn).toBeEnabled({ timeout: 5000 });
    await submitBtn.click();
    await expect(page.getByText(/lien envoyé|email envoyé/i)).toBeVisible({ timeout: 10000 });
    await page.waitForTimeout(1500);
    const magicUrl = await getMagicLinkFromDb(email);
    expect(magicUrl).toContain('token=');
    await page.goto(magicUrl);
    await expect(page).toHaveURL(/oi-capture|app\.ocre\.immo/, { timeout: 10000 });
    expect(errors.filter(e => !/favicon|Sentry|GlitchTip/i.test(e))).toHaveLength(0);
  });
});
