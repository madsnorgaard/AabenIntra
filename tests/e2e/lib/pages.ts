import type { Page } from '@playwright/test';

/**
 * Discovers content URLs from the seeded demo site instead of hard-coding ids
 * (node and term ids depend on seed order).
 */
export async function firstStoryPath(page: Page): Promise<string> {
  await page.goto('/news', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('.ai-card[href]').first().getAttribute('href');
  if (!href) {
    throw new Error('No story card found on /news - did the demo seed run?');
  }
  return new URL(href, page.url()).pathname;
}

export async function firstChannelPath(page: Page): Promise<string> {
  await page.goto('/channels', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('a.ai-channel-card__title[href]').first().getAttribute('href');
  if (!href) {
    throw new Error('No channel found on /channels - did the demo seed run?');
  }
  return new URL(href, page.url()).pathname;
}
