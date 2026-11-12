// Anonymous pages. No session header, so the login form itself is measured.
const { config } = require('../common.cjs');

module.exports = config({ urls: ['/user/login'] });
