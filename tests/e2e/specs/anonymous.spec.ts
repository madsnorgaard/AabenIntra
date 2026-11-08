import { test, expect } from '@playwright/test';
import { expectNoA11yViolations } from '../lib/axe';

test.describe('anonymous', () => {
  test('/user/login has no WCAG 2.2 AA violations', async ({ page }, testInfo) => {
    await page.goto('/user/login');
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expectNoA11yViolations(page, testInfo, { label: 'anon-user-login', lang: 'en' });
  });
});
