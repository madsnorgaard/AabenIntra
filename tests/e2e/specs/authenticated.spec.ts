import { test, expect } from '@playwright/test';
import { expectNoA11yViolations } from '../lib/axe';
import { firstChannelPath, firstStoryPath } from '../lib/pages';

/**
 * Every public-facing AabenIntra surface, as the logged-in demo user.
 * Static routes first; story node and channel page are discovered from the
 * seeded content.
 */
const STATIC_PAGES: Array<{ path: string; label: string; heading: RegExp }> = [
  { path: '/dashboard', label: 'dashboard', heading: /./ },
  { path: '/news', label: 'news', heading: /news/i },
  { path: '/directory', label: 'directory', heading: /people/i },
  { path: '/channels', label: 'channels', heading: /channels/i },
  { path: '/kudos', label: 'kudos', heading: /recognition/i },
  { path: '/activity', label: 'activity', heading: /activity/i },
];

test.describe('authenticated', () => {
  test('session is logged in', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page.locator('body')).toHaveClass(/user-logged-in/);
  });

  for (const p of STATIC_PAGES) {
    test(`${p.path} has no WCAG 2.2 AA violations`, async ({ page }, testInfo) => {
      await page.goto(p.path);
      await expect(page.locator('h1').first()).toHaveText(p.heading);
      await expectNoA11yViolations(page, testInfo, { label: `auth-${p.label}`, lang: 'en' });
    });
  }

  test('a story node has no WCAG 2.2 AA violations', async ({ page }, testInfo) => {
    const path = await firstStoryPath(page);
    await page.goto(path);
    await expect(page.locator('article.ai-article h1')).toBeVisible();
    await expectNoA11yViolations(page, testInfo, { label: 'auth-story-node', lang: 'en' });
  });

  test('a channel page has no WCAG 2.2 AA violations', async ({ page }, testInfo) => {
    const path = await firstChannelPath(page);
    await page.goto(path);
    await expect(page.locator('.ai-channel h1')).toBeVisible();
    await expectNoA11yViolations(page, testInfo, { label: 'auth-channel', lang: 'en' });
  });
});
