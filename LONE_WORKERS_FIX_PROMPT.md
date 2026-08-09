# Lone Worker Safety — Standardisation + Feature-Complete Prompt

**Paste this whole file into Claude Design.** Goal: rebuild
`/health-safety/lone-workers` to the Health & Safety **gold standard** so it reads as the same
product as `/health-safety`, `/incidents` and `/health-safety/analytics` — and make every
create/manage workflow a **modal modelled on the client "Add client" wizard**, with **right-click
options on every row** like the Clients page. Then run your own audit and record the backend work in
handover docs.

NZ-only. Web-only. Don't reinvent primitives — compose the kits below.

---

## 0. Read these first (match them, don't reinvent)

**Gold-standard pages to mirror exactly (the three the brief names):**
- `resources/js/pages/incidents/index.tsx` ← **the closest structural analogue. Copy its skeleton:**
  HeroShell → WorkflowRibbon → EntityFilter/HeroSegmented footer → TabStrip → register table with
  left-click→detail-modal + right-click→ShiftContextMenu → LaravelPagination.
- `resources/js/pages/health-safety/dashboard.tsx` ← hub hero (`CommandCentreHero`) + `TabStrip`.
- `resources/js/pages/health-safety/analytics.tsx` ← `HeroShell` + `HeroComplianceBadges` + `HeroSegmented` footer.

**Workflow + right-click pattern to mirror (the brief's explicit ask):**
- **Add/modal wizard:** `resources/js/components/clients/add-client-dialog.tsx`
  (re-exported as `resources/js/pages/operations/clients/_create-dialog.tsx`). This is the canonical
  modal: full-height `Dialog`, left **stepper rail**, per-step bodies, a **completeness meter**, a top
  progress bar, a sticky footer (Back / Cancel / Continue → on the Review step: **Save & add another**
  + Create), client-side `validateStep` that mirrors the FormRequest, Inertia `useForm.post(...)` with
  `preserveScroll`/`preserveState` and `onError` jumping to the step that failed, and a **SuccessPane**.
- **Right-click:** `resources/js/pages/operations/clients/index.tsx`. Copy its idiom:
  a single shared `actionsFor(entity): MenuItem[]` list that powers **both** the row's three-dot menu
  **and** the right-click menu; row has `onContextMenu={(e)=>onContext(e,entity)}`; the menu is
  cursor-anchored with viewport-edge clamping and closes on Escape/scroll/outside-click; every item is
  permission-gated and opens a modal (never a bare navigation).

**Shared kits you MUST compose (verified — these files/exports exist):**
- Hero: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges,
  HeroSegmented, HeroSummaryStrip, HeroSummaryMetric, fmt, DOT_CLASS, type Tone`
- Rows: `resources/js/pages/health-safety/components/register-row-kit.tsx`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone, type Tone`
- Right-click + tabs + filter: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem,
  type RosterTabTone, EntityFilter, type EntityFilterOption`
- Wizard chrome (for the modals): `@/components/wizard/shell`
  → `WizardShell, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow, type WizardStep`
  and `@/components/wizard/primitives`
  → `Field, FieldErr, SelectInput, Segmented, ChipMulti, TilePicker, StepHead, SubHead, InfoCard, Ring`
- Workflow ribbon: `resources/js/pages/health-safety/components/workflow-ribbon.tsx` → `WorkflowRibbon`
- Detail-as-modal reference: `@/components/incidents/incident-detail-dialog`
  (`IncidentDetailDialog`, `type IncidentDetail`).

**House rules (from the gold-standard file headers — non-negotiable):**
- **Semantic tokens only.** No raw oklch / hex / `border-l-red-600` / `bg-amber-500`. Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` maps.
- App-primary gradient only on the hero. No per-site brand tint.
- **NZ-only.** HSWA 2015 lone/remote-worker duties, WorkSafe, ACC, **en-NZ dates**, NZD. Don't "fix" to GBP/US.
- **Web-only.** No phone frames, no mobile-app treatments (a native worker check-in app comes later).
- Reuse the sanctioned `eslint-disable no-restricted-syntax` header comments from the kits for on-dark
  hero buttons / bespoke modal surfaces — don't add new raw-colour violations.

---

## 1. What this is (CONFIRMED by audit)

Lone Worker Safety is the live-monitoring register for staff working alone/remotely. One controller
drives everything: `app/Http/Controllers/HealthSafety/LoneWorkerController.php` (366 lines), rendering
`resources/js/pages/health-safety/lone-workers/index.tsx` (**1,003 lines, one file**).

There is **no tab structure today** — the page is a single "Active Sessions" table plus a "Recent
Alerts" card grid. It spans **two entities**:

| Entity | Backing model(s) | Existing actions (all ad-hoc `<Dialog>` forms today) |
|---|---|---|
| **Sessions** | `LoneWorkerSession` (+ `LoneWorkerCheckIn` children) | start session, record check-in, end session, trigger emergency |
| **Alerts** | `ControlRoomAlert` (canonical) + `LoneWorkerAlert` (legacy) | acknowledge, resolve |

Existing endpoints (keep these — workflows POST to them; routes in `routes/health-safety.php`
lines ~265–280, prefix `lone-workers`, names `lone-workers.*`; GET gated `permission:hazards.view`,
all writes gated `permission:hazards.manage`):
`sessions.store` (startSession), `sessions.check-in`, `sessions.end`, `sessions.emergency`,
`alerts.acknowledge`, `alerts.resolve`.

**⚠️ Critical architecture nuance — alerts are owned by the Control Room, not this page.**
Per the controller's own header (PR4): operational lone-worker alerts flow
`LoneWorkerSignalService → SignalProcessingService → ControlRoomAlert`. **`ControlRoomAlert`
(source=`lone_worker`) is the operational source of truth**; `LoneWorkerAlert` is a **legacy
compatibility model** kept only for pre-PR4 history. The acknowledge/resolve actions on this
controller are **convenience actions for the H&S view only** — SLA, escalation and playbooks live in
the Control Room. So the redesign must **deep-link alerts to the Control Room**
(`control-room.alerts.show`, route exists) as the primary operational action and treat
acknowledge/resolve as secondary convenience. **Do NOT build a parallel alert-triage surface.**

**Touchpoints elsewhere in the codebase (audit these too):**
- Left-nav entry: `resources/js/components/app-sidebar.tsx` (~line 1234, "Lone Worker Safety" → `/health-safety/lone-workers`) — confirm it sits correctly in the H&S workflow flyout and is permission-gated.
- Background job: `app/Jobs/CheckLoneWorkerOverdueJob.php`, scheduled in `routes/console.php` — flips sessions to `overdue` and emits overdue signals; the hero/badges must reflect what it produces.
- Signal pipeline: `app/Services/HealthSafety/LoneWorkerSignalService.php` (`emitEmergency` etc.) → Control Room.
- Models: `LoneWorkerSession` (status lifecycle: `active → overdue → emergency → completed`; soft-deletes; `AuditableChanges`; has `location_lat`/`location_lng`), `LoneWorkerCheckIn`, `LoneWorkerAlert` (legacy).

---

## 2. Audit — gaps to fix (🔴 breaks consistency / feature gap · 🟠 polish)

**Page chrome — `lone-workers/index.tsx`**
1. 🔴 Uses generic `PageHero`, **not** `HeroShell` + hs-hero-kit. No status pill, no medallion, no
   `HeroCluster` tiles, no NZ `HeroComplianceBadges`, no `WorkflowRibbon`. Stats are 4 flat numbers.
   → Rebuild the hero to match Incidents/Analytics.
2. 🔴 **No tabs.** Sessions live in a `<table>`, alerts in a `<Card>` grid — two different visual
   languages on one page. → Introduce `TabStrip` (**Sessions / Alerts**) driven by
   `router.get(..., { preserveState, preserveScroll })` with server counts.
3. 🔴 **No right-click anywhere.** No `ShiftContextMenu`, no `onContextMenu`. Session row actions are
   three inline buttons (Check In / End / Emergency); alert actions are inline buttons on cards.
   → Add right-click to every session row and every alert row using the Clients idiom.
4. 🔴 **Alerts are cards, not a register.** → Re-express as a `RegisterTableHeader` table with
   tone dots + `FlagBadge`, matching Incidents.
5. 🔴 **6 ad-hoc `<Dialog>` forms** hand-rolled inline (start, check-in, end, emergency, acknowledge,
   resolve). None use the wizard chrome; the "Start Session" create flow is a flat one-screen form.
   → Standardise onto the wizard pattern (see §3.6).
6. 🔴 No **detail-as-modal**. You can't open a session into a focused panel to see its check-in
   timeline (`LoneWorkerCheckIn`), location, and alert history. → Add a session detail dialog (§3.5).
7. 🔴 **`en-GB` date formatting** (`fmtDate`/`fmtDateTime`, lines ~91 & ~100) violates the NZ-only
   house rule. → Use `en-NZ` via the kit's `fmt` helpers.
8. 🟠 Hand-rolled `sessionStatusColor` / `alertTypeColor` / `alertStatusColor` switch helpers →
   replace with semantic tokens / `TONE_BG` / `TONE_DOT` + `FlagBadge` + `entityTone`.
9. 🟠 Hand-rolled pagination (`sessions.links.map`) → use `LaravelPagination`.
10. 🟠 1,003-line monolith. → Split per entity (e.g. `lone-workers/sessions-tab.tsx`,
    `…/alerts-tab.tsx`, plus a `…/modals/` folder), like Incidents.
11. 🟠 Check-in modal only offers `ok` / `concern`, but the endpoint also accepts `emergency`
    (`checkIn` validation `in:ok,concern,emergency`). → Surface the emergency path consistently.

**Backend — `LoneWorkerController@index`**
12. 🟢 Sessions already `paginate(25)->withQueryString()` with `site_id`/`status`/`user_id` filters — keep.
13. 🔴 No `tabCounts`, no `hero` block (cluster + NZ-badge counts), no `detail` prop, no `can` block.
    → Return all four so the hero/tabs/right-click/detail render from real data (see §6).
14. 🟠 Alerts use `.limit(20)/.limit(10)->get()` then merge canonical+legacy in PHP. → When the
    **Alerts** tab is active, paginate the canonical set server-side; keep legacy as historical tail.
15. 🟠 `can_manage` is gated on `hazards.manage` (lone workers piggybacks on the Hazards permission).
    → Keep as-is unless product wants a dedicated `lone-workers.view/manage`; flag the decision in the handover.
16. 🟠 Honour `?tab=`, `period`, and `q` server-side in addition to the existing `site_id`/`status`/`user_id`.

**Feature gaps found during audit (flag as NEW backend work)**
17. 🔴 **No "extend / edit session" endpoint.** An overdue session can only be checked-in or ended —
    there's no way to push out `expected_end_at` or fix the check-in interval. → Propose
    `sessions.update` (extend / edit) under `hazards.manage`.
18. 🟠 **Geolocation is captured but never surfaced.** `startSession`/`checkIn` validate
    `location_lat`/`location_lng` and the model casts them, but the UI never sends or shows them.
    → Capture optional coordinates in the Start wizard + check-in, and show last-known location in the
    session detail (a static map link is fine — web-only; note the self-hosted Nominatim
    reverse-geocoding plan in `docs/self-hosted-nominatim-reverse-geocoding-plan.md`).

---

## 3. Target spec — `/health-safety/lone-workers`

Structure it **exactly** like `incidents/index.tsx`.

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon` at the top (plan → monitor → check-in → escalate/close).
- `HeroMedallion icon={Radio}` (or `ShieldAlert`), `HeroStatusPill` ("Lone worker monitoring · live"),
  `h1` "Lone Worker Safety", one-line description.
- Top-right: a **Board reports / export** `Popover` CTA, consistent with the other H&S pages, plus the
  primary **Start session** button (opens the wizard).
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile `href` → the matching tab/filter:
  - *Live monitoring* → Active sessions, Overdue check-ins, Emergencies (unresolved), Ending within 1h.
  - *Alerts & response* → Alerts today, Awaiting acknowledgement, Unresolved (Control Room), Sessions without a recent check-in.
- **`HeroComplianceBadges`** NZ chip row (e.g. "all active workers checked in", "no overdue check-ins",
  "emergencies cleared") — feed it counts/booleans from the controller, never pre-formatted strings.
- **Hero footer = filter bar** (`HeroShell footer={…}`): `HeroSegmented` period · `EntityFilter` Site ·
  status select · worker select · right-aligned search · Clear. All drive `router.get`.
- **Right-click on the hero** (`onContextMenu` → `ShiftContextMenu`): quick actions — *Start session*,
  *View emergencies*, *Open Control Room*, *Export* — using the same `ShiftCtxItem[]` machinery as rows.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`: **Sessions / Alerts**, each with a `badge` from server `tabCounts`
(e.g. Sessions = active+overdue+emergency; Alerts = unresolved). Changing tab →
`router.get(..., { preserveState, preserveScroll })`. (If your audit shows check-in volume warrants it,
a third **Check-in log** tab is optional — otherwise the timeline lives in the session detail modal.)

### 3.3 Tables + rows (per tab)
- `RegisterTableHeader` with hint **"Right-click a row for the full list of actions"** + `MousePointer2`.
- Columns per entity:
  - **Sessions:** Worker (+ location) · Site / Client · Started · Expected end · Last check-in
    (+ "overdue by Xm") · Status (tone dot).
  - **Alerts:** Worker · Site/Client · Type (emergency / overdue check-in / no response) · Triggered ·
    Status (active / acknowledged / resolved) · Source (Control Room vs legacy `FlagBadge`).
- Each `<tr>`: `onClick → openDetail(id)` (modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45` + focus ring — copy the Incidents row exactly.
- Tones via `TONE_BG` / `TONE_DOT` + `FlagBadge` (emergency = critical, overdue = warning,
  active = success, completed = muted). No raw colours, no `animate-pulse` hacks.

### 3.4 Right-click menu (`ShiftContextMenu`) — the Clients idiom on the H&S component
Mirror **`actionsFor()` in `operations/clients/index.tsx`**: build one shared `ShiftCtxItem[]` per row,
reused by the row's three-dot menu and the right-click menu. Use the **`ShiftContextMenu`** component
from `@/components/rostering` (so it matches Incidents visually) — do **not** hand-roll a third menu.
Build items contextually and gate each on `can.manage` + current status:
- **Sessions:** View · Record check-in · Extend / edit session · End session · Trigger emergency
  (critical tone) · Open worker profile · Copy link.
  (Check-in / extend / end / emergency only when status is `active` or `overdue`.)
- **Alerts:** View · **Open in Control Room** (primary — deep-link `control-room.alerts.show`) ·
  Acknowledge (convenience) · Resolve (convenience, critical tone) · Open session · Copy link.
Every mutating item opens the relevant **modal** below — never a bare navigation (except the explicit
"Open in Control Room" deep-link, which is the sanctioned hand-off).

### 3.5 Detail-as-modal
- Add a `detail: LoneWorkerDetail | null` prop loaded only when a `?session=` (or `?alert=`) param is
  present (Inertia partial reload, `only: ['detail']`, `preserveState`, `preserveScroll`) — exactly
  like `openDetail()` in Incidents.
- **Session detail** on `WizardShell`/`Dialog` chrome (follow `IncidentDetailDialog`): worker / site /
  client / activity / monitoring plan, a **check-in timeline** (`LoneWorkerCheckIn`), last-known
  location (static map link), and any alert history — with an **Options footer bar** carrying the
  lifecycle actions (check-in / extend / end / emergency). Support an `initialAction` so a right-click
  action (e.g. "Record check-in") opens the modal straight onto that step.
- **Alert detail** can be a thin summary that foregrounds the **Open in Control Room** deep-link; do not
  reimplement Control Room triage here. Closing drops the param so `detail` returns null.

### 3.6 Workflow modals — model the create flow on the Add-client wizard
For the **create** flow — **Start session** — build a stepped wizard that copies `add-client-dialog.tsx`:
- Full-height `Dialog`, left **stepper rail** with per-step status, **completeness meter**, top progress bar.
- Sticky footer: Back / Cancel / Continue; on the Review step **Save & add another** + Create
  (`ReviewCard`/`ReviewRow` for the summary; POST with `stay=1` for "Save & add another", which the
  controller already honours via `$request->boolean('stay')`).
- Client-side `validateStep` mirroring the `startSession` validation rules; `useForm.post('sessions.store')`
  with `preserveScroll`/`preserveState`; `onError` jumps to the failing step; **SuccessPane** on success.
- Suggested steps:
  - *Start session:* **Worker & location** (worker [required], site, client, location text + optional
    lat/lng) → **Monitoring plan** (expected end [required, after:now], check-in interval, activity
    description) → **Review**.
For the smaller **sub-actions** (record check-in, extend/edit, end, trigger emergency, acknowledge,
resolve) use focused single-screen action modals on the same `Dialog` chrome that POST to the existing
endpoints and refresh in place (`preserveScroll`, partial reload). Emergency and Resolve use the
critical tone and an explicit confirm. No full-page navigation anywhere.

---

## 4. Backend changes (`LoneWorkerController` + routes)

Record each of these in the handover docs (§5), then implement:
- **`index()`**: keep the sessions `paginate(25)`; add server-side filters (`period`, `q`) alongside the
  existing `site_id`/`status`/`user_id`; paginate the canonical alerts when `?tab=alerts`; return
  `tabCounts`, a `hero` block (cluster + NZ-badge counts, reading active/overdue/emergency session
  counts and canonical `ControlRoomAlert` counts), a `detail` prop (loaded only when `?session=` /
  `?alert=` is present, eager-loading `checkIns`, `alerts`, `user`/`site`/`client`), and `can: { manage }`.
- **New:** `sessions.update` (extend / edit `expected_end_at`, `check_in_interval_minutes`,
  `activity_description`, `location`) under `permission:hazards.manage` — flag explicitly as a **new**
  endpoint/route. This fills the "overdue session can't be extended" gap (§2.17).
- Keep the existing `startSession/checkIn/endSession/triggerEmergency/acknowledgeAlert/resolveAlert`
  signatures and route names (deep links must keep working); they already `return back()`, which is
  partial-reload friendly.
- **Respect canonical ownership:** alert acknowledge/resolve stay convenience-only and the UI deep-links
  to `control-room.alerts.show`. Do **not** add new alert-triage logic here.
- Surface geolocation: accept the already-validated `location_lat`/`location_lng` from the Start wizard
  and check-in modal; return them in `detail` for the map link (§2.18).
- Confirm the permission model: keep `hazards.view`/`hazards.manage`, or split to `lone-workers.*` —
  record the decision and wire `can.manage` to whatever is chosen.

---

## 5. Do your own audit + write the handover (REQUIRED)

Before coding, run your own pass and **write it down** under a new `docs/lone-workers-redesign/` folder
(match the precedent in `docs/health-safety-redesign/` and `docs/incidents-redesign/`):
- **`CURRENT_STATE_AUDIT.md`** — what the page/sessions/alerts/dialogs do today, file/line references,
  and the off-pattern list from §2 (correct or extend it with anything you find).
- **`BACKEND_AUDIT.md`** — models (`LoneWorkerSession`, `LoneWorkerCheckIn`, `LoneWorkerAlert`),
  the canonical `ControlRoomAlert` pipeline + `LoneWorkerSignalService`, the `CheckLoneWorkerOverdueJob`
  schedule, every route/endpoint, validation rules, the status lifecycle, permissions/policy, and
  **every backend change** the redesign needs (the §4 list plus anything new — incl. the `sessions.update`
  endpoint and geolocation surfacing) with proposed signatures, migrations, and risk notes. This is the
  engineering handover.
- **`PROGRESS.md`** — phased plan + a checklist you tick as you go.
Keep these updated as you build.

---

## 6. Definition of done
- [ ] Hero is `HeroShell` + hs-hero-kit with `WorkflowRibbon`, two clusters, NZ `HeroComplianceBadges`,
      and **right-click quick actions** — visually consistent with `/incidents` and `/health-safety/analytics`.
- [ ] `TabStrip` (Sessions / Alerts) with live server counts; sessions `paginate()` + `LaravelPagination`;
      filters in the hero footer drive `router.get`. Alerts render as a register table, not cards.
- [ ] **Every row** supports left-click → detail modal and right-click → `ShiftContextMenu`, built from
      one shared `actionsFor()` list (Clients idiom); keyboard accessible; semantic tokens only
      (zero raw hex/oklch/`border-l-*`); **en-NZ dates**.
- [ ] **The Start-session create flow is a stepped modal modelled on the Add-client wizard** (stepper
      rail, completeness, Save & add another, validateStep, SuccessPane); every sub-action (check-in,
      extend/edit, end, emergency, acknowledge, resolve) is an action modal. Nothing navigates to a full page.
- [ ] Alerts deep-link to the Control Room (`control-room.alerts.show`) as the primary action; no
      parallel triage surface built here.
- [ ] `index()` returns `tabCounts` + `hero` + `detail` + `can`; new `sessions.update` (extend/edit)
      added; all existing endpoints still work.
- [ ] `docs/lone-workers-redesign/{CURRENT_STATE_AUDIT,BACKEND_AUDIT,PROGRESS}.md` written and current,
      with all backend changes specified.
- [ ] `npm run lint` + typecheck clean; screenshot each tab + each modal.

## 7. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0.
- ❌ Don't keep any inline `<Dialog>` form or navigate-away workflow as the primary action.
- ❌ Don't build a parallel alert-triage surface — alerts are owned by the Control Room (deep-link out).
- ❌ Don't fork a parallel client-side filtering engine — server-side like Incidents.
- ❌ Don't add raw colours, GBP/US/`en-GB` formatting, or mobile-app framing.
- ❌ Don't change the existing route names/URLs (deep links must keep working).

## 8. Suggested order
1. Audit + write the three handover docs (§5).
2. Backend: `index()` (tabCounts + hero + detail + can + filters) + new `sessions.update` + geolocation.
3. Page shell: hero → TabStrip → Sessions + Alerts register tables (right-click + click→detail).
4. Session detail modal + lifecycle action modals; alert detail → Control Room deep-link.
5. The Add-client-style Start-session wizard.
6. Split the monolith into per-tab files; nav check (`app-sidebar.tsx`); lint/types; screenshot every surface.
