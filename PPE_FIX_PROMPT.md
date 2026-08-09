# PPE Module — Redesign + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: redesign `/health-safety/ppe` to the
Health & Safety **gold standard** — same hero, right-click and tab idioms as `/incidents`,
`/health-safety/analytics` and the `/health-safety` dashboard — and make **every workflow a
modal that follows the Client "Add Client" wizard UX**. Standardise the page so it reads as one
product with the rest of H&S, and add right-click options everywhere.

You must (1) **re-audit** the page and every place it touches after reading my audit below, and
(2) **write all backend changes and any work you don't finish into a handover doc** (see §11).

---

## 0. Read these first (match them — do not reinvent a primitive these already provide)

**Gold-standard pages to mirror exactly (the three Chane named as the consistency target):**
- `resources/js/pages/incidents/index.tsx` ← closest analogue: `HeroShell` + `TabStrip` +
  `ShiftContextMenu` over a register. **Copy its structure.**
- `resources/js/pages/health-safety/analytics.tsx` ← hero + segmented filter + right-click reference.
- `resources/js/pages/health-safety/dashboard.tsx` ← the `/health-safety` landing (`CommandCentreHero`
  + `TabStrip`) for landing-level chrome and the H&S nav idioms.

**THE MODAL NORTH STAR — every PPE create/edit/action modal must follow this, exactly:**
- `resources/js/components/clients/add-client-dialog.tsx` (the "Add Client" wizard, re-exported at
  `resources/js/pages/operations/clients/_create-dialog.tsx`). This is the canonical multi-step
  modal: full-height `Dialog` (`maxWidth: min(94vw, 1080px)`, `[&>button]:hidden`), a **left stepper
  rail** (~248px, numbered steps + blurbs + live completeness meter), a main column with a
  "**Step X of N · {label}**" header, a **top progress bar**, a scroll-contained body, and a sticky
  footer (**Back · Cancel · Continue**, and on review **Save & add another · Create**). It uses
  **per-step client validation** (`validateStep`) that mirrors the server request, jumps to the first
  failing step on submit (`stepForError`), drives everything through Inertia `useForm`
  (`forceFormData`, `preserveScroll`, `preserveState`), and ends on a **success pane**.
- The wizard is built from shared primitives — **compose these, do not hand-roll inputs**:
  `@/components/wizard/primitives` → `Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput,
  Segmented, ChipMulti, TilePicker, Ring, IconType` (+ the `WIZARD_RAIL_CLASS`,
  `WIZARD_PROGRESS_*`, `WIZARD_FOOTER_CLASS` constants).
- For simpler one-step action modals (Return, Record inspection) use the shared shell
  `@/components/wizard/shell` → `WizardShell, WizardStep, WizardStepPane, WizardSuccessPane,
  ReviewCard, ReviewRow` — same look, less ceremony.

**How a register page launches the modal + wires right-click (copy this wiring):**
- `resources/js/pages/operations/clients/index.tsx` — see `ClientContextMenu` (right-click via
  `onContextMenu={(e) => onContext(e, row)}` on each row), the `addOpen` state +
  `onClick={() => setAddOpen(true)}` trigger, and `<AddClientDialog isOpen … onClose … />`.
  PPE's table → right-click → modal flow should look identical.

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

**The non-negotiable house rules (see `docs/DESIGN_TOKENS.md`, `docs/POPUP_STYLE_GUIDE.md`,
`docs/GOVERNANCE_HERO_GUIDE.md`):**
- **Semantic tokens only.** No raw oklch / hex / `border-l-red-600` / `bg-amber-500`. Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` maps.
- App-primary gradient **only on the hero** (no per-site brand tint).
- **NZ-only.** HSWA 2015, WorkSafe NZ, AS/NZS PPE standards (e.g. AS/NZS 1801 head, 1337 eye,
  1715/1716 RPE + fit-testing, 2210 footwear, 4602 hi-vis), Ngā Paerewa NZS 8134:2021. en-NZ dates,
  NZD. Do not "fix" to GBP/US.
- **Web-only.** No phone frames, no mobile-app treatments.

---

## 1. What PPE is, and the surfaces (CONFIRMED by audit)

PPE is **four models** behind **one controller** — `app/Http/Controllers/HealthSafety/PpeController.php`:

| Model | What it is |
|---|---|
| `App\Models\PpeType` | Catalogue of PPE kinds (category, standard, inspection cadence, lifespan). `is_active`. |
| `App\Models\PpeInventory` | Physical stock at a site (brand/model/serial, condition, status, expiry, next-inspection). |
| `App\Models\PpeAllocation` | Issue of an inventory item to a worker (fit-test, training, acknowledgement, return). |
| `App\Models\PpeInspection` | An inspection record against an inventory item (result + condition after). |

- Migration: `database/migrations/2026_03_28_200005_create_ppe_tables.php`.
- Route group: `routes/health-safety.php` (~line 284) — `prefix('ppe')->name('ppe.')`, all under
  the H&S group prefixed `/health-safety`. Permission gate today: **`hazards.manage`** (PPE shares
  the H&S manage permission — keep this).
- Server endpoints that already exist (reuse them — don't navigate to full-page forms):
  - `GET  /health-safety/ppe` → `index` → renders `health-safety/ppe/index`
  - `POST /health-safety/ppe/types` → `storeType`
  - `POST /health-safety/ppe/inventory` → `storeInventory`
  - `PUT  /health-safety/ppe/inventory/{inventory}` → `updateInventory`
  - `POST /health-safety/ppe/inventory/{inventory}/allocate` → `allocate`
  - `POST /health-safety/ppe/allocations/{allocation}/return` → `returnPpe`
  - `POST /health-safety/ppe/inventory/{inventory}/inspections` → `storeInspection`
- The page already returns server-paginated `inventory` (25) and `allocations` (25), `types`,
  `sites`, `staff`, `filters`, `stats { total_items, allocated, inspections_due, condemned }`,
  and `can_manage`.

**The page today** is `resources/js/pages/health-safety/ppe/index.tsx` (~80KB single file, off-pattern).
It has **three sub-areas**: PPE Types · Inventory · Allocations, on a basic `Tabs`.

**Confirm during your re-audit** every other place PPE surfaces or should (HR/worker profile "my PPE",
Site page, the H&S dashboard tiles, analytics) and bring them to the same chrome or deep-link them in.

---

## 2. Audit — gaps & issues to fix
Numbered so you can work through them. Severity: 🔴 breaks consistency / feature gap, 🟠 polish.

**Chrome / consistency (`ppe/index.tsx`)**
1. 🔴 Uses the generic `PageHero` instead of `HeroShell` + `hs-hero-kit`. No eyebrow status pill, no
   medallion, no Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**, no
   `WorkflowRibbon`. → Rebuild the hero to match `/incidents` and `/health-safety/analytics`.
2. 🔴 **No right-click anywhere.** Rows have no `onContextMenu`. → Add `ShiftContextMenu` on every
   row (Types, Inventory, Allocations), AND right-click quick-actions on the hero banner.
3. 🔴 Uses the plain `Tabs` primitive, no per-tab counts. → Replace with the rostering `TabStrip`
   (`RosterTabItem[]` with server `badge` counts), like Incidents/Analytics.
4. 🔴 Rows are hand-rolled. → Rebuild on `register-row-kit` (`RegisterTableHeader`, `FlagBadge`,
   `TONE_BG`/`TONE_DOT`, `initials`, `entityTone`). Add the header hint
   "Right-click a row for the full lifecycle" + `MousePointer2`.
5. 🔴 No detail-as-modal. Clicking a row does nothing / inline-expands. → Add a **detail modal**
   (`hs-detail-dialog`) for an inventory item (Overview / Allocation / Inspections / History) and for
   an allocation, opened by left-click and by a right-click "View" item, via Inertia partial reload
   (`only: ['detail']`, `preserveState`, `preserveScroll`, `?item=` / `?allocation=` param).
6. 🟠 Audit for raw colour literals and replace with semantic tokens / `TONE_BG` / `TONE_DOT`.

**Workflows are plain dialogs, not the Add-Client wizard (the core of this job)**
7. 🔴 **Add PPE type**, **Add inventory item**, and **Allocate to worker** are basic
   `Dialog`/`DialogFooter` forms. → Rebuild each as a **stepper-rail wizard that follows
   `add-client-dialog.tsx` exactly** (rail + Step X of N + progress bar + Back/Cancel/Continue +
   Save & add another + per-step `validateStep` + success pane). See §4.
8. 🔴 **Return PPE** and **Record inspection** are basic dialogs too. → Rebuild as single-step
   `WizardShell` action modals with the same chrome (see §4).
9. 🔴 Every workflow must **POST to the existing endpoint and refresh in place**
   (`preserveScroll`, partial reload) — never navigate to a full page.

**Feature / lifecycle gaps (confirm, then fix UI + the missing backend in §7)**
10. 🔴 **PPE types can't be edited or retired from the UI** — there's no `updateType` /
    deactivate endpoint. The catalogue is create-only. → Add edit + activate/deactivate.
11. 🔴 **Worker acknowledgement is dead.** `PpeAllocation` carries an `acknowledged` flag (the
    frontend type has it) but `allocate` never sets it and there's no acknowledge action. → Add an
    acknowledge workflow + endpoint.
12. 🔴 **Condemn / dispose** is only reachable by editing `status` in the generic update. → Make
    "Condemn" and "Dispose" first-class right-click actions (each an action modal) writing the
    `condemned` / `disposed` status with a reason.
13. 🟠 **Inspections-due** is a stat but not actionable. → Make the "Inspections due" hero tile and a
    TabStrip tab filter to overdue/ due-soon items, each row offering "Record inspection".

---

## 3. Target spec — the PPE register page

Structure it **exactly** like `incidents/index.tsx`. Concretely:

### 3.1 Hero (`HeroShell`) — with right-click
- Optional `WorkflowRibbon` (PPE lifecycle stage: Catalogue → Stock → Issue → Inspect → Retire).
- `HeroMedallion icon={ShieldCheck}`, `HeroStatusPill` ("PPE register · synced…"), `h1`
  "PPE & Equipment", one-line description.
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab/filter:
  - *Live · register* → Total items, Allocated, Available, Inspections due.
  - *Needs attention* → Overdue inspection, Expiring/expired, Condemned awaiting disposal,
    Unacknowledged allocations, Missing fit-test (RPE).
- **`HeroComplianceBadges`** NZ chip row — RPE fit-test current, items past expiry, inspections
  overdue, condemned-awaiting-disposal, hi-vis/footwear coverage. Feed it counts/booleans from the
  controller, **never pre-formatted strings**.
- **Hero footer = the filter bar** (`HeroShell footer={…}`), mirroring Analytics: `HeroSegmented`
  period/state pills · `EntityFilter` Site · selects for Category / Status / Condition / Type /
  Assignee · right-aligned search · Clear. All drive server requests via `router.get`.
- **Right-click on the hero** (`onContextMenu` → `ShiftContextMenu`): *Add PPE type*, *Add inventory*,
  *Allocate PPE*, *Export CSV*, *Go to analytics*.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`, each with a server-count `badge`. Suggested:
**Inventory** (All / Available / Allocated / Inspection due / Expiring / Condemned) ·
**Allocations** (Active / Unacknowledged / Overdue return) · **Types** (catalogue).
Changing tab does `router.get(… preserveScroll)`.

### 3.3 Table + rows
- `RegisterTableHeader` with the right-click hint + `MousePointer2`.
- Inventory columns: Type (+ category tone dot) · Site/Location · Brand/Model/Serial · Condition ·
  Status · Next inspection (FlagBadge: Overdue / Due-soon) · Expiry (FlagBadge: Expired / Expiring).
- Allocation columns: Worker (initials avatar) · Item/Type · Allocated date · Fit-test · Training ·
  Acknowledged (FlagBadge) · Status.
- Each `<tr>`: `onClick → open detail modal`, `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45` + focus ring — copy the Incidents row exactly.
- All tone via `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Incidents' `openRowCtx`), gated on `can.manage` + status:
- **Inventory row:** View · Edit item · Allocate to worker · Record inspection · Mark maintenance ·
  Condemn (critical tone) · Dispose · separator · Copy link.
- **Allocation row:** View · Mark acknowledged · Return PPE · Record inspection · separator · Copy link.
- **Type row:** View · Edit type · Activate/Deactivate · Add inventory of this type.
Each mutating item opens the relevant **modal** below — never a bare navigation.

### 3.5 Detail-as-modal
- Add `detail` props loaded only when `?item=` / `?allocation=` is present (eager-load type, site,
  active allocation, inspections, audit). Build on `hs-detail-dialog`: sections + an **Options footer
  bar** with the lifecycle actions. Support `initialAction` so a context-menu action opens the modal
  straight onto that step. Closing drops the query param so `detail` returns null.

---

## 4. Workflow modals — follow the Add-Client wizard (this is the point of the job)

Every workflow below must **look and behave like `add-client-dialog.tsx`**: same `Dialog` sizing and
`[&>button]:hidden`, same stepper rail (for multi-step) / `WizardShell` (for single-step), same
"Step X of N" header + top progress bar, same footer buttons, the same Inertia submit options
(`forceFormData`, `preserveScroll`, `preserveState`), the same per-step `validateStep` that mirrors
the controller's validation and the same first-failing-step jump, and the same **success pane**.
Compose `@/components/wizard/primitives` for every field. **Do not build bespoke inputs.**

1. **Add PPE type** → `POST /health-safety/ppe/types`. Steps: *Identity* (name; `TilePicker`
   category: head/eye/ear/respiratory/hand/foot/body/fall_protection/high_visibility/other) →
   *Standards & lifecycle* (`SelectInput` standards_reference, `Segmented` inspection_frequency
   daily/weekly/monthly/quarterly/annually, typical_lifespan_months) → *Guidance*
   (description, hazards_addressed) → *Review & create* (`ReviewCard`/`ReviewRow`).
   Offer **Save & add another**.
2. **Add inventory item** → `POST /health-safety/ppe/inventory`. Steps: *Type & site*
   (`SelectInput` ppe_type_id, site_id; location) → *Identification* (brand, model, serial_number,
   quantity) → *Condition & dates* (`Segmented` condition new/good/fair/poor; purchase_date,
   expiry_date, next_inspection_due) → *Review*. **Save & add another.**
3. **Allocate PPE to worker** → `POST /health-safety/ppe/inventory/{inventory}/allocate`. Steps:
   *Worker* (`SelectInput` user_id from `staff`) → *Fit-test* (toggle fit_test_completed,
   fit_test_date, fit_test_result — surface as required when the type is respiratory per AS/NZS 1715)
   → *Training & acknowledgement* (training_completed/date; acknowledgement toggle) → *Review*.
4. **Return PPE** → `POST /health-safety/ppe/allocations/{allocation}/return`. Single-step
   `WizardShell`: `Segmented` returned condition (new/good/fair/poor/condemned) + notes → confirm.
5. **Record inspection** → `POST /health-safety/ppe/inventory/{inventory}/inspections`. Single-step:
   `Segmented` result (pass/fail/needs_repair/condemned), `SelectInput` condition_after, findings,
   action_taken, next_inspection_due → confirm.
6. **Edit inventory** → `PUT /health-safety/ppe/inventory/{inventory}` (existing). Wizard or single
   pane reusing the Add-inventory fields.
7. **Acknowledge / Condemn / Dispose / Edit type / Deactivate type** → action modals POSTing to the
   **new endpoints in §7**.

---

## 5. Touch-point parity
During your re-audit, for **every** place PPE appears (HR/worker profile, Site page, H&S dashboard
tiles, analytics), either adopt the same chrome (hero/rows/right-click/detail-modal) or deep-link to
this register as the single source of truth. List what you found and what you changed in the handover.

---

## 6. Backend changes (write these into the handover doc — see §11)
Keep existing signatures; make redirects friendly to in-place partial reloads (they already
`redirect()->back()`). In `PpeController@index`:
- Add **`tabCounts`** for every TabStrip tab (available/allocated/inspection-due/expiring/condemned;
  active/unacknowledged/overdue-return).
- Add a **`hero`** block (the two clusters + NZ compliance-badge counts/booleans).
- Add a **`detail`** prop, loaded only when `?item=` / `?allocation=` is present (eager-load type,
  site, allocations, inspections, createdBy/updatedBy).
- Return `can: { manage }` (object) alongside the existing `can_manage` for consistency with H&S
  pages; keep the `hazards.manage` gate.

New endpoints/methods to add in `PpeController` + `routes/health-safety.php` (under the same
`hazards.manage` middleware, beside the existing `ppe.` routes ~line 284):
- `updateType` (`PUT /health-safety/ppe/types/{type}`) and a `toggleTypeActive` (or `is_active` on
  update) — edit/retire catalogue entries (gap 10).
- `acknowledge` (`POST /health-safety/ppe/allocations/{allocation}/acknowledge`) — the
  `acknowledged` + `acknowledged_at` columns **already exist** in the migration; just add the
  endpoint that sets them (and an `acknowledged_by` column if you want attribution) (gap 11).
- `condemn` / `dispose` (`POST …/inventory/{inventory}/condemn` · `…/dispose`) writing the status +
  a reason/audit, instead of relying on the generic update (gap 12).
Note any new columns/migrations explicitly in the handover (e.g. `acknowledged_at`,
`acknowledged_by`, condemn/dispose reason + timestamp).

---

## 7. Definition of done (acceptance criteria)
- [ ] `/health-safety/ppe` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`, two
      clusters, optional `WorkflowRibbon`, and **right-click quick actions**.
- [ ] `TabStrip` with live server counts; filters live in the hero footer and drive `router.get`;
      server-side pagination retained.
- [ ] Every row supports **left-click → detail modal** and **right-click → ShiftContextMenu**;
      keyboard accessible; semantic tokens only (zero raw hex/oklch/`border-l-*`).
- [ ] **Every workflow is a modal that follows the Add-Client wizard** — Add type, Add inventory,
      Allocate, Return, Record inspection, Edit, Acknowledge, Condemn, Dispose — none navigates away.
- [ ] PPE types are editable + retireable; worker acknowledgement works; condemn/dispose are
      first-class; inspections-due is actionable.
- [ ] Wherever else PPE appears uses the same chrome or deep-links here.
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` violations except the sanctioned
      on-dark hero buttons (copy the existing eslint-disable comments from the kit / Add-Client header).

## 8. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/modal primitive — compose the kits in §0.
- ❌ Don't keep any navigate-away workflow or a basic `DialogFooter` form as the primary action.
- ❌ Don't build bespoke wizard inputs — use `@/components/wizard/primitives`.
- ❌ Don't add raw colours, GBP/US formatting, or mobile-app framing. NZ-only, web-only.
- ❌ Don't change the `hazards.manage` permission gate or fork a second filtering engine.

## 9. Suggested order
1. Backend: `index` (tabCounts + hero + detail + `can`) and the new type/acknowledge/condemn/dispose
   routes + any migration.
2. Page: hero → TabStrip → table+right-click (register-row-kit) → detail modal.
3. Workflow modals, Add-Client wizard parity, one at a time (Add type → Add inventory → Allocate →
   Return → Record inspection → Edit → Acknowledge/Condemn/Dispose).
4. Touch-point parity (HR/worker, Site, dashboard tiles, analytics).
5. Lint/types, screenshot each surface, write the handover (§11).

## 10. Re-audit + handover (REQUIRED)
Before you build, **re-audit** `resources/js/pages/health-safety/ppe/index.tsx`, `PpeController`,
the four `Ppe*` models, the migration, the routes, and every touch point in §5 — confirm or correct
my §1–§2 findings.

Then create a drop folder **`.design-drops/ppe-redesign/`** (mirror the existing
`.design-drops/incidents-redesign/` and `health-safety-events-redesign/` drops) containing:
- **`HANDOFF.md`** — what changed on each surface, the modal map, and a **"Backend changes
  required"** section listing every controller method, route, migration and column you added or that
  still needs wiring (this is where the backend work lives), plus a **"Not done / follow-ups"**
  section for anything left for the next pass.
- **`PPE_GAP_ANALYSIS.md`** — the corrected audit.
Keep `docs/` consistent with the H&S convention (e.g. a `docs/HEALTH_SAFETY_PPE_BACKEND_AUDIT.md` if
you prefer the backend notes there). **All work you don't complete must be written into these
handover files — do not leave it implicit.**
