// M_PLAYWRIGHT_OCRE_PARCOURS — mini-launcher PWA /launcher
const { test, expect } = require('@playwright/test');

test.describe('Mini-launcher PWA /launcher', () => {
  test('Sans cookie ocre_jwt → redirect home publique', async ({ page }) => {
    await page.context().clearCookies();
    const resp = await page.goto('https://ocre.immo/launcher', { waitUntil: 'load' });
    // Doit rediriger vers home (statut redirect chain final = 200 sur /)
    expect(page.url()).toMatch(/^https:\/\/ocre\.immo\/?(\?|$)/);
  });

  test('Avec cookie JWT simulé → grid 6 outils affichées', async ({ page, context }) => {
    // Simuler cookie JWT (payload base64url avec exp futur)
    const payload = Buffer.from(JSON.stringify({ sub: 11, iat: Date.now()/1000|0, exp: 9999999999, first_name: 'TestE2E' })).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
    const fakeJwt = `eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.${payload}.fake_sig`;
    await context.addCookies([{ name: 'ocre_jwt', value: fakeJwt, domain: '.ocre.immo', path: '/', secure: true, httpOnly: false, sameSite: 'Lax' }]);
    await page.goto('https://ocre.immo/launcher');
    // 6 tuiles présentes
    const tiles = page.locator('.lh-tile');
    await expect(tiles).toHaveCount(6, { timeout: 5000 });
    // Au moins une tuile contient "en cours" (modules non débloqués grisés)
    const enCours = page.locator('.lh-tile.lh-locked, .lh-tile:has-text("en cours")');
    await expect(enCours.first()).toBeVisible({ timeout: 5000 });
  });
});
