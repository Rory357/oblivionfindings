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
| 1 | Purchase Requests + Financial Discrepancies models for the Finance tab | **shipped this session (`8a0fd597`)** |
| 2 | PATH plan dedicated model | shipped previously (`651c4b80`) |
| 3 | NextOfKin relationship taxonomy enum | **shipped this session (`51d3cac4`)** |
| 4 | Per-organisation retention overrides UI for `oblivion:prune-retention` | **shipped this session — reframed as system-wide (see below)** |
| 5 | Cross-client review queue pagination (currently capped at 200) | **shipped this session (`afb8f2c0`)** |
| 6 | Documents + Risk Management tab harmonisation | shipped previously (`e0a2820b`) |

All six parent-doc follow-ups are now shipped.

---

## Follow-up #4 shipped — Retention overrides wired into the existing settings UI

The parent doc's framing — "per-organisation retention overrides UI for `oblivion:prune-retention`" — turned out to be wrong on inspection: there is no `organizations` table (only stub columns) and no `config/retention.php` file existed for the docblock to point at. There are however two existing systems that should have been connected and were not:

1. The `oblivion:prune-retention` command, which was reading `config('retention.audit_log_years')` from a config file that never existed.
2. The Settings > Data & Privacy UI (`DataSettingsController` at `/settings/data`), which was writing `DataRetentionPolicy` rows the prune command silently ignored.

This wave wires them up and creates the missing config file:

- `config/retention.php` now exists with the two keys the prune-command docblock had been claiming all along, so admins can set baseline retention without touching env vars.
- `PruneTimelineAndAuditLogs` gained a `resolveYears()` helper that prefers, in order: CLI flag → active `DataRetentionPolicy` row for the relevant `model_type` → `config/retention.php` → hard-coded fallback. The docblock now matches reality.
- `DataSettingsController::RETENTION_ROWS` and `resources/js/pages/settings/data.tsx`'s `retentionRows` gained a `timeline-events` row (defaulting to 5 yr) so admins can configure the timeline retention from the same UI as audit logs.

The "per-organisation" piece in the parent doc was unattainable without a multi-tenancy refactor, so the work was re-scoped to "system-wide retention overrides via the existing Settings > Data UI now actually drive the prune command." That is what every other retention row in that UI already controls.

A new feature test creates two `DataRetentionPolicy` rows (audit_logs → 7 yr, timeline_events → 1 yr) and an audit log + timeline event whose ages would each fall on the *opposite* side of the config defaults; the prune command preserves the audit log and sweeps the timeline event, confirming the override takes precedence over both the config default and the hardcoded fallback.

---

## Follow-up #3 shipped — NextOfKin relationship enum

The `relationship` column on `next_of_kins` was a free-text string, validated against an inline `in:parent,sibling,…` list in `UsersController` and string-matched by the family-tree tab into family / guardian / friend / other buckets. There was no shared source of truth.

`App\Enums\NextOfKinRelationship` is now the canonical taxonomy (12 cases, including `guardian` which the family-tree tab grouped for but no relationship value mapped to). `ClientController::show` now returns `relationship_label` and `relationship_category` per next-of-kin so the frontend no longer needs to keyword-match; `UsersController` validates via `Rule::enum(NextOfKinRelationship::class)` and the Create form gained the Legal Guardian option. Legacy rows that pre-date the enum fall through to a string-matching fallback so they stay in their original groups.

---

## Follow-up #1 shipped — Purchase Requests + Financial Discrepancies models

The Finance tab's `purchase_requests` and `discrepancies` arrays were hard-coded empty on the controller pending the eventual dedicated Finance module. This wave lands the two underlying client-scoped models so the controller can populate the arrays from real tables — the moment any flow starts creating records, the Finance tab will surface them.

CRUD UI is intentionally NOT shipped here. The placeholder text on the tab still says "ships with dedicated Finance module" and that is where the create / edit / approve flows belong. This commit just removes the empty-array placeholder, lands the migrations, and adds a test asserting both models surface through the Inertia payload.

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
The 20-tab Client Profile is shipped (Phase 1 + 2 + 3) and ALL SIX
follow-ups from the parent doc are now closed. Read
docs/client-profile-tab-harmonisation-handoff.md to see what each commit
addressed.

The next sensible work is the dedicated Finance module the Phase 2/3 doc
referenced — CRUD UI for ClientPurchaseRequest + ClientFinancialDiscrepancy
records (the models now exist), approval workflow, and a way to write the
movements back to ClientLedgerEntry. Touch the Finance tab and the
operations/client-funds page so they share the new flow.
```
