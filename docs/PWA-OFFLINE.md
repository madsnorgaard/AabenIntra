# PWA offline scope and sync design (M12, issue #125)

Design document, no code. Scope: what an installable AabenIntra can do without
a network, how offline writes are queued and reconciled, and what must never
be cached. Target users are frontline staff (hjemmepleje, teknik og miljø,
skoler) who read policies and news on a phone with intermittent coverage. The
PWA itself is #102; push notifications are #103.

## Principles

1. **Read offline, write queued, never guess.** Content that was fetched can
   be read again. Writes go into an outbox and are replayed. The UI never
   pretends a queued write has succeeded.
2. **Per user, per device, per tenant.** Everything cached is namespaced by
   tenant host and user id. Logging out wipes it.
3. **Server stays the source of truth.** Offline state is a cache of what the
   server said, plus a list of what the user asked for. Conflicts are resolved
   by server rules, and the client is told.
4. **Private files are never stored offline.** Documents behind
   `/system/files/` (the private file system at `/opt/drupal/private`) are
   fetched live, every time.

## Content matrix

| Surface | Route(s) today | Offline strategy | Why |
| --- | --- | --- | --- |
| News feed | `/news` | cache-first, stale-while-revalidate, last 50 items | Read-heavy, changes a few times a day, personalised only by follows (which are also cached) |
| Story, Document, Page, Event, Tip full view | `/node/{nid}`, aliases | cache-first, SWR, on visit and on prefetch of the first screen of the news feed | The core "read the policy on the bus" case |
| Channel pages | `/channels`, `/channels/{tid}` | cache-first, SWR | Same as news |
| Images (public image styles) | `/sites/default/files/styles/*` | cache-first, size-capped LRU (see `assets`) | Visual completeness; derivative URLs are content-addressed enough to cache safely |
| Theme assets | `dist/main.css`, `dist/main.js`, fonts, module CSS/JS | precache at install, versioned by the deploy sha | Required for the shell to render |
| Dashboard | `/dashboard` | network-only; offline shows the cached news feed with a banner | Tile order, pins, org scope and follows combine per request; a stale dashboard misleads |
| Search | future `/search` (M3) | network-only with an offline notice | Solr lives on the server; a local index is out of scope |
| People directory, org chart, experts | `/directory`, `/org-chart`, `/experts` | network-only with an offline notice; optionally cache the last viewed profile page | Contains phone numbers and photos of colleagues; cached personal data on a lost phone is a GDPR exposure we choose not to take |
| Activity feed | `/activity` | network-only | Low value stale; high churn |
| Group landing pages | `/group/{id}` | network-only | Membership and secret-group access are evaluated per request; caching could show a page to someone who was removed |
| Mandatory-read acknowledgement | `POST /aabenintra/acknowledge/{node}` | **queued write** | The reader did read it offline; the receipt must not be lost |
| Reactions | `POST /aabenintra/react/{node}/{reaction}` | **queued write** | Low stakes, high engagement value |
| Channel follow | `POST /aabenintra/follow/{tid}` | **queued write** | Same as reactions |
| Theme preferences | `PATCH /aabenintra/preferences` | already offline-tolerant: `localStorage` applies instantly, server write is best-effort | Existing behaviour in `main.js`; move the pending write into the outbox so it survives a page close |
| Comments | core comment form | **drafts kept locally, never auto-synced** | Text written offline is reviewed by the author before it posts; a comment on a thread that moved on needs a human look |
| Node create / edit, Kudos give | node forms | not supported offline (network-only, form disabled with notice) | Editorial workflows, revisions and media uploads do not belong in a queue |
| Admin, reports, CSV | `/admin/*` | never cached, network-only | Out of scope and contains aggregate personal data |
| Private files | `/system/files/*` | **never cached**, network-only | Access is checked per request by Drupal; a cached copy bypasses it |

"Cache-first, SWR" means: serve from cache if present, fetch in the background,
update the cache, and let the page know a newer version exists (a quiet
"Updated - reload" affordance, not an automatic re-render of what the user is
reading).

## Service worker: contrib `pwa` module vs custom

Two viable routes; the recommendation is the second.

| | `drupal/pwa` (contrib) | Custom service worker in the theme |
| --- | --- | --- |
| Manifest | generated from config UI (name, colours, icons) | hand-written `manifest.webmanifest` in the theme, tenant branding from theme settings (#54) |
| Caching | generic: precache a URL list + offline fallback page; one strategy for everything | per-route strategies as in the matrix above |
| Writes | none | outbox + Background Sync |
| Per-user namespace / logout wipe | not built in | required, straightforward to implement |
| Deploy coupling | module update cadence | versioned with `dist/` by the same Vite build that already ships the theme |
| Maintenance | upstream carries it | we carry ~400 lines of SW code and its tests |

The matrix needs per-route strategies, queued writes and a strict "never cache
private files" rule. That is beyond what the contrib module is designed for,
and wrapping it would mean fighting its cache list. A custom
`sw.js` built by Vite into the theme's `dist/` (served from the site root via a
small route or an Apache alias so its scope is `/`) keeps the SW versioned with
the assets it precaches. Reuse `drupal/pwa` only for the manifest if the theme
settings route proves slower to ship than expected.

## IndexedDB schema

Database name: `aabenintra:<tenant_host>:<uid>`. One database per user per
tenant so that logout can delete it with `indexedDB.deleteDatabase()` and a
shared device never leaks between accounts. Version 1 has four object stores.

### `content`

Rendered HTML plus the metadata needed to decide freshness.

| Field | Type | Notes |
| --- | --- | --- |
| `url` (key) | string | Path including language prefix (`/da/node/42`); the two translations are two records |
| `nid` | int | For invalidation by entity |
| `bundle` | string | story, document, page, event, tip |
| `html` | string | The full response body as served (so the shell renders exactly what the server rendered) |
| `etag` | string | From the response; sent back as `If-None-Match` on revalidation |
| `fetchedAt` | int | ms epoch |
| `changed` | int | Node `changed` timestamp parsed from a `<meta name="aabenintra:changed">` tag the theme adds to node pages |
| `mandatory` | bool | Whether the page carried the acknowledgement banner when cached |
| `private` | bool | Always `false`; a response containing a `/system/files/` link is still cached, the links are not |

Index: `by_fetchedAt` (LRU eviction), `by_nid`.

### `assets`

Images and public files referenced by cached content.

| Field | Type | Notes |
| --- | --- | --- |
| `url` (key) | string | Full path under `/sites/default/files/styles/` |
| `blob` | Blob | The bytes |
| `size` | int | For the budget |
| `lastUsed` | int | LRU |

Budget: 50 MB per database by default, configurable per tenant; evict by
`lastUsed`. Only `image/*` responses from the public files path are stored.
Anything under `/system/files/` is refused at write time regardless of type.

### `outbox`

Queued writes, replayed in order.

| Field | Type | Notes |
| --- | --- | --- |
| `id` (key, auto) | int | Replay order |
| `kind` | string | `acknowledge`, `react`, `follow`, `preferences` |
| `url` | string | The endpoint path |
| `method` | string | `POST` or `PATCH` |
| `body` | string or null | JSON body for preferences |
| `csrfKey` | string | Which `drupalSettings` token to use at replay time (tokens are per session; a replay after re-login needs a fresh one) |
| `createdAt` | int | Client timestamp; sent as `X-AabenIntra-Client-Time` for the server to log, never to decide |
| `attempts` | int | Backoff counter |
| `state` | string | `pending`, `sending`, `failed`, `conflict` |
| `lastError` | string or null | For the "Pending changes" panel |

Index: `by_state`.

Coalescing rules at enqueue time: a `preferences` entry replaces any pending
`preferences` entry (full-state PATCH, last one wins); a `react` on the same
node replaces a pending `react` on that node; a `follow` on the same term
toggles a pending `follow` on that term away (two toggles cancel). Acknowledge
is never coalesced away - it is only ever added once per `(nid, langcode)`.

### `meta`

Key-value store for the client's own bookkeeping: `uid`, `langcode`,
`deploySha` (from a `<meta name="aabenintra:build">` tag; a mismatch triggers
a precache refresh), `lastSyncAt`, `lastLogoutAt`, `cacheBudgetBytes`.

## Sync queue and Background Sync

1. On any write while offline (or when the fetch fails with a network error),
   the island writes to `outbox` and updates the UI to a **pending** state
   that is visually distinct from done: e.g. the "I have read this" button
   becomes "Will be confirmed when online", `aria-live` announces it.
2. The island registers a Background Sync tag (`aabenintra-outbox`). Browsers
   without Background Sync (Safari) fall back to replay on the next page load
   and on `online` events.
3. The SW `sync` handler drains the outbox in `id` order, one request at a
   time, using the token for `csrfKey` from the latest `drupalSettings` the
   page shared with it via `postMessage`. If the session has expired (a `403`
   with the HTML login page, or a redirect to `/user/login`), the drain stops,
   the entries stay `pending`, and the page shows "Sign in to send 3 pending
   changes".
4. Success: delete the entry; broadcast `{kind, result}` so any open page
   applies the server's response (counts, following, prefs) exactly as the
   online island would.
5. Server error `5xx` or network failure: `attempts++`, exponential backoff
   (1 min, 5 min, 30 min, then hourly), stay `pending`. After 7 days, mark
   `failed` and surface it.
6. `4xx` other than session expiry: mark `conflict` or `failed` per the rules
   below; never retry a `400`/`404`.

The replay carries `X-AabenIntra-Client-Time` and an `X-AabenIntra-Replay: 1`
header so the server can log that a receipt arrived late. The acknowledgement
endpoint should store the server time as `created` (as it does today) and may
later gain an `client_created` column for audit; the design does not depend on
it.

## Conflict resolution

| Write | Conflict possible? | Rule | What the user sees |
| --- | --- | --- | --- |
| Acknowledgement | Effectively no. The receipt is a `merge()` on `(nid, uid, langcode)`; two devices acknowledging the same node both end in one row. | **Last write wins, and it is safe**: the latest server time becomes `created`. If the node was unpublished or deleted meanwhile, the replay gets a 404 and the entry is dropped with a quiet notice. | "Confirmed" once synced; nothing if the node disappeared |
| Reaction | Yes: the user reacted on another device, or the node vanished. | **Server state wins.** The endpoint is a toggle, so blindly replaying `like` could remove a `like` set elsewhere. The replay therefore sends the *intended* end state: the SW first does the toggle, compares `mine` to the queued intent, and toggles again if they differ (at most two requests). The returned counts are applied. | Counts update; if the end state differs from what they pressed, a toast "Updated to match your other device" |
| Follow | Yes, same toggle semantics. | Same intent-reconciliation as reactions. | Button reflects server state |
| Preferences | Yes: edited on two devices. | **Server timestamp wins** - the newest PATCH overwrites because the client sends full state; the response is applied. No merge of individual keys. | Panel re-renders with the result |
| Comment drafts | n/a | **Never synced.** Stored in `localStorage` per form, offered back to the author ("You have an unsent comment on this page") when they return online; posting is a normal form submit. | Draft restored, author decides |
| Node edits | n/a | Not supported offline. | Form disabled with notice |

The general rule: idempotent writes replay as-is; toggles replay as intents;
authored text is never auto-posted.

## Security

- **Private files are never cached**, enforced in the SW fetch handler by
  path prefix (`/system/files/`) before any strategy runs, and again at
  `assets` write time. Document nodes are cached (title, body, metadata) but
  the attachment links inside them require network, and the offline page says
  so next to each file link.
- **Per-user database name** (`aabenintra:<host>:<uid>`) so a shared tablet
  cannot show user A's cache to user B even before logout runs.
- **Cache cleared on logout.** The theme adds a `logout` message to the SW on
  the `/user/logout` click and on seeing a response that sets a new session
  for a different uid; the SW deletes the user database and the user-scoped
  Cache Storage entries, leaving only the precached shell.
- **Session expiry stops replay**, never bypasses it. Tokens are per session
  and the SW does not try to refresh them; the page does after login.
- **No credentials in IndexedDB**: no CSRF tokens, no cookies, no emails. The
  `csrfKey` field is the key *name*, not a token.
- **CSP** (`docs/SECURITY-HEADERS.md`) must allow `worker-src 'self'` and the
  manifest; nothing else changes.
- **Storage is opportunistic.** `navigator.storage.persist()` is requested;
  if denied, the browser may evict and the app must behave correctly with an
  empty cache (it does: everything degrades to network-only).
- **Content cached is content the user was allowed to see at fetch time.**
  Revoked access (removed from a group, node unpublished) is honoured on the
  next revalidation; the SWR window is bounded to 24 hours for node pages and
  1 hour for lists, after which the cached copy is served only with an
  explicit "offline copy from <time>" label.

## Integration test scenarios

Playwright with `context.setOffline(true)` and a Drupal functional backend.
Each scenario is a test to write when #102 is implemented.

1. **Read offline.** Visit `/news` and a story online; go offline; reload
   both; content renders with an "offline copy" label; images cached appear,
   uncached ones show alt text.
2. **Private file is not cached.** Visit a Document with a private attachment
   online; go offline; the page renders, the attachment link shows "requires
   connection"; `/system/files/...` is not in Cache Storage or `assets`.
3. **Acknowledge offline, sync on reconnect.** Open a mandatory story offline;
   press "I have read this"; button shows pending; go online; receipt exists
   in `aabenintra_read_receipt` with server `created`; banner gone on reload.
4. **Acknowledge replay after node deleted.** Queue an acknowledgement; delete
   the node server-side; reconnect; outbox is empty, no error toast beyond a
   quiet notice, no 500 in logs.
5. **Reaction intent reconciliation.** Device A (online) sets `like`. Device B
   offline presses `like` (intent: like). Reconnect B. Server state is `like`
   (one toggle, not two); counts on B match server.
6. **Reaction switch.** B offline presses `celebrate` while server has `like`;
   on reconnect server ends at `celebrate`.
7. **Follow toggles cancel.** Offline: follow, then unfollow the same channel;
   outbox is empty; nothing is sent on reconnect.
8. **Preferences last-write-wins.** Set accent on A, then on B offline; B
   reconnects; server has B's accent; A sees it on next load.
9. **Session expired during replay.** Queue two writes; expire the session
   server-side; reconnect; nothing is lost, UI shows "Sign in to send 2
   pending changes"; after login, both replay.
10. **Logout wipes.** Cache content as user A; log out; IndexedDB has no
    `aabenintra:<host>:<uidA>` database; Cache Storage holds only the shell;
    log in as B offline-capable pages are empty until fetched.
11. **Shared device isolation.** Cache as A; log in as B on the same browser
    without logout (session replaced); B's first navigation does not show A's
    news; A's database is deleted.
12. **Dashboard offline notice.** Go offline; visit `/dashboard`; banner says
    the dashboard needs a connection and offers the cached news feed; no
    stale tiles are shown.
13. **Deploy invalidates shell.** Bump the build sha; next online load
    precaches new `dist/` and old entries are removed.
14. **Storage eviction.** Fill `assets` past budget; the oldest `lastUsed`
    entries are evicted; content still renders.
15. **Danish and English copies.** Cache `/node/42` and `/da/node/42`; both
    render offline in their language; acknowledging the Danish copy records
    `langcode = da`.

## Open questions

- **Scope of "page".** Should Layout Builder pages (the `page` bundle from
  Standard) be cached? They can embed blocks with per-user content. Proposal:
  cache only if the page carries no `user`-context cache metadata (expose via
  a meta tag).
- **Badge counts on the installed app** (unread mandatory items) need an
  endpoint; defer to #103 (notifications).
- **Native Background Sync coverage** is Chromium-only. Is a 24-hour outbox
  on iOS (replay on next open) acceptable for the acknowledgement use case,
  or do frontline tenants need a "sync now" button? Ask the pilot kommune
  (#110).
- **Cache budget per tenant** - a theme setting, or a tenant `.env` value
  (#44)? Leaning theme setting so the operator can change it without a
  redeploy.
- **Client timestamp retention.** Do we store `client_created` on receipts for
  audit ("read on 3 Nov, synced 5 Nov")? Compliance officers may want it; it
  is also a new personal-data field to document in `docs/GDPR.md`.
- **Comment drafts in `localStorage` vs the user database.** `localStorage`
  survives logout today; move drafts into the per-user database so the logout
  wipe covers them.
- **Reactions intent reconciliation needs two requests in the worst case.** A
  future `PUT /aabenintra/react/{node}` with an explicit body (`{"reaction":
  "like"}` or `null`) would make the replay single-shot and idempotent; this
  is the first candidate for the island-endpoint convention change in
  [API.md](API.md).
