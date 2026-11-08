# GDPR tooling: data export and right to erasure

AabenIntra ships the `aabenintra_gdpr` module so a tenant can answer
data-subject requests under GDPR Art. 15 (access), Art. 17 (erasure) and
Art. 20 (portability) without touching the database by hand. This page
describes what is exported, what the two erasure modes do, the retention
defaults, how to run the commands and how the self-service flow works.

The module owns no personal data itself. Every source is wrapped in a
*collector* (`GdprDataCollectorInterface`, tagged `aabenintra_gdpr.collector`)
that is active only when its owning module is installed. Feature modules do
not depend on `aabenintra_gdpr`; the dependency points the other way.

A Danish summary follows at the end ("Dansk resumé").

## What is exported

The export is a single JSON document (`schema_version: 1`) with three top
level keys:

| Key | Content |
| --- | --- |
| `exported_at` | ISO 8601 timestamp (UTC) |
| `subject` | `uid`, `name`, `mail`, `langcode`, `created`, `access`, `roles`, `status` |
| `sections` | One entry per active collector: `module`, `label`, `count`, `items[]` |

Sections and their sources:

| Section | Owning module | Items |
| --- | --- | --- |
| `profile` | user (fields from recipes and `aabenintra_directory`) | Every non-empty configurable user field: primary org unit, location, job title, phone, skills, photo, picture, and any field a tenant adds later. References are exported as id + label; files as filename, uri and URL. |
| `content` | node | Nodes the user authored (story, page, event, document, kudos given, ...): nid, type, title, status, created, changed, langcode, body. |
| `comments` | comment | Comments the user wrote: cid, commented entity, subject, body, status, created. |
| `files` | file | Managed files the user uploaded: fid, filename, uri, mime, size, created. |
| `kudos_received` | aabenintra_kudos | Kudos where the user is `field_recipient`: badge, message, given by, created. |
| `reactions` | aabenintra_social | Rows in `aabenintra_reaction`: entity, reaction type, created. |
| `follows` | aabenintra_channels | Rows in `aabenintra_follow`: target type, target id, created. |
| `read_receipts` | aabenintra_compliance | Rows in `aabenintra_read_receipt`: nid, langcode, created. |
| `activity` | aabenintra_activity | Rows in `aabenintra_activity` where the user is actor or target. |
| `preferences` | aabenintra_theme | The `user.data` preferences blob (accent, colour scheme, density, tile order). |
| `group_memberships` | group | Workspace memberships (group, roles, since) and groups the user owns. |
| `export_requests` | aabenintra_gdpr | The user's own export requests and their status. |

Not exported: watchdog/dblog entries (operational log, see retention below),
session rows, cache entries and search indexes (derived data), and Redis
keys (volatile).

Exports are written to `private://gdpr-exports/<uid>-<timestamp>.json`.
They are never registered as managed files and `hook_file_download()` denies
every direct request to that directory; the only way to fetch one over HTTP
is the one-time link described under "Self-service requests".

## Erasure modes and what survives

`aabenintra:gdpr-erase` runs every active collector first (while the uid is
still valid), then handles the account, then writes an audit row.

| Source | `--mode=anonymise` | `--mode=delete` |
| --- | --- | --- |
| Account row | Kept, blocked. Name becomes `deleted-user-<uid>`, mail `deleted-<uid>@invalid.invalid`, password randomised, roles removed, timezone cleared, all configurable fields emptied. | Deleted through core's `user_cancel_delete` path (`_user_cancel()`), which fires `hook_user_predelete()`. |
| Profile fields | Emptied; picture/photo file entities deleted. | Same, then the account is deleted. |
| Authored content | Kept. Author and revision author of every revision reassigned to `reassign_uid` (default 0 = anonymous, the same outcome as core's `user_cancel_reassign`). | Deleted by core's node `hook_user_predelete()`. |
| Comments | Kept, reassigned; stored author name replaced by the site's anonymous label, mail/homepage cleared. | Deleted by core's comment `hook_user_predelete()`. |
| Uploaded files | Owner reassigned (files are shared resources referenced from content). | Owner reassigned; files that lose their last usage become temporary and file cron removes them. |
| Kudos given | Treated as authored content (above): kept and anonymised so the recipient keeps their recognition. | Deleted with the other content. |
| Kudos received | Kept; `field_recipient` now points at the pseudonymised account. | Deleted - the kudos is *about* the subject. |
| Reactions, follows, activity rows | Deleted. | Deleted. |
| Mandatory-reading receipts | **Kept**, attached to the pseudonymised account (see below). | Deleted; the count is retained in the erasure log. |
| Interface preferences and all other `user.data` | Deleted. | Deleted. |
| Group memberships | Deleted. Groups the user owns are reassigned to `reassign_uid`, or uid 1 when that is 0 (what the group module does on cancel). | Same. |
| Own export requests and files | Deleted. | Deleted. |

Free text written by *other* people (a story that mentions the subject by
name, a comment thread) is not touched by either mode. That is an editorial
task; the export tells you where to look.

### Why receipts survive anonymisation

A read receipt proves that an employee acknowledged a mandatory instruction
(a safety procedure, an information-security policy). The employer needs
that evidence for as long as it may have to show compliance, which is a
legal-obligation / legitimate-interest ground under Art. 17(3)(b) and (e).
After anonymisation the row still carries the uid, but the uid now resolves
to `deleted-user-<uid>` with no name, mail or profile data, so the receipt is
pseudonymous rather than personal. The compliance report keeps counting it.

Proposal for a later iteration of `aabenintra_compliance`: add a
`subject_hash` column and let the GDPR eraser rewrite `uid` to 0 while
storing the salted hash, so even the join to the placeholder account
disappears. The module already computes that hash (`Anonymizer::subjectHash()`,
SHA-256 of site hash salt + uid) for the erasure log.

### Safety rails

- uid 0 can never be erased. uid 1 and any account holding the
  `administrator` role are refused unless `--force` is given.
- `--dry-run` reports per-source counts and the account action without
  changing anything, and writes no audit row.
- The interactive command asks for confirmation unless `--dry-run` or
  `-y` is used.
- Every real erasure writes a row to `aabenintra_gdpr_erasure` with the
  hashed subject id, mode, per-source counts, who ran it and when. The table
  holds no personal data and is shown on `/admin/people/gdpr-requests`.

## Retention policy

Defaults the module enforces or that we propose. Values marked *proposed*
are not enforced by code; the operator must confirm them with their DPO and
document the decision in the tenant's record of processing (Art. 30).

| Data | Default | Enforced by | Status |
| --- | --- | --- | --- |
| Export files in `private://gdpr-exports` | 7 days | `aabenintra_gdpr` cron (`retention_days`) | enforced |
| Export request rows | Kept until the user is erased; a `ready` row expires with its file | `aabenintra_gdpr` cron | enforced |
| Erasure log (`aabenintra_gdpr_erasure`) | Indefinite - pseudonymous accountability record | - | enforced (no personal data) |
| Self-service request rate | 1 request per 24 h per user | `request_rate_limit_hours` | enforced |
| Mandatory-reading receipts | Lifetime of the employment + the period the instruction is auditable; delete on account deletion | manual / `--mode=delete` | proposed |
| Activity feed rows | 24 months | - | proposed; add a cron purge to `aabenintra_activity` |
| Audit log (dblog/watchdog) | 12 months | `dblog` row limit is count-based, not time-based | proposed; use syslog shipping with a 12-month bucket policy |
| Blocked / inactive accounts | Anonymise after 6 months of inactivity following off-boarding | HR process + `aabenintra:gdpr-erase` | proposed |
| Sessions | 23 days (core default `gc_maxlifetime`) | core | enforced |
| Backups containing personal data | Rotation of the hosting backup set (see contabo-infrastructure docs) | hosting | document per tenant |

## Running the commands

```sh
# Where does this user's data live, and how much?
drush aabenintra:gdpr-inventory 42
drush aabenintra:gdpr-inventory jane@example.org --format=json

# Export (default path: private://gdpr-exports/<uid>-<timestamp>.json)
drush aabenintra:gdpr-export 42
drush aabenintra:gdpr-export jane@example.org --output=/tmp/jane.json
drush aabenintra:gdpr-export 42 --output=- > jane.json

# Erasure: always dry-run first
drush aabenintra:gdpr-erase 42 --mode=anonymise --dry-run
drush aabenintra:gdpr-erase 42 --mode=anonymise -y
drush aabenintra:gdpr-erase 42 --mode=delete --dry-run
drush aabenintra:gdpr-erase 42 --mode=delete -y
drush aabenintra:gdpr-erase 1 --mode=anonymise --force   # refused without --force
```

The subject argument accepts a uid, an e-mail address or an account name.
All three commands exit non-zero when the user cannot be resolved, the mode
is unknown, the erasure is refused or the export cannot be written.

In the production container:

```sh
docker compose exec drupal drush aabenintra:gdpr-inventory jane@example.org
```

Settings live in `aabenintra_gdpr.settings` (`/admin/config/people/gdpr`):

| Key | Default | Meaning |
| --- | --- | --- |
| `retention_days` | 7 | Days an export file and its download link stay valid |
| `reassign_uid` | 0 | Account that inherits content on anonymisation (0 = anonymous) |
| `request_rate_limit_hours` | 24 | Minimum gap between two self-service requests from one user |

## Self-service requests

Users holding the permission `request own gdpr export` can request their
own export without involving an administrator. The permission is only
*defined* by the module; grant it to the authenticated role in the tenant's
recipe or install script if you want it on by default.

1. The client sends `POST /api/gdpr/export-requests` (empty JSON body) with
   the session cookie and the header `X-CSRF-Token: <token>` where the token
   comes from `GET /session/token`. The route uses core's
   `_csrf_request_header_token` requirement, so the same token works as for
   JSON:API and REST.
2. The server answers `202` with the request id, or `429` with a
   `Retry-After` header when the rate limit applies. Only the *current*
   user's data can be requested; there is no uid parameter.
3. A queue item (`aabenintra_gdpr_export`) is created. Core cron drains the
   queue, runs the exporter and marks the request `ready`.
4. The user receives an e-mail (mail key `export_ready`) with a link of the
   form `/gdpr/export/<token>`. The token is random (256 bit), only its
   SHA-256 hash is stored, and the link:
   - requires the user to be signed in as the account that made the request
     (anyone else gets 403, unknown tokens get 404),
   - works once (status becomes `downloaded` and the hash is cleared
     before the file is streamed),
   - expires with the file after `retention_days`.
5. `GET /api/gdpr/export-requests` returns the caller's requests with
   status, timestamps and failure message, so a profile page can show
   "your export is being prepared".

Administrators with `administer gdpr requests` see every request and the
erasure log on `/admin/people/gdpr-requests` (plain table, no Views).

Failures (for example a missing private file system) mark the request
`failed` with the message, log an error and drop the queue item; the user
can request again after the rate-limit window.

## Mapping to GDPR articles

| Article | Right | What AabenIntra provides |
| --- | --- | --- |
| Art. 15 | Access | `aabenintra:gdpr-export` and the self-service request produce a complete, human-readable JSON of what is held, by source, with the purpose implied by the owning module. The `inventory` command answers "which systems hold data about this person". |
| Art. 16 | Rectification | Not tooled; users edit their own profile, editors edit content. |
| Art. 17 | Erasure | `aabenintra:gdpr-erase` in two modes; the erasure log provides the accountability record; receipts are retained under Art. 17(3)(b)/(e) and documented above. |
| Art. 18 | Restriction | Partially: blocking the account (core) stops processing for login; no field-level restriction. |
| Art. 20 | Portability | The export is JSON, UTF-8, pretty-printed, with a declared `schema_version`, suitable for transmission to another controller. |
| Art. 30 | Record of processing | The collector list (`gdpr-inventory` without a subject) is the technical inventory of personal-data stores to cite in the tenant's record. |
| Art. 32 | Security | Exports live in the private file system, are served once, to the owner only, and are purged on a timer. |

## Extending

A module that stores personal data registers one service:

```yaml
services:
  mymodule.gdpr_collector:
    class: Drupal\mymodule\Gdpr\MyCollector
    arguments: ['@module_handler', '@database']
    tags:
      - { name: aabenintra_gdpr.collector }
```

Extend `TableCollectorBase` for a ledger table (declare `table()`,
`uidColumns()`, `exportColumns()`), or implement
`GdprDataCollectorInterface` directly. Keep `applies()` honest: it must
return `FALSE` when the data store is absent so the GDPR module keeps working
on sites that do not install your module.

## Dansk resumé

`aabenintra_gdpr` giver kommunen værktøjer til at efterleve den
registreredes rettigheder efter databeskyttelsesforordningen:

- **Indsigt og dataportabilitet (art. 15 og 20):** `drush
  aabenintra:gdpr-export <uid|mail>` skriver en samlet JSON-fil med
  profilfelter, indhold, kommentarer, filer, kudos, reaktioner, fulgte
  kanaler, kvitteringer for obligatorisk læsning, aktivitetsrækker,
  præferencer og gruppemedlemskaber. Medarbejderen kan selv bestille
  eksporten via `POST /api/gdpr/export-requests`; den dannes af cron og
  sendes som et engangslink på e-mail, der kun virker for den, der har
  bestilt den, og udløber efter 7 dage.
- **Sletning (art. 17):** `drush aabenintra:gdpr-erase <uid|mail>
  --mode=anonymise|delete`. *Anonymise* bevarer kontoen spærret under navnet
  `deleted-user-<uid>`, tømmer alle profilfelter, sletter billeder og
  flytter forfatterskab af indhold til en konfigurerbar konto (standard:
  anonym). *Delete* sletter kontoen og alt dens indhold via Drupals
  kerne-afmelding. `--dry-run` viser antal rækker pr. kilde uden at ændre
  noget. uid 0/1 og administratorer afvises uden `--force`.
- **Kvitteringer for obligatorisk læsning bevares ved anonymisering** som
  dokumentation for, at medarbejderen har bekræftet fx en
  sikkerhedsinstruks (art. 17, stk. 3, litra b og e). Rækken peger
  herefter på den pseudonymiserede konto og indeholder ingen
  identificerende oplysninger. Ved egentlig sletning fjernes rækkerne, og
  antallet gemmes i sletteloggen.
- **Slettelog:** hver sletning skrives til `aabenintra_gdpr_erasure` med en
  saltet hash af bruger-id, tilstand, antal pr. kilde, udfører og tidspunkt.
  Loggen indeholder ingen personoplysninger og vises på
  `/admin/people/gdpr-requests`.
- **Opbevaring:** eksportfiler slettes efter 7 dage (håndhævet). Øvrige
  frister i tabellen ovenfor (aktivitetsfeed 24 måneder, systemlog 12
  måneder, inaktive konti 6 måneder) er forslag, som den dataansvarlige
  skal bekræfte med sin DPO og indføre i fortegnelsen efter art. 30.
