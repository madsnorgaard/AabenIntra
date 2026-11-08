# Security policy

AabenIntra is an intranet product for organisations in the Danish public
sector. It handles employee data and internal documents, so we treat
vulnerability reports as the highest-priority input we get. Thank you for
taking the time to report one.

## Reporting a vulnerability

**Do not open a public GitHub issue for a security problem.**

1. Preferred: GitHub private vulnerability reporting on
   <https://github.com/madsnorgaard/AabenIntra/security/advisories/new>.
   Reports there are visible only to the maintainers and become a draft
   advisory with a CVE if warranted.
2. If private reporting is unavailable, contact the maintainer through
   <https://madsnorgaard.net> and ask for a secure channel before sending
   details. Do not put reproduction steps, tokens or screenshots of real
   tenant data in a first message over an unencrypted channel.
3. Operators who run their own AabenIntra tenant should publish their own
   `security.txt` (RFC 9116) with a `security@` alias on their domain; tenant
   incidents (a compromised account, a misconfigured instance) go to the
   operator first, product vulnerabilities to us.

Please include: the affected version or commit, the component (module,
recipe, theme, Docker image), reproduction steps, impact as you understand
it, and whether the issue is already public anywhere. We are happy to receive
reports in Danish or English.

## What to expect

| Step | Target |
| --- | --- |
| Acknowledgement of your report | within 2 business days |
| Triage (confirmed / not a vulnerability / duplicate) and a severity | within 5 business days |
| Fix released for **critical** severity | within 7 days of triage |
| Fix released for **high** severity | within 14 days |
| Fix released for **medium** severity | within 30 days |
| Fix released for **low** severity | next regular release |

Severity follows CVSS 3.1 base scores (critical 9.0+, high 7.0-8.9, medium
4.0-6.9, low under 4.0), adjusted for intranet context: anything that lets an
authenticated employee read content, receipts or profile data they are not
entitled to, or crosses a tenant boundary, is at least high regardless of
score. If we miss a target we will tell you why and give a new date.

Business days are Danish business days (CET/CEST).

## Supported versions

AabenIntra tracks Drupal 11. We support the latest minor release line (N) and
the one before it (N-1); older lines receive no fixes.

| Line | Status |
| --- | --- |
| Current minor (N) | Security and bug fixes |
| Previous minor (N-1) | Security fixes only, until the next minor ships |
| Older | Unsupported - upgrade |
| `main` before the first tagged release | Treated as the current line; fixes land on `main` and deploy to the reference tenant |

Release tagging and the CHANGELOG are introduced with the deployment runbook
(#126, `docs/DEPLOYMENT.md`, `CHANGELOG.md`); until the first tag, "version"
means a commit on `main`. Tenants are expected to run a supported line of
AabenIntra **and** a supported Drupal core (11.x current minor or the
previous one, per drupal.org's core release cycle). The Docker image pins
`drupal:11-php8.4-apache`; PHP 8.3 is the floor declared by the modules.

## Coordination with Drupal security releases

Most of the attack surface is Drupal core and contrib. How we handle upstream:

- The Drupal Security Team publishes advisories on **Wednesdays** (core on
  the third Wednesday of the month in its release windows, contrib any
  Wednesday), with pre-announcement for highly critical core issues. We
  subscribe to the security announcements and check them every Wednesday
  afternoon CET.
- `SA-CORE-*` rated critical or highly critical: a `security:` PR the same
  day, merged after a green build check, which deploys to the reference
  tenant; customer tenants are updated per their change window within the
  critical SLA above. `SA-CORE-*` moderate or less critical and
  `SA-CONTRIB-*` for modules we ship (`admin_toolbar`, `gin`, `gin_toolbar`,
  `group`, `pathauto`, `token`, `focal_point`, `easy_responsive_images`,
  `environment_indicator`, `redis`): within the matching SLA, usually in the
  next Dependabot cycle.
- Dependabot opens weekly composer PRs (Mondays) and GitHub's Dependabot
  security updates are enabled on the repository, so a published advisory for
  any locked package produces a PR without waiting for the schedule.
  `composer audit` runs as part of the quality gate (`docs/QUALITY.md`).
- Commits and PR titles for upstream fixes name the advisory
  (`security: Drupal core 11.4.8 => 11.4.10 (SA-CORE-2026-014/015/016)`) so
  tenants can map their deployed version to the advisory list.
- If an upstream fix needs a data migration or an operator step, the PR body
  says so under "Operator steps" and `docs/DEPLOYMENT.md` is updated.

## Disclosure policy

- We follow coordinated disclosure. We ask reporters to keep details private
  until a fix is released to supported lines and tenants have had a
  reasonable window to update: 14 days after the release for critical and
  high, 30 days for medium and low. We will agree a date with you.
- When the fix ships we publish a GitHub security advisory with a CVE where
  applicable, credit the reporter (unless they prefer not to be named), and
  add a CHANGELOG entry under "Security".
- If a vulnerability is being exploited or is already public, we release a
  fix as soon as one exists and publish immediately.
- Issues in Drupal core or a contrib project are reported to the Drupal
  Security Team (<https://www.drupal.org/security-team/report-issue>) and
  handled under their process; we will tell you that we did so.

## Scope

In scope:

- Custom modules in `web/modules/custom/aabenintra_*`, the `aabenintra`
  theme, the recipes in `web/recipes/`, `settings.php`,
  `services.prod.yml`, `docker/Dockerfile`, `docker-compose.yml` and the
  scripts in `scripts/`.
- The island endpoints under `/aabenintra/*` and the compliance report
  (`docs/openapi.yaml`).
- Access control for groups (visibility, membership, secret groups), org-unit
  scoping of the dashboard and feeds, mandatory-read receipts, and anything
  that would let one tenant see another tenant's data.

Out of scope (report upstream or to the operator instead):

- Vulnerabilities in Drupal core or contrib modules themselves - report to the
  Drupal Security Team; we will apply the fix.
- Infrastructure of a specific deployment (Traefik, the host OS, DNS, mail
  server, backups) - the operator of that tenant.
- Findings that require a compromised administrator account, physical access
  to a device, or a browser with security features disabled.
- Rate limiting and brute-force protection at the edge (handled by the
  operator's reverse proxy, see the Traefik hardening guide), missing
  security headers that are set by the edge rather than the application
  (`docs/SECURITY-HEADERS.md` documents which are which), clickjacking on
  pages that are already authenticated-only.
- The demo content module `aabenintra_demo` and the `scripts/seed_*.php`
  scripts, which are never enabled in production.
- Reports from automated scanners without a demonstrated impact.

Please do not test against the reference tenant
`aabenintra.fenixnordic.solutions` or any customer tenant with anything
beyond passive observation. Spin up your own instance (DDEV instructions in
[CONTRIBUTING.md](CONTRIBUTING.md)); it takes ten minutes.

## Hardening references for operators

- `docs/SECURITY-HEADERS.md` - CSP, HSTS, frame and content-type headers
- `docs/TENANT-ISOLATION.md` - the container-per-tenant isolation model
- `docs/SESSION-POLICY.md` and `docs/AUDIT-LOG.md` - sessions and
  authentication audit
- `docs/BACKUPS.md` - per-tenant backups and restore tests
- `docs/GDPR.md` - data export and erasure tooling

## Acknowledgements

Reporters who help us fix a confirmed issue are credited in the advisory and
listed here with their consent. No reports have been received yet.
