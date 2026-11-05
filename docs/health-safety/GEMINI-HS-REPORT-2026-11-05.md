# Health & Safety — Gemini Polish, Accessibility & Test-Health Report (3–5 Nov 2026)

- **Worktree:** `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\gemini-hs`
- **Branch:** `gemini/hs-continue` (branched from `main` at `fda7c3273`)
- **Handover:** `docs/health-safety/HANDOVER-GEMINI-HS-2026-11-03.md` (`77d97ba30`)

---

## Tasks

### Task 1 — P10, a revised action from the Actions tab
- **Status:** Done (`54971ca3c`)
- **What changed & files:**
  - `resources/js/components/health-safety/event-detail-dialog.tsx`: On a safety review's `Actions` tab (`?section=actions`), rows whose independent verification marked the action ineffective now offer the same `Add revised action` button, `ActionCreateDialog` (`revisedFromAction`), and `canAddAction` / `canCreateCorrectiveActions` eligibility rules as the `Overview` tab instead of offering only `Add corrective action`.
  - `resources/js/components/health-safety/event-detail-dialog.test.tsx`: Added coverage for the `Actions` tab button, pop-up prefill, and permission gating.
- **Tests added/changed & result:**
  - Added in `resources/js/components/health-safety/event-detail-dialog.test.tsx`:
    - `offers "Add revised action" from the Actions tab with the same pop-up and permission rules as Overview`
  - Result: Passed (`event-detail-dialog.test.tsx` — 51/51 passed; also passed in Gate Step 2).
- **Screenshots:**
  - `.board/shots/task1-actions-tab-revised-button-light.png`
  - `.board/shots/task1-actions-tab-revised-button-dark.png`
  - `.board/shots/task1-revised-action-dialog-light.png`
  - `.board/shots/task1-revised-action-dialog-dark.png`
- **Anything not done:** None.

---

### Task 2 — P12, "The activity couldn't be loaded" on a corrective action page
- **Status:** Done (`e723aabbe`)
- **What changed & files:**
  - Root cause: `HsCorrectiveActionPresenter` sets `evidence.load_state` to `'unavailable'` both when an evidence query fails and when a cross-Site H&S reader (such as `admin` viewing `CA-2026-9013`) is lawfully restricted from incident-participant completion evidence. `HsCorrectiveActionWorkspace` coupled `history_state` directly to `evidence.load_state`, causing `corrective-actions/show.tsx` to render `state: 'failed'` (`"The activity couldn't be loaded"`) even though the corrective action's own non-private step timestamps (`created_at`, `assigned_at`, `started_at`, `completed_at`, `verified_at`, `closed_at`) and `ActionOwnershipHistory` were present.
  - `app/Services/HealthSafety/HsCorrectiveActionWorkspace.php`: Decoupled `history_state` (`'loaded'` vs `'restricted'` vs `'unavailable'`) from evidence privacy so restricted cross-Site reads keep completion evidence private without reporting a fake history load failure, while genuine query/load failures still return `'unavailable'`.
  - `resources/js/pages/health-safety/corrective-actions/show.tsx`: Rendered step activity from the record's own non-private timestamps and ownership history when `history_state === 'restricted'`, reserving `"The activity couldn't be loaded"` for genuine `'unavailable'` failures.
  - `tests/Feature/HealthSafety/HsCorrectiveActionWorkspaceTest.php`
  - `resources/js/pages/health-safety/corrective-actions/show.test.tsx`
- **Tests added/changed & result:**
  - Added in `tests/Feature/HealthSafety/HsCorrectiveActionWorkspaceTest.php`:
    - `test_cross_site_admin_read_keeps_evidence_private_without_reporting_a_history_load_error`
  - Added in `resources/js/pages/health-safety/corrective-actions/show.test.tsx`:
    - `shows step activity without "The activity couldn’t be loaded" for a cross-site admin whose evidence access is restricted`
    - `still shows the load-error message when participant history genuinely failed to load`
  - Result: Passed (`HsCorrectiveActionWorkspaceTest.php` — 10/10 passed; `show.test.tsx` — 11/11 passed; both passed in Gate Steps 1 & 2).
- **Screenshots:**
  - `.board/shots/task2-corrective-action-restricted-light.png`
  - `.board/shots/task2-corrective-action-restricted-dark.png`
  - `.board/shots/task2-10-corrective-action-loaded-light.png`
  - `.board/shots/task2-10-corrective-action-loaded-dark.png`
- **Anything not done:** None.

---

### Task 3 — P14, safety review titles in sentence case
- **Status:** Done (`5ed03a8f3`)
- **What changed & files:**
  - Incident records created through the report wizard store lower-case or snake_case type prefixes such as `injury incident` or `near_miss incident`. Formatted display titles in sentence case (`"Injury incident — Amelia Wilson"`, `"Near miss incident — Tane Wineera"`) at presentation time only, without rewriting stored database values or altering person/Site proper nouns:
    - `app/Support/HealthSafety/HsReviewTitle.php`
    - `app/Support/HealthSafety/HsStoryReviewPresenter.php`
    - `resources/js/components/health-safety/event-detail-dialog.tsx`
    - `tests/Unit/HealthSafety/HsReviewTitleTest.php`
    - `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- **Tests added/changed & result:**
  - Added data-provider cases in `tests/Unit/HealthSafety/HsReviewTitleTest.php` (`test_titles`):
    - `'incident with lower-case stored type title, reader'`
    - `'near miss with snake_case stored type title, reader'`
  - Added in `resources/js/components/health-safety/event-detail-dialog.test.tsx`:
    - `renders safety review titles in sentence case without altering person or Site names (P14)`
  - Result: Passed (`HsReviewTitleTest.php` — 16/16 passed; `event-detail-dialog.test.tsx` — 51/51 passed; both passed in Gate Steps 1 & 2).
- **Screenshots:**
  - `.board/shots/task3-10-safety-reviews-list-light.png`
  - `.board/shots/task3-10-safety-reviews-list-dark.png`
  - `.board/shots/task3-safety-review-overview-light.png`
  - `.board/shots/task3-safety-review-overview-dark.png`
- **Anything not done:** None.

---

### Task 4 — P15, one line instead of four in closure readiness
- **Status:** Done (`4319d3353`)
- **What changed & files:**
  - Added `collapsePendingWorksafeRequirements()` and `PENDING_WORKSAFE_DECISION_LABEL` (`"Record the WorkSafe decision first — it blocks WorkSafe notification, acknowledgement and Site preservation."`) so every surface showing closure readiness (Overview's `Closure readiness` disclosure, the `Closed` step progress panel, and `CloseReviewDialog`) collapses the four repeated WorkSafe lines into one plain sentence naming what it blocks while keeping all four statutory checks in the readiness counter (`4 things left · 1 done`).
  - Files:
    - `resources/js/components/health-safety/review-progress.tsx`
    - `resources/js/components/health-safety/event-detail-dialog.tsx`
    - `resources/js/components/health-safety/review-dialogs.tsx`
    - `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- **Tests added/changed & result:**
  - Updated in `resources/js/components/health-safety/event-detail-dialog.test.tsx`:
    - `shows one plain WorkSafe line while the decision is missing, keeping every check in the count`
    - *Why updated:* The existing test asserted the earlier generic copy (`"Record the WorkSafe decision first. The other WorkSafe steps depend on it."`) and did not check the `Closed` step's `Progress` region, which still rendered all four repeated `"Record the signed WorkSafe decision before confirming …"` items. Updated the assertion to require the explicit blocked-items sentence required by Task 4 (`"Record the WorkSafe decision first — it blocks WorkSafe notification, acknowledgement and Site preservation."`) across both `Closure readiness` and the `Closed` step `Progress` panel.
  - Result: Passed (`event-detail-dialog.test.tsx` — 51/51 passed; passed in Gate Step 2).
- **Screenshots:**
  - `.board/shots/task4-closure-readiness-deduped-light.png`
  - `.board/shots/task4-closure-readiness-deduped-dark.png`
- **Anything not done:** None.

---

### Task 5 — P18, report wizard accessibility and polish
- **Status:** Done (`6b6915db9`)
- **What changed & files:**
  - `resources/js/components/wizard/primitives.tsx`: Added explicit `aria-label` on `TileChoice` radio/button tiles so incident-type tiles expose clean accessible names, and enabled full-row toggling with accessible checkbox labelling on checkable wizard rows.
  - `resources/js/components/incidents/incident-report-dialog.tsx`:
    - Gave incident-type tiles and `"Straight away"` checkboxes accessible names and full-row click toggling (beyond the 16px checkbox box) while keeping 44px touch targets;
    - Aligned the Follow-ups `"Due"` date picker styling and height with its neighbouring controls;
    - Formatted `Occurred at` and follow-up `Due` dates on the `Review` step in NZ format instead of raw `YYYY-MM-DD HH:mm`;
    - Anchored the client `SelectContent` downward (`side="bottom"`, `avoidCollisions={false}`) so it never opens upward over the incident-type tiles.
  - `resources/js/components/incidents/incident-report-dialog.test.tsx`
- **Tests added/changed & result:**
  - Added in `resources/js/components/incidents/incident-report-dialog.test.tsx`:
    - `gives incident-type tiles and Straight away checkboxes accessible names and toggles the whole Straight away row`
  - Result: Passed (`incident-report-dialog.test.tsx` — passed locally and in Gate Step 2).
- **Screenshots:**
  - `.board/shots/task5-wizard-step1-light.png`
  - `.board/shots/task5-wizard-step1-dark.png`
  - `.board/shots/task5-wizard-straight-away-light.png`
  - `.board/shots/task5-wizard-straight-away-dark.png`
  - `.board/shots/task5-wizard-review-light.png`
  - `.board/shots/task5-wizard-review-dark.png`
- **Anything not done:** None.

---

### Task 6 — P8, one way to close a safety review
- **Status:** Done (`6918ea8d2`)
- **What changed & files:**
  - Checked the approved G-boards first: `hs-boards-2026-11-01/g3.html` (board M8) defines closing a safety review as a 480px `Close this safety review` modal on the pop-up kit.
  - Unified every close entry point (`?action=close` URLs/links, the `More` menu `Close safety review` item, and the `Progress` next-step `Close safety review` button) onto `CloseReviewDialog` (480px `PopupForm`), retiring the duplicate inline close panel. When statutory/closure checks are still incomplete, `CloseReviewDialog` shows the deduplicated plain-language blocker links and disables submission (or links to the independent close-anyway request flow where eligible) without loosening any close gate.
  - Files:
    - `resources/js/components/health-safety/review-dialogs.tsx`
    - `resources/js/components/health-safety/event-detail-dialog.tsx`
    - `resources/js/components/health-safety/event-detail-dialog.test.tsx`
- **Tests added/changed & result:**
  - Added in `resources/js/components/health-safety/event-detail-dialog.test.tsx`:
    - `opens the single 480 close pop-up from both ?action=close links and the Progress button, ready or blocked (P8)`
  - Result: Passed (`event-detail-dialog.test.tsx` — 51/51 passed; passed in Gate Step 2).
- **Screenshots:**
  - `.board/shots/task6-close-review-popup-light.png`
  - `.board/shots/task6-close-review-popup-dark.png`
- **Anything not done:** None.

---

### Task 7 — Retire `IncidentJourneyPresenter`
- **Status:** Done (`bacb29b99`)
- **What changed & files:**
  - Moved the active `journeyState()` cross-module state helper onto `App\Services\Incidents\IncidentJourneyService`, updated its three callers (`HsEventController`, `IncidentController`, `AlertWorkspaceService`), deleted the unmounted 567-line `app/Services/Incidents/IncidentJourneyPresenter.php`, preserved the active `journeyState()` tests in a dedicated `tests/Feature/Incidents/IncidentJourneyStateTest.php`, updated `.github/workflows/tests.yml` to reference `IncidentJourneyStateTest.php`, and removed the presenter-only tests (including the two skipped `NotInThisBuild` tests).
  - Modified:
    - `.github/workflows/tests.yml`
    - `app/Http/Controllers/HealthSafety/HsEventController.php`
    - `app/Http/Controllers/IncidentController.php`
    - `app/Services/ControlRoom/AlertWorkspaceService.php`
    - `app/Services/Incidents/IncidentJourneyService.php`
    - `tests/Feature/HealthSafety/RestraintDerivedWorkspacePrivacyTest.php`
  - Added:
    - `tests/Feature/Incidents/IncidentJourneyStateTest.php`
  - Deleted:
    - `app/Services/Incidents/IncidentJourneyPresenter.php`
    - `tests/Feature/Incidents/IncidentJourneyPresenterTest.php`
    - `tests/Feature/Incidents/IncidentJourneyPresenterCurrentOwnershipTest.php`
- **Grep verification (`git grep -n "IncidentJourneyPresenter" -- ':!docs'`):**
  ```text
  (0 matches — exit code 1)
  ```
- **Tests added/changed & result:**
  - Added `tests/Feature/Incidents/IncidentJourneyStateTest.php` (preserving active `IncidentJourneyService::journeyState()` coverage):
    - `test_a_missing_response_does_not_claim_live_response_or_complete_work`
    - `test_it_reports_each_cross_module_journey_state_from_canonical_records`
    - `test_standalone_terminal_alerts_never_report_an_active_response`
  - Deleted presenter-only test files `tests/Feature/Incidents/IncidentJourneyPresenterTest.php` and `tests/Feature/Incidents/IncidentJourneyPresenterCurrentOwnershipTest.php`, and removed the four `IncidentJourneyPresenter::present()` tests at the bottom of `tests/Feature/HealthSafety/RestraintDerivedWorkspacePrivacyTest.php` (*why changed:* those tests directly instantiated the retired `IncidentJourneyPresenter` to test its unmounted three-stage presenter payload; all live restraint-derived workspace privacy tests in `RestraintDerivedWorkspacePrivacyTest.php` remain intact).
  - Result: Passed (`IncidentJourneyStateTest.php` + `RestraintDerivedWorkspacePrivacyTest.php` — 28/28 passed locally and in Gate Step 1).
- **Screenshots:** None (no UI changed; retired unmounted backend presenter).
- **Anything not done:** None.

---

### Task 8 — H&S test health
- **Status:** Done (`869db74f9`)
- **Initial check (`git log` + single-file runs on `fda7c3273`):**
  - `DismissedAlertScopeTest`: 6 lone-worker tests still failed on `main`.
  - `HsLegacyStorageCompatibilityTest`: 2 tests failed with `UniqueConstraintViolationException` when run on kept/pre-seeded test databases.
  - `PpeCommandConcurrencyTest`: intermittently failed in repeated runs due to fixed-sleep barrier races and outer MySQL `1213` gap-lock deadlocks.
  - `RestraintOperationalAlertBridgeConcurrencyTest`: relied on a fixed `usleep()` window before locking source rows in `race()`.
- **What changed & files (code fixes vs test fixes):**
  1. **Code fix — `app/Services/HealthSafety/LoneWorkerSessionService.php`:**
     - `create()` previously checked shift/worker/site agreement before checking whether the actor could access the submitted `site_id`, `client_id`, or `shift_id` (returning `422` validation details for inaccessible cross-Site records instead of `403` denial), and when an accessible rostered shift disagreed with the selected `user_id` or `site_id` it only attached a validation error to `client_id`. Fixed `create()` to enforce `403` access checks on `site_id`, `client_id`, and `shift_id` first, and to report validation errors on `user_id` and `site_id` alongside `client_id` when a shift's worker or Site disagrees.
  2. **Code fix — `app/Services/HealthSafety/PpeAccessService.php`:**
     - `withCurrentViewer()` now retries outer MySQL `1213` serialization deadlocks (up to 3 attempts) when concurrent PPE commands gap-lock `ppe_source_versions` across `ppe_command_intent_claims`.
  3. **Test fix — `tests/Feature/ControlRoom/DismissedAlertScopeTest.php`:**
     - Updated:
       - `test_resident_tracking_omits_dismissed_alerts_from_live_stats_and_map_join`
       - `test_health_and_safety_alert_kpi_is_limited_to_the_users_accessible_sites`
       - `test_resident_tracking_alert_summaries_are_limited_to_clients_at_the_users_sites`
       - `test_lone_worker_options_only_include_accessible_sites_staff_clients_and_shifts`
       - `test_lone_worker_detail_does_not_expose_cross_site_sessions_or_alerts`
       - `test_lone_worker_coordinator_session_mutations_reject_another_sites_session`
       - `test_lone_worker_coordinator_alert_mutations_reject_another_sites_alert`
       - `test_lone_worker_own_worker_check_in_remains_ownership_authorized_only_for_that_workers_session`
       - `test_start_lone_worker_session_rejects_inaccessible_site_client_or_shift`
       - `test_start_lone_worker_session_requires_selected_shift_worker_client_and_site_to_agree`
     - *Why the test was wrong:* `main` had migrated lone-worker mutations onto reviewed command envelopes (`LoneWorkerReviewedCommandFixtures`), canonical `Signal` + `SignalSource` alert provenance, explicit `control_room.view` / `clients.viewAny` read permissions, and `404` concealment on standalone cross-Site session/alert routes, while `DismissedAlertScopeTest` still posted legacy unreviewed payloads without `Signal` provenance and expected `403` instead of `404` on standalone cross-Site session/alert concealment routes.
  4. **Test fix — `tests/Feature/HealthSafety/HsLegacyStorageCompatibilityTest.php`:**
     - Updated:
       - `test_hs_services_own_the_inert_legacy_storage_value`
       - `test_safeguarding_uses_hidden_inert_legacy_storage_compatibility`
     - *Why the test was wrong:* Called `Permission::create(['key' => ...])` unconditionally and hardcoded a colliding safeguarding `reference_number`, throwing `UniqueConstraintViolationException` whenever permissions or safeguarding fixtures already existed in kept test schemas. Switched to `Permission::firstOrCreate` and a unique reference number.
  5. **Test fix — `tests/Feature/HealthSafety/PpeCommandConcurrencyTest.php` & `tests/Feature/HealthSafety/RestraintOperationalAlertBridgeConcurrencyTest.php`:**
     - Updated the `race()`, `firstClaimRace()`, and `waitForSourceLocks()` synchronization helpers inside the test files only.
     - *Why the tests were wrong:* The parent test locked the source row before child PHP workers finished booting and reaching their ready file barrier (or waited with fixed sleeps instead of polling `performance_schema.data_lock_waits`), causing child workers to time out or miss the intended lock contention window under load.
- **Result:** All four test files passed locally (including repeated stress runs) and passed 100% in Gate Step 1 (`TEST_PROCESSES=2 composer test:fast`).
- **Screenshots:** None (backend/test-health task).
- **Anything not done:** None.

---

### Task 9 — Item #19, the logged race limits
- **Status:** Already fixed on main (`73541cca8`)
- **Verification:**
  - Checked `git log` and code on `fda7c3273`: commit `73541cca8` (`Enforce H&S item 19 race and current-authority boundaries`) already enforces mid-request current-authority `FOR SHARE` / `CurrentAuthorizationReads` re-checks on client risk summaries and investigation pack exports, and serializes same-UUID concurrent restraint submissions safely without duplicate events or alerts.
  - Ran existing suites locally and in Gate Step 1:
    - `tests/Feature/HealthSafety/HsRiskIncidentSourceCompositionTest.php` — 24 passed
    - `tests/Feature/HealthSafety/HsIncidentReportConsumerTest.php` — 33 passed
    - `tests/Feature/HealthSafety/RestraintOperationalAlertBridgeConcurrencyTest.php` — 4 passed
- **Screenshots:** None.
- **Anything not done:** None (already fixed on `main`).

---

### Task 10 — Accessibility pass over the main H&S pages
- **Status:** Done (`35418538f`)
- **Before vs after `axe-core` counts (Playwright headless Chromium across 39 H&S routes in light + dark mode = 78 page states):**
  - **Before (`38` routes × light/dark = `76` page states):**
    - Total `axe` violation nodes: **`174`** (`72` critical, `102` serious) across `57` rule occurrences.
    - **H&S-owned violation nodes: `136`**, broken down by rule:
      - `aria-required-parent` (critical, `70` nodes): `RiskMap` 5×5 matrix (`resources/js/components/signature/blocks.tsx`) placed `role="rowheader"`, `role="cell"`, and `role="columnheader"` directly inside `role="grid"` without `role="row"` parents.
      - `color-contrast` (serious, `50` H&S nodes): empty `RiskMap` cells (`rgba(..., 0.4)` text), `.sg-next` dark-mode muted text (`resources/css/signature-blocks.css`), and unlayered `.text-caption` overriding tinted status foregrounds on `/health-safety/events` table rows (`resources/js/pages/health-safety/events/index.tsx`).
      - `definition-list` (serious, `12` nodes): `RecordProgress` (`resources/js/components/signature/record-progress.tsx`) rendered trailing `<span>` siblings inside `<dl> > <div>` alongside `<dt>` and `<dd>`.
      - `aria-required-children` (critical, `2` nodes): paired with `RiskMap`'s `role="grid"`.
      - `aria-hidden-focus` (serious, `2` nodes): Recharts `<svg>` inside `FocusDonut`'s `aria-hidden="true"` visual wrapper (`resources/js/pages/health-safety/analytics-charts.tsx`) remained keyboard-focusable (`tabIndex=0`).
    - Out-of-scope global shell nodes: `38` dark-mode nodes (`1` per dark-mode page) on global `app-sidebar.tsx` `sidebar-badge-all-tasks` (`2.98:1`).
  - **After (`39` routes × light/dark = `78` page states, adding `/health-safety/corrective-actions/8` after fixing the seeded ID):**
    - **H&S-owned violation nodes: `0`** (`0` rules / `0` nodes across all `39` light-mode pages, and `0` H&S-owned nodes across all `39` dark-mode pages; the only `39` dark-mode nodes remaining are `1` per page on out-of-scope global `resources/js/components/app-sidebar.tsx` `sidebar-badge-all-tasks`).
- **What changed & files:**
  - `resources/js/components/signature/record-progress.tsx`: Moved optional item actions inside `<dd>` so `<dl>` groups contain only `<dt>` and `<dd>`.
  - `resources/js/components/signature/blocks.tsx` & `resources/css/signature-blocks.css`: Wrapped each `RiskMap` row in `<div role="row" className="sg-risk-row">` (`display: contents`) so the 5×5 visual layout is unchanged while satisfying ARIA grid hierarchy; tuned empty-cell and dark-mode `.sg-next` foreground contrast within semantic tokens to clear WCAG AA `4.5:1`.
  - `resources/js/pages/health-safety/events/index.tsx`: Replaced unlayered `.text-caption` overrides on tinted safety-review rows with mode-aware status foreground tokens and added keyboard `Enter`/`Space` row activation.
  - `resources/js/pages/health-safety/analytics-charts.tsx`: Disabled keyboard focus (`rootTabIndex={-1}` / `accessibilityLayer={false}`) on the `aria-hidden` decorative Recharts pie while keeping the accessible segment buttons interactive.
  - `resources/js/components/health-safety/event-detail-dialog.tsx` & `resources/js/pages/health-safety/events/story.tsx`: Preserved accessible labels on investigation narrative fields.
  - `resources/js/components/health-safety/hs-accessibility.test.tsx`: Added Vitest regression tests for the four fixed component patterns.
- **Tests added/changed & result:**
  - Added `resources/js/components/health-safety/hs-accessibility.test.tsx`:
    - `keeps <dl> groups in RecordProgress strictly composed of <dt> and <dd> children`
    - `wraps RiskMap rowheaders, cells, and columnheaders inside role="row" elements`
    - `keeps FocusDonut aria-hidden chart container free of tabbable SVG elements while exposing segment buttons`
    - `uses mode-aware status foreground utilities instead of unlayered text-caption on tinted safety review rows and supports keyboard activation`
  - Result: Passed (`hs-accessibility.test.tsx` — 4/4 passed locally and in Gate Step 2).
- **Screenshots:**
  - `.board/shots/task10-overview-light.png`
  - `.board/shots/task10-overview-dark.png`
  - `.board/shots/task3-10-safety-reviews-list-light.png`
  - `.board/shots/task3-10-safety-reviews-list-dark.png`
  - `.board/shots/task2-10-corrective-action-loaded-light.png`
  - `.board/shots/task2-10-corrective-action-loaded-dark.png`
  - `.board/shots/task10-risk-assessments-matrix-light.png`
  - `.board/shots/task10-risk-assessments-matrix-dark.png`
  - `.board/shots/task10-analytics-donut-light.png`
  - `.board/shots/task10-analytics-donut-dark.png`
  - `.board/shots/task10-restraints-register-light.png`
  - `.board/shots/task10-restraints-register-dark.png`
- **Anything not done:** Did not touch `resources/js/components/app-sidebar.tsx` (`sidebar-badge-all-tasks` dark-mode contrast `2.98:1`) because `app-sidebar.tsx` is global navigation outside H&S; listed under *Things noticed, not touched*.

---

### Task 11 — Local 6-role end-to-end headless browser walk
- **Status:** Done (`9198fe4d2`)
- **Roles & workflow verified on local temporary DB (dropped cleanly afterwards):**
  1. `sw1@demo.test` (`support_worker`): Reported a near miss (`INC-2026-0077`) via the wizard and confirmed permission boundaries.
  2. `lead.kauri-house@demo.test` (`team_lead`): Recorded a first-aid treatment at Kauri House (`FirstAidTreatmentWizard`), reported an injury incident (`INC-2026-0080`) for Amelia Wilson (verifying Task 5 accessible tiles, downward client picker, full-row `"Straight away"` toggle, and NZ-formatted `Review` date), and logged a physical hazard via `HazardCreateDialog` on `/compliance/hazards`.
  3. `manager@demo.test` (`provider_manager`): Assigned incident ownership and completed the manager review on `/incidents/220` (`INC-2026-0080`, linked to safety review `/health-safety/events/74`); added corrective action `/health-safety/corrective-actions/11`, started it, completed it, and verified that separation-of-duties blocks self-verification (`"Check it worked"` hidden from the completer/creator).
  4. `safety@demo.test` (`health_safety_officer`): Independently checked and verified `/health-safety/corrective-actions/11` as `Effective`.
  5. `compliance@demo.test` (`compliance_lead`): Walked H&S Overview, the close-anyway approval worklist, and read-only incident audit boundaries.
  6. `admin@demo.test` (`admin`): Verified the newly logged Kauri House hazard and verified corrective action in the organisation-wide registers (`0` server errors, `0` uncaught page errors).
- **Small bugs found & fixed during the walk (`9198fe4d2`):**
  1. **Referrerless `back()` redirect hijacking on `/incidents` and `/incidents/{id}`:** `IncidentController` sets `Referrer-Policy: no-referrer`, so browsers omit the `Referer` header on form POSTs from `/incidents` and `/incidents/{id}`. Background `fetch`/`axios` JSON GETs (`GET /health-safety/rail-counts`, `GET /incidents/{id}/owners/options`, `GET /incidents/drafts/{uuid}`, and `GET /health-safety/corrective-actions/{id}/owner-candidates`) lacked `X-Requested-With: XMLHttpRequest`, causing Laravel's `StartSession::storeCurrentUrl()` (`! $request->ajax()`) to overwrite `session('_previous.url')` with raw JSON endpoints. Submitting `POST /incidents` (`stay: true` modal report) or `POST /incidents/{id}/owner` then redirected `302` to raw JSON instead of the Inertia page, leaving `ReportSentPane` and `AssignOwnerDialog` stuck in real browsers. Fixed by adding `'X-Requested-With': 'XMLHttpRequest'` to those four background JSON lookups and guarding `IncidentOwnershipController` and `IncidentController` against polluted `session('_previous.url')` paths when `Referer` is absent.
  2. **Raw snake_case underscores on H&S Overview emergency drills:** `HsDashboardService` formatted `fire_evacuation` due items as `"Fire_evacuation drill — Kauri House"`. Fixed with `str_replace('_', ' ', ...)` so Overview displays `"Fire evacuation drill — Kauri House"`.
- **Files changed:**
  - `app/Http/Controllers/IncidentController.php`
  - `app/Http/Controllers/IncidentOwnershipController.php`
  - `app/Services/HealthSafety/HsDashboardService.php`
  - `resources/js/components/health-safety/action-owner-pane.tsx`
  - `resources/js/components/health-safety/workspace-header.tsx`
  - `resources/js/components/incidents/incident-ownership-panel.tsx`
  - `resources/js/hooks/use-incident-report-draft.ts`
  - `resources/js/components/incidents/incident-ownership-panel.test.tsx`
  - `tests/Feature/Incidents/IncidentOwnershipTest.php`
- **Tests added/changed & result:**
  - Added in `resources/js/components/incidents/incident-ownership-panel.test.tsx`:
    - `marks eligible-owner lookups as XMLHttpRequest so referrerless pages preserve their back URL`
  - Added in `tests/Feature/Incidents/IncidentOwnershipTest.php`:
    - `test_assigning_owner_without_referer_after_options_lookup_redirects_back_to_incident_page`
    - `test_stay_report_submission_without_referer_after_rail_counts_redirects_back_to_incidents_index`
  - Result: Passed (`incident-ownership-panel.test.tsx` — 18/18 passed; `IncidentOwnershipTest.php` — 17/17 passed; both passed in Gate Steps 1 & 2).
- **Screenshots:**
  - `.board/shots/task11-sw-nearmiss-review-light.png`
  - `.board/shots/task11-lead-firstaid-review-light.png`
  - `.board/shots/task11-lead-firstaid-saved-light.png`
  - `.board/shots/task11-lead-injury-submitted-light.png`
  - `.board/shots/task11-lead-hazard-review-light.png`
  - `.board/shots/task11-lead-hazard-logged-light.png`
  - `.board/shots/task11-lead-hazard-logged-dark.png`
  - `.board/shots/task11-manager-incident-review-dialog-light.png`
  - `.board/shots/task11-manager-incident-reviewed-light.png`
  - `.board/shots/task11-manager-incident-reviewed-dark.png`
  - `.board/shots/task11-safety-add-action-dialog-light.png`
  - `.board/shots/task11-manager-complete-action-light.png`
  - `.board/shots/task11-safety-verify-action-form-light.png`
  - `.board/shots/task11-safety-action-verified-light.png`
  - `.board/shots/task11-safety-action-verified-dark.png`
  - `.board/shots/task11-compliance-close-anyway-queue-light.png`
  - `.board/shots/task11-compliance-close-anyway-queue-dark.png`
  - `.board/shots/task11-admin-actions-register-light.png`
  - `.board/shots/task11-admin-actions-register-dark.png`
- **Anything not done:** None (broader cross-app observations are listed under *Things noticed, not touched*).

---

### Task 12 — Investigate only: `CheckControlRoomSlaBreaches` lock duration
- **Status:** Done (investigated and measured locally; **zero Control Room files changed**).
- **Why H&S pages show `"This page is busy"`:**
  - H&S workspace reads (`/health-safety`, `/health-safety/lone-workers`, `/health-safety/events/{id}`) execute inside `CurrentAuthorizationReads::within()`, which reads linked `control_room_alerts` rows using `LOCK IN SHARE MODE NOWAIT` (`FOR SHARE NOWAIT`).
  - While `CheckControlRoomSlaBreaches` holds an exclusive `FOR UPDATE` (`X`) lock on those `control_room_alerts` rows (and gap/index locks during chunk scans), concurrent H&S `FOR SHARE NOWAIT` queries abort immediately with MySQL error `3572` (`SQLSTATE[HY000]: General error: 3572 Statement aborted because lock(s) could not be acquired immediately and NOWAIT is set`), confirmed via a live two-connection probe. When the job holds locks across bounded `RetryPageReadOnLockConflict` retries, `CalmPageOnLockConflict` renders `503 This page is busy`.
- **Local measurements (32 seeded users, 30 role holders, 3 recipients per escalated alert on local NVMe MySQL):**
  1. **Unfiltered open-SLA scan (`150` open SLAs with `0` breaches):** `CheckControlRoomSlaBreaches::handle()` (`app/Jobs/CheckControlRoomSlaBreaches.php:36-68`) iterates *every* open `AlertSla` via `chunkById(100)` without a SQL `WHERE` filter for overdue unbreached deadlines, opening a `DB::transaction()` and taking `FOR UPDATE` locks on both `control_room_alerts` and `control_room_alert_sla` before checking `$sla->checkForBreaches()` in PHP — **`274.2 ms` wall time / `622` SQL queries** just to skip 150 non-breached rows (`1.83 ms` of X-lock churn per idle open SLA).
  2. **Single escalating breached alert transaction (`FOR UPDATE` X-lock hold):** **`234.05 ms` warm** (up to ~`920 ms` cold) and **`125` SQL queries** inside one `DB::transaction()`:
     - `lock_and_state_update` (`lockForUpdate` + SLA breach timestamps + escalation level update): **`3.70 ms` (`7` queries — 1.6% of lock time)**
     - `notify_sla_breach_escalation` (`ControlRoomNotificationService::notifySlaBreachEscalation()`): **`148.24 ms` warm (`108` queries — 86.4% of queries inside the lock!)**, driven by `resolveUsersByRoles()` re-querying role holders and lazy-loading `roles.permissions`, `permissionOverrides`, `hrEmployeeProfile`, and `staffProfile` per user, running per-user `findVisible` authorization queries, and synchronously inserting `notifications` + `control_room_communications` rows inside the row-lock transaction
     - `automation_on_alert_escalated`: **`11.42 ms` (`9` queries)**
     - `audit_log_or_fail`: **`0.40 ms` (`1` query)**
  3. **Realistic backlog tick (`150` idle open SLAs + `40` overdue breaching SLAs):** **`5,529.94 ms` (`5.53 s`)** and **`5,622` SQL queries** on local NVMe with 32 users — easily reaching ~`8 s` on staging/test datasets with more staff/sites or remote storage I/O.
- **Proposal to shorten lock hold to `< 5 ms` per breached alert without changing any decision:**
  1. **SQL pre-filter candidate SLAs before locking:** Add a `WHERE` clause on `AlertSla` for `(acknowledge_deadline <= $now AND acknowledged_at IS NULL AND acknowledge_breached = false)` OR `(response_deadline <= $now AND responded_at IS NULL AND response_breached = false)` OR `(resolution_deadline <= $now AND resolved_at IS NULL AND resolution_breached = false)` before `chunkById(100)`, while still re-checking `$sla->checkForBreaches()` after `lockForUpdate()` inside the transaction. Idle open alerts incur **zero** `FOR UPDATE` locks.
  2. **Pre-load role-holder graphs once per job tick outside the row lock:** Resolve candidate escalation role holders once per chunk with `with(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile', 'staffProfile'])` before opening per-alert `DB::transaction()` blocks, re-verifying current recipient visibility cheaply against the locked alert state.
  3. **Use the existing `control_room_communications` outbox + `afterCommit` delivery:** Stage escalation notification intent rows (`status = 'pending'`, deterministic `delivery_key`) inside the `DB::transaction()` — matching `ControlRoomNotificationService::stageAlertNotifications()` (`lines 57–133`) — and dispatch `DeliverControlRoomAlertNotifications` `afterCommit()` so per-recipient `notifications` writes happen after `control_room_alerts` `FOR UPDATE` locks are released.

---

## Gate

All five steps were executed sequentially, one at a time, through `heavy-lock.sh`:

1. **Pest (`TEST_PROCESSES=2 composer test:fast`, with `public/build/manifest.json` moved aside during the run and restored immediately afterwards):**
   - Command:
     `& "C:\Program Files\Git\bin\bash.exe" "C:/Users/steph/.claude/heavy-lock.sh" "gemini-hs-gate-pest" -- env TEST_PROCESSES=2 "C:/Users/steph/.config/herd/bin/php84/php.exe" "C:/Users/steph/.config/herd/bin/composer.phar" test:fast`
   - Summary line:
     `Tests:    106 failed, 3 risky, 14 notices, 156 skipped, 26028 passed (335068 assertions) — Duration: 10108.82s`
   - H&S / Incident / Task 8 result: **0 failures** in `Tests\Feature\HealthSafety\*`, `Tests\Unit\HealthSafety\*`, `Tests\Feature\Incidents\*`, or `Tests\Feature\ControlRoom\DismissedAlertScopeTest`.
2. **Vitest (`npx vitest run --maxWorkers=2`):**
   - Command:
     `& "C:\Program Files\Git\bin\bash.exe" "C:/Users/steph/.claude/heavy-lock.sh" "gemini-hs-gate-vitest" -- npx vitest run --maxWorkers=2`
   - Summary line:
     `Test Files  5 failed | 1163 passed | 9 skipped (1177)` / `Tests  11 failed | 9147 passed | 17 skipped (9175)` / `Duration  1607.79s`
   - H&S / Incident / Wizard / Signature result: **0 failures** in any Health & Safety, Incident, Wizard, or Signature suite.
3. **ESLint (`npx eslint .`):**
   - Command:
     `& "C:\Program Files\Git\bin\bash.exe" "C:/Users/steph/.claude/heavy-lock.sh" "gemini-hs-gate-eslint" -- npx eslint .`
   - Summary line:
     `[heavy-lock] gemini-hs-gate-eslint done (exit 0)` (0 errors, 0 warnings).
4. **TypeScript (`NODE_OPTIONS=--max-old-space-size=8192 npx tsc --noEmit`):**
   - Command:
     `& "C:\Program Files\Git\bin\bash.exe" "C:/Users/steph/.claude/heavy-lock.sh" "gemini-hs-gate-tsc" -- env NODE_OPTIONS=--max-old-space-size=8192 npx tsc --noEmit`
   - Summary line:
     `[heavy-lock] gemini-hs-gate-tsc done (exit 0)` (0 errors).
5. **Production build (`NODE_OPTIONS=--max-old-space-size=8192 npm run build`):**
   - Command:
     `& "C:\Program Files\Git\bin\bash.exe" "C:/Users/steph/.claude/heavy-lock.sh" "gemini-hs-gate-build" -- env NODE_OPTIONS=--max-old-space-size=8192 npm run build`
   - Summary line:
     `✓ 6463 modules transformed. ... ✓ built in 5m 48s` (`exit 0`).

### Pre-existing failures that also fail on `main` (`fda7c3273`, not modified)
- **Pest (106 failed tests across 28 non-H&S test classes):**
  - Web Push / VAPID (local environment OpenSSL EC key generation/signing): `Tests\Unit\Chat\ChatBrowserPushTransportTest` (24), `Tests\Unit\Push\VapidKeysTest` (5), `Tests\Unit\Push\WebPushSenderTest` (25), `Tests\Unit\Push\WebPushEncryptionTest` (2), `Tests\Feature\PushNotificationsWebPushTest` (4)
  - Sites / Rostering / Operations / Timesheets: `Tests\Feature\Sites\AddSiteModalE2ETest` (1), `Tests\Feature\Sites\AddSiteModalStoreTest` (2), `Tests\Feature\Sites\AddSiteModalValidationTest` (1), `Tests\Feature\Sites\SiteComplianceWorkflowTest` (1), `Tests\Feature\Rostering\WorkforceEligibilityAsyncRuntimeTest` (4), `Tests\Feature\Rostering\WorkforceAcceptedWorkloadIntegrityTest` (1), `Tests\Feature\Rostering\WorkforceShiftSaveCommandIntegrityTest` (1), `Tests\Feature\ShiftControlRoomSignalPipelineTest` (5), `Tests\Unit\Operations\ShiftReportingServiceTest` (1), `Tests\Unit\Services\ShiftStaffEligibilityServiceTest` (1), `Tests\Feature\Timesheets\TimesheetPayrollAdjustmentIntegrityTest` (1)
  - Security & Devices / Monitoring / IT: `Tests\Feature\Monitoring\MonitoringRecoveryOperatorWorkflowTest` (2), `Tests\Feature\Monitoring\MonitoringSchemaTest` (1), `Tests\Feature\Monitoring\NetworkMonitoringThresholdsAndRoutingTest` (1), `Tests\Unit\EndpointActionAuthorityTest` (1), `Tests\Unit\SecurityDevices\EndpointCommandSignerTest` (1), `Tests\Feature\It\ItApiCanonicalLifecycleTest` (1), `Tests\Architecture\ItSecuritySingleTenantBoundaryTest` (2)
  - eMAR / Medications: `Tests\Feature\MedicationsSafetyOverrideAuthorizationTest` (1), `Tests\Feature\Emar\WitnessPinReminderTest` (1), `Tests\Feature\MedicationOverdueAlertsTest` (1)
  - HR / Company Hub / Compliance / Settings / Security / Architecture: `Tests\Feature\Hr\RecognitionFeedShoutoutSeamTest` (1), `Tests\Feature\Hr\StaffOnboardingShiftEligibilityTest` (1), `Tests\Feature\Hr\HrCalendarFeedTest` (1), `Tests\Feature\Hr\HrMessageOnlyRecognitionTest` (1), `Tests\Unit\CompanyHub\HubDraftActorTest` (1), `Tests\Feature\CompanyHub\HubCampaignTest` (1), `Tests\Feature\CompanyHub\HubInsightsLinksTest` (1), `Tests\Feature\Compliance\ComplianceDashboardSiteScopeTest` (1), `Tests\Feature\Reporting\OperationalReportsTest` (1), `Tests\Feature\SettingsControllerTest` (1), `Tests\Feature\Settings\ProfileUpdateTest` (1), `Tests\Feature\RestrictedIndependentAuthoritySeedingTest` (2), `Tests\Architecture\IdentityPublicationMutexBoundaryTest` (1), and `Tests\Feature\SecurityAccessControlTest > incident update cannot escalate portal visibility without permission` (1 — verified untouched on `main`: `Client::factory()->create()` creates a `site_id => null` client that `ClientIncidentPolicy::update()` now denies with `403` under canonical Site access).
- **Vitest (11 failed tests across 5 non-H&S files):**
  - `resources/js/test/client-profile-phase-one-ui.test.tsx` (1)
  - `resources/js/test/device-management-section.test.tsx` (6)
  - `resources/js/test/site-profile-accessibility.test.tsx` (1)
  - `resources/js/pages/security-devices/devices/device-profile-header.test.tsx` (2)
  - `resources/js/pages/security-devices/devices/device-switch-tabs.test.tsx` (1)

---

## Questions for Stephan

1. **Hazard reference number format (`HAZ-2026-0001` vs older `HAZ-01024`):**
   - Newly logged hazards use year-scoped `HAZ-YYYY-NNNN` (`HAZ-2026-0001`) while older seeded/legacy records use `HAZ-NNNNN` (`HAZ-01024`). Should existing `HAZ-NNNNN` records keep their historical references permanently (displaying both formats side-by-side in registers), or should one canonical format be adopted going forward?
2. **Removing a draft safety procedure:**
   - Draft procedures in the Procedures register currently have no delete/discard action if created by mistake before approval. Should authors/managers be able to permanently delete an unapproved `draft` procedure (never published/approved versions), or archive/withdraw it with a reason?
3. **Archiving a private chemical exposure record:**
   - Individual worker chemical exposure records have no archive/supersede action when entered in error, unlike SDS/chemical inventory entries (and health-monitoring retention rules apply to valid exposure records). Should H&S officers have a governed `"Entered in error / archive"` workflow with mandatory reason and audit trail (preserving statutory retention while hiding voided rows from active lists), or keep exposure records strictly immutable?
4. **PPE `"Check for changes"` button at the top of a brand-new record:**
   - The PPE workspace renders a `"Check for changes"` refresh button in the header even when a brand-new PPE inventory item has just been created and has no prior changes. Should `"Check for changes"` be hidden completely unless a concurrent version conflict (`409` / stale `source_version`) is detected, or kept only as an inline action inside the stale-version banner?

---

## Things You Noticed, Not Touched

1. **App-wide `StartSession::storeCurrentUrl()` + `Referrer-Policy: no-referrer` interaction outside H&S/Incidents:**
   - `resources/js/app.tsx` does not set a global `axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'`, and several non-H&S modules call `fetch(url, { headers: { Accept: 'application/json' } })` without `X-Requested-With`. On any route that sets `Referrer-Policy: no-referrer` and returns `back()`, background JSON GETs will overwrite `session('_previous.url')` unless marked `XMLHttpRequest` (fixed for all H&S and Incident lookups in `9198fe4d2`).
2. **Global sidebar dark-mode badge contrast (`resources/js/components/app-sidebar.tsx`):**
   - `sidebar-badge-all-tasks` (`bg-red-600/90 text-white` over dark sidebar surface) measures `2.98:1` in dark mode (below WCAG AA `4.5:1`). Left untouched as global shell UI outside H&S.
3. **Fresh demo seeders have zero `is_first_aider = true` HR profiles:**
   - `FirstAidAccessService::eligibleStaff()` requires `hr_employee_profiles.is_first_aider = true`, which has `0` seeded rows in fresh demo databases, leaving the required `First aider` picker empty on `/health-safety/first-aid/report` until an employee profile is flagged as a first aider.
4. **`GET /health-safety/events/close-anyway-requests` returns raw JSON:**
   - Navigating directly to `/health-safety/events/close-anyway-requests` in a browser renders raw JSON (`{"requests":[]}`) because it is a JSON lookup endpoint rather than an Inertia page (the UI worklist lives on `/health-safety` Overview and safety review detail). Left route contract unchanged.
5. **Unmounted `resources/js/components/incidents/incident-journey-status.tsx`:**
   - Left untouched when retiring `IncidentJourneyPresenter` (Task 7) because its test file (`incident-journey-status.test.tsx`) also tests the shared `<AlertStatus>` component and was outside Task 7's explicit scope.

---

## Commits (`git log --oneline fda7c3273..HEAD`)

*(Preceding this report commit on `gemini/hs-continue`):*

```text
9198fe4d2 fix(health-safety): preserve referrerless incident back redirects and format drill labels
35418538f fix(health-safety): resolve WCAG AA axe violations across H&S pages and components
869db74f9 fix(health-safety): resolve lone-worker shift validation, PPE deadlock retry and H&S test health
bacb29b99 chore(hs): retire unmounted IncidentJourneyPresenter
6918ea8d2 Unify safety review closing onto the 480 pop-up kit (P8)
6b6915db9 fix(incidents): report wizard accessible names, full-row toggles and NZ date/picker polish (P18)
4319d3353 fix(health-safety): collapse pending WorkSafe closure checks into one clear line (P15)
5ed03a8f3 fix(health-safety): format safety review titles in sentence case
e723aabbe fix(health-safety): stop showing an activity load error on restricted corrective actions
54971ca3c fix(health-safety): offer Add revised action from safety review Actions tab
77d97ba30 docs(health-safety): add Gemini H&S handover for 3-5 Nov 2026
```
