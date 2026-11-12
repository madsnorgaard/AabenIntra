import { chromium, type FullConfig } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { BASE_URL } from './playwright.config';

/**
 * Logs the CI test user in through the normal /user/login form and persists
 * the session twice:
 *  - .auth/user.json   Playwright storageState for the authenticated project.
 *  - .auth/cookie.txt  The Drupal session cookie as a `Cookie:` header value,
 *                      read by lighthouse/auth/lighthouserc.cjs so Lighthouse
 *                      can audit authenticated pages.
 *
 * Credentials come from the same env vars scripts/install_ci.sh honours.
 */
export default async function globalSetup(_config: FullConfig): Promise<void> {
  const name = process.env.AI_CI_USER_NAME ?? 'demo.anna';
  const pass = process.env.AI_CI_USER_PASS ?? 'ci-tester-pass';

  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL: BASE_URL });
  const page = await context.newPage();

  await page.goto('/user/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="name"]').fill(name);
  await page.locator('input[name="pass"]').fill(pass);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/user/login'), { timeout: 30_000 }),
    page.locator('form.user-login-form [type="submit"], #user-login-form [type="submit"]').first().click(),
  ]);

  const cookies = await context.cookies(BASE_URL);
  const session = cookies.filter((c) => /^S?SESS[0-9a-f]+$/i.test(c.name));
  if (session.length === 0) {
    await browser.close();
    throw new Error(`Login as ${name} did not yield a Drupal session cookie - check AI_CI_USER_NAME/AI_CI_USER_PASS and that install_ci.sh ran.`);
  }

  mkdirSync('.auth', { recursive: true });
  await context.storageState({ path: '.auth/user.json' });
  writeFileSync('.auth/cookie.txt', session.map((c) => `${c.name}=${c.value}`).join('; ') + '\n');

  await browser.close();
}
