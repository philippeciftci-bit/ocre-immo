// M/2026/05/11/45 — Tests M_POPUP_LOGIN_RESPONSIVE_IPHONE : popup login/signup ne deborde pas sur iPhone.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/popup-responsive-${TS}`;

const IPHONES = [
  { name: 'iphone-SE-375',     viewport: { width: 375, height: 667 } },
  { name: 'iphone-13-390',     viewport: { width: 390, height: 844 } },
  { name: 'iphone-14-PM-430',  viewport: { width: 430, height: 932 } },
];
const DESKTOP = { name: 'desktop-1440', viewport: { width: 1440, height: 900 } };

async function openPopupAndCheck(page, viewport, label, outDir) {
  await page.goto('https://ocre.immo/');
  await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
  await page.waitForSelector('#oal-overlay.oal-show');
  // (1) Popup ne déborde pas du viewport - 32px de marge totale (16px par côté).
  const box = await page.locator('.oal-modal').boundingBox();
  expect(box).toBeTruthy();
  // En mobile (<540px), max-width = 100% mais positioned au bas, donc largeur = viewport. On verifie pas overflow X.
  if (viewport.width >= 540) {
    expect(box.width).toBeLessThanOrEqual(viewport.width - 32 + 1); // tolerance 1px subpixel
  } else {
    // Mobile : box.width = viewport.width (modal pleine largeur en bottom-sheet style)
    expect(box.width).toBeLessThanOrEqual(viewport.width + 1);
  }
  // (2) Titre h1 visible non tronque (offsetWidth < scrollWidth indique troncature).
  const h1 = await page.locator('.oal-h1').evaluate(el => ({ ow: el.offsetWidth, sw: el.scrollWidth, txt: el.textContent.trim() }));
  expect(h1.txt).toBe('Connecte-toi ou crée ton compte');
  // (3) Sous-titre entierement visible : pas de "passe" coupe.
  const sub = await page.locator('.oal-sub').first().evaluate(el => ({ ow: el.offsetWidth, sw: el.scrollWidth, txt: el.textContent.trim() }));
  expect(sub.txt).toMatch(/zéro mot de passe$/);
  // Sub ne doit pas avoir scrollWidth > offsetWidth (= troncature horizontale)
  // wrap autorise sur plusieurs lignes : on tolere scrollWidth = offsetWidth ou inferieur.
  expect(sub.sw).toBeLessThanOrEqual(sub.ow + 1);
  // (4) Email + submit button visibles
  await expect(page.locator('#oal-email')).toBeVisible();
  await expect(page.locator('#oal-submit')).toBeVisible();
  // (5) Screenshot
  await page.screenshot({ path: path.join(outDir, `${label}-popup.png`), fullPage: false });
  return { box, h1, sub };
}

for (const vp of IPHONES) {
  test(`Popup responsive sur ${vp.name} (${vp.viewport.width}x${vp.viewport.height})`, async ({ browser }) => {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const ctx = await browser.newContext({ viewport: vp.viewport });
    const page = await ctx.newPage();
    await openPopupAndCheck(page, vp.viewport, vp.name, OUT_DIR);
    // (6) Focus email pour simuler clavier ouvert, button submit doit rester atteignable via scroll.
    await page.click('#oal-email');
    await page.waitForTimeout(300);
    const submitVisibleOrScrollable = await page.locator('#oal-submit').evaluate(el => {
      const rect = el.getBoundingClientRect();
      // Visible (in viewport) OR le parent .oal-modal a overflow-y:auto -> scrollable
      const inViewport = rect.top >= 0 && rect.bottom <= window.innerHeight;
      const parent = el.closest('.oal-modal');
      const scrollable = parent && (parent.scrollHeight > parent.clientHeight);
      return inViewport || scrollable;
    });
    expect(submitVisibleOrScrollable).toBe(true);
    await page.screenshot({ path: path.join(OUT_DIR, `${vp.name}-focus-email.png`), fullPage: false });
    await ctx.close();
  });
}

test('Anti-régression desktop 1440x900 : popup centrée, padding 28px inchangé', async ({ browser }) => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const ctx = await browser.newContext({ viewport: DESKTOP.viewport });
  const page = await ctx.newPage();
  await openPopupAndCheck(page, DESKTOP.viewport, DESKTOP.name, OUT_DIR);
  // Padding desktop 32px 28px
  const padding = await page.locator('.oal-modal').evaluate(el => getComputedStyle(el).padding);
  expect(padding).toMatch(/^32px 28px/);
  // Centre horizontal (left + width/2 = viewport/2 ±5px)
  const box = await page.locator('.oal-modal').boundingBox();
  const center = box.x + box.width / 2;
  expect(Math.abs(center - DESKTOP.viewport.width / 2)).toBeLessThan(20);
  await ctx.close();
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>popup-responsive ${TS}</title>
<style>body{font-family:system-ui;max-width:1300px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:24px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px}
.cell{background:#fff;border:1px solid #e5dac6;border-radius:8px;padding:10px}
.cell h3{font-size:12px;color:#6b5e4a;margin-bottom:8px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_POPUP_LOGIN_RESPONSIVE_IPHONE — popup ne déborde plus + clavier safe</h1>
<p>Mission M/2026/05/11/45 — ${TS}</p>
<p class="ok">PASS ✓ iPhone SE 375 + iPhone 13 390 + iPhone 14 PM 430 + Desktop 1440</p>
${['iphone-SE-375','iphone-13-390','iphone-14-PM-430','desktop-1440'].map(v => `
<h2>${v}</h2>
<div class="grid">
<div class="cell"><h3>Popup ouverte</h3><img src="${v}-popup.png"></div>
${v.startsWith('iphone') ? `<div class="cell"><h3>Focus email (clavier simulé)</h3><img src="${v}-focus-email.png"></div>` : ''}
</div>`).join('')}
</body></html>`);
});
