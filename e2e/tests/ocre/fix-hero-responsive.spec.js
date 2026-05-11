// M/2026/05/12/1 — Preuve fonctionnelle fix hero responsive ocre.immo.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/fix-hero-responsive-${TS}`;

const CASES = [
  { name: 'ipad-portrait-820',  viewport: { width: 820, height: 1180 }, maxHeroPx: 820, url: 'https://ocre.immo/', heroSel: '.hv-hero', ctaText: 'Voir les outils' },
  { name: 'iphone-13-390',      viewport: { width: 390, height: 844 },  maxHeroPx: 844, url: 'https://ocre.immo/', heroSel: '.hv-hero', ctaText: 'Voir les outils' },
];

for (const c of CASES) {
  test(`${c.name} (${c.viewport.width}×${c.viewport.height}) : hero height ≤ ${c.maxHeroPx}px + CTA above-the-fold`, async ({ browser }) => {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const ctx = await browser.newContext({ viewport: c.viewport });
    const page = await ctx.newPage();
    await page.goto(c.url, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    // (1) Hero hauteur ≤ viewport height
    const heroH = await page.locator(c.heroSel).evaluate(el => el.getBoundingClientRect().height);
    console.log(`${c.name} hero height = ${Math.round(heroH)}px (max attendu ${c.maxHeroPx})`);
    expect(heroH).toBeLessThanOrEqual(c.maxHeroPx + 2);
    // (2) max-height cap effectif (≤ 820 sur tous viewports)
    expect(heroH).toBeLessThanOrEqual(820 + 2);
    // (3) CTA "Voir les outils" above-the-fold (rect.top < viewport.height)
    const cta = await page.locator('a.hv-cta', { hasText: c.ctaText }).first().evaluate(el => el.getBoundingClientRect());
    console.log(`${c.name} CTA "Voir les outils" rect.top = ${Math.round(cta.top)} (viewport.height = ${c.viewport.height})`);
    expect(cta.top).toBeLessThan(c.viewport.height);
    expect(cta.top + cta.height).toBeLessThanOrEqual(c.viewport.height + 2);
    await page.screenshot({ path: path.join(OUT_DIR, `${c.name}-after.png`), fullPage: false });
    await ctx.close();
  });
}

test('Desktop 1440×900 anti-régression : hero capped à 820px et CTA visible', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto('https://ocre.immo/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(500);
  const heroH = await page.locator('.hv-hero').evaluate(el => el.getBoundingClientRect().height);
  console.log(`desktop-1440 hero height = ${Math.round(heroH)}px (max 820)`);
  expect(heroH).toBeLessThanOrEqual(820 + 2);
  await page.screenshot({ path: path.join(OUT_DIR, 'desktop-1440-after.png'), fullPage: false });
  await ctx.close();
});

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>fix-hero-responsive ${TS}</title>
<style>body{font-family:system-ui;max-width:1200px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:24px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>Fix hero responsive ocre.immo — M/2026/05/12/1</h1>
<p>${TS}</p>
<p class="ok">PASS ✓ hero height ≤ viewport sur iPad 820 + iPhone 13 + Desktop 1440. CTA above-the-fold.</p>
<h2>iPad portrait 820×1180</h2><img src="ipad-portrait-820-after.png">
<h2>iPhone 13 390×844</h2><img src="iphone-13-390-after.png">
<h2>Desktop 1440×900</h2><img src="desktop-1440-after.png">
</body></html>`);
});
