# Hazardous Substances / Chemical Register — Redesign + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: rebuild `/health-safety/substances` to the
Health & Safety **gold standard** (same hero, tabs, right-click and detail-modal idioms as
`/health-safety/events`, `/incidents` and `/health-safety/analytics`), and make **every create/edit
workflow a modal that matches the Client page "Add client" wizard UX**. Then audit the module
yourself and record all backend work in a handover doc (see §9).

---

## 0. Read these first (match them — do NOT reinvent)

**Gold-standard register pages to mirror exactly (chrome, tabs, rows, detail modal):**
- `resources/js/pages/health-safety/events/index.tsx` ← closest analogue. Copy its structure.
- `resources/js/pages/incidents/index.tsx` ← sibling register, identical chrome.
- `resources/js/pages/health-safety/analytics.tsx` ← hero + segmented filter + compliance-badge reference.
- `/health-safety` itself is `resources/js/pages/health-safety/dashboard.tsx` (CommandCentreHero) — the hub the register links back to.

**Modal / workflow gold standard — this is the one Chane wants every substance form to feel like:**
- `resources/js/components/clients/add-client-dialog.tsx` ← **the canonical "Add client" wizard.**
  Full-height `Dialog`, left **stepper rail** with per-step icons + blurbs, **completeness meter**,
  top progress bar, scroll-contained body, sticky footer, **"Save & add another"**, **success pane
  with next-step deep-links**, **edit mode** ("Complete profile"), and server-error→step mapping.
- Shared wizard primitives both wizards use: `@/components/wizard/primitives`
  → `Field, FieldErr, StepHead, SubHead, SelectInput, Segmented, TilePicker, ChipMulti, InfoCard, Ring`
- Shared wizard shell: `@/components/wizard/shell`
  → `WizardShell, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow, type WizardStep`
- The config-driven H&S wizard engine: `resources/js/pages/health-safety/components/form-wizard.tsx`
  (`HsFormWizard`) and its declarative configs `…/components/wizard-configs.tsx`.
  **A `substanceConfig` already exists** (`key: 'substance'`, posts to `/health-safety/substances`)
  and its field set already matches the controller — reuse it, don't invent a new field list.

**Shared kits you MUST compose (never hand-roll a primitive these provide):**
- Hero: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges, HeroSegmented, HeroSummaryStrip, HeroSummaryMetric, fmt, type Tone`
- Rows: `resources/js/pages/health-safety/components/register-row-kit.tsx`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Workflow ribbon: `resources/js/pages/health-safety/components/workflow-ribbon.tsx` → `WorkflowRibbon`
- Tabs / right-click / filters: `@/components/rostering`
  → `TabStrip, type RosterTabItem, ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, EntityFilter`
- Pagination: `@/components/ui/laravel-pagination` → `LaravelPagination`
- Detail-as-modal reference: `@/components/health-safety/event-detail-dialog` (`EventDetailDialog`)
  and `@/components/incidents/incident-detail-dialog` (`IncidentDetailDialog`).

**Non-negotiable house rules (from the gold-standard file headers + docs/DESIGN_TOKENS.md):**
- **Semantic tokens only.** No raw oklch / hex / `border-l-red-600` / `bg-amber-500`. Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` maps.
- App-primary gradient only on the hero (no per-substance / per-site brand tint).
- **NZ-only.** HSNO/EPA, Hazardous Substances Regs 2017, WES (WES-TWA/STEL/Ceiling), SDS, WorkSafe NZ,
  Ngā Paerewa NZS 8134:2021, ACC. **en-NZ dates, NZD.** Never GBP/US, never `en-GB`, never TRIR.
- **Web-only.** No phone frames, no mobile-app treatments.

---

## 1. What this is, and the surfaces (CONFIRMED by audit)

Hazardous substances are **`HazardousSubstance`** records (org-level, **not** client-scoped), with three
children: **`SafetyDataSheet`** (versioned SDS docs), **`SubstanceStorageLocation`** (site-scoped
quantities), **`SubstanceExposureRecord`** (worker exposure incidents). One controller drives
everything: `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php`.

| Surface | Route / file | Status today |
|---|---|---|
| **Register** `/health-safety/substances` | `index()` → `resources/js/pages/health-safety/substances/index.tsx` | **Off-pattern. Primary target.** |
| **Detail** `/health-safety/substances/{id}` | `show()` → `…/substances/show.tsx` | **Full-page navigate-away. Convert to detail-modal.** |
| **Create** `/health-safety/substances/create` | `create()` → `…/substances/create.tsx` | **Off-pattern full-page form, wrong field contract (see §2.5). Replace with modal.** |
| **Dashboard tile** (`/health-safety`) | `dashboard-tabs.tsx` ~line 287 "Hazardous substances" | Keep, point at the new register. |
| **Quick-add launcher** | `report-launcher.tsx` ~line 43 (`substance`, `inPlace: true`) | Already opens `HsFormWizard` in place — make it the SAME modal as the register's Add button. |
| **Analytics compliance badge** | `analytics.tsx` ~line 709 `sdsExpiring={0}` | **Hardcoded 0 — wire the real count.** |
| **Sidebar** | `app-sidebar.tsx` ~line 1252 | Standardise the label (see §6 naming). |
| **Site page "chemicals stored here"** | — | **Not present.** Optional read-only surface (storage locations are site-scoped) — audit & propose in §9, don't build unless cheap. |

So: this is a **single org-level register** plus a detail page and a create form, surfaced on the H&S
dashboard, the report launcher, analytics and the sidebar. No client-profile surface (substances aren't
person-scoped). The only latent location surface is the **Site page** via storage locations.

---

## 2. Audit — gaps & issues to fix
Severity: 🔴 breaks consistency / real bug, 🟠 polish.

**Register `substances/index.tsx`**
1. 🔴 Uses generic `PageHero`, not `HeroShell` + hs-hero-kit. No `WorkflowRibbon`, no `HeroStatusPill`,
   no `HeroMedallion`, no Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**.
   → Rebuild the hero to match Events/Incidents.
2. 🔴 **No right-click anywhere.** Rows only expose a "View" link. → Add `ShiftContextMenu` on every
   row **and** an `onContextMenu` quick-action menu on the hero (Chane explicitly wants right-click).
3. 🔴 **No tabs.** → Add a `TabStrip` with server-side counts (see §3.2).
4. 🔴 Filters live in a separate `Card` of `Select`s. → Move them into the **hero footer**
   (`HeroShell footer={…}`) like Events: period/segmented + `EntityFilter` Site + selects + search + Clear.
5. 🔴 Row "View" **navigates away** to `/health-safety/substances/{id}`. → Open a **detail-as-modal**
   (Inertia partial reload `only: ['detail']`, `preserveState`, `preserveScroll`) like `openEvent()`.
   Keep "Open full page" as a context-menu fallback.
6. 🔴 "Add Substance" is a `<Link>` to the **full-page create form**. → Replace with the **Add-client-style
   modal wizard** (§4). The register's Add button and the report-launcher tile must open the **same** modal.
7. 🟠 Hand-rolled `statusColor()` map. → Use semantic tokens / `TONE_BG` / `TONE_DOT` + `FlagBadge`.

**Detail `substances/show.tsx`**
8. 🔴 Full navigate-away page. → Convert to a `SubstanceDetailDialog` on `WizardShell` chrome (follow
   `EventDetailDialog`): sections **Overview / Safety & handling / SDS / Storage / Exposures / History**,
   plus an **Options footer bar** with the lifecycle actions. Support `initialSection` / `initialAction`
   so a right-click action (e.g. "Add SDS") opens the modal straight onto that pane.
9. 🔴 **`en-GB` locale bug** at `show.tsx:265` and `show.tsx:387` (`toLocaleDateString('en-GB')`). →
   Use **en-NZ** everywhere (and in the new modal). This is a NZ-only violation.
10. 🟠 The inline SDS / storage / exposure dialogs (`sdsForm`, `storageForm`, `exposureForm`) are fine
    workflows but live on a dead-end page. → Re-home them as **action modals/panes** reachable from the
    detail modal AND from row right-click (no navigate-away).

**Create `substances/create.tsx` — contract mismatch (real bug)**
11. 🔴 The full-page form posts fields the controller **does not accept and silently drops**:
    `hsno_approval, signal_word, hazard_statements, precautionary_statements, firefighting_measures,
    exposure_limit_type, exposure_limit_value, requires_tracking`. `store()` only validates:
    `name, common_name, hsno_classification, hazard_classifications[], physical_form,
    is_controlled_substance, un_number, ppe_required, first_aid_measures, spill_procedures,
    storage_requirements, handling_precautions`. → Either **drop these dead fields** (use the
    `substanceConfig` contract, which is correct) **or** add them to `store()`/model `$fillable` if the
    business actually wants them. **Decide and record in the handover (§9).** Then delete the full-page
    form (keep the route as a deep-link that opens the modal, or redirect it).

**Status enum mismatch (real bug)**
12. 🔴 `index.tsx`'s `statusColor()` handles `active / inactive / pending_review / restricted`, but
    `store()` only ever sets `active` and `update()` only allows `active / inactive / removed`. So
    `pending_review` / `restricted` are unreachable and `removed` is unstyled. → Pick **one canonical
    status set**, align UI + validation (+ migration/enum + any seeders), and document it.

**Wiring / consistency**
13. 🔴 `analytics.tsx` `HeroComplianceBadges … sdsExpiring={0}` is hardcoded. → Feed the real
    "SDS expiring/expired" count (from `SafetyDataSheet.review_date`). Same count powers the register hero.
14. 🟠 Naming drift: "Chemical Register" (index/breadcrumb), "Hazardous substances" (dashboard tile,
    launcher), "Add Substance". → Standardise one product name across register, sidebar, dashboard,
    launcher and breadcrumbs (recommend **"Chemical register"** as the surface, "hazardous substance" as
    the record noun).

---

## 3. Target spec — Register `/health-safety/substances`

Structure it **exactly** like `events/index.tsx` / `incidents/index.tsx`.

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon` at the top (pick the substances/"manage" stage).
- `HeroMedallion icon={FlaskConical}`, `HeroStatusPill` ("Chemical register · Hazardous Substances Regs 2017"),
  `h1` "Chemical register", one-line description.
- Top-right: **Board reports / Export** `Popover` CTA (mirror Events' reports popover; include a chemical
  register / SDS register export).
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab:
  - *Live · chemical register* → Active substances, Controlled, SDS current, Storage locations.
  - *Needs attention* → SDS expiring (≤30d), SDS missing, Awaiting review, Exposures (period).
- **`HeroComplianceBadges`** NZ chip row — feed it real counts/booleans (SDS expiring, WorkSafe-notifiable
  exposures awaiting, Ngā Paerewa, etc.). Never pass pre-formatted strings; pass counts/booleans.
- **Hero footer = the filter bar** (`HeroShell footer={…}`): `HeroSegmented` period pills · `EntityFilter`
  Site · selects for Physical form / Status / Controlled / SDS-state · right-aligned search · Clear.
  All drive server requests via `router.get` (`preserveState`, `preserveScroll`).
- **Right-click on the hero** (`onContextMenu`) → `ShiftContextMenu` quick actions: *Add substance*,
  *Export register*, *Board reports →*, *SDS expiring →* (jumps to that tab). Same `ShiftCtxItem[]` /
  `ShiftCtxState` machinery as the rows.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`, each with a `badge` from server `tabCounts`, changing tab does
`router.get(… preserveScroll)`:
**All · Active · Controlled · SDS expiring · SDS missing · Inactive** (adjust to the canonical status set
from §2.12). Tone-code: Controlled = critical, SDS expiring/missing = warning, Active = success.

### 3.3 Table + rows
- `RegisterTableHeader` with hint **"Right-click a row for the full lifecycle"** + `MousePointer2`.
- Columns: Name (+ Controlled flag, tone dot) · HSNO / classification · Physical form · Hazard pictograms
  (chips) · SDS (status: current / expiring / missing via `FlagBadge`) · Storage locations · Status.
- Each `<tr>`: `onClick → openSubstance(id)` (modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45`, focus ring — copy the Events row exactly.
- Tone via `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.
- `LaravelPagination` when `last_page > 1`.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Events' `openRowCtx`), gating on `can.manage`:
- **View substance** (detail modal) · **Edit substance** (modal, §4 edit mode) · separator ·
  **Add / replace SDS** · **Add storage location** · **Record exposure** · separator ·
  **Mark inactive / removed** (critical tone) · **Copy link** · **Open full page** (`/…/{id}` fallback).
- `tag` = status or "CONTROLLED" (uppercase); `meta` = `"{name} · {hsno}"`. Each mutating item opens the
  relevant **modal/pane**, never a bare navigation.

### 3.5 Detail-as-modal (`SubstanceDetailDialog`)
- Add a `detail: SubstanceDetail | null` prop loaded **only** when `?substance={id}` is present
  (`only: ['detail']`), eager-loading `safetyDataSheets`, `storageLocations.site`, `exposureRecords.user`,
  `creator`.
- Build on `WizardShell` chrome like `EventDetailDialog`. Sections: **Overview** (identity, HSNO, pictograms,
  controlled), **Safety & handling** (PPE, first-aid, spill, storage, handling, WES exposure limits),
  **SDS** (versioned list + upload/replace + download), **Storage** (per-site quantities, labelled /
  segregation flags + add), **Exposures** (records + record-new), **History**.
- **Options footer bar** with: Edit · Add SDS · Add storage location · Record exposure · Mark inactive.
  Support `initialAction` so a context-menu choice opens straight onto that pane.
- Closing drops the `?substance=` param so `detail` returns null.

### 3.6 Workflow modals (every workflow is a modal — no navigate-away)
All POST to **existing** endpoints and refresh in place (`preserveScroll`, partial reload):
- **Add / edit substance** → the Add-client-style wizard (§4) → `POST /health-safety/substances` /
  `PUT /health-safety/substances/{substance}`.
- **Add / replace SDS** → `POST /health-safety/substances/{substance}/sds` (multipart; supersedes current).
- **Add storage location** → `POST /health-safety/substances/{substance}/storage-locations`.
- **Record exposure** → `POST /health-safety/substances/{substance}/exposure-records`.
- **Download SDS** → `GET /health-safety/substances/{substance}/sds/{sds}/download`.

---

## 4. The create / edit modal — **Add-client UX parity** (Chane's priority)

The substance create/edit flow must **read and behave like `add-client-dialog.tsx`**, not a plain form.
Concretely, match these affordances:

- **Full-height `Dialog`** (`p-0`, hidden default close), **left stepper rail** (`hidden sm:flex`,
  ~248px) listing steps with icon + label + blurb, a check on completed steps, and a **completeness meter**
  pinned to the bottom of the rail.
- **Main column**: header "Step X of N · {label}", top **progress bar**, scroll-contained body, sticky
  **footer** with Back / Cancel / Continue, and on the review step **"Save & add another"** + primary
  **"Create substance"**. On edit, the primary is **"Save substance"** and there's no add-another.
- **Success pane** (`WizardSuccessPane`) after create with **next-step deep-links**: "Add SDS",
  "Add storage location", "View substance", "Add another".
- **Edit mode**: opening from a row's *Edit substance* loads the record's values into the same modal
  (title "Edit substance" / "Complete substance"), POSTs `PUT /…/{substance}` via `_method: 'put'`.
- **Server-error → step mapping**: on `onError`, jump to the first step containing a failing field
  (mirror `stepForError` in add-client and the `onError` handler in `HsFormWizard`).
- **Steps & fields** — reuse the **existing `substanceConfig`** (it already matches `store()`):
  - **Substance**: name*, common_name, physical_form* (segmented), hsno_classification, hazard_classifications
    (chips), un_number, is_controlled_substance (toggle).
  - **Controls**: ppe_required, storage_requirements, handling_precautions, first_aid_measures, spill_procedures.
  - **Review** (`ReviewCard`/`ReviewRow`).
  - If §2.11 keeps any extra fields (signal word, hazard/precautionary statements, WES exposure limits,
    firefighting, tracking), add them as a third **"Hazard & exposure"** step — but only after the backend
    accepts them.

**Standardisation decision to make and record (§9):** the project goal is one modal idiom everywhere.
Prefer **upgrading the shared `WizardShell` / `HsFormWizard`** to reach Add-client parity (Save & add
another, edit mode, success-pane deep-links, richer completeness) so *all* H&S wizards inherit it — rather
than forking a bespoke `substance-wizard-dialog.tsx`. If a bespoke dialog is faster, model it 1:1 on
`add-client-dialog.tsx`. Either way, the report-launcher tile and the register Add button open the **same**
component.

---

## 5. Touch points to keep consistent (audit each)
- `app-sidebar.tsx` (~1252) — label + active state for the register.
- `dashboard-tabs.tsx` (~287) "Hazardous substances" — link/opens the new register; same naming.
- `report-launcher.tsx` (~43) substance tile (`inPlace: true`) — opens the §4 modal (single source).
- `analytics.tsx` (~709) — replace `sdsExpiring={0}` with the real count.
- `hs-hero-kit.tsx` `HeroComplianceBadges` — ensure the SDS-expiring feed is wired from real data.
- Breadcrumbs in index/show/create — one product name.

---

## 6. Backend changes (record these in the handover, §9)
*(You're a design pass; you may stub/propose backend, but every backend change must be written up so the
follow-up engineering loop can implement it.)*

- **`index()`**: add `tabCounts` (per §3.2), a `hero` block (cluster counts + **real** NZ-badge counts incl.
  SDS-expiring/missing from `SafetyDataSheet.review_date` + `status`), a `detail` prop (loaded only when
  `?substance=` present, eager-loading sds / storage.site / exposures.user / creator), and `can: { manage }`.
  Keep server-side pagination (currently `paginate(25)` — align to 20 if matching Events).
- **Status enum reconciliation** (§2.12): choose the canonical set, update `store()` default, `update()`
  validation `in:…`, the migration/enum, seeders, and the UI tabs/badges to match.
- **Create-contract reconciliation** (§2.11): drop dead fields from `create.tsx` (and delete/redirect the
  page) **or** extend `store()` validation + model `$fillable` to accept them. Document which.
- **SDS expiry**: add a computed "expiring (≤30d) / expired / missing-current" signal on `SafetyDataSheet`
  (or controller-side), expose to register hero + tabs + `analytics.tsx` badge.
- **Status / lifecycle route**: wire the *Mark inactive/removed* action — reuse `update()` (PUT) or add a
  small `POST /…/{substance}/status` route under the same permission middleware.
- Keep `store/update/storeSds/downloadSds/storeStorageLocation/storeExposureRecord` signatures; just make
  redirects friendly to in-place partial reloads (they already `redirect()->back()`). Permissions today use
  `hazards.manage` / `hazards.create` — confirm that's intended or split out a `substances.*` ability.
- Routes live in `routes/health-safety.php` (`substances` group, ~line 172). Add any new route beside them
  under the same middleware.

---

## 7. Definition of done (acceptance criteria)
- [ ] `/health-safety/substances` hero is `HeroShell` + hs-hero-kit, with `WorkflowRibbon`, two clusters,
      and **real NZ `HeroComplianceBadges`**, plus **right-click quick actions** on the hero.
- [ ] `TabStrip` with live server counts; server-side pagination + `LaravelPagination`; filters live in the
      hero footer and drive `router.get`.
- [ ] Every row supports **left-click → detail modal** and **right-click → ShiftContextMenu**; keyboard
      accessible; semantic tokens only (zero raw hex/oklch/`border-l-*`).
- [ ] **Every workflow is a modal**: Add, Edit, Add/replace SDS, Add storage location, Record exposure,
      Mark inactive — none navigates to a full page.
- [ ] The **Add/Edit substance modal matches the Add-client wizard UX** (stepper rail, completeness meter,
      progress bar, Save & add another, success-pane deep-links, edit mode, error→step mapping).
- [ ] Register Add button **and** report-launcher tile open the **same** modal.
- [ ] Detail page converted to `SubstanceDetailDialog` (sections + options footer + `initialAction`); the
      old `/show` route still resolves as a deep-link fallback.
- [ ] **No `en-GB`** anywhere — en-NZ dates throughout.
- [ ] Status enum + create field contract reconciled (UI ↔ validation ↔ model), documented in the handover.
- [ ] `analytics.tsx` SDS-expiring badge shows a real number, not 0.
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` violations except the sanctioned on-dark
      hero buttons (copy the existing eslint-disable comments from the kit).
- [ ] Screenshot every surface (register, each tab, detail modal, add modal, edit modal, add-SDS pane).

## 8. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0.
- ❌ Don't keep any navigate-away workflow or the plain "View" link as the primary action.
- ❌ Don't post fields the backend doesn't accept (fix the contract first).
- ❌ Don't add `client_id` to substances or make this client-scoped — it's org-level.
- ❌ Don't add raw colours, GBP/`en-GB`, TRIR, or mobile-app framing.
- ❌ Don't fork a parallel filtering engine — server-side like Events.

## 9. Your audit + handover doc (REQUIRED)
1. **Before coding, run your own audit pass** to confirm/extend §2 (check the `HazardousSubstance`,
   `SafetyDataSheet`, `SubstanceStorageLocation`, `SubstanceExposureRecord` models + the
   `SubstanceExposureRecordObserver` for columns the UI should surface; verify the status enum + SDS expiry
   columns actually exist).
2. **Implement the frontend redesign** (register, detail modal, add/edit modal, action panes).
3. **Write the handover doc** capturing every backend change needed, following the repo convention:
   - Create `SUBSTANCES_FIX_PROMPT.md` at repo root **and/or** a `.design-drops/health-safety-substances-redesign/`
     folder (mirror `.design-drops/health-safety-events-redesign/` and `docs/HEALTH_SAFETY_*_FIX_PROMPT.md`).
   - Include: an **Audit addendum** (what you confirmed/found), a **Backend change spec** (§6 items with
     exact files/routes/validation/migrations), and a **Work-completed vs Remaining** checklist so the
     engineering loop can finish the backend.

## 10. Suggested order
1. Your audit pass (models, enum, SDS expiry) → write the handover skeleton.
2. Backend (or backend stubs + handover): `index()` (tabCounts + hero + detail + can), status/SDS-expiry,
   contract reconciliation.
3. Register page: hero (+ hero right-click) → tabs → table + row right-click → detail modal → workflow modals.
4. Add/Edit modal to Add-client parity; unify the launcher tile + Add button.
5. Touch points (§5): analytics badge, dashboard tile, launcher, sidebar, breadcrumbs.
6. en-NZ sweep, lint/types, screenshot each surface, finalise the handover checklist.
