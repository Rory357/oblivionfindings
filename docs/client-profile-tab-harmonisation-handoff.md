# Client Profile — Tab Harmonisation Handoff

**Status:** Shipped to `main` (commit `e0a2820b`).
**Parent plan:** [`docs/client-profile-phase-2-3-handoff.md`](client-profile-phase-2-3-handoff.md) — this wave addresses follow-up **#6** ("Documents tab and Risk Management tab") from that doc's "Risks and follow-ups" section.

This doc is the "what shipped, where to find it, how to verify, what's left" handoff so a fresh session does not need to re-discover the work.

---

## Commit in this wave

| Commit | What |
|---|---|
| `e0a2820b` | feat(clients): harmonise Documents + Risk Management tabs with Phase 2 pattern. 5 files changed, +1100 / -229. Replaces a 200-line inline IIFE and a `ClientProfilePlaceholder`. Fixes two long-standing bugs in the previous Documents code. |

---

## What shipped

### Documents tab → Phase 2 component

[`resources/js/pages/operations/clients/tabs/documents.tsx`](../resources/js/pages/operations/clients/tabs/documents.tsx) — new component, replaces the 200-line inline IIFE that lived in [`show.tsx`](../resources/js/pages/operations/clients/show.tsx).

- **Stat strip** — total / expired / expiring-in-30-d / family-portal counts
- **Filter bar** — search, category Select, visibility Select (all / family portal / internal), expiry Select (any / expired / expiring / current), active-filter counter, **Clear** button
- **Card-grouped grid** — by `folder`, with file-extension-aware icons and category-tinted Badges
- **`EmptyState`** — two flavours: "no documents at all" vs "no matches for filters"
- **Manage button** — still links to the standalone `/operations/clients/{id}/documents` page for upload + folder management

### Risk Management tab → Phase 2 component

[`resources/js/pages/operations/clients/tabs/risk-management.tsx`](../resources/js/pages/operations/clients/tabs/risk-management.tsx) — new component, replaces the `ClientProfilePlaceholder` that previously rendered the tab.

- **Stat strip** — active / critical / review-overdue / review-in-30-d
- **Filters** — status (active / inactive / all), severity (any / critical / high / medium / low)
- **Severity-tinted Cards** — with `review_state` ("Review overdue", "Review soon") and inactive badges
- **Inline CRUD via Dialog** — Add risk, Edit risk, Delete risk (with `window.confirm`), all wired to the existing `ClientRiskController` routes (`POST/PUT/DELETE /operations/clients/{client}/risks{/risk?}`). No backend changes needed.
- **`EmptyState`** — distinguishes "no risks at all" from "no matches for filters"

### Backend changes (light)

[`app/Http/Controllers/ClientController.php`](../app/Http/Controllers/ClientController.php):

- `documents` select now includes `folder` (was previously omitted, so every doc grouped under "Unfiled" in the tab — see Bugs Fixed).
- `client_risks` query updated: removed `->where('active', true)`, ordered by active-first → severity → label, bumped limit to 50, so inactive risks are visible for management.
- `can` array gained `manage_risks`, `create_risks`, `update_risks`, `delete_risks` derived from `risks.create` / `risks.update` / `risks.delete` permissions.

[`resources/js/pages/operations/clients/show.tsx`](../resources/js/pages/operations/clients/show.tsx):

- Imports + wires `<DocumentsTab>` and `<RiskManagementTab>`.
- Risk Management tab-nav count badge now counts **active** risks only (since `client_risks` now contains all risks).
- The 200-line inline Documents IIFE was deleted.

---

## Bugs fixed alongside (per the project's "fix-as-you-find" memory)

| Bug | Where | Impact | Fix |
|---|---|---|---|
| Documents tab read `d.expires_at` | Old inline IIFE in `show.tsx` | The expired / expiring badges **never fired** — the actual field is `d.expiry_date`. | New component uses `d.expiry_date` consistently. |
| `folder` missing from controller select | `ClientController::show` documents query | Every document grouped under "Unfiled" regardless of its real folder. | `folder` added to the select. |

---

## Permissions (no new gates)

The Risk Management tab reuses the existing risk permissions:
- `risks.viewAny` / `risks.viewAssigned` — already gate the route; the page receives `client_risks` if the user can see the client (`ClientPolicy::view`).
- `risks.create` / `risks.update` / `risks.delete` — gate the Add / Edit / Delete buttons via the new `can` flags.

The Documents tab continues to use `view`/`update` on the Client policy (unchanged).

---

## Verification done

- `npm run types` — clean (0 errors).
- `npm run lint` — 0 errors, 270 warnings (all pre-existing in unrelated files; was 271 before, the wave dropped one). Both new files are warning-free.
- `php artisan test --filter=ClientProfilePhaseOneTest` — 7/7 pass.
- `php artisan test --filter=ClientProfilePhaseTwoThreeTest` — 9/9 pass.
- Direct Vite module-compile check on both new files — clean.

**Browser-render verification was blocked locally** by the Herd HTTPS + preview-port mismatch (APP_URL is `https://oblivionfindings.test`, Vite serves on `:5174` with the Herd cert, the preview tool is bound to `localhost:8766` and can't follow navigation off its launched port). Spot-check both tabs on the dev environment (`https://oblivionfindings.com`) after deploy.

---

## Verification on dev (after deploy)

1. Open `/operations/clients/{id}` for a client that has documents and active risks.
2. Click **Documents** tab. Confirm:
   - The stat strip shows real counts, not zeros.
   - The category / visibility / expiry filters narrow the grid.
   - The Clear button resets all filters and the active-filter counter updates.
   - Cards group under their real folder names (no longer all under "Unfiled").
   - Documents with `expiry_date` in the past show **Expired**, within 30 days show **Expiring** (no longer always blank).
3. Click **Risk Management** tab. Confirm:
   - The stat strip shows active / critical / review-overdue counts.
   - "Add risk" opens a Dialog; submitting creates a risk inline and refreshes via Inertia.
   - "Edit" populates the same Dialog and PUTs.
   - The trash button confirms via `window.confirm` and DELETEs.
   - Inactive risks render at 60 % opacity with an "Inactive" badge; the status filter can hide them.
4. Confirm the **tab-nav badge** for Risk Management still shows the **active** risk count (not the total).

---

## What's left from the parent doc's "Risks and follow-ups"

The follow-ups list in [`client-profile-phase-2-3-handoff.md`](client-profile-phase-2-3-handoff.md) had six items. After this wave:

| # | Item | Status |
|---|---|---|
| 1 | Purchase Requests + Financial Discrepancies models for the Finance tab | open |
| 2 | PATH plan dedicated model | shipped previously (`651c4b80`) |
| 3 | NextOfKin relationship taxonomy enum | open |
| 4 | Per-organisation retention overrides UI for `oblivion:prune-retention` | open (note: `config/retention.php` does **not** exist yet — premise needs reframing before picking this up) |
| 5 | Cross-client review queue pagination (currently capped at 200) | **shipped this follow-up — see below** |
| 6 | Documents + Risk Management tab harmonisation | **shipped this wave (`e0a2820b`)** |

The remaining open items (#1, #3, #4) are not "shortest wins" — #4 needs its premise re-scoped (there is no `config/retention.php`); #1 and #3 need fresh design and data-model work.

---

## Follow-up #5 shipped — Review queue pagination

`app/Http/Controllers/Operations/ReviewQueueController.php` swapped its hard `->limit(200)` for `->paginate(50)->withQueryString()` with `->through(...)` for the row transform. Stats (`total`, `critical`, `warning`, `clients`, `sites`) now come from aggregate queries over the **full filtered set** rather than `->count()` on the truncated 200-row collection, so the page-hero counters stay accurate as the user pages through the queue.

`resources/js/pages/operations/review-queue/index.tsx` now consumes the paginated container (`items.data`, `items.links`, etc.) and renders the existing `LaravelPagination` component plus a `Showing X–Y of N` summary when `last_page > 1`. Filter changes still call `applyFilters()` which routes via `router.get('/operations/review-queue', params)` so pagination state resets to page 1 on filter changes — desired behaviour.

Verification:

- `npm run types` — clean.
- `npm run lint` — both files warning-free.
- `vendor/bin/pint --test` — passes.
- `php artisan test --filter=ClientProfilePhaseTwoThreeTest` — 9/9 pass (existing site-filter test still asserts `stats.total = 2`, `stats.clients = 2`, `stats.sites = 2`, and now goes through the aggregate path).

Bug fix bundled in: a note created exactly 48 hours ago would previously have hit both the `>= 48h` critical and `>= 24h` warning buckets in the old in-memory stats had the data crossed the 200-cap boundary; the new SQL uses `>` on the 48h side for the warning bucket so the buckets are strictly disjoint.

---

## Suggested next-session prompt

```
The 20-tab Client Profile is shipped (Phase 1 + 2 + 3), the Documents +
Risk Management tabs were harmonised with the Phase 2 pattern, and the
cross-client review queue is now paginated. Read
docs/client-profile-tab-harmonisation-handoff.md.

Remaining open follow-ups from the parent doc:
  1. Purchase Requests + Financial Discrepancies models for the Finance tab.
  3. NextOfKin relationship taxonomy enum.
  4. Per-organisation retention overrides UI — note: `config/retention.php`
     does NOT exist yet, so the original premise needs reframing first.
     Confirm with the user whether the intent is a new `config/retention.php`
     or per-org overrides on existing retention behaviour before scoping.

Pick one and ship it. Run npm run types + ClientProfilePhaseTwoThreeTest
before committing.
```
