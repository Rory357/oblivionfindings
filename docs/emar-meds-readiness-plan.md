# eMAR / Meds — Production-Readiness Plan

> Scope: stabilise the existing eMAR / medication stack so it is coherent, reliable, testable, and safe to ship incrementally. Prefer small targeted fixes over rebuilds. The implementation is largely in place — what's missing is convergence (two offline queues, two MAR pages, two admin recording paths), desktop polish on the canonical MAR, and offline parity for the guided round.

## Context

Stack: Laravel 12 + Pest, Inertia + React 19, Playwright 1.59 (`tests/e2e/*.spec.ts`, `data-test` testIdAttribute, MySQL CI). Canonical eMAR routes live at `/emar/*` (`routes/emar.php:47-302`); worker-facing surface at `/meds/*` (`routes/emar.php:33-45`); API at `/api/medications/*` (`routes/api_medications.php`). Two long-standing planning docs are archived at [`docs/archive/MEDICATION_MODULE_IMPLEMENTATION.md`](archive/MEDICATION_MODULE_IMPLEMENTATION.md) and [`docs/MEDICAL_MODULE_GAP_ANALYSIS.md`](MEDICAL_MODULE_GAP_ANALYSIS.md). Both are now partly out of date — this plan supersedes them where they conflict.

## 1. Current-state map

### Routes / surfaces

- **Worker home** — `/meds/today` → `WorkerMedsController::today` (`app/Http/Controllers/Emar/WorkerMedsController.php:47`) → renders `meds/today/index.tsx` (508 lines). Reads from `EnhancedMarService` + `GuidedRoundService` so numbers match `/my-day`.
- **Worker PRN quick flow** — `POST /meds/today/prn` → `WorkerMedsController::recordPrn` (`WorkerMedsController.php:103`). Uses `HandlesOfflineSubmission` trait + `EnhancedMarService::recordAdministration`. UI in `resources/js/components/prn-sheet.tsx` (763 lines) — bottom sheet with offline queueing via `submitOffline()`.
- **Worker guided round** — `GET /emar/rounds/{round}/guided` and `POST /emar/rounds/{round}/guided/items/{medication}` → `GuidedRoundController` (`app/Http/Controllers/Emar/GuidedRoundController.php`). UI in `resources/js/pages/meds/round/guided.tsx` (567 lines). One-med-at-a-time; safety/witness logic comes from `EnhancedMarService::recordAdministration`. Server has time-window dedup (`GuidedRoundController.php:106-116`); frontend uses plain Inertia `form.post()` with **no offline queueing**.
- **Admin MAR (canonical)** — `/emar/mar?client_id=&date=` → `EmarController::mar` → renders `resources/js/pages/emar/MarCharts.tsx` (1837 lines). This is what `medications/enhanced-mar/{client}`, `/clients/{id}/mar`, `/operations/clients/{id}/mar` all 302-redirect to (via `App\Support\EmarUrl`).
- **Admin daily list** — `/emar/daily` → `MedicationsController@index` (`routes/emar.php:54`).
- **Admin dashboard** — `/emar/` → `EmarController::dashboard` → renders `resources/js/pages/emar/Index.tsx` (705 lines). KPI grid, 7-day trend, donut, alerts, client-status grid.
- **Admin sub-pages** under `/emar/*`: `prn`, `controlled`, `medications`, `stock`, `prescriptions`, `competency`, `reviews`, `rounds`, `self-admin`, `destructions`, `handovers`, `errors`, `audit`, `emergency-access`, `reports`, plus PDF exports.
- **Operations admin MAR (parallel)** — `/operations/clients/{client}/mar` → `App\Http\Controllers\DailyMarController` (despite gap-analysis listing it "dead") → renders `resources/js/pages/operations/clients/mar.tsx` (518 lines). Posts admin recordings to `/operations/clients/{client}/medical/medications/{medication}/administrations` → `ClientMedicalController::storeAdministration` (`app/Http/Controllers/ClientMedicalController.php:387`). **No idempotency on this path**; no offline.
- **API** — `MedicationsApiController` (~1500 lines). The recording endpoint at `POST /api/medications/clients/{client}/medications/{medication}/administrations` is the most defensible one: uses `HandlesMedicationSync` semantics (idempotency cache, conflict detection on offline replays, structured `sync` envelope, scan verification). Used by `MarCharts.tsx` and `enhanced-mar.tsx` (orphaned).

### Worker-facing surfaces

- `meds/today/index.tsx` — frontline home: due now / due later / rounds / PRN quick action / overdue banner / empty state.
- `meds/round/guided.tsx` — full-screen "Given / Refused / Held" flow with sticky bottom action bar and a confirm/reason dialog.
- `prn-sheet.tsx` — bottom sheet PRN flow, with reason chips, dose, notes, controlled-drug witness hint.
- The worker PRN sheet is also reachable from `operations/clients/{id}/care` via `preselectedClient` (good — same component, both contexts).
- Bottom nav: `staff-bottom-nav.tsx`, with Meds slot pinned to `/meds/today`.

### Offline behavior

There are **two parallel offline systems** running side by side:

1. **PR 26 / `lib/offline-queue.ts`** (490 lines, IndexedDB-backed)
   - Used for `prn` and `progress_note` actions only.
   - `bootOfflineQueue()` registers `online`/`visibilitychange` listeners and replays queued items.
   - `OfflineStatusBanner` (`resources/js/components/offline-status-banner.tsx`) is mounted at app root in `app.tsx:50` and surfaces pending count + syncing state.
   - Per-submission `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline` flags.
   - Drops on 409 / 422 (terminal). Drops after 8 retries to keep the queue bounded.
   - Server side: `HandlesOfflineSubmission` trait used by `WorkerMedsController::recordPrn` and `Operations\ProgressNoteController`.
2. **`lib/emar-offline.ts`** (368 lines, localStorage-backed)
   - Used for `MarCharts`, `ControlledDrugs`, `StockManagement`, `ScheduledStockCounts`, `ResidentTransport*`, `shift-medication-card`.
   - Different storage key (`emar-offline-queue:v1`), different device-id key (`emar-offline-device-id:v1`).
   - **No max-retry cap** — bad items can keep cycling.
   - **Not represented in the OfflineStatusBanner** — pending count goes to `getQueuedEmarMutationCount()` only.
   - Server side: `HandlesMedicationSync` trait used by `EmarController`, `CDLossReportController`, `FleetAssets\ResidentTransportController`, `MedicationsApiController` (inline cache helpers).
3. **Service worker** (`public/sw.js`, 100 lines)
   - Network-first cache for `/emar`, `/emar/mar`, `/emar/medications`, `/emar/stock` and two fleet-asset routes.
   - **`/meds/today` and `/meds/round/{id}/guided` are not in the shell cache.** A worker who loads `/meds/today` once online and then reloads while offline will get the browser's offline error page, not a cached one.
   - Caches `/api/medications/*` GETs network-first. POSTs are not intercepted (queueing is at the JS layer).

### Components and reuse

`resources/js/components/medications/`:

- `RecordAdministrationDialog.tsx` — full record form, takes `safetyCheck`, `prnData`, `witnesses`. Used by `enhanced-mar.tsx` and `MarCharts.tsx`.
- `SafetyCheckPanel.tsx`, `PrnHistoryPanel.tsx`, `RefusalFollowUpDialog.tsx`, `AdministrationEvidenceDialog.tsx`, `SupportingEvidenceDialog.tsx`.
- Form-specific helpers: `InhalerAdminFields.tsx`, `InjectableAdminFields.tsx`, `InsulinAdminFields.tsx`, `TopicalAdminFields.tsx`, `SpecialistAdminFields.tsx`.
- `MedicationScanVerificationPanel.tsx`, `MedicationVersionHistory.tsx`, `ScheduledStockCounts.tsx`, `DrugInteractionManager.tsx`, `DashboardWidgets.tsx`.

`resources/js/components/emar/`:

- `ClientAllergyBanner.tsx`, `DrugInteractionAlert.tsx`.

`resources/js/components/`:

- `prn-sheet.tsx` (worker), `med-round-card.tsx`, `staff-bottom-nav.tsx`, `staff-page-shell.tsx`, `offline-status-banner.tsx`, `flash-toaster.tsx`, `dictate-button.tsx`.

### Services (PHP)

- `EnhancedMarService` (`app/Services/EnhancedMarService.php`, 573 lines) — single source of truth. Builds the MAR view, runs `recordAdministration` inside a transaction with `lockForUpdate` on the medication and on stock for controlled drugs (`EnhancedMarService.php:438-489`). Creates the `ClientControlledDrugEntry` register row when `status === given` and the medication is controlled (`EnhancedMarService.php:494-527`).
- `MedicationSafetyService` — allergies, interactions, duplicates, PRN limits, time-window, expiry.
- `MarScheduleService` — schedule + window calculations.
- `MedicationAlertService`, `MedicationReportingService`, `MedicationIncidentIntegrationService`, `MedicationScanVerificationService`.
- `GuidedRoundService` — round progress, used by `WorkerMedsController` for `/meds/today` numbers.

### Existing test coverage

- **Pest unit/feature**:
  - `tests/Feature/MedicationControllerTest.php` — 165 tests. Strong coverage of `ClientMedicalController` admin flows: permissions, validation, controlled-drug witness, time-window reason rules, break-glass, stock updates, discrepancies. Does **not** test idempotency, `client_request_uuid`, or offline replay.
  - `tests/Feature/MedicationsApiControllerTest.php` — 3 tests. Cross-client 404 sanity only.
  - `tests/Unit/MedicationSafetyServiceTest.php` — safety logic.
  - `tests/Feature/Contracts/AlertIdempotencyTest.php` — alert dedup, not medication idempotency.
- **Dusk smoke**:
  - `tests/Browser/Emar/EmarPagesTest.php` — 13 page-loads-without-throwing.
  - `tests/Browser/Emar/EmarDashboardTest.php` — 5 smoke tests.
  - `tests/Browser/Medications/MedicationDashboardTest.php` — 3.
  - `tests/Browser/Medications/MedicationPagesTest.php` — 2.
- **Playwright**: **no eMAR / meds e2e specs.** All existing specs are rostering / my-day / attendance.

### Cross-module touch points

- **My Day**: `meds_due` and `active_round` come through `MyTasksController::getMedicationsDue` and `GuidedRoundService::progress`. Frontline meds bottom-nav lives in `my-day` and points at `/meds/today`.
- **Shifts**: `recordAdministration` consumes `shift_id`, attributes the dose to the active shift, and inherits `service_context_id` from it.
- **Audit**: `TimelineEvent` rows written by `MedicationsApiController::recordAdministration` and admin paths. Trait `AuditableChanges` on the medication models. `AuditLogger::log('medications.scan.verify', ...)` for scan attempts.
- **Incidents**: `MedicationIncidentIntegrationService` auto-creates incidents for missed / refused-high-risk / late / PRN-over-limit / controlled discrepancy.
- **Permissions**: `medications.view`, `medications.administer.record`, `medications.administer.correct`, `medications.controlled.witness`, `medications.controlled.record`, `medications.orders.manage`, `medications.stock.update`, `medications.reports.export`, `medications.audit.view`, `medications.breakglass`. Plus the broad `clients.update` / `clients.viewAssigned` fallbacks used throughout.

---

## 2. Production-readiness gaps

### P0 — must fix before production

1. **Operations admin recording path has no idempotency or conflict detection.** `ClientMedicalController::storeAdministration` (`app/Http/Controllers/ClientMedicalController.php:387-475`) does not validate `client_request_uuid` and does not call `HandlesMedicationSync`. A flaky network or a fast double-tap from `operations/clients/mar.tsx:178-194` can create duplicate administrations because `useForm.post(...)` does not include the offline metadata. The `/api/medications/.../administrations` path is hardened (idempotency cache, 409 on offline-vs-server divergence) — the Inertia path is not. **This is two paths to the same write with materially different safety, both visible from admin UIs.**
2. **Guided round administration has no offline queueing.** `pages/meds/round/guided.tsx:163-183` uses plain `form.post()`. A worker who walks a round on a tablet that drops connectivity loses the in-flight dose. The server-side dedup (`GuidedRoundController.php:106-116`) only protects against double-submits if the request actually lands; it does not queue. The `recordPrn` peer endpoint already wires `HandlesOfflineSubmission` — round administer should too.
3. **Service worker shell does not cache `/meds/today` or `/meds/round/{id}/guided`.** `public/sw.js:2-9` lists `/emar` and `/emar/mar` only. A worker on a residential site with patchy Wi-Fi will see Chrome's "no internet" page when reloading `/meds/today`, even though the JS bundle is already cached. PR 12's whole point is that frontline workers don't bounce into `/emar`.
4. **Two parallel offline queues that don't share state.** `lib/emar-offline.ts` (localStorage, no banner, no retry cap) and `lib/offline-queue.ts` (IndexedDB, banner, 8-retry cap). The Status banner only reflects the second one. A worker can have 5 queued admin actions in localStorage and zero in IndexedDB, see "all clear" in the banner, and be misled. Worse: a worker who hits localStorage's 5MB ceiling silently drops queue items.
5. **MarCharts (canonical desktop MAR) is too long for one page and doesn't read as desktop UI.** 1837 lines, stacked-card layout that's the same on a 27-inch monitor as on an iPad, and a free-text time picker for navigation. There is no two-pane layout (med list + detail), no keyboard navigation between rows, no fixed table header. This is the "weak desktop layouts" problem GPT-5.5 flagged.
6. **`enhanced-mar.tsx` (641 lines) is orphaned but still in the bundle.** `routes/medications.php:35-39` 302-redirects `/medications/enhanced-mar/{client}` to `/emar/mar`, so the page never renders. But the file ships, and one in-app link at `pages/medications/index.tsx:140` still points at the redirect URL. It contains an `alert()` for errors (`enhanced-mar.tsx:212`) and a custom-rolled modal with `bg-white` instead of design tokens (`enhanced-mar.tsx:622-636`). Either delete it or wire it up — leaving it half-alive bloats the bundle and makes "is this code live?" a fresh question each time.
7. **No e2e (Playwright) coverage of any medication recording flow.** Dusk smoke covers page-loads-without-throwing. Pest covers controller validation. Nothing exercises the actual frontline path: open `/meds/today` → tap PRN → record → see the count update, or walk a guided round to completion. This is the highest-value coverage to add given how many tablets will run this in production.
8. **Offline conflict / replay path is untested end-to-end.** No test forces an `online → record → ACK lost → reconnect → replay` flow. The idempotency caching, the `409 conflict` payload, and the toast surface all live in code with zero exercising-them-together tests. `tests/Feature/Contracts/AlertIdempotencyTest.php` covers something else.

### P1 — should fix before production if time allows

9. **Desktop layout polish on `MarCharts.tsx`.** Independent of P0-5: a fixed table-header for the day view, a per-medication detail panel that opens beside the list (md:lg breakpoint) instead of a full-width modal, keyboard arrow nav between rows, sortable columns. None of these need a rebuild — they're additive. See §5.
10. **`pages/medications/dashboard.tsx` is orphaned-similar to `enhanced-mar.tsx`.** Route `/medications/dashboard` 302-redirects to `/emar` (`routes/medications.php:28-32`), but the file (252 lines, hits its own `/api/medications/dashboard/widgets` endpoint) is still in the bundle. Decide: delete or repurpose.
11. **PRN over-limit incident throttle is per-minute** (`MedicationsApiController.php:752-756`). Acceptable but not visible — operators won't know a PRN attempt was rejected unless they see the toast. Surface in the worker UI.
12. **Worker view of "did my queued PRN actually land?"** PR 26's banner says "1 item waiting to send" but doesn't link to anywhere. The worker sees the dose disappear from "Given last 24h" until reconnect. A small "queued — will send" pill on the affected PRN row in `prn-sheet.tsx` would close the loop.
13. **Round-administer permission surface has a small gap.** `routes/emar.php:105-112` gates the guided round on `medications.administer.record|clients.update|medications.orders.manage`. `WorkerMedsController::today` uses the same OR-list. But `MarCharts.tsx`-driven recordings go through the API which uses `medications.administer.record|clients.update`. So a user with `medications.orders.manage` only can walk a round but cannot record from `/emar/mar`. Documented inconsistency, low security risk, easy to align.
14. **`pages/emar/MarCharts.tsx:212` has hard-coded `bg-white`** in modal — should use `bg-card`. Same shape of issue exists elsewhere; the codebase has tokens (see `docs/DESIGN_TOKENS.md`).
15. **Audit-log page `pages/medications/audit.tsx` (426 lines) does not paginate.** `MedicationAuditController` returns up to a fixed limit; on a year-old tenant this will be slow and unstable. Compare `pages/operations/timesheets/approvals.tsx` for a pagination pattern.
16. **`prn-sheet.tsx` does not surface the offline-banner state.** A worker can pick a PRN, type a reason, hit "Record" while offline, and only realise it queued from the toast — not from any pre-submission cue. Adding a one-line "Will send when you're back online" hint above the submit button when `useOfflineQueueState().online === false` is a 5-line change.

### P2 — polish / later

17. **Two-MAR-page divergence isn't actually causing user pain** because the orphans don't render — but bundle hygiene matters. Schedule a delete pass once P0-6 / P1-10 are settled.
18. **`MEDICATION_MODULE_IMPLEMENTATION.md` and `MEDICATION_UI_PAGES_CHECKLIST.md` predate the consolidation to `EnhancedMarService` and the EmarUrl redirect.** They reference routes that don't render and pages that are orphaned, and have been moved under `docs/archive/` with archive notes.
19. **No bulk-record affordance** on `MarCharts.tsx`. Useful for residential settings where one round delivers six identical doses to one resident. Not a blocker; a paper-process workaround exists.
20. **Scheduled stock count UI is unimplemented** (gap-analysis GAP-002). Model exists, scheduled job exists. P2 unless the audit framework forces it.
21. **Allergen master list** (gap-analysis GAP-005) — drift in client-entered allergen strings ("Penicillin" vs "PCN"). P2; current `MedicationAllergy::isMatching` is permissive enough.
22. **Bar/QR code scanning** — `MedicationsApiController::recordAdministration` already accepts `scan_code` and verifies it; the UI on `MarCharts.tsx` includes the scanner panel. Worker pages do not. Not a P0.

---

## 3. Minimal implementation plan

Each ticket is sized so that one PR ships one ticket. Risk levels are LOW (markup / additive), MED (touches a write path), HIGH (changes a transactional contract).

### PR A — Idempotency on the operations admin recording path — P0-1

- **Goal**: bring `ClientMedicalController::storeAdministration` to parity with `MedicationsApiController::recordAdministration` for offline-replay safety.
- **Files**:
  - `app/Http/Controllers/ClientMedicalController.php` — `use HandlesMedicationSync;` add `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline` validation rules; call `getCachedIdempotentResponse('administration', $data)` before the write; call `rememberIdempotentResponse('administration', $data, $payload)` after success; on offline-replay-with-existing-record, emit `buildConflictPayload` with HTTP 409.
  - `resources/js/pages/operations/clients/mar.tsx:178-194` — add a UUID to the form payload (use `crypto.randomUUID()`), include `captured_offline_at: new Date().toISOString()`. Reuse `submitEmarMutation` if we want full offline; minimum bar is the UUID + idempotency.
- **Acceptance**:
  - Posting the same payload twice (same UUID) returns the cached response; only one administration row exists.
  - Posting an offline-queued admin against a medication that already has a competing administration in the same minute returns 409.
  - All existing 165 tests in `MedicationControllerTest.php` still pass; one new test asserts duplicate-UUID behaviour and one asserts conflict.
- **Risk**: MED — touches the canonical Inertia admin path.
- **Tests**: see PR I.

### PR B — Offline queueing on the guided round — P0-2

- **Goal**: a worker mid-round on patchy Wi-Fi never loses a dose record.
- **Files**:
  - `app/Http/Controllers/Emar/GuidedRoundController.php` — `use HandlesOfflineSubmission;` accept `client_request_uuid`/`captured_offline_at`/`origin_device_id`/`queued_offline`; wrap the existing `DB::transaction` body in `runOfflineSubmissionOnce('round_admin', $data, fn() => …)`. The existing time-window dedup is **kept** (it now backs up the UUID guard, double belt-and-braces).
  - `resources/js/pages/meds/round/guided.tsx:151-183` — replace the plain `form.post()` with `submitOffline({ action: 'round_admin', url, payload, queuedMessage: 'Saved on this device — will send when you\'re back.' })`. Keep the UI snappy by advancing the index optimistically (already does this).
  - `resources/js/lib/offline-queue.ts:28` — extend the `OfflineAction` union to include `'round_admin'`.
- **Acceptance**:
  - Set `navigator.onLine = false` in tests, click Given, see the toast and the index advance, see the IDB queue row.
  - Bring the network back, see the queued row land, see the round counters update on next reload.
  - A repeated replay of the same UUID does not create a second administration.
- **Risk**: MED — touches a write path used in active rounds.
- **Tests**: see PR I.

### PR C — Service worker shell cache for worker pages — P0-3

- **Goal**: `/meds/today` and `/meds/round/{id}/guided` work after a hard reload on a flaky connection.
- **Files**:
  - `public/sw.js:2-9` — extend `OFFLINE_URLS` with `/meds/today` (and a placeholder for the round, see note). Bump `CACHE_NAME` to `of-app-shell-v3` so old caches purge on activate.
  - The guided round URL is parameterised. Either (a) add a regex match in `isCacheableNavigation` for `/meds/round/*/guided`, or (b) accept that a hard reload during a round falls back to `/meds/today` (network-first with `/meds/today` as fallback). Recommend (b) — workers re-enter via `/meds/today` and resume the round.
- **Acceptance**:
  - DevTools → Application → Cache Storage shows `/meds/today` after first visit.
  - Reload `/meds/today` while offline; page loads from cache.
  - Old cache entries for `of-app-shell-v2` are purged on activate.
- **Risk**: LOW — additive; service-worker bumps already worked once for `v2`.
- **Tests**: covered by Playwright in PR I (offline visit).

### PR D — Converge the two offline queues — P0-4

This is the largest single piece of work in this plan. It's still scoped: we do not write a new abstraction. We migrate `lib/emar-offline.ts` callers to `lib/offline-queue.ts` and delete the localStorage queue.

- **Goal**: one offline submission queue, one device-id, one banner that knows about everything.
- **Approach**:
  1. Extend `OfflineAction` (`lib/offline-queue.ts:28`) with the eMAR action types currently in `emar-offline.ts` (`administration`, `correction`, `cd_loss_report`, `cd_entry`, `stock_update`, `transport_*`).
  2. For each call site of `submitEmarMutation` (8 files — `ControlledDrugs.tsx`, `MarCharts.tsx`, `StockManagement.tsx`, `ScheduledStockCounts.tsx`, `shift-medication-card.tsx`, `transports/show.tsx`, `transports/medications.tsx`), swap to `submitOffline({ action, url, payload })` and translate the `SubmitMutationResult` → `SubmitOfflineResult` shape used by the receiving callbacks. Both expose `status: 'queued' | 'sent'` so the rename is mechanical. Keep `success/duplicate/rejected` toasts at the call site (the new lib doesn't auto-toast on success).
  3. Delete `lib/emar-offline.ts` and remove `bootEmarOffline()` from `app.tsx:11,41`.
  4. Server-side, `HandlesMedicationSync` and `HandlesOfflineSubmission` both still exist — they serve different envelope shapes. Keep both. The cache key prefix `emar:idempotency:` and `offline:idempotency:` continue to namespace cleanly.
- **Files**:
  - `resources/js/lib/offline-queue.ts` — extend action union; harden against >100 queued items by emitting a warning toast (rare in practice).
  - `resources/js/lib/emar-offline.ts` — delete after callers migrate.
  - `resources/js/app.tsx:11,41` — drop `bootEmarOffline`.
  - All 7 view callers — mechanical swap.
- **Acceptance**:
  - One queue surface, visible in `OfflineStatusBanner`.
  - All actions retain idempotency and conflict handling.
  - Lighthouse / network panel shows no second IDB / localStorage write.
  - No regressions in `MarCharts.tsx` recording flow.
- **Risk**: MED — many call sites, mechanical change.
- **Tests**: PR I extends to assert pending count visible in banner across surfaces.

### PR E — Desktop layout polish for `MarCharts.tsx` — P0-5 / P1-9

- **Goal**: make the canonical MAR usable on a 1440px monitor without a rebuild. See §5 for the concrete layout plan.
- **Files**:
  - `resources/js/pages/emar/MarCharts.tsx` — introduce a `lg:grid-cols-[minmax(0,1fr)_360px]` two-pane layout: left = the day's medication rows; right = a sticky detail panel that updates on row click. On `< lg`, the detail panel becomes a `Drawer` triggered by tap. Use `<table>` semantics for the day list at `lg+` (sticky header, tabular-nums, keyboard arrows between rows) and the existing card list at `< lg`.
  - Add `data-test="mar-row"`, `data-test="mar-row-record"`, `data-test="mar-detail-pane"`, `data-test="mar-date-prev/next"`.
  - Replace `bg-white` (`enhanced-mar.tsx:622`, anywhere in `MarCharts.tsx`) with `bg-card`.
- **Acceptance**:
  - On 1440×1000, the day list and the detail panel are visible side by side; clicking a row updates the panel without a full reload.
  - On tablet (Pixel 7 viewport), the cards-with-modal pattern is unchanged.
  - Keyboard `↑/↓` moves selection in the day list; `Enter` opens the record dialog.
- **Risk**: MED-LOW — additive layout, opt-in keyboard behaviour, no API change.
- **Tests**: Playwright `meds-mar-desktop.spec.ts` (PR I).

### PR F — Delete or repurpose `enhanced-mar.tsx` and `medications/dashboard.tsx` — P0-6 / P1-10

- **Goal**: stop shipping orphaned pages.
- **Approach**: delete both. Update `pages/medications/index.tsx:140` to point at `/clients/${c.id}/mar` (which already 302-redirects to `/emar/mar`). Drop the redirect routes that go nowhere useful — `/medications/enhanced-mar/{client}` and `/medications/dashboard`. Move `MEDICATION_MODULE_IMPLEMENTATION.md` and `MEDICATION_UI_PAGES_CHECKLIST.md` from the repo root into `docs/archive/` and add a stub paragraph linking to this plan.
- **Files**:
  - `resources/js/pages/medications/enhanced-mar.tsx` — delete.
  - `resources/js/pages/medications/dashboard.tsx` — delete.
  - `resources/js/pages/medications/index.tsx:140` — update link target.
  - `routes/medications.php:21-39` — drop the orphan redirects; keep `/medications` redirect intact.
  - `MEDICATION_MODULE_IMPLEMENTATION.md`, `MEDICATION_UI_PAGES_CHECKLIST.md` — move + archive note.
  - `docs/MEDICAL_MODULE_GAP_ANALYSIS.md` — annotate "see emar-meds-readiness-plan.md for current state".
- **Acceptance**:
  - `vite build` ships fewer chunks; visual regression unchanged.
  - No internal link 404s; the redirect URL still works for any external bookmark (we keep `/clients/{id}/mar`).
- **Risk**: LOW — deletes only.

### PR G — Worker UX touch-ups — P1-11 / P1-12 / P1-16

- **Goal**: small, frontline-shaped affordances.
- **Files**:
  - `resources/js/components/prn-sheet.tsx:221-270` — when offline, render an inline "Will send when you're back online" hint above the submit button (`useOfflineQueueState().online === false`).
  - `resources/js/pages/meds/today/index.tsx` — on PRN rows that are currently queued (cross-reference `getOfflineQueueSnapshot()`), show a small "queued" pill.
  - `resources/js/pages/meds/round/guided.tsx` — same "queued" pill on items that have been actioned offline-locally but not yet ACKed by the server.
- **Acceptance**:
  - A worker can tell at a glance whether their last record landed on the server.
- **Risk**: LOW — visual hints only.

### PR H — Permission alignment for round administration — P1-13

- **Goal**: align the OR-list across round-administer / API-administer.
- **Files**:
  - `routes/emar.php:105-112` and `routes/api_medications.php:35-37` and `routes/operations.php:280-282` — settle on `medications.administer.record|clients.update|medications.orders.manage` everywhere a write happens, or document the deviation.
- **Acceptance**:
  - One OR-list for med-admin writes. RBAC tests cover all three.
- **Risk**: LOW — permissions only; covered by Pest.

### PR I — Test coverage to lock the above in — P0-7 / P0-8 / P1-15

Bundles all test work. Risk LOW (tests-only).

- **Pest**:
  - `tests/Feature/MedicationsApiControllerIdempotencyTest.php` — duplicate-UUID returns cached, conflict-on-replay returns 409, success caches for 7 days.
  - `tests/Feature/Operations/ClientMedicalAdministrationIdempotencyTest.php` — PR A behaviour.
  - `tests/Feature/Emar/GuidedRoundOfflineReplayTest.php` — PR B behaviour, including the time-window dedup still functions when no UUID is present (legacy path).
  - `tests/Feature/Routing/MedicationRouteAlignmentTest.php` — assert the 3 OR-lists from PR H match.
- **Vitest**:
  - `lib/offline-queue.test.ts` — replay on `online` event, drop on 8 retries, 409/422 terminal.
  - `components/offline-status-banner.test.tsx` — renders pending count, hides when clear.
- **Playwright** (`tests/e2e/`, all chromium-desktop + chromium-mobile):

| # | Spec | Role | Starting state | Action | Expected |
|---|---|---|---|---|---|
| 1 | `meds-today-loads.spec.ts` | sw with assigned shift | Has 2 due-now meds, 1 PRN configured | Visit `/meds/today` | Both due-now rows visible, PRN button enabled, no console errors |
| 2 | `meds-prn-quick-record.spec.ts` | sw | Same | Tap "Give as-needed med" → pick → reason → submit | Toast "Saved …", `given_last_24h` increments by 1 on next prop reload |
| 3 | `meds-prn-offline-queue.spec.ts` | sw | Same; `context.setOffline(true)` after page load | Submit a PRN | Banner shows "1 item waiting"; `setOffline(false)` → banner clears, PRN visible in history |
| 4 | `meds-guided-round.spec.ts` | sw | One in-window round with 3 items | Walk Given / Refused (with reason) / Held (with reason) | Round completes, all 3 administrations linked to round_id, summary tiles correct |
| 5 | `meds-guided-round-offline.spec.ts` | sw | One in-window round; offline mid-walk | Submit Given on item 2 while offline, then reconnect | Item 2 administration lands once, no duplicates, round counters honest |
| 6 | `meds-mar-desktop.spec.ts` | admin | Client with 4 scheduled doses today | Visit `/emar/mar?client_id=…` on chromium-desktop | Two-pane layout visible; row click updates detail panel; `↓ Enter` opens record dialog |
| 7 | `meds-mar-mobile.spec.ts` | admin | Same, chromium-mobile | Same | Cards-with-modal still works, no horizontal scroll |
| 8 | `meds-controlled-witness.spec.ts` | admin | Client with a controlled drug due | Record without witness → record with same-user witness → record with valid witness | Two errors, then success; controlled-drug register row created |
| 9 | `meds-permission-boundaries.spec.ts` | sw without `medications.administer.record` | Visit `/meds/today` | 403 |

- **Selectors**: prefer `getByRole` for visible buttons; add `data-test="meds-due-row"`, `data-test="meds-prn-button"`, `data-test="meds-prn-submit"`, `data-test="meds-round-given"`, `data-test="meds-round-refused"`, `data-test="meds-round-held"`, `data-test="mar-row"`, `data-test="mar-detail-pane"`. Per project policy.
- **Fixtures**: extend `database/seeders/FrontlineLifecycleDemoSeeder.php` with `sw-meds@demo.test` who has an in-progress round and a configured PRN. Helper: `loginAsMedsDemoWorker(page)` in `tests/e2e/helpers.ts`.

---

## 4. What not to touch

- **`EnhancedMarService::recordAdministration` transactional shape** (`EnhancedMarService.php:438-489`) — the lock-for-update on medication and stock, the controlled-drug register write, the safety-check + time-window guards. All passed through 165 Pest tests. Do not refactor.
- **`MedicationSafetyService` rules** — allergy / interaction / duplicate / PRN-limit / time-window. Tuning thresholds is product, not readiness.
- **Permission middleware on read endpoints** (`medications.view|clients.viewAny|clients.viewAssigned`). They're consistent across read paths.
- **The `EmarUrl::mar` redirect chain.** It's the single forwarding mechanism that lets us delete orphan pages in PR F without breaking external bookmarks.
- **`HandlesMedicationSync` trait** — different envelope from `HandlesOfflineSubmission` (returns a `sync` object on success/conflict). Don't merge them; PR D consolidates only the **client** queue.
- **`AuditLogger::log('medications.scan.verify', ...)`** and the scan-verification UI (`MedicationScanVerificationPanel.tsx`). Currently unused on worker surfaces but tested and wired through the API. Don't gut.
- **Feature flags** — there are none specifically for medications. Don't introduce one for the layout pass; the redesign is opt-in by viewport.
- **Dusk smoke tests under `tests/Browser/{Emar,Medications}/`** — they're cheap and they catch route-load regressions. They're not Playwright; don't migrate them as part of this plan.
- **`MedicationOrderVersion` versioning** — used by `MedicationVersionHistory.tsx`, write path inside the medication-update controller. Untouched.
- **Round of seven controller-side validations in `recordAdministration`** (status enum, witness same-user, witness permission, PRN reason, refused-reason, time-window-reason). They duplicate some service-level checks deliberately for the API path.

### Tempting refactors to defer

- Pull `MedicationsApiController` (~1500 lines) into per-resource controllers. Worth doing eventually; not necessary for production.
- Convert the worker meds bottom-nav into a router-aware component. Cosmetic.
- Replace the two offline traits with one that returns either envelope shape based on a flag. The current split is small and clear.

---

## 5. Desktop layout plan for `MarCharts.tsx`

Concrete, scope-bounded. No rebuild.

### Layout structure (PR E)

- Container: `mx-auto max-w-7xl px-4 py-4` on top of `AppLayout`.
- Header strip stays as today: client picker (left), date prev/today/next (right), allergy + interaction banners full-width below.
- **At `lg` and above**: `lg:grid lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-4`. Left = day list. Right = sticky detail panel (`lg:sticky lg:top-4`).
- **Below `lg`**: keep the current single-column card layout. The detail panel becomes a `Drawer` (shadcn) triggered by row tap, no behavioural change.
- KPI strip (`stats.given/refused/missed/withheld/pending`) renders inline above the day list, full-width, `grid-cols-2 md:grid-cols-5`.

### Day list (left pane, `lg+`)

- Render as a real `<table>` (a11y + keyboard nav for free): `<thead>` sticky with `position: sticky; top: 0; background: bg-background;`. Columns: time / med / dose / route / status / actions. Use `tabular-nums` on time and dose.
- Every row gets `data-test="mar-row"`, `tabIndex={0}`, `aria-selected`.
- Selected row gets `bg-muted` ring; `↑/↓` arrows move selection; `Enter` opens the record dialog; `Esc` closes the detail panel on small screens.
- Status pills reuse the existing `pillForStatus` map — no new colour tokens.

### Detail panel (right pane, `lg+`)

- Header: med name + dosage + state pill (Active / Paused / Ceased).
- Sections (collapsible, all expanded by default):
  - **Selected dose** — scheduled time, current administration (if any), record/correct buttons.
  - **Safety** — `SafetyCheckPanel` (existing).
  - **PRN history** (if PRN) — `PrnHistoryPanel` (existing).
  - **Recent administrations** (last 5 from `history`) — date, status, by, reason.
  - **Order details** — instructions, indication, prescriber, pharmacy, dose times.
  - **Attachments** — `AdministrationEvidenceDialog` link.
  - **Version history** — link to `MedicationVersionHistory` (existing component).
- No new components built. The panel composes existing ones.

### Administration history (lower section, full-width)

- Today the history list is appended below scheduled rows. Move it into the detail panel for the selected medication on `lg+` (it's already in the API response under `history`).
- Keep the legacy "all-medications history" list at the bottom of the page, collapsed by default with a "Show today's full history" disclosure — that view answers "what was administered to this client overall today" which the per-med detail panel doesn't.

### Responsive behaviour

- `< lg`: today's stacked-card layout, modal record dialog, drawer for detail. No regression.
- `lg`–`xl`: two-pane.
- `xl`+ (1280px+): two-pane plus a wider detail panel (`lg:grid-cols-[minmax(0,1fr)_440px]`).

### Worker-focused scanning and action flow

- `MarCharts.tsx` is the **admin** surface. Worker scanning lives on `/meds/today` and `/meds/round/{id}/guided` — those are already action-flow shaped (large buttons, sticky footer, one-med-per-screen). No layout change needed there.
- On `MarCharts.tsx`, the `MedicationScanVerificationPanel` (existing) renders inside the record dialog. Keep it that way; don't pull scanning into the day list.

---

## 6. Offline eMAR plan

The minimum bar to declare the offline path production-safe.

### Local queue

- One queue (PR D), IndexedDB-backed (`oblivion-offline` DB, `submissions` store, `client_request_uuid` as key).
- Per-submission metadata: `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline`, `attempts`, `lastError`.
- Action types after PR D: `prn`, `progress_note`, `round_admin` (PR B), `administration` (PR A move), `correction`, `cd_loss_report`, `cd_entry`, `stock_update`, `transport_*`. **One union, one storage.**
- Retry cap of 8 — beyond which the item drops with a toast asking the worker to re-enter. Already implemented in `lib/offline-queue.ts`.

### Sync status visibility

- `OfflineStatusBanner` (already mounted at `app.tsx:50`) shows: offline indicator, syncing spinner, pending count.
- PR G adds a per-row "queued" pill on `/meds/today` and `/meds/round/.../guided` so workers see exactly which items are pending.
- The banner sits above the frontline sticky header (`z-50`). Don't move it.

### Retry behaviour

- Triggers: `online` event, `visibilitychange` to visible, app boot if `navigator.onLine`.
- Replay is sequential per item (`replayOne`) — first network-level error halts the sweep until the next trigger. Permanent failures (`409`, `422`) drop the item.
- Backoff is implicit (next `online` event). For now don't add timer-based retries; the trigger set is enough.

### Duplicate prevention

- Server-side: idempotency cache keyed by `({scope}, client_request_uuid)`, 7-day TTL. Already in place for API admin paths and for PRN/progress-note via traits.
- After PR A, the operations Inertia admin path is also covered.
- After PR B, the round administer path is covered.
- The guided-round time-window dedup (`GuidedRoundController.php:106-116`) is a backstop for callers who don't send a UUID.

### Conflict handling

- The "queued offline → server has a competing record" case returns 409 with a `sync.message` payload — already implemented for the API admin path (`MedicationsApiController.php:694-715`) and replicated by PR A for the operations Inertia path and PR B for round administer.
- Client-side: `lib/emar-offline.ts:192-208` (today) emits a `CustomEvent('emar:offline-conflict')` and a toast. After PR D, `lib/offline-queue.ts` should do the same — extend `replayOne` to dispatch the event and drop the item on 409.
- A "supervisor review" surface is **not** a P0 — the conflict toast tells the worker to re-record, which is sufficient until incidents start showing the volume.

### Audit logging

- Server-side: `TimelineEvent` rows include `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline` flags (`MedicationsApiController.php:776-790`). Replicate on PR A and PR B writes.
- The administration row already has the metadata via the JSON `meta` column on the timeline entry. No new schema.

### User feedback

- "Saved on this device — will send when you're back online" toast on queue.
- "Queued item sent" / "Queued items sent" toast on replay success.
- "A queued item could not be saved" toast on terminal failure with a clear "please re-enter" hint.
- All copy already exists in `offline-queue.ts`. Don't change.

---

## 7. Testing plan

### Existing setup to build on

- Pest with isolated per-PID MySQL DB (`.github/workflows/tests.yml`).
- Playwright config: `playwright.config.ts` — `testDir: ./tests`, `testMatch: /.*\.spec\.ts/`, `testIdAttribute: 'data-test'`, `trace: 'retain-on-failure'`, `screenshot: 'only-on-failure'`, projects `chromium-desktop` (1440×1000) and `chromium-mobile` (Pixel 7), `webServer: php -S 127.0.0.1:4173`.
- Helpers: `tests/e2e/helpers.ts` — already has `loginAsFrontlineDemoWorker`, `loginAsStaff`. Add `loginAsMedsDemoWorker(page)`.
- Fixtures: seeded by `migrate:fresh --seed` in CI. Extend `FrontlineLifecycleDemoSeeder` with the meds scenarios listed in PR I.

### Unit tests (Vitest)

- `lib/offline-queue.test.ts` — submit-online happy path, submit-offline queue, replay-on-online, drop-on-409, drop-after-8-retries, banner-state-broadcast.
- `components/offline-status-banner.test.tsx` — visibility states, copy variants.
- `components/prn-sheet.test.tsx` — reason chips render, free-text fallback, controlled-drug witness hint.
- `components/medications/RecordAdministrationDialog.test.tsx` — required-reason logic, witness validation, PRN history rendering.

### Feature / integration tests (Pest)

- `tests/Feature/MedicationsApiControllerIdempotencyTest.php` — duplicate UUID, 409 conflict, 7-day TTL.
- `tests/Feature/Operations/ClientMedicalAdministrationIdempotencyTest.php` — PR A.
- `tests/Feature/Emar/GuidedRoundOfflineReplayTest.php` — PR B.
- `tests/Feature/Emar/WorkerMedsTodayPayloadTest.php` — `/meds/today` payload shape, due-now sort, fallback when no shift context.
- `tests/Feature/Emar/PrnQuickRecordTest.php` — `WorkerMedsController::recordPrn` happy path, controlled-drug witness flow, PRN-over-limit incident, idempotent replay.
- `tests/Feature/Routing/MedicationRouteAlignmentTest.php` — PR H.
- `tests/Feature/MedicationsScanVerificationTest.php` — scan-verify code matching, audit log entries.

### Offline / sync tests (Pest)

- `tests/Feature/Offline/MedicationOfflineReplayContractTest.php` — given a UUID seen previously, the API returns the cached `sync` envelope without re-running the write. Use `Cache::store('array')` for determinism.
- `tests/Feature/Offline/MedicationOfflineConflictTest.php` — given a queued offline payload for a `scheduled_for` that already has an administration recorded, the API returns 409 with the conflict envelope.

### Manual QA scenarios

- **Worker, online, happy path**: clock in → `/my-day` → tap Meds → record a Given on a due dose → check banner stays clean → return to `/my-day` → see counter decrement.
- **Worker, online, refused**: same but Refused with a reason → see refusal-followup created where applicable.
- **Worker, offline mid-round**: airplane mode → walk 3 items → reconnect → see banner show "Sending 3…" → see all 3 administrations on `/emar/mar` afterwards.
- **Worker, controlled drug**: record a controlled dose without a witness → see error → with same user → see error → with valid witness → see the controlled-drug register row.
- **Admin, two-pane MAR**: visit `/emar/mar?client_id=…` on a 1440px screen → see two-pane → click rows → see detail update without reload → arrow-key down → enter to record.
- **Admin, mobile MAR**: same on Pixel 7 → cards-with-modal unchanged.
- **Permissions**: a worker without `medications.administer.record` cannot post a PRN; sees a 403 toast.

### Desktop and mobile viewport checks

- `chromium-desktop` (1440×1000): two-pane MAR, full KPI strip, dashboard charts at full width. Visit `/emar`, `/emar/mar`, `/emar/daily`, `/medications/audit`.
- `chromium-mobile` (Pixel 7): worker home (`/meds/today`), guided round, PRN sheet, MAR cards-with-modal. Sticky bottom nav and footer don't overlap content (use `safe-area-inset-bottom`).

### Playwright tests (PR I)

See PR I table for the 9 specs. All under `tests/e2e/`, naming `meds-*.spec.ts` for grouping. Selector strategy:

- Prefer `getByRole('button', { name: /…/i })`.
- Use `data-test` for buttons whose label is duplicated (e.g. `Record` appears on multiple rows).
- Avoid `waitForTimeout`. Use `expect(...).toBeVisible({ timeout: 10_000 })`.
- Never assert against unrelated UI text.

---

## 8. Final recommendation

**Small scoped hardening is enough.**

The architecture is in place: one canonical `EnhancedMarService` write path, idempotency primitives on the API side, a real worker surface at `/meds/today`, a worker round flow at `/meds/round/{id}/guided`, a working IndexedDB offline queue, an offline status banner already mounted at app root, and a service worker. What's missing is convergence (PR A, B, D), shell coverage (PR C), desktop polish (PR E), bundle hygiene (PR F), and the test coverage that makes any of this auditable in production (PR I).

I do not recommend a redesign. Three places where someone might be tempted, and why I'd push back:

- **"Pick one MAR page and rebuild it."** No — the canonical page is `MarCharts.tsx` already; the orphans (`enhanced-mar.tsx`, `medications/dashboard.tsx`) just need deleting, and the desktop polish is additive.
- **"Replace the dual-trait offline server contract with one shared trait."** Tempting, mechanically clean, but the two envelope shapes serve different callers (Inertia redirect-with-flash vs JSON `sync` payload) and merging them invites breakage on call sites we'd then need to retest. Keep them split.
- **"Ship a full PWA + IndexedDB-cached MAR rendering."** That's a quarter of work and isn't justified by the operational risk: the MAR is read mostly from `/emar/mar` which is already in the SW shell; what matters for a worker is that recording a dose offline doesn't get lost. PR B + PR D + PR C close that gap without rewriting how the page renders.

Risks if we ship without P0:

- **P0-1 unfixed** is the highest concrete risk: an admin double-taps "Record" or a flaky network retry, and the same dose is recorded twice. Two real administrations in the audit log against one real event. That is a regulator-visible problem in NZ HealthCERT terms.
- **P0-2 unfixed** loses doses on patchy connectivity in residential settings. The single workflow most likely to be done on a tablet outside Wi-Fi range is the guided round.
- **P0-4 unfixed** means the offline banner is a partial truth and the worker can be misled about what's pending. Lower than P0-1/P0-2 in terms of consequence but easy to land.

Everything in P1 and P2 can ship after launch as polish.
