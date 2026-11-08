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

/**
 * The channel page lives at /channels/{tid} (aabenintra_channels.channel). The
 * card title on /channels links to the term's canonical URL, so take the id
 * from the follow button instead.
 */
export async function firstChannelPath(page: Page): Promise<string> {
  await page.goto('/channels', { waitUntil: 'domcontentloaded' });
  const tid = await page.locator('.ai-channel-card button.ai-follow[data-topic]').first().getAttribute('data-topic');
  if (!tid) {
    throw new Error('No channel found on /channels - did the demo seed run?');
  }
  return `/channels/${tid}`;
}

/**
 * A taxonomy term landing page (/taxonomy/term/{tid}), as linked from the
 * "Browse" department navigation in the left drawer.
 */
export async function firstTermPath(page: Page): Promise<string> {
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  const href = await page.locator('nav.ai-nav__terms a.ai-nav__term[href]').first().getAttribute('href');
  if (!href) {
    throw new Error('No department term link in the navigation drawer - did the demo seed run?');
  }
  return new URL(href, page.url()).pathname;
}
