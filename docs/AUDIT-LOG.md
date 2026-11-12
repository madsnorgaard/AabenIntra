# Authentication audit log (`aabenintra_audit`)

Records who logged in, from where, failed attempts, password resets and
account changes. Append-only table `aabenintra_audit_event`, reviewed at
**Reports > Authentication audit log** (`/admin/reports/auth-audit`), settings
at **Configuration > System > Authentication audit log**
(`/admin/config/system/auth-audit`).

Permissions: `view authentication audit log` (read + CSV) and
`administer authentication audit log` (settings). Both are flagged
"restricted access": the log contains personal data.

## Event catalogue

| Event | Severity | When | `uid` | Details keys |
|---|---|---|---|---|
| `login_success` | info | Local login completed (`hook_user_login`) | the user | `method`: `password` or `password_reset` |
| `login_failed` | notice | Login form rejected | account if the name exists, else 0 | `reason`: `invalid_credentials`, `unknown_user`, `account_blocked`; `username_attempted` set |
| `login_blocked_flood` | warning | Core flood control refused the attempt | as above | `scope`: `ip` or `user` |
| `logout` | info | `hook_user_logout` | the user | |
| `password_reset_requested` | notice | `/user/password` submitted | resolved account or 0 | `resolved`: bool; `username_attempted` = typed value |
| `password_reset_link_opened` | notice | One-time link (`user.reset`) opened | uid from the URL | |
| `password_reset_link_used` | notice | Link accepted and user logged in | the user | followed by `login_success` with `method=password_reset` |
| `password_reset_link_rejected` | warning | Link expired, already used or forged | uid from the URL | `reason` |
| `password_changed` | notice | Password hash differs after save | the user | `actor_uid`, `other_sessions_terminated: true` |
| `mail_changed` | notice | E-mail differs after save | the user | `actor_uid`, `from`, `to` |
| `roles_changed` | warning | Roles differ after save | the user | `actor_uid`, `added[]`, `removed[]` |
| `account_blocked` | warning | Status 1 -> 0 | the user | `actor_uid`, `sessions_terminated: true` |
| `account_unblocked` | notice | Status 0 -> 1 | the user | `actor_uid` |
| `user_created` | notice | `hook_user_insert` | new user | `actor_uid`, `roles[]`, `status` |
| `user_deleted` | warning | `hook_user_delete` | deleted uid | `actor_uid`, `name` |
| `sso_login` | info | `AuditRecorder::recordExternalLogin()` | the user | `provider`, `method: sso`, provider-specific keys |
| `sso_login_failed` | notice | same, `$success = FALSE` | 0 | `provider`; `username_attempted` = external identity |
| `token_issued` / `token_revoked` | notice | `AuditRecorder::recordToken()` (reserved, no tokens exist yet) | the user | `label` |
| `alert_sent` | warning | A suspicious-login rule fired | subject uid | `rule`, `trigger_event`, `trigger_id`, `ip`, `mail_sent` |

`actor_uid` is the user performing the change (0 for drush/cron). `uid` is
always the *subject* of the event.

Not covered (by design or core limitation): REST login (`user.login.http`,
not enabled), session termination by an administrator other than through
block/password change (core offers no UI for it; a decorator on
`session_manager` would be the way if ever needed), read access to content.

## Row fields

| Column | Content |
|---|---|
| `id` | serial |
| `event` | catalogue name above (max 64) |
| `uid` | subject user, 0 = unknown |
| `username_attempted` | what was typed, for failed logins/reset requests |
| `ip` | client IP, reverse-proxy aware (`Request::getClientIp()` with `reverse_proxy` from `settings.php`); truncated after `anonymise_ip_after_days` |
| `ip_anonymised` | 1 once truncated |
| `user_agent` | `User-Agent`, max 512 chars |
| `session_hash` | sha256 of the PHP session id; the id itself is never stored, so a row cannot be used to hijack a session but two rows from the same session can be correlated |
| `severity` | RFC 5424 level (Drupal `RfcLogLevel`): 6 info, 5 notice, 4 warning |
| `details` | JSON object. Keys that look like secrets (`pass`, `token`, `hash`, `secret`, `cookie`, ...) are dropped before writing |
| `created` | unix timestamp |

Indexes: `(uid, created)`, `(event, created)`, `(ip, created)`.

## Retention and data minimisation

Config `aabenintra_audit.settings`, changed in the settings form or with
`drush config:set aabenintra_audit.settings retention_days 365`:

- `retention_days` (default 365): rows older than this are deleted on cron.
  Twelve months matches the usual kommune requirement to be able to
  investigate an incident discovered late; adjust to the tenant's
  retention schedule. 0 disables purging (not recommended).
- `anonymise_ip_after_days` (default 90): the IP is truncated on cron (IPv4
  last octet zeroed, IPv6 last 64 bits zeroed), the row itself stays. 0
  disables.

Both steps run in `hook_cron()`; anonymisation handles up to 500 distinct
addresses per run. The code is `src/Retention.php`; `src/IpAnonymiser.php`
is pure and unit-tested.

The log is the processing basis for security monitoring (GDPR art. 6(1)(f)
/ art. 32). It must be listed in the tenant's record of processing
activities; user agents and IPs are personal data. Subject-access requests:
`AuditRepository::findByUid($uid, $accountName)` returns every row about one
user (as subject and as attempted name) with details decoded - the GDPR
tooling module consumes this for exports.

## Export

The report page's **Download CSV (current filter)** streams the filtered rows
(`/admin/reports/auth-audit/csv?event=...&user=...&ip=...&from=...&to=...`).
Columns: `id, time_utc, created, event, severity, uid, username_attempted,
ip, ip_anonymised, user_agent, session_hash, details` (details as JSON).
The response is streamed in chunks of 500 rows, so a year of logins exports
without hitting the PHP memory limit. The CSV link requires the same
permission as the page and is `no-store`.

Filters: event type, user (account name or uid; matches both the subject and
`username_attempted`), IP (exact or prefix `10.0.*`), from/to date (site
time zone, inclusive).

For SIEM forwarding, subscribe to the `AuditEvent` below rather than
polling the table.

## Suspicious-login alerts (optional, default off)

`alerts.enabled` + `alerts.mail`. Two rules in
`src/SuspiciousLoginDetector.php`, evaluated on every recorded event:

- **new_ip**: a successful local or SSO login from an IP the user has not
  logged in from during `alerts.new_ip_days` (default 90). Users without any
  earlier login in that window do not alert (first login, long absence).
  Keep `new_ip_days` <= `anonymise_ip_after_days`, otherwise anonymised rows
  stop matching and known addresses look new.
- **failed_burst**: the `alerts.failed_threshold`-th (default 5) failed or
  flood-blocked attempt from one IP within `alerts.failed_window` seconds
  (default 900). Alerts exactly once when the threshold is reached, so a
  brute-force run gives one mail per window. Two attempts in the same second
  can in theory skip the exact count; the trade-off is accepted for
  simplicity.

Each alert sends one mail (`hook_mail` key `suspicious_login`, through the
site's normal mail system - SMTP via `settings.php` in production), writes a
warning to the Drupal log channel `aabenintra_audit`, and records an
`alert_sent` row. The mail contains rule, event id, uid, attempted name, IP,
time and a link to the report; no secrets.

## Wiring an SSO provider

A future OIDC (Entra ID, #79) or MitID (#81) module should, once it has
authenticated the user and created the Drupal session:

```php
\Drupal::service('aabenintra_audit.recorder')->recordExternalLogin(
  'entra',
  (int) $account->id(),
  ['subject' => $claims['sub'], 'tenant' => $claims['tid'], 'amr' => $claims['amr'] ?? []],
);
```

and on rejection (`$success = FALSE`, uid 0, the external identity as
`$usernameAttempted`). Never pass id/access tokens, nonces, codes or the
raw claims array. Both calls go through the same sanitiser, catalogue
severity and alerting as local logins. If the provider logs the user in via
`user_login_finalize()`, a `login_success` row is also written; that is
intended (one row for the local session, one for the external assertion).

Reacting to events: subscribe to `Drupal\aabenintra_audit\Event\AuditEvent`
(`AuditEvent::NAME`, dispatched after the row is written, read-only payload
with id, event, uid, ip, severity, details, created).

## Tests

- Unit (run locally, no DB): `vendor/bin/phpunit -c web/core
  web/modules/custom/aabenintra_audit/tests/src/Unit` - IP anonymiser and
  filter parsing.
- Kernel (need a database, run in CI): recorder/repository/retention/cron,
  user-entity hooks, suspicious-login rules with the test mail collector.
