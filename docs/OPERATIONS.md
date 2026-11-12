# Operations

Entry points for running an AabenIntra tenant in production. Each page is
self-contained; this one only says where to look.

| Topic | Page | Scripts |
|---|---|---|
| First install of a tenant | `scripts/install_prod.sh` (header comment) | `install_prod.sh` |
| Ongoing deploys | `.github/workflows/deploy.yml`, `scripts/deploy_post.sh` | `deploy_post.sh` |
| Edge protection: rate limits, body caps, WAF | [TRAEFIK-HARDENING.md](TRAEFIK-HARDENING.md) | `docker-compose.hardening.yml` |
| Backups, restore, RTO/RPO, monthly restore test | [BACKUPS.md](BACKUPS.md) | `backup_tenant.sh`, `restore_tenant.sh` |

Conventions that apply to all of it: secrets live only in the tenant
`.env` and the operator's password manager; nothing in this repo or its
backups should ever contain them. Scripts run on the Docker host with the
deploy user, no sudo. Tenant-specific names (hostname, slug) come from
`.env`, never from edits to committed files.
