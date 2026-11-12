/**
 * Shared Lighthouse CI settings for AabenIntra (issue #123).
 *
 * Targets from the issue: Performance >= 0.85, Accessibility >= 0.95,
 * Best Practices >= 0.90, LCP < 2500 ms, CLS < 0.1.
 *
 * CI measures against the PHP built-in server on a shared runner, so each
 * category is asserted on the median of three runs. THRESHOLDS below are the
 * currently enforced values; raise them towards TARGETS as the theme improves
 * (ratchet plan in docs/ACCESSIBILITY.md). A threshold must never be lowered
 * without a note in that document.
 */
const path = require('node:path');

const BASE_URL = process.env.AI_BASE_URL || 'http://127.0.0.1:8080';

const TARGETS = {
  performance: 0.85,
  accessibility: 0.95,
  'best-practices': 0.9,
  lcpMs: 2500,
  cls: 0.1,
};

const THRESHOLDS = {
  performance: 0.85,
  accessibility: 0.95,
  'best-practices': 0.9,
  lcpMs: 2500,
  cls: 0.1,
};

const median = { aggregationMethod: 'median-run' };

function config({ urls, extraHeaders }) {
  return {
    ci: {
      collect: {
        url: urls.map((u) => BASE_URL + u),
        numberOfRuns: 3,
        settings: {
          // Desktop profile: the intranet is used at a desk first; mobile is
          // covered by the PWA epic (#101). Simulated throttling keeps the
          // runner's own network out of the measurement.
          preset: 'desktop',
          onlyCategories: ['performance', 'accessibility', 'best-practices'],
          skipAudits: ['uses-http2', 'is-on-https', 'redirects-http'],
          ...(extraHeaders ? { extraHeaders } : {}),
        },
      },
      assert: {
        assertions: {
          'categories:performance': ['error', { minScore: THRESHOLDS.performance, ...median }],
          'categories:accessibility': ['error', { minScore: THRESHOLDS.accessibility, ...median }],
          'categories:best-practices': ['error', { minScore: THRESHOLDS['best-practices'], ...median }],
          'largest-contentful-paint': ['error', { maxNumericValue: THRESHOLDS.lcpMs, ...median }],
          'cumulative-layout-shift': ['error', { maxNumericValue: THRESHOLDS.cls, ...median }],
        },
      },
      upload: {
        target: 'filesystem',
        outputDir: path.join(process.cwd(), '.lighthouseci', 'reports'),
        reportFilenamePattern: '%%PATHNAME%%-%%DATETIME%%.report.%%EXTENSION%%',
      },
    },
  };
}

module.exports = { config, TARGETS, THRESHOLDS, BASE_URL };
