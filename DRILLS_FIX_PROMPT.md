# Emergency Drills — Feature-Complete + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: bring the Emergency Drills module up to the
Health & Safety **gold standard** (same hero, tabs, right-click and detail-modal idioms as
`/health-safety/events`, `/incidents` and `/health-safety/analytics`), make every workflow a
**modal that follows the client "Add Client" wizard**, make the module feature-complete (several
buttons currently call routes that don't exist), and wire drills into the **Site profile + Calendar**
so the module stops being a silo.

---

## 0. Read these first (do not skip — match them, don't reinvent)

**Gold-standard REGISTER pages to mirror exactly (hero / tabs / rows / right-click / detail-modal):**
- `resources/js/pages/health-safety/events/index.tsx` ← the closest analogue. Copy its structure.
- `resources/js/pages/health-safety/corrective-actions/index.tsx` ← sibling register, same chrome.
- `resources/js/pages/health-safety/analytics.tsx` ← hero + segmented filter reference.
- `resources/js/pages/incidents/*` ← cross-check the same idioms hold here too.

**Gold-standard CREATE / WORKFLOW modal (the product owner's explicit reference — every drill
workflow must follow this, NOT a full page):**
- `resources/js/components/clients/add-client-dialog.tsx` → `AddClientDialog`.
  Bespoke multi-step wizard: 248px stepper rail (hidden < sm), "Step x of y" header + close, 3px
  progress bar, scrollable body, footer actions, per-step `validateStep`, server errors auto-jump to
  the offending step, `preserveScroll`+`preserveState` submit, success pane with "Add another".
  It is opened with a simple `isOpen`/`onClose` boolean from a hero CTA (`setAddOpen(true)`).
  **Reuse its primitives, don't re-style:** `@/components/wizard/primitives`
  → `ChipMulti, Field, FieldErr, InfoCard, Ring, Segmented, SelectInput, StepHead, SubHead, TilePicker, type IconType`.

**Shared kits you MUST compose (never hand-roll a primitive these already provide):**
- Hero: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges, HeroSegmented, HeroSummaryStrip, fmt, type Tone`
- Rows: `resources/js/pages/health-safety/components/register-row-kit.tsx`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Right-click + tabs + filters: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem, EntityFilter`
- Modal/wizard shell (use for the action modals): `@/components/wizard/shell`
  → `WizardShell, type WizardStep, ReviewCard, ReviewRow`
- Workflow ribbon: `resources/js/pages/health-safety/components/workflow-ribbon.tsx` → `WorkflowRibbon`
- Detail-as-modal reference: `@/components/health-safety/event-detail-dialog` (`EventDetailDialog`)

**The non-negotiable house rules (from the gold-standard file headers):**
- Semantic tokens only. **No raw oklch / hex / `border-l-red-600` / `bg-amber-500`.** Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` maps. (Drills today is already mostly semantic — keep it that way.)
- App-primary gradient only on the hero (no per-site brand tint).
- **NZ-only.** en-NZ dates, NZD, WorkSafe, Ngā Paerewa NZS 8134:2021, Fire and Emergency NZ (FENZ)
  evacuation-scheme language. Do not "fix" to GBP/US.
- **Web-only.** No phone frames, no mobile-app treatments.

**Before you write code:** run your own audit pass over §1–§5 to confirm/extend this one, then record
every backend change in the handover docs per **§6** as you go.

---

## 1. What this is, and the surfaces (CONFIRMED by audit)

Drills are **`EmergencyDrill`** records — a scheduled evacuation/fire/lockdown drill at a location
(`site_id`, **no `client_id`**). One controller drives everything:
`app/Http/Controllers/HealthSafety/EmergencyDrillController.php`. Children: `EmergencyDrillParticipant`,
`EmergencyDrillFinding`. Lifecycle the model already supports:
**`scheduled → in_progress → completed`** (+ `cancelled`), with all columns present (`started_at`,
`completed_at`, `duration_minutes`, `evacuation_time_seconds`, `outcome`, …) — so **no migration is
needed for the lifecycle**, only controller + routes + UI.

| Surface | Route / file | Status today |
|---|---|---|
| **Register** `/health-safety/drills` | `index()` → `resources/js/pages/health-safety/drills/index.tsx` | **Off-pattern. Primary target.** Generic `PageHero`, raw table, no tabs, no right-click, row "View" navigates away. (Server-side paginate + filters already work — keep.) |
| **Create** `/health-safety/drills/create` | `create()` → `.../drills/create.tsx` | **Full-page form. Replace with the Add-Client-style modal wizard.** |
| **Detail** `/health-safety/drills/{id}` | `show()` → `.../drills/show.tsx` | **Off-pattern + partly broken** (see §2). Convert to detail-as-modal; keep route as deep-link fallback. |
| **Site / location profile** | — | **Not present today.** Add a Drills tab + overview compliance (see §4). |
| **Site Calendar** | — | **Not present today.** Scheduled drills do not appear on any calendar (see §4). |

So to confirm what you asked: **site/location profile = not wired to drills yet**, and the **site
calendar does NOT show drills** (it has an `emergency` source but that is *Emergency Plan reviews*,
not `EmergencyDrill`). Both are gaps to close in §4.

---

## 2. Audit — gaps & issues to fix

Numbered so you can work through them. Severity: 🔴 breaks consistency / feature gap, 🟠 polish.

**Register `drills/index.tsx`**
1. 🔴 Uses generic `PageHero`, not `HeroShell` + `hs-hero-kit`. No eyebrow status pill, no medallion,
   no Live / Needs-attention `HeroCluster`s, no NZ `HeroComplianceBadges`, no `WorkflowRibbon`.
   → Rebuild the hero to match Events.
2. 🔴 **No right-click anywhere.** Rows only expose a single "View" link. → Add `ShiftContextMenu` on
   every row, AND right-click quick actions on the hero banner (you asked for this).
3. 🔴 **No `TabStrip`.** Filtering is a Card of selects. → Add a `TabStrip` (All / Scheduled / Overdue /
   In progress / Completed / Findings open) with server-side `tabCounts`, like Events.
4. 🟠 Server-side `paginate` + filters already exist (`q`, `site_id`, `drill_type`, `status`) — keep,
   but move the filter controls into the **hero footer** (`HeroShell footer={…}`) like Events, and
   render pagination with `LaravelPagination`.
5. 🔴 Row click **navigates away** to `/health-safety/drills/{id}`. → Open a **detail-as-modal**
   (Inertia partial reload `only: ['detail']`, `preserveState`, `preserveScroll`), like `openEvent()`.
   Keep "Open full page" as a context-menu fallback.

**Create `drills/create.tsx`**
6. 🔴 **Full-page form, not a modal.** → Replace with a **"Schedule drill" modal wizard built in the
   `AddClientDialog` idiom** (§3.6), opened from the hero CTA. Keep the `/create` route as a deep-link
   fallback that simply opens the modal.

**Detail `drills/show.tsx` — BROKEN endpoints**
7. 🔴 **"Start drill" is broken** — `show.tsx` POSTs to `/health-safety/drills/{id}/start`, which has
   **no route and no controller method**. → Implement it (§5).
8. 🔴 **"Complete drill" is broken** — POSTs to `/health-safety/drills/{id}/complete`, which **does not
   exist**. The whole completion form (evacuation time, roll-call, outcome, improvements) is dead.
   → Implement it (§5) and drive it from the **Complete-drill modal** (§3.6).
9. 🔴 **"Resolve finding" is broken** — POSTs to `/.../findings/{id}/resolve`, which **does not exist**
   (only `PUT /findings/{finding}` `updateFinding()` exists, and it isn't wired). → Implement resolve
   (§5) and wire it through the finding modal / row right-click.
10. 🟠 Add-participant and add-finding **do** work (routes exist) but are bespoke dialogs. → Re-skin
    them to the shared modal idiom so the whole module reads as one product.
11. 🔴 `update()` (PUT `/{drill}`) and `updateFinding()` (PUT `/findings/{finding}`) exist but are
    **not wired to any UI**. → Either wire them behind the new modals or fold them in; no dead endpoints.

**Backend / lifecycle (feature gap)**
12. 🔴 The model supports `scheduled → in_progress → completed`, but the controller only ever sets
    `scheduled` (on create). There is **no server path to `in_progress` or `completed`** → add the
    transitions in §5 so the lifecycle the UI paints is real. (Completing a drill must still fire
    `EmergencyDrillObserver`, which records the `drill_failure` `HsEvent` + Control Room signal on a
    non-passing outcome — **keep that observer untouched**.)

**Cross-module (Site profile + Calendar) — see §4 for the fix**
13. 🔴 Drills are **absent from the Site profile** (`sites/show.tsx` has 19 tabs, none for drills;
    `SiteController::show()` loads no drill data).
14. 🔴 Drills are **absent from the Site Calendar**. `SiteCalendarAggregator::defaultProviders()` has
    11 providers and `CalendarSources` has no `drill` key; `EmergencyPlanObligationProvider` only
    surfaces *plan review* dates, not `EmergencyDrill.scheduled_at`.
15. 🟠 Per-site drill compliance (`compliant / due_soon / overdue`) is already computed twice
    (`EmergencyDrillController::index` and `HsAnalyticsService::drillStatusBySite()`) but never shown
    on the site. → Surface it on the profile (§4); reuse one source of truth, don't add a third.

**Repo hygiene / naming**
16. 🟠 `SiteComplianceController` has separate site-check types `fire_drill` / `evacuation_drill` that
    are **distinct from `EmergencyDrill`**. Flag this in the handover (§6) — cross-link or clarify so
    staff don't double-log; **do not merge the two concepts without a product decision.**

---

## 3. Target spec — Register `/health-safety/drills`

Structure it **exactly** like `events/index.tsx`. Concretely:

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon` at the top (pick the drills stage: Schedule → Run → Record findings → Close out).
- `HeroMedallion icon={Siren}` (matches the sidebar icon), `HeroStatusPill` ("Drill register · synced…"),
  `h1` "Emergency Drills", one-line description.
- Top-right: hero CTA **"Schedule drill"** (opens the modal wizard, §3.6) + a **Board reports** /
  export popover mirroring Events.
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab:
  - *Live · schedule* → Scheduled (next 30d), Overdue, In progress, Completed (period).
  - *Needs attention* → Sites overdue a drill, Findings open, Findings overdue, Awaiting completion write-up.
- **`HeroComplianceBadges`** NZ chip row (e.g. % sites drilled in last 6 months, sites overdue, FENZ
  evacuation-scheme due) — feed it counts/booleans from the controller, never pre-formatted strings.
  Make these reconcile with the H&S dashboard `drill_compliance_pct` and analytics `drillStatusBySite`.
- **Hero footer = the filter bar** (`HeroShell footer={…}`), mirroring Events: `HeroSegmented` period
  pills · `EntityFilter` Site · selects for Drill-type / Status / Outcome / Due-state · right-aligned
  search · Clear. All drive server requests via `router.get` (reuse the existing `index()` filters).
- **Right-click on the hero** (`onContextMenu`): a `ShiftContextMenu` with quick actions —
  *Schedule drill*, *Export CSV*, *Board reports →*, *Jump to overdue sites*. (Same `ShiftCtxItem[]` +
  `ShiftCtxState` machinery as the rows.)

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`: All / Scheduled / Overdue / In progress / Completed / Findings open,
each with a `badge` from server `tabCounts`. Changing tab does `router.get(… preserveScroll)`.

### 3.3 Table + rows
- `RegisterTableHeader` with hint **"Right-click a row for the full lifecycle"** + `MousePointer2`.
- Columns: Ref / When (scheduled_at, en-NZ) · Drill (type + title, tone dot) · Site · Status ·
  Participants · Findings (open count) · Flags (Overdue, In-progress, Findings-overdue, Awaiting-writeup).
- Each `<tr>`: `onClick → openDrill(id)` (modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45` + focus ring — copy the Events row exactly.
- Status/severity tone via `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Events' `openRowCtx`), gated on `can.manage` + status:
- **View drill** (detail modal) · **Start drill** (scheduled→in_progress) · **Complete drill**
  (in_progress→completed, opens the write-up modal) · **Add participant** · **Add finding** ·
  **Resolve finding** · **Edit / reschedule** · **Cancel drill** (critical tone) · separator ·
  **Copy link** · **Open full page** (`/health-safety/drills/{id}` fallback).
- Each item that mutates opens the relevant **modal** (below), never a bare navigation.

### 3.5 Detail-as-modal
- Add a `detail: DrillDetail | null` prop (Inertia partial reload, `only: ['detail']`, driven by a
  `?drill=` query param). Eager-load `site`, `conductor`, `participants.user`, `findings.assignee`.
- Build a `DrillDetailDialog` on `WizardShell` chrome (follow `EventDetailDialog`): sections
  Overview / Run & timings / Participants / Findings / History, plus an **Options footer bar** with the
  lifecycle actions. Support `initialAction` so a context-menu action (e.g. "Complete") opens the modal
  straight onto that step. Closing drops `?drill=` so `detail` returns null.

### 3.6 Workflow modals — every workflow is a modal in the **Add-Client idiom**
All POST to endpoints (some new — see §5) and refresh in place (`preserveScroll`, partial reload).
Build each in the `AddClientDialog` pattern (stepper rail, per-step validate, error-jump, review step):
- **Schedule drill** (replaces `create.tsx`) → `POST /health-safety/drills`. Steps: Site & drill type
  (with recommended-type chips) → Scenario & schedule (date/time, en-NZ) → Expected participants/wardens
  → Review (`ReviewCard`/`ReviewRow`).
- **Start drill** → `POST /health-safety/drills/{drill}/start` *(new)* — sets `started_at`,
  `status=in_progress`.
- **Complete drill** → `POST /health-safety/drills/{drill}/complete` *(new)*. Steps: Timings
  (`completed_at`, `duration_minutes`, `evacuation_time_seconds`) → Roll-call (`total_participants`,
  `residents_evacuated`, `all_areas_checked`, `assembly_point_reached`, `roll_call_completed`,
  `weather_conditions`) → Outcome & learnings (`outcome`, `observer_notes`, `improvements_identified`,
  `conducted_by`) → Review. (On submit the observer fires automatically.)
- **Add participant** → `POST /health-safety/drills/{drill}/participants` (existing). Fields: user, role
  (participant / observer / warden / first_aider / coordinator), attended, notes.
- **Add finding** → `POST /health-safety/drills/{drill}/findings` (existing). Fields: finding_type
  (observation / non_conformance / improvement / positive), severity (low/medium/high/critical),
  description, corrective_action, assigned_to, due_date.
- **Resolve finding** → `POST /.../findings/{finding}/resolve` *(new)* — sets `status=resolved`,
  `resolved_at`, `resolution_notes`. (Or wire the existing `updateFinding` PUT behind this modal.)

---

## 4. Site / location profile + Calendar integration (cross-module — you asked to check this)

The Site profile (`resources/js/pages/sites/show.tsx`, controller `app/Http/Controllers/SiteController.php`
`show()`) already has a **Calendar tab** (`SiteCalendarEmbed` → `/sites/{site}/calendar`,
`SiteCalendarController`, `SiteCalendarAggregator` + `CalendarSources`). Other modules
(inspections, compliance, checklists, hazards, assets, vendors, credentials, meals, damages, emergency
**plans**, respite) all feed the calendar via obligation providers. **Drills do not.** Close these gaps:

**4.1 Add drills to the Calendar**
- New `app/Services/Sites/Calendar/Providers/DrillObligationProvider.php` (mirror
  `EmergencyPlanObligationProvider`) that emits calendar items for `EmergencyDrill` where
  `status=scheduled` within the requested range, scoped by site, deep-linking to the drill detail modal.
- Register it in `SiteCalendarAggregator::defaultProviders()`.
- Add a `drill` source to `app/Services/Sites/Calendar/CalendarSources.php` (label "Emergency drill",
  icon `Siren`, group `auto`).
- Add the `drill` key to `DEFAULT_SOURCES` in `resources/js/pages/sites/calendar/SiteCalendar.tsx` so
  the embed and full calendar render and filter it.

**4.2 Add drills to the Site profile**
- In `SiteController::show()` load: `nextDrill` (next scheduled), `lastCompletedDrill`, and
  `drillComplianceStatus` (`compliant / due_soon / overdue`) — **reuse `HsAnalyticsService::drillStatusBySite()`**
  (single source of truth; don't re-implement the 6-month rule a third time).
- Add a **"Drills" tab** to `sites/show.tsx` (parity with the Hazards/Inspections tabs): an embedded
  compact gold-standard register (top N upcoming + recent, right-click + click→detail modal, and a
  **"Schedule drill" CTA that opens the same modal** scoped to this site). At minimum, add a drill
  **compliance card on the Overview tab** with the status badge + "next drill due" + link to
  `/health-safety/drills?site_id={id}`.
- 🟠 Optional: add drill compliance to the site **Readiness** checklist.

**4.3 Reconcile the numbers**
- Ensure the register hero counts, the H&S dashboard `drill_compliance_pct`
  (`HealthSafetyDashboardController`), the analytics `drillStatusBySite`, and the new site-profile badge
  all agree (same 6-month definition, same scoping).

Record every cross-module gap you confirm or discover (and anything you defer) in the handover (§6).

---

## 5. Backend changes (`EmergencyDrillController` + routes + providers)

- **`index()`**: in addition to today's `drills` / `stats` / `site_compliance` / `sites` / `filters`,
  return `tabCounts`, a `hero` block (cluster + NZ-badge counts), a `detail` prop (loaded only when
  `?drill=` is present, with the eager-loads in §3.5), and `can: { manage }`.
- **New lifecycle endpoints** (model columns already exist — no migration):
  - `POST /health-safety/drills/{drill}/start` → `start()`: set `status=in_progress`, `started_at=now`.
  - `POST /health-safety/drills/{drill}/complete` → `complete()`: validate + persist the completion
    fields (§3.6) and set `status=completed`, `completed_at`. (Lets `EmergencyDrillObserver` fire.)
  - `POST /health-safety/drills/{drill}/findings/{finding}/resolve` → `resolveFinding()`: set
    `status=resolved`, `resolved_at`, `resolution_notes`.
  - Add a **Cancel** path (`status=cancelled`) for the right-click "Cancel drill".
- **Wire or remove dead endpoints**: `update()` (PUT `/{drill}`) and `updateFinding()`
  (PUT `/findings/{finding}`) must be reachable from the new edit/finding modals, or folded in.
- **Routes** live in `routes/health-safety.php` (drills block ~lines 205–231). Add the new routes beside
  the existing ones under the same permission middleware (`hazards.manage` for mutations,
  `hazards.view` for reads). Keep the `{drill}` wildcard **after** `/create` to avoid the conflict
  that's already guarded against.
- **Calendar providers / sources** as in §4.1; **`SiteController::show()`** props as in §4.2.
- **Do not touch** `EmergencyDrillObserver`, `HsEventService`, `HsSignalService` behaviour — just make
  sure completing a drill flows through the model so they still fire.

---

## 6. Your audit + handover docs (REQUIRED — do this as you go)

The product owner wants you to **run your own audit pass first**, then record all backend + cross-module
work in the repo's handover convention so engineering can verify it:

- Create **`docs/drills-redesign/`** mirroring `docs/health-safety-redesign/` and
  `docs/incidents-redesign/`:
  - `CURRENT_STATE_AUDIT.md` — the front-end state you found (confirm/extend §1–§2).
  - `BACKEND_AUDIT.md` — **every** backend change: new routes, new controller methods + validation,
    the `DrillObligationProvider`, the `CalendarSources` `drill` key, `SiteController::show()` props,
    the broken `/start` `/complete` `/resolve` endpoints you fixed, and the dead `update`/`updateFinding`
    you wired. Note "no migration required (columns already exist)".
  - `PROGRESS.md` — running checklist (mirror the other redesign PROGRESS files).
- Update **`docs/hs-workflow-consistency/PROGRESS.md`** to add a Drills row (hero / tabs / right-click /
  detail-modal / modal-workflows parity status) and add drill scenarios to
  `docs/hs-workflow-consistency/E2E_TESTING.md`.
- If you follow the `.design-drops/<module>-redesign/` loop, drop a `HANDOFF.md` +
  `GAP_ANALYSIS.md` there too (see `.design-drops/health-safety-events-redesign/` for the template).
- Explicitly list the **cross-module gaps** from §4 (site profile + calendar) and anything you defer.

---

## 7. Definition of done (acceptance criteria)
- [ ] `/health-safety/drills` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`, two
      clusters, `WorkflowRibbon`, and **right-click quick actions**.
- [ ] `TabStrip` with live server counts; filters live in the hero footer and drive `router.get`;
      `LaravelPagination`.
- [ ] Every row supports **left-click → detail modal** and **right-click → ShiftContextMenu**;
      keyboard accessible; semantic tokens only (zero raw hex/oklch/`border-l-*`).
- [ ] **Every workflow is a modal in the Add-Client idiom**: Schedule, Start, Complete, Add participant,
      Add finding, Resolve finding, Edit/reschedule, Cancel — none navigates to a full page.
- [ ] Full lifecycle `scheduled → in_progress → completed` (+ cancelled) is reachable from the UI and
      persisted; **`/start`, `/complete`, `/findings/{id}/resolve` now exist** and the show-page buttons
      work. No dead endpoints.
- [ ] Completing a drill still fires `EmergencyDrillObserver` (HsEvent + Control Room signal on a
      non-passing outcome).
- [ ] **Site profile** has a Drills tab (or Overview compliance card) and **scheduled drills appear on
      the Site Calendar** via a real `DrillObligationProvider` + `drill` source.
- [ ] Drill compliance numbers agree across register hero, H&S dashboard, analytics, and site profile.
- [ ] Handover docs written per §6 (incl. `BACKEND_AUDIT.md`).
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` violations except the sanctioned
      on-dark hero buttons (copy the existing eslint-disable comments from the kit).

## 8. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0.
- ❌ Don't keep any navigate-away workflow or a full-page create form as the primary action.
- ❌ Don't add `client_id` to drills.
- ❌ Don't add raw colours, GBP/US formatting, or mobile-app framing.
- ❌ Don't change the observer / signal behaviour, or merge `EmergencyDrill` with the
  `SiteComplianceController` `fire_drill`/`evacuation_drill` check types without a product decision.
- ❌ Don't re-implement per-site drill compliance a third time — reuse `drillStatusBySite()`.

## 9. Suggested order
1. Backend: `index()` (tabCounts + hero + detail + can) and the new `start` / `complete` /
   `resolveFinding` / `cancel` routes + methods (fixes the broken buttons first).
2. Register page: hero → tabs → table + right-click → detail modal → workflow modals (Add-Client idiom).
3. Replace `create.tsx` with the Schedule-drill modal; re-skin participant/finding dialogs.
4. Cross-module: `DrillObligationProvider` + `CalendarSources` + `SiteCalendar` source; `SiteController`
   props + Site-profile Drills tab/card.
5. Reconcile numbers; write handover docs (§6); lint/types; screenshot each surface.
