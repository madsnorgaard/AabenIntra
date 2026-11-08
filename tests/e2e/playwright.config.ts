import { defineConfig, devices } from '@playwright/test';

/**
 * Accessibility gate for AabenIntra.
 *
 * Expects a running site (see scripts/install_ci.sh), by default on
 * http://127.0.0.1:8080. The `global-setup` logs the CI test user in once and
 * stores the session for the authenticated specs and for Lighthouse CI.
 */
export const BASE_URL = process.env.AI_BASE_URL ?? 'http://127.0.0.1:8080';

export default defineConfig({
  testDir: './specs',
  globalSetup: './global-setup.ts',
  fullyParallel: false,
  // The PHP built-in server is single-process per worker; keep load modest so
  // timings stay predictable and Lighthouse (run afterwards) is not skewed.
  workers: 2,
  retries: process.env.CI ? 1 : 0,
  forbidOnly: !!process.env.CI,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI
    ? [['list'], ['html', { open: 'never' }], ['github']]
    : [['list'], ['html', { open: 'never' }]],
  outputDir: 'test-results',
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'en-GB',
    ...devices['Desktop Chrome'],
  },
  projects: [
    {
      name: 'anonymous',
      testMatch: /anonymous\.spec\.ts/,
    },
    {
      name: 'authenticated',
      testMatch: /(authenticated|danish)\.spec\.ts/,
      use: { storageState: '.auth/user.json' },
    },
  ],
});
