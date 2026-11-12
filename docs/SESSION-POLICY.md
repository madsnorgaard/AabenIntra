# Session and device policy

Applies to every AabenIntra tenant container. The settings live in
`services.prod.yml` (bind-mounted as `web/sites/default/services.yml`) and
take effect after `drush cr`.

## Web sessions

| Setting | Value | Why |
|---|---|---|
| Idle lifetime (`gc_maxlifetime`) | 28800 s (8 h) | One working day. An intranet session that has been idle for 8 h is removed server-side and the next request requires a new login. |
| Cookie lifetime (`cookie_lifetime`) | 0 (session cookie) | The cookie disappears when the browser is closed. On shared or kiosk PCs in a kommune the next person does not inherit a login. |
| `cookie_samesite` | Lax | Blocks cross-site POSTs carrying the cookie (CSRF defence in depth) while still allowing normal top-level navigation from mail links. |
| `cookie_secure` | true | Drupal derives this from the request scheme; behind Traefik the request is HTTPS via `X-Forwarded-Proto` because `settings.php` enables `reverse_proxy`. The key is kept in the file to document the intent. |
| `cookie_httponly` | true (PHP default) | Not readable from JavaScript. |

Consequences:

- "Remember me" is not offered. Drupal core has no such option and none is
  added; a persistent login would defeat the two rules above.
- Session IDs are regenerated on login (core) and all sessions of a user are
  destroyed when the account is blocked or its password changes (core). Both
  are recorded in the audit log (`account_blocked`, `password_changed`).
- Only one session per user is *not* enforced. A user may be logged in on a
  PC and a phone at the same time. If a tenant requires single-session, the
  contrib module `session_limit` is the recommended route; it needs no
  changes here.

### Changing the policy

1. Edit `session.storage.options` in `services.prod.yml` in the repo (PR).
2. Deploy; the file is bind-mounted, then run `drush cr` (the deploy script
   does this).
3. Existing sessions keep their old cookie lifetime until they expire or the
   user logs in again.

Do not set `cookie_lifetime` above 0 without a documented decision from the
tenant's information-security function. Do not lower `gc_maxlifetime` below
the longest expected editing session (long articles are drafted in the
browser) unless autosave is enabled.

## API tokens

There are no API tokens today. AabenIntra is server-rendered; JSON:API,
REST and OAuth modules are not enabled, so the only credential is the session
cookie above.

Intended policy for when an integration (mobile client, BI export, a future
headless frontend) needs JSON:API access:

- Tokens are issued per integration, not per person, and are tied to a
  dedicated service account with the minimum role.
- Tokens rotate: short-lived access tokens (<= 1 h) with a refresh token that
  is itself rotated on every use and expires after 30 days of inactivity.
  `drupal/simple_oauth` implements this model; the choice is made when the
  need arises, not pre-emptively.
- Every issue and revoke is written to the audit log through
  `AuditRecorder::recordToken()` (events `token_issued`, `token_revoked`),
  storing only a label or truncated fingerprint, never the token.
- Tokens are never committed to the repo, mailed or pasted in chat; they are
  handed over through the tenant's secret store.

## Passwords and MFA

Password rules today are Drupal core's: no complexity policy, no expiry,
no breached-password check. Recommended follow-up (separate issue, not part
of #120): add `drupal/password_policy` with a length-based rule (minimum 12
characters, no forced rotation, per NIST SP 800-63B and the Danish CFCS
guidance) and a reuse constraint. The long-term direction is SSO (Entra ID
#79, MitID Erhverv #81) so that passwords and MFA are governed by the tenant's
identity provider rather than by Drupal.

Brute-force protection is core flood control (5 failed attempts per account
per 6 h, 50 per IP per hour). Blocked attempts are logged as
`login_blocked_flood` and can trigger the optional alert, see
[AUDIT-LOG.md](AUDIT-LOG.md).
