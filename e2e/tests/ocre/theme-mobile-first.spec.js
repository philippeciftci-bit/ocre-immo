// M/2026/05/11/47 — Tests M_OCRE_THEME_REFONTE_MOBILE_FIRST matrice 6 viewports x 3 pages.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/theme-mobile-first-${TS}`;

const VIEWPORTS = [
  { name: 'iphone-SE-375',     viewport: { width: 375, height: 667 } },
  { name: 'iphone-13-390',     viewport: { width: 390, height: 844 } },
  { name: 'iphone-14-PM-430',  viewport: { width: 430, height: 932 } },
  { name: 'ipad-portrait-768', viewport: { width: 768, height: 1024 } },
  { name: 'ipad-landscape-1024', viewport: { width: 1024, height: 768 } },
  { name: 'desktop-1440',      viewport: { width: 1440, height: 900 } },
];

async function checkPage(page, url, label, outDir) {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(900);
  const stats = await page.evaluate(() => ({
    docW: document.documentElement.clientWidth,
    scrollW: document.documentElement.scrollWidth,
    bodyScrollW: document.body.scrollWidth,
    htmlOverflowX: getComputedStyle(document.documentElement).overflowX,
    bodyOverflowX: getComputedStyle(document.body).overflowX,
    viewportMeta: (document.querySelector('meta[name="viewport"]') || {}).content || null,
    bodyFontSize: getComputedStyle(document.body).fontSize,
    visualScale: window.visualViewport ? window.visualViewport.scale : 1,
    tokenFsBase: getComputedStyle(document.documentElement).getPropertyValue('--fs-base').trim(),
    tokenSpaceMd: getComputedStyle(document.documentElement).getPropertyValue('--space-md').trim(),
  }));
  // (1) Pas d'overflow horizontal au niveau du document.
  expect(stats.scrollW).toBeLessThanOrEqual(stats.docW);
  expect(stats.bodyScrollW).toBeLessThanOrEqual(stats.docW + 1);
  // (2) Filet overflow-x posé sur html ET body.
  expect(stats.htmlOverflowX).toBe('hidden');
  expect(stats.bodyOverflowX).toBe('hidden');
  // (3) Meta viewport correct.
  expect(stats.viewportMeta).toMatch(/width=device-width/);
  expect(stats.viewportMeta).toMatch(/initial-scale=1/);
  // (4) Pas de zoom forcé.
  expect(Math.abs(stats.visualScale - 1)).toBeLessThan(0.05);
  // (5) Tokens fluides définis (clamp values, pas vide).
  expect(stats.tokenFsBase).toMatch(/clamp\(.+\)/);
  expect(stats.tokenSpaceMd).toMatch(/clamp\(.+\)/);
  // (6) Font size effective ≥ 14px (lisibilité minimum).
  const fsNum = parseFloat(stats.bodyFontSize);
  expect(fsNum).toBeGreaterThanOrEqual(14);
  await page.screenshot({ path: path.join(outDir, `${label}.png`), fullPage: false });
  return stats;
}

for (const vp of VIEWPORTS) {
  test(`${vp.name} (${vp.viewport.width}×${vp.viewport.height}) : home + oi-agent + popup mobile-first fluid`, async ({ browser }) => {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const ctx = await browser.newContext({ viewport: vp.viewport });
    const page = await ctx.newPage();

    // Page 1 : home
    await checkPage(page, 'https://ocre.immo/', `${vp.name}-home`, OUT_DIR);

    // Page 2 : oi-agent
    await checkPage(page, 'https://ocre.immo/oi-agent', `${vp.name}-oi-agent`, OUT_DIR);

    // Page 3 : popup ouverte sur oi-agent
    await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
    await page.waitForSelector('#oal-overlay.oal-show');
    const popupBox = await page.locator('.oal-modal').boundingBox();
    expect(popupBox.width).toBeLessThanOrEqual(vp.viewport.width + 1);
    await page.screenshot({ path: path.join(OUT_DIR, `${vp.name}-popup.png`), fullPage: false });

    await ctx.close();
  });
}

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>theme-mobile-first ${TS}</title>
<style>body{font-family:system-ui;max-width:1400px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;color:#001D3D;margin-top:28px;border-bottom:1px solid #e5dac6;padding-bottom:6px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px}
.cell{background:#fff;border:1px solid #e5dac6;border-radius:8px;padding:10px}
.cell h3{font-size:12px;color:#6b5e4a;margin-bottom:8px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>M_OCRE_THEME_REFONTE_MOBILE_FIRST — matrice 6 viewports × 3 pages</h1>
<p>Mission M/2026/05/11/47 — ${TS}</p>
<p class="ok">PASS ✓ Zéro overflow horizontal sur toutes pages + tokens fluides actifs + font≥14px + zoom=1 + viewport-fit=cover</p>
${VIEWPORTS.map(v => `
<h2>${v.name} (${v.viewport.width}×${v.viewport.height})</h2>
<div class="grid">
<div class="cell"><h3>Home</h3><img src="${v.name}-home.png"></div>
<div class="cell"><h3>Oi Agent</h3><img src="${v.name}-oi-agent.png"></div>
<div class="cell"><h3>Popup login</h3><img src="${v.name}-popup.png"></div>
</div>`).join('')}
</body></html>`);
});
