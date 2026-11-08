/**
 * Accepted, documented accessibility exclusions.
 *
 * Every entry here MUST have a matching row in docs/ACCESSIBILITY-KNOWN-ISSUES.md
 * and a tracking GitHub issue. Exclusions are by CSS selector (not by rule) so
 * the rule keeps running on the rest of the page. Remove the entry when fixed.
 *
 * Keep this list empty whenever possible - fix the theme instead.
 */
export interface KnownIssue {
  /** axe rule id, for the reader (the exclusion itself is by selector). */
  rule: string;
  /** CSS selectors to exclude from analysis. */
  exclude: string[];
  /** Restrict to these paths (without the /da prefix); omit = all pages. */
  pages?: string[];
  /** Why it is accepted for now, and the issue tracking the fix. */
  reason: string;
  issue: string;
}

export const KNOWN_ISSUES: KnownIssue[] = [];
