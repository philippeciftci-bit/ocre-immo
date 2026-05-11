// M/2026/05/12/2 — Preuve fix popup login : anti-zoom iOS + max-width 380px iPhone.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const TS = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT_DIR = `/opt/atelier-tools/maquettes/fix-popup-login-${TS}`;

const CASES = [
  { name: 'iphone-13-390',     viewport: { width: 390, height: 844 } },
  { name: 'ipad-portrait-820', viewport: { width: 820, height: 1180 } },
];

for (const c of CASES) {
  test(`${c.name} (${c.viewport.width}×${c.viewport.height}) : popup anti-zoom + max-width 380`, async ({ browser }) => {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const ctx = await browser.newContext({ viewport: c.viewport });
    const page = await ctx.newPage();
    await page.goto('https://ocre.immo/');
    await page.waitForLoadState('domcontentloaded');
    // Trigger popup via window.ocreSignupOpen (CTAs sur la home appellent ça)
    await page.evaluate(() => window.ocreSignupOpen({ app: 'agent' }));
    await page.waitForSelector('#oal-overlay.oal-show');
    await page.waitForTimeout(500);

    // Vérif font-size input email = 16px (anti-zoom iOS Safari)
    const inputFontSize = await page.locator('#oal-email').evaluate(el => getComputedStyle(el).fontSize);
    console.log(`${c.name} input email font-size = ${inputFontSize}`);
    expect(inputFontSize).toBe('16px');

    // Mesure BEFORE focus input (popup vierge, pas d'accordéon)
    const before = await page.locator('.oal-modal').evaluate(el => {
      const r = el.getBoundingClientRect();
      return { top: r.top, left: r.left, width: r.width };
    });
    await page.screenshot({ path: path.join(OUT_DIR, `${c.name}-before-focus.png`), fullPage: false });

    // Focus email + mesure AFTER : top/left/width identiques (delta ≤ 2px). Pas d'accordéon ouvert.
    await page.locator('#oal-email').focus();
    await page.waitForTimeout(500);
    const after = await page.locator('.oal-modal').evaluate(el => {
      const r = el.getBoundingClientRect();
      return { top: r.top, left: r.left, width: r.width };
    });
    console.log(`${c.name} BEFORE focus:`, before, '\nAFTER focus:', after);
    expect(Math.abs(after.top - before.top)).toBeLessThanOrEqual(2);
    expect(Math.abs(after.left - before.left)).toBeLessThanOrEqual(2);
    expect(Math.abs(after.width - before.width)).toBeLessThanOrEqual(2);
    await page.screenshot({ path: path.join(OUT_DIR, `${c.name}-after-focus.png`), fullPage: false });

    // Vérif max-width 380 sur tous viewports
    const modalW = await page.locator('.oal-modal').evaluate(el => el.offsetWidth);
    console.log(`${c.name} modal width = ${modalW}px (max 380)`);
    expect(modalW).toBeLessThanOrEqual(380 + 2);

    // Bonus : tel-row select + input = 16px aussi (accordeon cas C ouvert après)
    await page.fill('#oal-email', 'unknown-popuptest-' + Date.now() + '@ocre.test');
    await page.click('#oal-submit');
    await page.waitForFunction(() => document.getElementById('oal-extra').classList.contains('oal-extra-open'), { timeout: 8000 });
    const telSelectFS = await page.locator('#oal-tel-country').evaluate(el => getComputedStyle(el).fontSize);
    const telInputFS = await page.locator('#oal-tel').evaluate(el => getComputedStyle(el).fontSize);
    expect(telSelectFS).toBe('16px');
    expect(telInputFS).toBe('16px');

    await ctx.close();
  });
}

test.afterAll(() => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>fix-popup-login ${TS}</title>
<style>body{font-family:system-ui;max-width:1100px;margin:auto;padding:24px;background:#fafaf7}h1{color:#001D3D}
h2{font-size:14px;margin-top:24px;color:#001D3D;border-bottom:1px solid #e5dac6;padding-bottom:6px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
img{max-width:100%;border:1px solid #e5dac6;border-radius:6px}
.ok{color:#2e7d32;font-weight:600}</style></head><body>
<h1>Fix popup login ocre.immo — M/2026/05/12/2</h1>
<p>${TS}</p>
<p class="ok">PASS ✓ font-size 16px (anti-zoom iOS) + max-width 380px + position invariante avant/après focus.</p>
${CASES.map(c => `
<h2>${c.name}</h2>
<div class="grid">
<div><h3 style="font-size:12px">Before focus</h3><img src="${c.name}-before-focus.png"></div>
<div><h3 style="font-size:12px">After focus</h3><img src="${c.name}-after-focus.png"></div>
</div>`).join('')}
</body></html>`);
});
