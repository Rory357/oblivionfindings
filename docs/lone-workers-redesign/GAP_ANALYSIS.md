# Lone Worker Safety — Gap Analysis

Capability the design (`audit/01-design-digest.md`) requires vs. what exists today, grouped
**Backend / Frontend / Cross-module**. Status = **Present** (use as-is) / **Partial** (exists,
must extend) / **Missing** (build). Each gap names the **file to touch** and the **exact kit /
endpoint to reuse**.

Sources: `audit/01..05`, `app/Http/Controllers/HealthSafety/LoneWorkerController.php`,
`routes/health-safety.php:264-281`, `resources/js/pages/health-safety/lone-workers/index.tsx`
(today = legacy `PageHero` + inline `Dialog`s + flat `can_manage`).

NZ / web-only constraints (apply to every row): en-NZ dates via `@/lib/datetime` (never en-GB),
NZD, HSWA 2015 / WorkSafe / ACC framing, no mobile frames. **Keep all route names + URLs stable**
(`/health-safety/lone-workers`, `health-safety.lone-workers.*`) for deep links + the sidebar.

---

## A. BACKEND

| # | Capability design needs | Status | File to touch | Reuse / endpoint |
|---|---|---|---|---|
| B1 | `shift_id` link session→roster (prefill, "not-monitored" metric, persistence) | **Missing** | new migration on `lone_worker_sessions`; `app/Models/LoneWorkerSession.php` (`$fillable` + `shift()` BelongsTo); `app/Models/Shift.php` (`hasOne(LoneWorkerSession)`) | nullable `unsignedBigInteger('shift_id')->after('client_id')` + FK→`shifts` `nullOnDelete` + index. Do NOT merge Shift+session (`audit/05` A) |
| B2 | Edit / extend a session (push `expected_end_at`, change interval, activity, location) | **Missing** | `routes/health-safety.php` (+`PATCH /sessions/{session}` → `updateSession`, name `…sessions.update`, `permission:hazards.manage`); `LoneWorkerController` | mirror `startSession` rules; relax `after:now`; gate `$session->isActive()||isOverdue()`; set `updated_by`; clear `overdue→active` on extend |
| B3 | `index` returns `tab` + `tabCounts` | **Missing** | `LoneWorkerController::index` | `$request->input('tab','sessions')`; `tabCounts = { sessions: active+overdue+emergency, alerts: unresolvedCanonical }` |
| B4 | `index` returns a `hero` block (2 clusters + 5 NZ badges as counts/bools) | **Missing** | `LoneWorkerController::index` | new `hero` array: clusters (active/overdue/emergency/ending-<1h, alerts-today/awaiting-ack/unresolved/no-recent-checkin) + badges (checkedIn/activeTotal, overdue, emergency, HSWA-bool, after-hours-bool). Feed counts, never strings (`audit/03` §1) |
| B5 | `period` filter (today / week / 30d) over `started_at` | **Missing** | `LoneWorkerController::index` (`$request->only` + query); `filters` prop | add `period` to `withQueryString()`; default `today` |
| B6 | `q` text search (worker name / client name / activity / location) | **Missing** | `LoneWorkerController::index` | `when($q, where worker.name LIKE / client / activity_description / location)` |
| B7 | "Ending <1h" subset count | **Missing** | `LoneWorkerController::index` | active where `expected_end_at` between now and now+1h |
| B8 | Paginated alerts when `tab=alerts` | **Partial** (today = hand-merged unpaginated collection, `:75-98`) | `LoneWorkerController::index` | paginate canonical `ControlRoomAlert` (source=`lone_worker`) with own page param when `tab=alerts`; keep small merged "recent" list for sessions tab. Apply period/q |
| B9 | Server-hydrated detail keyed off `?session=` / `?alert=` | **Missing** | `LoneWorkerController::index` (+ `detail` prop) | when `?session={id}`: load `LoneWorkerSession` with `checkIns` (desc), `alerts`, `user/site/client/shift` → `detail`. When `?alert={id}`: resolve `cr_`→`ControlRoomAlert`, `legacy_`→`LoneWorkerAlert` (handle id prefixes from `mapCanonicalAlert`/`mapLegacyAlert`) |
| B10 | `can` object (`{manage, view}`) instead of flat `can_manage` | **Partial** (`can_manage` bool `:116`) | `LoneWorkerController::index`; detail builder | emit `can: { manage: canDo('hazards.manage'), view: canDo('hazards.view') }`; keep `can_manage` only if other code needs it |
| B11 | Sessions register (paginate 25, client flattened, filters) | **Present** | — | `LoneWorkerController::index :42-58` already paginates 25 + flattens client + `withQueryString` |
| B12 | start / check-in / end / emergency endpoints | **Present** | — | `sessions.store`, `sessions.check-in` (`status in:ok,concern,emergency`), `sessions.end`, `sessions.emergency` (`:272-275`) |
| B13 | `acknowledge` / `resolve` alert (convenience) | **Present** (legacy-only, by design) | — | `alerts.acknowledge` / `alerts.resolve` (`:278-279`) — keep as legacy-row convenience; primary triage = CR deep-link |
| B14 | `startSession` accepts `shift_id` | **Missing** (depends B1) | `LoneWorkerController::startSession :125-135` | add `'shift_id' => ['nullable','exists:shifts,id']` |
| B15 | Default coords from latest `ShiftGpsLog` when a shift is chosen | **Missing** | `LoneWorkerController::startSession` | `ShiftGpsLog::where('shift_id',…)->latest('captured_at')->first()` for lat/lng fallback (`audit/05` A) |
| B16 | Canonical alert pipeline (overdue/overrun/emergency → ControlRoomAlert) | **Present** | — | `LoneWorkerSignalService` + `CheckLoneWorkerOverdueJob` (5-min cron `routes/console.php:154-158`). Visualise only — no new escalation code (`audit/05` E) |
| B17 | Demo seeder content (active/overdue/emergency/completed + check-ins + ≥1 CR alert) | **Missing** | `database/seeders/HealthSafetyDemoSeeder.php` | add lone-worker block so the new UI has data (`audit/02` §10) |
| B18 | Feature tests (index filters/tabCounts/detail, start/check-in/end/emergency/update, overdue job) | **Missing** | `tests/Feature/HealthSafety/LoneWorker*Test.php` (new) | Pest; no existing coverage (`audit/02` §11) |

---

## B. FRONTEND

Today's `index.tsx` is **legacy** (`PageHero`, inline `Dialog` CRUD, raw `Select`). The redesign
**replaces** it by composing the H&S gold-standard kits — same route, same component path
(`health-safety/lone-workers/index`).

| # | Capability design needs | Status | File to touch / create | Reuse target (exact) |
|---|---|---|---|---|
| F1 | Page shell: AppLayout breadcrumbs + `HeroShell` + footer filter bar | **Missing** (legacy `PageHero`) | `resources/js/pages/health-safety/lone-workers/index.tsx` (rewrite) | `HeroShell({children,footer})` from `@/pages/health-safety/components/hs-hero-kit`; structural template `pages/incidents/index.tsx` |
| F2 | WorkflowRibbon at top of hero | **Missing** | index.tsx | `<WorkflowRibbon current="report" />` from `@/pages/health-safety/components/workflow-ribbon` (no lone-worker stage; report front-door) |
| F3 | Medallion + status pill + title + subtitle | **Missing** | index.tsx | `HeroMedallion icon={Radio}`, `HeroStatusPill`, `<h1>`; copy in `audit/01` §1.2 |
| F4 | Reports CTA popover (board reports) | **Missing** | index.tsx | `Popover` mirroring Incidents Reports popover (`report-launcher`/board-reports links), `audit/01` §1.3 |
| F5 | Start session primary CTA → wizard | **Missing** | index.tsx | primary solid `Plus` button opening the Start-session wizard (F12) |
| F6 | Two `HeroCluster`s × 4 `HeroClusterTile` (links to tab/filter) | **Missing** | index.tsx | `HeroCluster`/`HeroClusterTile` (`href` = `?tab=…&status=…`); tile matrix `audit/01` §1.4 |
| F7 | NZ compliance badge row (5 chips, lone-worker-specific) | **Partial** (`HeroComplianceBadges` exists but hard-coded to dashboard's 5) | index.tsx; optionally `hs-hero-kit.tsx` | **Decision: render a LOCAL chip row reusing the kit's `CHIP_CLASS`/`CHIP_ICON` tone classes** (option b in `audit/01` §1.5) — the lone-worker 5-set (workers-checked-in / overdue / emergency / HSWA / after-hours) differs from the module row; do NOT force-fit the fixed component. Feed counts/bools |
| F8 | Hero footer filter bar (Period pill / Site / Status / Search / Clear) | **Missing** | index.tsx | `HeroSegmented variant="pill"` (period), `EntityFilter onDark` (site), small select (status), raw on-dark `<input type="search">` (`ml-auto`), Clear. Drives `router.get(...,{preserveState,preserveScroll})`. `audit/01` §1.6 |
| F9 | Right-click hero → quick actions | **Missing** | index.tsx | `ShiftContextMenu` with QUICK header (Start / View emergencies / Open Control Room / Export). `audit/01` §1.7 |
| F10 | Tabs (Sessions / Alerts) with count badges | **Missing** | index.tsx | `TabStrip` + `RosterTabItem[]` from `@/components/rostering`; `badge: count||undefined`; `audit/01` §2 |
| F11 | Register tables (sessions + alerts) with `RegisterTableHeader`, row left-click=detail, right-click=ctx, ⋮ kebab | **Missing** | index.tsx (+ small row components) | `RegisterTableHeader` (hint "Right-click a row…", `hintIcon={MousePointer2}`), `TONE_BG`/`TONE_DOT`/`initials`/`entityTone`/`FlagBadge` from `register-row-kit`; row pattern `audit/04` A |
| F12 | Single `actionsFor(row)` powering right-click + kebab | **Missing** | index.tsx | one `actionsFor(row): ShiftCtxItem[]` (conditional spreads `satisfies ShiftCtxItem`); gate on `can.manage` + status; menus per `audit/01` §4 |
| F13 | `LaravelPagination` (sessions 25; alerts when `tab=alerts`) | **Missing** | index.tsx | `LaravelPagination` from `@/components/ui/laravel-pagination`; render only when `rows.last_page>1` |
| F14 | Session detail modal (2-col: plan / location / timeline / alert-history + Options bar) | **Missing** | new `resources/js/components/health-safety/lone-worker-detail-dialog.tsx` | wrap `WizardShell` as a section-rail modal (NOT fresh Dialog) per `IncidentDetailDialog` (`audit/04` B); param-driven `?session=` partial reload `only:['detail']`; sections + footer Options bar gated by `can`+status; `audit/01` §5.1 |
| F15 | Alert detail modal (summary + CR banner + Open-in-CR primary + ack/resolve + linked-session) | **Missing** | same dialog file (alert variant) | width min(94vw,460px) single col; foreground **Open in Control Room** → `control-room.alerts.show` (strip `cr_`); do NOT rebuild triage; `audit/01` §5.2 |
| F16 | Start-session wizard (3 steps, from-shift + ad-hoc, validateStep, Save&add another, success pane) | **Missing** | new `resources/js/components/health-safety/lone-worker-wizard.tsx` | `WizardShell`+`WizardStep[]`+`WizardStepPane`+`WizardSuccessPane`+`ReviewCard/Row`+`Ring`; inputs via `wizard/primitives` (`Field`/`SelectInput`/`Segmented`/`StepHead`/`InfoCard`); contract `add-client-dialog.tsx` (`audit/04` C); spec `audit/01` §6 |
| F17 | 6 action modals (check-in / extend / end / emergency / acknowledge / resolve) on shared Dialog chrome | **Missing** | same dialog file (action panes) OR small `lone-worker-action-modal.tsx` | single-screen panes on Dialog chrome (NOT wizards): `StepHead`+`Field`/`Segmented`/`InfoCard`+Cancel/CTA; check-in 3-tile picker (`TilePicker`); POST existing endpoints; partial reload. Matrix `audit/01` §7 |
| F18 | en-NZ timestamps + relative "overdue by Xm" | **Missing** | index.tsx + dialogs | `formatDateTime` / `formatRelative` from `@/lib/datetime` (`audit/03` §9) |
| F19 | No `animate-pulse`, tokens only, app-primary gradient only | **N/A (rule)** | all FE files | semantic tokens; sanctioned `eslint-disable no-restricted-syntax` only for on-dark/bespoke surfaces (`audit/01` §0) |

---

## C. CROSS-MODULE

| # | Capability design needs | Status | File to touch | Reuse / dedup rule |
|---|---|---|---|---|
| X1 | Roster link + wizard Step-1 prefill from chosen shift | **Missing** (B1/B14/B15) | migration + model + controller + wizard | `Shift`/`ShiftGpsLog` data; prefill `user_id/site_id/client_id/expected_end_at(=ends_at)/location/lat-lng(last GPS)`; persist `shift_id`. Link not merge (`audit/05` A) |
| X2 | "Rostered lone shifts NOT yet monitored" worklist + hero KPI | **Missing** | `LoneWorkerController::index` (hero block) | `Shift` where (lone condition) AND clocked-in AND `whereDoesntHave(active loneWorkerSession)` AND `ends_at>=now()`. **Lone/remote derivation** = derive now (`is_on_call`, or solo coverage); a `Shift.is_lone_worker` flag is **out of scope** (`audit/05` A gap 2) |
| X3 | My Day "being-monitored / check-in" card | **Missing** | `resources/js/pages/my-day/index.tsx` + its controller props | render only when signed-in user has active/overdue session; one-tap OK + "I need help" → POST existing `sessions.check-in`. **HARD dedup: NO register/wizard/hero in My Day** (`audit/05` B). Parallel change — need not block coordinator build |
| X4 | Control Room triage deep-link (alert rows + alert detail) | **Present** (canonical) | UI wiring only | `control-room.alerts.show` (strip `cr_`); requires `controlRoom.viewAny` to view. Do NOT rebuild triage (`audit/05` C) |
| X5 | Overdue/emergency detection + escalation | **Present** | — | `CheckLoneWorkerOverdueJob` + CR engine. Visualise only; do NOT add lone-worker rules to `NotificationEscalationRule` / `notifications:escalate` (walled off) (`audit/05` E) |
| X6 | Lone-worker emergency → Incident/HsEvent | **Present** (via CR bridge) | — | `SensorIncidentBridgeService::confirm` / `flagAsIncident` → `ClientIncident` → `HsEvent`. Do NOT add a direct "escalate to incident" button. Optional read-only "Escalated to INC-{id}" chip when `ControlRoomAlert.context['incident_id']` set (`audit/05` Judgement 2) |
| X7 | Queclink / `lone_worker_tracker` GPS panic link | **Missing — OUT OF SCOPE** | — | `LocateNowService` + taxonomy exist but unwired. Note the seam, hide the action (hide-unbuilt rule) (`audit/05` D) |
| X8 | Sidebar entry | **Present** | `resources/js/components/app-sidebar.tsx:1232-1237` | keep label "Lone Worker Safety" / href `/health-safety/lone-workers` / icon `PersonStanding`; `hazards.*` perms. No churn (`audit/05` F) |

---

## DUPLICATION GUARD — reuse, never rebuild

1. **UI kits (compose, don't reinvent any primitive):**
   - Hero chrome → `hs-hero-kit.tsx` (`HeroShell`/`HeroMedallion`/`HeroStatusPill`/`HeroCluster`/`HeroClusterTile`/`HeroSegmented`/`fmt`).
   - Breadcrumb spine → `workflow-ribbon.tsx` (`current="report"`).
   - Row helpers → `register-row-kit.tsx` (`TONE_BG`/`TONE_DOT`/`initials`/`entityTone`/`FlagBadge`/`RegisterTableHeader`).
   - Tabs / filters / context menu → `@/components/rostering` (`TabStrip`/`EntityFilter`/`ShiftContextMenu`).
   - Wizard + modals → `@/components/wizard/shell` + `@/components/wizard/primitives` (the Add-client contract).
   - Pagination → `@/components/ui/laravel-pagination`. Dates → `@/lib/datetime`.
   - **Structural copy target = `pages/incidents/index.tsx` + `incident-detail-dialog.tsx` + `add-client-dialog.tsx`.** Do not hand-roll hero/table/modal chrome.
2. **Existing endpoints — reuse, only ADD `sessions.update`:** `sessions.store`, `sessions.check-in`, `sessions.end`, `sessions.emergency`, `alerts.acknowledge`, `alerts.resolve` all exist. The redesign adds exactly **one** route (`PATCH …sessions.update`) and **one** column (`shift_id`). Two server touch-points only (`audit/05` net guidance).
3. **My Day worker flow — do NOT duplicate.** Worker check-in is a single tap in `my-day/index.tsx` POSTing to the same `sessions.check-in`. The coordinator page is the watch-tower; My Day is the worker cockpit. Never put the register / wizard / hero clusters in front of workers (`audit/05` B, HARD rule).
4. **Control Room triage — do NOT rebuild.** No SLA / escalation / playbooks / assign on this page. Alert rows + alert detail **deep-link** to `control-room.alerts.show`. Ack/resolve here stay labelled "convenience action". `ControlRoomAlert (source='lone_worker')` is the operational source of truth (`audit/05` C, `audit/02` §9).
5. **Escalation + incident bridge — already canonical.** No new escalation code; no direct incident-create button. Reuse `CheckLoneWorkerOverdueJob` + CR engine + `SensorIncidentBridgeService` (`audit/05` E + Judgement 2).
6. **Roster — link, not merge.** Add nullable `shift_id`; never fold `Shift` into `LoneWorkerSession` (`ShiftSafetyInvariantService` / payroll lock untouched) (`audit/05` A).

---

## MODAL INVENTORY

Every modal, its fields, chrome type, and the premium-document-upload decision.

**Premium upload (`file-dropzone.tsx`) decision — GLOBAL: NO, for every modal.** Justification
(`audit/05` Judgement 1): there is **no `lone_worker_attachments` table** and no documents column
on any of the 3 lone-worker tables; a session is a **real-time presence/safety overlay**
(start → check-in → overdue → emergency → end, measured in minutes), not a document-bearing
record like an Incident / Safeguarding concern / Site. If an emergency becomes an Incident,
evidence lives on that `ClientIncident` (which already has the attachments pipeline) — the correct
single home. Adding upload here would split evidence across two records and require a new table +
controller + storage with zero domain demand. Default is NO and **no modal overrides it.**

| Modal | Chrome type | Fields | Premium upload? | Why |
|---|---|---|---|---|
| **Start-session wizard** | Add-client-style **wizard** (`WizardShell`, 3 steps, 248px rail, Ring, Save&add another, success pane) | **Step 1 "Choose the shift":** mode toggle `[From shift \| Ad-hoc]`; (shift) shift-tile list → prefills; (adhoc) Worker* select, Site select, Client select; shared Location text, Latitude, Longitude. **Step 2 "Monitoring plan":** Expected end* `datetime-local` (after:now), Check-in interval `[15/30/60/120]`, Activity textarea. **Step 3 "Review & start":** 2 ReviewCards (Worker&location / Monitoring plan) + Ring | **NO** | Operational presence setup; coordinates default from `ShiftGpsLog`, not a file. No artefact to attach |
| **Session detail** | param-driven **section-rail modal** (`WizardShell` as detail chrome, like `IncidentDetailDialog`) + footer Options bar | Read-only: Monitoring plan rows, Last-known location (map placeholder + Open map), Check-in timeline, Alert history. Options bar (gated): Record check-in / Extend-edit / End / Trigger emergency | **NO** | Read/act surface; lifecycle is timestamps not documents |
| **Alert detail** | param-driven **single-screen action modal** (same Dialog chrome) | Read-only summary rows; CR info banner; **Open in Control Room** (primary, foregrounded); Acknowledge + Resolve row; View linked session | **NO** | Triage + evidence live in Control Room / the linked Incident |
| **Check-in** | **single-screen action modal** (Dialog chrome, NOT wizard) | Worker status* 3-tile picker `[OK \| Concern \| Emergency]` (`TilePicker`); Notes (optional) textarea | **NO** | Status + timestamp only |
| **Extend / edit session** | single-screen action modal | New expected end `datetime-local`; Check-in interval `Segmented [15/30/60/120]` | **NO** | Schedule fields only (→ `sessions.update`) |
| **End session** | single-screen action modal | Confirm `InfoCard` (warning) only | **NO** | Confirmation, no input |
| **Trigger emergency** | single-screen action modal | Confirm `InfoCard` (critical) only; CTA critical-toned `Phone` | **NO** | Confirmation; notifies contacts + CR |
| **Acknowledge alert** | single-screen action modal | Notes (optional) textarea | **NO** | Convenience note only |
| **Resolve alert** | single-screen action modal | Resolution notes textarea; CTA critical-toned | **NO** | Convenience note only |

Chrome-type summary: **1 Add-client-style wizard** (Start session) + **8 single-screen
action/detail modals on the same Dialog chrome** (session detail, alert detail, and the 6
lifecycle actions). **0 modals use the premium document upload.**
