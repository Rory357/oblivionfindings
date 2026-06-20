# Lone Worker Safety — Build Plan

> **STATUS (2026-06-20, in worktree `frosty-spence-59899d`):**
> ✅ **S1** schema+migration (`shift_id` FK, ran clean) · ✅ **S2** controller+routes
> (index hero/tabs/detail/filters/options/can, `sessions.update`, shift link + GPS prefill) ·
> ✅ **S3** page shell · ✅ **S4** registers + shared `actionsFor` + ShiftContextMenu + pagination ·
> ✅ **S5** session/alert detail dialog · ✅ **S6** Start-session wizard · ✅ **S7** action modals
> (shared `LoneWorkerActionForm` reused in 3 chromes) · ✅ **S9** demo seeder (6 sessions) +
> Pest (`LoneWorkerControllerTest`, **9 passed / 79 assertions**) · **tsc clean**.
> ⏸️ **S8** My Day worker check-in card **DEFERRED** — `sessions.check-in` is `hazards.manage`-gated;
> worker self-check-in needs a permission-model decision (allow session owner). Sidebar verified (no change).
> Premium document upload: **intentionally none** — no attachments domain for a real-time presence overlay
> (evidence lives on the escalated Incident). 🔄 **S10** verify → merge → deploy → Chrome-verify next.

# Lone Worker Safety — Build Plan

Ordered, checkbox step plan to rebuild `/health-safety/lone-workers` to the H&S gold standard.
Pairs with `GAP_ANALYSIS.md`. Compose the existing kits; **two server touch-points only**
(`shift_id` column + `sessions.update` route) — everything else is reuse.

**Global constraints (every step):**
- **NZ / web-only:** en-NZ dates via `@/lib/datetime` (`formatDateTime`/`formatRelative`, never
  en-GB), NZD, HSWA 2015 / WorkSafe / ACC framing. **No mobile frames** — desktop full-width.
- **Keep route names + URLs stable** (`/health-safety/lone-workers`,
  `health-safety.lone-workers.*`, component path `health-safety/lone-workers/index`,
  sidebar href/label/icon) so deep links + bookmarks + the sidebar keep working.
- **Tokens only / app-primary gradient only**; no `animate-pulse`; sanctioned
  `eslint-disable no-restricted-syntax` only for on-dark / bespoke modal surfaces.
- **Dedup (HARD):** no worker check-in UI here (My Day owns it); no Control Room triage rebuild
  (deep-link only); no direct incident-create (CR bridge owns it); link Shift, never merge.
- **Migrations:** run locally + autonomously (per project policy).
- ⚠️ Worktree: backend PHP tests autoload the **parent** app — verify backend by merging then
  testing in the parent repo; migrations + frontend DO use the worktree. Run `artisan test`
  with cwd = worktree only for migration checks.

---

## S1 — Schema + migration
- [ ] **Create migration** adding `shift_id` to `lone_worker_sessions`:
  `$table->unsignedBigInteger('shift_id')->nullable()->after('client_id')`; FK→`shifts`
  `nullOnDelete`; add index. (Confirm `shifts` table/PK before writing.) — *new file
  `database/migrations/xxxx_add_shift_id_to_lone_worker_sessions.php`*
- [ ] **Model `LoneWorkerSession`**: add `shift_id` to `$fillable`; add `shift()` BelongsTo. —
  *`app/Models/LoneWorkerSession.php`*
- [ ] **Model `Shift`**: add `loneWorkerSession()` hasOne (for `whereDoesntHave` + reverse link).
  — *`app/Models/Shift.php`*
- [ ] Run migration locally; confirm clean. **Reuse:** `ShiftGpsLog` (latest ping) read at
  S2/S6, not stored here. **No** new attachments table (GAP X7/Judgement 1).

## S2 — Controller + routes
- [ ] **Add route** `PATCH /sessions/{session}` → `updateSession`, name `…sessions.update`,
  `permission:hazards.manage`. Keep every existing route/name. — *`routes/health-safety.php:271`*
- [ ] **`updateSession(Request,$session)`**: validate `expected_end_at` (date, relaxed/no
  `after:now`), `check_in_interval_minutes` (15-480), `activity_description`, `location`,
  lat/lng; gate `$session->isActive()||isOverdue()`; set `updated_by`; clear `overdue→active` on
  extend. — *`LoneWorkerController`*
- [ ] **`startSession`**: add `'shift_id'=>['nullable','exists:shifts,id']`; when `shift_id`
  present and lat/lng empty, default from `ShiftGpsLog::where('shift_id',…)->latest('captured_at')`.
  — *`LoneWorkerController:125-152`*
- [ ] **`index` — extend payload (keep `sessions` paginate 25):**
  - [ ] read `tab` (default `sessions`), `period` (today/week/30d, default today), `q`; add to
    `filters` + `withQueryString()`.
  - [ ] apply `period` over `started_at` + `q` over worker/client/activity/location to sessions
    (and alerts).
  - [ ] **`tabCounts`** `{ sessions: active+overdue+emergency, alerts: unresolved canonical }`.
  - [ ] **`hero`** block: Cluster A (active / overdue / emergency / ending-<1h) + Cluster B
    (alerts-today / awaiting-ack / unresolved / no-recent-checkin) + badge counts
    (checkedIn, activeTotal, overdue, emergency, HSWA-bool, after-hours-bool). **Counts/bools
    only.**
  - [ ] **"lone shifts not monitored"** KPI: `Shift` (lone derivation: `is_on_call` or solo
    coverage) AND clocked-in AND `whereDoesntHave(active loneWorkerSession)` AND `ends_at>=now()`.
  - [ ] **alerts paginator** when `tab=alerts` (paginate canonical `ControlRoomAlert`
    source=`lone_worker`, own page param); keep merged recent list for sessions tab.
  - [ ] **`detail`** prop when `?session={id}` (load `checkIns` desc + `alerts` + `user/site/client/shift`)
    or `?alert={id}` (resolve `cr_`→`ControlRoomAlert`, `legacy_`→`LoneWorkerAlert`).
  - [ ] **`can`** `{manage,view}` from `hazards.manage`/`hazards.view`.
  - **Reuse:** `mapCanonicalAlert`/`mapLegacyAlert` (id prefixes), `LoneWorkerSignalService` (no
    new escalation).

## S3 — Page shell
- [ ] **Rewrite** `index.tsx`: `AppLayout` breadcrumbs `[Health & Safety → Lone workers]` + `<Head>`;
  outer `div.flex.flex-col.gap-6.p-6`. Drop legacy `PageHero` + inline `Dialog`s. — *rewrite
  `resources/js/pages/health-safety/lone-workers/index.tsx`*
- [ ] **HeroShell** (footer = filter bar) containing, in order: `<WorkflowRibbon current="report"/>`;
  medallion (`Radio`)+`HeroStatusPill`+`<h1>`+`<p>`; Reports popover CTA + Start-session primary
  CTA; two `<HeroCluster>`×4 `<HeroClusterTile>` (`href` = `?tab=…&status=…`); local 5-chip NZ
  badge row (reuse `register-row-kit` tone classes — see GAP F7).
- [ ] **Footer filter bar:** `HeroSegmented variant="pill"` (period) · `EntityFilter onDark`
  (site) · status select · on-dark `<input type="search"> ml-auto` · Clear (when filtered). All
  → `router.get(url,{...filters,...},{preserveState,preserveScroll})`.
- [ ] **Inertia helpers:** `go(next)`, `setTab(id)`, `openDetail(id)`/`openAlert(id)`
  (`only:['detail']`), `closeDetail()`, `clearFilters()` — copy `pages/incidents/index.tsx:237-260`.
  **Reuse:** `hs-hero-kit`, `workflow-ribbon`.

## S4 — Registers + shared `actionsFor` + ShiftContextMenu + pagination
- [ ] **TabStrip** `[Sessions (Radio, info), Alerts (Bell, critical)]` with `badge:count||undefined`.
  — *index.tsx; `@/components/rostering`*
- [ ] **Sessions table** in `Card>CardContent.p-0`→`overflow-x-auto`→`<table>`:
  `RegisterTableHeader` (icon `Radio`, hint "Right-click a row…", `hintIcon={MousePointer2}`);
  columns Worker(avatar+location) / Site·Client / Started / Expected end / Last check-in
  (+overdue-by) / Status pill / ⋮. Rows: `tabIndex=0`, `onClick→openDetail`,
  `onContextMenu→openRowCtx`, Enter/Space, `cursor-pointer hover:bg-muted/55`. **Reuse**
  `TONE_BG`/`TONE_DOT`/`initials`/`entityTone`, `formatDateTime`/`formatRelative`.
- [ ] **Alerts table:** columns Worker / Site·Client / Type pill / Triggered / Status / Source
  (`FlagBadge` control_room/legacy) / ⋮.
- [ ] **`actionsFor(row): ShiftCtxItem[]`** — ONE builder feeding right-click + ⋮ kebab;
  conditional spreads `satisfies ShiftCtxItem`; gate mutating items on `can.manage`+status;
  every mutating item opens a MODAL. Menus per `audit/01` §4. — *index.tsx*
- [ ] **Right-click hero** quick-actions menu (QUICK header). **`LaravelPagination`** rendered
  when `rows.last_page>1` (sessions; alerts when `tab=alerts`). Empty-state blocks per tab.
  **Reuse:** `ShiftContextMenu`, `LaravelPagination`.

## S5 — Session / alert detail modal
- [ ] **Create** `resources/js/components/health-safety/lone-worker-detail-dialog.tsx`. Wrap
  `WizardShell` as a section-rail modal (NOT a fresh Dialog) per `IncidentDetailDialog`
  (`audit/04` B). No internal open-state — mount when `detail` present; `openDetail` adds
  `?session=`/`?alert=` with `only:['detail']`, `closeDetail` drops it.
- [ ] **Session variant** (min(94vw,840px), 2-col): LEFT Monitoring plan (`ReviewCard`/`ReviewRow`)
  + Last-known location (map placeholder + Open map using lat/lng); RIGHT Check-in timeline
  (`<ol>` dot markers by kind) + Alert history (clickable → `openAlert`).
- [ ] **Footer Options bar** (gated `canAct = active||overdue`): Record check-in / Extend-edit /
  End / Trigger emergency → open the matching action pane (S7). Null while a pane is active.
- [ ] **Alert variant** (min(94vw,460px), single col): summary rows + CR info banner +
  **Open in Control Room** primary (strip `cr_`, `control-room.alerts.show`) + Acknowledge/Resolve
  row + View-linked-session. **Do NOT rebuild triage.**
  **Reuse:** `WizardShell`/`ReviewCard`/`ReviewRow`, `wizard/primitives`, `InfoCard`. Submit
  pattern: gate `onDone()` on `!page.props.flash?.error` (302+flash, not 422).

## S6 — Start-session wizard
- [ ] **Create** `resources/js/components/health-safety/lone-worker-wizard.tsx` using
  `WizardShell`+`WizardStep[]`+`WizardStepPane`+`WizardSuccessPane`+`ReviewCard/Row`+`Ring`;
  `useForm` holds all fields `{shift_id,user_id,site_id,client_id,location,lat,lng,
  expected_end_at,interval,activity}`; `wizMode='shift'|'adhoc'`. — *`audit/01` §6 / `audit/04` C*
- [ ] **Step 1 "Choose the shift":** mode `Segmented`; (shift) shift-tile list → `selectShift`
  prefills user/site/client/expected_end/location; (adhoc) amber `InfoCard` + Worker* / Site /
  Client selects; shared Location / Latitude / Longitude + primary `InfoCard` (coords default
  from `ShiftGpsLog`).
- [ ] **Step 2 "Monitoring plan":** Expected end* `datetime-local` (client validate required +
  after:now) · interval `Segmented [15/30/60/120]` · Activity textarea.
- [ ] **Step 3 "Review & start":** `Ring pct` + 2 `ReviewCard`s with Edit→jump-to-step.
- [ ] **validateStep** mirrors `startSession`; `onError` jumps to failing step
  (`e.user_id?0:1`). **Save & add another** POSTs `stay=1`; submit → `useForm.post(route(
  'health-safety.lone-workers.sessions.store'))`. Success pane: "Session started" + View/Done.
  **Reuse:** `wizard/shell` + `wizard/primitives`.

## S7 — Action modals (on wizard/Dialog chrome, single-screen)
- [ ] Build the 6 action panes (in the detail dialog file or a small
  `lone-worker-action-modal.tsx`): **check-in** (3-tile `TilePicker` `[OK/Concern/Emergency]` +
  Notes), **extend** (new expected end + interval `Segmented`), **end** (warning `InfoCard`),
  **emergency** (critical `InfoCard`, CTA `Phone`), **acknowledge** (Notes), **resolve**
  (Resolution notes). Each = thin context header + `StepHead` body + Cancel/CTA footer. — *`audit/01` §7*
- [ ] POST to existing endpoints (`sessions.check-in`/`sessions.update`/`sessions.end`/
  `sessions.emergency`/`alerts.acknowledge`/`alerts.resolve`); `preserveScroll`, refresh in place;
  toasts per `audit/01` §7. Gate `onDone()` on `!flash?.error`. **Reuse:** `wizard/primitives`,
  `Segmented`/`TilePicker`/`InfoCard`.

## S8 — Cross-module wiring
- [ ] **Shift link surfaced:** session detail shows linked-shift row; "lone shifts not monitored"
  hero KPI live (S2). — *index.tsx / detail dialog*
- [ ] **My Day check-in card** (parallel, need not block): render only when signed-in user has an
  active/overdue session; one-tap OK + "I need help" → POST `sessions.check-in`; expose a
  `props` field for the worker's active session; auto-end on shift clock-out. **NO register/
  wizard/hero in My Day.** — *`resources/js/pages/my-day/index.tsx` + controller*
- [ ] **Sidebar:** verify entry unchanged (label/href/icon `PersonStanding`). — *`app-sidebar.tsx:1232-1237`*
- [ ] **Optional read-only** "Escalated to INC-{id}" chip on detail when
  `ControlRoomAlert.context['incident_id']` set. Hide Queclink "Locate now" (unbuilt).
  **Reuse:** CR bridge / `LocateNowService` (not wired here).

## S9 — Tests
- [ ] **Backend Pest** `tests/Feature/HealthSafety/LoneWorker*Test.php`: index filters
  (period/q/tab) + tabCounts + hero + detail hydration (`?session=`/`?alert=` incl. `cr_`/`legacy_`);
  start (with/without `shift_id` + GPS prefill); check-in (ok/concern/emergency → signal); end;
  emergency; **update** (gating + extend clears overdue); overdue job flips status + emits.
- [ ] **Demo seeder** block (active/overdue/emergency/completed + check-ins + ≥1 CR alert). —
  *`database/seeders/HealthSafetyDemoSeeder.php`*
- [ ] **Frontend:** `tsc` typecheck + `vite build` clean (wayfinder may need
  `php84 -d memory_limit=1024M`). Keep new files pint-clean; don't pint shared files.
  ⚠️ Run backend tests in the **parent** repo after merge (junction-vendor autoloads parent app).

## S10 — Verify / merge / deploy
- [ ] Self-review diff; confirm zero raw colours, en-NZ dates, no mobile frames, route names/URLs
  unchanged, no triage/worker-UI duplication.
- [ ] Merge branch → origin/main (`--no-ff`); deploy webhook (~5-8 min; migration runs on deploy).
- [ ] **Chrome-verify LIVE on .com** as Demo Admin: hero (clusters/badges/period/site/search),
  tabs + both registers, row left-click → detail modal (session 2-col + Options bar; alert →
  Open-in-CR), right-click + ⋮ menus, Start-session wizard (shift + ad-hoc, Save&add another,
  success), all 6 action modals, deep-link `?session=`/`?alert=`, **0 app console errors**.
- [ ] Note deferred items (My Day card if split; Queclink Phase-2; `Shift.is_lone_worker` flag).

---

### File manifest (create ✚ / edit ✎)
- ✚ `database/migrations/xxxx_add_shift_id_to_lone_worker_sessions.php` (S1)
- ✎ `app/Models/LoneWorkerSession.php`, `app/Models/Shift.php` (S1)
- ✎ `routes/health-safety.php` (S2)
- ✎ `app/Http/Controllers/HealthSafety/LoneWorkerController.php` (S2)
- ✎ `resources/js/pages/health-safety/lone-workers/index.tsx` (S3/S4/S8 — rewrite)
- ✚ `resources/js/components/health-safety/lone-worker-detail-dialog.tsx` (S5/S7)
- ✚ `resources/js/components/health-safety/lone-worker-wizard.tsx` (S6)
- ✚ (optional) `resources/js/components/health-safety/lone-worker-action-modal.tsx` (S7)
- ✎ `resources/js/pages/my-day/index.tsx` + its controller (S8 — parallel)
- ✎ `database/seeders/HealthSafetyDemoSeeder.php` (S9)
- ✚ `tests/Feature/HealthSafety/LoneWorker*Test.php` (S9)
- ✎ `resources/js/components/app-sidebar.tsx` (S8 — verify only, expect no change)

### Reuse targets (never rebuild)
`hs-hero-kit.tsx` · `workflow-ribbon.tsx` · `register-row-kit.tsx` · `@/components/rostering`
(`TabStrip`/`EntityFilter`/`ShiftContextMenu`) · `@/components/wizard/shell` + `primitives` ·
`@/components/ui/laravel-pagination` · `@/lib/datetime` · structural copy
`pages/incidents/index.tsx` + `incident-detail-dialog.tsx` + `add-client-dialog.tsx` ·
endpoints `sessions.store/check-in/end/emergency` + `alerts.acknowledge/resolve` ·
`control-room.alerts.show` · `LoneWorkerSignalService` + `CheckLoneWorkerOverdueJob` +
`SensorIncidentBridgeService` · My Day `sessions.check-in`.
