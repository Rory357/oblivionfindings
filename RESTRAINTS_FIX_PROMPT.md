# Restraints & Behaviour Support — Redesign + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: rebuild `/health-safety/restraints` to the
Health & Safety **gold standard** (same hero, tabs, right-click and detail-modal idioms as
`/health-safety`, `/incidents` and `/health-safety/analytics`), and rebuild every create/edit
flow as a **guided wizard modal that mirrors the Client page "Add Client" modal**. Make it
feature-complete and consistent across every surface restraints appear on.

This file already contains a full audit (§1–§2 are confirmed against the codebase). **You must
then run your own audit pass and record all remaining + backend work in the handover docs (§7).**

---

## 0. Read these first (do not skip — match them, don't reinvent)

**Page-chrome gold standard (mirror these exactly for the register surface):**
- `resources/js/pages/health-safety/events/index.tsx` ← closest analogue. Copy its hero, tabs,
  right-click rows, and detail-as-modal wiring.
- `resources/js/pages/incidents/index.tsx` ← sibling register, identical chrome.
- `resources/js/pages/health-safety/analytics.tsx` ← hero + `HeroSegmented` + `HeroSummaryStrip` reference.

**Create-workflow gold standard (mirror this for EVERY create/edit/review modal):**
- `resources/js/components/clients/add-client-dialog.tsx` ← **the "Add Client" wizard.** This is the
  UX Chane wants every restraint/plan workflow to follow: a full-height modal with a **left stepper
  rail** (icon + label + blurb per step), a **profile-completeness meter**, **per-step client-side
  validation that jumps to the first failing step**, a **Review & create** step, a **SuccessPane**,
  and a **"Save & add another"** action. (Re-exported via `resources/js/pages/operations/clients/_create-dialog.tsx`.)

**Shared kits you MUST compose (never hand-roll a primitive these already provide):**
- Hero: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges,
  HeroSegmented, HeroSummaryStrip, HeroSummaryMetric, fmt, DOT_CLASS, type Tone`
- Rows: `resources/js/pages/health-safety/components/register-row-kit.tsx`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Right-click + tabs + filters: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem, EntityFilter`
- Wizard modal (the Add Client idiom): `@/components/wizard/primitives`
  → `StepHead, SubHead, Field, FieldErr, SelectInput, Segmented, ChipMulti, TilePicker, InfoCard, Ring, type IconType`
- Wizard shell (the H&S register's create-wizard scaffold): `@/components/wizard/shell`
  → `WizardShell, type WizardStep, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow`
- Detail-as-modal reference: `@/components/health-safety/event-detail-dialog` (`EventDetailDialog`)
- Workflow ribbon: `resources/js/pages/health-safety/components/workflow-ribbon.tsx` → `WorkflowRibbon`

> **Reconcile the two wizard toolkits.** The Add Client modal is built on `@/components/wizard/primitives`
> + a bespoke stepper/completeness shell; the H&S register wizards use `@/components/wizard/shell`
> (`WizardShell`). Part of this job is to make the restraint wizards **look and behave like Add Client**
> while staying inside the H&S design system. Decide (and record in the handover) whether to extend
> `WizardShell` to carry the stepper-rail + completeness-meter, or compose the Add Client primitives
> directly. Either way the result must be ONE wizard idiom, not a third variant.

**The non-negotiable house rules (from the gold-standard file headers):**
- Semantic tokens only. **No raw oklch / hex / `border-l-red-600` / `bg-amber-500`.** Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` / `DOT_CLASS` maps.
- App-primary gradient only on the hero.
- **NZ-only.** Ngā Paerewa NZS 8134:2021, Health & Disability Services Standards, restrictive-practice
  / seclusion reduction language, en-NZ dates, NZD. Never "fix" to GBP/US.
- **Web-only.** No phone frames, no mobile-app treatments.
- Carry over the `eslint-disable no-restricted-syntax` header comment when (and only when) you build a
  bespoke modal surface, exactly as `add-client-dialog.tsx` does.

---

## 1. What this is, and the surfaces (CONFIRMED by audit)

The feature is two models: **`RestraintEvent`** (a restrictive-practice/restraint episode, scoped to a
`client_id`, optionally `site_id`, `stay_id`, `behaviour_support_plan_id`, `related_incident_id`) and
**`BehaviourSupportPlan`** ("BSP", scoped to a `client_id`, status `draft → active → under_review →
archived`). One controller drives the register: `app/Http/Controllers/HealthSafety/RestraintController.php`.

| # | Surface | Route / file | Status today |
|---|---|---|---|
| 1 | **Restraint Register** `/health-safety/restraints` | `index()` → `resources/js/pages/health-safety/restraints/index.tsx` | **Off-pattern. Primary target.** |
| 2 | **Record restraint (respite)** | `RespiteStayController@recordRestraint` → `resources/js/components/respite/modals/stay-actions.tsx` (`RestraintModal`, POST `/respite/stays/{stay}/restraints`) | **2nd create surface, off-pattern. Bring to parity.** |
| 3 | **Respite compliance blockers** | `resources/js/components/respite/detail-modal.tsx` (`unreviewedRestraints`) | Read-only count; keep, deep-link to detail modal. |
| 4 | **Client profile** | `resources/js/components/clients/profile/tabs/behaviour-abc.tsx` (ABC charting) | **No restraint/BSP panel today.** Add read-only panel (see §5). |
| 5 | **Sidebar** | `resources/js/components/app-sidebar.tsx` (~L1267, "Restraint Register") | Gate is inconsistent (see audit #12). |
| 6 | **H&S dashboard + analytics** | `resources/js/pages/health-safety/dashboard.tsx`, `analytics.tsx` | **No restraint metrics. Gap (see §6).** |

**Already wired behind the scenes (do NOT break):** `app/Observers/RestraintEventObserver.php` mirrors
every event into an `HsEvent` (category `RESTRAINT`) and dispatches an operational alert via
`ComprehensiveAlertBridgeService` when there's an injury or the event is outside the plan. The redesign
must preserve this bridge.

**Routes today** (`routes/health-safety.php` ~L90–106, all gated by borrowed `hazards.*` permissions):
`GET /health-safety/restraints` · `POST …/events` · `POST …/plans` · `PUT …/events/{event}` ·
`PUT …/plans/{plan}`. Plus `POST /respite/stays/{stay}/restraints` (`routes/respite.php` ~L87).
**No** show/detail route, **no** status-transition route, **no** export, **no** incident-link UI.

---

## 2. Audit — gaps & issues to fix

Numbered so you can work through them. Severity: 🔴 breaks consistency / feature gap, 🟠 polish.

**Register page `restraints/index.tsx`**
1. 🔴 Uses generic `PageHero`, not `HeroShell` + hs-hero-kit. No eyebrow status pill, no medallion, no
   Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**, no `HeroSummaryStrip`, no
   `WorkflowRibbon`. → Rebuild the hero to match Events/Analytics.
2. 🔴 **No right-click anywhere.** Rows expose only an inline "Review" button. → Add `ShiftContextMenu`
   on every event row **and** every plan card, plus right-click quick-actions on the hero.
3. 🔴 Tabs are basic `TabsRoot` with **no counts**. → Replace with `TabStrip` + server `tabCounts`
   (e.g. Events: All / Unreviewed / Out-of-plan / Injury / Critical / 30 days; Plans: Active / Draft /
   Review due / Under review / Archived).
4. 🔴 **No detail view at all** — you can't open an event or a plan. → Add **detail-as-modal** for both
   (see §3.4), following `EventDetailDialog` + the `only:['detail']` partial-reload pattern.
5. 🔴 **Create flows are raw shadcn `Dialog` forms** — one giant scrolling form each ("Record Event",
   "Create Plan"). → Rebuild both as the **Add Client wizard idiom** (see §4).
6. 🔴 **Review is a thin `Dialog`.** → Make "Review event" a step/action inside the event detail modal
   (and reachable from the row right-click), not a separate bare dialog.
7. 🔴 Hand-rolled `restraintTypeBadge` / `severityBadge` / `statusBadgeColor` colour maps. → Replace
   with `TONE_BG` / `TONE_DOT` / `FlagBadge` from register-row-kit.
8. 🔴 **UI omits backend-supported fields.** The model + `storeEvent` validation already accept
   `staff_involved[]`, `authorised_by`, `related_incident_id`, `duration_minutes` — none are captured
   in the form. → Surface them in the event wizard (staff involved, authoriser, **link to incident**,
   computed duration), and show them in the detail modal.
9. 🔴 **`related_incident_id` (RestraintEvent → ClientIncident) is never exposed.** → Add an incident
   picker in the event wizard and a linked-incident chip in the detail modal (deep-link to `/incidents`).
10. 🔴 **Plans have no lifecycle.** Only create/update exist — no activate / submit-for-review / archive,
    no plan detail, no plan review workflow, even though an overdue `review_date` is rendered in red.
    → Add the full `draft → active → under_review → archived` lifecycle + a "Record plan review"
    action (sets outcome + next `review_date`).
11. 🔴 Plans are loaded with `->get()` (unbounded) and rendered as cards with no pagination; events
    `paginate(25)` but filtering is hand-rolled and lives outside the hero. → Server-side filters in the
    **hero footer** (period · client `EntityFilter` · type · severity · status · review-state · search),
    `paginate(20)` both tabs, `LaravelPagination`.
12. 🟠 **Permission drift.** Routes gate on `hazards.view` / `hazards.manage|create`; the controller's
    `canCreate/canReview` reuse `hazards.*`; the sidebar gates on `hazards.view || safeguarding.viewAny`.
    → Introduce dedicated `restraints.*` (view/create/manage/review) — or `safeguarding.*` — permissions
    and apply them consistently across route, controller and sidebar. **Record the exact scheme in the
    backend handover.**
13. 🟠 **Bug:** `updateEvent` validation caps severity at `low,medium,high` (drops `critical`), while
    `storeEvent` allows `critical`. → Fix the enum.
14. 🟠 No CSV / board-report export (Events + Incidents have one). → Add a register export from the hero.
15. 🟠 No empty/loading/skeleton states matching the gold standard. → Add them.

**Respite create surface (`stay-actions.tsx` `RestraintModal`)**
16. 🔴 Separate, off-pattern form posting to `/respite/stays/{stay}/restraints`. → Rebuild on the **same
    restraint-event wizard** (pre-scoped to the stay's client/site; keep the server-side auto-link of the
    active BSP). One wizard component, two entry points.

**Client profile**
17. 🔴 No restrictive-practice/BSP surface on the client profile (only the ABC tab). → Add a **read-only**
    "Restrictive practice & behaviour support" panel (see §5), consistent with how the client profile
    surfaces other H&S context.

**Analytics / dashboard**
18. 🔴 Neither `analytics.tsx` nor `dashboard.tsx` shows any restraint metric. → Add restraint KPIs
    (see §6).

---

## 3. Target spec — Restraint Register `/health-safety/restraints`

Structure it **exactly** like `events/index.tsx`. Concretely:

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon` at the top (pick the restraint/safeguarding stage).
- `HeroMedallion icon={ShieldAlert}`, `HeroStatusPill` ("Restraint register · synced…"), h1
  "Restraints & Behaviour Support", one-line description.
- Top-right: **Export / Board reports** `Popover` CTA.
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab:
  - *Live · this period* → Events (30d), Out-of-plan, Injuries, Critical.
  - *Needs attention* → Unreviewed events, Plans review-due, Under-review plans, Clients with no active BSP.
- **`HeroComplianceBadges`** NZ chip row (unreviewed restraints, restrictive-practice reduction,
  Ngā Paerewa NZS 8134:2021, plans overdue review) — feed it counts/booleans from the controller,
  never pre-formatted strings.
- **Hero footer = the filter bar** (`HeroShell footer={…}`): `HeroSegmented` period pills · client
  `EntityFilter` · selects for Type / Severity / Within-plan / Review-state · right-aligned search ·
  Clear. All drive server requests via `router.get`.
- **Right-click on the hero** (`onContextMenu`) → `ShiftContextMenu` quick actions: *Record restraint
  event*, *Create behaviour support plan*, *Export register*, *Open analytics →*.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`, each with a server `badge`. Two register lenses (Events, Plans) with
their own tab sets (see audit #3). Changing tab → `router.get(… preserveScroll)`.

### 3.3 Tables + rows
- `RegisterTableHeader` with hint **"Right-click a row for the full workflow"** + `MousePointer2`.
- **Events columns:** When · Client · Type (tone dot) · Duration · Severity · Within-plan · Reviewed ·
  Flags (Unreviewed, Out-of-plan, Injury, Linked-incident).
- **Plans:** keep card layout but make each card a first-class row — left-click opens the plan detail
  modal, right-click opens its `ShiftContextMenu`; show status + review-due `FlagBadge`s.
- Each row/card: `onClick → open…(id)` (modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45`, focus ring — copy the Events row exactly.
- All tone via `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.

### 3.4 Detail-as-modal (events **and** plans)
- Add `detail: RestraintEventDetail | BspDetail | null` props (Inertia partial reload, `only:['detail']`,
  `preserveState`, `preserveScroll`), opened via `?event=` / `?plan=` query params — mirror `openEvent()`.
- Build `RestraintEventDetailDialog` + `BspDetailDialog` on the **`EventDetailDialog` chrome**: sections
  (Event overview / De-escalation & response / Injury & post-incident / Linked plan & incident / Review),
  and an **Options footer bar** carrying the lifecycle actions. Support `initialSection` / `initialAction`
  so a context-menu action (e.g. "Review event", "Archive plan") opens the modal straight onto that step.
- Closing drops the query param so `detail` returns null.

### 3.5 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Events' `openRowCtx`), gated on `can.*` and current state:
- **Event:** View event · Review event (if unreviewed) · Link incident · Open linked plan · Open client
  profile · separator · Copy link.
- **Plan:** View plan · Activate / Submit for review / Archive (by status) · Record plan review ·
  Record event under this plan · Open client profile · separator · Copy link.
- Every mutating item opens the relevant **wizard/detail modal** — never a bare navigation.

---

## 4. Create/edit/review = the **Add Client modal** idiom (core requirement)

Rebuild **all** of these as one guided wizard family that looks and behaves like `add-client-dialog.tsx`
(left stepper rail with icon+label+blurb, top progress bar, completeness/score meter, per-step
client-side validation that jumps to the first failing step, **Review** step, `SuccessPane`,
**"Save & add another"**, semantic tokens only):

- **Record restraint event** (from the register **and** from the respite stay — same component, different
  pre-scoping) → POST `/health-safety/restraints/events` (or `/respite/stays/{stay}/restraints`).
  Suggested steps: *Person & context* (client/site/stay, link active BSP, link incident) → *The episode*
  (type, start/end → live duration, severity) → *Trigger & de-escalation* → *Restraint & response*
  (description, person's response, staff involved) → *Injury & post-incident* (conditional injury detail)
  → *Plan adherence* (within-plan toggle, deviation reason, authoriser) → **Review & record**.
- **Create / edit behaviour support plan** → POST `/health-safety/restraints/plans` /
  PUT `…/plans/{plan}`. Steps: *Person & title* → *Triggers & de-escalation* → *Approved vs prohibited
  interventions* (use `ChipMulti`/`Segmented` where it fits) → *Restrictive-practice type & review cadence*
  → **Review & create**. Carry `status` through the lifecycle.
- **Review restraint event** → the review form becomes an action/step inside the event detail modal
  (notes, lessons learned, outcome), PUT `…/events/{event}`.
- **Record plan review** → new lightweight wizard/step: outcome + next `review_date` + status change.

Submit via Inertia `form.post/put` with `preserveScroll`, `preserveState`, refresh in place (no navigate
away). Map server validation errors back to the owning step exactly as Add Client's `stepForError` does.

---

## 5. Other-surface parity

- **Respite `RestraintModal`** — replace with the §4 event wizard, pre-scoped to the stay (client/site
  locked, stay_id set, active-BSP auto-link preserved). Audit #16.
- **Respite detail-modal** — keep the `unreviewedRestraints` compliance blocker, but make it deep-link
  into the event detail modal / register filtered to that stay.
- **Client profile** — add a **read-only** "Restrictive practice & behaviour support" panel: active BSP
  summary (status, review-due `FlagBadge`), recent restraint events (type, severity, within-plan, reviewed),
  each opening the **same detail modal in read-only mode**; right-click → "Open in register". No
  create/review here — all mutation deep-links to `/health-safety/restraints`. Single source of truth.
- **Sidebar** — fix the gate to the new permission scheme (audit #12); keep label "Restraint Register".

---

## 6. Backend changes (you SPEC these; record them in the handover — see §7)

You are redesigning the UI. Where the UI needs server support, **specify it precisely in
`RESTRAINTS_BACKEND_HANDOVER.md`** (payload shapes, routes, permissions) rather than leaving it implied:

- **`index()`**: return `tabCounts`, a `hero` block (cluster + NZ-badge counts), paginate **both** events
  and plans (`paginate(20)`), keep server-side filters, add `can: { create, review, manage }`, and a
  `detail` prop loaded only when `?event=` / `?plan=` is present (eager-load client, site, plan, incident,
  reviewer, authoriser, staff).
- **Detail endpoints / props** for event + plan (overview + history).
- **Plan lifecycle**: `POST …/plans/{plan}/activate`, `/submit-review`, `/archive`, and
  `POST …/plans/{plan}/review` (outcome + next review_date). Write `status`, `status_changed_at`,
  `status_changed_by`.
- **Event**: fix the `updateEvent` severity enum to include `critical` (audit #13); accept
  `related_incident_id`, `staff_involved[]`, `authorised_by`, `duration_minutes` from the wizard.
- **Permissions**: introduce `restraints.* ` (or `safeguarding.*`) view/create/manage/review +
  migration/seeder; reconcile route + controller + sidebar gates.
- **Export**: CSV/board-report endpoint for the register.
- **Analytics + dashboard**: add restraint aggregates (events over time, within-plan %, unreviewed count,
  by-type, plans overdue review, restraint-reduction trend) to the H&S analytics controller and a KPI tile
  on `dashboard.tsx`.
- **Preserve** `RestraintEventObserver` (HsEvent bridge + alert) and the respite active-BSP auto-link.

---

## 7. Your audit + handover docs (REQUIRED)

1. **Run your own audit pass first.** Treat §1–§2 as a head start, not the whole truth. Re-grep the
   codebase for every restraint / behaviour-support / restrictive-practice touchpoint, confirm the route,
   model, observer and permission facts above, and note anything this prompt missed.
2. **Produce two handover docs in the repo root** and keep them current as you work:
   - `RESTRAINTS_HANDOVER.md` — what you changed on the **frontend**: every file touched, the new
     component map (register page, wizard family, detail dialogs, client panel), before/after notes, and a
     screenshot per surface (register, event wizard, plan wizard, event detail, plan detail, respite modal,
     client panel).
   - `RESTRAINTS_BACKEND_HANDOVER.md` — every **backend** change the redesign needs but that belongs to the
     backend engineer: new/changed routes, controller methods + request classes, the exact `index()` /
     `detail` payload shapes the UI expects, the permission scheme + migration/seeder, the plan-lifecycle
     and review endpoints, the export endpoint, and the analytics queries. Each item: what, why, and the
     shape the UI consumes. Flag anything you stubbed with mock data so it can't ship un-wired.
3. List anything still **to be completed** (and why) under a "Remaining work" heading in the relevant
   handover doc — don't silently drop scope.

---

## 8. Definition of done (acceptance criteria)
- [ ] `/health-safety/restraints` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`,
      two clusters, `WorkflowRibbon`, `HeroSummaryStrip`, and **right-click quick actions**.
- [ ] `TabStrip` with live server counts; `paginate(20)` events **and** plans; filters live in the hero
      footer and drive `router.get`.
- [ ] Every event row **and** plan card supports **left-click → detail modal** and **right-click →
      ShiftContextMenu**; keyboard accessible; semantic tokens only (zero raw hex/oklch/`border-l-*`).
- [ ] **Every create/edit/review is a wizard modal that matches the Add Client UX** (stepper rail,
      completeness meter, per-step validation, Review step, Save & add another) — none navigates to a page.
- [ ] Detail-as-modal for events and plans on the `EventDetailDialog` chrome, with `initialAction` deep
      links from the context menu.
- [ ] Full BSP lifecycle (`draft → active → under_review → archived`) + plan review are reachable from the
      UI and persisted; event review happens inside the detail modal; `critical` severity bug fixed.
- [ ] Backend-supported fields surfaced: staff involved, authoriser, **linked incident**, duration.
- [ ] Respite `RestraintModal` reuses the same event wizard; respite compliance blocker deep-links in.
- [ ] Client profile shows a **read-only** restrictive-practice & BSP panel deep-linking to the register.
- [ ] Restraint KPIs added to analytics + dashboard; CSV/board export works.
- [ ] `RestraintEventObserver` HsEvent bridge + alerts still fire; respite active-BSP auto-link intact.
- [ ] `RESTRAINTS_HANDOVER.md` + `RESTRAINTS_BACKEND_HANDOVER.md` written, with screenshots + remaining work.
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` violations except the sanctioned
      bespoke-modal eslint-disable header (copy it from `add-client-dialog.tsx`).

## 9. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0, and converge the
  two wizard toolkits into ONE Add-Client-style idiom.
- ❌ Don't keep any navigate-away workflow or any raw shadcn `Dialog` form as the primary create path.
- ❌ Don't break the `RestraintEventObserver` HsEvent bridge / alerts, or the respite active-BSP auto-link.
- ❌ Don't add raw colours, GBP/US formatting, or mobile-app framing. NZ-only, web-only.
- ❌ Don't make the client-profile panel writable — it deep-links to the register (single source of truth).
- ❌ Don't ship mock data un-flagged; every backend dependency goes in `RESTRAINTS_BACKEND_HANDOVER.md`.

## 10. Suggested order
1. Your audit pass → start both handover docs.
2. Backend spec: `index()` (tabCounts + hero + paginate both + detail + can), plan-lifecycle/review +
   event endpoints, permissions, export, analytics — write into the backend handover as you go.
3. Register page: hero → tabs → tables + right-click → detail modals.
4. Wizard family (event + plan + reviews) in the Add Client idiom; wire both entry points.
5. Respite modal reuse + respite deep-link.
6. Client-profile read-only panel.
7. Analytics + dashboard KPIs; export.
8. Permissions/sidebar reconcile; lint/typecheck; screenshot every surface into the handover.
