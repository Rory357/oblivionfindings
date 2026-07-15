# Control Room, Incidents, and H&S Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver one secure, idempotent, desktop incident journey from Control Room through the official incident record into accepted H&S governance, together with an action-first Control Room dashboard and five proven end-to-end scenarios.

**Architecture:** Keep `ControlRoomAlert`, `ClientIncident`, and `HsEvent` as separate accountable records, anchored by `ClientIncident` and orchestrated through one transactional `IncidentJourneyService`. Reuse and generalise the H&S command-centre visual grammar, drive every worklist from canonical presenters and visibility services, and keep operational, incident-review, and H&S lifecycle states independently truthful.

**Tech Stack:** Laravel 13, PHP 8.4, Eloquent/MySQL, Pest 4, Inertia 2, React 19, TypeScript 5.7, Tailwind 4, Vitest, Playwright desktop Chromium.

**Design source:** `docs/superpowers/specs/2026-07-12-control-room-incident-hs-unified-journey-design.md`

---

## Delivery rules

- Work only in `codex/control-room-incident-hs-unification`.
- Desktop only. Do not add or claim mobile verification.
- Follow red → green → refactor for every production behaviour.
- Commit after each task passes its task-specific verification.
- After each implementation task, run a specification review and then a code-quality review before starting the next task.
- Preserve existing `UserSiteAccessService` behaviour on the already-scoped dashboard, alert list, and alert workspace.
- Do not trust prior “complete” notes as evidence; prove the R1–R21 ledger in the design.

## File structure

### Backend units

- `app/Services/Incidents/IncidentJourneyService.php` — the only writer that links alert, incident, and H&S records.
- `app/Services/Incidents/IncidentJourneyPresenter.php` — one read contract for references, context, evidence, owners, acceptance, WorkSafe, lifecycle, and next action.
- `app/Services/Incidents/IncidentJourneyReconciler.php` — dry-run/apply discovery and repair logic.
- `app/Services/ControlRoom/ControlRoomAlertLifecycleService.php` — atomic alert, SLA, task-gate, and audit transitions.
- `app/Services/ControlRoom/AlertPriorityService.php` — transparent priority score/reason.
- `app/Services/ControlRoom/AlertWorklistQuery.php` — shared actionable worklist query.
- `app/Services/ControlRoom/AlertWorklistPresenter.php` — canonical alert row payload.
- `app/Services/ControlRoom/ControlRoomDeskService.php` — live desk aggregates and optional analytics.
- `app/Models/HsRecommendationDisposition.php` — explicit disposition for each investigation recommendation.
- `app/Console/Commands/ReconcileIncidentJourneys.php` — repeatable repair command.

### Frontend units

- `resources/js/components/command-centre/hero-kit.tsx` — neutral hero primitives extracted from H&S.
- `resources/js/components/command-centre/workflow-ribbon.tsx` — shared workflow ribbon primitive.
- `resources/js/components/control-room/alert-worklist/*` — shared alert list, row, filters, and state display.
- `resources/js/components/control-room/alert-workspace/*` — split workspace sections and next-action decision.
- `resources/js/components/control-room/dashboard/*` — action-first desk composition.
- `resources/js/components/incidents/incident-journey-status.tsx` — three-state journey summary and H&S acceptance.
- `resources/js/lib/control-room-vocab.ts` — status, severity, SLA, priority reason, and official-reference presentation.

---

### Task 1: Close cross-site, sensitivity, and out-of-scope write paths

**Files:**

- Modify: `app/Services/UserSiteAccessService.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php`
- Modify: `app/Http/Controllers/IncidentController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomDiscussionController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php`
- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Test: `tests/Feature/ControlRoom/ControlRoomJourneyAuthorizationTest.php`
- Test: `tests/Feature/HealthSafety/HsEventSiteIsolationTest.php`

- [x] **Step 1: Write failing read-isolation tests**

```php
it('does not return another sites incidents clients sites or hs events', function () {
    [$user, $ownSite, $otherSite] = siteBoundControlRoomFixture();
    $otherIncident = ClientIncident::factory()->for(clientAt($otherSite))->submitted()->create();
    $otherEvent = HsEvent::factory()->forSource($otherIncident)->create(['site_id' => $otherSite->id]);

    $this->actingAs($user)->get('/control-room/incidents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('incidents', fn ($rows) => collect($rows)->doesntContain('id', $otherIncident->id))
            ->where('sites', fn ($rows) => collect($rows)->doesntContain('id', $otherSite->id)));

    $this->actingAs($user)->get("/health-safety/events?event={$otherEvent->id}")
        ->assertNotFound();
});
```

- [x] **Step 2: Run the isolation tests and confirm the current leak**

Run: `php artisan test tests/Feature/ControlRoom/ControlRoomJourneyAuthorizationTest.php tests/Feature/HealthSafety/HsEventSiteIsolationTest.php`

Expected: FAIL because the Incident Tracker/H&S event register returns or opens the other-site rows.

- [x] **Step 3: Add explicit incident and H&S scope helpers**

```php
public function applyClientIncidentScope(Builder $query, ?User $user, array $bypass = []): Builder;
public function assertCanAccessClientIncident(?User $user, ClientIncident $incident, array $bypass = []): void;
public function applyHsEventScope(Builder $query, ?User $user, array $bypass = []): Builder;
public function assertCanAccessHsEvent(?User $user, HsEvent $event, array $bypass = []): void;
```

Use `ClientIncident.site_id` when populated and the incident's client/shift site only as a compatibility fallback. H&S uses `hs_events.site_id`.

H&S record IDs use non-disclosing scoped resolution: inaccessible and nonexistent IDs both return 404 for overlays, direct reads, and record mutations. Explicit attempts to select an inaccessible `site_id` filter return 403. Organisation-wide H&S access requires the explicit `healthSafety.viewAllSites` permission; absence of a site assignment never grants access by itself.

- [x] **Step 4: Scope reads, pickers, queue counts, and nested parent records**

Apply the helpers before pagination/detail lookup. Every task/evidence/discussion/watcher/time-entry/message mutation must resolve its parent alert and call `assertCanAccessAlert()` before validation or write.

- [x] **Step 5: Write failing out-of-scope write tests**

```php
it('rejects creating alerts or incidents for an inaccessible client or source', function () {
    [$user, , $otherSite] = siteBoundControlRoomFixture();
    $otherClient = clientAt($otherSite);
    $otherIncident = ClientIncident::factory()->for($otherClient)->submitted()->create();

    $this->actingAs($user)->post('/control-room/alerts', manualAlertPayload($otherClient, $otherSite))->assertForbidden();
    $this->actingAs($user)->post('/control-room/incidents/create-alert', sourcePayload($otherIncident))->assertForbidden();
    $this->actingAs($user)->post('/control-room/incidents/flag', flagPayload($otherClient))->assertForbidden();
});
```

- [x] **Step 6: Enforce site/client/source agreement and run the security suite**

Run: `php artisan test tests/Feature/ControlRoom/ControlRoomJourneyAuthorizationTest.php tests/Feature/HealthSafety/HsEventSiteIsolationTest.php tests/Unit/Services/UserSiteAccessServiceTest.php`

Expected: PASS with all cross-site IDs absent; Control Room out-of-scope writes and forbidden site filters return 403, while inaccessible H&S record IDs return the same 404 as nonexistent IDs.

- [x] **Step 7: Commit**

```powershell
git add app/Services/UserSiteAccessService.php app/Http/Controllers/ControlRoom app/Http/Controllers/HealthSafety/HsEventController.php tests/Feature/ControlRoom/ControlRoomJourneyAuthorizationTest.php tests/Feature/HealthSafety/HsEventSiteIsolationTest.php
git commit -m "fix(control-room): enforce incident journey visibility"
```

**Task 1 completion evidence — 2026-07-13**

- Phase A visibility and H&S isolation: `34a8ad4e`, `5d71e917`, `d22ecf9d`, and `083f030d`; focused proof 20 tests / 165 assertions; specification and quality reviews approved.
- Phase B1 nested alert records and task hierarchy: `df6c9815` and `a89721f9`; combined proof 88 tests / 229 assertions; specification and quality reviews approved.
- Phase B2 scoped messaging and shared authorization concern: `911f0648`, nondisclosure correction `69cebcfe`, and deterministic thread-summary correction `62386a68`.
- Phase B2 TDD proof: initial security RED 6 failed / 4 passed, ordering RED 1 failed, query-quality RED 4 failed / 11 passed; final messaging GREEN 15 tests / 104 assertions.
- Phase B1 regressions after the shared concern: nested authorization 36 tests / 96 assertions and task controller 13 tests / 51 assertions.
- Independent Phase B2 specification re-review: compliant with fresh 11 tests / 82 assertions. Independent code-quality re-review: approved. PHP lint, Pint, and `git diff --check` passed; no mobile test was run.

---

### Task 2: Add explicit journey, H&S acceptance, task transfer, and shift acceptance schema

**Files:**

- Create: `database/migrations/2026_07_12_000100_add_incident_journey_links.php`
- Create: `database/migrations/2026_07_12_000200_add_hs_handover_acceptance.php`
- Create: `database/migrations/2026_07_12_000300_add_alert_task_transfer.php`
- Create: `database/migrations/2026_07_12_000350_add_alert_sla_cycle_history.php`
- Create: `database/migrations/2026_07_12_000400_add_control_room_shift_handover_acceptance.php`
- Create: `database/migrations/2026_07_12_000500_create_hs_recommendation_dispositions.php`
- Modify: `app/Models/ClientIncident.php`
- Modify: `app/Models/HsEvent.php`
- Modify: `app/Models/ControlRoom/AlertTask.php`
- Modify: `app/Models/ControlRoom/AlertSla.php`
- Modify: `app/Models/ControlRoom/Shift.php`
- Create: `app/Models/HsRecommendationDisposition.php`
- Modify: `database/factories/ClientIncidentFactory.php`
- Modify: `database/factories/HsEventFactory.php`
- Test: `tests/Feature/Incidents/IncidentJourneySchemaTest.php`

- [x] **Step 1: Write a failing schema and relationship test**

```php
it('stores direct journey links acceptance transfers and recommendation dispositions', function () {
    expect(Schema::hasColumns('client_incidents', ['site_id', 'hs_event_id']))->toBeTrue();
    expect(Schema::hasColumns('hs_events', ['handover_status', 'owner_user_id', 'accepted_by_user_id', 'accepted_at', 'acceptance_notes']))->toBeTrue();
    expect(Schema::hasColumns('control_room_alert_tasks', ['transferred_to_hs_corrective_action_id', 'transferred_at', 'transferred_by_user_id']))->toBeTrue();
    expect(Schema::hasColumns('control_room_alert_sla', ['cycle_number', 'cycle_started_at', 'cycle_history', 'ended_as']))->toBeTrue();
    expect(Schema::hasColumns('control_room_shifts', ['handover_status', 'handover_snapshot', 'handover_version', 'handover_prepared_at', 'handover_accepted_at']))->toBeTrue();
    expect(Schema::hasTable('hs_recommendation_dispositions'))->toBeTrue();
});
```

- [x] **Step 2: Run the test and verify missing-column failures**

Run: `php artisan test tests/Feature/Incidents/IncidentJourneySchemaTest.php`

Expected: FAIL on the first missing column/table.

- [x] **Step 3: Implement migrations with reversible indexes and foreign keys**

Use these exact contracts:

```php
$table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete()->index();
$table->foreignId('hs_event_id')->nullable()->constrained('hs_events')->nullOnDelete()->unique();

$table->string('handover_status', 30)->default('not_required')->index();
$table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
$table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('accepted_at')->nullable();
$table->text('acceptance_notes')->nullable();
```

Recommendation dispositions use a unique `(hs_investigation_id, recommendation_index)` key and fields `disposition`, `reason`, `hs_corrective_action_id`, `decided_by_user_id`, and `decided_at`.

SLA cycle fields use `cycle_number` (default 1), `cycle_started_at`, JSON `cycle_history`, and nullable `ended_as`. Reopening snapshots the complete prior clock/breach outcome into `cycle_history` before starting the next cycle; dismissal stores `ended_as=dismissed` so it cannot be counted as successful compliance.

- [x] **Step 4: Add fillable, casts, constants, and relationships**

H&S handover constants are `not_ready`, `awaiting_acceptance`, `accepted`, and `not_required`. Alert-task terminal statuses include `completed`, `cancelled`, and `transferred`. Shift handover states are `none`, `prepared`, and `accepted`.

- [x] **Step 5: Verify migrate, rollback, and re-migrate on the test database**

Run the six migrations in order with `--database=mysql` under the test environment used by Pest, roll them back by path in reverse order, then re-run the schema test.

Expected: every migration applies and reverses cleanly; the schema test passes after re-migration.

- [x] **Step 6: Commit**

```powershell
git add database/migrations app/Models database/factories tests/Feature/Incidents/IncidentJourneySchemaTest.php
git commit -m "feat(incidents): add explicit journey and handover schema"
```

**Task 2 completion evidence — 2026-07-13**

- Schema/model implementation: `6304a3e7`; provenance, terminal-scope, timeline-site, and constraint-quality correction: `264cbca9`; direct contract-evidence hardening: `7e0f7908`.
- TDD evidence: initial missing-schema RED 1 failed / 1 assertion; model-contract RED 10 failed / 2 passed / 35 assertions; quality RED 2 failed / 12 passed / 117 assertions plus transferred-overdue RED 1 failed.
- Final focused proof: `IncidentJourneySchemaTest` 14 tests / 217 assertions; real H&S source-link 1 test / 16 assertions; transferred-task overdue scope 1 test / 1 assertion. The final schema suite directly proves fillability/guarded persistence and the complete near-miss, handover-acceptance, idempotency, ownership, timing, and recommendation-disposition factory contracts rather than relying on factory unguarding.
- Migration proof on the MySQL test database: all six applied `000100 → 000500`, rolled back `000500 → 000100`, and reapplied `000100 → 000500`. Legacy incident/event/SLA/shift rows survived; defaults remained H&S `not_required`, SLA cycle `1`, shift `none` with version `1`.
- Independent specification review: compliant. Independent code-quality re-review: approved. PHP lint, Pint, and `git diff --check` passed; no controller, service, route, UI, lifecycle, or mobile work was included.

---

### Task 3: Build the transactional incident journey service

**Files:**

- Create: `app/Services/Incidents/IncidentJourney.php`
- Create: `app/Services/Incidents/IncidentJourneyService.php`
- Modify: `app/Models/ControlRoomAlert.php`
- Modify: `app/Models/ClientIncident.php`
- Modify: `app/Models/HsEvent.php`
- Modify: `app/Services/HealthSafety/HsEventService.php`
- Test: `tests/Feature/Incidents/IncidentJourneyServiceTest.php`

- [x] **Step 1: Write failing alert-to-incident and retry tests**

```php
it('creates one submitted incident and one hs event from an existing alert and is idempotent', function () {
    $alert = ControlRoomAlert::factory()->triaging()->create();
    $actor = coordinator();

    $first = app(IncidentJourneyService::class)->submitFromAlert($alert, incidentInput(), $actor);
    $second = app(IncidentJourneyService::class)->submitFromAlert($alert->fresh(), incidentInput(), $actor);

    expect($second->incident->is($first->incident))->toBeTrue()
        ->and(ClientIncident::count())->toBe(1)
        ->and(HsEvent::count())->toBe(1)
        ->and(ControlRoomAlert::count())->toBe(1)
        ->and($first->incident->control_room_alert_id)->toBe($alert->id)
        ->and($first->incident->hs_event_id)->toBe($first->hsEvent->id)
        ->and($first->hsEvent->control_room_alert_id)->toBe($alert->id);
});
```

- [x] **Step 2: Run and confirm the missing-service failure**

Run: `php artisan test tests/Feature/Incidents/IncidentJourneyServiceTest.php`

Expected: FAIL because `IncidentJourneyService` does not exist.

- [x] **Step 3: Implement the service API and transaction**

```php
public function submitFromAlert(ControlRoomAlert $alert, array $input, User $actor): IncidentJourney;
public function ensureForSubmittedIncident(ClientIncident $incident, ?User $actor = null): IncidentJourney;
public function ensureAlertForIncident(ClientIncident $incident, User $actor, ?string $reason = null): IncidentJourney;
public function journeyForIncident(ClientIncident $incident): IncidentJourney;
```

Lock the alert/incident rows, prefer direct links, enforce all three backlinks, set `context.incident_id` for compatibility, set H&S `handover_status=awaiting_acceptance`, and preserve existing alert notes/tasks/evidence/status.

Map a critical operational alert to the incident model's supported `high` severity while preserving critical on the alert and in journey provenance; do not silently coerce or lose the original severity.

- [x] **Step 4: Add distinct-incident deduplication tests**

Create two same-client, same-type submitted incidents less than 30 minutes apart. Call `ensureAlertForIncident()` twice per incident and assert two alerts total, one stable alert per incident, and no orphan.

- [x] **Step 5: Add low/medium and high rules**

`ensureForSubmittedIncident()` creates an H&S event for every submitted incident. It creates an alert automatically only for high/critical-equivalent rules or explicit escalation. It never creates an H&S event for a draft.

- [x] **Step 6: Run the service tests**

Run: `php artisan test tests/Feature/Incidents/IncidentJourneyServiceTest.php`

Expected: PASS, including retries and two distinct incidents in one fuzzy dedup window.

- [x] **Step 7: Commit**

```powershell
git add app/Services/Incidents app/Models/ControlRoomAlert.php app/Models/ClientIncident.php app/Models/HsEvent.php app/Services/HealthSafety/HsEventService.php tests/Feature/Incidents/IncidentJourneyServiceTest.php
git commit -m "feat(incidents): orchestrate one incident journey"
```

**Task 3 completion evidence — 2026-07-13**

- Initial implementation: `b6748d2f`; lifecycle/WorkSafe/tuple/provenance correction: `e2c2ed40`; ownership/discriminator/context hardening: `441f8c1f`.
- Initial TDD evidence: missing-service RED 1 failure; full-behaviour RED 12 failures / 1 pass / 2 assertions; initial GREEN 13 tests / 157 assertions.
- Independent specification review then found four blockers: non-draft lifecycle regression, H&S WorkSafe demotion, non-canonical H&S source tuples, and lost sensor provenance. Fix-round RED was 5 failures / 12 passes / 159 assertions; GREEN was 17 tests / 200 assertions.
- Independent quality review then found foreign H&S re-parenting, over-broad signal classification, and destructive alert-context collisions. Quality-round RED was 3 failures / 17 passes / 206 assertions; final focused GREEN was 20 tests / 228 assertions.
- Final reviewed behaviour preserves reviewed/closed incident state, keeps all seven WorkSafe fields H&S-authoritative after first canonical linkage, allows only monotonic initial legacy adoption, rejects foreign H&S ownership before writes, safely repairs same-incident tuple drift, requires a real signal plus trusted sensor source, and deep-fills reused alert context without replacing operational provenance.
- Regression proof: `HsEventBackboneTest` 26 tests / 44 assertions; `HsEventWorksafeTest` 6 tests / 21 assertions. Root-owned post-review rerun: `IncidentJourneyServiceTest` 20 tests / 228 assertions on `441f8c1f`.
- Final independent specification re-review: pass. Final independent code-quality re-review: approved with no High/Medium defect. PHP lint, Pint, and `git diff --check` passed. No observer, bridge, controller, route, UI, mobile, Task 4, merge, push, or deployment work was included.

---

### Task 4: Route observers, sensor, medication, and legacy bridges through the journey

**Files:**

- Modify: `app/Observers/ClientIncidentObserver.php`
- Modify: `app/Services/ControlRoom/ComprehensiveAlertBridgeService.php`
- Modify: `app/Services/ControlRoom/SensorIncidentBridgeService.php`
- Modify: `app/Services/MedicationIncidentIntegrationService.php`
- Modify: `app/Services/Medication/MedicationSignalService.php`
- Test: `tests/Feature/HealthSafety/ControlRoomBridgeWiringTest.php`
- Test: `tests/Feature/ControlRoom/SensorIncidentJourneyTest.php`
- Test: `tests/Feature/Medication/MedicationIncidentJourneyTest.php`

- [x] **Step 1: Write failing draft/submission observer tests**

Assert a created draft has no `HsEvent`; changing the same incident to submitted creates exactly one H&S event, and repeated updates remain one.

- [x] **Step 2: Write failing sensor and medication correlation tests**

Sensor confirmation must reuse its alert and preserve evidence. A controlled-drug incident plus emitted signal must result in exactly one incident, one alert, one H&S event, and matching links.

- [x] **Step 3: Run all three files and capture the current duplicate/orphan failures**

Run: `php artisan test tests/Feature/HealthSafety/ControlRoomBridgeWiringTest.php tests/Feature/ControlRoom/SensorIncidentJourneyTest.php tests/Feature/Medication/MedicationIncidentJourneyTest.php`

- [x] **Step 4: Make the observer a compatibility caller**

On `created`, return immediately for drafts; otherwise call `ensureForSubmittedIncident()`. On draft → submitted call the same method. Synchronise mutable source fields through the journey service. Do not catch-and-forget a partial link; dispatch a reconciliation job or log a structured repair-required event.

- [x] **Step 5: Make bridge deduplication return the correlated alert**

Incident-backed paths query `context.incident_id` or the direct FK. Fuzzy deduplication may remain for non-incident operational signals, but it must never return `null` for a distinct incident that needs a relationship.

- [x] **Step 6: Delegate sensor and medication creation to the service**

Keep sensor-specific evidence capture and dismissal, but use `submitFromAlert()` for confirmation. Medication signal enrichment locates the official incident journey instead of opening a second alert.

- [x] **Step 7: Run bridge regression suites**

Run: `php artisan test tests/Feature/HealthSafety/ControlRoomBridgeWiringTest.php tests/Feature/ControlRoom/ControlRoomAlertControllerTest.php tests/Feature/ControlRoom/SensorIncidentJourneyTest.php tests/Feature/Medication/MedicationIncidentJourneyTest.php`

Expected: PASS with exact record counts and stable links.

- [x] **Step 8: Commit**

```powershell
git add app/Observers app/Services/ControlRoom app/Services/Medication app/Services/MedicationIncidentIntegrationService.php tests/Feature
git commit -m "fix(incidents): prevent duplicate journey bridges"
```

#### Crash-containment checkpoint — 2026-07-13

- Task 4 implementation and hardening are present through commit `2dde398d53064349ca1bbd141b19a026af991d68` (`fix(incidents): harden bridge identity and races`), following `d371f89f`, `2018927c`, `5fc1531f`, and `df49e2bd`.
- The final formatted Task 4 code passed the exact six-file gate: 135 tests, 595 assertions, exit 0. Pint, PHP lint for all seven touched files, and `git diff --check` also passed.
- This is an implementation checkpoint, not Task 4 acceptance. A fresh independent specification re-review and code-quality re-review at `2dde398d` are still required, so the Task 4 checkboxes remain deliberately unchecked.
- No Task 5 UI work, dashboard redesign, desktop browser E2E scenario, final client/SSR build, merge, push, or deployment has started.
- Exact resume point: review Task 4 at `2dde398d`; if both reviews approve, record the completion evidence and only then begin Task 5.

#### Task 4 completion evidence — 2026-07-13

- Canonical bridge implementation and successive hardening are committed through `a43c66ad28bb42137a46f2704b6575b22138d811`, including `d371f89f`, `2018927c`, `5fc1531f`, `df49e2bd`, `2dde398d`, `5818fe48`, `cd65b87f`, and `962a3c28`.
- Final behaviour keeps `IncidentJourneyService` as the sole alert/incident/H&S link writer; drafts create no H&S event; submitted observer, sensor, medication, signal, and legacy-controller paths converge on one atomic, retry-safe journey with exact client/source validation and monotonic severity, WorkSafe, and lifecycle state.
- Quality hardening adds stable medication and PRN request identity, durable failed-hook replay, alert-first locking and outer deadlock retries, queue/SLA/playbook reconciliation, reversible notification-outbox and administration UUID schema, exactly-once notification/governance jobs with scheduled recovery, and non-conversational filtering for operational outbox rows.
- Final exact 10-file Task 4 union gate: 219 tests / 1,431 assertions / exit 0 / 448.22 seconds. Focused owning suites, Pint, PHP lint, container/job/schedule checks, `git diff --check`, and isolated migration apply → rollback → reapply all passed.
- Final independent code-quality review: approved with no Critical/Important finding. Final independent specification reconciliation: pass at `a43c66ad`. No Task 5 UI, mobile, WebView, merge, push, or deployment work was included.
- Residual reconciliation after Task 5: `eaf6c0c1e907973f1251a380a50bdc202859a655` terminalises stale queue/SLA/playbook state when a promoted alert has no compatible target, reactivates a later matching SLA as a new cycle, removes terminal SLA rows from presenters/compliance, preserves wildcard fallbacks, and aligns the stale H&S backbone assertion to the canonical incident journey. Affected proof: 242 tests / 1,532 assertions; independent re-review pass.

---

### Task 5: Make Save draft and Submit incident truthful and reusable

**Files:**

- Modify: `app/Http/Controllers/IncidentController.php`
- Modify: `resources/js/components/incidents/incident-report-dialog.tsx`
- Modify: `resources/js/pages/health-safety/components/report-incident-dialog.tsx`
- Modify: `resources/js/pages/health-safety/components/report-launcher.tsx`
- Modify: `database/seeders/RbacSeeder.php`
- Test: `tests/Feature/IncidentControllerTest.php`
- Test: `resources/js/components/incidents/incident-report-dialog.test.tsx`

- [x] **Step 1: Write failing intent tests**

```php
it('saves a draft without hs and submits atomically with hs', function () {
    $draft = $this->actingAs(supportWorker())->post('/incidents', reportPayload(['intent' => 'draft']))->assertRedirect();
    expect(ClientIncident::latest()->first()->status)->toBe('draft')->and(HsEvent::count())->toBe(0);

    $this->actingAs(supportWorker())->post('/incidents', reportPayload(['intent' => 'submit']))->assertRedirect();
    $incident = ClientIncident::latest()->first();
    expect($incident->status)->toBe('submitted')->and($incident->submitted_at)->not->toBeNull()->and($incident->linkedHsEvent())->not->toBeNull();
});
```

- [x] **Step 2: Run backend and component tests and confirm mismatch**

Expected: the current “Submit incident” path persists `draft` and the tests fail.

- [x] **Step 3: Add `intent=draft|submit` and validate incident context**

Store `site_id` from the selected incident-time context. Reject a selected shift whose client/site does not match. For submit, call `IncidentJourneyService` inside the request transaction and return the incident/H&S references in flash data.

- [x] **Step 4: Consolidate H&S launcher onto the canonical dialog**

Remove the separate H&S payload mapping that puts site in description, collapses people, maps Critical incorrectly, or promises a corrective action it does not create. H&S passes defaults into the canonical dialog.

- [x] **Step 5: Update labels and success panes**

Render two explicit buttons: `Save draft` and `Submit incident`. Success headings are `Draft saved` or `Incident submitted`. Show the official incident reference and `Awaiting H&S acceptance` when submitted.

- [x] **Step 6: Align role permissions**

Give the H&S officer the minimum incident create/view permissions needed by visible H&S actions. Give the coordinator the narrow Control Room create/handover permission used by the operator workflow. Remove full Control Room dashboard permission from the support-worker role while retaining Control Room tasks in canonical My Day.

- [x] **Step 7: Run tests**

Run: `php artisan test tests/Feature/IncidentControllerTest.php tests/Feature/ControlRoom/ControlRoomDashboardTest.php && npm test -- resources/js/components/incidents/incident-report-dialog.test.tsx`

- [x] **Step 8: Commit**

```powershell
git add app/Http/Controllers/IncidentController.php resources/js/components/incidents resources/js/pages/health-safety/components database/seeders/RbacSeeder.php tests/Feature resources/js/components/incidents/incident-report-dialog.test.tsx
git commit -m "feat(incidents): separate draft and submit workflows"
```

**Task 5 completion evidence — 2026-07-13**

- Canonical reporting implementation: `492cadb4f717a8a9a4eee8f1b872e26c5c90c594`; final draft/site/safeguarding hardening: `d620e30c04fad2d14470546a75ccdbd0cdef7cc2`.
- One desktop dialog now owns Incidents and H&S reporting. It sends explicit draft/submit intent, preserves a DB-unique request UUID across retry and draft → submit, renders visible validation with first-invalid-step routing, and shows only official incident/H&S references with truthful `Draft saved`, `Incident submitted`, and `Awaiting H&S acceptance` states.
- Submitted reports synchronously create one canonical H&S journey inside the request transaction; drafts create no H&S event, Control Room alert, escalation notification, communication, or safeguarding side effect. Abuse/neglect submission adds one linked safeguarding concern while retaining one H&S event/alert, and critical escalation creates one idempotent statutory notifiable record.
- Incident-time client/site/shift context, deep-link prefill, draft reuse, and legacy submit/retry all use `UserSiteAccessService` and organization scope. H&S has create-only incident permission, coordinators have narrow alert creation, support workers no longer receive the full dashboard, and follow-up assignment requires `incidents.followups.manage`.
- Final Task 5 backend union before the bounded safeguarding fix: 194 tests / 1,076 assertions. Final review-fix proof: 7 tests / 60 assertions; affected governance + bridge proof: 36 tests / 84 assertions. Component suite: 20/20; TypeScript, ESLint, Prettier, Pint, PHP lint, `git diff --check`, and reversible UUID migration proof passed.
- Same independent code-quality reviewer: `READY`. Final independent specification reconciliation: `PASS`. No Task 6, dashboard redesign, mobile, WebView, merge, push, or deployment work was included.

---

### Task 6: Add H&S handover acceptance and one WorkSafe source

**Files:**

- Modify: `app/Services/HealthSafety/HsEventService.php`
- Modify: `app/Services/HealthSafety/HsDashboardService.php`
- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Modify: `app/Http/Controllers/IncidentController.php`
- Modify: `routes/health-safety.php`
- Modify: `resources/js/pages/health-safety/dashboard.tsx`
- Modify: `resources/js/pages/health-safety/events/index.tsx`
- Modify: `resources/js/pages/health-safety/events/show.tsx`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Modify: `resources/js/components/incidents/incident-detail-dialog.tsx`
- Test: `tests/Feature/HealthSafety/HsHandoverAcceptanceTest.php`
- Test: `tests/Feature/HealthSafety/HsWorksafeConsistencyTest.php`

- [x] **Step 1: Write failing acceptance tests**

Assert submitted incident-backed events appear in `handover=awaiting`, drafts do not, an authorised H&S officer can accept, another-site user cannot, acceptance does not change `HsEvent.status`, and the owner/actor/time are returned to Incident and Control Room presenters.

- [x] **Step 2: Implement acceptance service and route**

```php
public function acceptHandover(HsEvent $event, User $actor, ?User $owner = null, ?string $notes = null): HsEvent;
```

Route: `POST /health-safety/events/{event}/accept-handover`, named `health-safety.events.handover.accept`.

- [x] **Step 3: Write failing WorkSafe count-consistency tests**

Create pending/notified/acknowledged H&S events and assert `/incidents` and `/health-safety` expose the same pending count and state from `HsEvent`.

- [x] **Step 4: Make HsEvent authoritative**

Change H&S dashboard/worklists and incident detail payloads to read H&S WorkSafe fields. Treat draft incident fields as provisional submission input. Keep legacy columns as compatibility projections updated from the H&S service, never as an independent workflow.

- [x] **Step 5: Render acceptance and usable handover content**

H&S detail must show incident narrative, immediate controls, attachments, Control Room evidence, playbook outcome, important communications, operational tasks, official references, source/site, owner, acceptance, three lifecycle states, and next action.

- [x] **Step 6: Run tests**

Run: `php artisan test tests/Feature/HealthSafety/HsHandoverAcceptanceTest.php tests/Feature/HealthSafety/HsWorksafeConsistencyTest.php tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsDashboardServiceTest.php`

- [x] **Step 7: Commit**

```powershell
git add app/Services/HealthSafety app/Http/Controllers/HealthSafety app/Http/Controllers/IncidentController.php routes/health-safety.php resources/js/pages/health-safety resources/js/components/incidents tests/Feature/HealthSafety
git commit -m "feat(health-safety): accept incident handovers"
```

**Task 6 completion evidence — 2026-07-14**

- Implementation commit: `1985f00d662f42c3f907951d091f37d64f739c40`.
- H&S now has an explicit, site-scoped handover acceptance action with owner, accepting actor, timestamp, and notes. Incident and Control Room payloads surface that same acceptance state without changing the H&S event lifecycle status.
- `HsEvent` is the authoritative WorkSafe workflow. Pending/notified/acknowledged state and counts are permission- and site-scoped across H&S and Incidents; submitted/reviewed Incident updates cannot mutate the legacy projection inward.
- The desktop H&S detail and worklists now carry the usable handover context: incident narrative, immediate controls, evidence, playbook result, communications, tasks, official references, source/site, owner, acceptance, WorkSafe state, and next action. Restricted linked records render as inert summaries rather than fabricated links.
- An idempotent historical backfill repairs only unambiguous, non-draft canonical Incident/H&S journey links and freezes the incident-time H&S site before synchronisation, avoiding current-client-site leakage after a move.
- Focused PHP gate: 41 tests / 511 assertions. Focused component gate: 15 tests. Wider affected boundary: 351 tests / 2,029 assertions after correcting one stale permission expectation.
- TypeScript, targeted ESLint, Prettier, Pint, PHP lint, `git diff --check`, the production client build (4,944 modules), and the SSR build (1,596 modules) passed.
- Independent backend/security review: `PASS`. Independent specification review: `PASS`. No Task 7 lifecycle work, mobile, responsive/mobile testing, WebView, merge, push, or deployment work was included.

---

### Task 7: Centralise alert lifecycle, SLA, and operational task gates

**Files:**

- Create: `app/Services/ControlRoom/ControlRoomAlertLifecycleService.php`
- Modify: `app/Models/ControlRoomAlert.php`
- Modify: `app/Models/ControlRoom/AlertSla.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php`
- Modify: `app/Jobs/CheckControlRoomSlaBreaches.php`
- Modify: `app/Jobs/AutoEscalateControlRoomQueues.php`
- Modify: `routes/control-room.php`
- Test: `tests/Unit/ControlRoom/ControlRoomAlertLifecycleServiceTest.php`
- Test: `tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php`

- [x] **Step 1: Write failing state, SLA, and active-scope tests**

Cover `open → ack → triaging → resolved → closed`, confirmed continuing to resolve, dismissed excluded from actionable/unresolved/breach/escalation, no human open → resolved jump, explicit incident-driven reopen to triaging, and every transition updating actor/time/SLA once.

- [x] **Step 2: Write failing open-task resolution test**

An alert with `open`, `in_progress`, or `blocked` tasks must reject resolution. Completed/cancelled/transferred tasks permit it. Cancelling a task requires a non-empty reason and records that reason in the audit trail; a bare status flip is rejected.

- [x] **Step 3: Implement lifecycle API**

```php
public function acknowledge(ControlRoomAlert $alert, User $actor): ControlRoomAlert;
public function startTriage(ControlRoomAlert $alert, User $actor): ControlRoomAlert;
public function confirmSensor(ControlRoomAlert $alert, User $actor): ControlRoomAlert;
public function dismissSensor(ControlRoomAlert $alert, User $actor, string $reason): ControlRoomAlert;
public function resolve(ControlRoomAlert $alert, User $actor, string $notes, string $code): ControlRoomAlert;
public function close(ControlRoomAlert $alert, User $actor, ?string $notes = null): ControlRoomAlert;
public function reopenForIncident(ControlRoomAlert $alert, ClientIncident $incident, User $actor, string $reason): ControlRoomAlert;
```

Use transactions and the existing audit logger. Append operator notes; do not overwrite the alert's original notes. Reopen clears terminal actor/time fields, returns to `triaging`, records the incident/reason, and creates a new audited SLA response cycle rather than rewriting the historic clock.

- [x] **Step 4: Implement transfer-to-H&S task endpoint**

Create one `HsCorrectiveAction`, set task status `transferred`, store the three transfer fields, and make retries return the same corrective action.

- [x] **Step 5: Delegate controllers and jobs to canonical scopes/service**

Replace repeated `whereNotIn(['resolved','closed'])` with `actionable()`. Exclude dismissed SLA rows from compliance denominators. Sensor dismissal ends clocks without counting as successful compliance. Remove `IncidentController::close()`'s direct best-effort alert mutation; operational resolution is a separate gated service action. Incident reopen sets journey attention and exposes the explicit reopen action without silently changing H&S status.

- [x] **Step 6: Run lifecycle tests**

Run: `php artisan test tests/Unit/ControlRoom/ControlRoomAlertLifecycleServiceTest.php tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/ControlRoom/ControlRoomAlertControllerTest.php tests/Feature/ControlRoom/StaleAlertAutoResolutionTest.php`

- [x] **Step 7: Commit**

```powershell
git add app/Services/ControlRoom/ControlRoomAlertLifecycleService.php app/Models/ControlRoomAlert.php app/Models/ControlRoom/AlertSla.php app/Http/Controllers/ControlRoom app/Jobs routes/control-room.php tests/Unit/ControlRoom tests/Feature/ControlRoom
git commit -m "fix(control-room): make lifecycle and sla truthful"
```

**Task 7 completion evidence — 2026-07-15**

- Implementation commit: `16605c165c5ffa3419515b2e035d540c732e1fb8`.
- Alert lifecycle, SLA cycles, escalation, snooze, task cancellation/transfer, incident reopen, sensor confirmation/dismissal, stale-alert automation, operational reports, dashboards and worklists now share the canonical active-status and site/tenant provenance rules. Terminal and unknown statuses cannot leak into default actionable queues.
- Control Room task resolution rejects open work; task deletion is no longer a lifecycle bypass; incident follow-ups reject closed incidents under lock; H&S transfer is idempotent and records the destination action. Audit writes needed for safety/lifecycle integrity are transactionally durable.
- The expanded operational boundary now fail-closes Fleet/Lone Worker person attribution. It rechecks assignment history, device/tracker/asset/site/client identity and tracking consent after the base SOS callback, prevents silent worker/resident rerouting, preserves a masked safety alarm when consent alone changes, delays trip/driver metrics until final acceptance, and persists a privacy marker so delayed queue workers cannot reconstruct discarded person/location context.
- Final Control Room corrective gate: 363 tests / 2,326 assertions. Final telemetry/Lone Worker/Control Room provenance gate: 176 tests / 6,652 assertions. Post-format telemetry focus: 11 tests / 1,469 assertions. Additional affected-file gates included 32 tests / 263 assertions and the full Fleet telemetry file at 54 tests / 5,602 assertions.
- Pint was idempotent, all touched PHP files passed syntax lint, and the whole worktree passed `git diff --check`. Independent Task 7 specification re-review returned `PASS`; the final telemetry/privacy findings were closed with root read-only review plus non-vacuous regressions.
- No desktop browser acceptance, mobile/responsive/mobile testing, WebView work, merge, push, or deployment was included in Task 7. Desktop browser and five-journey acceptance remain in Tasks 15–16.

---

### Task 8: Enforce H&S recommendation disposition and closure gates

**Files:**

- Modify: `app/Models/HsInvestigation.php`
- Modify: `app/Models/HsEvent.php`
- Modify: `app/Services/HealthSafety/HsInvestigationService.php`
- Modify: `app/Services/HealthSafety/HsEventService.php`
- Modify: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Modify: `app/Http/Controllers/HealthSafety/HsInvestigationController.php`
- Modify: `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php`
- Modify: `app/Services/UserSiteAccessService.php`
- Modify: `database/factories/HsEventFactory.php`
- Modify: `database/seeders/RbacSeeder.php`
- Modify: `routes/health-safety.php`
- Modify: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Test: `tests/Feature/HealthSafety/HsRecommendationDispositionTest.php`
- Test: `tests/Feature/HealthSafety/HsEventClosureTest.php`
- Test: `tests/Feature/HealthSafety/HsEventWorkflowTest.php`
- Test: `tests/Feature/HealthSafety/HsEventSiteIsolationTest.php`
- Test: `resources/js/components/health-safety/event-detail-dialog.test.tsx`

- [x] **Step 1: Write failing disposition and closure tests**

For every recommendation, require exactly one disposition: `corrective_action`, `accepted_risk`, `duplicate`, or `no_action`. Non-action dispositions require a reason. Corrective-action disposition requires the linked action. H&S closure also blocks awaiting acceptance and WorkSafe pending.

- [x] **Step 2: Run and verify the zero-actions false-positive**

Expected: current `allCorrectiveActionsResolved()` allows closure with recommendations but zero actions.

- [x] **Step 3: Implement disposition service methods and endpoint**

```php
public function dispositionRecommendation(HsInvestigation $investigation, int $index, string $disposition, User $actor, ?string $reason = null): HsRecommendationDisposition;
public function undispositionedRecommendationIndexes(HsInvestigation $investigation): array;
```

- [x] **Step 4: Add all closure blockers**

`HsEventService::closeBlockers()` returns blockers for unaccepted handover, pending WorkSafe, incomplete investigation, undispositioned recommendation, and unverified/open corrective action. Add the explicit `healthSafety.overrideClosure` permission; a reason alone never authorises bypass. Every authorised override records actor, reason, and blockers in the audit trail.

- [x] **Step 5: Render disposition controls and blockers**

Each recommendation row shows its disposition, linked corrective action, actor/time, and a role-authorised action. The closure pane lists blockers in plain language.

- [x] **Step 6: Run governance tests**

Run: `php artisan test tests/Feature/HealthSafety/HsRecommendationDispositionTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsInvestigationTest.php tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsEventWorkflowTest.php tests/Feature/HealthSafety/HsEventSiteIsolationTest.php && npm test -- resources/js/components/health-safety/event-detail-dialog.test.tsx`

- [x] **Step 7: Commit**

```powershell
git add app/Models/HsInvestigation.php app/Models/HsEvent.php app/Services/HealthSafety app/Http/Controllers/HealthSafety routes/health-safety.php resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx tests/Feature/HealthSafety
git commit -m "feat(health-safety): disposition investigation recommendations"
```

**Task 8 completion evidence — 2026-07-15**

- Implementation commit: `9750a46b4066c0b5bd16abb7a18312ec0479955c`. The TypeScript correction discovered by this gate is isolated in `ae8d0067f23c0fe833b7ceb9b2421c4b7565baa9`.
- RED proof reproduced the zero-action false-positive, missing recommendation outcome endpoint, reason/permission gaps, unscoped corrective-action mutations and cross-site assignee acceptance. The new site-isolation suite initially failed exactly the two missing controller boundaries before the shared eligibility fix.
- Every completed recommendation now has one auditable outcome: corrective action, accepted risk, duplicate, or no action. Non-action outcomes require a reason; corrective-action outcomes create or reuse one canonical linked action. Rows show the decision, linked action, actor/time and one clear next action.
- H&S closure now lists and enforces acceptance, WorkSafe, active/required investigation, recommendation and corrective-action gates. A reason alone never bypasses them; only `healthSafety.overrideClosure` plus a reason can do so, with actor, exact blockers and reason written through the strict transactional audit path.
- Every investigation and corrective-action mutation now resolves the H&S event through the tenant/site boundary. Lead, team, approver and action-owner IDs reuse the same approved H&S event-site eligibility as the UI picker and do not disclose cross-site or nonexistent staff through a generic existence check.
- Final post-format backend gate: 97 tests / 353 assertions. Desktop event-detail component gate: 8 tests. `npm run types`, PHP syntax lint for all touched PHP, Pint test mode, Prettier check and `git diff --check` all exited 0.
- Root specification/code-quality re-review found no remaining Task 8 P0/P1. No browser E2E, mobile/responsive/WebView test, merge, push or deployment was performed; the five desktop journeys and final visual audit remain in Tasks 15–16.

---

### Task 9: Create canonical journey and alert presenters plus shared visual primitives

**Files:**

- Create: `app/Services/Incidents/IncidentJourneyPresenter.php`
- Create: `app/Services/ControlRoom/AlertPriorityService.php`
- Create: `app/Services/ControlRoom/AlertWorklistQuery.php`
- Create: `app/Services/ControlRoom/AlertWorklistPresenter.php`
- Create: `resources/js/components/command-centre/hero-kit.tsx`
- Create: `resources/js/components/command-centre/workflow-ribbon.tsx`
- Create: `resources/js/components/control-room/alert-worklist/types.ts`
- Create: `resources/js/components/control-room/alert-worklist/alert-status.tsx`
- Create: `resources/js/components/incidents/incident-journey-status.tsx`
- Create: `resources/js/lib/control-room-vocab.ts`
- Modify: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
- Modify: `resources/js/pages/health-safety/components/workflow-ribbon.tsx`
- Test: `tests/Unit/ControlRoom/AlertPriorityServiceTest.php`
- Test: `tests/Unit/ControlRoom/AlertWorklistPresenterTest.php`
- Test: `tests/Feature/Incidents/IncidentJourneyPresenterTest.php`
- Test: `resources/js/components/command-centre/hero-kit.test.tsx`
- Test: `resources/js/components/incidents/incident-journey-status.test.tsx`

- [x] **Step 1: Write failing presenter tests**

Assert official references, incident-time site, narrative/controls/evidence, playbook/comms/tasks, H&S acceptance/owner, WorkSafe, three lifecycle states, and role-aware next action. Assert no fabricated `CR-{id}` or `INC-{id}`.

- [x] **Step 2: Write failing priority tests**

Order SLA breached → explicit priority/severity fallback → escalation → next deadline → oldest. Include dismissed, snoozed, confirmed, unassigned, and tied rows. Assert the presenter emits a human-readable priority reason.

- [x] **Step 3: Implement presenters and shared query**

Use `scopeActionable()`, site scoping, deterministic ordering, and eager loading. Return one canonical payload to Desk, Active alerts, My queue, Safety handovers, and shift handover.

- [x] **Step 4: Extract neutral hero/ribbon primitives**

Move `HeroShell`, status pill, medallion, clusters, tiles, segmented control, and summary strip under `command-centre`. Keep H&S compliance badges in H&S and re-export old symbols to prevent visual churn.

- [x] **Step 5: Implement shared vocabulary and accessible status**

Every severity/SLA/status uses icon + text + colour. Dates use `resources/js/lib/datetime.ts` with `en-NZ` and `Pacific/Auckland`.

- [x] **Step 6: Run backend and frontend unit tests**

Run: `php artisan test tests/Unit/ControlRoom/AlertPriorityServiceTest.php tests/Unit/ControlRoom/AlertWorklistPresenterTest.php tests/Feature/Incidents/IncidentJourneyPresenterTest.php && npm test -- resources/js/components/command-centre/hero-kit.test.tsx resources/js/components/incidents/incident-journey-status.test.tsx`

- [x] **Step 7: Commit**

```powershell
git add app/Services/Incidents app/Services/ControlRoom resources/js/components/command-centre resources/js/components/control-room/alert-worklist resources/js/components/incidents resources/js/lib/control-room-vocab.ts resources/js/pages/health-safety/components tests/Unit tests/Feature resources/js/**/*.test.tsx
git commit -m "feat(ui): share incident journey command-centre language"
```

**Task 9 evidence (2026-07-15):**

- RED proof first failed on the absent presenter/query/priority/component classes; implementation then made the canonical six-test backend contract pass with 38 assertions.
- The final worklist query/presenter rerun passed 2 tests / 12 assertions after adding permission-scoped eager loading; the existing nested-provenance regression suite passed 8 tests / 45 assertions after the loaded-client optimisation.
- Frontend proof passed 9 tests across the neutral command-centre hero, three-stage incident journey status and existing H&S compliance hero contract. `npm run types`, focused ESLint, PHP syntax checks, Pint test mode, Prettier check and `git diff --check` all exited 0.
- The shared order is SLA breach, explicit priority/severity fallback, escalation, next applicable deadline, oldest trigger and deterministic ID. Default worklists include confirmed alerts and exclude dismissed/currently snoozed rows.
- Official alert/incident/H&S references remain authoritative; missing references stay null. No desktop browser E2E, mobile/responsive/WebView test, merge, push or deployment was performed in this task.

---

### Task 10: Rebuild the Control Room Desk dashboard

**Files:**

- Create: `app/Services/ControlRoom/ControlRoomDeskService.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomDashboardController.php`
- Modify: `app/Services/ControlRoom/AlertWorklistQuery.php`
- Modify: `app/Services/ControlRoom/AlertWorklistPresenter.php`
- Modify: `app/Services/UserSiteAccessService.php`
- Modify: `app/Models/User.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Replace: `resources/js/pages/control-room/index.tsx`
- Create: `resources/js/components/control-room/dashboard/control-room-hero.tsx`
- Create: `resources/js/components/control-room/dashboard/live-desk-panel.tsx`
- Create: `resources/js/components/control-room/dashboard/continuity-panel.tsx`
- Create: `resources/js/components/control-room/dashboard/queue-pressure-panel.tsx`
- Create: `resources/js/components/control-room/dashboard/service-health-panel.tsx`
- Create: `resources/js/components/control-room/dashboard/analytics-panel.tsx`
- Test: `tests/Feature/ControlRoom/ControlRoomDeskTest.php`
- Test: `resources/js/components/control-room/dashboard/control-room-dashboard.test.tsx`

- [x] **Step 1: Write failing controller-contract and query-budget tests**

Assert props `hero`, `worklist`, `queues`, `handover`, `activity`, `filters`, `freshness`, and optional `analytics`. Assert worklist IDs/order match `AlertWorklistQuery`, dismissed/snoozed excluded, response time is calculated from `responded_at` rather than acknowledgement, an unavailable response average remains null rather than `0m`, and a live partial reload runs no analytics query.

- [x] **Step 2: Write failing DOM-order and accessibility tests**

Assert the operational workflow ribbon, hero actions, Now and Continuity clusters, filters, and priority worklist render before service health; historical charts are absent until Analytics opens; freshness has Updated/Refreshing/Stale text.

- [x] **Step 3: Implement the Desk service and deferred analytics**

Wrap Inertia props in closures. Make analytics optional and 60–120 second cached. Poll only live props and pause while `document.hidden`. Detect new critical alerts by stable IDs.

- [x] **Step 4: Build the desktop composition**

First viewport: workflow ribbon → hero/filters → priority worklist plus continuity panel. Remove duplicate KPI cards, quick-stat row, and charts-before-work. Keep only Last 24 hours summary with an Analytics link.

- [x] **Step 5: Verify performance contract**

Record query count for the live partial endpoint. Target at most 15 SQL statements and no analytics query. If the target is exceeded, replace cloned counts with conditional aggregates before proceeding.

- [x] **Step 6: Run tests**

Run: `php artisan test tests/Feature/ControlRoom/ControlRoomDeskTest.php tests/Feature/ControlRoom/ControlRoomDashboardTest.php && npm test -- resources/js/components/control-room/dashboard/control-room-dashboard.test.tsx`

- [x] **Step 7: Commit**

```powershell
git add app/Services/ControlRoom/ControlRoomDeskService.php app/Http/Controllers/ControlRoom/ControlRoomDashboardController.php resources/js/pages/control-room/index.tsx resources/js/components/control-room/dashboard tests/Feature/ControlRoom resources/js/components/control-room/dashboard/control-room-dashboard.test.tsx
git commit -m "feat(control-room): rebuild dashboard as live desk"
```

**Task 10 evidence (2026-07-15):**

- RED proof first failed on the missing Desk service and dashboard components. The completed contract exposes live `hero`, canonical `worklist`, `queues`, `handover`, `activity`, `filters` and `freshness`; `analytics` is permission-gated, optional, cached for 90 seconds and absent from the initial response and live polling path.
- The dashboard now leads with the shared incident-response ribbon, one operational hero, explicit Now/Continuity groups, a deterministic priority worklist, H&S handover continuity, real queue-pressure filters and readable Updated/Refreshing/Stale service health. Historical analytics opens from the Last 24 hours strip and gives loading feedback instead of blocking the Desk.
- Polling requests only the six live props every 30 seconds, pauses while `document.hidden`, does not write repeated dashboard-view audit records and detects newly visible critical alerts by stable IDs. Dismissed and currently snoozed alerts remain outside the live worklist.
- The query-budget gate passed at no more than 15 live-data SQL statements. Repeated RBAC/site checks now reuse request-scoped access state while retaining tenant, site, client-integrity and selected-site precedence rules; the canonical site-access regressions passed 24 tests / 97 assertions.
- The combined new and legacy dashboard run passed 35 tests / 375 assertions. A final Desk plus access-safety run passed 24 tests / 97 assertions, including the new queue-filter contract. The dashboard component suite passed 3 tests; `npm run types`, focused ESLint, PHP syntax, Pint, Prettier and `git diff --check` exited cleanly.
- No desktop browser E2E, mobile/responsive/WebView test, merge, push or deployment was performed in Task 10. Browser proof remains deliberately scheduled for Tasks 15–16 after the workflow surfaces are complete.

---

### Task 11: Unify Active alerts, workspace next action, and Safety handovers

**Files:**

- Modify: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`
- Replace: `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php`
- Modify: `app/Services/ControlRoom/AlertWorkspaceService.php`
- Modify: `routes/control-room.php`
- Modify: `resources/js/pages/control-room/alerts/index.tsx`
- Replace: `resources/js/pages/control-room/incidents.tsx`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.tsx`
- Create: `resources/js/components/control-room/alert-workspace/next-action.ts`
- Create: `resources/js/components/control-room/alert-workspace/linked-journey.tsx`
- Create: `resources/js/components/control-room/alert-worklist/alert-worklist.tsx`
- Create: `resources/js/components/control-room/alert-worklist/alert-worklist-row.tsx`
- Test: `tests/Feature/ControlRoom/ControlRoomSafetyHandoverTest.php`
- Test: `resources/js/components/control-room/alert-workspace/next-action.test.ts`

- [x] **Step 1: Write failing Active alerts tests**

Assert default actionable lens, canonical ordering, visible summary/playbook/SLA text, sorting label parity, accessible sort buttons/checkbox names, and terminal records absent until an explicit history lens.

- [x] **Step 2: Write failing alert-to-incident route tests**

Route `POST /control-room/alerts/{alert}/create-incident` must be authorised, call `submitFromAlert()`, return official references, keep the same alert, and be idempotent.

- [x] **Step 3: Write failing Safety handover tests**

Assert lenses Needs incident, Awaiting H&S, Accepted/investigating, Operational complete/governance continuing, and Complete. The controller must query canonical journeys with database pagination; it must not load all ClientIncident/MedicationError/SafeguardingConcern rows into PHP.

- [x] **Step 4: Implement list/workspace changes**

Render canonical rows and one recommended CTA. Keep sensor Confirm/Dismiss. Put assign/escalate/snooze/edit in secondary actions. The linked section offers `Create incident and hand over`, `Open incident`, or `Continue in H&S` based on actual links/capabilities.

- [x] **Step 5: Replace fake/raw references and dead instructions**

Remove `CR-{id}`, `INC-{id}`, “Flag incident in Incident Tracker,” unconditional “Create Alert,” and the duplicate raw-domain detail dialog.

- [x] **Step 6: Run tests**

Run: `php artisan test tests/Feature/ControlRoom/ControlRoomSafetyHandoverTest.php tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php tests/Feature/ControlRoom/ControlRoomAlertControllerTest.php && npm test -- resources/js/components/control-room/alert-workspace/next-action.test.ts`

- [x] **Step 7: Commit**

```powershell
git add app/Http/Controllers/ControlRoom app/Services/ControlRoom/AlertWorkspaceService.php routes/control-room.php resources/js/pages/control-room resources/js/components/control-room tests/Feature/ControlRoom
git commit -m "feat(control-room): unify alerts and safety handovers"
```

**Task 11 evidence (2026-07-15):**

- RED proof first failed on the absent canonical `sla`/sort payload, missing alert-to-incident route, missing handover lens contract, missing `next-action.ts`, and missing accessible Alert worklist component. The completed tests cover the actionable/history boundary, shared priority order, summary/playbook/SLA context, official references, idempotent journey creation, all five handover states, and a 25-row database paginator.
- Active alerts now render the shared canonical worklist with named selection/sort controls, readable priority and deadline reasons, playbook progress, official linked references, an explicit History lens, and one `Continue response` action. The integration-alerts reuse path retains its compatibility table until Task 13.
- The Alert workspace now shows the official CR reference, one visible Control Room → incident → H&S journey, and one recommended action selected from sensor confirmation, `Create incident and hand over`, `Open incident`, or `Continue in H&S`. Assignment, escalation, snooze, editing, and sensor dismissal remain secondary controls. Fake `CR-{id}`/`INC-{id}` labels and the dead Incident Tracker instruction were removed from these surfaces.
- Safety handovers replaces the old mixed-domain tracker UI with canonical alert-led journeys and the lenses Needs incident, Awaiting H&S, Accepted/in progress, Operations done/H&S open, and Complete. The primary journey query is site-scoped and database-paginated; the unused legacy response prop is a bounded 25-row-per-source compatibility preview rather than an unbounded PHP load.
- The final Task 11 backend gate passed 89 tests / 607 assertions across the new Safety handover suite, legacy incident-controller coverage, and the complete alert-controller lifecycle. The wider regression correction gate passed 85 tests / 518 assertions, including the 15-query Desk budget, tenant isolation, site-precedence filtering, platform-only shifts, and fresh transaction-actor authorization.
- Frontend focused proof passed 7 tests across next-action, accessible worklist, and linked H&S presentation. `npm run types`, focused ESLint, Pint, Prettier, PHP syntax and `git diff --check` exited cleanly.
- No desktop browser E2E, mobile/responsive/WebView test, merge, push or deployment was performed. Browser proof remains scheduled for Tasks 15–16 after the remaining Control Room surfaces, universal `/tasks` integration, and five deterministic journeys are complete.

---

### Task 12: Implement Prepared → Accepted Control Room shift handover

**Files:**

- Create: `app/Services/ControlRoom/ControlRoomShiftHandoverService.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomHandoverController.php`
- Modify: `app/Http/Controllers/ControlRoom/ControlRoomShiftController.php`
- Modify: `app/Models/ControlRoom/Shift.php`
- Modify: `routes/control-room.php`
- Modify: `resources/js/pages/control-room/shifts/handover.tsx`
- Modify: `resources/js/pages/control-room/shifts.tsx`
- Test: `tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php`

- [x] **Step 1: Write failing prepared/accepted state tests**

Preparing requires an incoming lead and explicit review of every critical/high alert. It stores structured snapshots and leaves the outgoing shift active. Only the selected incoming lead can accept. Acceptance atomically completes outgoing and activates incoming, recording actor/time once.

- [x] **Step 2: Write the reactivation regression test**

Calling the legacy acknowledge route on a completed shift must never set that shift back to active or create two active shifts.

- [x] **Step 3: Implement service API**

```php
public function prepare(Shift $outgoing, User $incomingLead, array $reviewedAlertIds, User $actor, int $expectedVersion): Shift;
public function accept(Shift $outgoing, User $actor, int $expectedVersion): Shift;
```

Snapshots use the canonical alert presenter and include reference, summary, person/site, owner, SLA, tasks, incident/H&S state, and next action.

- [x] **Step 4: Add autosave/resume and version conflict UI**

Persist draft fields after changes, show `Saved`, require incoming lead before final review, make snapshot rows openable, and show a conflict message when the version changed.

- [x] **Step 5: Run tests**

Run: `php artisan test tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php tests/Feature/ControlRoom/ControlRoomHandoverControllerTest.php tests/Feature/ControlRoom/ControlRoomShiftControllerTest.php`

- [x] **Step 6: Commit**

```powershell
git add app/Services/ControlRoom/ControlRoomShiftHandoverService.php app/Http/Controllers/ControlRoom app/Models/ControlRoom/Shift.php routes/control-room.php resources/js/pages/control-room/shifts tests/Feature/ControlRoom
git commit -m "feat(control-room): require incoming handover acceptance"
```

**Task 12 completion evidence — 2026-07-15**

- The former one-click completion flow is replaced by an autosaved, resumable `Prepared → Accepted` contract. The outgoing lead must explicitly review every visible active critical/high alert; only the selected eligible incoming lead can accept; the outgoing shift remains active until that acceptance atomically completes it and creates one incoming active shift.
- The frozen snapshot uses the canonical alert worklist presenter and carries official reference, summary, person/site, assignee, SLA, open tasks, incident/H&S journey state, and canonical next action. Carry-forward priorities are linked alert IDs rather than free-text pseudo-tickets, with a direct Universal Tasks link for application-wide work.
- Optimistic version checks prevent stale draft/prepare/accept writes. Transaction locks, idempotent re-acceptance, legacy-route guarding, and the active-shift start guard prevent duplicate active shifts, reactivation of history, and bypass of prepared acceptance.
- Focused PHP gate: 17 tests / 165 assertions. TypeScript, targeted ESLint, Prettier, Pint, PHP lint, and `git diff --check` passed. Specification and code-quality self-reviews passed; no mobile, WebView, browser, merge, push, or deployment work was included.

---

### Task 13: Roll the shared command-centre shell through the full Control Room module

**Files:**

- Create: `resources/js/components/command-centre/workspace-strip.tsx`
- Create: `resources/js/components/command-centre/command-centre-page.tsx`
- Modify: `resources/js/pages/control-room/escalations.tsx`
- Modify: `resources/js/pages/control-room/my-tasks.tsx`
- Modify: `resources/js/pages/control-room/map.tsx`
- Modify: `resources/js/pages/control-room/shifts.tsx`
- Modify: `resources/js/pages/control-room/stats.tsx`
- Modify: `resources/js/pages/control-room/reports.tsx`
- Modify: `resources/js/pages/control-room/messaging.tsx`
- Modify: `resources/js/pages/control-room/settings.tsx`
- Modify: `resources/js/pages/control-room/devices/index.tsx`
- Modify: `resources/js/pages/control-room/devices/show.tsx`
- Modify: `resources/js/pages/control-room/playbooks/index.tsx`
- Modify: `resources/js/pages/control-room/playbooks/show.tsx`
- Modify: `resources/js/pages/control-room/sla/index.tsx`
- Modify: `resources/js/pages/control-room/sla/breaches.tsx`
- Modify: `resources/js/pages/control-room/broadcast.tsx`
- Modify: `resources/js/pages/control-room/broadcast-show.tsx`
- Modify: `resources/js/pages/control-room/show.tsx`
- Test: `resources/js/components/command-centre/workspace-strip.test.tsx`
- Test: `resources/js/components/command-centre/command-centre-page.test.tsx`
- Test: `tests/Feature/ControlRoom/ControlRoomShellConsistencyTest.php`

- [x] **Step 1: Inventory every routed Control Room page in a failing consistency test**

Build the expected route/component matrix from `routes/control-room.php`. Assert each primary operator page uses the shared workspace strip and either the full or compact command-centre shell, and assert each specialist page has a unique title rather than the repeated generic `Command Centre` label.

- [x] **Step 2: Write failing component semantics tests**

`WorkspaceStrip` renders route navigation in a `<nav>`, sets `aria-current="page"` on the active destination, supports keyboard focus, and does not use `role="tab"` without a tab panel. `CommandCentrePage` exposes title, description, status/freshness, actions, optional workflow, and optional footer filters.

- [x] **Step 3: Implement full and compact shell variants**

Use the extracted hero primitives. The full variant supports Desk, Active alerts, Escalations, Safety handovers, My queue, and Shifts. The compact variant gives Devices, Playbooks, SLA, Reports, Stats, Broadcasts, Messaging, and Settings the same eyebrow, medallion, hierarchy, spacing, and action treatment without duplicating dashboard KPIs.

- [x] **Step 4: Replace page-local status/date/reference maps while touching each page**

Use `control-room-vocab.ts`, the shared alert status component, and `resources/js/lib/datetime.ts`. Preserve domain-specific content and actions. Remove raw enum labels, colour-only SLA dots, fabricated references, and repeated local relative-time helpers from the listed pages.

- [x] **Step 5: Apply page-specific information architecture**

Use exact titles: `Escalations`, `My queue`, `Live map`, `Control Room shifts`, `Operational analytics`, `Reports`, `Messaging`, `Settings`, `Devices`, `Playbooks`, `SLA performance`, and `Broadcasts`. Keep route navigation stable and ensure the first action matches the page purpose.

- [x] **Step 6: Run component, route, type, and formatting checks**

Run: `npm test -- resources/js/components/command-centre/workspace-strip.test.tsx resources/js/components/command-centre/command-centre-page.test.tsx && php artisan test tests/Feature/ControlRoom/ControlRoomShellConsistencyTest.php && npm run types && npx prettier --check resources/js/components/command-centre resources/js/pages/control-room`

Expected: all routed desktop Control Room pages use the shared shell contract and compile without new type or formatting errors.

- [x] **Step 7: Commit**

```powershell
git add resources/js/components/command-centre resources/js/pages/control-room tests/Feature/ControlRoom/ControlRoomShellConsistencyTest.php
git commit -m "feat(control-room): unify the command-centre module shell"
```

**Task 13 evidence (2026-07-15):**

- Added the semantic desktop workspace strip and full/compact `CommandCentrePage` shell, then applied unique page purpose, hierarchy and actions across every routed Control Room operator and specialist surface in the route matrix.
- Replaced remaining compatibility-table, My queue, escalation, device-history and SLA-breach alert badges with the shared alert vocabulary; added truthful official references and removed fabricated `CR-123`/`#123` labels and repeated page-local relative-time formatters.
- `npx vitest run resources/js/components/command-centre/command-centre-page.test.tsx resources/js/components/command-centre/workspace-strip.test.tsx resources/js/components/control-room/alert-worklist/alert-worklist.test.tsx` passed: 3 files, 4 tests.
- `npm run types`, targeted ESLint, targeted Prettier, Pint, PHP syntax checks and `git diff --check` passed.
- Focused Control Room shell/My queue/escalation/shift suite passed: 29 tests, 212 assertions. Operational surface site-isolation passed: 17 tests. The SLA breach fixture was corrected to use its tenant site and then passed with 18 assertions, including the official-reference contract.
- Desktop browser proof remains intentionally deferred to Tasks 15–16. No mobile/responsive/WebView testing, merge, push or deployment was performed.

---

### Task 14: Integrate Universal Tasks, add reconciliation tooling, and clean navigation

**Files:**

- Create: `app/Services/Incidents/IncidentJourneyReconciler.php`
- Create: `app/Console/Commands/ReconcileIncidentJourneys.php`
- Modify: `app/Services/Tasks/TaskAggregator.php`
- Modify: `app/Services/Tasks/TaskItem.php`
- Modify: `app/Services/Tasks/Providers/ControlRoomAlertProvider.php`
- Modify: `app/Services/Tasks/Providers/ClientIncidentProvider.php`
- Modify: `app/Services/Tasks/Providers/IncidentFollowupProvider.php`
- Modify: `app/Services/Tasks/Providers/HsEventProvider.php`
- Modify: `app/Services/Tasks/Providers/HsInvestigationProvider.php`
- Modify: `app/Services/Tasks/Providers/HsCorrectiveActionProvider.php`
- Modify: `resources/js/pages/tasks/index.tsx`
- Modify: `resources/js/pages/tasks/task-detail-dialog.tsx`
- Modify: `resources/js/components/app-sidebar.tsx`
- Modify: `resources/js/pages/control-room/my-tasks.tsx`
- Test: `tests/Feature/Tasks/AllTasksIncidentJourneyTest.php`
- Test: `resources/js/pages/tasks/tasks-incident-journey.test.tsx`
- Test: `tests/Feature/Console/ReconcileIncidentJourneysTest.php`
- Test: `tests/Feature/Navigation/ControlRoomNavigationTest.php`

- [x] **Step 1: Write failing Universal Tasks journey-contract tests**

For the five journey entry paths, assert that `/tasks` shows every genuinely actionable Alert, Incident follow-up, H&S investigation and corrective action exactly once, with the source module, official references, incident-time site, authorised person label, owner, due/SLA state and canonical deep link. Assert tenant/site/sensitivity isolation, active-by-default behaviour, explicit completed history, and stable filtering/search by any official reference.

- [x] **Step 2: Write failing transfer and deduplication tests**

Transferring an operational task to H&S must remove the source responsibility from active work and expose the one linked corrective action. Retries must not duplicate it. Keep separate accountable work—such as incident review and a corrective action—separate, but group it under one journey summary so staff understand why multiple actions exist.

- [x] **Step 3: Implement the Universal Tasks contract**

Keep `/tasks` as the application-wide hub and Control Room `My queue` as a filtered specialist view. Extend `TaskItem` with journey references/source context, make every provider reuse canonical permissions and site scopes, and link rows to the source workspace. Use the shared status/date/reference language from Task 9. Do not create a second task lifecycle or allow Universal Tasks mutations to bypass source-module gates.

- [x] **Step 4: Write failing dry-run/apply/rerun tests**

Seed missing H&S, inconsistent direct links, duplicate incident alerts, missing references, WorkSafe projection drift, missing site snapshot, dismissed-active data, and existing managed H&S events without acceptance. Dry-run reports counts without mutation; apply repairs deterministic cases and records ambiguities; a second apply reports zero repairs.

- [x] **Step 5: Implement reconciler issue/result types**

Return counts for `missing_hs`, `link_mismatch`, `duplicate_alert`, `missing_reference`, `worksafe_drift`, `missing_site`, `dismissed_active`, `acceptance_backfill`, and `ambiguous`.

- [x] **Step 6: Implement command**

Command: `incidents:reconcile-journeys {--apply} {--incident=} {--chunk=200}`. Default is dry-run. Apply uses chunked transactions and emits a non-zero exit only for unresolved fatal errors.

- [x] **Step 7: Clean navigation vocabulary**

Rename Control Room's `My Day` entry to `My Control Room queue`/`My queue`; keep `/my-day` as the only support-worker My Day. Rename Incident Tracker to Safety handovers and Overview to Desk. Keep Universal Tasks visibly application-wide and label Control Room `My queue` as a filtered view, so the two destinations are not mistaken for duplicate task systems.

- [x] **Step 8: Run tests and a dry-run**

Run: `php artisan test tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/Console/ReconcileIncidentJourneysTest.php tests/Feature/Navigation/ControlRoomNavigationTest.php && npm test -- resources/js/pages/tasks/tasks-incident-journey.test.tsx && php artisan incidents:reconcile-journeys`

Expected: Universal Tasks shows one truthful, scoped cross-module work feed; tests pass; dry-run prints a structured report and performs no writes.

- [x] **Step 9: Commit**

```powershell
git add app/Services/Incidents/IncidentJourneyReconciler.php app/Console/Commands/ReconcileIncidentJourneys.php app/Services/Tasks resources/js/pages/tasks resources/js/components/app-sidebar.tsx resources/js/pages/control-room/my-tasks.tsx tests/Feature/Tasks tests/Feature/Console tests/Feature/Navigation
git commit -m "feat(tasks): unify incident journey work and reconciliation"
```

**Task 14 evidence (2026-07-15):**

- Universal Tasks now keeps separate source-owned responsibilities but groups them with truthful Control Room, Incident and H&S references, incident-time person/site context, source-specific next actions and canonical workspace links. Search by any official reference returns the whole journey without inventing a second task lifecycle.
- All six journey providers now reuse the source modules' canonical tenant/site scopes. Corrective-action reassignment also revalidates the parent H&S event and site-eligible assignee at the mutation boundary.
- Control Room sidebar vocabulary now matches the workspace strip: `Desk`, `My queue` and `Shifts`. The Control Room queue explicitly identifies Universal Tasks as the application-wide hub; `/my-day` remains the support-worker destination.
- `AllTasksIncidentJourneyTest` passed 5 tests / 50 assertions, covering all five entry sources, reference search, site isolation, active/history separation, separate accountable work, and retry-safe transfer to one corrective action.
- `ReconcileIncidentJourneysTest` passed 2 tests / 39 assertions. Dry-run, apply, deterministic repair, ambiguity preservation and zero-repair rerun are covered across all nine issue categories. Navigation and UI journey-reference tests passed; TypeScript, ESLint, Pint, PHP syntax and `git diff --check` passed.
- A read-only command run against the existing MySQL test database scanned 85 legacy/demo incidents and reported 255 issues with 0 repairs. The unconfigured local SQLite attempt failed before querying because the worktree has no SQLite file; no database mutation occurred.
- Desktop browser proof remains intentionally deferred to Tasks 15–16. No mobile/responsive/WebView testing, merge, push or deployment was performed.

---

### Task 15: Build deterministic desktop five-incident acceptance coverage

**Files:**

- Create: `database/seeders/IncidentHandoverE2ESeeder.php`
- Create: `tests/e2e/incident-handover.spec.ts`
- Create: `tests/e2e/incident-handover-helpers.ts`
- Modify: `tests/e2e/helpers.ts`
- Modify: `tests/e2e/control-room-dashboard.spec.ts`
- Modify: `tests/e2e/control-room-alert-lifecycle.spec.ts`
- Modify: `tests/e2e/control-room-smoke.spec.ts`
- Test evidence: `output/playwright/incident-handover/`

- [x] **Step 1: Add deterministic role/site/client fixtures**

Seeder creates site `Playwright Incident Handover House`, client `Playwright Aroha Handover`, assigned support worker, coordinator/operator, incident reviewer, H&S owner, and independent H&S verifier. It returns IDs/references through a machine-readable manifest and is safe to rerun.

- [x] **Step 2: Add `readIncidentJourney()` database invariant helper**

Return exact counts, IDs, references, direct links, alert/incident/H&S states, acceptance, WorkSafe, investigation, recommendations/dispositions, corrective actions, and timestamps. Poll with `expect.poll()` after writes.

- [x] **Step 3: Implement Scenario 1 — existing alert to accepted H&S**

Drive dashboard → alert workspace → create incident and hand over → H&S awaiting worklist → accept. Assert retry idempotency and preserved alert notes/tasks/evidence.

- [x] **Step 4: Implement Scenario 2 — support-worker draft then submit**

Assert draft creates no H&S/alert; submit same incident creates one awaiting H&S event and no alert for medium severity; accept; support worker sees read-only acceptance without governance controls.

- [x] **Step 5: Implement Scenario 3 — high/notifiable full governance**

Assert automatic single alert, WorkSafe count equality, separate operational/incident/H&S states, acceptance, notify/acknowledge, investigation, recommendation disposition, corrective-action separation of duties, and governance closure.

- [x] **Step 6: Implement Scenario 4 — sensor fall confirmation**

Assert existing alert reused, evidence visible, one incident/H&S, retry stable, accepted in H&S, then operationally resolved.

- [x] **Step 7: Implement Scenario 5 — two similar incidents and medication correlation**

Create two same-client/type incidents within 30 minutes and verify distinct alert correlation when escalated. Include the medication-origin incident as one of the two and prove its signal enriches rather than duplicates the journey.

- [x] **Step 8: Update stale selectors and run desktop only**

Run:

```powershell
$env:PLAYWRIGHT_PORT='4187'
npx playwright test tests/e2e/incident-handover.spec.ts --project=chromium-desktop --workers=1
```

Expected: five scenarios pass, zero console errors, no serious/critical Axe violations, screenshots/invariant JSON exist, and no mobile project runs.

- [x] **Step 9: Commit**

```powershell
git add database/seeders/IncidentHandoverE2ESeeder.php tests/e2e output/playwright/incident-handover
git commit -m "test(incidents): prove five desktop handover journeys"
```

**Task 15 completion evidence (2026-07-15):**

- Deterministic MySQL fixtures cover the operator/coordinator, support worker, incident reviewer, H&S owner and independent H&S verifier at one isolated site. The fixture seeder is safe to rerun and the browser helper returns machine-readable role/site/client identities.
- The five desktop scenarios passed both independently and in the final combined run. Each scenario wrote a current PNG plus database invariant JSON under `output/playwright/incident-handover/`; the reports contain generated CR/INC/HS references, direct-link IDs, separate lifecycle states, H&S acceptance and exact source counts.
- Scenario 1 preserved the original Control Room note, task and evidence through handover; scenario 2 proved truthful draft/submit behaviour plus an independent reviewer close while H&S remained open/accepted; scenario 3 completed WorkSafe, investigation, recommendation disposition, corrective action, independent verification and governance closure; scenario 4 reused the sensor alert/evidence; scenario 5 kept similar manual and medication incidents distinct while enriching one journey.
- The authoritative combined desktop-only command passed 37 tests in 5.7 minutes with exit 0 on port 4196. No mobile, responsive or WebView project ran. Product/test implementation was committed across `76bb0a4d5`, `86d9af286`, `0cd1a26c7`, `f388fbd81` and the final Universal Tasks/reviewer fix `0b54cce572`.

---

### Task 16: Full verification, fresh audit, and completion ledger

**Files:**

- Create: `docs/audits/control-room-incidents-hs-completion-audit-2026-07-12.md`
- Modify: `docs/superpowers/plans/2026-07-12-control-room-incident-hs-unification.md`

- [x] **Step 1: Run focused PHP suites**

```powershell
php artisan test tests/Feature/ControlRoom tests/Feature/HealthSafety tests/Feature/IncidentControllerTest.php tests/Feature/Incidents tests/Unit/ControlRoom
```

- [x] **Step 2: Run frontend unit, type, formatting, and builds**

```powershell
npm test
npm run types
npx prettier --check resources/ tests/e2e/
npm run build
npx vite build --ssr
```

- [x] **Step 3: Run the five desktop scenarios fresh**

Use a clean deterministic fixture run on port 4196 and the `chromium-desktop` project only. Record the exact commit and URL in the audit.

- [x] **Step 4: Run role/site manual browser checks**

Verify coordinator, support worker, incident reviewer, H&S owner, and H&S verifier. Confirm no out-of-scope row/picker/action, no dead link/403 advertised action, and H&S acceptance visible from all three modules.

- [x] **Step 5: Re-audit R1–R21 requirement by requirement**

For every ledger row, cite a current test result, route/payload, database invariant, screenshot, or manual browser observation. Mark missing/indirect evidence as open and return to the owning task; do not mark completion from absence of findings.

- [x] **Step 6: Re-audit the original ten findings and dashboard target**

Confirm permission/site isolation, dashboard first viewport, metrics/lifecycle, active sorting, shared visual grammar, bidirectional handover, truthful reporting, one WorkSafe source, closure/shift acceptance, and automated coverage. Record any remaining P0/P1 and fix it before proceeding.

- [x] **Step 7: Run reconciliation dry-run and inspect git state**

```powershell
php artisan incidents:reconcile-journeys
git diff --check
git status --short
git log --oneline --decorate -20
```

- [x] **Step 8: Complete the plan and audit documents**

Check every task box only when its evidence exists. Add exact test counts, build exits, browser routes, screenshots, invariant files, remaining low-priority boundaries, and deployment boundary to the completion audit.

- [x] **Step 9: Commit final evidence**

```powershell
git add docs/superpowers/plans/2026-07-12-control-room-incident-hs-unification.md docs/audits/control-room-incidents-hs-completion-audit-2026-07-12.md
git commit -m "docs(control-room): record unified journey completion audit"
```

**Task 16 completion evidence (2026-07-15):**

- The relevant backend run passed 1,351 tests / 9,454 assertions, and the final Universal Tasks/reviewer regression pack passed 6 tests / 53 assertions. The frontend run passed 69 files / 298 tests; TypeScript, scoped branch Prettier, scoped Pint and `git diff --check` passed. Client and SSR production builds both exited 0.
- The final combined production-built browser run on `http://127.0.0.1:4196` passed 37/37 tests in 5.7 minutes using only `chromium-desktop`. A focused dashboard evidence rerun passed 1/1 with zero serious/critical Axe findings and wrote the audited 1440 × 1000 first-viewport capture.
- The completion audit maps every R1–R21 requirement and each original audit gap to current code, route, test, browser or invariant evidence. The re-audit found and fixed one P1 in `ShiftTaskProvider` (`Shift::user` was not a valid relationship and could break the shared Tasks badge/reviewer login); commit `0b54cce572` uses `Shift::staff`, and no P0/P1 remains open in scope.
- The reconciliation command was run read-only against the shared test database: 85 incidents scanned, 255 legacy/demo issues reported, 0 repairs. Apply remains a deployment action requiring target backup and explicit review.
- The Playwright runner and PHP preview closed normally; port 4196 had no listener afterward. No mobile/WebView work, merge, push or deployment was performed.
- The final product fix is committed at `0b54cce572`; the completion audit, current screenshots/invariants and dashboard evidence are committed at `34f2a74fea`.

---

## Final release gate

Do not declare the feature complete until all of the following are proven on the final commit:

- R1–R21 are evidenced in the completion audit.
- No open P0/P1 remains in the fresh code/UI/workflow audit.
- All five desktop incident journeys pass and land in H&S with correct acceptance and record invariants.
- Incidents and H&S show the same WorkSafe state/count.
- Every site-bound role is denied cross-site read and write paths.
- Dashboard first viewport shows the next operational action, not historical analytics.
- Client build and SSR build both exit 0.
- No tracked work outside the isolated branch was changed.

---

## Crash-containment checkpoint — 2026-07-12

This work is intentionally paused at a safe boundary following the Fleet task's crash-containment request. No merge, push, deploy, reset, discard, archive, UI implementation, schema work, or additional audit section was started during containment.

### Exact branch state

- Worktree: `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-control-room-incident-hs-unification`
- Branch: `codex/control-room-incident-hs-unification`
- Current product HEAD before this checkpoint commit: `911f0648829522a090c65dcb239c8b8930024c80`
- Planning baseline: `0dff98b7`
- Product worktree was clean before this checkpoint was added.

### Scope completed so far

- Design specification and the 16-task implementation plan are committed.
- Phase A is implemented: Control Room and H&S list/detail/mutation site visibility, safeguarding need-to-know filtering, scoped create/mutation paths, and explicit `healthSafety.viewAllSites` permission handling.
- Phase B1 is implemented: parent-alert authorization for task, evidence, discussion, watcher, and time-entry routes; cross-alert relationship checks; task hierarchy/cycle validation; atomic reorder validation; and site-scoped people pickers.
- Phase B2 implementation is present at `911f0648`: messaging list/thread/send/read paths are scoped to visible alerts and site-visible direct targets, and the five nested-record controllers use a shared authorization concern.
- No dashboard modernization, shared visual-system implementation, incident/H&S journey schema, workflow orchestration, acceptance flow, reconciliation, or five-scenario desktop E2E implementation has started.

### Honest verification boundary

- Baseline targeted suites before product changes: 279 tests passed, 1,251 assertions.
- Phase A focused proof: 20 tests passed, 165 assertions; its specification and quality reviews were approved.
- Phase B1 combined proof after hardening: 88 tests passed, 229 assertions; its specification and quality reviews were approved.
- The Phase B2 implementer reported 56 tests passed, 195 assertions across the required combined suites, plus PHP lint, Pint, and diff checks.
- Phase B2's independent specification reviewer was interrupted for containment before returning a verdict. A quality review and a fresh root-owned verification run are still required; therefore Phase B2 is implemented but not review-approved.
- No browser E2E scenario, dashboard visual verification, client build, SSR build, or final R1–R20 re-audit has been run on this branch.

### Browser and preview containment

- The in-app browser had zero task-session tabs and zero open tabs matching `127.0.0.1:8768` or `localhost:8768`; no browser tab was closed.
- The task-owned orphaned local preview chain (`pwsh` PID 36860, `cmd` PID 36668, `php` PID 37228) was stopped.
- Port `127.0.0.1:8768` was confirmed not listening after shutdown.
- The active Phase B2 specification reviewer was interrupted. No implementation or review subagent remains intentionally active for this audit.

### Files changed from `0dff98b7` through `911f0648`

- Added: `app/Http/Controllers/ControlRoom/Concerns/AuthorizesControlRoomAlertAccess.php`
- Modified: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`
- Modified: `app/Http/Controllers/ControlRoom/ControlRoomDiscussionController.php`
- Modified: `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php`
- Modified: `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php`
- Modified: `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php`
- Modified: `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php`
- Modified: `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php`
- Modified: `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php`
- Modified: `app/Http/Controllers/HealthSafety/HsEventController.php`
- Modified: `app/Services/UserSiteAccessService.php`
- Modified: `database/seeders/RbacSeeder.php`
- Added: `docs/superpowers/plans/2026-07-12-control-room-incident-hs-unification.md`
- Added: `docs/superpowers/specs/2026-07-12-control-room-incident-hs-unified-journey-design.md`
- Added: `tests/Feature/ControlRoom/ControlRoomJourneyAuthorizationTest.php`
- Added: `tests/Feature/ControlRoom/ControlRoomMessagingAuthorizationTest.php`
- Added: `tests/Feature/ControlRoom/ControlRoomNestedRecordAuthorizationTest.php`
- Modified: `tests/Feature/ControlRoom/ControlRoomTaskControllerTest.php`
- Added: `tests/Feature/HealthSafety/HsEventSiteIsolationTest.php`
- Modified: `tests/Unit/Services/UserSiteAccessServiceTest.php`

### Next safe step

Resume with an independent specification review of `911f0648`, then a code-quality review and fresh focused authorization test run with exclusive access to the shared test database. Only after Phase B2 is approved should Task 2 schema work begin. Continue to exclude mobile testing.

---

## Crash-containment checkpoint — 2026-07-13 (Task 3 boundary)

This audit is intentionally paused at a safe committed boundary following a second Fleet crash-containment request. The active Task 3 specification reviewer was interrupted before it returned a verdict. No further implementation, fix, test, browser journey, audit section, merge, push, deployment, reset, discard, or archive action was started during containment.

### Exact branch state

- Worktree: `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-control-room-incident-hs-unification`
- Branch: `codex/control-room-incident-hs-unification`
- Product HEAD before this checkpoint commit: `b6748d2f4ebd28ef13fb3d4fec6942ee70a0326a`
- Planning baseline: `0dff98b7`
- The product worktree was clean before this checkpoint was added.

### Exact scope reached

- Task 1 is complete and independently specification- and quality-approved: Control Room and H&S site visibility, nested-record authorization, task hierarchy and reorder safety, and non-disclosing site-scoped messaging.
- Task 2 is complete and independently specification- and quality-approved: six journey/handover/SLA/recommendation migrations, corresponding model and factory contracts, legacy-default proof, and full down/up migration proof.
- Task 3 implementation exists at `b6748d2f`: one incident journey service/DTO, direct alert–incident–H&S links, transaction/lock/conflict handling, critical-severity provenance, and focused service tests.
- Task 3 is **not approved or complete**. Its independent specification review was interrupted. Root pre-review also identified two likely blockers that remain unproved and unfixed: a retry may regress a reviewed/closed incident to `submitted`, and a retry may overwrite H&S-canonical WorkSafe state with stale incident values.
- Tasks 4–16 have not started. In particular, there is no observer/sensor/medication bridge rewiring, lifecycle transition layer, explicit H&S or shift acceptance flow, dashboard modernization, shared Control Room/H&S visual system, reconciliation command, five desktop E2E journeys, client/SSR build proof, or final R1–R20 re-audit.
- Mobile remains explicitly excluded from this audit and test scope.

### Honest verification boundary

- Task 1 final focused suites and independent reviews are recorded in its completion ledger above.
- Task 2 final schema suite: 14 tests, 130 assertions; source-link, terminal-overdue, and migration down/up checks also passed as recorded above.
- Task 3 implementer evidence: 13 focused journey tests with 157 assertions, plus 18 H&S-backbone regression tests with 25 assertions; PHP lint, Pint, and diff checks were reported green.
- Task 3 has no independent specification verdict, no independent code-quality verdict, and no root-owned post-review rerun. Its implementer evidence must not be treated as release approval.
- No dashboard/browser E2E, live/demo-server audit, client build, SSR build, or final feature-completeness audit has been run for the unfinished product state.

### Files changed since the previous checkpoint

The previous checkpoint contains the exact file list through `911f0648`. From `911f0648` through product HEAD `b6748d2f`, these committed files changed:

- Modified: `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php`
- Modified: `app/Models/ClientIncident.php`
- Modified: `app/Models/ControlRoom/AlertSla.php`
- Modified: `app/Models/ControlRoom/AlertTask.php`
- Modified: `app/Models/ControlRoom/Shift.php`
- Modified: `app/Models/ControlRoomAlert.php`
- Modified: `app/Models/HsEvent.php`
- Added: `app/Models/HsRecommendationDisposition.php`
- Modified: `app/Services/HealthSafety/HsEventService.php`
- Added: `app/Services/Incidents/IncidentJourney.php`
- Added: `app/Services/Incidents/IncidentJourneyService.php`
- Modified: `database/factories/ClientIncidentFactory.php`
- Modified: `database/factories/HsEventFactory.php`
- Added: `database/factories/HsRecommendationDispositionFactory.php`
- Added: `database/migrations/2026_07_12_000100_add_incident_journey_links.php`
- Added: `database/migrations/2026_07_12_000200_add_hs_handover_acceptance.php`
- Added: `database/migrations/2026_07_12_000300_add_alert_task_transfer.php`
- Added: `database/migrations/2026_07_12_000350_add_alert_sla_cycle_history.php`
- Added: `database/migrations/2026_07_12_000400_add_control_room_shift_handover_acceptance.php`
- Added: `database/migrations/2026_07_12_000500_create_hs_recommendation_dispositions.php`
- Modified: `docs/superpowers/plans/2026-07-12-control-room-incident-hs-unification.md`
- Modified: `tests/Feature/ControlRoom/ControlRoomMessagingAuthorizationTest.php`
- Modified: `tests/Feature/ControlRoom/ControlRoomTaskControllerTest.php`
- Modified: `tests/Feature/HealthSafety/HsEventRegisterTest.php`
- Added: `tests/Feature/Incidents/IncidentJourneySchemaTest.php`
- Added: `tests/Feature/Incidents/IncidentJourneyServiceTest.php`

The cumulative baseline-to-product-HEAD diff is 42 files: 6,888 insertions and 216 deletions. The checkpoint document itself is the only containment-time file change.

### Browser and process containment

- The in-app browser tab list was empty; no tab was opened, navigated, or closed during containment.
- No task-owned `php`, `node`, or `cmd` preview process was running for this worktree, so no process was stopped.
- No PHP test runner was active.
- Common local preview ports `8000`, `8001`, `8768`, `5173`, and `4173` had no listener.
- The Task 3 specification-review subagent was interrupted. No implementation or review subagent remains intentionally active for this audit.

### Next safe step

Resume Task 3 with a fresh independent specification review of `b6748d2f`, explicitly test the lifecycle-retry and H&S-canonical WorkSafe concerns, return any findings to the Task 3 implementer under TDD, then obtain a fresh quality review and root-owned focused rerun. Do not begin Task 4 until Task 3 is approved. Continue with desktop-only scope.

---

## Crash-containment checkpoint — 2026-07-13 (Task 4 final-hardening stop)

This work is intentionally paused immediately after the one in-flight focused test completed. No fix was applied after its result, and no further test suite, audit section, browser journey, formatting pass, commit, merge, push, deployment, reset, discard, archive, or cleanup outside this task was started.

### Exact branch and working-tree state

- Worktree: `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-control-room-incident-hs-unification`
- Branch: `codex/control-room-incident-hs-unification`
- HEAD: `2dde398d53064349ca1bbd141b19a026af991d68` (`fix(incidents): harden bridge identity and races`)
- Ten product/test files are modified but uncommitted: six application files and four test files, currently 588 insertions and 104 deletions.
- This plan was already modified and remains intentionally uncommitted; the present section is the only containment-time edit.

### Exact scope reached in the resumed hardening pass

- Implemented the four findings from the fresh Task 4 specification review under focused TDD: canonical journey ownership for incident-correlated signal alerts, transactional medication-error controller integration, fail-closed handling of every non-empty incident claim, and client validation for exact direct/context alert correlation.
- Also removed a direct journey-link write from the Control Room flag-as-incident path and stopped the sensor bridge from re-writing canonical incident context after submission.
- Focused four-file verification reached 59 passing tests and 383 assertions after the planned fixes.
- A final self-review identified one additional unresolved provenance defect: a critical Control Room alert is capped to a high ClientIncident and the new generic attachment path currently creates a high H&S event instead of preserving the original critical alert severity.
- The focused regression for that defect completed RED at this stop point: 1 failed test, 4 assertions, exit code 1. Expected H&S severity `critical`; actual `high` at `tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php:233`.
- The provenance fix, Pint, PHP lint, `git diff --check`, the final focused rerun, and the exact Task 4 six-file gate have **not** been run. The uncommitted pass must not be treated as approved or complete.

### Files currently changed

- `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php`
- `app/Http/Controllers/Emar/MedicationErrorController.php`
- `app/Services/ControlRoom/SensorIncidentBridgeService.php`
- `app/Services/ControlRoom/SignalProcessingService.php`
- `app/Services/Incidents/IncidentJourneyService.php`
- `app/Services/Medication/MedicationSignalService.php`
- `tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php`
- `tests/Feature/Emar/MedicationErrorsTest.php`
- `tests/Feature/Medication/MedicationIncidentJourneyTest.php`
- `tests/Unit/ControlRoom/SignalProcessingServiceTest.php`
- `docs/superpowers/plans/2026-07-12-control-room-incident-hs-unification.md` (pre-existing checkpoint document plus this containment section)

### Browser and process containment

- This Task 4 hardening pass did not open or control a browser tab and did not start a preview server, so there was no task-owned browser or preview state to close.
- The only task-owned PHP test runner exited normally with test exit code 1; no task-owned runner remains.
- A separate Vite build was observed under `C:\Users\steph\.codex\worktrees\43ca\oblivionfindings`. It does not belong to this worktree/task and was deliberately left untouched.
- No implementation or review subagent was spawned by this hardening pass; nothing task-owned remains to interrupt.

### Next safe step

Resume at the existing RED test only. Preserve the original alert severity provenance in `IncidentJourneyService::attachAlertToIncident()` before draft/submitted H&S creation or synchronisation, make the focused controller regression green, then run Pint, PHP lint, `git diff --check`, the final focused suite, and the exact Task 4 six-file gate. Review and commit only the ten product/test files; keep this plan out of that product commit. Continue with desktop-only scope and do not begin the dashboard/UI audit section until Task 4 is independently approved.

---

## Crash-containment checkpoint — 2026-07-13 (Task 4 final-hardening GREEN stop)

This checkpoint supersedes the older Task 4 RED-stop instructions immediately above. Work is intentionally paused after the current combined focused runner exited successfully. No further self-review, formatter, lint, diff check, expanded gate, commit, merge, push, deployment, browser journey, or new audit section was started after that result.

### Exact branch and verification state

- Worktree: `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-control-room-incident-hs-unification`
- Branch: `codex/control-room-incident-hs-unification`
- HEAD: `cd65b87f856057caf3b0ad1cecde699d12f86ef6`
- Initial combined RED: 12 failed, 90 passed, 725 assertions, exit code 1, 202.81 seconds.
- Current combined GREEN: 102 passed, 797 assertions, exit code 0, 202.77 seconds.
- The GREEN gate covered the Control Room incident request path, trusted-signal promotion, medication-error delivery/linking, medication incident identity, canonical incident journey operations/locking, sensor retry contracts, and governance escalation locking.
- Pint, final PHP lint, `git diff --check`, the exact expanded Task 4 gate, and a product commit have **not** been run on this state. It is a strong focused checkpoint, not final Task 4 approval.

### Exact scope currently changed

Nine application files and seven test files are uncommitted; the plan remains separately dirty and must stay out of the product commit:

- `app/Domain/Governance/Services/IncidentEscalationService.php`
- `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php`
- `app/Http/Controllers/Emar/MedicationErrorController.php`
- `app/Services/ControlRoom/ComprehensiveAlertBridgeService.php`
- `app/Services/ControlRoom/IncidentAlertOperationalInitializer.php` (new, untracked)
- `app/Services/ControlRoom/SensorIncidentBridgeService.php`
- `app/Services/ControlRoom/SignalProcessingService.php`
- `app/Services/Incidents/IncidentJourneyService.php`
- `app/Services/Medication/MedicationSignalService.php`
- `tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php`
- `tests/Feature/ControlRoom/SensorIncidentJourneyTest.php`
- `tests/Feature/Emar/MedicationErrorsTest.php`
- `tests/Feature/Governance/GovernanceCrossModuleEscalationTest.php`
- `tests/Feature/Incidents/IncidentJourneyServiceTest.php`
- `tests/Feature/Medication/MedicationIncidentJourneyTest.php`
- `tests/Unit/ControlRoom/SignalProcessingServiceTest.php`
- `docs/superpowers/plans/2026-07-12-control-room-incident-hs-unification.md` (pre-existing checkpoints plus this containment update)

The implemented hardening covers monotonic requested-severity provenance; critical trusted-signal promotion through alert, incident provenance, and H&S; fail-closed major/critical medication delivery; idempotent medication-error linking and rollback; stable durable medication event identity including PRN attempts; operational queue/SLA/automation/notification initialization for newly created incident alerts; consistent alert-before-incident lock order with outer retry contracts; and governance escalation serialization on the incident lock. Adjacent stale bridge writes and unlocked correlated-alert updates were also removed or locked within this Task 4 boundary.

### Browser and process containment

- The containment browser session had zero managed tabs and found zero open Oblivion Findings, localhost, or loopback preview tabs. Nothing was closed because this task owned no browser tab.
- No `php`, `node`, `npm`, `npx`, Vite, Playwright, Chrome, or Edge process matched this worktree or a task preview/test command. Nothing was stopped.
- The combined PHP test runner exited normally; no task-owned command session remains running.

### Next safe step

Resume from this exact 102-passing/797-assertion GREEN state. Self-review the eight recorded Task 4 quality concerns, run Pint only on the sixteen changed product/test PHP files, run PHP lint and `git diff --check`, then run the exact expanded Task 4 gate once on the final formatted code plus only any narrow formatter regression that is genuinely needed. Commit only the sixteen product/test files and exclude this plan. Do not begin Task 5, dashboard/UI, mobile, WebView, merge, push, or deployment work.
