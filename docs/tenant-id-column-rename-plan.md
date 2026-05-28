# Plan: rename `tenant_id` → `organization_id` across the schema

**Status:** Plan only. No migration shipped from this doc.
**Origin:** frontline-ops audit, item O-6. `users.tenant_id` was removed; the remaining `tenant_id` columns on other tables now mirror `organization_id` semantically, but the divergent name is a long-term maintenance hazard.

---

## Decision: rename, don't drop

Three options were considered:
1. **Drop `tenant_id` entirely.** Requires that every table already has `organization_id`. Many don't (sites, fleet_*, hr_*, etc.). Would need a second column add first — that's the same work as a rename and twice the migration surface.
2. **Leave it alone.** Cheap. Confusing forever. Every new contributor needs the same single-tenant explainer.
3. **Rename `tenant_id` → `organization_id`.** ← chosen.

Rename costs one migration per table + a code sweep, gives consistent naming, and matches the only existing per-user scope column (`users.organization_id`).

---

## Inventory: enumerate the affected tables FIRST

Don't trust the partial list in the audit. Generate the truth from the live schema:

```sql
-- Postgres (production)
SELECT table_schema, table_name
FROM information_schema.columns
WHERE column_name = 'tenant_id'
  AND table_schema NOT IN ('pg_catalog', 'information_schema')
ORDER BY table_schema, table_name;
```

```sql
-- MySQL (if applicable)
SELECT table_schema, table_name
FROM information_schema.columns
WHERE column_name = 'tenant_id'
  AND table_schema = DATABASE()
ORDER BY table_name;
```

Expected output (from migration grep, pending live verification):

**Sites & related** (`2026_02_08_*`, `2026_02_09_000002`, `2026_02_20_210000`)
- `sites`, `site_contacts`, `site_documents`, `site_hazards`, `site_hazard_actions`, `site_calendar_*`, `site_checklist_*`, `site_meal_*` (catering), `site_rooms`

**HR** (`2026_02_12_100*`)
- `hr_employee_profiles`, `hr_compliance_*`, `hr_leave_*`, `hr_recruitment_*`, `hr_onboarding_*`, `hr_performance_*`, `hr_cases_*`, `hr_policies`, `hr_course_*`, `hr_expense_*`

**Fleet** (`2026_04_05_100200`)
- `fleet_assets`, `fleet_work_orders`, `fleet_fuel_logs`, `fleet_*` (whichever the migration touches)

**Integration** (`2026_02_12_000001-3`, `2026_05_03_000100`)
- `integration_secrets`, `integration_site_configs`, `integration_events`, `integration_alerts`, `device_events`, `device_groups`

**Ledger**
- `client_ledger_entries`, `house_ledgers`, `house_ledger_entries`

**Catering** (subset of sites)
- `site_meal_shopping_lists`, `site_meal_inventory_items`, `site_meal_inventory_movements`, `site_meal_products`

**Roadmap** (single-tenant alias only — `users.tenant_id` already gone; resource-side `tenant_id` columns remain)
- `quarterly_roadmap_plans`, `initiatives`, `initiative_budgets`, `initiative_suggestions`, `decision_requests`

The live SQL above is the source of truth. **Don't ship the migration off the migration files alone — they may have drift.**

---

## Risk matrix

| Risk | Likelihood | Mitigation |
|---|---|---|
| Postgres column rename is metadata-only (instant) | n/a | Rename is fast on Postgres regardless of table size. ALTER TABLE … RENAME COLUMN takes ACCESS EXCLUSIVE briefly. |
| Long-running query holds row locks during rename | medium | Run during low-traffic window; abort if `lock_timeout` triggers. |
| App code reads `tenant_id` AFTER column gone | high if naive | Phase the rollout (expand-contract). Don't drop in same release. |
| Eloquent model `$fillable` lists `tenant_id` | high | Add `organization_id` to fillable in step 2; remove `tenant_id` in step 4. |
| Foreign keys named `*_tenant_id_foreign` | low | Postgres rename keeps FK; verify on staging. |
| Indexes named `*_tenant_id_index` | low | Indexes survive column rename in Postgres. Names stay stale (cosmetic). |
| Existing factories / seeders / tests reference `tenant_id` | high | Find/replace pass against test suite. |
| Cross-table joins / queries using `tenant_id` | high | Grep `tenant_id` in `app/`, `database/seeders/`, `tests/`. |
| API responses / Inertia props exposing `tenant_id` | medium | Inertia → JS resources tend to camelCase but verify; downstream consumers (Power BI, etc.) may break. |

---

## Phased rollout (expand → backfill-noop → contract)

For each table, four releases over four PRs / deploy windows. **Don't compress.**

### Phase 1 — Expand (add `organization_id` column, alias-write)

For every table T where `tenant_id` exists but `organization_id` does NOT:

```php
// database/migrations/YYYY_MM_DD_HHMMSS_add_organization_id_to_<table>.php
public function up(): void
{
    Schema::table('T', function (Blueprint $table) {
        $table->unsignedBigInteger('organization_id')->nullable()->after('tenant_id');
        $table->index('organization_id');
    });

    // Backfill in-flight: copy tenant_id -> organization_id
    DB::statement('UPDATE T SET organization_id = tenant_id WHERE organization_id IS NULL');
}

public function down(): void
{
    Schema::table('T', function (Blueprint $table) {
        $table->dropIndex(['organization_id']);
        $table->dropColumn('organization_id');
    });
}
```

App code: model observers / services that WRITE to these tables should write `organization_id` going forward. **Reads still use `tenant_id`.** Add a Blade/JS deprecation log if a read hits `->tenant_id` to surface stragglers.

### Phase 2 — Switch reads (one bounded context per PR)

For each bounded context (Sites, HR, Fleet, Integration, Ledger, Catering, Roadmap):
- Grep the context for `tenant_id`. Replace `->tenant_id` reads with `->organization_id`.
- Replace `where('tenant_id', …)` queries with `where('organization_id', …)`.
- Update factories/seeders/tests.
- Run that context's test suite.
- Ship.

**One context per PR keeps blast radius small.** Don't bundle.

### Phase 3 — Drop the alias-write (writes go through `organization_id` only)

Once Phase 2 is done across all contexts:
- Update observers / services to stop writing to `tenant_id`.
- Add a one-off backfill `DB::statement('UPDATE T SET organization_id = tenant_id WHERE organization_id IS NULL')` per table to catch anything Phase 1 missed.
- Add an integrity check command: `php artisan tenantid:assert-mirrors` — fails if any row has `tenant_id != organization_id`.

### Phase 4 — Contract (drop `tenant_id`)

For each table:

```php
public function up(): void
{
    Schema::table('T', function (Blueprint $table) {
        $table->dropIndex(['tenant_id']);
        $table->dropColumn('tenant_id');
    });
}
```

This release is irreversible without a backup restore — keep one. **Don't do this same release as Phase 3.** Sleep on it.

---

## Pre-flight checks (before any phase ships)

1. **Schema parity:** dev / staging / prod schemas all in sync (no rogue tenant_id-only or organization_id-only tables).
2. **Mirror integrity:** `SELECT count(*) FROM T WHERE tenant_id IS DISTINCT FROM organization_id` returns 0 for every T.
3. **Test suite green** on the touched contexts.
4. **No `tenant_id` reads in templates** — grep `resources/views/`, `resources/js/`.
5. **Backup taken** within the last hour.

---

## Post-flight checks

After Phase 4 on each table:

```sql
SELECT column_name FROM information_schema.columns
WHERE table_name = 'T' AND column_name IN ('tenant_id', 'organization_id');
-- Expect: only 'organization_id'
```

App-side:
```bash
grep -rn "tenant_id" app/ resources/ database/ tests/ | grep -v "//\|#"
# Expect: zero matches (allowing for migration history files)
```

---

## What's NOT in this plan

- Cross-tenant data routing (we're single-tenant).
- Renaming the underlying concept (still "organization" — the org_id is the scope key).
- Touching `users.tenant_id` — already removed.
- Cleaning the migration history (rewriting old migrations is destructive — leave them alone, just stop generating new `tenant_id` columns).

---

## Effort estimate (very rough)

| Phase | PRs | Net dev days |
|---|---|---|
| Phase 1 — expand | 1 migration PR (all tables) | 0.5 |
| Phase 2 — read switch | 7 bounded-context PRs | 5–8 |
| Phase 3 — write switch | 1 PR per write path (~3) | 1–2 |
| Phase 4 — contract | 1 migration PR | 0.5 |

**Total: 7–11 dev days across 12+ PRs over several sprints.** Don't try to compress.

---

## File index for the implementor

| Context | Models with `->tenant_id` |
|---|---|
| Sites | `Site`, `SiteContact`, `SiteDocument`, `SiteHazard`, `SiteRoom`, `SiteMealShoppingList`, `SiteMealInventoryItem`, `SiteMealInventoryMovement` |
| HR | `HrEmployeeProfile`, `HrLeaveRequest`, `HrExpenseClaim`, `HrCourseEnrollment`, `HrComplianceMatrix`, `HrCase`, … |
| Fleet | `FleetAsset`, `FleetWorkOrder`, `FleetFuelLog`, `AssetMaintenanceLog` |
| Integration | `IntegrationSiteConfig`, `IntegrationTenantSecret`, `IntegrationEvent`, `IntegrationAlert`, `DeviceEvent` |
| Ledger | `ClientLedgerEntry`, `HouseLedger`, `HouseLedgerEntry` |
| Roadmap | `QuarterlyRoadmapPlan`, `Initiative`, `InitiativeBudget`, `InitiativeSuggestion`, `DecisionRequest` |
| Observers | `ClientLedgerEntryObserver`, `AssetMaintenanceLogObserver`, `TimesheetMileageObserver`, `SiteHazardObserver`, `HrExpenseClaimObserver`, `HrCourseEnrollmentObserver`, `HouseLedgerEntryObserver`, `FleetWorkOrderObserver`, `FleetFuelLogObserver`, `DeviceEventObserver` |
| Services | `app/Services/Catering/*`, `app/Services/Integration/Adapters/{Unifi,Queclink,Milesight}`, `app/Services/Integration/{UnifiOperationalBridgeService,AlertRoutingService}` |
