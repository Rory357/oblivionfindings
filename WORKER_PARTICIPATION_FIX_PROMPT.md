# Worker Participation — Standardisation + Feature-Complete Prompt

**Paste this whole file into Claude Design.** Goal: rebuild
`/health-safety/worker-participation` to the Health & Safety **gold standard** so it reads as the
same product as `/health-safety`, `/incidents` and `/health-safety/analytics` — and make every
create/manage workflow a **modal modelled on the client "Add client" wizard**, with **right-click
options on every row** like the Clients page. Then run your own audit and record the backend work
in handover docs.

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

**Shared kits you MUST compose (verified — these exports exist):**
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
- **NZ-only.** HSWA 2015 worker-participation duties, WorkSafe, ACC, en-NZ dates, NZD. Don't "fix" to GBP/US.
- **Web-only.** No phone frames, no mobile-app treatments.
- Reuse the sanctioned `eslint-disable no-restricted-syntax` header comments from the kits for on-dark
  hero buttons / bespoke modal surfaces — don't add new raw-colour violations.

---

## 1. What this is (CONFIRMED by audit)

Worker Participation is the HSWA participation register. One controller drives everything:
`app/Http/Controllers/HealthSafety/WorkerParticipationController.php` (440 lines), rendering
`resources/js/pages/health-safety/worker-participation/index.tsx` (**4,006 lines, one file**).

It has **three tabs**, over **four entities**:

| Tab | Entities | Existing actions (all ad-hoc `<Dialog>` forms today) |
|---|---|---|
| **H&S Representatives** | `HsRepresentative` | add rep, edit rep |
| **Committee Meetings** | `HsCommittee`, `HsCommitteeMeeting` | create committee, schedule meeting, edit meeting, add attendees, complete (with action items), cancel, upload/download minutes |
| **Consultations** | `HsConsultation` (+ documents) | create, edit, change status, record feedback, record outcome, close, upload/download document |

Existing endpoints (keep these — workflows POST to them; routes in `routes/health-safety.php`,
prefix `worker-participation`, names `worker-participation.*`):
`representatives.store/update`, `committees.store`, `committees.meetings.store`, `meetings.update`,
`meetings.attendees`, `meetings.complete`, `meetings.cancel`, `meetings.minutes.upload/download`,
`consultations.store/update`, `consultations.status`, `consultations.documents.upload/download`.

---

## 2. Audit — gaps to fix (🔴 breaks consistency / feature gap · 🟠 polish)

**Page chrome — `worker-participation/index.tsx`**
1. 🔴 Uses generic `PageHero`, **not** `HeroShell` + hs-hero-kit. No status pill, no medallion, no
   `HeroCluster` tiles, no NZ `HeroComplianceBadges`, no `WorkflowRibbon`. → Rebuild the hero to match
   Incidents/Analytics.
2. 🔴 Uses the old `TabsRoot/TabsList/TabsTrigger/TabsContent`. → Replace with `TabStrip` +
   `RosterTabItem[]`, driven by `router.get(..., { preserveState, preserveScroll })`, with server counts.
3. 🔴 **No right-click anywhere.** No `ShiftContextMenu`, no `onContextMenu`. Row actions are inline
   buttons. → Add right-click to every row (reps, meetings, consultations) using the Clients idiom.
4. 🔴 **~15 ad-hoc `<Dialog>` forms / 13 `useForm`s** hand-rolled inline. None use the wizard chrome,
   none match the Add-client modal. → Standardise onto the wizard pattern (see §3.4–3.5).
5. 🔴 No **detail-as-modal**. There's no way to open a representative/meeting/consultation into a
   focused panel; everything is crammed into the tab. → Add detail dialogs (see §3.4).
6. 🟠 Hand-rolled `ConsultationProgressBar` + status colours — re-express with semantic tokens /
   `TONE_BG` / `TONE_DOT` and `FlagBadge`.
7. 🟠 4,006-line monolith. → Split per entity (e.g. `worker-participation/representatives-tab.tsx`,
   `…/meetings-tab.tsx`, `…/consultations-tab.tsx`, plus a `…/modals/` folder), like Incidents.

**Backend — `WorkerParticipationController@index`**
8. 🔴 No pagination — uses `.get()` and `.limit(20)/.limit(50)->get()`, filters client-side. → Move to
   server-side `paginate()` per active tab + `LaravelPagination`, like Incidents' controller.
9. 🔴 No `tabCounts`, no `hero` block (cluster + NZ-badge counts), no `detail` prop, no `can` block. →
   Return all four so the hero/tabs/right-click/detail render from real data (see §6).
10. 🟠 No `?tab=` / filter params honoured server-side (Site, status, period). → Add them, mirroring
    Incidents.

**Cross-surface consistency**
11. 🟠 Confirm the left-nav entry for Worker Participation sits in the H&S workflow flyout in the right
    order (see `buildSafetySubPanelGroups` in `app-sidebar.tsx`) and is permission-gated consistently.
12. 🟠 If reps/consultations should surface anywhere else (e.g. a read-only "your H&S rep" panel on a
    Site page), note it in the handover — don't build a second management surface.

---

## 3. Target spec — `/health-safety/worker-participation`

Structure it **exactly** like `incidents/index.tsx`.

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon` at the top (participation/consult stage).
- `HeroMedallion icon={Users}` (or `ShieldCheck`), `HeroStatusPill` ("Worker participation · synced…"),
  `h1` "Worker Participation", one-line description.
- Top-right: a **Board reports / export** `Popover` CTA, consistent with the other H&S pages.
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile `href` → the matching tab/filter:
  - *Representatives & committees* → Active reps, Sites without a rep, Committees, Meetings this quarter.
  - *Consultation & actions* → Open consultations, Awaiting feedback, Overdue meeting actions,
    Minutes outstanding.
- **`HeroComplianceBadges`** NZ chip row (e.g. reps coverage, overdue meeting minutes, consultations
  awaiting outcome) — feed it counts/booleans from the controller, never pre-formatted strings.
- **Hero footer = filter bar** (`HeroShell footer={…}`): `HeroSegmented` period · `EntityFilter` Site ·
  status select · right-aligned search · Clear. All drive `router.get`.
- **Right-click on the hero** (`onContextMenu` → `ShiftContextMenu`): quick actions — *Add representative*,
  *Schedule meeting*, *New consultation*, *Export*, using the same `ShiftCtxItem[]` machinery as rows.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`: **Representatives / Committee meetings / Consultations**, each with a
`badge` from server `tabCounts`. Changing tab → `router.get(..., { preserveState, preserveScroll })`.

### 3.3 Tables + rows (per tab)
- `RegisterTableHeader` with hint **"Right-click a row for the full list of actions"** + `MousePointer2`.
- Columns per entity:
  - **Representatives:** Name · Site · Work group · Election method/date · Training days · Status (tone dot).
  - **Meetings:** Committee · Date · Status (scheduled/completed/cancelled) · Attendees · Minutes flag · Actions-due flag.
  - **Consultations:** Topic · Type · Status (use the lifecycle bar tones) · Feedback/outcome flags · Documents.
- Each `<tr>`: `onClick → openDetail(id)` (modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45` + focus ring — copy the Incidents row exactly.
- Tones via `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.

### 3.4 Right-click menu (`ShiftContextMenu`) — the Clients idiom on the H&S component
Mirror **`actionsFor()` in `operations/clients/index.tsx`**: build one shared `ShiftCtxItem[]` per row,
reused by the row's three-dot menu and the right-click menu. Use the **`ShiftContextMenu`** component
from `@/components/rostering` (so it matches Incidents visually) — do **not** hand-roll a third menu.
Build items contextually and gate each on `can.manage` + current status:
- **Representatives:** View · Edit · Record training days · Mark stood-down/active · Copy link.
- **Meetings:** View · Edit · Add attendees · Complete meeting (+ action items) · Cancel ·
  Upload minutes · Download minutes · Copy link.
- **Consultations:** View · Edit · Change status · Record feedback · Record outcome · Upload document ·
  Close consultation (critical tone) · Copy link.
Every mutating item opens the relevant **modal** below — never a bare navigation.

### 3.5 Detail-as-modal
- Add a `detail: WpDetail | null` prop loaded only when a `?representative=` / `?meeting=` /
  `?consultation=` param is present (Inertia partial reload, `only: ['detail']`, `preserveState`,
  `preserveScroll`) — exactly like `openDetail()` in Incidents.
- Build detail dialogs on `WizardShell`/`Dialog` chrome (follow `IncidentDetailDialog`): sections for
  the entity's fields + history + documents, and an **Options footer bar** carrying the lifecycle
  actions. Support an `initialAction` so a right-click action (e.g. "Complete meeting") opens the modal
  straight onto that step. Closing drops the param so `detail` returns null.

### 3.6 Workflow modals — model EVERY create flow on the Add-client wizard
For the three **create** flows — **Add representative**, **Schedule meeting** (incl. create committee),
**New consultation** — build a stepped wizard that copies `add-client-dialog.tsx`:
- Full-height `Dialog`, left **stepper rail** with per-step status, **completeness meter**, top progress bar.
- Sticky footer: Back / Cancel / Continue; on the Review step **Save & add another** + Create
  (`ReviewCard`/`ReviewRow` for the summary).
- Client-side `validateStep` mirroring each FormRequest; `useForm.post(<existing route>)` with
  `preserveScroll`/`preserveState`; `onError` jumps to the failing step; **SuccessPane** on success.
- Suggested steps:
  - *Representative:* Who (user + site + work group) → Election (method, date, training days) → Review.
  - *Meeting:* Committee (pick or create) → Schedule (date, location, agenda items) → Attendees → Review.
  - *Consultation:* Topic & type → Scope (sites, who's consulted, dates) → Documents → Review.
For the smaller **lifecycle/sub-actions** (edit, change status, record feedback/outcome, complete,
cancel, upload minutes/document, add attendees) use focused single-screen action modals on the same
`Dialog` chrome that POST to the existing endpoints and refresh in place (`preserveScroll`, partial
reload). No full-page navigation anywhere.

---

## 4. Backend changes (`WorkerParticipationController` + routes)

Record each of these in the handover docs (§5), then implement:
- **`index()`**: switch the three lists to server-side `paginate()` keyed off the active `?tab=`; add
  server-side filters (`site_id`, `status`, `period`, `q`); return `tabCounts`, a `hero` block (cluster
  + NZ-badge counts), a `detail` prop (loaded only when an entity param is present, eager-loading the
  relations the detail modal needs), and `can: { manage }`.
- Keep the existing `store/update/status/upload/...` signatures; just make their `redirect()->back()`
  responses friendly to in-place partial reloads (they already return back).
- Add any genuinely missing transitions you find during your audit (e.g. a representative
  stand-down/reactivate status write) beside the others under the same permission middleware — flag
  these explicitly in the handover as **new** endpoints.
- Confirm a policy / permission gate exists for `worker-participation.manage`; wire `can.manage` to it.

---

## 5. Do your own audit + write the handover (REQUIRED)

Before coding, run your own pass and **write it down** under a new
`docs/worker-participation-redesign/` folder (match the precedent in `docs/health-safety-redesign/`):
- **`CURRENT_STATE_AUDIT.md`** — what each tab/entity/dialog does today, file/line references, and the
  off-pattern list from §2 (correct or extend it with anything you find).
- **`BACKEND_AUDIT.md`** — models (`HsRepresentative`, `HsCommittee`, `HsCommitteeMeeting`,
  `HsConsultation` + documents/attendees/minutes), every route/endpoint, validation rules, status
  fields/lifecycles, policies, and **every backend change** the redesign needs (the §4 list plus
  anything new) with proposed signatures, migrations, and risk notes. This is the engineering handover.
- **`PROGRESS.md`** — phased plan + a checklist you tick as you go.
Keep these updated as you build.

---

## 6. Definition of done
- [ ] Hero is `HeroShell` + hs-hero-kit with `WorkflowRibbon`, two clusters, NZ `HeroComplianceBadges`,
      and **right-click quick actions** — visually consistent with `/incidents` and `/health-safety/analytics`.
- [ ] `TabStrip` (Representatives / Committee meetings / Consultations) with live server counts;
      server-side `paginate()` + `LaravelPagination`; filters in the hero footer drive `router.get`.
- [ ] **Every row** supports left-click → detail modal and right-click → `ShiftContextMenu`, built from
      one shared `actionsFor()` list (Clients idiom); keyboard accessible; semantic tokens only
      (zero raw hex/oklch/`border-l-*`).
- [ ] **Every create workflow is a stepped modal modelled on the Add-client wizard** (stepper rail,
      completeness, Save & add another, validateStep, SuccessPane); every sub-action is an action modal.
      Nothing navigates to a full page.
- [ ] `index()` returns `tabCounts` + `hero` + `detail` + `can`; all existing endpoints still work.
- [ ] `docs/worker-participation-redesign/{CURRENT_STATE_AUDIT,BACKEND_AUDIT,PROGRESS}.md` written and
      current, with all backend changes specified.
- [ ] `npm run lint` + typecheck clean; screenshot each tab + each modal.

## 7. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0.
- ❌ Don't keep any inline `<Dialog>` form or navigate-away workflow as the primary action.
- ❌ Don't fork a parallel client-side filtering engine — server-side like Incidents.
- ❌ Don't add raw colours, GBP/US formatting, or mobile-app framing.
- ❌ Don't change the existing route names/URLs (deep links must keep working).

## 8. Suggested order
1. Audit + write the three handover docs (§5).
2. Backend: `index()` (paginate + tabCounts + hero + detail + can) + any new transitions/policy.
3. Page shell: hero → TabStrip → three register tables (right-click + click→detail).
4. Detail modals + lifecycle action modals.
5. The three Add-client-style create wizards.
6. Split the monolith into per-tab files; nav check; lint/types; screenshot every surface.
