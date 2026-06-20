# Lone Workers Redesign — Cross-Module Integration & Duplication Audit (05)

Read-only audit. Goal: wire the `/health-safety/lone-workers` coordinator redesign into the
existing platform with **zero duplication**. Each integration point below states the **integration
point**, the **dedup rule** (what to reuse, not rebuild), and the **gap** (what is genuinely missing).

Domain model recap (today):
- `lone_worker_sessions` (`app/Models/LoneWorkerSession.php`): `user_id`, `site_id` (nullable),
  `client_id` (nullable), `started_at`, `expected_end_at`, `ended_at`, `location` (text),
  `location_lat`/`location_lng` (decimal 10,7), `activity_description`, `check_in_interval_minutes`
  (default 60), `last_check_in_at`, `status` (active/overdue/emergency/completed),
  `emergency_triggered_at`, `emergency_notes`, `created_by`/`updated_by`. **No `shift_id`.**
- `lone_worker_check_ins` (`LoneWorkerCheckIn`): `lone_worker_session_id`, `checked_in_at`,
  `location_lat`/`lng`, `status` (ok/concern/emergency), `notes`.
- `lone_worker_alerts` (`LoneWorkerAlert`) — **@deprecated legacy compat only** (PR4 docblock).
  Canonical operational alert = `ControlRoomAlert` where `source='lone_worker'`.
- Migration `2026_03_28_200004_create_lone_worker_tables.php` — **3 tables only, NO attachments table.**
- Controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php`; routes
  `routes/health-safety.php:265-281`; page `resources/js/pages/health-safety/lone-workers/index.tsx`.

---

## (A) SHIFTS / ROSTER

**Integration point.** `app/Models/Shift.php` already holds *everything* a session re-keys:
`user_id` (rel `staff()` L148), `site_id` (`site()` L128), `client_id` (`client()` L123),
`starts_at`/`ends_at`/`actual_starts_at`/`actual_ends_at` (casts L48-51), `location` (string, fillable
L36), `status`, `is_on_call` (bool). Plus `signals()` → `ShiftSignal` (L178) and GPS via
`ShiftGpsLog`. `ShiftGpsLog` (`app/Models/ShiftGpsLog.php`): `shift_id`, `user_id`, `event_type`,
`latitude`/`longitude` (decimal 10,7), `accuracy`, `address`, `captured_at`; `belongsTo(Shift)`.
`ShiftSignal` (`app/Models/ShiftSignal.php`): `shift_id`, `site_id`, `client_id`, `user_id`,
`signal_type`, `severity_hint`, `occurred_at`, `idempotency_key`, `payload`, `hasOne(ShiftSignalOutbox)`
— this is the shift→Control-Room pipeline (parallel to the lone-worker one).

**No lone/remote/solo flag exists.** Grep of `app/Models/Shift*.php` for `lone|remote|solo` = 0 hits.
The only adjacent concept is `is_on_call` (bool). The roster is therefore **not** a system of record for
"which shifts need lone-worker monitoring" today.

**Dedup rule.** Do **not** merge `Shift` and `LoneWorkerSession` (the INTEGRATION_AUDIT §3 "Why link,
not merge" is correct): a shift is payroll/rostering truth guarded by `ShiftSafetyInvariantService`
(`Shift::booted()` L75-76, plus the approved-timesheet payroll-critical lock L79-108). A session is a
safety overlay with its own lifecycle. **Link, don't merge:** add a **nullable `shift_id` FK** to
`lone_worker_sessions` + `belongsTo(Shift)` / `Shift hasOne(LoneWorkerSession)`. Nullable preserves the
genuine ad-hoc case (worker alone, no rostered shift).

**Query — "rostered lone/remote shifts NOT yet being monitored"** (the strong hero metric). Two-stage,
because there is no flag yet:
1. Decide the source-of-truth for "lone/remote". Cheapest is to **derive** it (e.g. `is_on_call=true`,
   or solo coverage: a shift whose `site_id`/window has exactly one rostered `user_id`). Stronger is a
   new `is_lone_worker`/`requires_lone_monitoring` boolean on `Shift` (out-of-scope additive change;
   flag for product per INTEGRATION_AUDIT open decision).
2. Query: `Shift` where (lone condition) AND `actual_starts_at` set (clocked in) AND
   `whereDoesntHave('loneWorkerSession', active)` (via the new FK) AND `ends_at >= now()`. Surface as a
   "Rostered, not yet monitored" worklist + a hero KPI count — far better than today's flat
   `active_sessions` count (`LoneWorkerController::index` L61).

**Wizard prefill from a chosen shift** (the mockup's new Step 1 "Choose the shift"). Selecting a shift
fills: `user_id` ← `shift.user_id`; `site_id` ← `shift.site_id`; `client_id` ← `shift.client_id`;
`expected_end_at` ← `shift.ends_at`; `location` ← `shift.location`; `location_lat/lng` ← **latest
`ShiftGpsLog` ping** for that shift (`ShiftGpsLog::where('shift_id',…)->latest('captured_at')->first()`)
instead of asking the worker for coordinates again. Persist `shift_id` on the session. Ad-hoc stays a
toggle that hides Step 1. `startSession` (`LoneWorkerController` L123-152) already accepts all these
fields; only `shift_id` (nullable, `exists:shifts,id`) needs adding to its `validate()`.

**Gap.** (1) `shift_id` column + relations + wizard prefill. (2) the "lone/remote" determination
(derive now, or add a `Shift` flag later). (3) optional auto-lifecycle (offer-to-monitor on clock-in;
auto-complete session on clock-out/handover) — keep an *offer*, not silent magic; clearly Phase-2.

---

## (B) MY DAY — worker check-in (do NOT rebuild on the coordinator page)

**Integration point.** `resources/js/pages/my-day/index.tsx` is the frontline worker's shift cockpit:
it reads `props.active_shift` (L181), the clock session `props.clock.open_session` (L292,
`clockedIn` L293, live `clockedMinutes` ticker L296-312), a `WhatsNextRail` (L42), end-of-shift
checklist + outgoing handover sheets (L18-19, L148-157), residents/tasks from the active shift.

**Confirmed: My Day does NOT yet surface any lone-worker check-in.** Grep of `my-day/index.tsx` for
`check-in | sessions.check | lone_worker | monitored` = **0 hits.** So the worker-facing "You're being
monitored — check in" affordance does **not** exist today and must be added **in My Day**, not here.

**Where it lives + what it POSTs.** A single "You're being monitored — check in" card on the active-shift
block in My Day (rendered only when the signed-in user has an `active`/`overdue` `LoneWorkerSession`,
ideally the one linked to `active_shift` via the new `shift_id`). One tap = OK; a second affordance =
"I need help". It POSTs to the **existing** endpoint
`POST /health-safety/lone-workers/sessions/{session}/check-in`
(route `health-safety.lone-workers.sessions.check-in`, `LoneWorkerController::checkIn` L157-197), with
`status: ok|concern|emergency`. An `emergency` check-in already drives the full emergency flow
(`checkIn` L178-194 → `LoneWorkerSignalService::emitEmergency`). End-of-session ≈ end-of-shift: when a
monitored shift is clocked out / handed over, **auto-end the session** rather than asking twice.

**Dedup rule (HARD).** The redesigned coordinator page must **NOT** contain a worker check-in UI. The
register, hero clusters/badges, Start-session wizard, detail modal, and paginated history are
**coordinator/H&S surfaces** with no place in My Day. Share *data + endpoints*, never duplicate the
*UI*. (This page = watch-tower; My Day = worker cockpit.) The coordinator's own "record a check-in on the
worker's behalf" action is fine on the detail modal — it hits the same endpoint.

**Gap.** The My Day "being-monitored / check-in" card itself (frontend + a `props` field exposing the
worker's active session). This is a My Day change, parallel to the coordinator redesign — note it but it
need not block the coordinator build.

---

## (C) CONTROL ROOM — canonical triage (do NOT rebuild triage)

**Integration point.** Lone-worker operational alerts surface as `ControlRoomAlert` with
`source='lone_worker'`. Emission: `LoneWorkerSignalService` (`app/Services/HealthSafety/`) →
`SignalProcessingService::ingest()`/`process()` → `ControlRoomAlert`. Signal types (service consts):
`lone_worker_emergency` (CRITICAL), `lone_worker_overdue_checkin` (HIGH, →CRITICAL ≥60min),
`lone_worker_session_overrun` (MEDIUM→HIGH). `SignalSource` slug `lone_worker` is auto-created
(L184-208). `LoneWorkerController::index` already reads canonical counts/lists from
`ControlRoomAlert::where('source','lone_worker')` (L65-81) — the legacy `LoneWorkerAlert` is only kept
for pre-PR4 historical rows (L83-93).

**Canonical triage target.** `GET /control-room/alerts/{alert}` →
**`control-room.alerts.show`** (`routes/control-room.php:51-53`). The full operational lifecycle lives
there: assign / acknowledge / confirm / dismiss / triage / resolve / close / escalate / note / tasks
(`routes/control-room.php:81-152`). `ControlRoomAlert` has the state machine (`VALID_STATUSES`,
`ALLOWED_TRANSITIONS`, escalation level cap 5) in the model.

**Dedup rule.** Do **NOT** rebuild triage/SLA/escalation on the lone-worker page. The redesign's alert
rows should **deep-link to `control-room.alerts.show`** as the triage destination (this is the canonical
target). The legacy `acknowledgeAlert`/`resolveAlert` actions (`LoneWorkerController` L249-282) are
explicitly documented as compatibility-only convenience actions and should be de-emphasised (or treated
as the legacy-row affordance only), not promoted as the primary triage path.

**Gap.** None on triage — already canonical. Only UI wiring: alert rows/cards → CR deep link, and a
status pill mapping (CR `open/ack/resolved/closed` ↔ display, already done in `mapCanonicalAlert`
L356-361).

---

## (D) QUECLINK / LocateNow — GPS / panic-device path

**Integration point.** `app/Services/Queclink/*` is a full GPS-tracker stack (AT-track protocol parser,
command builder, `ConfigurationSnapshotService`, `SerialNumberAllocator`, TCP listener).
`LocateNowService` (`app/Services/Queclink/LocateNowService.php`) queues a "request location now" command
to a paired Queclink device (`queueForDevice`/`queueForImei`). Crucially, `familyFor()` (L72-90)
recognises device category **`lone_worker_tracker`** (alongside `personal_tracker`, `client_tracker`)
→ `FAMILY_GL30M`. So the platform already models personal/lone-worker GPS panic devices at the device
layer (also referenced in `DeviceTaxonomy.php`).

**Link / dedup.** There is **no current code path wiring a Queclink device ping or panic event to a
`LoneWorkerSession` or to a `source='lone_worker'` ControlRoomAlert.** Queclink ingest (TcpListener) does
not reference lone-worker sessions. So today Queclink GPS and lone-worker sessions are **disconnected.**
The principled future link is: a `lone_worker_tracker` device panic/ping → `LoneWorkerSignalService`
(reuse the existing emission service, do not invent a second alert path) → ControlRoom. And the wizard /
detail could offer a "Locate now" action via `LocateNowService` when the session's worker has a paired
tracker.

**Gap (out of scope for this redesign).** Wiring Queclink → lone-worker sessions/alerts is a separate
hardware-integration workstream. **Note the seam, do not build it** in the UI redesign. Mentioning it as a
"device locate" affordance is at most a Phase-2 stub and should otherwise stay hidden (per the project's
hide-unbuilt-actions rule).

---

## (E) NOTIFICATIONS / ESCALATION — how overdue/emergency escalate

**Integration point + how it works.**
- **Detection job:** `app/Jobs/CheckLoneWorkerOverdueJob.php`, scheduled in `routes/console.php:154-158`
  **every 5 minutes**, timezone `Pacific/Auckland`. It loads sessions in `['active','overdue']`
  (emergency excluded to avoid noise on top of the existing critical alert; completed excluded). For each:
  if `isCheckInOverdue()` → flips `active`→`overdue` and calls
  `LoneWorkerSignalService::emitOverdueCheckIn($session, $minutesOverdue)`; if past `expected_end_at` →
  `emitSessionOverrun()`. (`LoneWorkerSession::isCheckInOverdue()` L112-121 = last check-in + interval is
  past.)
- **Escalation engine:** operational lone-worker escalation is handled **exclusively by the Control Room
  engine** (`CheckControlRoomSlaBreaches`, `AutoEscalateControlRoomQueues`,
  `ControlRoomNotificationService`) once the alert exists. This is explicit in two architectural docblocks.
- **`NotificationEscalationRule`** (`app/Models/NotificationEscalationRule.php`) is **WORKFLOW-only**
  (timesheets/leave/expenses/onboarding). Its docblock states operational keys (`lone_worker.*`,
  `incidents.*`, `safeguarding.*`, `hazard.*`, …) are **SKIPPED**.
- **`EscalatePendingNotifications`** (`app/Console/Commands/EscalatePendingNotifications.php`,
  `notifications:escalate`) hard-skips event keys with prefix `lone_worker.` (and the other operational
  prefixes) via `OPERATIONAL_EVENT_PREFIXES` (L41-49, `isOperationalEventKey` L80-89). So this command is
  **NOT** the lone-worker escalation path.

**Dedup rule.** Do **NOT** add lone-worker escalation rules to `NotificationEscalationRule` /
`notifications:escalate` — they are intentionally walled off. Severity/escalation behaviour belongs to
the ControlRoom engine, configured against `ControlRoomAlert`. The redesign needs no new escalation code.

**Gap.** None on escalation mechanics. The redesign just *visualises* the lifecycle (overdue countdown
from `expected_end_at`/`last_check_in_at` + interval; "emergency" critical state) and links out to CR.

---

## (F) SIDEBAR NAV

**Integration point.** `resources/js/components/app-sidebar.tsx` L1232-1237: pushed into the H&S
management group when `can?.hazards?.view || can?.compliance?.view`:
- **label:** `'Lone Worker Safety'`
- **href:** `'/health-safety/lone-workers'`
- **icon:** `PersonStanding`

**Dedup rule / gap.** Keep label/href/icon unchanged (no nav churn; route already canonical). Permissions
are `hazards.view` (index) / `hazards.manage` (mutations) per `routes/health-safety.php:267-280`. The
INTEGRATION_AUDIT open decision of splitting `lone-workers.*` permissions is **out of scope** for the
redesign — reuse `hazards.*`.

---

## CRITICAL JUDGEMENT 1 — Does any lone-worker modal need the premium file-dropzone upload?

**No. It is scope creep here.** Justification:
- **No storage exists.** There is **no `lone_worker_attachments` table** and none of the 3 lone-worker
  tables has a documents column (migration `2026_03_28_200004`). Grep of the lone-worker page for
  `attachment|file-dropzone|FileDropzone|upload` = **0 hits.** Adding `file-dropzone.tsx`
  (`FileDropzone`/`StagedFileCard`/`AttachmentUploader`) would require a brand-new table + controller +
  storage wiring with no domain demand.
- **Domain mismatch.** A lone-worker session is a *real-time presence/safety overlay* — its lifecycle is
  start → check-in → overdue → emergency → end, measured in minutes. It is not a document-bearing record
  the way an Incident (evidence photos), Safeguarding concern (evidence), or Site (policies) are. Those
  modules legitimately use the premium dropzone (per memory: Add Site / Safeguarding Evidence / Incident
  attachments). Lone-worker has no equivalent artefact.
- **Escalation already carries evidence elsewhere.** If a lone-worker emergency becomes an Incident (see
  Judgement 2), evidence/attachments live on that `ClientIncident` (which *does* have the attachments
  pipeline) — the right home. Duplicating upload onto the session would split evidence across two records.

**Verdict:** do **not** add file upload to any lone-worker modal. Keep the action modals
(check-in / extend-edit / end / emergency / acknowledge / resolve) as focused single-screen forms that
POST to existing endpoints. (If product ever wants session-level photos, that's a separate, scoped
feature with its own table — not part of this gold-standard pass.)

## CRITICAL JUDGEMENT 2 — Is a lone-worker → Incident/HsEvent escalation worth wiring, and does one exist?

**It already exists — via Control Room — and is the correct, source-agnostic path. Do not build a
second one.**
- A lone-worker emergency/overdue already becomes a `ControlRoomAlert` (source='lone_worker') through
  `LoneWorkerSignalService` → `SignalProcessingService` (Judgement context: §C).
- The Control Room provides a **source-agnostic alert→Incident bridge**:
  - `ControlRoomAlertController::confirm()` (`app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:700-721`)
    → `SensorIncidentBridgeService::confirm($alert, $user, …)`
    (`app/Services/ControlRoom/SensorIncidentBridgeService.php:32-81`). `confirm()` does **not** gate on
    `$alert->source` — it works on any `ControlRoomAlert`. It creates a `ClientIncident`
    (`control_room_alert_id` back-link, idempotent), and the **`ClientIncidentObserver` then opens the
    `HsEvent` and back-links it** (documented L19-22). It is idempotent (returns the existing incident if
    already linked, L34-36) and severity-mapped (critical→high, L142-146).
  - There is also `ControlRoomIncidentController::flagAsIncident()` (…`ControlRoomIncidentController.php:338`)
    as a more general "flag this alert as an incident" path.
- Therefore a lone-worker emergency → Incident → HsEvent path is **already feature-complete**, and it
  lives in **Control Room**, exactly where triage belongs.

**Verdict:** the redesign should **not** add its own "escalate to incident" button that creates a
`ClientIncident` directly. Instead, the lone-worker alert row should **deep-link to
`control-room.alerts.show`**, where the operator uses the existing confirm/flag-as-incident flow. The
only (optional, cheap) polish worth considering is surfacing on the session detail modal a read-only
"Escalated to INC-{id}" link when `ControlRoomAlert.context['incident_id']` is set — purely
informational, reusing data the bridge already writes. No new escalation code.

---

## Net build guidance (dedup-first)

| Concern | Reuse (do NOT rebuild) | Genuine gap to build |
|---|---|---|
| Roster link | `Shift` + `ShiftGpsLog` data; `ShiftSafetyInvariantService` untouched | nullable `shift_id` FK + relations; wizard Step-1 prefill; "not-yet-monitored" worklist/KPI; lone-shift derivation |
| Worker check-in | `sessions.check-in` endpoint; My Day shift cockpit | My Day "being-monitored / check-in" card (My Day change) |
| Triage | `control-room.alerts.show` + full CR lifecycle | UI deep-link only |
| GPS/panic | `LocateNowService`, `lone_worker_tracker` taxonomy | (out of scope) optional Phase-2 device link via `LoneWorkerSignalService` |
| Escalation | `CheckLoneWorkerOverdueJob` (5-min); CR escalation engine | none — visualise only |
| Incident escalation | CR `confirm`/`flagAsIncident` → `ClientIncident` → `HsEvent` | none — deep-link + optional read-only "Escalated to INC-{id}" chip |
| Uploads | (nothing) | none — file-dropzone is scope creep; no table, no domain need |
| Nav | sidebar entry (label/href/icon `PersonStanding`); `hazards.*` perms | none |

**Two new server touch-points only:** `shift_id` on `lone_worker_sessions` (+ `startSession` validation
& prefill plumbing) and the My Day card props. Everything else is canonical and must be *reused*, not
duplicated.
