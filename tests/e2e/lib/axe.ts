import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, type TestInfo } from '@playwright/test';
import type { AxeResults, Result } from 'axe-core';
import { mkdirSync, writeFileSync } from 'node:fs';
import { KNOWN_ISSUES } from './known-issues';

/**
 * WCAG 2.2 AA. axe-core tags are cumulative per version, so 2.0 A/AA + 2.1
 * A/AA + the 2.2 AA additions (target-size etc.) together make up 2.2 AA.
 */
export const WCAG_22_AA_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/** Impacts that fail the gate. moderate/minor are reported, not fatal. */
const BLOCKING: ReadonlySet<string> = new Set(['serious', 'critical']);

export interface AxeOptions {
  /** Human label for the report file. */
  label: string;
  /** Expected <html lang>; asserted when given. */
  lang?: string;
}

function formatViolation(v: Result): string {
  const nodes = v.nodes
    .slice(0, 5)
    .map((n) => `      - ${n.target.join(' ')}\n        ${n.failureSummary?.replace(/\n/g, '\n        ') ?? ''}`)
    .join('\n');
  return `  [${v.impact}] ${v.id}: ${v.help}\n    ${v.helpUrl}\n${nodes}${v.nodes.length > 5 ? `\n      ... ${v.nodes.length - 5} more` : ''}`;
}

/**
 * Runs axe against the current page with WCAG 2.2 AA rules, attaches the full
 * JSON result to the test, and fails on serious/critical violations.
 *
 * Known, documented issues (lib/known-issues.ts <-> docs/ACCESSIBILITY-KNOWN-ISSUES.md)
 * are excluded by *selector* so the rest of the rule still applies elsewhere
 * on the page. Nothing is excluded silently: every entry carries the reason
 * and the tracking issue.
 */
export async function expectNoA11yViolations(page: Page, testInfo: TestInfo, opts: AxeOptions): Promise<AxeResults> {
  if (opts.lang) {
    await expect(page.locator('html')).toHaveAttribute('lang', opts.lang);
  }

  let builder = new AxeBuilder({ page }).withTags(WCAG_22_AA_TAGS);
  for (const issue of KNOWN_ISSUES) {
    if (issue.pages && !issue.pages.some((p) => new URL(page.url()).pathname.replace(/^\/da(?=\/|$)/, '') === p)) {
      continue;
    }
    for (const selector of issue.exclude) {
      builder = builder.exclude(selector);
    }
  }

  const results = await builder.analyze();

  const dir = 'axe-reports';
  mkdirSync(dir, { recursive: true });
  const file = `${dir}/${opts.label.replace(/[^a-z0-9-]+/gi, '-').toLowerCase()}.json`;
  writeFileSync(file, JSON.stringify(results, null, 2));
  await testInfo.attach(`axe-${opts.label}`, { path: file, contentType: 'application/json' });

  const blocking = results.violations.filter((v) => BLOCKING.has(v.impact ?? ''));
  const advisory = results.violations.filter((v) => !BLOCKING.has(v.impact ?? ''));

  if (advisory.length) {
    const text = advisory.map(formatViolation).join('\n');
    await testInfo.attach(`axe-${opts.label}-advisory`, { body: text, contentType: 'text/plain' });
    console.warn(`axe advisory (moderate/minor) on ${opts.label}:\n${text}`);
  }

  expect(
    blocking,
    `axe found ${blocking.length} serious/critical WCAG 2.2 AA violation(s) on ${opts.label} (${page.url()}):\n${blocking.map(formatViolation).join('\n')}\n\nFix the template/CSS, or document it in docs/ACCESSIBILITY-KNOWN-ISSUES.md and tests/e2e/lib/known-issues.ts.`,
  ).toEqual([]);

  return results;
}
