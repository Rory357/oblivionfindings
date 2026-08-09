# Injuries & Return-to-Work — Redesign + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: rebuild `/health-safety/injuries`
to the Health & Safety **gold standard** (same hero, right-click and detail-modal idioms
as `/health-safety/events`, `/incidents` and `/health-safety/analytics`), make every
**create / edit workflow a modal that matches the Client "Add Client" wizard exactly**, add
**right-click options everywhere**, and make it consistent across every surface injuries appear on.

You must also **audit the module yourself after reading this**, and record every backend / data
change you depend on (or discover) in a handover file — see §8. Do **not** do the HR-module work
in §7; that is tagged for Claude Code.

---

## 0. Read these first (do not skip — match them, don't reinvent)

**Gold-standard pages to mirror exactly (the pages the user named for consistency):**
- `resources/js/pages/health-safety/events/index.tsx` ← the closest analogue. Copy its hero,
  TabStrip, right-click rows and detail-as-modal structure.
- `resources/js/pages/incidents/index.tsx` ← shares the same register chrome; match it.
- `resources/js/pages/health-safety/analytics.tsx` ← hero + segmented filters + the LTIFR/TRIFR
  language; the analytics page **deep-links into this register** (drill-through), so honour its params.
- `resources/js/pages/health-safety/dashboard.tsx` (`/health-safety`) ← the command-centre the
  register must feel part of.

**The create/edit modal reference — match this UX beat-for-beat (the user's explicit ask):**
- `resources/js/components/clients/add-client-dialog.tsx` ← the **Add Client wizard**. This is the
  modal workflow to mirror: left **stepper rail** (icon + label + blurb per step, check-marks for
  completed steps, a **profile-completeness meter** at the bottom), a header "Step X of N", a top
  progress bar, a scroll-contained body, and a footer with **Back · Cancel · Continue**, plus
  **Save & add another** + **Create** on the review step, then a **success pane**. Per-step client
  validation that mirrors the server request, and jump-to-first-error-step on submit.
- It is built on the **shared wizard primitives** — reuse these, do not hand-roll:
  `@/components/wizard/primitives` → `Field, FieldErr, StepHead, SubHead, SelectInput, TilePicker,
  ChipMulti, Segmented, InfoCard, Ring, type IconType`. (Add Client is the canonical reference for
  these primitives.)
  `@/components/wizard/shell` → `WizardShell, type WizardStep, ReviewCard, ReviewRow`.

**Shared H&S kits you MUST compose (never hand-roll a primitive these already provide):**
- Hero: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges,
  HeroSegmented, HeroSummaryStrip, fmt, type Tone`
- Rows: `resources/js/pages/health-safety/components/register-row-kit.tsx`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Right-click + tabs + entity filter: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem, EntityFilter`
- Workflow ribbon: `resources/js/pages/health-safety/components/workflow-ribbon.tsx` → `WorkflowRibbon`
- Detail-as-modal reference: `@/components/health-safety/event-detail-dialog` (`EventDetailDialog`) —
  copy its `detail` prop + `openEvent()` / `closeDetail()` partial-reload pattern.
- Pagination: `@/components/ui/laravel-pagination` (`LaravelPagination`).
- Dates: `formatDateTime` / date helpers from `@/lib/datetime`. **Never** `toLocaleDateString('en-GB')`.

**The non-negotiable house rules (from the gold-standard file headers):**
- Semantic tokens only. **No raw oklch / hex / `border-l-red-600` / `bg-amber-500`.** Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` maps.
- App-primary gradient only on the hero (no per-site brand tint).
- **NZ-only.** LTIFR / TRIFR (never TRIR), WorkSafe-notifiable, ACC claims (format e.g. `26/123456`),
  Ngā Paerewa NZS 8134:2021. **en-NZ dates, NZD.** Do not "fix" to GBP/US.
- **Web-only.** No phone frames, no mobile-app treatments.

---

## 1. What this is, and the surfaces (CONFIRMED by audit)

Injuries are **`WorkplaceInjury`** records — a **workplace injury to a staff member**
(`user_id` = the injured worker, plus `site_id`, optional `related_incident_id` → `ClientIncident`).
This is staff/people data (ACC + return-to-work), **not** client data. One controller drives it:
`app/Http/Controllers/HealthSafety/ReturnToWorkController.php`.

Child records: **`ReturnToWorkPlan`** (goals + staged return + medical clearance), **`ModifiedDuty`**
(per-stage modified duties), **`WorkCapacityAssessment`** (GP / specialist / physio / OT / employer
capacity findings).

| Surface | Route / file | Status today |
|---|---|---|
| **Register** `/health-safety/injuries` | `index()` → `resources/js/pages/health-safety/injuries/index.tsx` | **Off-pattern. Primary target.** |
| **Create** `/health-safety/injuries/create` | `create()` → `injuries/create.tsx` (full page) | **Full-page form. Convert to modal.** |
| **Detail** `/health-safety/injuries/{id}` | `show()` → `injuries/show.tsx` (886-line full page) | **Full-page. Convert to detail-as-modal.** |
| **Modal create (already exists, duplicate)** | `rtwConfig` in `components/wizard-configs.tsx` via `report-launcher.tsx` | **Thin generic wizard, not wired to this page. Reconcile.** |
| **Analytics drill-through** `/health-safety/analytics` | links to `injuries: '/health-safety/injuries'` with `?type=` etc. | **Wired. Register must honour incoming filters.** |
| **Dashboard / command centre** `/health-safety` | `command-centre-hero.tsx`, `dashboard-tabs.tsx` | **Surfaces injury counts. Keep in sync.** |
| **Incident linkage** `/incidents` | `WorkplaceInjury.related_incident_id` → `ClientIncident` | **Data link exists; surface it both ways (see §6).** |
| **HR module / staff profile** | — | **NOT PRESENT. See §7 — Claude Code work, not yours.** |

There are currently **two create paths** (the full-page `create.tsx`, and the `rtwConfig` modal wizard
launched from the report launcher). That's the inconsistency to kill: there must be **one** create
modal, built to Add-Client parity.

---

## 2. Audit — gaps & issues to fix

Numbered so you can work through them. Severity: 🔴 breaks consistency / feature gap, 🟠 polish.

**Register `injuries/index.tsx`**
1. 🔴 Uses the generic `PageHero`, not `HeroShell` + `hs-hero-kit`. No status pill, no medallion, no
   Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**, no `WorkflowRibbon`.
   → Rebuild the hero to match Events.
2. 🔴 **No right-click anywhere.** Rows only expose a "View" link. → Add `ShiftContextMenu` on every
   row, AND right-click quick-actions on the hero banner (`onContextMenu`).
3. 🔴 **No tabs.** → Add a `TabStrip` (All / Reported / Under treatment / Return to work / Recovered /
   Closed, plus a **WorkSafe-notifiable** and **ACC open** flag tab) with server-side counts.
4. 🔴 Row "View" **navigates away** to `/health-safety/injuries/{id}`. → Open a **detail-as-modal**
   (Inertia partial reload `only: ['detail']`, `preserveState`, `preserveScroll`) like Events'
   `openEvent()`. Keep "Open full page" as a context-menu fallback only.
5. 🔴 **"Record Injury" is a `<Link>` to the full-page create form.** → Replace with the **Add-Client-
   grade create modal** (§4). Retire `create.tsx` as a page (keep the route as a deep-link that opens
   the modal) and fold the duplicate `rtwConfig` path into this one modal.
6. 🔴 **Status options are wrong / mismatched.** The filter offers `open / active / recovering /
   returned_to_work`, but the model's real statuses are
   **`reported / under_treatment / return_to_work / recovered / closed`** (see `WorkplaceInjury` +
   `ReturnToWorkController@update`). → Use the real statuses everywhere (tabs, filters, badges, detail).
7. 🔴 **`en-GB` date formatting** (`toLocaleDateString('en-GB')`). → Use `@/lib/datetime` (en-NZ).
8. 🟠 Hand-rolled `statusColor` / `severityColor` maps. → Replace with `TONE_BG` / `TONE_DOT` +
   `FlagBadge` from the row kit so tones match every other register.
9. 🟠 Filters live in a separate `Card`. → Move them into the **hero footer** (`HeroShell footer={…}`)
   like Events/Analytics: `HeroSegmented` period · `EntityFilter` Site · selects for Severity / Status
   / Treatment / ACC / WorkSafe · right-aligned search · Clear. All drive `router.get`.
10. 🟠 No board/register **export** affordance. → Add the shared export popover from the hero, consistent
   with Events.

**Create `injuries/create.tsx` (full page) + `rtwConfig` (duplicate modal)**
11. 🔴 Full-page form is off-pattern and duplicates the `rtwConfig` wizard. The generic `rtwConfig` is
   only 2 steps + review and lacks the Add-Client polish (stepper rail, completeness meter, save &
   add another, success pane). → Build the **one** create modal to Add-Client parity (§4).

**Detail `injuries/show.tsx` (886-line full page)**
12. 🔴 Full-page detail with RTW plans, modified duties and capacity assessments. → Convert to an
   **`InjuryDetailDialog`** (detail-as-modal, §5). The sub-record forms (RTW plan, capacity
   assessment, modified duty) become **action modals**, not inline full-page forms.

**Lifecycle**
13. 🔴 Status changes only happen via a generic `update`. There's no first-class way to move
   `reported → under_treatment → return_to_work → recovered → closed` from the UI. → Surface the full
   lifecycle as right-click + detail-modal actions (write through `update`, or a dedicated transition
   endpoint — see §8).

**Consistency across the named pages**
14. 🟠 `/incidents`, `/health-safety/events` and `/health-safety/analytics` already share the kit;
   this register currently does not, so the safety workflow "drifts apart." Fixing 1–10 closes that.

---

## 3. Target spec — Register `/health-safety/injuries`

Structure it **exactly** like `events/index.tsx`. Concretely:

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon` at the top (injury → treatment → RTW → recovered stage).
- `HeroMedallion icon={HeartPulse}`, `HeroStatusPill` ("Injury & RTW register · synced…"), `h1`
  "Injuries & Return to Work", one-line description.
- Top-right: **Export / board reports** `Popover` CTA (mirror Events) + the primary **Record injury**
  button that opens the create modal (§4).
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab:
  - *Live · open caseload* → Reported, Under treatment, Return-to-work, Recovered (period).
  - *Needs attention* → WorkSafe-notifiable awaiting, ACC claim open/unlodged, RTW review due,
    Capacity assessment `requires_review`, Lost-time > 0.
- **`HeroComplianceBadges`** NZ chip row (WorkSafe-notifiable count, ACC claims open, LTIFR/TRIFR
  period figure, lost-time days) — feed it counts/booleans from the controller, never pre-formatted
  strings.
- **Hero footer = the filter bar** (gap 9).
- **Right-click on the hero** (`onContextMenu`): a `ShiftContextMenu` with quick actions —
  *Record injury*, *Export CSV*, *Open analytics →*, *WorkSafe register →*.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`: All / Reported / Under treatment / Return to work / Recovered /
Closed (+ flag tabs WorkSafe-notifiable, ACC open), each with a `badge` from server `tabCounts`.
Changing tab does `router.get(... preserveScroll)`.

### 3.3 Table + rows
- `RegisterTableHeader` with hint **"Right-click a row for the full lifecycle"** + `MousePointer2`.
- Columns: Worker (avatar/initials + name) · When (en-NZ) · Injury (type + body part, tone dot) ·
  Severity · Status · Lost days · ACC (claim # / Not lodged) · WorkSafe flag.
- Each `<tr>`: `onClick → openInjury(id)` (modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45` + focus ring — copy the Events row exactly.
- Severity/status tone via `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Events' `openRowCtx`):
- **View injury** (opens detail modal) · **Edit details** (opens edit modal, §4) ·
  **Start treatment** (reported→under_treatment) · **Begin return to work** (→return_to_work) ·
  **Mark recovered** · **Add RTW plan** · **Record capacity assessment** · **Add modified duty** ·
  **Lodge / update ACC claim** · **Flag WorkSafe-notifiable** · **Close** (critical tone) ·
  separator · **Open linked incident** (if `related_incident_id`) · **Copy link** ·
  **Open full page** (`/health-safety/injuries/{id}` fallback).
- Gate each on `can.manage` and current status. Each mutating item opens the relevant **modal**
  (detail-modal pre-opened on that action), never a bare navigation.

### 3.5 Detail-as-modal
- Add a `detail: InjuryDetail | null` prop (Inertia partial reload, `only: ['detail']`).
- See §5 for `InjuryDetailDialog`. `openInjury(id, {section?, action?})` mirrors `openEvent`.

---

## 4. Create / Edit modal = **Add Client parity** (the core ask)

Build **one** `InjuryWizardDialog` (create + edit) that matches `add-client-dialog.tsx` UX exactly,
on `@/components/wizard/primitives` + `@/components/wizard/shell`. Requirements:

- **Stepper rail** (left aside): icon + label + blurb per step, check-marks for completed steps, and a
  **completeness meter** at the bottom (same component/treatment as Add Client's "Profile completeness").
- **Header** "Step X of N · {step label}", **top progress bar**, scroll-contained body.
- **Footer**: Back · Cancel · Continue; on the **Review** step: **Save & add another** (create mode
  only) + **Record injury** / **Save changes**. Then a **success pane** ("Injury recorded — add an RTW
  plan from its record", with a button that opens the detail modal on the RTW section).
- **Per-step client validation** mirroring `ReturnToWorkController@store`; on submit, re-validate all
  steps and jump to the first failing step (copy Add Client's `validateStep` / `stepForError` approach).
- Submit with `preserveScroll, preserveState`; refresh the register in place (no navigate-away). The
  controller already supports a `stay` flag for **Save & add another** — wire it.

**Steps (cover every `store` field; richer than the current `rtwConfig`):**
1. **Worker & site** — `user_id` (staff select), `site_id`, `injury_date`, optional
   **link to incident** (`related_incident_id`, searchable). *(icon: User)*
2. **Injury** — `injury_type` (TilePicker/select; full enum: strain, laceration, fracture, burn,
   contusion, concussion, repetitive_strain, chemical_exposure, biological_exposure, needle_stick,
   slip_trip_fall, manual_handling, psychological, illness, other), `body_part_affected`, `severity`
   (Segmented: minor/moderate/serious/critical), `description`. *(icon: Activity)*
3. **Treatment & ACC** — `medical_treatment_type` (none, first_aid, gp_visit, hospital,
   emergency_department, hospitalisation, specialist, ongoing), `immediate_treatment`,
   `worksafe_notifiable` (toggle, with an InfoCard explaining WorkSafe notifiability),
   `acc_claim_number` (placeholder `26/123456`), `notes`. *(icon: HeartPulse)*
4. **Review & record** — `ReviewCard` / `ReviewRow` summary, completeness, Save & add another / Record.

**Edit mode** opens the same wizard pre-filled (mirror Add Client's edit/`isEditMode` branch), used by
the row "Edit details" action; PUTs to `/health-safety/injuries/{injury}`.

---

## 5. Detail-as-modal — `InjuryDetailDialog`

Build on the `EventDetailDialog` pattern (and/or `hs-detail-dialog.tsx`). Sections:
- **Overview** — worker, site, date, type/body part, severity, status, lost-time days, linked incident
  (deep-link), ACC claim + WorkSafe flags. Status shown as a `WorkflowRibbon`/timeline.
- **Return-to-work plans** — list `ReturnToWorkPlan`s (goals, staged return, hours/week, medical
  clearance). "Add RTW plan" / "Update plan" / "Add modified duty" open **action sub-modals**.
- **Capacity assessments** — list `WorkCapacityAssessment`s (assessor type, capacity status,
  restrictions, next assessment). "Record capacity assessment" action modal.
- **History / audit** — the model is `AuditableChanges`; show the change log.
- **Options footer bar** with the lifecycle actions (Start treatment / Begin RTW / Mark recovered /
  Lodge ACC / Flag WorkSafe / Close). Support `initialAction` so a row-context action opens the modal
  straight onto that step. Closing drops the `?injury=` param so `detail` returns null.

All sub-record forms (RTW plan, capacity assessment, modified duty) POST to the **existing** endpoints
(§8) and refresh in place.

---

## 6. Cross-surface consistency (the pages the user named)

- **`/health-safety/analytics` drill-through:** the analytics page already links into this register
  (`injuries: '/health-safety/injuries'`, with filters like `?type=`). The redesigned register **must
  read those query params** and pre-apply the matching tab/filter so the drill-through lands correctly.
  Keep LTIFR / TRIFR / lost-time language identical to analytics.
- **`/incidents` ↔ injuries:** `WorkplaceInjury.related_incident_id` links to `ClientIncident`. Surface
  it **both ways**: in the injury detail modal show "Linked incident → open"; on the incident detail,
  show "Workplace injury recorded → open" (confirm/By-audit whether the incident wizard's
  `injury_occurred` toggle should create/link a `WorkplaceInjury` — record the finding in the handover).
- **`/health-safety` dashboard & command-centre hero:** keep the injury counts/KPIs it reads in sync
  with the new `tabCounts` / `hero` payload.

---

## 7. HR module surfacing — **CLAUDE CODE, NOT CLAUDE DESIGN**

> Do **not** build this. The user has asked that the HR-module work be done by **Claude Code**. This
> section is here only so your redesign leaves the right seams. Workplace injuries are **staff** data
> (`user_id`), and today they have **zero** presence in the HR module (no `hr.php` route, no HR page).
> Claude Code will add: a **read-only "Injuries & Return to Work"** section on the staff/employee
> profile (`resources/js/pages/hr/employees/show.tsx`), an **HR nav** entry, and **HR analytics**
> workforce injury KPIs (lost-time, ACC claims). See `INJURIES_HR_SURFACING_PLAN.md`.
>
> **What you must do to leave the seam:** build the `InjuryDetailDialog` and row/badge components so
> they can be imported and rendered **read-only** from an HR/staff context (e.g. an `embedded` /
> `readOnly` prop, no create/assign/close affordances), exactly like a register row. Don't couple the
> dialog to the register page's local state.

---

## 8. Backend changes (`ReturnToWorkController` + routes + model) — and write the handover

After your own audit, **record every backend/data change you rely on or discover in a handover file
`INJURIES_BACKEND_HANDOVER.md`** (create it; group by Controller / Routes / Model / Migration). Likely
items:

- **`index`**: keep `paginate(25)` + server filters, but add `tabCounts`, a `hero` block (cluster +
  NZ-badge counts: LTIFR/TRIFR, lost-time days, WorkSafe-notifiable, ACC open), a `detail` prop
  (loaded only when `?injury=` is present, eager-loading `returnToWorkPlans`, `capacityAssessments`,
  `modifiedDuties`, `user`, `site`, `relatedIncident`), and a `can: { manage }` block.
- **Status options:** make the UI statuses match the model
  (`reported / under_treatment / return_to_work / recovered / closed`). If you want explicit
  transitions, add `POST /health-safety/injuries/{injury}/status` writing `status` (+ timestamps);
  otherwise drive them through the existing `update`.
- **Existing endpoints to wire the modals to (no signature change needed):**
  `store`, `update`, `storeRtwPlan`, `updateRtwPlan`, `storeCapacityAssessment`, `storeModifiedDuty`
  (routes in `routes/health-safety.php`, prefix `injuries`, lines ~233–262). Make their redirects
  friendly to in-place partial reloads (they already `redirect()->back()`).
- **`store` `stay` flag** already exists → wire **Save & add another**.
- **Model dead code:** `WorkplaceInjury::scopeActive()` checks `status = 'active'`, a status that's
  never set. Flag for removal/fix in the handover.
- Confirm the `WorkplaceInjuryObserver` behaviour (what it does on create/update — e.g. lost-time or
  incident sync) and note it so the UI doesn't fight it.

---

## 9. Definition of done (acceptance criteria)

- [ ] `/health-safety/injuries` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`,
      two clusters, `WorkflowRibbon`, and **right-click quick actions**.
- [ ] `TabStrip` with live server counts; server-side `paginate` + `LaravelPagination`; filters live
      in the hero footer and drive `router.get`; analytics drill-through params are honoured.
- [ ] Every row supports **left-click → detail modal** and **right-click → ShiftContextMenu**;
      keyboard accessible; semantic tokens only (zero raw hex/oklch/`border-l-*`; no `en-GB` dates).
- [ ] **One** create/edit modal, matching the **Add Client** wizard (stepper rail, completeness meter,
      per-step validation, Save & add another, success pane); the duplicate full-page `create.tsx`
      and `rtwConfig` path are reconciled into it.
- [ ] Detail-as-modal `InjuryDetailDialog` with Overview / RTW plans / Capacity assessments / History
      and an options footer; sub-record forms are action modals; nothing navigates to a full page.
- [ ] Full lifecycle `reported → under_treatment → return_to_work → recovered → closed` reachable from
      the UI and persisted; statuses match the model.
- [ ] Linked-incident shown both ways; dashboard/analytics counts stay in sync.
- [ ] `InjuryDetailDialog` + rows render in a read-only `embedded` mode (the HR seam, §7).
- [ ] `INJURIES_BACKEND_HANDOVER.md` written, listing every backend/data change.
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` violations except the sanctioned
      on-dark hero buttons (copy the existing eslint-disable comments from the kit).

## 10. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0.
- ❌ Don't keep any navigate-away workflow or a plain "View" link as the primary action.
- ❌ Don't build the HR-module surfacing (§7) — that's Claude Code's.
- ❌ Don't add raw colours, GBP/US formatting, `en-GB` dates, TRIR, or mobile-app framing.
- ❌ Don't invent a parallel filtering engine — server-side like Events.
- ❌ Don't treat injuries as client data — `user_id` is the **staff** worker.

## 11. Suggested order
1. Backend: `index` (tabCounts + hero + detail + can), status options, status route if used; write
   `INJURIES_BACKEND_HANDOVER.md`.
2. Register page: hero → tabs → table + right-click → detail modal.
3. The one create/edit modal to Add-Client parity (retire the duplicates).
4. Detail-modal sections + sub-record action modals + full lifecycle.
5. Cross-surface: analytics drill-through params, incident two-way link, dashboard sync.
6. Leave the HR read-only seam (§7). Lint/types, screenshot every surface.
