// M_PLAYWRIGHT_OCRE_PARCOURS — cas erreurs validation popup signup
const { test, expect } = require('@playwright/test');

test.describe('Erreurs validation signup vitrine', () => {
  test('Email format invalide → message inline rouge au blur', async ({ page }) => {
    await page.goto('https://ocre.immo/oi-agent');
    await page.getByRole('button', { name: /Commencer/i }).first().click();
    const emailInput = page.locator('input[type=email]').first();
    await emailInput.fill('pas-un-email');
    await emailInput.blur();
    await expect(page.getByText(/email invalide/i).first()).toBeVisible({ timeout: 3000 });
  });

  test('Bouton Recevoir mon lien désactivé tant que CGU pas cochée', async ({ page }) => {
    await page.goto('https://ocre.immo/oi-agent');
    await page.getByRole('button', { name: /Commencer/i }).first().click();
    await page.locator('input[type=email]').first().fill('test-cgu@example.com');
    await page.getByRole('button', { name: /Continuer/i }).click();
    await page.locator('input#osp-prenom, input#prenom').first().fill('Test');
    await page.locator('input#osp-nom, input#nom').first().fill('CGU');
    await page.locator('input#osp-phone, input#phone').first().fill('612345678');
    // CGU pas cochée → bouton disabled
    const btn = page.getByRole('button', { name: /Recevoir.*lien/i });
    const cls = await btn.getAttribute('class');
    expect(cls).toMatch(/disabled|btn-disabled/);
  });

  test('Token magic-link inexistant → page erreur claire (pas crash 500)', async ({ page }) => {
    const fakeToken = '0000000000000000000000000000000000000000000000000000000000000000';
    const resp = await page.goto(`https://auth.ocre.immo/api/magic-link/validate.php?token=${fakeToken}&app=agent`);
    // Doit rediriger vers /error.html, pas 500
    expect(resp.status()).toBeLessThan(500);
    expect(page.url()).toMatch(/error|invalid|token/i);
  });

  test('Téléphone invalide pour pays FR (3 chiffres) → champ pas vert', async ({ page }) => {
    await page.goto('https://ocre.immo/oi-agent');
    await page.getByRole('button', { name: /Commencer/i }).first().click();
    await page.locator('input[type=email]').first().fill('test-tel@example.com');
    await page.getByRole('button', { name: /Continuer/i }).click();
    const phone = page.locator('input#osp-phone, input#phone').first();
    await phone.fill('612');
    const cls = await phone.getAttribute('class');
    expect(cls || '').not.toMatch(/is-phone-valid/);
  });
});
