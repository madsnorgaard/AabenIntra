# Tenant isolation model

AabenIntra is sold as container-per-tenant. This document says exactly what
that means, what it does not protect against, how to verify it on a running
tenant (`drush aabenintra:verify-isolation`), and what the naming contract for
a tenant stack is. Related: `docs/DEPLOYMENT.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `docs/BACKUPS.md`.

## 1. The model: one compose stack per tenant

Every tenant is one `docker compose` project in its own directory with its
own `.env`:

| Per tenant (isolated) | Mechanism |
| --- | --- |
| Drupal container (`<slug>_drupal`) | Own PHP process space, own `/tmp`, own `settings.php` bind mount, own environment |
| MariaDB container (`<slug>_db`) and database | Own server process and data directory (`./data/mysql`); one database, one non-root user, root has a random unusable password |
| Redis container (`<slug>_redis`) | Own process and `./data/redis`; `cache_prefix` adds a per-tenant key prefix once the Redis backend is enabled |
| Public files | Named volume `drupal_files` scoped to the compose project |
| Private files | Bind mount `./data/private` -> `/opt/drupal/private`, outside the docroot |
| Pre-deploy DB snapshots | `~/backups/<project_dir>/` |
| Hostname, TLS certificate | Traefik host rule per tenant, Let's Encrypt certificate per host |
| Session cookie | Drupal names it `SESS<sha256(host + base path)>` and the browser scopes it to the host; tenants never share a cookie name or value |
| `hash_salt` | Per tenant from `.env`; signs one-time login links, form tokens and private file URLs |
| Trusted host pattern | Exactly one per tenant; a request for another tenant's host is rejected by Drupal |
| Users, roles, groups, content, search index, config | Live in the tenant database; there is no cross-database query path in the application |

There is no shared Drupal instance, no shared database server, no table
prefixing and no shared cache. A user "in tenant A" has no account in tenant
B; a Views query, JSON:API request or search in tenant A runs against tenant
A's database and index only.

## 2. Shared by all tenants (not isolated)

| Shared | Consequence |
| --- | --- |
| The VPS: kernel, CPU, memory, disk, network | A tenant can exhaust resources for the others (no per-tenant limits yet, see section 6). A kernel or container-runtime escape would cross tenants. |
| Docker daemon and Docker networks | Containers on the shared `web` network can reach each other by name. A tenant's Drupal container is on `web` (for Traefik) and on its own `internal` network (for its DB and Redis). The DB and Redis containers are **only** on `internal`, so another tenant's Drupal cannot reach them. |
| Traefik | Terminates TLS for every host; a Traefik misconfiguration could route host A to container B. |
| Mail relay (`mail_internal` network, shared SMTP credentials) | Mail from all tenants leaves through one relay and one sender identity today. A tenant cannot read another tenant's mail, but headers reveal the shared infrastructure. |
| The Docker image | Same code for all tenants; a vulnerability in the image affects all tenants at once (and is also fixed for all at once). |
| The deploy pipeline and its SSH key | Whoever can push to `main` can deploy to every tenant. |
| Host backups | Weekly VPS snapshots contain every tenant. |

## 3. Threat model

Protected against:

- **Application-level cross-tenant reads.** There is no code path from tenant
  A's Drupal to tenant B's data: different database server, different
  credentials, different network. Cross-tenant Views, JSON:API, search or
  cache leakage inside one Drupal is not a meaningful test here, because the
  tenants are not inside one Drupal. The isolation tests for this product are
  therefore configuration checks on the boundary (section 4) plus the
  intra-tenant access tests of the group module, not a cross-tenant query
  suite.
- **Credential reuse.** A leaked DB password of tenant A opens tenant A's DB
  container only (not reachable from outside `internal` anyway).
- **Session and token forgery across tenants.** Different hosts, different
  cookie names, different salts.
- **Private file exposure.** Private files are outside the docroot and served
  through Drupal's access-checked file controller.

Not protected against (accepted or mitigated elsewhere):

- **Container escape or kernel vulnerability.** Mitigation: unattended OS
  updates, Docker engine updates, follow-ups in section 6.
- **Noisy neighbour / resource exhaustion.** No limits today.
- **Operator error.** The same operator holds every tenant; a command meant
  for one tenant can be run in another's directory. Mitigation: the compose
  project directory *is* the tenant; scripts `cd` into `/opt/drupal` inside the
  container and never take a tenant argument.
- **Traefik misrouting.** Mitigation: `trusted_host_patterns` makes Drupal
  reject a request whose Host header is not its own tenant, so a misrouted
  request fails instead of rendering the wrong tenant.
- **Shared mail identity.** Per-tenant SMTP credentials and sender domains are
  a backlog item.

Inside one tenant, access control is Drupal's: roles and permissions, node
access, and the group module (`group` + `flexible_permissions`) for
members-only workspaces. uid 1 does **not** bypass group access; the install
script grants the administrator role the group overview permissions
explicitly. That layer is covered by the module's own tests and by the
`group_access` check below; it is intra-tenant, not tenant isolation.

## 4. Verifying a tenant

```bash
cd ~/docker/<tenant>
docker compose exec -T drupal drush aabenintra:verify-isolation            # table
docker compose exec -T drupal drush aabenintra:verify-isolation --format=json --strict
```

Exit code 1 when any check fails (and on warnings with `--strict`). The
checks, all read-only, print names and hosts but never secret values:

| Check | Verifies | Status rules |
| --- | --- | --- |
| `database` | DB user is not `root`; `DB_HOST` is not `localhost`/`127.0.0.1`/a generic shared name; no table prefix; naming contract (section 5) | fail / fail / fail; contract deviations warn |
| `cache_prefix` | `cache_prefix` set when `redis.connection` is configured; Redis host is tenant-specific | skipped until Redis is enabled; fail without prefix; warn on a shared-looking host |
| `hash_salt` | non-empty, not the `.env.example` placeholder, 32+ chars | fail / fail / warn |
| `private_files` | set, exists, writable, outside the docroot | fail |
| `public_files` | `public://` and `files/styles` writable | fail |
| `trusted_hosts` | exactly one valid pattern | fail on none or invalid (`TRUSTED_HOSTS` unset gives a `false` entry), warn on several |
| `settings_local` | no `settings.local.php` in the site directory | fail (it could re-point the DB or loosen trusted hosts without a trace in git) |
| `session_cookie` | cookie scope derives from the host; `cookie_domain` not a parent domain | fail on a leading-dot domain, warn if domain differs from host |
| `group_access` | `group`, `gnode`, `flexible_permissions`, `aabenintra_groups` enabled and group types exist | fail |

Run it once at onboarding (keep the output with the tenant record) and after
any change to `settings.php`, `docker-compose.yml` or `.env`.
`drush aabenintra:validate` (post-deploy health) shares the files and
trusted-hosts checks; see `docs/DEPLOYMENT.md`.

Automated coverage: the check framework has unit tests
(`tests/src/Unit/Check`) and kernel tests for individual checks
(`tests/src/Kernel`); they run in CI once the PHPUnit job lands. A test that
boots two Drupal sites and proves one cannot query the other is not
meaningful for a container-per-tenant deployment and is deliberately not
part of the suite; the boundary is verified on the real tenant with the
command above.

## 5. Naming contract for a tenant stack

Pick one slug per tenant (lowercase, `[a-z0-9_]`, e.g. `aabenintra` for the
showcase, `<kommune>_intra` for a customer). Then:

| Item | Value |
| --- | --- |
| Compose project directory | `~/docker/<tenant host>/` (the directory name is the Traefik host) |
| `container_name` | `<slug>_drupal`, `<slug>_db`, `<slug>_redis` |
| `DB_NAME`, `DB_USER` | `<slug>` |
| `DB_HOST` | `<slug>_db` |
| `REDIS_HOST` | `<slug>_redis` |
| `cache_prefix` | `<slug>_` |
| Internal network | compose-default `<project>_internal`, not `external` |
| Backups directory | `~/backups/<tenant host>/` |

`aabenintra_ops.settings: tenant_slug` may be set when the slug is not the
database name; by default the `database` check derives the slug from
`DB_NAME` and warns when the user or host do not follow it.

## 6. Future hardening

In rough order of value for the effort:

1. **Per-tenant resource limits** in compose (`deploy.resources.limits` or
   `mem_limit`/`cpus`) so one tenant cannot starve the others.
2. **Per-tenant SMTP identity**: own relay credentials and sender domain with
   SPF/DKIM per customer.
3. **Rootless Docker or user namespace remapping** (`userns-remap`) so a
   container root is an unprivileged host user; requires re-owning the bind
   mounts.
4. **Read-only root filesystem** for the Drupal container with explicit
   writable mounts (`files`, `private`, `/tmp`), and `no-new-privileges`.
5. **Traefik per-tenant middlewares** (rate limits, IP allowlists for admin
   paths) referenced from each tenant's compose labels.
6. **Separate deploy credentials per tenant** when tenants move to their own
   hosts (self-hosting), so a pipeline compromise is bounded.
7. **Enable the Redis backend** (the settings.php block is commented out) and
   then `cache_prefix` per the contract; the `redis` and `cache_prefix` checks
   switch from skipped to active.
