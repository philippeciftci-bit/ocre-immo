// M_PLAYWRIGHT_OCRE_PARCOURS — config Playwright
const { defineConfig, devices } = require('@playwright/test');
module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: false,
  retries: 1,
  workers: 2,
  reporter: [['html', { outputFolder: 'reports/html', open: 'never' }], ['line']],
  use: {
    baseURL: 'https://ocre.immo',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'iphone13', use: { ...devices['iPhone 13'] } },
  ],
});
