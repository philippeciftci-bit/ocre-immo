// M_PLAYWRIGHT_OCRE_PARCOURS — login utilisateur existant : entrée DIRECTE sans magic link
const { test, expect } = require('@playwright/test');
const { ensureExistingUser, cleanupTestUser } = require('./helpers/common');

test.describe('Login email existant → entrée directe app', () => {
  const email = 'e2e-existing-direct@example.com';
  test.beforeEach(() => { ensureExistingUser(email, 'ExistTest'); });
  test.afterEach(async () => { await cleanupTestUser(email); });

  test('Email reconnu via popup vitrine /oi-agent → redirect direct app.ocre.immo/oi-agent (pas accordéon)', async ({ page }) => {
    await page.goto('https://ocre.immo/oi-agent');
    await page.getByRole('button', { name: /Commencer/i }).first().click();
    await page.locator('input[type=email]').first().fill(email);
    await page.getByRole('button', { name: /Continuer/i }).click();
    // Doit rediriger SANS afficher accordéon (champ prénom invisible)
    await expect(page).toHaveURL(/oi-agent|app\.ocre\.immo/, { timeout: 8000 });
    // Accordéon ne doit PAS être déployé
    const prenom = page.locator('input#osp-prenom, input#prenom').first();
    await expect(prenom).not.toBeVisible({ timeout: 2000 }).catch(() => {});
  });

  test('Email reconnu via auth.ocre.immo/signup → redirect direct (pas Crée ton compte résiduel)', async ({ page }) => {
    await page.goto('https://auth.ocre.immo/signup');
    await page.locator('input[type=email]').first().fill(email);
    await page.getByRole('button', { name: /Continuer/i }).click();
    await expect(page).toHaveURL(/oi-agent|app\.ocre\.immo/, { timeout: 8000 });
  });
});
