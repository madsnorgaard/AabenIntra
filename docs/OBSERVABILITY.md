# Observability

How an AabenIntra tenant is observed in production: structured logs, error
tracking, a health endpoint, metrics and a Grafana dashboard. Everything is
configured through environment variables so one image serves every tenant,
and every piece is optional except the logs.

| Concern | Where it lives | Switch |
| --- | --- | --- |
| Structured JSON logs on stdout/stderr | `aabenintra_observability` + `drupal/monolog`, wired in `services.prod.yml` | always on; `LOG_LEVEL`, `LOG_DBLOG`, `LOG_ANONYMIZE_IP` |
| Error tracking | `drupal/raven` (Sentry SDK), configured by `aabenintra_observability` | `SENTRY_DSN` (empty = off) |
| Health endpoint `/health` | `aabenintra_observability` | always on |
| Request rate, errors, latency | Traefik metrics (already on the reference host) | operator's Prometheus |
| Container CPU/memory/restarts | cAdvisor (already on the reference host) | operator's Prometheus |
| Dashboard | `docs/observability/grafana-aabenintra.json` | import into Grafana |
| Alerts | `docs/observability/prometheus-alerts.example.yml` | copy into your rules |
| Log shipping for self-hosters | `docker-compose.logging.yml` + `docker/observability/config.alloy` | `LOKI_URL` |

## Environment contract

Set in the tenant's `.env` (see `.env.example`); `docker-compose.yml` passes
them to the Drupal container.

| Variable | Default | Meaning |
| --- | --- | --- |
| `APP_ENV` | `production` | `service.environment` in logs; default for `SENTRY_ENVIRONMENT` |
| `APP_RELEASE` | `dev` (build arg) | Release identifier: `service.version` in logs, `release` in Sentry, `version` in `/health`. Baked into the image: `APP_RELEASE=$(git rev-parse --short HEAD) docker compose build` |
| `TENANT` | hostname from `TRUSTED_HOSTS` | `tenant` field in logs. Derived automatically when `TRUSTED_HOSTS` is a plain escaped hostname such as `intranet\.kommune\.dk` |
| `LOG_LEVEL` | `info` | Minimum level written to the container log (`debug`, `info`, `notice`, `warning`, `error`, ...) |
| `LOG_DBLOG` | `0` | `1` keeps writing to the watchdog table as well, so Reports > Recent log messages stays populated |
| `LOG_ANONYMIZE_IP` | `1` | Truncate `client.ip` (IPv4 to /24, IPv6 to /64) |
| `SENTRY_DSN` | empty | DSN of a Sentry or GlitchTip project. Empty disables error tracking entirely |
| `SENTRY_ENVIRONMENT` | `APP_ENV` | Environment tag on events |
| `SENTRY_TRACES_SAMPLE_RATE` | `0.05` | Share of requests traced for performance (request + database spans). `0` disables tracing |
| `LOKI_URL` | - | Only for `docker-compose.logging.yml` |

Invalid values fall back to the default rather than breaking the container
(`LOG_LEVEL=verbose` means `info`, a non-numeric sample rate means `0.05`).

## Structured logging

### Pipeline

```
Drupal logger channels -> monolog (logger.factory) -> StreamHandler php://stderr
  -> Apache stderr -> docker json-file driver -> promtail / Alloy -> Loki -> Grafana
```

`drupal/monolog` replaces the core `logger.factory`. Core's own loggers
(dblog, syslog, raven) only receive records when they are listed as monolog
handlers, which is what `aabenintra_observability`'s service provider does
based on the environment:

- `aabenintra_stderr` (always): one JSON line per record, level from
  `LOG_LEVEL`. When drush runs in a terminal the same handler prints
  human-readable lines instead (deploy scripts, cron).
- `drupal.dblog` (only with `LOG_DBLOG=1` and the dblog module enabled).
- `drupal.raven` (only with `SENTRY_DSN` set and the raven module enabled).

The static part of this configuration is in `services.prod.yml`
(`monolog.channel_handlers`, `monolog.processors`). Tenants that mount a
different `services.yml` can keep the same two parameter blocks.

**dblog decision.** The watchdog table is off by default in production: it
grows inside the tenant database, is pruned by cron, offers no search worth
the name and duplicates what Loki holds with retention you control. Set
`LOG_DBLOG=1` on a tenant where editors rely on Reports > Recent log messages
and nobody looks at Grafana.

### Line format (ECS)

Field names follow the Elastic Common Schema 8.11 so the lines also import
into ECS-aware tools unchanged.

```json
{
  "@timestamp": "2026-11-08T12:45:12.123Z",
  "ecs": {"version": "8.11.0"},
  "log": {"level": "error", "logger": "php"},
  "message": "Drupal\\Core\\Database\\DatabaseExceptionWrapper: SQLSTATE[HY000] ... in Drupal\\Core\\Database\\StatementWrapperIterator->execute() (line 123 of core/lib/...)",
  "labels": {"channel": "php"},
  "event": {"dataset": "drupal.php"},
  "service": {"name": "aabenintra", "environment": "production", "version": "1a2b3c4"},
  "tenant": "intranet.kommune.dk",
  "host": {"name": "7f3c2a9b1d0e"},
  "user": {"id": "42"},
  "url": {"path": "/node/5", "domain": "intranet.kommune.dk"},
  "client": {"ip": "192.0.2.0"},
  "http": {"request": {"referrer": "https://intranet.kommune.dk/dashboard"}},
  "drupal": {"memory_bytes": 12345678, "context": {"nid": 17}},
  "error": {"type": "RuntimeException", "message": "...", "code": "0", "stack_trace": "/opt/drupal/web/...(123)\n#0 ..."}
}
```

| Field | Source | Notes |
| --- | --- | --- |
| `@timestamp` | record time, UTC, ms | |
| `log.level` | PSR-3 level name, lower case | `debug` ... `emergency` |
| `log.logger`, `labels.channel`, `event.dataset` | Drupal logger channel | `php`, `user`, `cron`, `access denied`, `page not found`, module names |
| `message` | message with placeholders substituted | |
| `service.*` | `aabenintra`, `APP_ENV`, `APP_RELEASE` | |
| `tenant` | `TENANT` or `TRUSTED_HOSTS` | omitted when neither yields a hostname |
| `host.name` | container hostname | the short container id unless `hostname:` is set |
| `user.id` | current user id | `0` for anonymous; never the account name or e-mail |
| `url.path`, `url.domain` | request URI | the query string is dropped (tokens, search terms) |
| `client.ip` | `Request::getClientIp()` behind Traefik (trusted proxy) | anonymised unless `LOG_ANONYMIZE_IP=0` |
| `http.request.referrer` | Referer header | |
| `drupal.memory_bytes` | PHP memory usage at log time | |
| `drupal.context` | remaining context keys after Drupal's standard ones are removed | normalised by monolog |
| `error.*` | the `exception` context key when present | type, message, code, `file(line)` + trace. Core's `Error::logException()` sets it for every uncaught exception |

Not logged on purpose: account names, e-mail addresses, request bodies,
backtrace arguments, cookies, query strings.

### Querying in Loki

The reference host tails `/var/lib/docker/containers/*/*-json.log` with
promtail. That setup labels lines with `job="containers"` and `container_id`
only, so filter on the JSON itself:

```logql
{job="containers"} | json | service_name="aabenintra"
{job="containers"} | json | service_name="aabenintra" | log_level=~"error|critical|alert|emergency"
{job="containers"} | json | service_name="aabenintra" | tenant="intranet.kommune.dk" | labels_channel="user" |= "Login attempt failed"
sum by (log_level) (count_over_time({job="containers"} | json | service_name="aabenintra" [5m]))
```

Loki flattens nested keys with `_`: `log.level` becomes `log_level`,
`labels.channel` becomes `labels_channel`, `url.path` becomes `url_path`.

With the self-hoster sidecar (`docker-compose.logging.yml`) streams carry
`container_name`, so `{container_name=~"aabenintra.*"} | json` is cheaper.

## Error tracking (Sentry or GlitchTip)

`drupal/raven` is installed and enabled (`install_prod.sh` runs
`drush en raven`); it does nothing until `SENTRY_DSN` is set. All other
settings are forced from the environment by
`aabenintra_observability`'s config override, so the UI at
`/admin/config/development/logging` shows them greyed out:

- captured: `error`, `critical`, `alert`, `emergency`; PHP fatal errors; drush
  errors. Warnings, notices and info (including the `user` channel's
  `Login attempt failed for %user`) are attached as **breadcrumbs** to the next
  captured event, not sent on their own.
- ignored channels: `page not found`, `access denied` (bot noise, broken links).
- privacy: `send_user_data`, `capture_user_ip`, `send_request_body`,
  `modules` (package list), `auto_session_tracking`, `trace` (call arguments),
  `database_tracing_args` are all off. Events carry the numeric user id only.
  This is the raven equivalent of `send_default_pii = false`.
- performance: `SENTRY_TRACES_SAMPLE_RATE` (default 5 %) of requests are
  traced with request and database spans, which is how slow queries surface
  in Sentry's Performance view. Twig and 404 tracing are off.
- `release` = `APP_RELEASE`, `environment` = `SENTRY_ENVIRONMENT` or `APP_ENV`.

Audit events (the `aabenintra_audit` module's authentication trail) are
ordinary log records today, so they already appear as breadcrumbs; forwarding
them as first-class Sentry events is a matter of adding their channel to
`log_levels` in `RavenOverrides` later.

### GlitchTip for data-sovereign hosting

Sentry's SaaS stores event data outside the EU unless you pick the EU region,
and even then a US company processes it. Municipalities that want error
tracking on their own hardware can run [GlitchTip](https://glitchtip.com/):
open source, speaks the Sentry protocol, a few containers (web, worker,
Postgres, Redis). Create a project, copy its DSN into `SENTRY_DSN` and
nothing else changes. GlitchTip ignores the performance sampling options it
does not implement.

Whichever you use: the DSN is a secret for the tenant's `.env`, never for
the repository or the image.

## `/health`

`GET /health` is anonymous, uncached (`Cache-Control: no-store`) and
answers during maintenance mode.

```json
{"status":"ok","db":"ok","cache":"ok","version":"1a2b3c4"}
```

- `200` when the database answers `SELECT 1` and the default cache backend
  (Redis when configured, otherwise the database) completes a set/get round
  trip; `503` with `"status":"fail"` otherwise. A failed check is logged at
  `error` level, so it also reaches Loki and Sentry.
- `version` is `APP_RELEASE`. Nothing else is exposed: no hostnames, paths,
  queue sizes or component versions.
- Probe it with the blackbox exporter (`http_2xx` module,
  `https://<tenant host>/health`), Traefik's `healthcheck` on the service, or
  an external monitor such as Uptime Kuma. For the reference host, add the
  URL to the `blackbox` job in the monitoring repository's `prometheus.yml`.

## Metrics

No PHP-level exporter is shipped. The request-level signals (rate, status
codes, latency) come from Traefik, resource usage from cAdvisor, and the
reference host already scrapes both:

- Traefik: `traefik_service_requests_total{service="aabenintra_drupal@docker", code, method}`
  and `traefik_service_request_duration_seconds_bucket`. Needs
  `--metrics.prometheus=true` (service labels are on by default; router labels
  need `--metrics.prometheus.addRoutersLabels=true`).
- cAdvisor: `container_cpu_usage_seconds_total`, `container_memory_working_set_bytes`,
  `container_start_time_seconds` (restarts) with `name="aabenintra_drupal"` etc.
- blackbox: `probe_success`, `probe_duration_seconds` for `/health`.

`drupal/prometheus_exporter` (2.1.0, Drupal 11/12) was evaluated. It is
maintained, but what it adds (entity counts, cron age, queue sizes) is not what
the base dashboard needs, it exposes `/metrics` on the public host so it
must be fenced with a Traefik IP allowlist per tenant, and each scrape
bootstraps Drupal. Worth revisiting once there is a concrete question those
metrics answer.

## Grafana dashboard

`docs/observability/grafana-aabenintra.json`: Dashboards > New > Import >
Upload, then pick your Prometheus and Loki datasources (the file uses
`${DS_PROMETHEUS}` / `${DS_LOKI}` inputs, so it works with any datasource
uid). Variables:

- `tenant`: Traefik service(s) matching `aabenintra.*`.
- `container`: cAdvisor container name(s) matching `aabenintra.*`.
- `log_stream`: the Loki stream selector your shipper produces
  (`job="containers"` for file-based promtail, `container_name=~"aabenintra.*"`
  for the Alloy sidecar).
- `log_tenant`: regex on the `tenant` JSON field.
- `level`: minimum level in the Recent errors panel.
- `health_target`: blackbox instance(s) ending in `/health`.

Panels: request rate, 4xx/5xx by code, 5xx ratio, p50/p95/p99 latency,
health probe status and duration, container CPU, memory, restarts and network,
error-level log rate, log volume by channel, and a log panel. Panels show
"No data" rather than failing when an exporter is missing.

## Alerts

`docs/observability/prometheus-alerts.example.yml` holds the four Prometheus
rules below plus a Loki ruler rule; thresholds are starting points.

| Alert | Condition | Why this threshold |
| --- | --- | --- |
| `AabenIntra5xxRate` | > 5 % of responses 5xx for 5 min | an intranet with a few requests per second; 5 % is many users seeing errors, and `clamp_min` keeps one failed request at 3 a.m. from paging |
| `AabenIntraSlow` | p95 > 3 s for 10 min | the point where editors start double-submitting forms |
| `AabenIntraHealthDown` | `probe_success == 0` for 3 min | DB or cache gone, or the container is down; 3 min absorbs a deploy restart |
| `AabenIntraContainerRestarted` | any `aabenintra_*` start time change in 15 min | MariaDB OOM kills show up here before users complain |
| `AabenIntraErrorLogBurst` (Loki) | > 20 error-level lines in 5 min | a deploy that broke a template produces hundreds; normal operation produces a handful a day |

## Self-hosters without a logging stack

If the host does not already ship container logs, add the Alloy sidecar:

```bash
echo 'LOKI_URL=http://loki:3100/loki/api/v1/push' >> .env
docker compose -f docker-compose.yml -f docker-compose.logging.yml up -d
```

`docker/observability/config.alloy` discovers containers whose name contains
`LOG_CONTAINER_FILTER` (default `aabenintra`) through the Docker socket,
labels them `container_name`, `compose_service`, `compose_project`,
`job="containers"` and `host`, and pushes to `LOKI_URL`. Grafana Alloy is
pinned (`grafana/alloy:v1.21.3`); promtail is end of life upstream and not
used here. For Grafana Cloud uncomment the `basic_auth` block.

The sidecar mounts the Docker socket read-only, which still lets it read
every container's metadata and logs on that host. Do not add it to hosts that
already tail `/var/lib/docker/containers` (the reference VPS2 setup does), or
every line is ingested twice.

A minimal Loki + Grafana for a single host is two more containers
(`grafana/loki`, `grafana/grafana`) with the Loki datasource pointed at
`http://loki:3100`; the dashboard above then works with
`log_stream = container_name=~"aabenintra.*"`.

## Privacy and retention (GDPR)

Logs are personal data when they can be tied to a person. The defaults keep
them minimal:

- No names, e-mail addresses, request bodies, query strings or call
  arguments in any log line or Sentry event. The numeric Drupal user id is
  the only identifier; it is needed to investigate "my page is broken"
  reports.
- Client IPs are truncated by default (`LOG_ANONYMIZE_IP=1`). Traefik's
  access log keeps the full IP for security forensics under its own,
  shorter retention; the application log does not need a second copy.
- Error tracking is opt-in per tenant (`SENTRY_DSN`) and should be covered by
  the tenant's record of processing activities; GlitchTip on the tenant's
  own infrastructure avoids a third-party processor altogether.
- Retention is set where the data lives: Loki `limits_config.retention_period`
  (30 days is a reasonable default for application logs; longer only with a
  documented purpose), Sentry/GlitchTip project retention (90 days default,
  GlitchTip configurable), docker json-file `max-size`/`max-file` (bounded to
  5 x 50 MB per container in `docker-compose.yml`), and the watchdog table
  (core's `dblog.settings:row_limit`) when `LOG_DBLOG=1`.
- Failed logins are logged (`user` channel) with the attempted account name
  inside the message because that is what core logs; treat the Loki tenant
  as access-controlled (Grafana login, or an IP allowlist in front of it).

## Local development

In DDEV there is no `services.prod.yml`, so monolog falls back to its own
default (syslog). To see the JSON lines locally, add to
`web/sites/default/settings.local.php`:

```php
$settings['container_yamls'][] = $app_root . '/../services.prod.yml';
```

and watch `ddev logs -f` (or `LOG_LEVEL=debug ddev drush cr` for the drush
line format). Unit tests: `vendor/bin/phpunit -c web/core/phpunit.xml.dist
web/modules/custom/aabenintra_observability/tests/src/Unit`. The `/health`
kernel test needs `SIMPLETEST_DB` and runs in CI.
