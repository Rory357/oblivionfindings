# Site Profile Corrective Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the Site Profile in the actual Client Profile design family while preserving every capability present at `b5b5df463ce788fbbf988c74f5142b7fcbb52628` and retaining the useful authorization, attention, placement, branding, preference, and deferred-loading work already on `codex/site-profile-redesign`.

**Architecture:** `resources/js/pages/sites/show.tsx` remains a coordinator. A dedicated `SiteProfileHero`, alert ribbon, grouped navigation, tier-two tabs, typed intent host, and one optional Inertia prop per tab compose focused full-depth surfaces. Backend tab presenters reuse canonical models, services, policies, and existing data builders; Site-owned records retain Site-owned dialogs, while cross-module actions invoke their canonical owner.

**Tech Stack:** Laravel 12, Inertia, React 19, TypeScript, Tailwind CSS 4, Vitest/Testing Library, Pest/PHPUnit, Vite client and SSR builds, real Chrome verification.

---

## Working contract

- Work only in `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-site-profile-redesign` on `codex/site-profile-redesign`.
- The approved design is `docs/superpowers/specs/2026-07-22-site-profile-corrective-redesign-design.md`. Do not restart brainstorming.
- The capability source is `b5b5df463ce788fbbf988c74f5142b7fcbb52628`. The live ledger is `docs/site-profile-corrective-capability-ledger.md`.
- Do not merge, push, deploy, run production migrations, or mutate a live environment.
- Preserve the inherited untracked `.superpowers/` directory.
- Run heavy PHP matrices, TypeScript, client build, SSR build, and browser server sequentially.
- Use TDD for every behaviour change: add one failing assertion, record the expected failure, implement the smallest complete behaviour, rerun, then refactor while green.
- A canonical replacement may change workflow ownership, but it must keep the action available in Site Profile with Site context.
- No row may use `Removed`. Any unresolved row becomes `Blocked` with exact dependency and user impact.

## File responsibility map

### Profile composition

- Create `resources/js/components/sites/profile/hero.tsx` for the dedicated Client-family Site hero, brand/contrast handling, operational strip, safety strip, statistics, compact indicators, actions, and hero footer.
- Create `resources/js/components/sites/profile/alert-ribbon.tsx` for Client-family attention pills immediately below the hero.
- Create `resources/js/pages/sites/profile-types.ts` for shared Site, hero, tab-prop, and intent types.
- Create `resources/js/pages/sites/site-profile-dialog-host.tsx` as the one typed host for Site-owned dialogs and canonical workflow entry points.
- Modify `resources/js/pages/sites/show.tsx` so it owns breadcrumbs, URL normalization, selected tab, per-tab loading/retry, focus/scroll preservation, hero/nav composition, active surface, and dialog host only.
- Modify `resources/js/pages/sites/tabs/registry.ts` and `types.ts` for tab-to-prop mapping, permission/applicability rules, metrics, labels, and direct-link normalization.

### Backend presentation

- Modify `app/Http/Controllers/Sites/SiteProfileController.php` to authorize first and expose one optional Inertia prop for each full tab.
- Keep `app/Services/Sites/SiteProfileData.php` eager-shell only.
- Create `app/Services/Sites/Profile/SiteProfilePeoplePresenter.php` with clients, contacts, staff requirements, and shift coverage methods.
- Create `app/Services/Sites/Profile/SiteProfileSafetyPresenter.php` with hazards, risk assessments, inspections, drills, first aid, PPE, and emergency plan methods.
- Create `app/Services/Sites/Profile/SiteProfileOperationsPresenter.php` with Calendar, Checklists, Meal Planner eligibility, Assets, Fleet, Hardware, and plan methods.
- Create `app/Services/Sites/Profile/SiteProfileAdminPresenter.php` with Documents, Finance/house ledger, Vendors/Credentials, and Services methods.
- Reuse `ChecklistsDashboardData`, `SiteTypePlanService`, `SiteEmergencyPlanService`, `HouseLedgerPresenter`, `DeviceRegistryService`, and canonical H&S/Fleet/Finance scopes.

### Full-depth surfaces

- Each file under `resources/js/pages/sites/tabs/` owns a complete Site-context work surface and raises a typed `SiteProfileIntent`.
- Extract reusable inner surfaces from `plan/index.tsx`, `emergency-plan/index.tsx`, `hardware/index.tsx`, and `ledger/index.tsx`; their standalone wrappers keep `AppLayout`/`Head`.
- Embed `SiteCalendar`, `ChecklistsWorkspace`, and `MealPlannerSubTabs` directly.
- Reuse the existing Site-owned dialog modules under `contacts/`, `clients/`, `rooms/`, and the overview/geofence modules.

### Task 1: Lock the corrective contract in failing tests

**Files:**
- Create: `resources/js/test/site-profile-corrective-contract.test.ts`
- Create: `resources/js/test/site-profile-capability-ledger.test.ts`
- Modify: `resources/js/test/site-profile-operations-tabs.test.tsx`
- Modify: `resources/js/test/site-profile-safety-tabs.test.tsx`
- Modify: `resources/js/test/site-profile-admin-tabs.test.tsx`
- Modify: `resources/js/test/site-profile-shell.test.tsx`
- Test: `tests/Feature/Sites/SiteProfilePayloadTest.php`

- [ ] **Step 1: Add source-contract assertions**

  Assert that `show.tsx` imports `SiteProfileHero`, `AlertRibbon`, and `SiteProfileDialogHost`; does not import `PageHero` or `module-summary-panel`; maps every deferred tab to a unique prop; and renders the ribbon before tier-two tabs. Assert direct imports of `SiteCalendar`, `ChecklistsWorkspace`, the embedded Meal Planner, and the plan/emergency inner surfaces.

- [ ] **Step 2: Add ledger-structure assertions**

  Parse `docs/site-profile-corrective-capability-ledger.md` and assert the baseline SHA, all required headings, no `Removed` outcome, endpoint/permission coverage, and a closure column on every capability row.

- [ ] **Step 3: Add per-tab payload assertions**

  Replace `safetyData`/`operationsData`/`adminData` expectations with individual props such as `hazardsData`, `calendarData`, and `documentsData`. A partial request for one prop contains only that prop and never a sibling register.

- [ ] **Step 4: Run and record RED**

  Run:

      npm test -- resources/js/test/site-profile-corrective-contract.test.ts resources/js/test/site-profile-capability-ledger.test.ts resources/js/test/site-profile-shell.test.tsx resources/js/test/site-profile-safety-tabs.test.tsx resources/js/test/site-profile-operations-tabs.test.tsx resources/js/test/site-profile-admin-tabs.test.tsx
      php artisan test --compact tests/Feature/Sites/SiteProfilePayloadTest.php

  Expected: frontend names the missing dedicated hero/host/full embeds; PHP names missing individual optional props.

- [ ] **Step 5: Commit the failing contract**

      git add docs/site-profile-corrective-capability-ledger.md resources/js/test tests/Feature/Sites/SiteProfilePayloadTest.php
      git diff --cached --check
      git commit -m "test(sites): lock corrective profile contract"

### Task 2: Replace group payloads with per-tab deferred presenters

**Files:**
- Modify: `app/Http/Controllers/Sites/SiteProfileController.php`
- Modify: `app/Services/Sites/SiteProfileData.php`
- Create: `app/Services/Sites/Profile/SiteProfilePeoplePresenter.php`
- Create: `app/Services/Sites/Profile/SiteProfileSafetyPresenter.php`
- Create: `app/Services/Sites/Profile/SiteProfileOperationsPresenter.php`
- Create: `app/Services/Sites/Profile/SiteProfileAdminPresenter.php`
- Modify: `tests/Feature/Sites/SiteProfilePayloadTest.php`
- Modify: `tests/Feature/Sites/SiteProfileAuthorizationTest.php`
- Create: `tests/Feature/Sites/Profile/SiteProfileTabPresentersTest.php`

- [ ] **Step 1: Add failing permission/privacy cases**

  Cover one allowed and denied request per presenter, unknown/unauthorized tab normalization, foreign Site denial, assigned-client scope, archived write flags, and credential non-disclosure. Assert exact props: `clientsData`, `contactsData`, `staffRequirementsData`, `shiftCoverageData`, `hazardsData`, `riskAssessmentsData`, `inspectionsData`, `drillsData`, `firstAidData`, `ppeData`, `emergencyPlanData`, `calendarData`, `checklistsData`, `mealPlannerData`, `assetsData`, `fleetData`, `hardwareData`, `planData`, `documentsData`, `financialsData`, `vendorsCredentialsData`, and `servicesData`.

- [ ] **Step 2: Run and record RED**

      php artisan test --compact tests/Feature/Sites/SiteProfilePayloadTest.php tests/Feature/Sites/SiteProfileAuthorizationTest.php tests/Feature/Sites/Profile/SiteProfileTabPresentersTest.php

- [ ] **Step 3: Implement focused presenter methods**

  Move current group queries into domain presenters, expand each method to the full baseline shape, and keep `SiteProfileData::shell()` eager-only. Check module permission before querying. Credential data excludes encrypted value, IV, TOTP secret, decrypted value, and live code.

- [ ] **Step 4: Expose one optional prop per tab**

  In `SiteProfileController` authorize `view` before any presenter call, then register each prop with `Inertia::optional`. Keep Overview, Readiness, hero, counts, attention, visible-navigation permissions, and preferences eager.

- [ ] **Step 5: Run GREEN and commit**

      php artisan test --compact tests/Feature/Sites/SiteProfilePayloadTest.php tests/Feature/Sites/SiteProfileAuthorizationTest.php tests/Feature/Sites/Profile/SiteProfileTabPresentersTest.php
      git add app/Http/Controllers/Sites/SiteProfileController.php app/Services/Sites tests/Feature/Sites
      git diff --cached --check
      git commit -m "refactor(sites): defer each profile tab payload"

### Task 3: Build the dedicated Client-family Site hero and coordinator

**Files:**
- Create: `resources/js/components/sites/profile/hero.tsx`
- Create: `resources/js/components/sites/profile/alert-ribbon.tsx`
- Create: `resources/js/pages/sites/profile-types.ts`
- Create: `resources/js/pages/sites/site-profile-dialog-host.tsx`
- Modify: `resources/js/pages/sites/show.tsx`
- Modify: `resources/js/pages/sites/tabs/registry.ts`
- Modify: `resources/js/pages/sites/tabs/types.ts`
- Modify: `resources/js/pages/sites/tabs/site-profile-states.tsx`
- Create: `resources/js/test/site-profile-hero.test.tsx`
- Create: `resources/js/test/site-profile-dialog-host.test.tsx`
- Modify: `resources/js/test/site-profile-shell.test.tsx`
- Modify: `resources/js/test/site-profile-accessibility.test.tsx`

- [ ] **Step 1: Add failing hero tests**

  Render configured dark/light hex brands and organisation-primary fallback. Assert back link/Site ID, identity/status/type/region chips, permission-shaped Add/Log menu, Edit/More, three stats, operational and safety strips, four indicators, `GroupPillRail` footer, semantic warning/critical tokens, and readable foreground.

- [ ] **Step 2: Add failing loading/coordinator tests**

  Assert one tab requests only its prop, a matching transport exception settles to one labelled retry card, retry fires once, direct links normalize safely, pin/search state remains, mutation refresh lists only affected props, and tab/scroll remain.

- [ ] **Step 3: Run RED**

      npm test -- resources/js/test/site-profile-hero.test.tsx resources/js/test/site-profile-dialog-host.test.tsx resources/js/test/site-profile-shell.test.tsx resources/js/test/site-profile-accessibility.test.tsx

- [ ] **Step 4: Implement hero, ribbon, coordinator, and typed host**

  Mirror `resources/js/components/clients/profile/hero.tsx`. Use `site.brand_colour ?? hero.brand_colour ?? 'var(--primary)'` and an accessible black/white foreground for configured hex. Define a discriminated intent union for Site edits, placements, rooms/plan, documents, and canonical module navigation. Replace group loading with tab-prop loading and exact exception matching.

- [ ] **Step 5: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-hero.test.tsx resources/js/test/site-profile-dialog-host.test.tsx resources/js/test/site-profile-shell.test.tsx resources/js/test/site-profile-accessibility.test.tsx
      git add resources/js/components/sites/profile resources/js/pages/sites resources/js/test
      git diff --cached --check
      git commit -m "feat(sites): match client profile hero family"

### Task 4: Restore Overview, Readiness, and Site-owned edit intents

**Files:**
- Modify: `resources/js/pages/sites/tabs/overview.tsx`
- Modify: `resources/js/pages/sites/tabs/readiness.tsx`
- Modify: `resources/js/pages/sites/site-profile-dialog-host.tsx`
- Reuse: `resources/js/pages/sites/_overview-dialogs.tsx`
- Reuse: `resources/js/pages/sites/_overview-map-card.tsx`
- Reuse: `resources/js/pages/sites/_site-geofence-dialog.tsx`
- Modify: `app/Services/Sites/SiteProfileData.php`
- Modify: `resources/js/test/site-profile-overview.test.tsx`
- Create: `tests/Feature/Sites/Profile/SiteProfileOverviewPresenterTest.php`

- [ ] **Step 1: Add failing parity tests**

  Assert readiness banner/all fixes; occupancy; location/access/map/geofence; typed and derived contacts; Site safety metadata; services; notes add/delete; activity; Site lines; and contextual edit controls. An incomplete readiness item opens its exact resolution intent.

- [ ] **Step 2: Run RED**

      npm test -- resources/js/test/site-profile-overview.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileOverviewPresenterTest.php tests/Feature/Sites/SiteOperationalReadinessTest.php

- [ ] **Step 3: Restore full eager Overview and host dialogs**

  Keep contact info, location/access, Site safety metadata, notes, Site lines, and geofence Site-owned. Use `ConfirmAction` for destructive notes and announce successful saves.

- [ ] **Step 4: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-overview.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileOverviewPresenterTest.php tests/Feature/Sites/SiteOperationalReadinessTest.php tests/Feature/Sites/SiteGeofenceTest.php tests/Feature/Sites/SiteOverviewContactsUnificationTest.php
      git add resources/js/pages/sites app/Services/Sites/SiteProfileData.php resources/js/test tests/Feature/Sites/Profile
      git diff --cached --check
      git commit -m "feat(sites): restore profile overview workflows"

### Task 5: Restore People, placements, contacts, staff requirements, and coverage

**Files:**
- Modify: `resources/js/pages/sites/tabs/clients.tsx`
- Modify: `resources/js/pages/sites/tabs/contacts.tsx`
- Modify: `resources/js/pages/sites/tabs/staff-requirements.tsx`
- Modify: `resources/js/pages/sites/tabs/shift-coverage.tsx`
- Modify: `resources/js/pages/sites/site-profile-dialog-host.tsx`
- Modify: `app/Services/Sites/Profile/SiteProfilePeoplePresenter.php`
- Reuse: `resources/js/components/clients/add-client-dialog.tsx`
- Reuse: `resources/js/pages/sites/clients/link-client-dialog.tsx`
- Reuse: `resources/js/pages/sites/contacts/_dialogs.tsx`
- Reuse: `resources/js/pages/sites/rooms/_dialogs.tsx`
- Modify: `resources/js/test/site-profile-client-workflows.test.tsx`
- Create: `resources/js/test/site-profile-people-tabs.test.tsx`
- Create: `tests/Feature/Sites/Profile/SiteProfilePeoplePresenterTest.php`

- [ ] **Step 1: Add failing People tests**

  Cover full person cards, canonical creation, link existing, service/key-worker/room placement, profile navigation, unlink confirmation; contact add/view/edit/delete/primary/phone/email; staff requirement add/edit/delete; and coverage add/edit/delete with roles, overstaffing, service, client and live impact.

- [ ] **Step 2: Run RED**

      npm test -- resources/js/test/site-profile-client-workflows.test.tsx resources/js/test/site-profile-people-tabs.test.tsx
      php artisan test --compact tests/Feature/Sites/SiteClientTest.php tests/Feature/Sites/Profile/SiteProfilePeoplePresenterTest.php

- [ ] **Step 3: Restore full surfaces**

  Keep `AddClientDialog` as the only creation workflow, `LinkClientDialog` for placement, and existing contact/room dialogs. Restore staff and coverage forms against `sites.staff_requirements.*` and `sites.coverage_requirements.*`.

- [ ] **Step 4: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-client-workflows.test.tsx resources/js/test/site-profile-people-tabs.test.tsx
      php artisan test --compact tests/Feature/Sites/SiteClientTest.php tests/Feature/Sites/Profile/SiteProfilePeoplePresenterTest.php
      git add resources/js/pages/sites app/Services/Sites/Profile resources/js/test tests/Feature/Sites/Profile
      git diff --cached --check
      git commit -m "feat(sites): restore people profile workspaces"

### Task 6: Restore every Safety register and canonical action

**Files:**
- Modify: `resources/js/pages/sites/tabs/hazards.tsx`
- Modify: `resources/js/pages/sites/tabs/risk-assessments.tsx`
- Modify: `resources/js/pages/sites/tabs/inspections.tsx`
- Modify: `resources/js/pages/sites/tabs/drills.tsx`
- Modify: `resources/js/pages/sites/tabs/first-aid.tsx`
- Modify: `resources/js/pages/sites/tabs/ppe.tsx`
- Modify: `app/Services/Sites/Profile/SiteProfileSafetyPresenter.php`
- Reuse: `resources/js/components/health-safety/applicable-procedures-panel.tsx`
- Reuse: `resources/js/components/health-safety/site-chemicals-panel.tsx`
- Reuse: `resources/js/components/health-safety/risk-assessments/ra-register-section.tsx`
- Modify: `resources/js/test/site-profile-safety-tabs.test.tsx`
- Create: `tests/Feature/Sites/Profile/SiteProfileSafetyPresenterTest.php`

- [ ] **Step 1: Add failing full-register tests**

  Assert hazard ratings/dates/owners/actions/procedures/chemicals; risk lifecycle/review/hazard linkage; inspection schedules/results/history/findings; drill cadence/history/findings; first-aid treatment/follow-up/attachments; and PPE inventory/allocations/inspection/expiry/condemnation. Canonical actions carry `site_id`.

- [ ] **Step 2: Run RED**

      npm test -- resources/js/test/site-profile-safety-tabs.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileSafetyPresenterTest.php tests/Feature/Sites/HazardModuleTest.php

- [ ] **Step 3: Implement permission-shaped full registers**

  Remove `SiteProfileModuleSummary` from Safety. Use responsive mobile cards, shared date/status helpers, and canonical route intents. Eager-load row relationships to avoid N+1 queries.

- [ ] **Step 4: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-safety-tabs.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileSafetyPresenterTest.php tests/Feature/Sites/HazardModuleTest.php
      git add resources/js/pages/sites/tabs app/Services/Sites/Profile resources/js/test tests/Feature/Sites/Profile
      git diff --cached --check
      git commit -m "feat(sites): restore safety profile registers"

### Task 7: Embed complete Calendar, Checklists, and Meal Planner workspaces

**Files:**
- Modify: `resources/js/pages/sites/tabs/calendar.tsx`
- Modify: `resources/js/pages/sites/tabs/checklists.tsx`
- Modify: `resources/js/pages/sites/tabs/meal-planner.tsx`
- Modify: `app/Services/Sites/Profile/SiteProfileOperationsPresenter.php`
- Reuse: `resources/js/pages/sites/calendar/SiteCalendar.tsx`
- Reuse: `resources/js/components/checklists/workspace.tsx`
- Reuse: `app/Support/ChecklistsDashboardData.php`
- Reuse: `resources/js/pages/sites/meal-planner/index.tsx`
- Modify: `resources/js/test/site-profile-operations-tabs.test.tsx`
- Create: `resources/js/test/site-profile-embedded-workspaces.test.tsx`
- Create: `tests/Feature/Sites/Profile/SiteProfileEmbeddedWorkspacesTest.php`

- [ ] **Step 1: Add failing embed tests**

  Calendar exposes month/week/day/agenda/timeline, sources, filters, create/edit/delete/approve/reject. Checklists exposes Overview, Due now, Runs, Schedule, Assignments, Library, Reports, run/template modals and exception actions. Meal Planner exposes Planner, Recipes, Shopping List, Inventory, Templates and all dialogs.

- [ ] **Step 2: Run RED**

      npm test -- resources/js/test/site-profile-operations-tabs.test.tsx resources/js/test/site-profile-embedded-workspaces.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileEmbeddedWorkspacesTest.php

- [ ] **Step 3: Embed canonical components**

  Pass `context="profile"` and Site scope to `SiteCalendar`; Site scope/full data/`embedded` to `ChecklistsWorkspace`; and `mode="embedded"` to Meal Planner. Preserve `?run=` and component-level recovery.

- [ ] **Step 4: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-operations-tabs.test.tsx resources/js/test/site-profile-embedded-workspaces.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileEmbeddedWorkspacesTest.php tests/Feature/Sites/Calendar/SiteCalendarWorkflowTest.php tests/Feature/Sites/HouseChecklistTest.php
      git add resources/js/pages/sites/tabs app/Services/Sites/Profile resources/js/test tests/Feature/Sites/Profile
      git diff --cached --check
      git commit -m "feat(sites): embed complete operational workspaces"

### Task 8: Restore Assets, Fleet, and Hardware depth

**Files:**
- Modify: `resources/js/pages/sites/tabs/assets.tsx`
- Modify: `resources/js/pages/sites/tabs/fleet.tsx`
- Modify: `resources/js/pages/sites/tabs/hardware.tsx`
- Modify: `resources/js/pages/sites/hardware/index.tsx`
- Modify: `app/Services/Sites/Profile/SiteProfileOperationsPresenter.php`
- Create: `resources/js/test/site-profile-assets-fleet-hardware.test.tsx`
- Create: `tests/Feature/Sites/Profile/SiteProfileAssetsFleetHardwareTest.php`

- [ ] **Step 1: Add failing depth tests**

  Assets cover inventory, owner/assignment, status/risk/location and service cues. Fleet covers charts, vehicles, telemetry consent, WOF/registration, bookings, outings, stats and compliance. Hardware covers full register/filters/status, rooms, assignment and plan pins.

- [ ] **Step 2: Run RED**

      npm test -- resources/js/test/site-profile-assets-fleet-hardware.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileAssetsFleetHardwareTest.php

- [ ] **Step 3: Implement full surfaces**

  Reuse Fleet chart primitives and canonical URLs. Extract `SiteHardwareSurface` from the standalone page so both contexts use identical controls/routes. Keep actions permission-shaped.

- [ ] **Step 4: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-assets-fleet-hardware.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileAssetsFleetHardwareTest.php
      git add resources/js/pages/sites app/Services/Sites/Profile resources/js/test tests/Feature/Sites/Profile
      git diff --cached --check
      git commit -m "feat(sites): restore asset fleet and hardware depth"

### Task 9: Restore Floor Plan/Rooms and Emergency Plan without forks

**Files:**
- Modify: `resources/js/pages/sites/tabs/plan.tsx`
- Modify: `resources/js/pages/sites/tabs/emergency-plan.tsx`
- Modify: `resources/js/pages/sites/plan/index.tsx`
- Modify: `resources/js/pages/sites/emergency-plan/index.tsx`
- Modify: `resources/js/pages/sites/site-profile-dialog-host.tsx`
- Modify: `app/Services/Sites/Profile/SiteProfileOperationsPresenter.php`
- Modify: `app/Services/Sites/Profile/SiteProfileSafetyPresenter.php`
- Create: `resources/js/test/site-profile-plan-emergency.test.tsx`
- Create: `tests/Feature/Sites/Profile/SiteProfilePlanEmergencyTest.php`

- [ ] **Step 1: Add failing complete-surface tests**

  Plan includes published thumbnail, draft, full builder, duplicate/publish/discard, inventory, rooms/resources/zones, assignment, emergency layer, pins, device pins and print/download. Emergency includes organisation identity, plan/legend/contacts/procedures/notes/footer, paper/print/PDF/manage and emergency builder.

- [ ] **Step 2: Run RED**

      npm test -- resources/js/test/site-profile-plan-emergency.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfilePlanEmergencyTest.php tests/Feature/Sites/SiteTypePlanTest.php tests/Feature/Sites/SiteEmergencyPlanTest.php

- [ ] **Step 3: Extract shared inner surfaces**

  Export `SitePlanSurface` and `SiteEmergencyPlanSurface` from existing pages and keep standalone wrappers intact. Reuse `SiteTypePlanBuilderDialog` and room dialogs; do not create another canvas or persistence path.

- [ ] **Step 4: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-plan-emergency.test.tsx resources/js/pages/sites/plan/_geometry.test.ts
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfilePlanEmergencyTest.php tests/Feature/Sites/SiteTypePlanTest.php tests/Feature/Sites/SiteEmergencyPlanTest.php
      git add resources/js/pages/sites app/Services/Sites/Profile resources/js/test tests/Feature/Sites/Profile
      git diff --cached --check
      git commit -m "feat(sites): restore plan rooms and emergency surfaces"

### Task 10: Restore Documents, Finance/Ledger, Vendors/Credentials, and Services

**Files:**
- Modify: `resources/js/pages/sites/tabs/documents.tsx`
- Modify: `resources/js/pages/sites/tabs/financials.tsx`
- Modify: `resources/js/pages/sites/tabs/vendors.tsx`
- Modify: `resources/js/pages/sites/tabs/services.tsx`
- Modify: `resources/js/pages/sites/ledger/index.tsx`
- Modify: `resources/js/pages/sites/site-profile-dialog-host.tsx`
- Modify: `app/Services/Sites/Profile/SiteProfileAdminPresenter.php`
- Reuse: `resources/js/pages/sites/vendors/_dialogs.tsx`
- Reuse: `resources/js/pages/sites/credentials/_dialogs.tsx`
- Create: `resources/js/test/site-profile-admin-depth.test.tsx`
- Create: `tests/Feature/Sites/Profile/SiteProfileAdminPresenterTest.php`

- [ ] **Step 1: Add failing Admin tests**

  Documents cover category/folder/version/effective/expiry/upload/download/edit/delete. Finance covers Site summary and complete house ledger entries/attachments/add/reconcile. Vendors/Credentials cover full registers/actions/audit/TOTP with separate secure reveal. Services covers full contexts and management.

- [ ] **Step 2: Run RED**

      npm test -- resources/js/test/site-profile-admin-tabs.test.tsx resources/js/test/site-profile-admin-depth.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileAdminPresenterTest.php tests/Feature/Sites/HouseLedgerTest.php tests/Feature/Sites/SiteCredentialDialogTest.php

- [ ] **Step 3: Implement full surfaces**

  Restore Site-owned document dialogs, extract `SiteLedgerSurface`, reuse vendor/credential dialogs and canonical endpoints, keep reveal/TOTP separate and audited, and never serialize secret fields. Services remain a Site-context register.

- [ ] **Step 4: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-admin-tabs.test.tsx resources/js/test/site-profile-admin-depth.test.tsx
      php artisan test --compact tests/Feature/Sites/Profile/SiteProfileAdminPresenterTest.php tests/Feature/Sites/HouseLedgerTest.php tests/Feature/Sites/SiteCredentialDialogTest.php tests/Feature/Sites/SiteCredentialTotpTest.php tests/Feature/Sites/VendorsCredentialsGlobalTest.php
      git add resources/js/pages/sites app/Services/Sites/Profile resources/js/test tests/Feature/Sites/Profile
      git diff --cached --check
      git commit -m "feat(sites): restore profile administration workspaces"

### Task 11: Close authorization, refresh, accessibility, and ledger evidence

**Files:**
- Modify: `tests/Feature/Sites/SiteProfileAuthorizationTest.php`
- Modify: `tests/Feature/Sites/SiteProfilePayloadTest.php`
- Modify: `resources/js/test/site-profile-accessibility.test.tsx`
- Modify: `resources/js/test/site-profile-capability-ledger.test.ts`
- Modify: `docs/site-profile-corrective-capability-ledger.md`
- Delete if unused: `resources/js/pages/sites/tabs/module-summary-panel.tsx`

- [ ] **Step 1: Add failing integration assertions**

  Cover all props, restricted counts, direct-object denial, archived writes, validation preserving/focusing dialogs, success announcements, affected-prop refresh, keyboard/Escape/focus, dark tokens, desktop-table/mobile-card parity, and no browser alert/confirm.

- [ ] **Step 2: Run RED**

  Run the complete focused frontend profile matrix and:

      php artisan test --compact tests/Feature/Sites/SiteProfileAuthorizationTest.php tests/Feature/Sites/SiteProfilePayloadTest.php

- [ ] **Step 3: Fix only proved gaps and close ledger**

  Apply only failures proved by tests. Add an index only with query-plan evidence. Replace every `Open` ledger closure with `Restored`, `Canonical replacement`, `Improved`, or `Blocked` plus file/test evidence.

- [ ] **Step 4: Run GREEN and commit**

      npm test -- resources/js/test/site-profile-corrective-contract.test.ts resources/js/test/site-profile-capability-ledger.test.ts resources/js/test/site-profile-shell.test.tsx resources/js/test/site-profile-registry.test.ts resources/js/test/site-profile-overview.test.tsx resources/js/test/site-profile-client-workflows.test.tsx resources/js/test/site-profile-people-tabs.test.tsx resources/js/test/site-profile-safety-tabs.test.tsx resources/js/test/site-profile-operations-tabs.test.tsx resources/js/test/site-profile-embedded-workspaces.test.tsx resources/js/test/site-profile-assets-fleet-hardware.test.tsx resources/js/test/site-profile-plan-emergency.test.tsx resources/js/test/site-profile-admin-tabs.test.tsx resources/js/test/site-profile-admin-depth.test.tsx resources/js/test/site-profile-accessibility.test.tsx resources/js/test/site-profile-hero.test.tsx resources/js/test/site-profile-dialog-host.test.tsx
      php artisan test --compact tests/Feature/Sites/SiteProfileAuthorizationTest.php tests/Feature/Sites/SiteProfilePayloadTest.php tests/Feature/Sites/Profile
      git add tests/Feature/Sites resources/js/test resources/js/pages/sites/tabs docs/site-profile-corrective-capability-ledger.md
      git diff --cached --check
      git commit -m "test(sites): close corrective profile capability ledger"

### Task 12: Run final builds and real-browser acceptance

**Files:**
- Create: `docs/evidence/site-profile-corrective-redesign/index.md`
- Create: `docs/evidence/site-profile-corrective-redesign/*.png`
- Modify: `docs/site-profile-redesign-post-audit.md`

- [ ] **Step 1: Load browser verification skill**

  Read `oblivionfindings-frontline-browser-verification` completely. Start one exact scoped server from this worktree, record PID/host/HEAD, verify worktree identity, and stop only that PID.

- [ ] **Step 2: Run formatting and focused matrices sequentially**

      vendor/bin/pint --dirty
      npx prettier --check resources/js/components/sites/profile resources/js/pages/sites resources/js/test/site-profile-*.test.ts*
      npm test -- resources/js/test/site-profile-corrective-contract.test.ts resources/js/test/site-profile-capability-ledger.test.ts resources/js/test/site-profile-shell.test.tsx resources/js/test/site-profile-registry.test.ts resources/js/test/site-profile-overview.test.tsx resources/js/test/site-profile-client-workflows.test.tsx resources/js/test/site-profile-people-tabs.test.tsx resources/js/test/site-profile-safety-tabs.test.tsx resources/js/test/site-profile-operations-tabs.test.tsx resources/js/test/site-profile-embedded-workspaces.test.tsx resources/js/test/site-profile-assets-fleet-hardware.test.tsx resources/js/test/site-profile-plan-emergency.test.tsx resources/js/test/site-profile-admin-tabs.test.tsx resources/js/test/site-profile-admin-depth.test.tsx resources/js/test/site-profile-accessibility.test.tsx resources/js/test/site-profile-hero.test.tsx resources/js/test/site-profile-dialog-host.test.tsx resources/js/test/page-grouped-profile-nav.test.tsx resources/js/test/use-ui-preference.test.tsx
      php artisan test --compact tests/Feature/Sites tests/Feature/HealthSafety tests/Feature/Fleet tests/Feature/Finance

- [ ] **Step 3: Run types/builds sequentially**

      npm run types
      npm run build
      npx vite build --ssr
      git diff --check

- [ ] **Step 4: Compare actual Client Profile and Site Profile at 1440×900**

  Capture route, role, viewport, scenario, branch, and HEAD. Confirm identity hierarchy, actions/stats, operational/safety strips, four indicators, hero footer, ribbon position, tier-two rhythm, and no recent-sites strip.

- [ ] **Step 5: Verify variants and full surfaces**

  Cover configured-brand house, organisation-fallback house, facility/day-service, head office, restricted viewer, and archived Site. Exercise Calendar, Checklists, Meal Planner, Floor Plan/Rooms, Emergency Plan, and a representative action from every other group.

- [ ] **Step 6: Verify narrow/dark/keyboard**

  At 390×844 assert document scroll width equals client width, no clipped controls, operable mobile cards, dark mode, visible focus, keyboard group/tier/search navigation, dialog focus containment, Escape, and zero new console/runtime errors.

- [ ] **Step 7: Update audit/evidence and commit**

  Record exact commands/results and all browser evidence. State that merge, push, deployment, migrations, and live production proof were not performed.

      git add docs/evidence/site-profile-corrective-redesign docs/site-profile-redesign-post-audit.md docs/site-profile-corrective-capability-ledger.md
      git diff --cached --check
      git commit -m "docs(sites): close corrective profile acceptance"

## Plan self-review

- Tasks 2–3 cover the shell, dedicated hero, ribbon, grouped navigation, per-tab loading/retry, deep links, and typed intents.
- Tasks 4–10 cover every named baseline surface, dialog family, and canonical owner.
- Task 11 covers authorization, privacy, query/accessibility proof, and ledger closure.
- Task 12 covers TypeScript, PHP/frontend tests, client/SSR builds, and real-browser acceptance.
- No task authorizes capability removal. `module-summary-panel.tsx` is deleted only after all consumers are full surfaces.
- Per-tab prop names are identical in controller, registry, tests, and mutation refresh lists.
- The plan contains no silent deferral: unresolved acceptance becomes `Blocked`.
