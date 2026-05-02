# Rostering / Portal / Respite — Production Readiness Plan

**Status:** Planning — implementation not started
**Date:** 2026-05-02
**Scope:** Portal (client/family + operations admin) + Respite + the Respite ↔ Shift / Client-profile integration

GPT-5.5 flagged this surface as: *"Rostering – Portal/Respite: Partial. Separate operational surfaces; likely usable but not fully unified with shifts. Also respite needs to be selected for a client before it shows in the client profile tab."*

This plan inspects the live repo (no `.claude/worktrees`) and proposes the smallest set of targeted, PR-sized changes to make the surface production-ready. It does not propose a rewrite — the existing architecture is mostly intact and the gaps are concrete and bounded.

---

## 1. Current State Map

### 1.1 Portal surface (two parallel surfaces, both wired)

#### a) Client / family portal — [routes/portal.php](routes/portal.php)
- Outside-auth SSO: `portal/login`, `portal/auth/{microsoft,google}/{redirect,callback}` via `App\Http\Controllers\Auth\PortalOAuthController`.
- Auth-guarded entry: `/portal` ([PortalController::index](app/Http/Controllers/PortalController.php))
- Per-client surface mounted at `/portal/clients/{client}/...`:
  - `PortalClientController::show` – overview ([PortalClientController.php](app/Http/Controllers/PortalClientController.php))
  - `Portal\FamilyDashboardController` – richer dashboard with today/week/month shifts ([FamilyDashboardController.php](app/Http/Controllers/Portal/FamilyDashboardController.php))
  - `Portal\PortalScheduleController`, `PortalCalendarController` (events JSON feed)
  - `Portal\PortalTimelineController`, `PortalTimelineInteractionController`
  - `Portal\PortalHealthController`, `PortalDocumentController`, `PortalPhotoController`
  - `Portal\PortalMessageController` (conversations, send, react, pin, search, archive)
  - `Portal\PortalLocationController` (asset tracking with consent)
  - `Portal\PortalFamilyNoteController` (CRUD + staff respond/assign-shift via `FamilyNoteController`)
  - `Portal\ConsentRequestPortalController` (approve/decline)
  - `Portal\PortalNotificationController`, `PortalPreferenceController`
  - Visit requests via `FamilyDashboardController::storeVisitRequest`/`cancelVisitRequest`
- Authorisation gate: `User::canAccessClientPortal($client)` checks `portalClients()` pivot membership ([User.php:187](app/Models/User.php:187)).
- Frontend: [resources/js/pages/portal/](resources/js/pages/portal/) – `client.tsx`, `family-dashboard.tsx`, `schedule.tsx`, `calendar.tsx`, `timeline.tsx`, `health.tsx`, `documents.tsx`, `photos.tsx`, `family-notes.tsx`, `messages.tsx`, `messages/`, `notifications.tsx`, `location.tsx`, `preferences.tsx`, `login.tsx`, `consent-requests/`, `index.tsx`.
- Tests: [tests/Feature/Portal/PortalSurfaceTest.php](tests/Feature/Portal/PortalSurfaceTest.php) — 3 feature tests (calendar tz, messages picker, location consent), [tests/Feature/ClientPortalUserControllerTest.php](tests/Feature/ClientPortalUserControllerTest.php).

#### b) Operations admin — Family Portal Settings — [routes/operations.php:940-945](routes/operations.php)
- Mounted under `/operations/family-portal/...` (Phase 9).
- Controller: [Operations\FamilyPortalController](app/Http/Controllers/Operations/FamilyPortalController.php) — index/show/edit/update on `FamilyPortalSetting`.
- Model: [FamilyPortalSetting](app/Models/FamilyPortalSetting.php) (one-to-one with Client; flags: `show_shift_schedule`, `show_care_notes`, `show_care_plans`, `show_medication_status`, `show_incidents`, `notify_shift_arrival`, `notify_shift_completion`, `notify_incident`).
- Migration: [2026_03_23_005400_create_family_portal_settings_table.php](database/migrations/2026_03_23_005400_create_family_portal_settings_table.php).
- Frontend: [resources/js/pages/operations/family-portal/](resources/js/pages/operations/family-portal/) — `Index.tsx`, `Show.tsx`, `Edit.tsx`.
- Demo seeder: [FamilyPortalDemoSeeder.php](database/seeders/FamilyPortalDemoSeeder.php).
- Per-client portal-user link controller: [ClientPortalUserController](app/Http/Controllers/ClientPortalUserController.php) under `routes/operations.php:244-250`.

### 1.2 Respite surface — [routes/respite.php](routes/respite.php)

Counts (from a quick grep of the route file):
- ~50 routes across referrals, requests, bookings, stays, resources, calendar, procedures, procedure runs, tasks, handover notes, communication logs, evidence packs, daily notes, risk-plan activations.

Controllers — [app/Http/Controllers/Respite/](app/Http/Controllers/Respite/) (14 files):
- `RespiteReferralController`, `RespiteBookingRequestController`, `RespiteBookingController`, `RespiteStayController`
- `RespiteResourceAllocationController`, `RespiteCalendarController`
- `RespiteProcedureTemplateController`, `RespiteProcedureRunController`, `RespiteTaskController`
- `RespiteHandoverNoteController`, `RespiteCommunicationLogController`, `RespiteEvidencePackController`
- `RespiteDailyNoteController`, `RespiteRiskPlanActivationController`

Models — `app/Models/Respite*.php` (15 files):
- `RespiteReferral`, `RespiteBookingRequest`, `RespiteBooking`, `RespiteStay`, `RespiteResourceAllocation`
- `RespiteCalendarEvent`, `RespiteHandoverNote`, `RespiteCommunicationLog`, `RespiteLinkedRef`
- `RespiteEvidencePack`, `RespiteProcedureRun`, `RespiteTask`, `RespiteAuditLog`
- `RespiteDailyNote`, `RespiteRiskPlanActivation`

Migrations:
- [2026_01_29_000600_create_respite_tables.php](database/migrations/2026_01_29_000600_create_respite_tables.php) — 11 tables created in one file.
- [2026_01_29_000700_add_respite_booking_id_to_shifts_table.php](database/migrations/2026_01_29_000700_add_respite_booking_id_to_shifts_table.php) — Shift ↔ Booking link.
- [2026_01_30_000001_add_respite_procedure_execution_tables.php](database/migrations/2026_01_30_000001_add_respite_procedure_execution_tables.php) — procedure runs, tasks, audit, daily notes, risk plan activations.
- [2026_03_25_100000_fix_respite_service_type.php](database/migrations/2026_03_25_100000_fix_respite_service_type.php) — `respite` → `planned_respite` rename.
- [2026_03_25_200100_add_respite_fields_to_sites.php](database/migrations/2026_03_25_200100_add_respite_fields_to_sites.php) — `offers_respite`, `respite_capacity`, contact, funding types, min/max stay.

Frontend pages — [resources/js/pages/respite/](resources/js/pages/respite/): `index.tsx`, `referrals/`, `requests/`, `bookings/`, `stays/`, `resources/`, `calendar/`, `procedures/`, `procedure-runs/`, `tasks/`, `handover-notes/`, `communication-logs/`, `evidence-packs/`, `daily-notes/`, `risk-plan-activations/`.

Service layer:
- [app/Services/Respite/RespiteCalendarProjector.php](app/Services/Respite/RespiteCalendarProjector.php) — only file in the namespace.

Events / Listeners:
- [app/Events/Respite/RespiteEvent.php](app/Events/Respite/RespiteEvent.php) — generic event dispatched from ~12 places across respite controllers.
- **No listeners** in `app/Listeners/` for `RespiteEvent`. Events are emitted but nothing reacts.

Factories — `database/factories/Respite*.php` (3 files only):
- `RespiteBookingFactory`, `RespiteDailyNoteFactory`, `RespiteTaskFactory`.
- **Missing**: factories for `RespiteReferral`, `RespiteBookingRequest`, `RespiteStay`, `RespiteResourceAllocation`, `RespiteHandoverNote`, `RespiteCommunicationLog`, `RespiteEvidencePack`, `RespiteCalendarEvent`, `RespiteProcedureRun`, `RespiteRiskPlanActivation`, `RespiteAuditLog`, `RespiteLinkedRef`, `RespiteProcedureTemplate`.

Tests:
- [tests/Browser/Respite/RespiteTest.php](tests/Browser/Respite/RespiteTest.php) — ~28 smoke tests, all `loginAs(admin@test.com)` + visit + assertSee. No domain assertions, no role-permission coverage, no state transitions tested.
- **No** PHPUnit Feature/Unit tests for any respite controller, model, or service (`grep -r Respite tests/Feature/` returns only `ClientControllerTest.php`, `Finance/...`, `SettingsControllerTest.php` — incidental references).

### 1.3 Rostering / Shifts ↔ Respite ↔ Client-profile integration

- `Shift` model has `respite_booking_id` (FK to `respite_bookings`, `nullOnDelete`) and a `respiteBooking()` BelongsTo ([Shift.php:24](app/Models/Shift.php#L24), [Shift.php:153](app/Models/Shift.php#L153)).
- Booking-side relation: `RespiteBooking::shift()` returns `hasOne(Shift::class, 'respite_booking_id')` ([RespiteBooking.php:60](app/Models/RespiteBooking.php#L60)).
- Auto-creation of the linked shift:
  - [RespiteBookingController::store](app/Http/Controllers/Respite/RespiteBookingController.php#L48) — uses `Shift::firstOrCreate` keyed on `respite_booking_id`, copying client/start/end/service_context/status=`scheduled`, `user_id=null`.
  - [RespiteBookingRequestController::approve](app/Http/Controllers/Respite/RespiteBookingRequestController.php#L122) — same `firstOrCreate` after approving the request and creating the booking.
- Cascade updates on booking edits:
  - [RespiteBookingController::update](app/Http/Controllers/Respite/RespiteBookingController.php#L108) — propagates `client_id`, `starts_at`, `ends_at` to the linked shift only when the shift is not completed.
- Calendars that surface shifts (and therefore respite-derived shifts):
  - [ClientCalendarController::events](app/Http/Controllers/ClientCalendarController.php#L18) – staff-facing client calendar.
  - [CalendarController::events](app/Http/Controllers/CalendarController.php#L68) – global staff calendar.
  - [Portal\PortalCalendarController::events](app/Http/Controllers/Portal/PortalCalendarController.php#L49) – family portal calendar (gated by `FamilyPortalSetting::show_shift_schedule`, default `true`).
- Client profile show — [resources/js/pages/operations/clients/show.tsx](resources/js/pages/operations/clients/show.tsx):
  - Respite block returned by [ClientController::show](app/Http/Controllers/ClientController.php#L550) → `respite.bookings`, `respite.requests`.
  - Tab visibility (line 664-668): `show: Boolean(respiteCan?.viewAny)` — gated **only** by `auth.can.respite.viewAny`, **not** by whether the client has any respite data.
  - Tab content (line 7067-7200) — links to create booking request, lists bookings + requests, deep-links to `/operations/shifts/{shift_id}` when present.
- Client list — [resources/js/pages/operations/clients/index.tsx:49-53](resources/js/pages/operations/clients/index.tsx) — has a `respiteFilter` ('all' / 'yes' / 'no') driven by a server-side `has_respite` flag from [ClientController::index](app/Http/Controllers/ClientController.php#L107). This filter is **likely** the source of GPT-5.5's "needs to be selected for a client" comment.
- No integration with rostering primitives:
  - `RosterPeriod`, `RosterTemplate`, `RosterSuggestion`, `RosterTemplateShift` — none reference `respite_booking_id`. Respite-derived shifts do not participate in roster generation or publishing.
  - [Shift::booted](app/Models/Shift.php#L61) calls `RosterPublishingService::markDirtyFromShift*` for create/update/delete, so the auto-created shift will at least mark the parent roster period dirty — that side is wired.

### 1.4 RBAC

Routes use these granular permissions:

`routes/portal.php`:
- `permission:calendar.viewAny`, `shifts.create`, `shifts.update` (within `/calendar/*` group)
- `permission:summaries.generate` (`/summaries/generate`)
- `throttle:ai-queries` for RAG endpoints

`routes/operations.php` (family-portal admin block, lines 940-944):
- `permission:clients.update`

`routes/respite.php` (top-down):
- `respite.viewAny`, `respite.create`, `respite.update`
- `respite.bookings.manage`, `respite.stays.manage`, `respite.resources.manage`
- `respite.calendar.view`, `respite.evidence.view`
- `respite.procedures.manage`, `respite.procedures.run`
- `respite.tasks.view|manage|approve`
- `respite.handovers.view|manage`
- `respite.communications.view|manage`
- `respite.evidence.manage|seal`
- `respite.daily-notes.view|manage`, `respite.risk-plans.view|manage`
- OR clauses also reference `respite.daily` and `respite.risk` (legacy aliases used in `permission:foo|bar`)

Permissions actually defined in [RbacSeeder.php:282-290](database/seeders/RbacSeeder.php):
- `respite.viewAny`, `respite.create`, `respite.update`
- `respite.bookings.manage`, `respite.stays.manage`, `respite.resources.manage`
- `respite.procedures.manage`, `respite.calendar.view`, `respite.evidence.view`

Permissions defined in [OperationsPermissionsSeeder.php:143-144](database/seeders/OperationsPermissionsSeeder.php):
- `family_portal.viewAny`, `family_portal.manage` — defined but **never assigned to any role** in any seeder.

---

## 2. Why This Was Marked Partial

GPT-5.5 said *"Separate operational surfaces; likely usable but not fully unified with shifts. Also respite needs to be selected for a client before it shows in the client profile tab."* The repo evidence supports both halves:

### 2.1 Separate but only loosely unified with shifts

| Concern | Evidence | Risk |
|---|---|---|
| Booking → Shift cascade is one-way and partial | [RespiteBookingController::update](app/Http/Controllers/Respite/RespiteBookingController.php#L108) cascades start/end/client only when shift not completed; cancellation does not cascade; no Stay → Shift cascade at all | Risky — published rosters may show ghost shifts after a booking is cancelled |
| Stay state never reflected on the parent Shift | [RespiteStayController::checkIn/extend/discharge](app/Http/Controllers/Respite/RespiteStayController.php) updates only the stay, not the shift's `actual_starts_at`/`actual_ends_at`/`status` | Risky — timesheet/payroll views will not see the stay's actuals |
| `RespiteEvent` has no listeners | [app/Events/Respite/RespiteEvent.php](app/Events/Respite/RespiteEvent.php) dispatched from 12+ sites; `app/Listeners/` has no `Respite/` directory | Acceptable for now (forward-compatible), but timeline events / notifications / coverage alerts that the rest of the app relies on are not emitted from respite state changes |
| Calendar treats respite shifts as plain shifts | All three calendar controllers render `Shift` rows generically; no badge or differentiation when `respite_booking_id` is set | Acceptable but unhelpful — users must click to see the respite linkage |
| Family/NOK portal has zero respite awareness | `grep respite app/Http/Controllers/Portal/` returns nothing; [PortalScheduleController](app/Http/Controllers/Portal/PortalScheduleController.php) only queries `Shift`, [FamilyDashboardController](app/Http/Controllers/Portal/FamilyDashboardController.php) likewise | Risky — families can't see "scheduled respite stay" semantics, only the underlying shift |
| Respite shifts unassigned by default | Both auto-creators set `user_id => null`; no follow-up flow forces an assignment before the booking is `confirmed` | Risky — confirmed bookings can sit with unassigned shifts indefinitely |
| RosterSuggestion / coverage gap services do not see respite | `grep respite app/Domain/Rostering/` returns nothing; respite-shifts must be manually filled | Acceptable for now; flag for future hardening |
| Shift detail UI hides the respite linkage | `ShiftController` does not surface `respite_booking_id`, so no link from shift back to booking | Cosmetic but hurts ops trust |

### 2.2 "Respite needs to be selected for a client" — a real but partly-misread observation

- Tab visibility on `operations/clients/show.tsx` is gated **only** by `auth.can.respite.viewAny` (line 664-668). It is **not** gated on whether the client has any respite booking/request.
- The list page filter (`operations/clients/index.tsx:49-53`) is gated on `has_respite`, computed from `respite_bookings_count + respite_booking_requests_count > 0`. Filtering the list to `has_respite = yes` is what makes the tab "appear" only after a respite item exists, not the tab logic itself.
- There is **no** client-level "respite enabled" flag on the `clients` table. The closest is `clients.service_context_id` referencing `service_contexts.type` enum which can be `planned_respite`/`emergency_respite`/`community_respite` ([ServiceType enum](app/Enums/ServiceType.php#L29-L32)) — but nothing in the show page consults it.
- Conclusion: the comment is real (the UX is unclear and inconsistent across pages) but it is a UX/discoverability gap, not a missing feature.

### 2.3 RBAC misalignment (P0)

- `routes/respite.php` references many granular permissions (`respite.tasks.view`, `respite.handovers.view`, `respite.communications.view`, `respite.daily-notes.view`, `respite.risk-plans.view`, `respite.evidence.manage`, `respite.evidence.seal`, `respite.procedures.run`, plus aliases `respite.daily` and `respite.risk`).
- These keys are **only** seeded inside [DuskDatabaseSeeder.php:131-138](database/seeders/DuskDatabaseSeeder.php) and **not** in [RbacSeeder.php](database/seeders/RbacSeeder.php) — so production tenants will not have those keys defined and only superadmin (which globally bypasses) can hit those routes.
- `family_portal.viewAny` and `family_portal.manage` are defined in `OperationsPermissionsSeeder.php` but never attached to any role — orphaned.

---

## 3. Production-Readiness Gaps

### P0 — Blockers (must fix before production)

1. **Granular respite permissions are not seeded for production** — block ~30 routes in `routes/respite.php` for non-admin roles.
   - Files: [database/seeders/RbacSeeder.php](database/seeders/RbacSeeder.php) (extend `respite.*` permission list and role assignments).
2. **Respite shift state never reflects stay state** — discharged or cancelled stays leave shifts in `scheduled`/`in_progress` with stale `ends_at`. Affects timesheet/payroll views.
   - Files: [RespiteStayController](app/Http/Controllers/Respite/RespiteStayController.php), [RespiteBookingController::update](app/Http/Controllers/Respite/RespiteBookingController.php) (already partial).
3. **Booking cancellation does not cancel its linked shift** — same root cause; results in ghost shifts on the calendar/roster.
   - File: [RespiteBookingController::update](app/Http/Controllers/Respite/RespiteBookingController.php#L108) — extend the cascade to handle `status === 'cancelled'`.
4. **Authorization gap on respite write endpoints** — controllers rely solely on the route middleware permission keys; no per-client `$this->authorize('view', $client)` checks. A user with `respite.bookings.manage` could create a booking against any client in any organization scope.
   - Files: all `RespiteBookingController`, `RespiteStayController`, `RespiteBookingRequestController`, `RespiteReferralController`. Add `$this->authorize('view', $client)` where a client is touched, and a tenant-scope guard where a model is loaded.

### P1 — Important gaps (operational flow / user trust)

5. **Family portal does not show respite stays/bookings** — families can see the underlying shift but not the respite framing (e.g., "Planned respite stay 12 May → 18 May at Site X").
   - Files: [PortalCalendarController::events](app/Http/Controllers/Portal/PortalCalendarController.php), [PortalScheduleController::index](app/Http/Controllers/Portal/PortalScheduleController.php), [FamilyDashboardController::show](app/Http/Controllers/Portal/FamilyDashboardController.php).
6. **Client-profile respite tab visibility rule is unclear** — should follow a documented rule, e.g., "show whenever the user has `respite.viewAny`; show an empty state with a 'Start a respite request' CTA when the client has no data yet, regardless of `service_context`." Current code already shows an empty state, but the gate has never been documented.
   - Files: [resources/js/pages/operations/clients/show.tsx](resources/js/pages/operations/clients/show.tsx#L664-L668), [docs/route-ownership.md](docs/route-ownership.md) (or add a short note in this plan's Acceptance Criteria).
7. **Calendar event differentiation** — operations and portal calendars don't badge respite-derived shifts. Trivial badge addition.
   - Files: [ClientCalendarController](app/Http/Controllers/ClientCalendarController.php), [PortalCalendarController](app/Http/Controllers/Portal/PortalCalendarController.php), corresponding event renderers in calendar pages.
8. **Shift detail page does not surface the respite linkage** — `ShiftController::show` does not include `respiteBooking`. No deep-link from shift back to `/respite/bookings/{id}`.
   - Files: [ShiftController](app/Http/Controllers/ShiftController.php) and `resources/js/pages/operations/shifts/show.tsx`.
9. **Family Portal admin permissions orphaned** — `family_portal.viewAny|manage` defined but unused. Either wire them to the routes (replace `permission:clients.update`) and assign to coordinator/manager roles, or remove them. Recommend wire-and-assign for least surprise.
   - Files: [OperationsPermissionsSeeder.php](database/seeders/OperationsPermissionsSeeder.php), [RbacSeeder.php](database/seeders/RbacSeeder.php), [routes/operations.php:940-944](routes/operations.php).
10. **No per-controller PHPUnit tests for respite write paths** — only Dusk smoke tests for index pages. Need feature tests for the booking → shift cascade, request → booking → shift cascade, stay check-in/discharge, cancellation cascade.
    - Files: new `tests/Feature/Respite/RespiteBookingShiftCascadeTest.php`, `tests/Feature/Respite/RespiteStayLifecycleTest.php`, `tests/Feature/Respite/RespiteBookingRequestApprovalTest.php`.

### P2 — Cleanup / polish (post-launch acceptable)

11. `RespiteCalendarController::index` does runtime backfill ([RespiteCalendarController.php:18-28](app/Http/Controllers/Respite/RespiteCalendarController.php)) — should be moved to a one-shot artisan command or a queued backfill.
12. Missing factories: only 3 of 15 respite models have factories; tests can't easily build state.
13. `RespiteEvent` has no listeners — keep dispatching for future, but document expected subscribers (timeline, notifications) in `docs/route-ownership.md` or this plan.
14. The Dusk smoke tests in `tests/Browser/Respite/RespiteTest.php` all log in as `admin@test.com` and just `assertSee` a single word — they would not catch most regressions. Add at least one happy-path Dusk test that creates a referral → request → booking → confirmed → stay → discharge.
15. Calendar projector only emits `booking_confirmed` events; `booking_cancelled`, `stay_started`, `stay_discharged` would let portal/operations calendars show lifecycle state.

---

## 4. Minimal Implementation Plan

Sequenced for one or two PRs of work. Each block is independently mergeable.

### PR 1 — RBAC + authorization hardening (P0 #1, #4; P1 #9)

Files touched:
- `database/seeders/RbacSeeder.php` — add the missing permission rows for: `respite.tasks.view|manage|approve`, `respite.handovers.view|manage`, `respite.communications.view|manage`, `respite.daily-notes.view|manage`, `respite.risk-plans.view|manage`, `respite.evidence.manage`, `respite.evidence.seal`, `respite.procedures.run`. Decide whether `respite.daily` / `respite.risk` aliases stay (recommend remove and update routes to use canonical keys).
- `database/seeders/RbacSeeder.php` — assign the new permissions to coordinator / manager / superadmin (line ~520-530, ~561-573 already grants the existing ones). Support worker keeps only `respite.viewAny` plus `respite.daily-notes.manage` if SW does daily notes (verify business rule with stakeholder).
- `routes/respite.php` — if the alias keys are dropped, replace `permission:respite.daily-notes.manage|respite.daily` with `permission:respite.daily-notes.manage` (and same for `respite.risk`).
- `routes/operations.php:940-944` — change `permission:clients.update` to `permission:family_portal.viewAny` for GET routes and `permission:family_portal.manage` for PUT, leaving `clients.update` as an OR fallback if desired (use `family_portal.viewAny|clients.update` in middleware to stay backwards-compat).
- `database/seeders/RbacSeeder.php` — assign `family_portal.viewAny` to roles that currently have `clients.update`; assign `family_portal.manage` to coordinator/manager/admin.
- `app/Http/Controllers/Respite/*Controller.php` — at the top of every action that takes a `Client` or a model that scopes to a client, call `$this->authorize('view', $client)` (after resolving the client from the request body or model). Use the existing `ClientPolicy::view` which already enforces tenant scope.

No new migrations.

### PR 2 — Respite ↔ Shift state cascade (P0 #2, #3; P1 #7, #8)

Files touched:
- `app/Http/Controllers/Respite/RespiteBookingController.php`
  - `update()` — when `status` transitions to `cancelled`, also cancel the linked shift (`status='cancelled'`, copy `cancellation_reason` to shift `notes`) unless the shift is `completed`.
- `app/Http/Controllers/Respite/RespiteStayController.php`
  - `checkIn()` — set the linked shift's `actual_starts_at = now()` and `status='in_progress'` if it isn't already past it.
  - `extend()` — propagate `new_end` to the booking's `end_at` and the shift's `ends_at`. Validation should be `after:actual_start` not `after:now`.
  - `discharge()` — set linked shift's `actual_ends_at = now()`, `status='completed'`, mirror discharge_summary into shift `notes` (append).
- New helper (small): `App\Services\Respite\RespiteShiftSync` to encapsulate the four cascade flows so each controller just calls one method. Keeps controllers thin and lets us unit-test the sync logic independently.
- `resources/js/pages/operations/shifts/show.tsx` — if `shift.respite_booking_id` is present, render a "Linked respite booking" card with link to `/respite/bookings/{id}`.
- `app/Http/Controllers/ShiftController.php` (`show` action) — eager-load `respiteBooking:id,start_at,end_at,status` and pass it through to the page props.
- `app/Http/Controllers/CalendarController.php` and `app/Http/Controllers/ClientCalendarController.php` and `app/Http/Controllers/Portal/PortalCalendarController.php` — when serializing shifts, include `is_respite => (bool) $shift->respite_booking_id` and a different `backgroundColor` (e.g., `#a855f7` purple) so the FullCalendar UI distinguishes them.

No new migrations.

### PR 3 — Family portal respite visibility (P1 #5, #6; P2 #15)

Files touched:
- `app/Http/Controllers/Portal/PortalScheduleController.php` — add a `respiteStays` block: the `RespiteBooking::where('client_id', $client->id)->whereBetween('start_at', [$rangeStart, $rangeEnd])` (with stay status). Gate by a new `FamilyPortalSetting::show_respite` flag (default `true`).
- `app/Http/Controllers/Portal/PortalCalendarController.php` — same: include respite stays as distinct calendar events (`type: 'respite_stay'`) instead of relying on the underlying shift.
- `app/Http/Controllers/Portal/FamilyDashboardController.php` — add an "Upcoming respite" card (next 30 days) summarising stays.
- `app/Models/FamilyPortalSetting.php` + new migration `add_show_respite_to_family_portal_settings_table.php` — add `show_respite` boolean column (default `true`).
- `app/Http/Controllers/Operations/FamilyPortalController.php::update` validation — accept `show_respite`.
- `resources/js/pages/operations/family-portal/Edit.tsx`, `Show.tsx` — surface the new toggle.
- `resources/js/pages/portal/schedule.tsx`, `portal/family-dashboard.tsx`, `portal/calendar.tsx` — render the new respite block.
- `resources/js/pages/operations/clients/show.tsx` — minor: add a short helper text under the Respite tab so it is clear the tab is permission-gated, not data-gated. Empty state already exists.

Required migration:
- `database/migrations/2026_05_..._add_show_respite_to_family_portal_settings_table.php` (single column add).

No new seeders required (default value covers existing rows).

### PR 4 — Test coverage for the cascade (P1 #10; P2 #14)

Files added:
- `tests/Feature/Respite/RespiteBookingShiftCascadeTest.php`
- `tests/Feature/Respite/RespiteBookingRequestApprovalTest.php`
- `tests/Feature/Respite/RespiteStayLifecycleTest.php`
- `tests/Feature/Respite/RespiteAuthorizationTest.php` (covers PR 1 work)
- `tests/Feature/Respite/RespiteRbacKeysSeededTest.php` — asserts every permission key referenced in `routes/respite.php` exists in the `permissions` table after running `RbacSeeder`. This catches future drift.

Files added — factories:
- `database/factories/RespiteReferralFactory.php`, `RespiteBookingRequestFactory.php`, `RespiteStayFactory.php`, `RespiteResourceAllocationFactory.php`, `RespiteHandoverNoteFactory.php`, `RespiteCommunicationLogFactory.php`, `RespiteEvidencePackFactory.php`, `RespiteCalendarEventFactory.php`, `RespiteProcedureRunFactory.php`, `RespiteRiskPlanActivationFactory.php`, `RespiteAuditLogFactory.php`, `RespiteLinkedRefFactory.php`, `RespiteProcedureTemplateFactory.php`.

Files added — Dusk:
- Extend `tests/Browser/Respite/RespiteTest.php` with one `respite end-to-end happy path` test that walks referral → request → booking → confirm → stay → discharge.

### Suggested order of work

1. PR 1 (smallest, unblocks production for non-admin roles)
2. PR 2 (highest user-visible value, depends on PR 1 for cleaner perms)
3. PR 3 (largest, but isolated to portal — can ship behind a `FamilyPortalSetting::show_respite` flag default-off if risk-averse)
4. PR 4 (add as work proceeds; do not block PR 1-3 on the full test set)

---

## 5. Acceptance Criteria

### 5.1 Respite tab visibility on the client profile

- The Respite tab on `operations/clients/show.tsx` shows whenever the user has `respite.viewAny`, regardless of whether the client has any respite data.
- When the user has the permission but the client has no data, the empty state shows a "Start a respite request" CTA wired to `/respite/requests/create?client_id={id}` (already in the code; just verify and document).
- The list-page `respiteFilter` continues to filter by `has_respite` derived from booking + request counts; this is a list filter, not a profile-tab gate.
- The behaviour is documented in `docs/route-ownership.md` (or this plan stays canonical).

### 5.2 Portal / Respite / Rostering / Shifts handoff

- Creating a `RespiteBooking` (directly or via an approved `RespiteBookingRequest`) creates exactly one `Shift` with `respite_booking_id` set and `status='scheduled'`.
- Updating a `RespiteBooking`'s start/end propagates to the linked shift (when the shift is not `completed`).
- Cancelling a `RespiteBooking` cancels the linked shift (when not `completed`).
- A `RespiteStay::checkIn` updates the linked shift to `actual_starts_at = now()` and `status='in_progress'`.
- A `RespiteStay::discharge` updates the linked shift to `actual_ends_at = now()` and `status='completed'`.
- `RespiteStay::extend` propagates new end to the booking and shift; validation is `after:actual_start`, not `after:now`.
- The shift detail page deep-links back to the booking when `respite_booking_id` is set.
- Operations and portal calendars distinguish respite shifts visually (badge or distinct color).
- Family/NOK portal sees a "Respite stays" block on schedule + dashboard, gated by `FamilyPortalSetting::show_respite`.

### 5.3 Permissions

- Every permission key referenced in `routes/respite.php` exists in the `permissions` table after `RbacSeeder` runs (covered by `RespiteRbacKeysSeededTest`).
- Coordinator, Manager, Admin roles have the full granular respite permission set.
- Support Worker has the minimum needed (`respite.viewAny`, plus daily notes if business agrees).
- Auditor keeps `respite.viewAny`, `respite.evidence.view`.
- `family_portal.viewAny` / `family_portal.manage` are wired to the operations admin routes and assigned to coordinator/manager/admin.
- Every respite controller action that touches a Client calls `$this->authorize('view', $client)` so tenant scope is enforced even if a permission is granted globally.

---

## 6. Verification Plan

### 6.1 Laravel feature tests

Run from repo root:

```bash
php artisan test --filter=Respite
php artisan test --filter=Portal
php artisan test --filter=ClientPortalUserController
```

New test groups expected:
- `Tests\Feature\Respite\RespiteBookingShiftCascadeTest` — create / update / cancel cascade.
- `Tests\Feature\Respite\RespiteBookingRequestApprovalTest` — approval creates booking + shift.
- `Tests\Feature\Respite\RespiteStayLifecycleTest` — check-in, extend, discharge mutate the shift.
- `Tests\Feature\Respite\RespiteAuthorizationTest` — out-of-tenant client returns 403.
- `Tests\Feature\Respite\RespiteRbacKeysSeededTest` — all routes' permission keys resolved post-seed.

### 6.2 Browser / Dusk

```bash
php artisan dusk --group=respite
```

Existing 28 smoke tests in `tests/Browser/Respite/RespiteTest.php` should still pass. Add at least one end-to-end happy path test that walks the full lifecycle.

### 6.3 Route-list spot checks

```bash
php artisan route:list --path=portal
php artisan route:list --path=respite
php artisan route:list --name=operations.family_portal
```

Verify after PR 1:
- All `respite.*` routes resolve and list expected permission middleware.
- After PR 1 the family-portal admin routes show `family_portal.viewAny|clients.update` (or sole `family_portal.viewAny|manage`) middleware.

### 6.4 Frontend / build

```bash
npm run types
npm run build
git diff --check
```

`npm run types` will fail on any TS prop-shape drift (e.g., when `PortalSchedule` props gain a `respiteStays` block).

### 6.5 Database

```bash
php artisan migrate --pretend     # PR 3 — verify the new column DDL
php artisan db:seed --class=RbacSeeder
php artisan db:seed --class=OperationsPermissionsSeeder
```

After seeding, query `permissions` for `respite.*` to confirm count matches the route file's references.

---

## 7. What Not To Change

Leave alone unless evidence proves otherwise:

1. **Route ownership** — `routes/portal.php` (client/family + shared features), `routes/operations.php` (admin family-portal settings), and `routes/respite.php` stay where they are. Do not merge respite into operations or move portal pieces around.
2. **`Portal\*` and `Operations\FamilyPortalController` separation** — the two-surface model (admin manages settings, family/NOK uses portal) is correct and should not be flattened.
3. **`Shift.respite_booking_id` schema** — column, FK, index are all correct ([migration](database/migrations/2026_01_29_000700_add_respite_booking_id_to_shifts_table.php)). Do not switch to a polymorphic `shiftable_*` or split into a join table.
4. **`RespiteCalendarEvent` projection model** — keep the projector pattern; just trigger from listeners rather than a synchronous backfill in `index()` (P2 #11).
5. **`RespiteEvent` envelope** — keep the generic event shape (`name`, `payload`, `version`). Do not split into one-event-per-action; subscribers can switch on `name`.
6. **`ServiceContext` enum and the `clients.service_context_id` link** — leave this untouched. It is a labeling/audit dimension, not a feature-gate. Adding a separate boolean `respite_enabled` on `clients` is unnecessary; permissions + data presence are sufficient.
7. **Existing Dusk smoke tests** — keep them running. They are shallow but catch route/render regressions cheaply.
8. **Inertia `auth.can.respite` shape** — defined at [HandleInertiaRequests.php:505-515](app/Http/Middleware/HandleInertiaRequests.php). Do not rename keys; add `evidenceManage`, `evidenceSeal`, etc. only if the frontend needs them.
9. **`FamilyPortalSetting` table shape** — add new columns (e.g., `show_respite`) but do not rename existing flags; they are referenced from at least 5 controllers.
10. **`PortalOAuthController` SSO routes** — the SSO surface is separate work; do not touch it as part of this plan.

---

## File index (quick reference)

- Routes: [routes/portal.php](routes/portal.php), [routes/respite.php](routes/respite.php), [routes/operations.php:940-945](routes/operations.php), [routes/shifts.php](routes/shifts.php) (legacy redirects).
- Inertia auth shape: [app/Http/Middleware/HandleInertiaRequests.php:505-515](app/Http/Middleware/HandleInertiaRequests.php).
- Portal controllers: [app/Http/Controllers/Portal/](app/Http/Controllers/Portal/), [app/Http/Controllers/PortalController.php](app/Http/Controllers/PortalController.php), [app/Http/Controllers/PortalClientController.php](app/Http/Controllers/PortalClientController.php), [app/Http/Controllers/ClientPortalUserController.php](app/Http/Controllers/ClientPortalUserController.php), [app/Http/Controllers/Operations/FamilyPortalController.php](app/Http/Controllers/Operations/FamilyPortalController.php).
- Respite controllers: [app/Http/Controllers/Respite/](app/Http/Controllers/Respite/).
- Respite models: [app/Models/Respite*.php](app/Models/), `App\Models\Shift::respite_booking_id`.
- Service: [app/Services/Respite/RespiteCalendarProjector.php](app/Services/Respite/RespiteCalendarProjector.php).
- Event: [app/Events/Respite/RespiteEvent.php](app/Events/Respite/RespiteEvent.php).
- Settings model: [app/Models/FamilyPortalSetting.php](app/Models/FamilyPortalSetting.php).
- Migrations: [database/migrations/2026_01_29_000600_create_respite_tables.php](database/migrations/2026_01_29_000600_create_respite_tables.php), [2026_01_29_000700_add_respite_booking_id_to_shifts_table.php](database/migrations/2026_01_29_000700_add_respite_booking_id_to_shifts_table.php), [2026_01_30_000001_add_respite_procedure_execution_tables.php](database/migrations/2026_01_30_000001_add_respite_procedure_execution_tables.php), [2026_03_25_100000_fix_respite_service_type.php](database/migrations/2026_03_25_100000_fix_respite_service_type.php), [2026_03_25_200100_add_respite_fields_to_sites.php](database/migrations/2026_03_25_200100_add_respite_fields_to_sites.php), [2026_03_23_005400_create_family_portal_settings_table.php](database/migrations/2026_03_23_005400_create_family_portal_settings_table.php).
- Seeders: [database/seeders/RbacSeeder.php](database/seeders/RbacSeeder.php) (canonical), [database/seeders/OperationsPermissionsSeeder.php](database/seeders/OperationsPermissionsSeeder.php) (`family_portal.*`), [database/seeders/DuskDatabaseSeeder.php:131-138](database/seeders/DuskDatabaseSeeder.php) (granular respite list reference).
- Tests: [tests/Browser/Respite/RespiteTest.php](tests/Browser/Respite/RespiteTest.php), [tests/Feature/Portal/PortalSurfaceTest.php](tests/Feature/Portal/PortalSurfaceTest.php), [tests/Feature/ClientPortalUserControllerTest.php](tests/Feature/ClientPortalUserControllerTest.php).
- Frontend: [resources/js/pages/portal/](resources/js/pages/portal/), [resources/js/pages/respite/](resources/js/pages/respite/), [resources/js/pages/operations/family-portal/](resources/js/pages/operations/family-portal/), [resources/js/pages/operations/clients/show.tsx](resources/js/pages/operations/clients/show.tsx).
