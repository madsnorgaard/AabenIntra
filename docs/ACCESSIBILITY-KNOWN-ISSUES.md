# Accessibility - known issues and accepted exclusions

This file is the human-readable counterpart of
`tests/e2e/lib/known-issues.ts`. Every selector excluded from the axe gate
must have a row here, and every row here must either have a matching
exclusion or be marked as advisory-only (not blocking the gate). Rows are
removed when the fix merges.

The accessibility statements (`ACCESSIBILITY-STATEMENT.da.md`,
`ACCESSIBILITY-STATEMENT.en.md`) point to this file for "non-accessible
content", so keep the wording plain: what a user experiences, not the rule id
alone.

## Blocking rule exclusions (gate stays green because of these)

None.

## Advisory findings (moderate/minor, reported but not failing the gate)

None. The first full gate run (PR #171, 8 November 2026) reported no
moderate or minor violations on any of the covered pages; the one serious
finding (follow-button text at 4.39:1 on the raw accent seed) was fixed in the
same pull request by using the `--accent-fill` token. Future runs list
advisories under the `axe-*-advisory` attachments in the `axe-playwright`
artifact; copy anything that is a real user problem into this table.

| axe rule | Where | Impact | What a user experiences | Issue |
|---|---|---|---|---|

## Known gaps not measured by the gate

| Area | What a user experiences | Issue |
|---|---|---|
| Navigation drawer and Personalise panel | Focus is not trapped inside the open drawer and is not returned to the toggle on close; Escape handling is incomplete. | #100 |
| Dashboard drag-to-reorder | Reordering tiles is pointer-only; the pin button is the keyboard alternative but there is no keyboard way to move a tile. | #100 |
| Search island | Not yet covered by the automated pages; results list and facets need their own axe pass. | #118 (follow-up) |
| Editor-uploaded content | Alternative text quality and PDF accessibility are the tenant's editorial responsibility; the gate only catches missing `alt`. Editoria11y for authors is planned. | #99 |
| Admin UI (Gin) | Covered by Drupal core and the Gin theme's own accessibility work, not by this gate. | - |
| Taxonomy term landing pages on a fresh install | `/taxonomy/term/N` (linked from the "Browse" and "Organisation" navigation) rendered an empty `main` with no heading on a fresh core 11.4 install: the `taxonomy_term` view had not been installed. The production site, installed on an older core, renders the hero correctly. `install_ci.sh` installs pending optional config to close the gap; `install_prod.sh` needs the same step for new tenants. | see PR #171 |

## Manual review log

| Date | Scope | Reviewer | Findings |
|---|---|---|---|
| - | Not yet performed. Follow the checklist in `ACCESSIBILITY.md`. | | |
