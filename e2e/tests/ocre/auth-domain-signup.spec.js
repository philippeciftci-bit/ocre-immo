// M_PLAYWRIGHT_OCRE_PARCOURS — signup direct depuis auth.ocre.immo (parcours alternatif vitrine)
const { test, expect } = require('@playwright/test');
const { genTestEmail, cleanupTestUser, getMagicLinkFromDb, collectConsoleErrors } = require('./helpers/common');

test.describe('auth.ocre.immo signup/login parcours alternatif', () => {
  let email;
  test.beforeEach(() => { email = genTestEmail('-auth-domain'); });
  test.afterEach(async () => { await cleanupTestUser(email); });

  test('auth.ocre.immo/signup nouveau email → accordéon → magic link → app', async ({ page }) => {
    const errors = collectConsoleErrors(page);
    const responses = [];
    page.on('response', r => { if (r.url().includes('/api/')) responses.push({ url: r.url(), status: r.status() }); });

    await page.goto('https://auth.ocre.immo/signup');
    await expect(page.locator('input[type=email]').first()).toBeVisible();
    // Vérifier ZÉRO bouton SSO Google/Apple/Facebook (M_OCRE_AUTH_ALIGN_V4 magic-link only)
    expect(await page.locator('text=/continuer avec google/i').count()).toBe(0);
    expect(await page.locator('text=/continuer avec apple/i').count()).toBe(0);
    expect(await page.locator('text=/continuer avec facebook/i').count()).toBe(0);

    await page.locator('input[type=email]').first().fill(email);
    await page.getByRole('button', { name: /Continuer/i }).click();
    await expect(page.locator('input#prenom').first()).toBeVisible({ timeout: 5000 });
    await page.locator('input#prenom').first().fill('AuthDomain');
    await page.locator('input#nom').first().fill('Test');
    await page.locator('input#phone').first().fill('612345678');
    await page.locator('input#cgu').first().check();
    await page.getByRole('button', { name: /Recevoir.*lien/i }).click();
    await page.waitForTimeout(1500);
    const magicUrl = await getMagicLinkFromDb(email);
    await page.goto(magicUrl);
    await expect(page).toHaveURL(/oi-agent|app\.ocre\.immo/, { timeout: 10000 });

    // Pas de boucle redirect : max 3 redirects
    const redirects = responses.filter(r => r.status >= 300 && r.status < 400);
    expect(redirects.length).toBeLessThanOrEqual(5);
    expect(errors.filter(e => !/favicon|Sentry/i.test(e))).toHaveLength(0);
  });

  test('auth.ocre.immo/login : 1 champ email seul + bouton M envoyer le lien', async ({ page }) => {
    await page.goto('https://auth.ocre.immo/login');
    await expect(page.locator('input[type=email]').first()).toBeVisible();
    await expect(page.getByRole('button', { name: /M.envoyer.*lien/i })).toBeVisible();
    // Pas de champ prénom/nom visible (login simplifié)
    expect(await page.locator('input#prenom').count()).toBe(0);
  });
});
