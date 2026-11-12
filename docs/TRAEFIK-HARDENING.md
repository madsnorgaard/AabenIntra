# Traefik hardening: rate limits, body caps and WAF

AabenIntra tenants run as one Drupal container each behind a shared Traefik
v3 reverse proxy. Traefik terminates TLS, so it is also the right place to
stop abusive traffic before it reaches PHP: credential stuffing against
`/user/login`, scripted scraping, oversized uploads, and one client eating
the whole Apache worker pool. This page covers the edge controls that ship
with the repo (`docker-compose.hardening.yml`), how to tune them per tenant,
and how an operator adds an OWASP Core Rule Set WAF on top.

Issue: #121. Related: the `aabenintra_security` module owns the response
headers (CSP, HSTS and friends) inside Drupal; see "Headers" below.

## What ships

`docker-compose.hardening.yml` is a compose override. Bring a tenant up with

```bash
cd ~/docker/<tenant>/
docker compose -f docker-compose.yml -f docker-compose.hardening.yml up -d
```

and Traefik picks the labels up within a few seconds, no restart. Leaving the
second `-f` off rolls everything back. The override adds, on the `drupal`
service only:

| Router (priority) | Rule | Middlewares | Default limit |
|---|---|---|---|
| `aabenintra_drupal` (10) | `Host(...)` - everything else | `ratelimit-general`, `inflight`, `buffering`, `compress` | 100 r/s sustained, burst 200, per client IP |
| `<tenant>-api` (20) | `/api/`, `/jsonapi`, `/aabenintra/` (+ language-prefixed) | `ratelimit-api`, `inflight`, `buffering`, `compress` | 30 r/s sustained, burst 60 |
| `<tenant>-login` (30) | `/user/login`, `/user/password*`, `/user/reset*` (+ `/da/...`) | `ratelimit-login`, `inflight`, `buffering`, `compress` | 10 per minute sustained, burst 20 |

Traefik evaluates the router with the highest priority first, so a request
to `/user/login` is always handled by the login router even though the main
router's `Host()` rule matches too. Explicit priorities are set because
Traefik's default (rule length) would also work today but silently flips if
someone lengthens the main rule.

The other middlewares:

- `inflight` - at most 100 concurrent requests per client IP (`inFlightReq`).
  Apache prefork in the `drupal` image has a finite number of workers; this
  stops one client holding them all.
- `buffering` - request bodies above `UPLOAD_MAX_BYTES` (default 64 MiB) are
  rejected with 413 at the edge. Bodies over 2 MiB are spooled to disk in
  the Traefik container rather than held in memory.
- `compress` - gzip/br/zstd for responses over 1 KiB. Traefik skips
  responses that already carry `Content-Encoding`, so it coexists with
  Apache `mod_deflate`.

What a blocked client sees: HTTP 429 (rate limit or in-flight cap) or 413
(body too large), with an empty body, logged in Traefik's access log with
`RouterName` set to the router that fired and `OriginStatus` empty, which is
how to tell an edge rejection from an application error.

## Per-tenant naming contract

Traefik's Docker provider sees every container on the shared `web` network
as one flat namespace. A middleware named `ratelimit-login` defined by two
containers with different numbers is an error, and Traefik then drops both.
Therefore:

- Every middleware and every router added by the override is prefixed with
  the tenant slug: `${TENANT}-ratelimit-login`, `${TENANT}-api`, ...
- `TENANT` comes from the tenant's `.env` (compose interpolation). It
  defaults to `aabenintra`, which is only correct for a host with one
  tenant. For a second tenant set `TENANT=<slug>` and `TENANT_HOST=<fqdn>`
  in that tenant's `.env` before `up -d`.
- The main router keeps the name `aabenintra_drupal` because the base
  `docker-compose.yml` defines it. A second tenant on the same host must
  rename that router (and its service) in its own base compose file; the
  hardening override follows whatever the base file calls it only through
  the explicit `service=aabenintra_drupal` labels, so adjust those too.
  Issue #52 (provisioning runbook) tracks making the base file tenant-aware.
- Slugs are lower-case `[a-z0-9-]`. Dots are legal in Traefik names but
  make the dashboard hard to read; use `kommune-x`, not
  `kommune-x.example.dk`.

Check the result in the Traefik dashboard (SSH tunnel to port 8080 on the
host) or with

```bash
docker logs traefik 2>&1 | grep -iE 'error|middleware' | tail
```

A duplicate-name conflict shows up there as "middleware ... defined multiple
times with different configurations".

## Tuning

All numbers are read from the tenant `.env`; nothing in the override file
needs editing. Defaults in brackets.

| Variable | Meaning |
|---|---|
| `RL_GENERAL_AVG` [100] / `RL_GENERAL_BURST` [200] | page/asset traffic, requests per second per IP and bucket size |
| `RL_API_AVG` [30] / `RL_API_BURST` [60] | island and API endpoints, per second per IP |
| `RL_LOGIN_AVG` [10] / `RL_LOGIN_BURST` [20] | login/reset, per MINUTE per IP |
| `INFLIGHT_MAX` [100] | concurrent requests per IP |
| `UPLOAD_MAX_BYTES` [67108864] | request body cap in bytes |

Traefik's `rateLimit` is a token bucket: `burst` tokens are available at
once and refill at `average` per `period`. A client that stays under
`average` never notices; one that bursts gets `burst` requests through and
is then throttled to `average`.

### The corporate NAT caveat

Every limit here keys on the client IP. A municipality's staff typically
reach the intranet from one or a handful of egress addresses, so "per IP"
means "per office". The defaults are chosen with that in mind:

- General: 100 r/s sustained with a burst of 200 from one address is on the
  order of 300 people loading a page within the same second. The theme
  ships aggregated CSS/JS and a page load is roughly 10-20 requests. If a
  large office hits 429s at 08:00 on a Monday, raise `RL_GENERAL_*` first;
  it is the only limit that ordinary browsing touches.
- Login: 20 attempts at once, then 10 per minute from one address. This is
  a brake on credential stuffing, not a quota for users: staff on SSO
  (Entra, issue #79) never hit `/user/login`, and password users log in
  once a day. Drupal's own flood control (5 failed attempts per account per
  6 hours, 50 per IP per hour) stays active behind it as the second layer.
  A 5,000-person organisation on password login behind a single NAT should
  raise `RL_LOGIN_AVG` to 30-60.
- API: the islands make one request per interaction (preference change,
  reaction, follow, acknowledge). 30 r/s per office is ample.

If the tenant is reached through a CDN or another reverse proxy in front of
Traefik, every request arrives from the proxy's address and all limits
collapse onto that one IP. In that case Traefik must read the client IP
from `X-Forwarded-For` instead. Add to each `ratelimit` middleware:

```
traefik.http.middlewares.${TENANT}-ratelimit-login.ratelimit.sourcecriterion.ipstrategy.depth=1
```

(`depth=1` = the last hop before Traefik; raise if more proxies are chained)
and make sure Traefik's entrypoint has `forwardedHeaders.trustedIPs` set to
the proxy's addresses, otherwise the header is spoofable and the limit is
trivially bypassed. Do not set `depth` without `trustedIPs`.

### Upload limits

The effective upload ceiling is the smallest of three values:

1. `UPLOAD_MAX_BYTES` at the edge (64 MiB default) - 413 from Traefik.
2. PHP `post_max_size` and `upload_max_filesize` in the `drupal` image. The
   image ships PHP's defaults, 8 MB and 2 MB, so today PHP is the limit. To
   allow 64 MiB documents add to `docker/Dockerfile`:

   ```dockerfile
   RUN printf 'upload_max_filesize = 64M\npost_max_size = 72M\n' > /usr/local/etc/php/conf.d/zz-uploads.ini
   ```

   (`post_max_size` a little above `upload_max_filesize` so a form with a
   file plus fields still fits.)
3. The per-field maximum on the Document and Media image fields
   (`max_filesize` in the recipes, currently empty = PHP limit).

Keep `UPLOAD_MAX_BYTES` at or slightly above `post_max_size` so the edge
rejects what PHP would reject anyway, but never below it, or users get a
blank 413 instead of Drupal's friendly validation message.

## Headers

The override sets no response headers on purpose. Three things already do:

1. The operator's Traefik stack applies a `security-headers@file` middleware
   on the `websecure` entrypoint for every host: HSTS (1 year,
   includeSubdomains), `X-Content-Type-Options: nosniff`,
   `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options: SAMEORIGIN`,
   and it strips `Server`/`X-Powered-By`/`X-Generator`.
2. The `aabenintra_security` Drupal module sets `Content-Security-Policy`
   with per-request nonces for the Vue islands, plus HSTS and the other
   standard headers, so a self-hosted tenant without an operator-level
   middleware is still covered.
3. Drupal core sets `X-Frame-Options: SAMEORIGIN` and `X-Content-Type-Options`.

How duplicates resolve: Traefik's `headers` middleware REPLACES a response
header of the same name coming from the service (it does not append), so
whatever the entrypoint middleware sets wins over Drupal for those names.
CSP is not in the operator's middleware, so Drupal's nonce-based CSP passes
through untouched. If you add a headers middleware of your own, do not put
`Content-Security-Policy` in it: a static edge CSP would overwrite the
nonces and break every island. Setting HSTS at both layers is harmless
(same value, edge wins) and is deliberately left as belt and braces.

Self-hosters without a global headers middleware: add HSTS at the Traefik
entrypoint (as the operator does), not in this override, so it also
protects tenants that run without the hardening file.

## WAF: OWASP Core Rule Set

Rate limiting stops volume; a WAF stops shape (SQLi, XSS, path traversal,
scanner noise). Two ways to put the OWASP CRS in front of a tenant with
Traefik v3. Option (b) is recommended.

### Option (a): ModSecurity sidecar

The `traefik-modsecurity-plugin` forwards each request to an
`owasp/modsecurity-crs` container and blocks when that returns 403. Pros:
the reference CRS engine, mature. Cons: one extra container and an extra
hop per request (the plugin re-sends the request to the sidecar), body size
limits to keep in sync, and the sidecar needs its own tuning.

In the operator's Traefik stack (`/home/<op>/docker/traefik`):

```yaml
# docker-compose.yml (Traefik stack)
services:
  traefik:
    # ...existing...
    command:
      - --experimental.plugins.modsecurity.modulename=github.com/madebymode/traefik-modsecurity-plugin
      - --experimental.plugins.modsecurity.version=v1.6.0
  waf:
    image: owasp/modsecurity-crs:4.3.0-apache-alpine
    container_name: traefik_waf
    environment:
      PARANOIA: "1"
      ANOMALY_INBOUND: "10"
      ANOMALY_OUTBOUND: "5"
      BACKEND: http://waf-backend:80
      MODSEC_RULE_ENGINE: DetectionOnly   # flip to On after a quiet week
      MODSEC_AUDIT_LOG_FORMAT: JSON
      MODSEC_AUDIT_LOG: /dev/stdout
    networks: [web]
  waf-backend:
    image: traefik/whoami
    container_name: traefik_waf_backend
    networks: [web]
```

(or the same two keys in `traefik.toml` under `[experimental.plugins.modsecurity]`).
Dynamic middleware in `traefik_dynamic.toml`:

```toml
[http.middlewares.waf.plugin.modsecurity]
  modSecurityUrl = "http://traefik_waf:8080"
  timeoutMillis = 2000
```

Then add `waf@file` to each tenant router's middleware list.

### Option (b): Coraza as a native WASM plugin (recommended)

[Coraza](https://coraza.io) is a ModSecurity-compatible engine written in
Go. The `coraza-http-wasm-traefik` plugin runs it inside Traefik's own
WASM runtime, no sidecar, no second hop, and the CRS is embedded in the
plugin. Static config for the operator's Traefik:

```toml
# traefik.toml
[experimental.plugins.coraza]
  moduleName = "github.com/jcchavezs/coraza-http-wasm-traefik"
  version = "v0.3.0"
```

```yaml
# or, YAML static config
experimental:
  plugins:
    coraza:
      moduleName: github.com/jcchavezs/coraza-http-wasm-traefik
      version: v0.3.0
```

Traefik downloads the plugin from the plugin catalog at start-up (outbound
HTTPS needed once; it is cached under `/plugins-storage`). Dynamic
middleware, detection-only first:

```yaml
# traefik_dynamic.yml (the operator's file is TOML; same keys)
http:
  middlewares:
    aabenintra-waf:
      plugin:
        coraza:
          directives:
            - Include @coraza.conf-recommended
            - Include @crs-setup.conf.example
            - Include @owasp_crs/*.conf
            # Paranoia level 1 (the CRS default): the rules with the fewest
            # false positives. Raise to 2 only after PL1 has run clean.
            - SecAction "id:900000,phase:1,pass,t:none,nolog,setvar:tx.blocking_paranoia_level=1"
            # Start in DetectionOnly: log what WOULD be blocked, block nothing.
            - SecRuleEngine DetectionOnly
            # Drupal-specific exclusions, see below.
            - Include /etc/traefik/waf/drupal-exclusions.conf
```

```toml
# TOML equivalent for traefik_dynamic.toml
[http.middlewares.aabenintra-waf.plugin.coraza]
  directives = [
    "Include @coraza.conf-recommended",
    "Include @crs-setup.conf.example",
    "Include @owasp_crs/*.conf",
    "SecAction \"id:900000,phase:1,pass,t:none,nolog,setvar:tx.blocking_paranoia_level=1\"",
    "SecRuleEngine DetectionOnly",
    "Include /etc/traefik/waf/drupal-exclusions.conf",
  ]
```

Mount the exclusions file into the Traefik container
(`./waf:/etc/traefik/waf:ro`). Coraza writes rule matches to Traefik's
log at `error` level, so in detection mode run `docker logs traefik | grep
-i coraza` for a week and read what it flagged.

#### Drupal false-positive exclusions

Drupal's admin UI and editors routinely send things the CRS considers
hostile: HTML bodies from CKEditor, serialized YAML in config forms, SQL-ish
words in content, long base64 image data, `%2F` in path aliases. The
baseline exclusions below are a starting point; every match you see in
detection mode that is legitimate becomes another `ctl:ruleRemoveById` or
a targeted `ruleRemoveTargetById`. Keep them narrow: exclude a rule for a
path, not globally.

```apache
# /etc/traefik/waf/drupal-exclusions.conf
# Admin UI and content editing: authenticated, CSRF-protected, and the
# bodies are HTML/YAML by design. Disable the request-body inspection
# rules that fire on markup and keep everything else (protocol, scanner
# detection, LFI/RFI on the path).
SecRule REQUEST_URI "@rx ^(/[a-z]{2})?/(admin/|node/[0-9]+/edit|node/add/|group/[0-9]+/edit|user/[0-9]+/edit|media/[0-9]+/edit|block/|taxonomy/term/[0-9]+/edit|editor/|ckeditor5/|quickedit/|layout_builder/|batch)" \
    "id:10001,phase:1,pass,t:none,nolog,\
    ctl:ruleRemoveById=941100-941999,\
    ctl:ruleRemoveById=942100-942999,\
    ctl:ruleRemoveById=930100-930130,\
    ctl:ruleRemoveById=932100-932999,\
    ctl:ruleRemoveById=920270,\
    ctl:ruleRemoveById=920271,\
    ctl:ruleRemoveById=920272,\
    ctl:ruleRemoveById=920273,\
    ctl:ruleRemoveById=920440"

# Search: free text, often with quotes and operators.
SecRule REQUEST_URI "@rx ^(/[a-z]{2})?/search" \
    "id:10002,phase:1,pass,t:none,nolog,\
    ctl:ruleRemoveTargetById=942100-942999;ARGS:keys,\
    ctl:ruleRemoveTargetById=941100-941999;ARGS:keys"

# Island endpoints: small JSON, written by our own JS. Allow the PATCH
# verb the preferences island uses (CRS 911100 enforces allowed methods).
SecRule REQUEST_URI "@rx ^(/[a-z]{2})?/aabenintra/" \
    "id:10003,phase:1,pass,t:none,nolog,\
    ctl:ruleRemoveById=911100"
SecAction "id:10004,phase:1,pass,t:none,nolog,setvar:'tx.allowed_methods=GET HEAD POST OPTIONS PATCH DELETE'"

# Drupal session and settings cookies carry long random values that trip
# the "cookie is too long/odd" heuristics.
SecRuleUpdateTargetById 942440 "!REQUEST_COOKIES:/^SSESS/"
SecRuleUpdateTargetById 942450 "!REQUEST_COOKIES:/^SSESS/"
SecRuleUpdateTargetById 920450 "!REQUEST_HEADERS:Cookie"

# File uploads: the multipart body is the file; CRS checks the filename only.
SecRule REQUEST_URI "@rx ^(/[a-z]{2})?/(file/ajax|media/add|node/add/document|node/[0-9]+/edit)" \
    "id:10005,phase:1,pass,t:none,nolog,\
    ctl:requestBodyAccess=Off"
```

Rule ID ranges: 920 protocol enforcement, 930 LFI, 932 RCE, 941 XSS, 942
SQLi. The last block switches body inspection off for upload endpoints so
Coraza does not buffer a 64 MiB PDF.

#### Rollout per tenant

1. Add the plugin to the Traefik static config and restart Traefik (the
   only restart in this procedure). Confirm `coraza` appears in the
   dashboard's plugin list.
2. Define the middleware in detection mode as above.
3. Attach it to ONE tenant by appending `aabenintra-waf@file` to the three
   `...middlewares=` labels in that tenant's `docker-compose.hardening.yml`
   (or set it per router in the Traefik dynamic file instead, to keep tenant
   repos free of operator-specific names). `up -d`.
4. Run the tenant normally for 5-7 working days. Review
   `docker logs traefik | grep -i 'coraza'`; add exclusions for genuine
   traffic; re-check.
5. Switch `SecRuleEngine DetectionOnly` to `SecRuleEngine On`. Blocked
   requests now get 403 from Traefik. Keep watching for a week.
6. Repeat 3-5 per tenant. Tenants can share the middleware once the
   exclusion set is stable; a tenant with special integrations gets its
   own middleware (`<tenant>-waf`) with extra exclusions.
7. Only then consider `tx.blocking_paranoia_level=2`, back in detection
   mode first.

### Option (c): ban repeat offenders from the access log

Traefik's access log (JSON, already enabled in the operator's stack) is a
good fail2ban source: a filter on `"RouterName":"<tenant>-login@docker"`
with `"DownstreamStatus":429` or Drupal's 403 on failed logins, and an
action that adds the IP to an `ipAllowList`/`ipDenyList` middleware via the
file provider or to the host firewall. Mentioned for completeness; the
rate limits above already make brute force impractical, and a ban list
shared by a whole NAT'd office is a support ticket waiting to happen.

## Verification checklist

Run from a machine outside the tenant's network. Replace `HOST`.

```bash
HOST=aabenintra.fenixnordic.solutions

# 1. Login limiter: 25 quick GETs must end in 429s (burst 20, then 10/min).
for i in $(seq 1 25); do curl -s -o /dev/null -w '%{http_code} ' "https://$HOST/user/login"; done; echo
# expect: 200 x20 then 429 x5 (the exact split depends on refill during the loop)

# 2. Language-prefixed login is covered too.
for i in $(seq 1 25); do curl -s -o /dev/null -w '%{http_code} ' "https://$HOST/da/user/login"; done; echo

# 3. The main router is NOT affected by the login bucket: pages still 200.
curl -s -o /dev/null -w '%{http_code}\n' "https://$HOST/"

# 4. API/island bucket is separate (needs ~70 requests in a second to trip).
seq 1 80 | xargs -P 20 -I{} curl -s -o /dev/null -w '%{http_code}\n' "https://$HOST/aabenintra/preferences" | sort | uniq -c

# 5. Body cap: 70 MiB POST gets 413 at the edge (Drupal never sees it).
head -c 73400320 /dev/zero | curl -s -o /dev/null -w '%{http_code}\n' -X POST --data-binary @- "https://$HOST/user/login"

# 6. Headers: HSTS from the edge, CSP from Drupal (nonce present), no Server/X-Powered-By.
curl -sI "https://$HOST/" | grep -iE 'strict-transport|content-security|x-frame|x-content-type|^server|x-powered|x-generator'

# 7. Compression negotiated.
curl -sI -H 'Accept-Encoding: gzip' "https://$HOST/" | grep -i content-encoding

# 8. Which router answered (Traefik access log on the host).
docker logs traefik --since 2m 2>&1 | grep -oE '"RouterName":"[^"]+"|"DownstreamStatus":[0-9]+' | paste - - | sort | uniq -c
```

After enabling the WAF additionally:

```bash
# 9. A textbook probe is logged (detection) or 403 (blocking).
curl -s -o /dev/null -w '%{http_code}\n' "https://$HOST/?q=1%27%20OR%201%3D1--"
curl -s -o /dev/null -w '%{http_code}\n' "https://$HOST/index.php?page=../../../../etc/passwd"
# 10. Editing a story with inline HTML and saving a config form still works (no false positive).
```

Record the outcome of 1-8 in the tenant's provisioning notes when a tenant
goes live, and re-run 1 and 6 after every Traefik upgrade.
