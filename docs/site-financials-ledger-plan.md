# Site Financials — Inline Ledger + Global Comparison Plan

## Goal

Replace the placeholder Financials tab on the Site detail page with a fully functional inline house ledger UI, and re-target the "Open Financial Dashboard" button to a new cross-site comparison page.

## Background

A survey of the codebase (2026-05-10) found:

- The HouseLedger backend is **fully built but completely unrouted**. Model, service, controller, observer, GL integration all exist; just needs routes + UI.
- The current "Open Financial Dashboard" button on [resources/js/pages/sites/show.tsx:2262-2285](../resources/js/pages/sites/show.tsx#L2262-L2285) links to `/finance/sites/{site}/financial-dashboard` — that's a **per-site** dashboard, not global. The link is mis-targeted for the desired behavior.
- Backend already provides everything needed for a cross-site view: `SiteCostService::compareSites()`, `SiteBudgetLine` (monthly budgets), site rent/lease fields, `site_utilities` table — none of it surfaced in UI.

### Confirmed scope (user decisions)

- **Inline tab content:** Ledger entries + balance only. (Budgets, utilities, rent editing deferred.)
- **Global dashboard target:** New All-Sites Comparison page.

## Existing assets

| Component | File | Status |
|---|---|---|
| HouseLedger model | [app/Models/HouseLedger.php](../app/Models/HouseLedger.php) | Complete |
| HouseLedgerEntry model | [app/Models/HouseLedgerEntry.php](../app/Models/HouseLedgerEntry.php) | Complete |
| HouseLedgerService | [app/Services/Sites/HouseLedgerService.php](../app/Services/Sites/HouseLedgerService.php) | Complete (`getOrCreateLedger`, `addEntry`, `getStatement`, `reconcile`) |
| HouseLedgerController | [app/Http/Controllers/Sites/HouseLedgerController.php](../app/Http/Controllers/Sites/HouseLedgerController.php) | Built (`index`, `store`, `downloadAttachment`, `reconcile`) — **unrouted** |
| Observer (GL bridge) | [app/Observers/HouseLedgerEntryObserver.php](../app/Observers/HouseLedgerEntryObserver.php) | Auto-posts entries to GL via `ProcessFinancialEventJob` |
| Migrations | [database/migrations/2026_02_20_000002_create_house_ledger_tables.php](../database/migrations/2026_02_20_000002_create_house_ledger_tables.php), [database/migrations/2026_04_09_160000_site_financial_integration.php](../database/migrations/2026_04_09_160000_site_financial_integration.php) | Schema in place |
| SiteCostService | [app/Domain/Finance/Services/SiteCostService.php](../app/Domain/Finance/Services/SiteCostService.php) | `compareSites()` ready for cross-site view |
| Existing per-site dashboard | [resources/js/pages/finance/site-dashboard/Show.tsx](../resources/js/pages/finance/site-dashboard/Show.tsx) | Stays at `/finance/sites/{site}/financial-dashboard` |
| Site Financials tab placeholder | [resources/js/pages/sites/show.tsx:2262-2285](../resources/js/pages/sites/show.tsx#L2262-L2285) | To be replaced |

---

## Phase 1 — Wire up the orphan HouseLedger routes

Add to [routes/sites.php](../routes/sites.php) inside the existing `sites/{site}` group:

```
GET    /sites/{site}/ledger                           → HouseLedgerController@index
POST   /sites/{site}/ledger/entries                   → HouseLedgerController@store
GET    /sites/{site}/ledger/entries/{entry}/download  → HouseLedgerController@downloadAttachment
POST   /sites/{site}/ledger/reconcile                 → HouseLedgerController@reconcile
```

**Verify** the controller's `index` returns Inertia or JSON — likely JSON given the API shape. If we need an Inertia render method for a standalone tab page, add it; otherwise the inline panel calls the JSON endpoint for paginated entries.

## Phase 2 — Inline ledger UI in the Financials tab

Replace the placeholder block at [resources/js/pages/sites/show.tsx:2262-2285](../resources/js/pages/sites/show.tsx#L2262-L2285) with a `<SiteLedgerPanel>` component.

**New component:** `resources/js/pages/sites/_ledger-panel.tsx` (follows the `_document-helpers.ts` co-location pattern already in use in this folder).

### Layout

- **Top row (3 cards):** Current Balance · Last Reconciled (date + Reconcile button) · Period filter (from/to).
- **Add Entry button** opens a Sheet/Dialog with: `entry_type` select, `category`, `description`, `reference`, `amount`, `entry_date`, `notes`, attachment upload. Posts to `/sites/{site}/ledger/entries`.
- **Entries table:** date · type badge · category · description · amount (signed/colored) · running balance · recorded by · attachment icon (download link) · approval state. Paginated. Date range filter at top.
- **"Open Financial Dashboard" button** stays in the panel header — re-pointed in Phase 3.

### Backend wiring

Pass `houseLedger` + first page of `entries` from `SiteController@show` so the tab is server-rendered. Subsequent pagination/filtering hits the JSON `index` route.

### Radix Select gotcha

Optional selects must use `value={data.field || undefined}` — never `<SelectItem value="">`.

## Phase 3 — Re-point the dashboard button

Change the button target from `/finance/sites/{site}/financial-dashboard` to the new global route in Phase 4 (`/finance/sites`, name `finance.sites.overview`).

## Phase 4 — New All-Sites Comparison page

### Route

In [routes/finance.php](../routes/finance.php):

```
GET /finance/sites → SitesFinancialOverviewController@index   (name: finance.sites.overview)
```

### Controller

**New:** `app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php`

Composes data from existing services (no new aggregation logic):

- `SiteCostService::compareSites([...all site ids], from, to)` — cost breakdown per site.
- `BudgetVarianceService` per site (loop or batch).
- Headline KPIs: total spend across all sites, count of sites over budget, top 5 spenders.

### Page

**New:** `resources/js/pages/finance/sites-overview/Show.tsx`

Layout:

- KPI hero strip: Total Cost (all sites) · Sites Over Budget · Avg Cost / Site · Period.
- Sortable comparison table: Site · Total Cost · vs Budget % · Top Category · Trend sparkline · link to per-site dashboard.
- Stacked bar chart: cost by site, segmented by category.
- Period filter (from/to).

## Phase 5 — Tests

Extend [tests/Feature/Sites/HouseLedgerTest.php](../tests/Feature/Sites/HouseLedgerTest.php) and add new feature tests:

- Ledger routes: auth, tenancy scoping; `store` creates entry and observer dispatches GL job; download requires permission; reconcile updates `last_reconciled_at` + `reconciled_by`.
- `SitesFinancialOverviewController`: returns expected sites + KPIs, respects tenancy.
- Site show page smoke: renders ledger panel with entries on the Financials tab.

---

## Order of work

1. **Phase 1** — routes (unblocks UI work).
2. **Phase 2** — inline tab UI (largest chunk).
3. **Phases 3–4** — re-point button + new global page + controller + UI (parallelizable with Phase 2).
4. **Phase 5** — tests per-phase as we go, not deferred to the end.

## Out of scope

- Monthly budget line management UI.
- Utilities tracking UI (`site_utilities` exists in schema, no surface).
- Rent/lease inline editing on the site (fields exist in schema).

These can be added later as separate panels on the same Financials tab without restructuring.
