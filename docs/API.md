# HTTP API and versioning policy

Status, as of this document: **AabenIntra has no public API.** The site is
server-rendered; `jsonapi`, `rest` and `serialization` are not enabled by any
recipe, `services.prod.yml` configures no CORS, and the `/api/dashboard/tiles`
endpoint discussed in issue #36 does not exist. What does exist is a handful of
private JSON endpoints under `/aabenintra/*` that the islands call from the
browser, plus a CSV export for the compliance report.

The machine-readable description is [`docs/openapi.yaml`](openapi.yaml)
(OpenAPI 3.1). This page explains how to read it and what guarantees apply.

## Endpoints today

| Method | Path | Island / caller | Access | CSRF key |
| --- | --- | --- | --- | --- |
| `POST` | `/aabenintra/follow/{taxonomy_term}` | channel follow button | authenticated | `aabenintra_channels_follow` |
| `POST` | `/aabenintra/react/{node}/{reaction}` | reactions bar | authenticated and may view the node | `aabenintra_social_react` |
| `POST` | `/aabenintra/acknowledge/{node}` | mandatory-read banner | `access content` | `aabenintra_acknowledge` |
| `GET` | `/aabenintra/preferences` | theme (read back prefs) | `access content` | none |
| `PATCH` | `/aabenintra/preferences` | theme Personalise panel, dashboard reorder/pin | `access content` | `aabenintra_theme_prefs` |
| `GET` | `/admin/reports/mandatory-reads/csv` | admin report "Download CSV" link | `access content overview` | none |

`{taxonomy_term}` and `{node}` are numeric ids (`\d+`) upcast to entities; an
unknown id is a Drupal 404. `{reaction}` is one of `like`, `celebrate`,
`insightful`, `support`; any other value is ignored and the current state is
returned unchanged.

### Authentication

All endpoints use the Drupal session cookie of the logged-in user. There are
no API keys, no bearer tokens and no anonymous access worth speaking of:
anonymous users pass `access content` on the acknowledge and preferences
routes, but the services short-circuit for uid 0 (no receipt is written, no
preference is stored), so the calls are no-ops.

Mutating requests additionally carry `X-CSRF-Token`. The token is minted
server-side per island key with `\Drupal::csrfToken()->get('<key>')` and
handed to the page in `drupalSettings` (`aabenintraChannels.csrfToken`,
`aabenintraSocial.csrfToken`, `aabenintraCompliance.csrfToken`,
`aabenintra.csrfToken`). A token minted for one key does not validate for
another, and the core `/session/token` route mints for the empty key, so it
cannot be used with these endpoints. This is intentional: the endpoints are
reachable only from pages that rendered the matching control.

### Error shapes as implemented

The endpoints were written at different times and do not share one error
format. The OpenAPI file documents each as it is:

| Endpoint | Bad / missing CSRF token | Not allowed | Bad payload |
| --- | --- | --- | --- |
| follow, react | `403` Drupal HTML error page (route has no `_format`, so `AccessDeniedHttpException` renders as HTML) | `403` HTML (route access) | n/a |
| acknowledge | `403` `{"error": "Invalid CSRF token"}` | `403` HTML (route access) | n/a |
| preferences PATCH | `403` `{"error": "Invalid CSRF token"}` | `403` HTML (route access) | `400` `{"error": "Invalid payload"}` when the body is not a JSON object |

Converging on `{"error": "<message>"}` with the HTTP status is the target for
new and touched endpoints (see "Internal endpoints" below). Clients must treat
any non-2xx as failure and leave the UI in its server-rendered state.

### Responses

- follow: `{"following": true, "followers": 12}`
- react: `{"counts": {"like": 3, "celebrate": 0, "insightful": 1, "support": 0}, "mine": "like"}` (`mine` is `null` after toggling off)
- acknowledge: `{"ok": true, "nid": 42}`
- preferences GET and PATCH: the full effective preferences object, defaults merged with the stored overrides. PATCH sanitises through an allow-list; unknown keys and invalid values are silently dropped, which is why the response is the whole object and the client should re-apply it.
- CSV: `text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="mandatory-reads.csv"`, columns `Title, Type, Acknowledged, Of users, Percent`.

## Versioning policy

### Internal endpoints (`/aabenintra/*`)

- **Unversioned and private.** They are an implementation detail of the
  islands and are shipped in the same release as the JavaScript that calls
  them. The contract is "same release": the server and the `dist/` + module
  JS deployed together always agree.
- **No compatibility promise** to anything outside this repository. Paths,
  payloads and error shapes may change in any release, including a patch
  release, when the calling island changes with them.
- A change still needs a `docs/openapi.yaml` update and a CHANGELOG entry in
  the same PR, because operators and security reviewers read them.
- Do not point integrations at these endpoints. If a second consumer needs
  the same capability, that is the trigger to promote it to the public API.

### Public API (`/api/v1/...`, future)

When AabenIntra exposes an API for other systems (AabenForms federation in
M13, the native app in #104, third-party integrations), it lives under
`/api/v1` and follows these rules:

1. **URL-versioned major, semver overall.** `/api/v1` is stable for its
   lifetime. Additive changes (new optional fields, new endpoints, new enum
   values where the schema says so) ship under the same major. Anything that
   removes or renames a field, changes a type, tightens validation or changes
   authentication is a new major at `/api/v2`.
2. **Deprecation signalled in-band.** A deprecated endpoint or field returns
   `Deprecation: @<unix-timestamp>` (RFC 9745) from the release that deprecates
   it and `Sunset: <HTTP-date>` (RFC 8594) with the removal date, plus a
   `Link: <changelog-url>; rel="deprecation"` header pointing at the CHANGELOG
   entry. Clients should log any response carrying `Deprecation`.
3. **Six months minimum** between the first release that sends `Deprecation`
   and the release that removes the endpoint, and never shorter than one
   supported minor (see [SECURITY.md](../SECURITY.md) for the N / N-1
   support window).
4. **Documented before merged.** Every public endpoint is in
   `docs/openapi.yaml` with request and response schemas, error shapes and
   security requirements, and every change has a CHANGELOG entry under
   "API".
5. **Authentication** for the public API is OAuth 2.0 / OIDC bearer tokens
   issued by the tenant's identity provider (M10, #79 / #86), not the session
   cookie, and never the island CSRF tokens.
6. **Tenant scoped.** Each tenant is its own container and database; the API
   never crosses tenants. There is no cross-tenant endpoint and no global
   admin API (see `docs/TENANT-ISOLATION.md`).

Related work landing in the same release line: the GDPR tooling (#117) adds
`/api/gdpr/export-requests`. Its shape is specified in `docs/GDPR.md` and the
OpenAPI file once that PR merges; the path is noted here so the `/api/`
namespace is reserved for it.

### Docs at `/api/docs` (follow-up, not built)

The intended delivery is a static page that renders `docs/openapi.yaml`
without a backend: a Twig-less HTML file in the theme (or a small
`aabenintra_api` module with one route) that loads Redoc or Swagger UI from the
theme's `dist/` (vendored at build time, no third-party CDN - the CSP in
`docs/SECURITY-HEADERS.md` will not allow one) and points it at
`/api/openapi.yaml`, served by a controller that returns the file with
`Content-Type: application/yaml`. Access is gated on a permission
(`access api documentation`) so unauthenticated users do not enumerate
endpoints. This is deliberately deferred until the first `/api/v1` endpoint
exists; until then the file in the repository is the documentation.

## Keeping `openapi.yaml` honest

- Every route in `web/modules/custom/*/*.routing.yml` that returns JSON, CSV
  or any non-HTML body is in the file. HTML page routes are not.
- The file is 3.1: `openapi: 3.1.0`, JSON Schema 2020-12 keywords, `null`
  expressed as `type: [string, "null"]`.
- Validate before committing with any OpenAPI 3.1 validator, for example
  `npx -y @redocly/cli lint docs/openapi.yaml`.
