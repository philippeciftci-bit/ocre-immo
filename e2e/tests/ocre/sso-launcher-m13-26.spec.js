// M/2026/05/13/26 — SSO launcher app.ocre.immo : 5 tests Playwright headless Chromium.
// Verifie : signup public vivant + login pose cookie + launcher route vers tenant +
// bouton header SPA ouvre launcher + cookie expire/tampered renvoie /login.
const { test, expect } = require('@playwright/test');

const LAUNCHER = 'https://app.ocre.immo';
const TENANT_SPA = 'https://test-launcher.ocre.immo';
const SIGNUP = 'https://signup.ocre.immo';
const TEST_EMAIL = 'test-launcher@ocre.immo';
const TEST_PWD = 'LauncherTest2026!';

test.describe('SSO launcher M118', () => {
  test('T1 — signup public toujours vivant (zero regression)', async ({ page }) => {
    const r = await page.goto(SIGNUP, { waitUntil: 'domcontentloaded' });
    expect(r.status()).toBeLessThan(400);
    const body = await page.content();
    expect(body.length).toBeGreaterThan(500);
    // Le signup doit toujours contenir un formulaire d'inscription.
    const hasSignupSignal = /email|inscription|signup|cr[ée]e?r? un compte|prenom|pr[ée]nom/i.test(body);
    expect(hasSignupSignal).toBe(true);
  });

  test('T2 — login pose cookie ocre_sso Domain=.ocre.immo + redirect /', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto(`${LAUNCHER}/login`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login$/);
    await page.fill('input[name="email"]', TEST_EMAIL);
    await page.fill('input[name="password"]', TEST_PWD);
    await Promise.all([
      page.waitForURL(`${LAUNCHER}/`, { waitUntil: 'domcontentloaded' }),
      page.click('button[type="submit"]'),
    ]);

    const cookies = await context.cookies();
    const sso = cookies.find(c => c.name === 'ocre_sso');
    expect(sso, 'cookie ocre_sso devrait etre pose').toBeTruthy();
    expect(sso.domain).toBe('.ocre.immo');
    expect(sso.httpOnly).toBe(true);
    expect(sso.secure).toBe(true);
    expect(sso.sameSite).toMatch(/Lax/i);
  });

  test('T3 — launcher rend carte Oi Agent active routee vers tenant + 3 cartes Bientot', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto(`${LAUNCHER}/login`);
    await page.fill('input[name="email"]', TEST_EMAIL);
    await page.fill('input[name="password"]', TEST_PWD);
    await Promise.all([
      page.waitForURL(`${LAUNCHER}/`, { waitUntil: 'domcontentloaded' }),
      page.click('button[type="submit"]'),
    ]);

    const agentCard = page.locator('a[data-card="oi-agent"]');
    await expect(agentCard).toHaveCount(1);
    const href = await agentCard.getAttribute('href');
    expect(href).toBe(`${TENANT_SPA}/`);

    // 3 cartes "Bientot" non-cliquables.
    const soonCards = page.locator('.launcher-card-soon');
    await expect(soonCards).toHaveCount(3);
    for (const key of ['oi-scan', 'oi-book', 'oi-demande']) {
      await expect(page.locator(`[data-card="${key}"].launcher-card-soon`)).toHaveCount(1);
    }
    const badges = page.locator('.launcher-card-soon-badge');
    await expect(badges.first()).toHaveText(/Bient[oô]t/);
  });

  test('T4 — bouton header SPA "Tous mes outils" pointe vers launcher', async ({ request }) => {
    // Test source HTML statique du SPA tenant (avant boot JS, garantit la presence du bouton).
    // Tenant reel : exbattat-a312.ocre.immo (provisionne).
    const resp = await request.get('https://exbattat-a312.ocre.immo/', { ignoreHTTPSErrors: true });
    expect(resp.status()).toBeLessThan(500);
    const html = await resp.text();
    expect(html).toContain('data-ocre-launcher="1"');
    expect(html).toContain('https://app.ocre.immo/');
    expect(html).toMatch(/Tous mes outils/);
  });

  test('T5 — cookie tampered renvoie /login (HMAC reject) + cookie absent aussi', async ({ page, context }) => {
    // 5a : cookie tampered.
    await context.clearCookies();
    await context.addCookies([{
      name: 'ocre_sso',
      value: 'tampered_invalid_payload.tampered_invalid_sig',
      domain: '.ocre.immo',
      path: '/',
      secure: true,
      httpOnly: true,
      sameSite: 'Lax',
    }]);
    await page.goto(`${LAUNCHER}/`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login$/);

    // 5b : cookie absent -> meme comportement.
    await context.clearCookies();
    await page.goto(`${LAUNCHER}/`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login$/);
  });
});
