# Lone Workers Redesign — Backend Audit (02)

Read-only audit of the existing Lone Worker backend + the canonical Control Room alert pipeline. Cites `file:line`, exact names, column types. Goal: enumerate what exists and what the gold-standard redesign of `/health-safety/lone-workers` must ADD.

---

## 1. Routes

`routes/health-safety.php:264-281` — prefix `lone-workers`, name `lone-workers.`, inside the `/health-safety` group (`health-safety.` name prefix → full route names are `health-safety.lone-workers.*`).

| Verb | URI | Name | Action | Middleware |
|---|---|---|---|---|
| GET | `/health-safety/lone-workers` | `health-safety.lone-workers.index` | `LoneWorkerController@index` | `permission:hazards.view` |
| POST | `/health-safety/lone-workers/sessions` | `…lone-workers.sessions.store` | `startSession` | `permission:hazards.manage` |
| POST | `/health-safety/lone-workers/sessions/{session}/check-in` | `…sessions.check-in` | `checkIn` | `permission:hazards.manage` |
| POST | `/health-safety/lone-workers/sessions/{session}/end` | `…sessions.end` | `endSession` | `permission:hazards.manage` |
| POST | `/health-safety/lone-workers/sessions/{session}/emergency` | `…sessions.emergency` | `triggerEmergency` | `permission:hazards.manage` |
| POST | `/health-safety/lone-workers/alerts/{alert}/acknowledge` | `…alerts.acknowledge` | `acknowledgeAlert` (legacy `LoneWorkerAlert` only) | `permission:hazards.manage` |
| POST | `/health-safety/lone-workers/alerts/{alert}/resolve` | `…alerts.resolve` | `resolveAlert` (legacy only) | `permission:hazards.manage` |

Permissions: VIEW = `hazards.view`; WRITE = `hazards.manage`. The Inertia prop `can_manage` is `$request->user()?->canDo('hazards.manage') ?? false` (`LoneWorkerController.php:116`). **No `lone-workers.show` route exists** — detail is rendered client-side via modal off the index payload (redesign will key it off `?session=`/`?alert=`).

**There is NO `sessions.update` route** — sessions are immutable once created except via check-in/end/emergency. (Redesign must add one.)

---

## 2. Controller — `app/Http/Controllers/HealthSafety/LoneWorkerController.php`

### `index(Request)` (`:38-118`)
- Reads filters via `$request->only(['site_id','status','user_id'])` (`:40`). **No `period` filter, no `q`/text search, no `tab` switch.**
- Sessions: `LoneWorkerSession::with(['user:id,name','site:id,name','client:id,first_name,last_name'])` + optional `where` on each filter, `orderByDesc('started_at')`, `paginate(25)->withQueryString()` (`:42-58`). `->through()` flattens client to `{id, name}`.
- Stats (`:60-73`):
  - `active_sessions` = `LoneWorkerSession::where('status','active')->count()`
  - `overdue_check_ins` = `where('status','overdue')->count()`
  - `alerts_today` = `ControlRoomAlert::where('source','lone_worker')->where('triggered_at','>=',now()->startOfDay())->count()`
  - `emergency_alerts` = `ControlRoomAlert::where('source','lone_worker')->where('alert_type','lone_worker_emergency')->whereNotIn('status',['resolved','closed'])->count()`
- Alerts (`:75-98`): merges up to 20 **canonical** `ControlRoomAlert` (source=`lone_worker`, `orderByDesc('triggered_at')->limit(20)`) with up to 10 **legacy** `LoneWorkerAlert` where `triggered_at < now()->subDay()`, sorts desc, takes 20. **Returned as a plain merged collection — NOT paginated.**
- Props (`:100-117`): `sessions` (paginator), `alerts` (collection), `stats`, `sites` (`Site::select('id','name')->where('is_active',true)`), `staff` (`User::select('id','name')`), `clients` (mapped `{id,name}`), `filters`, `can_manage`.
- Renders `health-safety/lone-workers/index`.

### Write actions
- `startSession` (`:123-152`): validates `user_id` (required, exists), `site_id`/`client_id` (nullable exists), `expected_end_at` (required date after:now), `activity_description` (nullable ≤2000), `check_in_interval_minutes` (nullable int 15-480), `location` (≤500), `location_lat`/`lng` (numeric bounded). Creates with `started_at=now`, `last_check_in_at=now`, `status='active'`, interval default 60, `created_by`/`updated_by`. `?stay=1` → `back()`; else redirect to index.
- `checkIn` (`:157-197`): validates `status` (`in:ok,concern,emergency`), `notes` (≤1000), lat/lng. Creates a `LoneWorkerCheckIn` (`checked_in_at=now`, default `ok`), bumps `last_check_in_at`, sets `status='active'`. If status=`emergency`: sets session `status='emergency'` + `emergency_triggered_at` + `emergency_notes`, writes legacy `LoneWorkerAlert`, AND calls `app(LoneWorkerSignalService::class)->emitEmergency(...)`.
- `endSession` (`:202-211`): sets `ended_at=now`, `status='completed'`.
- `triggerEmergency` (`:216-240`): validates `emergency_notes` (≤2000); sets `status='emergency'`, `emergency_triggered_at`, legacy alert, AND `emitEmergency`.
- `acknowledgeAlert`/`resolveAlert` (`:249-282`): mutate the **legacy `LoneWorkerAlert`** only (status `acknowledged`/`resolved`). Comment is explicit: canonical triage is in Control Room.

### Alert mappers (shared display shape)
- `mapLegacyAlert` (`:288-320`): id `legacy_{id}`, nested `session{user,site,client,started_at,expected_end_at,last_check_in_at,status,activity_description,check_in_interval_minutes,location}`, `type`, `triggered_at`, `status`, `notes`, `_source:'legacy'`.
- `mapCanonicalAlert` (`:326-365`): id **`cr_{id}`** (prefix matters for deep-linking — must be stripped to hit `control-room.alerts.show`). Pulls session context from `$alert->context['normalized_data']` keys (see §6). Status mapped: `open→active`, `ack→acknowledged`, `resolved|closed→resolved`. `session.status`/`check_in_interval_minutes` are `null` for canonical (not stored in context). `_source:'canonical'`.

---

## 3. Model — `app/Models/LoneWorkerSession.php`

Traits: `HasFactory, SoftDeletes, AuditableChanges` (`:14`).

**`$fillable`** (`:16-34`): `user_id, site_id, client_id, started_at, expected_end_at, ended_at, location, location_lat, location_lng, activity_description, check_in_interval_minutes, last_check_in_at, status, emergency_triggered_at, emergency_notes, created_by, updated_by`. (No `shift_id`.)

**`$casts`** (`:36-44`): `started_at/expected_end_at/ended_at/last_check_in_at/emergency_triggered_at` → `datetime`; `location_lat/location_lng` → `decimal:7`.

**Relationships** (`:48-81`): `user()` BelongsTo User; `site()` BelongsTo Site; `client()` BelongsTo Client; `checkIns()` HasMany `LoneWorkerCheckIn`; `alerts()` HasMany `LoneWorkerAlert`; `createdBy()`/`updatedBy()` BelongsTo User. (**No `shift()`.**)

**Scopes** (`:85-98`): `scopeActive` (status=active), `scopeOverdue` (status=overdue), `scopeEmergency` (status=emergency).

**Helpers** (`:102-121`): `isActive()`, `isOverdue()`, `isCheckInOverdue()` — for active sessions, `(last_check_in_at ?? started_at)->addMinutes(check_in_interval_minutes)->isPast()`.

Session `status` is a free string (default `active`). Observed values across code: `active`, `overdue`, `emergency`, `completed`.

---

## 4. Model — `app/Models/LoneWorkerAlert.php` (LEGACY / `@deprecated` PR4)

Traits: `HasFactory, SoftDeletes, AuditableChanges`. Doc block (`:11-27`) states the canonical lifecycle lives on `ControlRoomAlert` where `source='lone_worker'`; this model is retained for back-compat + history.

**`$fillable`** (`:32-43`): `lone_worker_session_id, alert_type, triggered_at, acknowledged_at, acknowledged_by, escalated_at, escalated_to, resolved_at, resolution_notes, status`.
**`$casts`**: `triggered_at/acknowledged_at/escalated_at/resolved_at` → `datetime`.
**Relationships**: `session()` (FK `lone_worker_session_id`), `acknowledgedBy()`, `escalatedTo()`.
**Scopes**: `scopeActive`, `scopeUnacknowledged` (`whereNull('acknowledged_at')`).
**Helpers**: `isActive()`, `isResolved()`.

---

## 5. Model — `app/Models/LoneWorkerCheckIn.php`

Traits: `HasFactory` only (**no SoftDeletes, no AuditableChanges**). `$table='lone_worker_check_ins'`.
**`$fillable`**: `lone_worker_session_id, checked_in_at, location_lat, location_lng, status, notes`.
**`$casts`**: `checked_in_at` → datetime; lat/lng → `decimal:7`.
**Relationship**: `session()` (FK `lone_worker_session_id`).

---

## 6. Schema — migration `2026_03_28_200004_create_lone_worker_tables.php`

### `lone_worker_sessions`
| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint PK | no | auto |
| `user_id` | unsignedBigInteger | no | — | FK→users cascadeOnDelete |
| `site_id` | unsignedBigInteger | **yes** | — | FK→sites nullOnDelete |
| `client_id` | unsignedBigInteger | **yes** | — | FK→clients nullOnDelete |
| `started_at` | dateTime | no | — |
| `expected_end_at` | dateTime | no | — |
| `ended_at` | dateTime | yes | — |
| `location` | text | yes | — |
| `location_lat` | decimal(10,7) | yes | — |
| `location_lng` | decimal(10,7) | yes | — |
| `activity_description` | text | yes | — |
| `check_in_interval_minutes` | integer | no | **60** |
| `last_check_in_at` | dateTime | yes | — |
| `status` | string | no | `'active'` |
| `emergency_triggered_at` | dateTime | yes | — |
| `emergency_notes` | text | yes | — |
| `created_by` | unsignedBigInteger | yes | — | FK→users nullOnDelete |
| `updated_by` | unsignedBigInteger | yes | — | FK→users nullOnDelete |
| `created_at/updated_at` | timestamps | — | — |
| `deleted_at` | softDeletes | yes | — |

Indexes: `status`; `user_id`; composite `['status','expected_end_at']`. **No `shift_id` column.**

### `lone_worker_check_ins`
`id`; `lone_worker_session_id` unsignedBigInteger NOT NULL (FK→lone_worker_sessions cascadeOnDelete); `checked_in_at` dateTime NOT NULL; `location_lat`/`location_lng` decimal(10,7) nullable; `status` string default `'ok'`; `notes` text nullable; `timestamps`. Index on `lone_worker_session_id`. **No softDeletes.**

### `lone_worker_alerts`
`id`; `lone_worker_session_id` NOT NULL (FK cascadeOnDelete); `alert_type` string NOT NULL; `triggered_at` dateTime NOT NULL; `acknowledged_at` dt nullable; `acknowledged_by` ubigint nullable (FK→users nullOnDelete); `escalated_at` dt nullable; `escalated_to` ubigint nullable (FK→users nullOnDelete); `resolved_at` dt nullable; `resolution_notes` text nullable; `status` string default `'active'`; `timestamps`; `softDeletes`. Indexes: `status`, `lone_worker_session_id`.

---

## 7. Canonical alert pipeline

**Flow:** lone worker event → `LoneWorkerSignalService` → `SignalProcessingService::ingest()` → `::process()` → `ControlRoomAlert` (`source='lone_worker'`).

### `app/Services/HealthSafety/LoneWorkerSignalService.php`
Type constants (`:22-24`): `TYPE_EMERGENCY='lone_worker_emergency'`, `TYPE_OVERDUE_CHECKIN='lone_worker_overdue_checkin'`, `TYPE_SESSION_OVERRUN='lone_worker_session_overrun'`. Constructed with `SignalProcessingService` (`:28-30`).

Public emitters:
- `emitEmergency(LoneWorkerSession, ?string $notes)` (`:35-49`) → severity `CRITICAL`; extra context `emergency_notes`, `emergency_triggered_at`.
- `emitOverdueCheckIn(LoneWorkerSession, int $minutesOverdue)` (`:54-72`) → severity `CRITICAL` if `≥60` else `HIGH`; extra `minutes_overdue`, `last_check_in_at`, `check_in_interval_minutes`.
- `emitSessionOverrun(LoneWorkerSession, int $minutesOverrun)` (`:77-93`) → severity `HIGH` if `≥60` else `MEDIUM`; extra `minutes_overrun`, `expected_end_at`.

`emit()` (`:98-164`) builds the signal payload. **`normalized_data` keys** (`:118-140`) — the canonical context the redesign reads back via `$alert->context['normalized_data']`:
`title, description, source_module='lone_worker', signal_type, lone_worker_session_id, worker_user_id, worker_name, site_id, site_name, client_id, client_name, location, location_lat, location_lng, activity_description, started_at, expected_end_at` + the per-type extras above. Top-level signal fields: `signal_source_id, signal_type_code, idempotency_key, site_id, client_id, severity_hint, occurred_at, payload=[], normalized_data`.

Idempotency (`:170-182`): sha256 of `lone_worker|type|session_id|user_id|window`; window = 15 min for emergency, 30 min otherwise.

Signal source (`:184-208`): `SignalSource::firstOrCreate(['slug'=>'lone_worker'], …name 'Lone Worker Safety', vendor 'internal', status 'active', capabilities ['manual_trigger','scheduled_checks'])`.

Failures are caught and logged (never throws to caller).

### Signal types & rules — migration `2026_04_10_250000_seed_lone_worker_signal_types_and_rules.php`
Seeds 3 `SignalType` (category `CATEGORY_PEOPLE_SAFETY`, default_severity critical/high/medium) and 3 `SignalRule`:
- Emergency: priority 5, output_severity `critical`, escalation_level 1, **tier 3**, dedup 5min, `suppress_in_maintenance=false`, notify `['managers_core','coordinators']`.
- Overdue: priority 15, output_severity `null` (uses severity_hint), tier 2, dedup 15min, notify `['managers_core']`.
- Overrun: priority 25, output_severity `null`, tier 1, dedup 30min, notify `[]`.

---

## 8. Overdue detection — `app/Jobs/CheckLoneWorkerOverdueJob.php` + cron

`handle(LoneWorkerSignalService)` (`:28-75`): loads sessions `whereIn('status',['active','overdue'])` with user/site/client. Per session:
- If `isCheckInOverdue()`: computes `minutesOverdue`, flips `status` active→`overdue`, calls `emitOverdueCheckIn`.
- If `expected_end_at` past: computes `minutesOverrun`, calls `emitSessionOverrun`.
Emergency + completed sessions are excluded. Logs a summary when anything fired.

**Cron** (`routes/console.php:154-158`): `app(Schedule::class)->job(new CheckLoneWorkerOverdueJob)->timezone('Pacific/Auckland')->everyFiveMinutes();` (import at `:34`). Note: **no `->withoutOverlapping()`** on this one (the adjacent job at `:152` has it).

---

## 9. `ControlRoomAlert` shape — `app/Models/ControlRoomAlert.php`

**Statuses** (`:15-33`): `open, ack, triaging, resolved, closed, confirmed, dismissed` (consts `STATUS_*`, list `VALID_STATUSES`). Transitions in `ALLOWED_TRANSITIONS` (`:38-46`). `MAX_ESCALATION_LEVEL=5`.

**Key fillable** (`:50-86`, partial): `source, alert_type, severity, status, asset_id, fleet_signal_id, device_id, queue_id, playbook_run_id, site_id, client_id, triggered_at, acknowledged_at/_by_user_id, resolved_at/_by_user_id, closed_at/_by_user_id, escalated_at/_by_user_id, escalation_level, assigned_to_user_id, assigned_at/_by_user_id, created_by_user_id, context, notes, priority, due_at, category, resolution_code, time_spent_minutes, watchers_count, snoozed_until, snoozed_by_user_id`.
**Casts** (`:88-99`): all `*_at`/`due_at`/`snoozed_until` → datetime; `context` → **array**; `escalation_level` → integer.
Relationships incl. `client()`, `site()` (used by the index map), plus assignedTo/acknowledgedBy/etc. Scopes: `scopeOpen`, `scopeUnresolved` (`whereNotIn(['resolved','closed'])`), `scopeHighPriority` (`['high','critical']`), `scopeAssignedTo`. Helpers `canTransitionTo`, `isTerminal`, `isActionable`. `severity` normalised via `AlertSeverity::normalise` on create/update.

**`AlertSeverity`** (`app/Enums/AlertSeverity.php`): `low, medium, high, critical` (consts; `ALL`, `RANK`, `normalise/higher/lower/isAtLeast`).

### `control-room.alerts.show` route
`routes/control-room.php:51-53`: `GET /control-room/alerts/{alert}` `->whereNumber('alert')` `->name('control-room.alerts.show')`, inside `permission:controlRoom.viewAny` group (`:44`).
Controller `ControlRoomAlertController@show` (`app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:413`): signature `show(Request $request, ControlRoomAlert $alert)`; aborts unless `user->canDo('controlRoom.viewAny')` + `assertCanAccessAlert($user,$alert)` (`:415-417`); eager-loads asset/fleetSignal/assignedTo/playbookRun/evidencePacks/communications/sla/client/device/tasks/discussions/watchers (`:419-438`).
**Deep-link gotcha:** the H&S page IDs canonical alerts as `cr_{id}`; strip the prefix and ensure the viewer holds `controlRoom.viewAny` before linking out (lone-worker page is gated only on `hazards.view`).

---

## 10. Demo seeder

`database/seeders/HealthSafetyDemoSeeder.php` has **no lone-worker session/alert/check-in seeding** — only incidental text mentions (`:849, :885, :972`). A grep across `database/seeders` for `LoneWorkerSession::`/`lone_worker_sessions` returns **no files**. The redesign should add a demo seeder block (active/overdue/emergency/completed sessions + check-ins + at least one canonical CR alert) so the new UI has content.

---

## 11. Existing tests

No dedicated lone-worker test file. Only incidental hits: `tests/Feature/Queclink/QueclinkPresetTest.php` and `tests/Browser/HealthSafety/HealthSafetyTest.php`. The controller, job, and signal service currently have ~no direct coverage — add Feature tests with the redesign.

---

## 12. What the redesign MUST ADD (gap list)

1. **`shift_id` nullable FK + relationship.** New migration on `lone_worker_sessions`: `$table->unsignedBigInteger('shift_id')->nullable()->after('client_id')` + FK→`shifts` `nullOnDelete` (confirm shifts table/PK name) + index. Add to `$fillable`, a `shift()` BelongsTo, and accept `shift_id` in `startSession` validation (`nullable|exists:shifts,id`). Eager-load `shift` where useful.
2. **`sessions.update` endpoint.** New `PATCH /sessions/{session}` → `updateSession`, named `…sessions.update`, `permission:hazards.manage`. Allow editing/extending **`expected_end_at`, `check_in_interval_minutes`, `activity_description`, `location`** (and lat/lng); reuse `startSession` validation rules (drop `after:now` or relax for extend), set `updated_by`, optionally re-clear `overdue→active` when extended. Gate on `$session->isActive()/isOverdue()` (don't edit completed/emergency).
3. **`period` + `q` filters on `index`.** Add `period` (e.g. today/7d/30d/all → range over `started_at`) and `q` (text over worker name / client name / activity / location) to `$request->only(...)` and the session query; thread through `withQueryString()` and the `filters` prop.
4. **Paginated alerts when `?tab=alerts`.** Switch the alerts list from a hand-merged collection to a real paginator when the alerts tab is active. Practical approach: paginate canonical `ControlRoomAlert` (source=`lone_worker`) with its own page param; keep the small merged "recent" list for the overview tab. Apply period/q to alerts too.
5. **Detail eager-loads keyed off `?session=` / `?alert=`.** When `?session={id}` present, load that `LoneWorkerSession` with `checkIns` (ordered desc), `alerts`, `user`, `site`, `client` (and `shift`) and pass as `selectedSession` so the detail modal opens server-hydrated. When `?alert={id}` present, hydrate the alert (canonical `cr_` → resolve via `ControlRoomAlert`; legacy `legacy_` → `LoneWorkerAlert` with `session.*`).
6. **`can.manage` shape.** Currently a flat `can_manage` bool. Gold-standard pages use a `can` object — emit `can: { manage: …, view: … }` (keep `can_manage` if other code depends on it, or migrate the frontend). Source from `hazards.manage` / `hazards.view`.
7. **(Supporting) demo seeder** for sessions/check-ins/canonical alerts (§10) and **Feature tests** (§11) covering index filters, start/check-in/end/emergency, the new update endpoint, and the overdue job.

### Frontend contract notes for the rebuild
- Index page component: `health-safety/lone-workers/index` (Inertia render at `LoneWorkerController.php:100`).
- Reuse the H&S hero kit (`hs-hero-kit.tsx`), `TabStrip`, register table w/ left-click detail modal + right-click context menu, and an Add-client-style wizard (see incidents/safeguarding/fleet-incidents redesigns).
- Canonical alert deep-link → strip `cr_` prefix, route `control-room.alerts.show` (requires `controlRoom.viewAny`).
- NZ context: lone-worker safety under HSWA 2015 — surface overdue/overrun/emergency as the compliance badges; severities map to AlertSeverity (`critical/high/medium/low`).
