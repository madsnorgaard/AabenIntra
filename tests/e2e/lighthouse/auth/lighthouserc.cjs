// Authenticated pages. The session cookie is written by tests/e2e/global-setup.ts
// (Playwright logs the CI user in) and sent on every request as a Cookie header.
const fs = require('node:fs');
const path = require('node:path');
const { config } = require('../common.cjs');

const cookieFile = path.resolve(__dirname, '..', '..', '.auth', 'cookie.txt');
if (!fs.existsSync(cookieFile)) {
  throw new Error(`${cookieFile} missing - run the Playwright suite first (its global setup writes the session cookie).`);
}
const cookie = fs.readFileSync(cookieFile, 'utf8').trim();

module.exports = config({
  urls: ['/dashboard', '/news'],
  extraHeaders: { Cookie: cookie },
});
