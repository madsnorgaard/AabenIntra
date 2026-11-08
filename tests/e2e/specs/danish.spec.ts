import { test, expect } from '@playwright/test';
import { expectNoA11yViolations } from '../lib/axe';

/**
 * Danish interface pass. URL-first negotiation: /da/... serves the Danish UI
 * (aabenintra_i18n recipe). Asserts <html lang="da"> so screen readers switch
 * pronunciation, then runs the same WCAG 2.2 AA rule set.
 */
test.describe('danish', () => {
  test('/da/dashboard has no WCAG 2.2 AA violations', async ({ page }, testInfo) => {
    await page.goto('/da/dashboard');
    await expect(page.locator('body')).toHaveClass(/user-logged-in/);
    await expect(page.locator('.ai-langswitch__link.is-active')).toHaveText('DA');
    await expectNoA11yViolations(page, testInfo, { label: 'da-dashboard', lang: 'da' });
  });

  test('/da/news has no WCAG 2.2 AA violations', async ({ page }, testInfo) => {
    await page.goto('/da/news');
    await expect(page.locator('h1').first()).toBeVisible();
    await expectNoA11yViolations(page, testInfo, { label: 'da-news', lang: 'da' });
  });
});
