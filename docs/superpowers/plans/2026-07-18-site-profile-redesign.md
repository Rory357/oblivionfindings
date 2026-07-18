# Site Profile Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild `/sites/{site}` as the branded, grouped, permission-aware Site Profile from the approved Claude Design while consolidating every cross-module action on its canonical workflow and reducing the initial payload.

**Architecture:** Keep `sites.show` as the stable URL and route name, but move page assembly to a focused controller and data services. A slim React shell reads one typed tab registry, renders shared grouped navigation in `PageHero`, and requests four permission-shaped optional Inertia group props. Site-owned forms stay local; Client, H&S, Checklists, Finance, Fleet, Hardware, and Vendor work opens the existing canonical owner.

**Tech Stack:** Laravel 13, Inertia 2, React 19, TypeScript, Tailwind 4, Pest 4, Vitest 4, Playwright

---

## Working rules

- Work only in `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-site-profile-redesign` on `codex/site-profile-redesign`.
- Run every behaviour test once before its implementation and record the expected failure.
- Keep the active task checkbox current; do not batch-check unfinished work.
- Preserve `sites.show`, existing `?tab=` deep links, tenant scoping, and Site policy authorization.
- Do not add a cross-module store, browser `alert()`/`confirm()`, page-specific local storage, or a second module modal.
- Run `git diff --check` before every task commit.

### Task 1: Generalize the grouped page navigation without regressing Client Profile

**Files:**

- Create: `resources/js/components/page/grouped-profile-nav.tsx`
- Modify: `resources/js/components/clients/profile/nav.tsx`
- Create: `resources/js/test/page-grouped-profile-nav.test.tsx`
- Verify: `resources/js/test/client-profile-navigation.test.tsx`

- [x] **Step 1: Write failing shared-navigation tests**

Cover group selection, remembered tab per group, count and warning badges, configurable test IDs, `/` search, keyboard selection, and pin/unpin callbacks. Use this public contract:

```ts
export type GroupedProfileTab = {
    id: string;
    label: string;
    group: string;
    count?: number;
    warningCount?: number;
    disabled?: boolean;
};

export type GroupedProfileNavProps = {
    tabs: GroupedProfileTab[];
    activeTab: string;
    onTabChange: (tab: string) => void;
    testIdPrefix: string;
    pinnedTabs?: string[];
    onPinnedTabsChange?: (tabs: string[]) => void;
};
```

- [x] **Step 2: Prove the new test fails**

Run: `npm test -- resources/js/test/page-grouped-profile-nav.test.tsx`

Expected: FAIL because `@/components/page/grouped-profile-nav` does not exist.

- [x] **Step 3: Extract the generic primitive and retain compatibility exports**

Move the existing `GroupPillRail`, `TierTwoTabs`, and `TabSearchPalette` behaviour into the shared file. Build test IDs from `${testIdPrefix}-group-*`, `${testIdPrefix}-tab-*`, and `${testIdPrefix}-search`. Make warning badges expose visible text and an accessible label. Re-export the shared symbols from `components/clients/profile/nav.tsx` so Client Profile imports remain valid.

- [x] **Step 4: Verify shared and Client Profile navigation**

Run: `npm test -- resources/js/test/page-grouped-profile-nav.test.tsx resources/js/test/client-profile-navigation.test.tsx`

Expected: PASS.

- [x] **Step 5: Commit**

Run: `git add resources/js/components/page/grouped-profile-nav.tsx resources/js/components/clients/profile/nav.tsx resources/js/test/page-grouped-profile-nav.test.tsx && git diff --cached --check && git commit -m "refactor(ui): share grouped profile navigation"`

### Task 2: Make one typed registry authoritative for Site Profile navigation

**Files:**

- Create: `resources/js/pages/sites/tabs/types.ts`
- Create: `resources/js/pages/sites/tabs/registry.ts`
- Create: `resources/js/test/site-profile-registry.test.ts`

- [x] **Step 1: Write failing registry tests**

Test the exact five groups and tabs from the approved design, house/day-service/head-office labels, head-office exclusions, permission-shaped locked tabs, group-data ownership, warning totals, and safe normalization of unknown or hidden `?tab=` values.

```ts
export type SiteProfileDataGroup =
    | 'peopleData'
    | 'safetyData'
    | 'operationsData'
    | 'adminData';
export type SiteProfileGroupId =
    | 'overview'
    | 'people'
    | 'safety'
    | 'operations'
    | 'admin';

export type SiteProfileTabDefinition = {
    id: string;
    group: SiteProfileGroupId;
    label: (siteType: string) => string;
    dataGroup?: SiteProfileDataGroup;
    permission?: string;
    hiddenFor?: string[];
};
```

- [x] **Step 2: Prove the registry test fails**

Run: `npm test -- resources/js/test/site-profile-registry.test.ts`

Expected: FAIL because the registry module does not exist.

- [x] **Step 3: Implement registry helpers**

Export `siteProfileGroups`, `siteProfileTabs`, `visibleSiteProfileTabs`, `resolveSiteProfileTab`, `dataGroupForTab`, and `warningTotalsByGroup`. `resolveSiteProfileTab` returns the requested visible tab or `overview`; it never returns a hidden or unauthorized tab.

- [x] **Step 4: Verify and commit**

Run: `npm test -- resources/js/test/site-profile-registry.test.ts`

Run: `git add resources/js/pages/sites/tabs/types.ts resources/js/pages/sites/tabs/registry.ts resources/js/test/site-profile-registry.test.ts && git diff --cached --check && git commit -m "feat(sites): define site profile tab registry"`

### Task 3: Persist pinned tabs through generic authenticated UI preferences

**Files:**

- Create: `database/migrations/2026_07_18_000100_create_user_ui_preferences_table.php`
- Create: `app/Models/UserUiPreference.php`
- Modify: `app/Models/User.php`
- Create: `app/Http/Requests/Settings/UpdateUiPreferenceRequest.php`
- Create: `app/Http/Controllers/Settings/UiPreferenceController.php`
- Modify: `routes/settings.php`
- Create: `tests/Feature/Settings/UiPreferenceTest.php`
- Create: `resources/js/hooks/use-ui-preference.ts`
- Create: `resources/js/test/use-ui-preference.test.tsx`

- [ ] **Step 1: Write failing endpoint tests**

Prove authentication is required, values are stored only for the acting user, the same user/key pair is updated rather than duplicated, invalid keys/JSON shapes fail validation, and one user cannot address another user's record. The schema contract is:

```php
Schema::create('user_ui_preferences', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('key', 120);
    $table->json('value');
    $table->timestamps();
    $table->unique(['user_id', 'key']);
});
```

- [ ] **Step 2: Prove the feature test fails**

Run: `php artisan test tests/Feature/Settings/UiPreferenceTest.php`

Expected: FAIL because the route and table do not exist.

- [ ] **Step 3: Implement the generic model and endpoint**

Expose authenticated `PUT /settings/ui-preferences/{key}` as `settings.ui-preferences.update`. Restrict keys to `^[a-z0-9][a-z0-9._-]{0,119}$`, require `value` to be an array, and use:

```php
$preference = $request->user()->uiPreferences()->updateOrCreate(
    ['key' => $request->route('key')],
    ['value' => $request->validated('value')],
);
```

- [ ] **Step 4: Write the failing hook test**

Verify optimistic pin toggles, `sites.profile.pinned-tabs` request payload, duplicate removal, rollback on failure, and an accessible error message.

- [ ] **Step 5: Implement and verify the hook**

Run: `npm test -- resources/js/test/use-ui-preference.test.tsx`

Run: `php artisan test tests/Feature/Settings/UiPreferenceTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

Run: `git add database/migrations/2026_07_18_000100_create_user_ui_preferences_table.php app/Models/UserUiPreference.php app/Models/User.php app/Http/Requests/Settings/UpdateUiPreferenceRequest.php app/Http/Controllers/Settings/UiPreferenceController.php routes/settings.php tests/Feature/Settings/UiPreferenceTest.php resources/js/hooks/use-ui-preference.ts resources/js/test/use-ui-preference.test.tsx && git diff --cached --check && git commit -m "feat(settings): persist generic UI preferences"`

### Task 4: Add a bounded, permission-filtered Site attention digest

**Files:**

- Create: `app/Services/Sites/SiteProfileAttentionService.php`
- Create: `tests/Feature/Sites/SiteProfileAttentionServiceTest.php`
- Create: `database/migrations/2026_07_18_000200_add_site_profile_attention_indexes.php`
- Verify: `app/Models/PpeInventory.php`

- [ ] **Step 1: Write failing attention aggregation tests**

Seed overdue hazards/actions, inspections, drills, documents, staffing gaps, assets/PPE, Checklists, and hardware. Assert this stable shape, real resolution links, severity ordering, a maximum of eight rows, permission filtering, and no credential secret fields:

```php
[
    'summary' => ['total' => 0, 'critical' => 0, 'warning' => 0],
    'groups' => ['overview' => 0, 'people' => 0, 'safety' => 0, 'operations' => 0, 'admin' => 0],
    'items' => [],
]
```

- [ ] **Step 2: Prove the service test fails**

Run: `php artisan test tests/Feature/Sites/SiteProfileAttentionServiceTest.php`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement source-specific private collectors**

Each collector selects only fields needed for the digest, filters by `site_id`, permission, status, and due/review date, and returns a normalized item with `source`, `severity`, `title`, `detail`, `due_date`, `tab`, and `href`. Merge, severity-sort, and cap rows after calculating uncapped counts. Do not cache the result.

- [ ] **Step 4: Add only confirmed composite indexes**

Add reversible indexes for the filters the service actually executes:

```text
site_hazards(site_id, status, review_date)
site_documents(site_id, expiry_date)
site_staff_requirements(site_id, is_active)
assets(site_id, requires_inspection, inspection_due_at)
assets(site_id, requires_maintenance, maintenance_due_at)
location_hardware(site_id, status, last_seen_at)
ppe_inventory(site_id, status, next_inspection_due)
ppe_inventory(site_id, status, expiry_date)
```

Before finalizing the migration, compare every index against the current MySQL schema and omit any equivalent existing prefix.

- [ ] **Step 5: Verify digest behaviour and migration reversibility**

Run: `php artisan test tests/Feature/Sites/SiteProfileAttentionServiceTest.php`

Run: `php artisan migrate --pretend --path=database/migrations/2026_07_18_000200_add_site_profile_attention_indexes.php`

Expected: PASS and valid `up` SQL.

- [ ] **Step 6: Commit**

Run: `git add app/Services/Sites/SiteProfileAttentionService.php tests/Feature/Sites/SiteProfileAttentionServiceTest.php database/migrations/2026_07_18_000200_add_site_profile_attention_indexes.php && git diff --cached --check && git commit -m "feat(sites): aggregate profile attention items"`

### Task 5: Split Site Profile payload assembly from Site CRUD and defer heavy groups

**Files:**

- Create: `app/Http/Controllers/Sites/SiteProfileController.php`
- Create: `app/Services/Sites/SiteProfileData.php`
- Modify: `app/Http/Controllers/SiteController.php`
- Modify: `routes/assets.php`
- Create: `tests/Feature/Sites/SiteProfilePayloadTest.php`
- Modify: `tests/Feature/SiteControllerTest.php`

- [ ] **Step 1: Write failing payload-boundary tests**

Prove `sites.show` still authorizes and renders `sites/show`; the eager response includes `site`, `hero`, `permissions`, `attention`, `overview`, `readiness`, and `uiPreferences`; the eager response excludes `peopleData`, `safetyData`, `operationsData`, `adminData`, credential secrets, full Checklists runs, and full Vendor registers. Add partial Inertia requests for each group using:

```php
[
    'X-Inertia' => 'true',
    'X-Inertia-Version' => Vite::manifestHash(),
    'X-Inertia-Partial-Component' => 'sites/show',
    'X-Inertia-Partial-Data' => 'safetyData',
]
```

- [ ] **Step 2: Prove the new boundary test fails**

Run: `php artisan test tests/Feature/Sites/SiteProfilePayloadTest.php`

Expected: FAIL because the route still uses `SiteController` and sends eager registers.

- [ ] **Step 3: Implement the focused controller and service**

Change only the route target, not its URL/name/middleware. Authorize before calling the data service. Return optional props with closures:

```php
return Inertia::render('sites/show', [
    ...$data->shell($request->user(), $site),
    'peopleData' => Inertia::optional(fn () => $data->people($request->user(), $site)),
    'safetyData' => Inertia::optional(fn () => $data->safety($request->user(), $site)),
    'operationsData' => Inertia::optional(fn () => $data->operations($request->user(), $site)),
    'adminData' => Inertia::optional(fn () => $data->admin($request->user(), $site)),
]);
```

Move read-only page assembly out of `SiteController::show`; leave Site CRUD and mutation methods in place.

- [ ] **Step 4: Add query ceilings**

Warm framework and permission caches before counting. Assert fixed ceilings for shell and each optional group with at least three rows per included relation. A ceiling failure must report the observed count.

- [ ] **Step 5: Verify controller compatibility**

Run: `php artisan test tests/Feature/Sites/SiteProfilePayloadTest.php tests/Feature/SiteControllerTest.php --filter="site show"`

Expected: PASS; update stale assertions that expected full eager Checklists/Vendor payloads to assert their optional summaries instead.

- [ ] **Step 6: Commit**

Run: `git add app/Http/Controllers/Sites/SiteProfileController.php app/Services/Sites/SiteProfileData.php app/Http/Controllers/SiteController.php routes/assets.php tests/Feature/Sites/SiteProfilePayloadTest.php tests/Feature/SiteControllerTest.php && git diff --cached --check && git commit -m "refactor(sites): defer site profile module payloads"`

### Task 6: Build the branded Site Profile shell and hero navigation

**Files:**

- Rewrite: `resources/js/pages/sites/show.tsx`
- Create: `resources/js/pages/sites/tabs/site-profile-states.tsx`
- Create: `resources/js/test/site-profile-shell.test.tsx`

- [ ] **Step 1: Write failing shell tests**

Assert explicit `site.brand_colour` reaches `PageHero`, missing brand colour falls through to the organisation primary token, readiness changes the URL/tab without `scrollIntoView`, hero stats and key contacts are permission-shaped, `?tab=hazards` opens Safety, unknown tabs normalize once, group data requests use `router.reload({ only: [...] })`, and loading/error/locked states remain distinct.

- [ ] **Step 2: Prove the shell test fails**

Run: `npm test -- resources/js/test/site-profile-shell.test.tsx`

Expected: FAIL against the legacy monolith.

- [ ] **Step 3: Implement the slim shell**

The shell owns only title/breadcrumbs, `PageHero`, registry navigation, query synchronization, optional-group loading, retry, active-tab rendering, and Site-owned dialog hosts. Pass:

```tsx
<PageHero
    brandColour={site.brand_colour ?? undefined}
    backHref="/sites"
    description={hero.description}
    readiness={hero.readiness}
    stats={hero.stats}
    quickActions={hero.quickActions}
    footer={<SiteProfileNavigation />}
/>
```

Use `replaceState` semantics for tab normalization and `preserveState`/`preserveScroll` for partial reloads. Never inspect protected optional data to decide visibility.

- [ ] **Step 4: Verify shell and shared navigation**

Run: `npm test -- resources/js/test/site-profile-shell.test.tsx resources/js/test/site-profile-registry.test.ts resources/js/test/page-grouped-profile-nav.test.tsx resources/js/test/client-profile-navigation.test.tsx`

Expected: PASS.

- [ ] **Step 5: Commit**

Run: `git add resources/js/pages/sites/show.tsx resources/js/pages/sites/tabs/site-profile-states.tsx resources/js/test/site-profile-shell.test.tsx && git diff --cached --check && git commit -m "feat(sites): build branded site profile shell"`

### Task 7: Implement focused Overview and Readiness tabs

**Files:**

- Create: `resources/js/pages/sites/tabs/overview.tsx`
- Create: `resources/js/pages/sites/tabs/readiness.tsx`
- Create: `resources/js/pages/sites/tabs/attention-panel.tsx`
- Create: `resources/js/test/site-profile-overview.test.tsx`

- [ ] **Step 1: Write failing presentation tests**

Cover setup/readiness banner, occupancy, attention severity plus text/icon cues, resolution links, contact summary, location/access/map, safety summary, services, notes, recent activity, zero-state actions, and no repeated hero-only content. Verify Readiness actions call `onNavigate(tab)` rather than scrolling the document.

- [ ] **Step 2: Prove the tests fail**

Run: `npm test -- resources/js/test/site-profile-overview.test.tsx`

Expected: FAIL because the focused components do not exist.

- [ ] **Step 3: Move and simplify the current overview/readiness UI**

Reuse existing cards and formatters. Every empty panel uses `SiteProfileEmptyState`; denied modules use `SiteProfileLockedState`; no-data and loading must not share markup. Preserve semantic status tokens independently of the hero brand colour.

- [ ] **Step 4: Verify and commit**

Run: `npm test -- resources/js/test/site-profile-overview.test.tsx resources/js/test/site-profile-shell.test.tsx`

Run: `git add resources/js/pages/sites/tabs/overview.tsx resources/js/pages/sites/tabs/readiness.tsx resources/js/pages/sites/tabs/attention-panel.tsx resources/js/test/site-profile-overview.test.tsx && git diff --cached --check && git commit -m "feat(sites): add profile overview and readiness"`

### Task 8: Consolidate Client creation and existing-client placement

**Files:**

- Modify: `resources/js/pages/sites/clients/_dialogs.tsx`
- Create: `resources/js/pages/sites/clients/link-client-dialog.tsx`
- Create: `resources/js/pages/sites/tabs/clients.tsx`
- Create: `resources/js/pages/sites/tabs/contacts.tsx`
- Create: `resources/js/pages/sites/tabs/staff-requirements.tsx`
- Create: `resources/js/pages/sites/tabs/shift-coverage.tsx`
- Modify: `app/Http/Controllers/SiteClientController.php`
- Create: `app/Http/Requests/Sites/LinkSiteClientRequest.php`
- Modify: `routes/assets.php`
- Create: `tests/Feature/Sites/SiteClientTest.php`
- Create: `resources/js/test/site-profile-client-workflows.test.tsx`

- [ ] **Step 1: Write failing workflow-ownership tests**

Frontend tests prove “Create client” opens `components/clients/add-client-dialog.tsx`, “Link existing client” opens only the focused placement wizard, and no quick-create fields or `sites.clients.store` submission remain. Backend tests prove linking validates tenant-scoped `client_id`, optional `service_context_id`, `room_id`, and `key_worker_id`, rejects occupied/foreign rooms and unauthorized workers, and applies placement atomically.

- [ ] **Step 2: Prove both suites fail**

Run: `npm test -- resources/js/test/site-profile-client-workflows.test.tsx`

Run: `php artisan test tests/Feature/Sites/SiteClientTest.php`

Expected: FAIL because quick-create remains and link accepts only `client_id`.

- [ ] **Step 3: Remove the duplicate quick-create path**

Delete `QuickCreateForm`, remove `SiteClientController::store`, and remove `sites.clients.store`. Keep the shared full Add Client dialog as the only new-client workflow, prefilled with Site/service context through its supported props.

- [ ] **Step 4: Implement the placement request and transaction**

Validate every selected record against the same tenant and Site scope before `DB::transaction`. Update the Client Site assignment and authorized placement metadata together. Return focused validation messages to the owning wizard step. Unlink continues through the shared destructive confirmation and explains room/assignment effects.

- [ ] **Step 5: Extract People tabs and verify**

Move existing People UI into typed focused components. Head office hides Clients and Shift Coverage through the registry; it does not render empty protected payloads.

Run: `npm test -- resources/js/test/site-profile-client-workflows.test.tsx resources/js/test/site-profile-shell.test.tsx`

Run: `php artisan test tests/Feature/Sites/SiteClientTest.php tests/Feature/Sites/SiteOverviewContactsUnificationTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

Run: `git add resources/js/pages/sites/clients/_dialogs.tsx resources/js/pages/sites/clients/link-client-dialog.tsx resources/js/pages/sites/tabs/clients.tsx resources/js/pages/sites/tabs/contacts.tsx resources/js/pages/sites/tabs/staff-requirements.tsx resources/js/pages/sites/tabs/shift-coverage.tsx app/Http/Controllers/SiteClientController.php app/Http/Requests/Sites/LinkSiteClientRequest.php routes/assets.php tests/Feature/Sites/SiteClientTest.php resources/js/test/site-profile-client-workflows.test.tsx && git diff --cached --check && git commit -m "refactor(sites): unify client placement workflows"`

### Task 9: Extract Safety tabs and route writes to canonical H&S workflows

**Files:**

- Create: `resources/js/pages/sites/tabs/hazards.tsx`
- Create: `resources/js/pages/sites/tabs/risk-assessments.tsx`
- Create: `resources/js/pages/sites/tabs/inspections.tsx`
- Create: `resources/js/pages/sites/tabs/drills.tsx`
- Create: `resources/js/pages/sites/tabs/first-aid.tsx`
- Create: `resources/js/pages/sites/tabs/ppe.tsx`
- Create: `resources/js/pages/sites/tabs/emergency-plan.tsx`
- Create: `resources/js/test/site-profile-safety-tabs.test.tsx`
- Modify: `tests/Feature/Sites/SiteOperationalReadinessTest.php`

- [ ] **Step 1: Write failing canonical-action tests**

Assert each Safety tab renders its permission-shaped summary and canonical create/manage link with `site_id` context. Assert the Site Profile does not host a second H&S create/edit form, and restricted viewers receive no record counts. Preserve existing Site-owned emergency metadata only where the H&S owner has no equivalent.

- [ ] **Step 2: Prove the test fails**

Run: `npm test -- resources/js/test/site-profile-safety-tabs.test.tsx`

Expected: FAIL because Safety UI still lives in the monolith and duplicates action hosting.

- [ ] **Step 3: Extract summary components and canonical links**

Use the current H&S route names and shared module dialogs where they already exist. All rows include a plain-language status, due date, and resolution path. The Site Profile may prefill Site context but must not persist H&S records itself.

- [ ] **Step 4: Verify Safety behaviour**

Run: `npm test -- resources/js/test/site-profile-safety-tabs.test.tsx resources/js/test/site-profile-overview.test.tsx`

Run: `php artisan test tests/Feature/Sites/SiteOperationalReadinessTest.php tests/Feature/Sites/SiteProfileAttentionServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

Run: `git add resources/js/pages/sites/tabs/hazards.tsx resources/js/pages/sites/tabs/risk-assessments.tsx resources/js/pages/sites/tabs/inspections.tsx resources/js/pages/sites/tabs/drills.tsx resources/js/pages/sites/tabs/first-aid.tsx resources/js/pages/sites/tabs/ppe.tsx resources/js/pages/sites/tabs/emergency-plan.tsx resources/js/test/site-profile-safety-tabs.test.tsx tests/Feature/Sites/SiteOperationalReadinessTest.php && git diff --cached --check && git commit -m "refactor(sites): route safety work to canonical modules"`

### Task 10: Extract Operations tabs and restore compact Checklists ownership

**Files:**

- Create: `resources/js/pages/sites/tabs/calendar.tsx`
- Create: `resources/js/pages/sites/tabs/checklists.tsx`
- Create: `resources/js/pages/sites/tabs/meal-planner.tsx`
- Create: `resources/js/pages/sites/tabs/assets.tsx`
- Create: `resources/js/pages/sites/tabs/fleet.tsx`
- Create: `resources/js/pages/sites/tabs/hardware.tsx`
- Create: `resources/js/pages/sites/tabs/plan.tsx`
- Modify: `resources/js/pages/sites/show.tsx`
- Create: `resources/js/test/site-profile-operations-tabs.test.tsx`
- Modify: `tests/Feature/SiteControllerTest.php`

- [ ] **Step 1: Write failing Operations ownership tests**

Prove Checklists renders bounded status counts and `/sites/{site}/checklists`, never `ChecklistsWorkspace`; Meal Planner reuses its existing canonical embedded component; Assets/Fleet/Hardware render summaries and filtered owner links; Plan & Rooms remains Site-owned; head office hides Meal Planner.

- [ ] **Step 2: Prove the test fails**

Run: `npm test -- resources/js/test/site-profile-operations-tabs.test.tsx`

Expected: FAIL because `show.tsx` imports and renders the full Checklists workspace.

- [ ] **Step 3: Extract Operations components and remove eager registers**

Delete the profile import/host for `ChecklistsWorkspace`. Keep only current/due/overdue counts, recent bounded activity, and the canonical workspace link in `operationsData`. Preserve Meal Planner’s established lazy import and embedded contract rather than copying its forms.

- [ ] **Step 4: Verify Operations and Checklists compatibility**

Run: `npm test -- resources/js/test/site-profile-operations-tabs.test.tsx resources/js/test/site-profile-shell.test.tsx`

Run: `php artisan test tests/Feature/SiteControllerTest.php --filter="checklist|linked assets|site show"`

Expected: PASS with compact Checklists assertions.

- [ ] **Step 5: Commit**

Run: `git add resources/js/pages/sites/tabs/calendar.tsx resources/js/pages/sites/tabs/checklists.tsx resources/js/pages/sites/tabs/meal-planner.tsx resources/js/pages/sites/tabs/assets.tsx resources/js/pages/sites/tabs/fleet.tsx resources/js/pages/sites/tabs/hardware.tsx resources/js/pages/sites/tabs/plan.tsx resources/js/pages/sites/show.tsx resources/js/test/site-profile-operations-tabs.test.tsx tests/Feature/SiteControllerTest.php && git diff --cached --check && git commit -m "refactor(sites): slim site operations tabs"`

### Task 11: Extract Admin tabs and remove duplicate Vendor/Credential management

**Files:**

- Create: `resources/js/pages/sites/tabs/documents.tsx`
- Create: `resources/js/pages/sites/tabs/financials.tsx`
- Create: `resources/js/pages/sites/tabs/vendors.tsx`
- Create: `resources/js/pages/sites/tabs/services.tsx`
- Modify: `resources/js/pages/sites/show.tsx`
- Create: `resources/js/test/site-profile-admin-tabs.test.tsx`
- Modify: `tests/Feature/Sites/SiteCredentialDialogTest.php`
- Modify: `tests/Feature/Sites/HouseLedgerTest.php`

- [ ] **Step 1: Write failing Admin ownership tests**

Assert Documents uses the Site-owned shared wizard/confirm primitives; Financials shows a permission-shaped summary and links to the Finance Site Dashboard instead of labelling the house ledger as all financials; Vendors & Credentials exposes status/count summaries and `/vendors?site_id={id}` only; no credential secret or duplicate reveal/edit dialog enters the Site Profile bundle or payload.

- [ ] **Step 2: Prove the tests fail**

Run: `npm test -- resources/js/test/site-profile-admin-tabs.test.tsx`

Run: `php artisan test tests/Feature/Sites/SiteCredentialDialogTest.php tests/Feature/Sites/HouseLedgerTest.php`

Expected: frontend FAIL against the duplicate Vendor/Credential register; backend establishes current audited reveal and ledger behaviour before compatibility assertions are updated.

- [ ] **Step 3: Extract Admin components and canonical links**

Remove duplicate Vendor/Credential dialog hosts and management tables from the Site Profile. Keep secrets out of `adminData`; reveal remains only in the canonical Vendor workspace. Render house ledger as a clearly named secondary link where permission permits, not as the Financials tab body.

- [ ] **Step 4: Verify Admin behaviour and secret non-disclosure**

Run: `npm test -- resources/js/test/site-profile-admin-tabs.test.tsx resources/js/test/site-profile-shell.test.tsx`

Run: `php artisan test tests/Feature/Sites/SiteCredentialDialogTest.php tests/Feature/Sites/HouseLedgerTest.php tests/Feature/Sites/SiteProfilePayloadTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

Run: `git add resources/js/pages/sites/tabs/documents.tsx resources/js/pages/sites/tabs/financials.tsx resources/js/pages/sites/tabs/vendors.tsx resources/js/pages/sites/tabs/services.tsx resources/js/pages/sites/show.tsx resources/js/test/site-profile-admin-tabs.test.tsx tests/Feature/Sites/SiteCredentialDialogTest.php tests/Feature/Sites/HouseLedgerTest.php && git diff --cached --check && git commit -m "refactor(sites): consolidate site admin workflows"`

### Task 12: Complete integration tests, accessibility, and responsive polish

**Files:**

- Modify: `resources/js/pages/sites/show.tsx`
- Modify: `resources/js/pages/sites/tabs/*.tsx`
- Create: `tests/Feature/Sites/SiteProfileAuthorizationTest.php`
- Create: `resources/js/test/site-profile-accessibility.test.tsx`

- [ ] **Step 1: Add failing cross-cutting tests**

Backend: foreign Site isolation, archived Site behaviour, restricted module payloads, unauthorized deep links, canonical action URLs, and no protected counts. Frontend: visible focus, 44px touch targets, keyboard group/tab/search operation, Escape handling, text/icon status cues, narrow card layout, and no browser confirm APIs.

- [ ] **Step 2: Prove failures and apply the smallest integration fixes**

Run: `php artisan test tests/Feature/Sites/SiteProfileAuthorizationTest.php`

Run: `npm test -- resources/js/test/site-profile-accessibility.test.tsx`

Expected: tests fail for any unintegrated boundary, then pass after focused fixes.

- [ ] **Step 3: Run affected module suites**

Run: `php artisan test tests/Feature/SiteControllerTest.php tests/Feature/Sites tests/Feature/Operations/ClientProfileSensitivePayloadTest.php`

Run: `npm test -- resources/js/test/site-profile-*.test.ts resources/js/test/site-profile-*.test.tsx resources/js/test/page-grouped-profile-nav.test.tsx resources/js/test/client-profile-navigation.test.tsx`

Expected: PASS. Record exact test/assertion counts in the post-audit.

- [ ] **Step 4: Run static and build gates**

Run: `vendor/bin/pint --dirty`

Run: `npm run types`

Run: `npm run build`

Run: `npx vite build --ssr`

Expected: all exit 0. If `npm run types` reports missing generated route modules, run `php artisan wayfinder:generate` once and rerun the type gate.

- [ ] **Step 5: Commit integration polish**

Run: `git add app resources routes tests database docs && git diff --cached --check && git commit -m "test(sites): cover profile integration boundaries"`

### Task 13: Verify the real Site Profile in the correct worktree browser

**Files:**

- Modify if evidence requires a fix: `resources/js/pages/sites/**`
- Create evidence directory if absent: `docs/evidence/site-profile-redesign/`

- [ ] **Step 1: Start the intended worktree host and prove its identity**

Follow `oblivionfindings-frontline-browser-verification`. Confirm the served asset/content marker comes from this worktree before accepting screenshots. Record host, branch, HEAD, viewport, user/role, Site ID/type, and timestamp.

- [ ] **Step 2: Verify representative variants**

At 1440x900 verify a branded house, a day-service hub, a head office, a Site without `brand_colour`, and a restricted viewer. At a narrow viewport smoke-test hero wrapping, group rail scrolling, tab cards, focus, and touch targets.

- [ ] **Step 3: Verify critical interactions**

Exercise grouped navigation, `/` search, pins after reload, `?tab=` deep links, readiness navigation, attention resolution, canonical Client create vs placement, compact Checklists link, canonical Finance/Vendor links, locked states, Escape handling, and partial-load retry. Capture console/network errors and confirm no credential secret appears in an Inertia response.

- [ ] **Step 4: Fix only observed Site Profile defects and rerun their focused tests**

Use the systematic-debugging skill for every unexpected result. Save final screenshots and a concise evidence index under `docs/evidence/site-profile-redesign/`.

- [ ] **Step 5: Commit evidence-backed fixes**

Run: `git add resources/js/pages/sites docs/evidence/site-profile-redesign && git diff --cached --check && git commit -m "fix(sites): close browser verification gaps"`

### Task 14: Write the mandatory post-audit and run final fresh verification

**Files:**

- Create: `docs/site-profile-redesign-post-audit.md`
- Modify: `docs/superpowers/plans/2026-07-18-site-profile-redesign.md`

- [ ] **Step 1: Audit frontend, backend, ownership, and performance after implementation**

The audit records: shipped architecture; removed duplicates; canonical workflow map; authorization/tenant findings; payload and query evidence; indexes added or deliberately omitted; automated/build/browser proof; and remaining improvements ranked by severity, user impact, engineering effort, owner, and recommended next action. Separate implemented/verified work from unresolved acceptance gaps.

- [ ] **Step 2: Run fresh verification from a clean prompt**

Run: `git status --short`

Run: `php artisan test tests/Feature/SiteControllerTest.php tests/Feature/Sites tests/Feature/Settings/UiPreferenceTest.php tests/Feature/Operations/ClientProfileSensitivePayloadTest.php`

Run: `npm test -- resources/js/test/site-profile-*.test.ts resources/js/test/site-profile-*.test.tsx resources/js/test/page-grouped-profile-nav.test.tsx resources/js/test/client-profile-navigation.test.tsx resources/js/test/use-ui-preference.test.tsx`

Run: `npm run types`

Run: `npm run build`

Run: `npx vite build --ssr`

Run: `git diff --check`

Expected: all exit 0; the worktree contains only the audit/plan updates intended for the final commit.

- [ ] **Step 3: Mark this plan truthfully**

Check only completed tasks. Add exact command results and browser evidence paths to the audit. Leave any blocked acceptance item unchecked and explain its blocker.

- [ ] **Step 4: Commit the closeout artifacts**

Run: `git add docs/site-profile-redesign-post-audit.md docs/superpowers/plans/2026-07-18-site-profile-redesign.md docs/evidence/site-profile-redesign && git diff --cached --check && git commit -m "docs(sites): close site profile redesign audit"`

- [ ] **Step 5: Report the branch as implementation-complete, not integrated**

Report branch/HEAD, commits, exact test/build counts, browser variants, files containing evidence, duplicates removed, remaining ranked improvements, and whether merge/push/deploy were requested. Do not merge, push, or deploy without separate user authorization.
