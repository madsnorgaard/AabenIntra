# Islands: adding progressive-enhancement JS

An "island" in AabenIntra is a small piece of client-side behaviour that
upgrades server-rendered markup. The page is complete and usable without it;
the island removes a round trip, keeps state in place and talks to a private
JSON endpoint under `/aabenintra/*`. There is no SPA, no client-side router and
no framework runtime. Islands that ship today:

| Island | Markup owner | Script | Endpoint |
| --- | --- | --- | --- |
| Channel follow button | `aabenintra_channels` templates | `aabenintra_channels/js/follow.js` | `POST /aabenintra/follow/{taxonomy_term}` |
| Reactions bar | `aabenintra_social` `hook_node_view` + template | `aabenintra_social/js/reactions.js` | `POST /aabenintra/react/{node}/{reaction}` |
| Mandatory-read acknowledgement | `aabenintra_compliance` `hook_node_view` | `aabenintra_compliance/js/acknowledge.js` | `POST /aabenintra/acknowledge/{node}` |
| Personalise panel, nav drawer, dashboard reorder/pin | theme `page.html.twig`, `aabenintra_dashboard` template | theme `src/js/main.js` (built to `dist/main.js`) | `PATCH /aabenintra/preferences` |

This page walks through the channel follow button, which is the cleanest
example, and states the rules every new island must follow. Reactions is the
same pattern with a richer payload.

## Anatomy of the follow island

### 1. Server renders the final state

`aabenintra_channels/templates/aabenintra-channel.html.twig`:

```twig
<button class="ai-follow{{ following ? ' is-following' : '' }}" type="button"
        data-topic="{{ tid }}" aria-pressed="{{ following ? 'true' : 'false' }}">
  <span class="ai-follow__label">{{ following ? 'Following'|t : 'Follow'|t }}</span>
  <span class="ai-follow__count" data-follow-count>{{ followers }}</span>
</button>
```

The controller computes `following` and `followers` on the server, so the
first paint is already correct for this user. The data contract between markup
and script is three hooks: the class `.ai-follow` (what the behaviour attaches
to), `data-topic` (the id the endpoint needs) and `data-follow-count` (where the
new count goes). Nothing else about the DOM is assumed.

### 2. Library definition

`aabenintra_channels.libraries.yml`:

```yaml
follow:
  version: VERSION
  js:
    js/follow.js: {}
  dependencies:
    - core/drupal
    - core/drupalSettings
    - core/once
```

Module islands are plain files served by Drupal's aggregator - no build step.
Only the theme's design layer goes through Vite (see [Theme islands](#theme-islands)).

### 3. Attaching the library and the CSRF token

`ChannelsController::followAttachments()` returns the `#attached` array used by
every page that renders a follow button:

```php
'#attached' => [
  'library' => ['aabenintra_channels/follow'],
  'drupalSettings' => [
    'aabenintraChannels' => [
      'csrfToken' => $this->csrfToken->get('aabenintra_channels_follow'),
    ],
  ],
],
```

Each island has its **own** CSRF key (`aabenintra_channels_follow`,
`aabenintra_social_react`, `aabenintra_acknowledge`, `aabenintra_theme_prefs`).
Drupal seeds the token with that key, so a token minted for one island does not
validate for another, and the generic `/session/token` route is of no use here.
Attach the token on the render array that contains the button - it is
per-session, which is why those render arrays also carry the `user` cache
context.

### 4. The behaviour

`aabenintra_channels/js/follow.js`:

```js
((Drupal, drupalSettings, once) => {
  'use strict';

  Drupal.behaviors.aabenintraFollow = {
    attach(context) {
      once('ai-follow', '.ai-follow', context).forEach((btn) => {
        btn.addEventListener('click', async () => {
          const tid = btn.dataset.topic;
          btn.disabled = true;
          try {
            const response = await fetch(
              `${drupalSettings.path.baseUrl}aabenintra/follow/${tid}`,
              {
                method: 'POST',
                headers: {
                  'X-CSRF-Token': drupalSettings.aabenintraChannels.csrfToken,
                  'Accept': 'application/json',
                },
              },
            );
            if (!response.ok) {
              return;
            }
            const data = await response.json();
            btn.classList.toggle('is-following', data.following);
            btn.setAttribute('aria-pressed', data.following ? 'true' : 'false');
            // ... update label and count from data
          } finally {
            btn.disabled = false;
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
```

Points that matter:

- `Drupal.behaviors` + `once()` so the handler attaches exactly once per
  element, including markup that arrives later via AJAX or BigPipe.
- `drupalSettings.path.baseUrl` rather than a hard-coded `/` so the island
  works under a subdirectory install. (`acknowledge.js` predates this and
  hard-codes the path; fix it when you touch it.)
- Disable the control while the request is in flight; re-enable in `finally`.
- On a non-2xx response leave the server-rendered state alone. The user keeps
  a truthful UI and can retry.
- Update the DOM only from the response, never from an optimistic guess. The
  server is the source of truth for counts.

### 5. The endpoint

`aabenintra_channels.routing.yml`:

```yaml
aabenintra_channels.follow:
  path: '/aabenintra/follow/{taxonomy_term}'
  defaults:
    _controller: '\Drupal\aabenintra_channels\Controller\FollowController::toggle'
  methods: [POST]
  requirements:
    _custom_access: '\Drupal\aabenintra_channels\Controller\FollowController::access'
    taxonomy_term: \d+
  options:
    parameters:
      taxonomy_term:
        type: 'entity:taxonomy_term'
```

and the controller validates the token from the header, performs the toggle
through the service and returns the new state:

```php
$token = (string) $request->headers->get('X-CSRF-Token', '');
if (!$this->csrfToken->validate($token, 'aabenintra_channels_follow')) {
  throw new AccessDeniedHttpException('Invalid CSRF token.');
}
$following = $this->follow->toggle($uid, FollowService::TYPE_TOPIC, $tid);
return new JsonResponse([
  'following' => $following,
  'followers' => $this->follow->followerCount(FollowService::TYPE_TOPIC, $tid),
]);
```

Endpoint rules:

- **POST (or PATCH) only**, declared with `methods:`. A GET that mutates state
  is a CSRF hole regardless of tokens.
- **Entity upcasting + regex** in the route (`\d+`, `type: entity:node`) so the
  controller receives a loaded entity or a 404, never a raw id.
- **Access in the route**, not only in the controller: `_permission` for
  simple cases, `_custom_access` when the answer depends on the target entity
  (reactions require `$node->access('view')`). Return cacheable access results
  (`cachePerUser()`, `addCacheableDependency($node)`).
- **CSRF validated in the controller** against the island's key. Decide the
  error shape and keep it: follow and react throw `AccessDeniedHttpException`
  (Drupal's standard 403 page), acknowledge and preferences return
  `JsonResponse(['error' => ...], 403)`. New islands should return the JSON
  shape; see [API.md](API.md) for the documented contract.
- **Idempotent service layer.** Follow, reaction and receipt writes all use
  `merge()` on a unique key, so a retried request cannot duplicate a row.
- **Invalidate cache tags** after the write (`aabenintra_follow:{uid}`,
  `aabenintra_channels`, `aabenintra_activity`; reactions invalidate the
  node's tags). Everything that renders the state must list those tags.

## Cacheability and personalised fragments

Render arrays that embed a per-user state must declare it. The two patterns in
use:

- **Per-user, cacheable.** The reactions bar uses
  `'#cache' => ['contexts' => ['user'], 'tags' => $node->getCacheTags()]`.
  The surrounding node render cache splits per user, which is acceptable for
  an intranet with hundreds of users, not thousands.
- **Per-user, never cached.** The mandatory-read banner uses
  `'#cache' => ['contexts' => ['user'], 'max-age' => 0]` because the receipt
  must be re-checked on every request.

For anything heavier, do not put the fragment in the page render cache at
all. Use a lazy builder so the expensive, shared page body is cached once and
the personalised part is filled in afterwards:

```php
$build['follow_button'] = [
  '#lazy_builder' => ['aabenintra_channels.lazy:followButton', [$tid]],
  '#create_placeholder' => TRUE,
];
```

with a service tagged for `TrustedCallbackInterface`. With `big_pipe` enabled
(it is, via Standard) the placeholder streams after the rest of the page. Prefer
the hashed contexts in `aabenintra_theme` (`aabenintra_user_prefs`,
`aabenintra_user_groups`) over a raw `user` context when the output only
depends on the user's preferences or org unit + memberships, so users in the
same bucket share cache entries.

The read side belongs on the page the island lives on, not on the endpoint.
The POST endpoints return fresh JSON and are not cached; none of them sets
cache headers and none should.

## No-JS fallback

Every island must degrade to something correct and usable:

- The follow button and the reactions bar render the current state and counts
  without JS. Clicking does nothing - acceptable because nothing lies. A better
  fallback, and the target for new islands, is a real `<form method="post">`
  whose action is a normal form route that redirects back; the script then
  intercepts `submit` instead of `click`.
- The dashboard template is "server-rendered fallback" by design: tiles,
  sizes, pin state and order are all computed in `DashboardController` from
  the user's saved preferences. The island adds drag-to-reorder and in-place
  pinning only.
- Server-side sorting and filtering (directory `?q=`, `?unit=`, `?skill=`)
  are query parameters first. Client-side filtering is an enhancement that
  must produce the same result the URL would.

If the no-JS version would be wrong (stale count, wrong `aria-pressed`), fix
the server side first.

## Accessibility

- Toggles are `<button type="button" aria-pressed="...">`, never `<a>` or
  `<div>`. Update `aria-pressed` from the response.
- Group related buttons with `role="group"` + `aria-label` (reactions bar).
- Live totals use `aria-live="polite"` so screen readers hear the new count
  without focus moving.
- Emoji and decorative glyphs are `aria-hidden="true"`; the accessible name
  comes from text or `title`/`aria-label`.
- Disabling the control during the request is a visible busy state; do not
  remove focus from it. Re-enable in `finally`.
- Drag-to-reorder on the dashboard is mouse-only today; pin/unpin is the
  keyboard path to the same outcome. Any new reorder UI needs a keyboard
  alternative (#100).
- Panels and drawers manage `aria-expanded` on the toggle, `aria-controls` to
  the panel id, and close on `Escape`.

## Theme islands

The theme's `src/js/main.js` is built by Vite into `dist/main.js` and loaded
through the `aabenintra/global` library. It owns UI that exists on every page:
the Personalise panel, the nav drawer and the dashboard grid. It reads
`drupalSettings.aabenintra` (`prefs`, `endpoint`, `csrfToken`) provided by
`aabenintra_theme_page_attachments()`, applies preferences to `<html>` on first
paint, and persists with one debounced `PATCH` carrying the **full** state so
out-of-order responses cannot lose an update.

`main.js` does not use `Drupal.behaviors` - it runs once on `DOMContentLoaded`
because the elements it enhances are page chrome that never arrives via AJAX.
That is a deliberate exception. If you add theme-level behaviour that can
appear inside AJAX-loaded content, write it as a behaviour.

After changing `src/`, rebuild and commit `dist/`:

```bash
cd web/themes/custom/aabenintra
npm install
npm run build
git add dist
```

`dist/` is the production transport; the Docker image does not run Node
(issue #56 tracks a CI gate that fails the PR when `dist/` is stale).

## Checklist for a new island

1. Server renders the final state in Twig with `data-*` hooks and ARIA state.
2. Library in `<module>.libraries.yml` depending on `core/drupal`,
   `core/drupalSettings`, `core/once`.
3. Attach the library and a CSRF token under a module-specific key via
   `#attached`, on the render array that contains the control, with the
   `user` cache context (or a hashed context) and the right cache tags.
4. `Drupal.behaviors.<name>` + `once()`; fetch with `X-CSRF-Token`,
   `credentials: 'same-origin'`, base-URL aware; disable while in flight;
   update the DOM only from the response.
5. Route: `methods: [POST]`, entity upcasting with `\d+`, access requirement,
   controller validates the token and returns `JsonResponse`. Errors as
   `{"error": "..."}` with the HTTP status.
6. Service writes are idempotent (`merge()` on a unique key) and invalidate
   the cache tags that every reader declares.
7. No-JS path is correct. Keyboard path exists. `aria-pressed` / `aria-live`
   maintained.
8. Danish strings added to
   `web/modules/custom/aabenintra_i18n/translations/aabenintra.da.po`.
9. Endpoint documented in `docs/openapi.yaml` and [API.md](API.md).
