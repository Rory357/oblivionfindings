# Risk Assessments Module — Redesign + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: redesign `/health-safety/risk-assessments` to the
Health & Safety **gold standard** — same hero, right-click and tab idioms as `/incidents`,
`/health-safety/analytics` and the `/health-safety` dashboard — and make **every workflow a modal that
follows the Client "Add Client" wizard UX**. Standardise the page so it reads as one product with the
rest of H&S, and add right-click options everywhere.

You must (1) **re-audit** the page and every place it touches after reading my audit below, and
(2) **write all backend changes and any work you don't finish into the handover docs** (see §10).
The backend (controller endpoints, routes, form requests) and the **Client/Site Profile placement
work in §6b are for Claude Code, not you** — design the surfaces, then specify them precisely in the
handover for Claude Code to wire.

---

## 0. Read these first (match them — do not reinvent a primitive these already provide)

**Gold-standard pages to mirror exactly (the three Chane named as the consistency target):**
- `resources/js/pages/incidents/index.tsx` ← closest analogue: `HeroShell` + `TabStrip` +
  `ShiftContextMenu` over a register (`openRowCtx`, `openDetail`). **Copy its structure.**
- `resources/js/pages/health-safety/analytics.tsx` ← hero + `HeroSegmented` + `EntityFilter` +
  right-click reference.
- `resources/js/pages/health-safety/dashboard.tsx` ← the `/health-safety` landing for landing-level
  chrome and the H&S nav idioms.

**THE MODAL NORTH STAR — every create/edit/action modal must follow this, exactly:**
- `resources/js/components/clients/add-client-dialog.tsx` (the "Add Client" wizard, re-exported at
  `resources/js/pages/operations/clients/_create-dialog.tsx`). This is the canonical multi-step modal:
  full-height `Dialog` (`maxWidth: min(94vw, 1080px)`, `[&>button]:hidden`), a **left stepper rail**
  (~248px, numbered steps + blurbs + live completeness meter), a main column with a
  "**Step X of N · {label}**" header, a **top progress bar**, a scroll-contained body, and a sticky
  footer (**Back · Cancel · Continue**, and on review **Save & add another · Create**). It uses
  **per-step client validation** (`validateStep`) that mirrors the server request, jumps to the first
  failing step on submit (`stepForError`), drives everything through Inertia `useForm`
  (`forceFormData`, `preserveScroll`, `preserveState`), and ends on a **success pane**.
- The wizard is built from shared primitives — **compose these, do not hand-roll inputs**:
  `@/components/wizard/primitives` → `Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput,
  Segmented, ChipMulti, TilePicker, Ring, IconType` (+ the `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_*`,
  `WIZARD_FOOTER_CLASS` constants).
- For simpler one-step action modals (Approve, Mark for review, Archive) use the shared shell
  `@/components/wizard/shell` → `WizardShell, WizardStep, WizardStepPane, WizardSuccessPane,
  ReviewCard, ReviewRow` — same look, less ceremony.

**How a register page launches the modal + wires right-click (copy this wiring):**
- `resources/js/pages/operations/clients/index.tsx` — see `ClientContextMenu` (right-click via
  `onContextMenu={(e) => onContext(e, row)}` on each row), the `addOpen` state +
  `onClick={() => setAddOpen(true)}` trigger, and `<AddClientDialog isOpen … onClose … />`. The
  risk-assessment table → right-click → modal flow should look identical.

**Shared kits you MUST compose:**
- Hero: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges,
  HeroSegmented, HeroSummaryStrip, fmt, type Tone`
- Rows: `resources/js/pages/health-safety/components/register-row-kit.tsx`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Right-click + tabs + filters: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem,
  EntityFilter` (and `multi-entity-filter` → `MultiEntityFilter` if multi-select is wanted)
- Detail-as-modal: `resources/js/pages/health-safety/components/hs-detail-dialog.tsx` (`HsDetailDialog`,
  light row-based). For a richer sectioned detail with an actions footer, follow
  `@/components/health-safety/event-detail-dialog` (`EventDetailDialog`).
- Workflow ribbon: `resources/js/pages/health-safety/components/workflow-ribbon.tsx` → `WorkflowRibbon`
- **5×5 matrix picker (REUSE — do not rebuild):** `resources/js/components/health-safety/risk-matrix.tsx`
  → `RiskMatrix`. This is the likelihood × consequence grid; use it in the modal for both inherent and
  residual scoring so the score/level update live exactly as the server calculates them.

**The non-negotiable house rules (see `docs/DESIGN_TOKENS.md`, `docs/POPUP_STYLE_GUIDE.md`,
`docs/GOVERNANCE_HERO_GUIDE.md`):**
- **Semantic tokens only.** No raw oklch / hex / `border-l-red-600` / `bg-amber-500`. Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` maps. Map risk levels: low → success, medium → info, high → warning,
  extreme → critical.
- App-primary gradient **only on the hero** (no per-site brand tint).
- **NZ-only.** HSWA 2015, WorkSafe NZ, Ngā Paerewa NZS 8134:2021, the SafePlus / ISO 31000 risk-management
  framing. en-NZ dates, NZD. Do not "fix" to GBP/US.
- **Web-only.** No phone frames, no mobile-app treatments.

---

## 1. What Risk Assessments is, and the surfaces (CONFIRMED by audit)

The page is **one model behind one (read-only) controller method**:

| Thing | Detail |
|---|---|
| Model | `App\Models\HsRiskAssessment` (table `hs_risk_assessments`) |
| Controller | `App\Http\Controllers\HealthSafety\HsEventController@riskAssessments` (read-only `index`) |
| Service | `App\Services\HealthSafety\HsRiskAssessmentService` — **already fully built** (see §6) |
| Migration | `database/migrations/2026_04_10_200000_create_hs_risk_assessments_table.php` (complete) |
| Page | `resources/js/pages/health-safety/risk-assessments/index.tsx` (~175 lines, off-pattern) |
| Route | `routes/health-safety.php:43` — `GET /risk-assessments` → `risk-assessments.index`, gated `permission:hazards.view` |
| Review job | `app/Jobs/CheckRiskAssessmentReviewsJob.php` (scheduled in `routes/console.php`) |

**The model is rich and the data layer is done:**
- **5×5 matrix.** `likelihood` (1–5) × `consequence` (1–5) → `risk_score` (1–25) → `risk_level`
  (low 1–4 / medium 5–9 / high 10–15 / extreme 16–25), via `HsRiskAssessment::calculateScore()`.
  Same again for **residual** risk after controls (`residual_*`), plus a `risk_acceptable` boolean.
- **Polymorphic** `assessable_type` / `assessable_id` — an assessment can be attached to a **Site,
  Client, Asset, etc.**, or stand alone. Also an optional `hs_event_id` link to the H&S backbone.
- **Lifecycle status:** `draft → active → under_review → superseded → archived`, with a version chain
  (`superseded_by_id`), an approval pair (`assessed_by_user_id`/`assessed_at`,
  `approved_by_user_id`/`approved_at`), and review cadence (`review_due_at`, `review_frequency_days`).
- Auto reference numbers `RA-YYYY-NNNN` via `generateReferenceNumber()`. Soft-deletes + audit trait.

**Do NOT conflate this with the two other "risk" registers (keep them separate):**
- `App\Models\ClientRisk` (`operations/clients/tabs/risk-management.tsx`) — a **lightweight client-care
  risk list** (label / severity / controls / review_date). Different purpose. **Do not merge.** (See §6b.)
- The **Governance Risk Register** (`App\Domain\Governance` → `RiskRegisterController`, `risk_register_entries`,
  pages under `resources/js/pages/Governance/Risks`) — the enterprise/strategic risk register. Different
  domain. Don't touch; at most deep-link.
- (Also separate: `SafeguardingRiskAssessment`, `RespiteRiskPlanActivation`. Leave alone.)

**The page today** renders the generic `PageHero`, three plain `Select` filters, and a hand-rolled
`<table>` of `LaravelPagination` rows. It is **read-only — there is no way to create, edit, approve,
review, supersede or archive an assessment from the UI at all.** That is the headline gap.

---

## 2. Audit — gaps & issues to fix
Numbered so you can work through them. Severity: 🔴 breaks consistency / feature gap, 🟠 polish.

**Chrome / consistency (`risk-assessments/index.tsx`)**
1. 🔴 Uses the generic `PageHero`, not `HeroShell` + `hs-hero-kit`. No eyebrow status pill, no medallion,
   no Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**, no `WorkflowRibbon`.
   → Rebuild the hero to match `/incidents` and `/health-safety/analytics`.
2. 🔴 **No right-click anywhere.** Rows have no `onContextMenu`; the hero has no quick actions.
   → Add `ShiftContextMenu` on every row, plus right-click quick-actions on the hero.
3. 🔴 Plain `Select`s in a `Card`, not the hero footer filter bar. No per-status counts.
   → Move filters into `HeroShell footer={…}` (mirror Analytics): `HeroSegmented` for status/level,
   `EntityFilter` for Site/Client, a "Due for review" toggle, search, Clear — all `router.get`.
4. 🔴 No `TabStrip`. → Add `TabStrip` with server-count badges (see §3.2).
5. 🔴 Hand-rolled `<table>`. → Rebuild on `register-row-kit` (`RegisterTableHeader`, `FlagBadge`,
   `TONE_BG`/`TONE_DOT`, `initials`). Risk score/level via the tone map, not ad-hoc `riskScoreColor`.
6. 🔴 Rows are not clickable and there is **no detail view** for a single assessment.
   → Each row: left-click → detail modal, right-click → context menu, keyboard accessible.

**Feature gaps (the whole write side is missing)**
7. 🔴 **No "New risk assessment" action** — no create modal, no 5×5 matrix capture. → §4.1.
8. 🔴 **No edit** of a draft. → §4.2.
9. 🔴 **No approve & activate** (draft → active), even though the service + approval columns exist. → §4.3.
10. 🔴 **No "Mark for review" / record review / update residual** — `review_due_at` is shown and rows go
    amber when overdue, but there is no way to act on it. The `CheckRiskAssessmentReviewsJob` flags
    reviews that the UI then can't service. → §4.4 / §4.5.
11. 🔴 **No supersede (new version)** and **no archive**, despite `supersede()`/`archive()` existing. → §4.6.
12. 🟠 No way to set the **assessable** (Site / Client / Event) or see what an assessment is attached to,
    beyond the bare `assessable_type` basename. → surface it in rows, detail and the create modal.
13. 🟠 Empty-state is a bare icon — give it a primary "New risk assessment" CTA.

---

## 3. Target spec — the Risk Assessment register page
Structure it **exactly** like `incidents/index.tsx`.

### 3.1 Hero (`HeroShell`) — with right-click
- Optional `WorkflowRibbon` for the RA lifecycle: **Draft → Active → Review → Superseded → Archived.**
- `HeroMedallion icon={ShieldAlert}`, `HeroStatusPill` ("Risk register · synced…"), `h1`
  "Risk Assessments", one-line description.
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab/filter:
  - *Live · register* → Total, Active, High/Extreme active, Drafts.
  - *Needs attention* → Due for review (overdue), Under review, Residual not acceptable, Awaiting approval.
- **`HeroComplianceBadges`** NZ chip row — assessments overdue for review, high/extreme without an
  approved control plan, residual-not-acceptable count, % active with a scheduled review date. Feed it
  counts/booleans from the controller, **never pre-formatted strings**.
- **Hero footer = the filter bar** (`HeroShell footer={…}`): `HeroSegmented` status + risk level ·
  `EntityFilter` Site · `EntityFilter` Client · "Due for review" toggle · right-aligned search · Clear.
- **Right-click on the hero** (`onContextMenu` → `ShiftContextMenu`): *New risk assessment*,
  *Export register*, *Go to analytics*, *Open risk-assessment register report*.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`, each with a server-count `badge`. Suggested:
**All** · **Active** · **Drafts** · **Due for review** · **High/Extreme** · **Superseded/Archived**.
Changing tab does `router.get(… preserveScroll)`.

### 3.3 Table + rows
- `RegisterTableHeader` with the right-click hint + `MousePointer2`.
- Columns: Reference · Title · Attached to (assessable: Site/Client/Event chip, or "Standalone") ·
  Status · Inherent (score chip + level `FlagBadge`) · Residual (score chip + level, or "—") ·
  Acceptable (Yes/No `FlagBadge`) · Assessed by (initials avatar) · Review due (`FlagBadge`
  Overdue / Due-soon).
- Each `<tr>`: `onClick → open detail modal`, `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45` + focus ring — copy the Incidents row exactly.
- All tone via `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Incidents' `openRowCtx`), gated on `can.manage` + status:
- **Draft:** View · Edit · Approve & activate · separator · Archive · Copy link.
- **Active:** View · Mark for review · Record review / update residual · Supersede (new version) ·
  separator · Archive (critical tone) · Copy link.
- **Under review:** View · Record review / update residual · Supersede · separator · Archive · Copy link.
- **Superseded / Archived:** View · Open current version (if `superseded_by_id`) · Copy link.
Each mutating item opens the relevant **modal** below — never a bare navigation.

### 3.5 Detail-as-modal
Add a `detail` prop loaded only when `?assessment=` is present (eager-load `assessable`, `hsEvent`,
`assessedBy`, `approvedBy`, `supersededBy`, audit). Build on `hs-detail-dialog` (or `EventDetailDialog`
for the richer sectioned look): show the inherent + residual matrices (read-only `RiskMatrix`), the
control plan (existing + additional controls), the version chain, and an **Options footer bar** with
the lifecycle actions. Support `initialAction` so a context-menu action opens the modal straight onto
that step. Closing drops the query param so `detail` returns null.

---

## 4. Workflow modals — follow the Add-Client wizard (this is the point of the job)

Every workflow must **look and behave like `add-client-dialog.tsx`**: same `Dialog` sizing and
`[&>button]:hidden`, same stepper rail (multi-step) / `WizardShell` (single-step), same "Step X of N"
header + top progress bar, same footer buttons, the same Inertia submit options (`forceFormData`,
`preserveScroll`, `preserveState`), the same per-step `validateStep` mirroring the controller, the same
first-failing-step jump, and the same **success pane**. Compose `@/components/wizard/primitives` for
every field. **Do not build bespoke inputs.** Each modal POSTs to a **new endpoint in §6** that simply
calls the **already-built `HsRiskAssessmentService`**.

1. **New risk assessment** → `POST /health-safety/risk-assessments` (`store` → `HsRiskAssessmentService::create`).
   Steps: *Context* (title, risk_description; **attach to** — `Segmented` Standalone / Site / Client /
   Event, then `SelectInput` for the chosen entity → sets `assessable_type`+`assessable_id` or
   `hs_event_id`) → *Inherent risk* (the `RiskMatrix` for likelihood × consequence; show the live
   score/level) → *Controls* (existing_controls, additional_controls) → *Residual risk* (`RiskMatrix`
   again + `risk_acceptable` toggle) → *Review & ownership* (review_frequency_days `Segmented`
   30/90/180/365, review_due_at) → *Review & create* (`ReviewCard`/`ReviewRow`). Offer **Save & add
   another**. (Created in `draft` — the service forces this.)
2. **Edit draft** → `PUT /health-safety/risk-assessments/{assessment}` (`update`). Same field set as #1,
   gated to `draft` only; recompute scores server-side.
3. **Approve & activate** → `POST …/{assessment}/activate` (`HsRiskAssessmentService::activate`).
   Single-step `WizardShell`: confirm + optional approver note; sets approved_by/at, computes
   `review_due_at` from frequency if unset. Gated to `draft`.
4. **Mark for review** → `POST …/{assessment}/review` (`HsRiskAssessmentService::markForReview`).
   Single-step confirm. Gated to `active`.
5. **Record review / update residual** → `POST …/{assessment}/residual`
   (`HsRiskAssessmentService::updateResidualRisk`). Single-step: residual `RiskMatrix` +
   `risk_acceptable` toggle + notes. Gated to active / under_review.
6. **Supersede (new version)** → `POST …/{assessment}/supersede` (`HsRiskAssessmentService::supersede`).
   Multi-step like #1, pre-filled from the current version; on success the old one is marked superseded
   and the new draft opens. **Archive** → `POST …/{assessment}/archive`
   (`HsRiskAssessmentService::archive`) — single-step confirm (critical tone).

---

## 5. Touch-point parity
During your re-audit, for **every** place a risk assessment appears or should, either adopt the same
chrome (hero/rows/right-click/detail-modal) or deep-link to this register as the single source of truth.
Known touch points to check and reconcile:
- **H&S dashboard** (`HsDashboardService` / `HsModuleSummaryService`) — the active / high-extreme /
  due-for-review tiles. Make each tile deep-link into the matching tab/filter here.
- **H&S analytics** (`/health-safety/analytics`) — risk-level breakdowns. Deep-link to filtered register.
- **H&S events** (`HsEvent::hsRiskAssessments` via `hs_event_id`) — from an event, "New/linked risk
  assessment" should open the same create modal with the event pre-attached; the event detail should
  list linked assessments.
- **Governance report** `GET /health-safety/reports/risk-assessment-register`
  (`HsGovernanceReportController@riskAssessmentRegister`) — keep, link from the hero right-click / export.
List what you found and what you changed in the handover.

---

## 6. Backend changes (write these into the handover doc for Claude Code — see §10)

**Good news: no new migration is needed.** The `hs_risk_assessments` table is complete and the
`HsRiskAssessmentService` already implements `create`, `activate`, `markForReview`, `supersede`,
`archive`, and `updateResidualRisk`. The work is **wiring HTTP → service**, which Claude Code will do.
Specify all of it in the handover:

**Controller** — either extend `HsEventController` or (cleaner, recommended) introduce a dedicated
`HsRiskAssessmentController`. In its `index` (replacing the current `riskAssessments`):
- Add **`tabCounts`** for every TabStrip tab (all / active / drafts / due-for-review / high-extreme /
  superseded-archived).
- Add a **`hero`** block (the two clusters + NZ compliance-badge counts/booleans).
- Add a **`detail`** prop, loaded only when `?assessment=` is present (eager-load `assessable`, `hsEvent`,
  `assessedBy`, `approvedBy`, `supersededBy`, audit).
- Return the **picker datasets** the create modal needs: `sites`, `clients`, `staff`, and (when launched
  from an event) the event context.
- Return `can: { manage }` (object) alongside any existing flag, consistent with other H&S pages.
- Extend `filters` to include `site_id`, `client_id`, `tab`, `search`.

**New endpoints/methods** (add beside the existing RA route in `routes/health-safety.php`, under
`permission:hazards.manage` — the write gate the rest of H&S uses):
- `store` — `POST /health-safety/risk-assessments`
- `update` — `PUT /health-safety/risk-assessments/{assessment}` (draft only)
- `activate` — `POST /health-safety/risk-assessments/{assessment}/activate`
- `markForReview` — `POST /health-safety/risk-assessments/{assessment}/review`
- `updateResidual` — `POST /health-safety/risk-assessments/{assessment}/residual`
- `supersede` — `POST /health-safety/risk-assessments/{assessment}/supersede`
- `archive` — `POST /health-safety/risk-assessments/{assessment}/archive`
- Each redirects `->back()` so in-place partial reloads work (mirror Incidents/PPE).
- Add **Form Request** classes (`StoreHsRiskAssessmentRequest`, etc.) whose rules mirror the modal's
  `validateStep` (likelihood/consequence 1–5, status-gated transitions, polymorphic assessable rules).
- Keep the `CheckRiskAssessmentReviewsJob` as-is; the new "Mark for review / Record review" flow is what
  closes its loop.

---

## 6b. Client Profile & Site Profile placement — **Claude Code task (NOT Claude Design)**

Chane's question: *does this need a place in the Client Profile and Site Profile modules, without
duplicating?* Audited answer:

**Client Profile** — `resources/js/pages/operations/clients/show.tsx` + `tabs/_groups.ts`.
- It already has a **`risk_management`** tab (under the "Health & safety" group) backed by
  `ClientRisk` — a *lightweight care-risk list*. **This is a different register; do NOT merge H&S risk
  assessments into it or duplicate it.**
- H&S `HsRiskAssessment` (polymorphic, `assessable_type = Client`) currently **does not surface on the
  client profile at all.** Plan to add it as a **distinct "H&S Risk Assessments" surface** for the
  client — preferred: a second section *inside* the existing `risk_management` tab (heading + the same
  register-row-kit table + right-click + the §4 modals scoped to this client), so the care-risk list and
  the formal H&S assessments live side by side without competing. If a separate tab reads cleaner,
  **surface that recommendation** — add a `hs_risk_assessments` key to `tabs/_groups.ts` under the
  "Health & safety" group and a tab component, reusing this register's components. Claude Code wires the
  controller to pass `HsRiskAssessment::forAssessable(Client::class, id)` and the create modal
  pre-attaches the client.

**Site Profile** — `resources/js/pages/sites/show.tsx`.
- Tabs today include **Hazards** (deep-links to `/sites/{id}/hazards`), Compliance, Inspections,
  Emergency Plan — but **no Risk Assessments surface.** Risk assessments naturally follow hazards.
- Plan a **new "Risk Assessments" tab** on the site profile (add to the `tabs` array next to
  `hazards`, with a matching `<TabsContent>`), listing `HsRiskAssessment::forAssessable(Site::class, id)`
  with the same right-click + §4 modals (create pre-attaches the site). **New tab needed → surfaced.**

In the handover, give Claude Code the exact files (`sites/show.tsx` tabs array ~line 1102–1218 +
`<TabsContent value="hazards">` ~line 2136 as the pattern to copy; `operations/clients/show.tsx` +
`tabs/_groups.ts` + `tabs/risk-management.tsx`), the controller props each needs, and the reusable
components so nothing is rebuilt.

---

## 7. Definition of done (acceptance criteria)
- [ ] `/health-safety/risk-assessments` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`,
      two clusters, optional `WorkflowRibbon`, and **right-click quick actions**.
- [ ] `TabStrip` with live server counts; filters live in the hero footer and drive `router.get`;
      server-side pagination retained.
- [ ] Every row supports **left-click → detail modal** and **right-click → ShiftContextMenu**;
      keyboard accessible; semantic tokens only (zero raw hex/oklch/`border-l-*`).
- [ ] **Every workflow is a modal that follows the Add-Client wizard** — New, Edit draft, Approve &
      activate, Mark for review, Record review/residual, Supersede, Archive — none navigates away.
- [ ] The create/supersede modals use the shared `RiskMatrix` for inherent + residual scoring, live.
- [ ] Wherever else RAs appear (dashboard, analytics, events, governance report) uses the same chrome
      or deep-links here.
- [ ] Client & Site profile placement is **specified in the handover for Claude Code** (not built by you),
      with no duplication of `ClientRisk`.
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` violations except the sanctioned
      on-dark hero buttons (copy the existing eslint-disable comments from the kit / Add-Client header).

## 8. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/modal primitive — compose the kits in §0.
- ❌ Don't rebuild the 5×5 matrix — reuse `RiskMatrix`.
- ❌ Don't merge into or duplicate `ClientRisk`, the Governance risk register, safeguarding or respite
      risk records — they are separate registers.
- ❌ Don't keep any navigate-away workflow or a basic `DialogFooter` form as the primary action.
- ❌ Don't build bespoke wizard inputs — use `@/components/wizard/primitives`.
- ❌ Don't add raw colours, GBP/US formatting, or mobile-app framing. NZ-only, web-only.
- ❌ Don't change the `hazards.view` / `hazards.manage` permission gates or fork a second filter engine.
- ❌ Don't implement the Client/Site profile wiring yourself — design + specify it for Claude Code (§6b).

## 9. Suggested order
1. Spec the backend for Claude Code: `index` (tabCounts + hero + detail + pickers + `can`) and the
   new store/activate/review/residual/supersede/archive routes + Form Requests (all calling the existing
   service). No migration.
2. Page: hero → TabStrip → table + right-click (register-row-kit) → detail modal.
3. Workflow modals with Add-Client wizard parity, one at a time (New → Edit → Approve → Mark for review
   → Record residual → Supersede → Archive), reusing `RiskMatrix`.
4. Touch-point parity (dashboard tiles, analytics, events, governance report).
5. Specify Client + Site profile placement for Claude Code (§6b).
6. Lint/types, screenshot each surface, write the handover (§10).

## 10. Re-audit + handover (REQUIRED)
Before you build, **re-audit** `resources/js/pages/health-safety/risk-assessments/index.tsx`,
`HsEventController@riskAssessments`, `HsRiskAssessment`, `HsRiskAssessmentService`, the migration, the
routes, and every touch point in §5 — confirm or correct my §1–§2 findings.

Then create a drop folder **`.design-drops/risk-assessments-redesign/`** (mirror the existing
`.design-drops/incidents-redesign/` and `health-safety-events-redesign/` drops) containing:
- **`HANDOFF.md`** — what changed on each surface, the modal map, and a **"Backend changes required"**
  section listing every controller method, route and Form Request to wire (all calling the existing
  `HsRiskAssessmentService`; note explicitly that **no migration is needed**), a dedicated
  **"Claude Code tasks — Client & Site Profile placement"** section (the exact files, tabs and props
  from §6b), and a **"Not done / follow-ups"** section for anything left for the next pass.
- **`RISK_ASSESSMENTS_GAP_ANALYSIS.md`** — the corrected audit.
Keep `docs/` consistent with the H&S convention — add
**`docs/HEALTH_SAFETY_RISK_ASSESSMENTS_BACKEND_AUDIT.md`** for the backend notes (matching
`HEALTH_SAFETY_ANALYTICS_BACKEND_AUDIT.md` etc.). **All work you don't complete must be written into
these handover files — do not leave it implicit.**
