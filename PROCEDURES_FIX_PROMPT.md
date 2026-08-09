# Safe Work Procedures — Redesign + Standardisation Prompt (Claude Design)

**Paste this whole file into Claude Design.** Goal: rebuild **`/health-safety/procedures`** (Safe Work
Procedures / SWMS) to the Health & Safety **gold standard** — same hero, tabs, right-click and
detail-modal idioms as `/health-safety/events`, `/incidents` and `/health-safety/analytics` — and make
**every create/edit/record flow a modal wizard that mirrors the Client page "Add client" modal exactly**.
Make it consistent across every surface it appears on, and surface it (read-only) in HR.

> **House workflow:** work in small verifiable passes. After each pass, run the app, screenshot the
> changed surface, and diff it against the gold-standard pages before continuing. **Run your own audit
> pass first (§A), then write every backend change + remaining task into the handover documents (§6)
> before/as you build.**

---

## 0. Read these first (match them — do not reinvent)

**Gold-standard pages to mirror (page chrome):**
- `resources/js/pages/health-safety/events/index.tsx` ← the closest analogue. **Copy its structure.**
- `resources/js/pages/health-safety/corrective-actions/index.tsx` ← sibling register, same chrome.
- `resources/js/pages/health-safety/analytics.tsx` ← hero + segmented filter reference.
- The `/incidents` register ← same register family; keep the look identical.

**THE create/edit workflow reference (NON-NEGOTIABLE — this is what Chane asked for):**
- `resources/js/components/clients/add-client-dialog.tsx` ← **the "Add client" modal. Mirror it exactly**
  for "New / Edit procedure". It is a full-height `Dialog` + **left stepper rail** + progress bar +
  scroll-contained body + **Review & create** step + **Success pane with "Add another"**. Re-exported via
  `resources/js/pages/operations/clients/_create-dialog.tsx`. Trigger pattern + row right-click live in
  `resources/js/pages/operations/clients/index.tsx`.
- Field primitives it composes: `@/components/wizard/primitives` →
  `Field, FieldErr, StepHead, SubHead, InfoCard, SelectInput, Segmented, ChipMulti, TilePicker, Ring`.
- Review primitives: `ReviewCard` / `ReviewRow` (see Add-client review step; shared variants also in
  `@/components/wizard/shell`). **Do not hand-roll a wizard — copy this one.**

**Shared H&S kits you MUST compose (never hand-roll a primitive these already provide):**
- Hero: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges,
  HeroSegmented, HeroSummaryStrip, fmt, type Tone`
- Rows: `resources/js/pages/health-safety/components/register-row-kit.tsx`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Right-click + tabs + filters: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem, EntityFilter`
- Workflow ribbon: `resources/js/pages/health-safety/components/workflow-ribbon.tsx` → `WorkflowRibbon`
- Detail-as-modal reference: `@/components/health-safety/event-detail-dialog` (`EventDetailDialog`)
- Status pills: `@/components/ui/status-badge` (`StatusBadge`) — use everywhere, never re-map colours by hand.

**Non-negotiable house rules (from the gold-standard file headers):**
- **Semantic tokens only.** No raw oklch / hex / `border-l-red-600` / `bg-amber-500`. Use
  `status-success / status-warning / status-critical / status-info / status-neutral`, `primary`, `muted`,
  and the `TONE_BG` / `TONE_DOT` maps. (ESLint `no-restricted-syntax` will block raw colour literals.)
- App-primary gradient only on the hero.
- **NZ-only.** HSWA 2015 (Health and Safety at Work Act), **WorkSafe NZ**, PCBU, Ngā Paerewa
  NZS 8134:2021, Hazardous Substances Regs 2017, ACC. en-NZ dates, NZD. Do **not** "fix" to GBP/US/OSHA.
- **Web-only desktop app.** No phone frames, no mobile-app treatments. Design for mouse + keyboard
  (hover, right-click, Enter/Space, focus rings).

---

## 1. What this is, and the surfaces (CONFIRMED by audit)

A procedure is a **`SafeWorkProcedure`** record — a controlled safe-work document with a version history
(`SafeWorkProcedureVersion`), a `status` lifecycle, a `review_date`, and JSON link fields
`applicable_roles`, `applicable_sites`, `related_training`, `hazards_addressed`, `ppe_required`, `steps`.
One controller drives everything: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php`.
Lifecycle in the model/migration: **`draft → under_review → approved → archived`** (+ `review_date` for
the recurring review cycle, + `current_version` snapshots).

| Surface | Route / file | Status today |
|---|---|---|
| **Register** `/health-safety/procedures` | `index()` → `resources/js/pages/health-safety/procedures/index.tsx` | **Off-pattern. Primary target.** |
| **Create** `/…/procedures/create` | `create()` → `…/procedures/create.tsx` (471 lines, full page) | **Convert to "Add client"-style modal wizard.** |
| **Edit** `/…/procedures/{id}/edit` | `edit()` → `…/procedures/edit.tsx` (479 lines, full page) | **Convert to the same modal wizard (edit mode).** |
| **Detail** `/…/procedures/{id}` | `show()` → `…/procedures/show.tsx` (307 lines, full page) | **Convert to a detail-as-modal** (keep route as deep-link fallback). |
| **Sidebar** "Safe Work Procedures" | `resources/js/components/app-sidebar.tsx:~1287` (group "Injury & Procedures") | Present — keep, just confirm grouping. |
| **H&S hub** `/health-safety` | `resources/js/pages/health-safety/dashboard.tsx` | **Not linked. Add a module card/tile.** |
| **H&S analytics** `/health-safety/analytics` | `analytics.tsx` | **No procedures metric. Add one (see §3.7).** |
| **HR module** | — | **Not present anywhere. Surface read-only — but the HR BUILD is Claude Code's job (§5).** |
| **Site profile** (`applicable_sites`) | Sites pages | Optional read-only "procedures that apply here" panel (§5). |
| **Client profile** (role/home context) | `resources/js/pages/operations/clients/tabs/*` | Optional read-only context panel (§5). |

---

## 2. Audit — gaps & issues to fix
Severity: 🔴 breaks consistency / feature gap · 🟠 polish.

**Register `procedures/index.tsx`**
1. 🔴 Uses the generic `PageHero` (not `HeroShell` + hs-hero-kit). No eyebrow status pill, no medallion,
   no Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**, no `WorkflowRibbon`.
   → Rebuild the hero to match Events.
2. 🔴 **No right-click anywhere.** Rows only expose a single "View" link. → Add `ShiftContextMenu` on
   every row, **and** right-click quick-actions on the hero banner (you asked for this).
3. 🔴 **No tabs.** Filtering is a separate `Card` with two `Select`s. → Add a `TabStrip`
   (All / Draft / Under review / Approved / **Review due** / Archived) with server-side counts, and move
   filters into the **hero footer** like Events.
4. 🔴 Row "View" **navigates away** to the full page `/procedures/{id}`. → Open a **detail-as-modal**
   (Inertia partial reload `only: ['detail']`, `preserveState`, `preserveScroll`). Keep "Open full page"
   as a context-menu fallback.
5. 🔴 **"New Procedure" is a full-page form** (`create.tsx`). → Replace with the **"Add client"-style
   modal wizard** (§4). Same for **Edit**.
6. 🔴 Hand-rolled `categoryBadge()` / `statusBadge()` colour maps. → Replace with `StatusBadge` +
   `TONE_BG` / `TONE_DOT` + `FlagBadge`. No bespoke colour functions.
7. 🟠 Stats are only Total / Approved / Due-for-review. → Expand into the two hero clusters (§3.1) and
   feed them from the controller, never pre-formatted strings.

**Lifecycle / workflow (feature gaps)**
8. 🔴 **Approve navigates away** (`approve()` redirects to show). There is **no Archive action**, **no
   "Request changes / Return to draft"**, and no in-place transition. → Make every transition a
   **modal/inline action that refreshes in place**, and add the missing `archive` + `requestChanges`
   transitions (see §6).
9. 🔴 **`review_date` is shown but there's no review workflow.** "Review due / overdue" is a real state
   (the controller already computes `due_for_review`). → Surface it as a tab + hero cluster + a
   **"Record review"** action (bumps `review_date`, optionally creates a new version).
10. 🟠 **Version history exists and is good** (`SafeWorkProcedureVersion`, shown on `show.tsx`). → Keep it;
    render it as a section in the detail modal ("History"), with per-version `change_summary` + who/when.
11. 🔴 The link fields **`applicable_roles`, `applicable_sites`, `related_training`, `hazards_addressed`
    are dead JSON** — captured but never surfaced or linked. → Surface them in the detail modal as
    chips, and (read-only) on the related Site / role / training / hazard surfaces (§5).

**Permissions (backend smell — document in handover)**
12. 🔴 Procedures **piggyback on `hazards.*` permissions** (`hazards.view` to read,
    `hazards.manage|hazards.create` to write/approve). And **`hazards.manage` is NOT exposed to the
    frontend** in `HandleInertiaRequests` `can` map, so the UI can't branch on it. → Introduce dedicated
    `procedures.{view,create,manage,approve}` permissions (or at minimum expose the needed flags) and pass
    a `can: { view, create, manage, approve }` block to every procedures page. (Document in §6.)

**Reach / discoverability**
13. 🟠 Not on the **H&S hub** (`dashboard.tsx`) and **not in analytics**. → Add a hub module card and one
    analytics metric (§3.7).
14. 🟠 **Demo seeder excludes procedures** (`HealthSafetyDemoSeeder`) so the page looks empty in demos.
    → Note in handover for Claude Code to seed a realistic set.

---

## 3. Target spec — Register `/health-safety/procedures`
Structure it **exactly** like `events/index.tsx`.

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon` at the top (Draft → Review → Approved → In-use → Review-due stage).
- `HeroMedallion icon={FileText}`, `HeroStatusPill` ("Procedure library · synced…"), `h1`
  "Safe Work Procedures", one-line description.
- Top-right CTA: **New procedure** (opens the modal wizard, §4) + a **Library reports** `Popover`
  (export register, review-due list, by-category coverage), mirroring Events' board-reports popover.
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to its tab:
  - *Library status* → Approved (in force), Under review, Draft, Archived.
  - *Needs attention* → **Review due (≤30d)**, **Review overdue**, Unapproved >X days, Categories with
    no approved procedure (coverage gap).
- **`HeroComplianceBadges`** NZ chip row (e.g. WorkSafe-aligned coverage, Ngā Paerewa documentation,
  high-risk categories covered: manual handling / challenging behaviour / lone working / medication) —
  feed it counts/booleans, never pre-formatted strings.
- **Hero footer = the filter bar** (`HeroShell footer={…}`): `HeroSegmented` (review window) ·
  `EntityFilter` Site (drives `applicable_sites`) · selects for Category / Status / Role / Review-state ·
  right-aligned search · Clear. All drive server requests via `router.get`.
- **Right-click on the hero** (`onContextMenu`) → `ShiftContextMenu` quick actions: *New procedure*,
  *Export register*, *Review-due list →*, *Manage categories*.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`: All / Draft / Under review / Approved / **Review due** / Archived, each
with a `badge` from server `tabCounts`. Changing tab → `router.get(… preserveScroll)`.

### 3.3 Table + rows
- `RegisterTableHeader` with hint **"Right-click a row for the full lifecycle"** + `MousePointer2`.
- Columns: Ref · Title (+ category tone dot) · Category · Status · Version · Owner/Approved-by ·
  **Review date (with Overdue / Due-soon `FlagBadge`)** · Applies-to (sites/roles count chip).
- Each `<tr>`: `onClick → openProcedure(id)` (detail modal) · `onContextMenu → openRowCtx` · `tabIndex={0}`
  · Enter/Space to open · `hover:bg-muted/45` + focus ring. Copy the Events row exactly.
- Status / category / review tone via `StatusBadge` + `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Events' `openRowCtx`), gating each on `can` + current status:
- **View procedure** (detail modal) · **Edit** (modal wizard, edit mode) · **Submit for review**
  (draft→under_review) · **Approve** (under_review→approved, `can.approve`) · **Request changes /
  Return to draft** · **Record review** (bump `review_date`) · **New version** (opens edit wizard with a
  required `change_summary`) · **Archive** (critical tone) · separator · **Copy link** · **Open full
  page** (`/procedures/{id}` fallback). Every mutating item opens the relevant **modal/confirm**, never a
  bare navigation.

### 3.5 Detail-as-modal (`ProcedureDetailDialog`)
- Add a `detail: ProcedureDetail | null` prop (Inertia partial reload, `only: ['detail']`, triggered by
  `?procedure={id}`).
- Build on the same chrome as `EventDetailDialog`. Sections: **Overview** (purpose, scope, category,
  status, version, owner, review date) · **Steps** (numbered, with per-step safety notes) · **PPE &
  hazards addressed** (chips) · **Applies to** (sites / roles / related training chips) · **History**
  (version list with `change_summary`, who/when) · and an **Options footer bar** with the lifecycle
  actions from §3.4. Support `initialAction` so a context-menu action (e.g. "Approve") opens the modal
  straight onto that step. Closing drops `?procedure=` so `detail` returns null.

### 3.6 Workflow modals — every workflow is a modal (no navigate-away)
All POST to existing/added endpoints and refresh in place (`preserveScroll`, partial reload):
- **New / Edit procedure** → the **"Add client"-style wizard** (§4). New → `POST /…/procedures`; Edit →
  `PUT /…/procedures/{id}` with a required `change_summary` that writes a `SafeWorkProcedureVersion`.
- **Submit for review** → `POST /…/procedures/{id}/submit-for-review` (exists).
- **Approve** → `POST /…/procedures/{id}/approve` (exists) — convert from redirect to in-place + a small
  confirm/sign step (approver name + date, like a mini review footer).
- **Request changes / Return to draft** → **needs adding** (§6).
- **Record review** → **needs adding** (§6) — bumps `review_date`, optional new version + note.
- **Archive / Restore** → **needs adding** (§6).

### 3.7 Hub + analytics hooks
- **H&S hub** (`dashboard.tsx`): add a "Safe Work Procedures" module card (icon `FileText`, count of
  approved + a "review due" pill) linking to the register, styled like the other hub cards.
- **Analytics** (`analytics.tsx`): add one tile/series — e.g. **procedure review compliance** (% approved
  procedures within review date) or **category coverage**. Match the existing analytics chart styling.

---

## 4. New / Edit procedure = the "Add client" modal wizard (mirror exactly)

**Copy `resources/js/components/clients/add-client-dialog.tsx`** structurally: full-height `Dialog`
(`max-width ~1080px`), **left stepper rail** (icon + label + blurb per step, click to jump, completeness
bar at the bottom), header "Step N of M · {step}", 3px progress bar, scroll-contained body, footer with
**Back / Cancel / Continue → Save**. Compose `@/components/wizard/primitives`
(`Field, FieldErr, StepHead, SubHead, InfoCard, SelectInput, Segmented, ChipMulti, TilePicker, Ring`).
Validate per step on **Continue**; on server error **jump to the failing step** (`stepForError`). End on a
**Review & save** step (`ReviewCard` / `ReviewRow`, each card with an **Edit** jump) and a **Success pane**
with **"Add another"** + **"Open procedure"**. Submit with `form.post(... { preserveScroll: true,
preserveState: true })`; **stay in place** and refresh the register — never navigate to a full page.

**Suggested steps (map to the existing `store`/`update` validation):**
1. **Basics** — title (req) · reference number (req, unique) · category (`TilePicker`/`SelectInput`) ·
   purpose · scope.
2. **Hazards & PPE** — `hazards_addressed` (`ChipMulti`) · `ppe_required` (`ChipMulti`).
3. **Steps** — repeatable step rows (step number · description · safety notes) — the dynamic-array
   pattern from Add-client's conditions/contacts.
4. **Applies to** — `applicable_sites` (multi) · `applicable_roles` (multi) · `related_training` (multi).
   *(These are the HR/site hooks — §5.)*
5. **Review cycle & approval** — `review_date` · owner/approver. Draft saves as `draft`.
6. **Review & save** — `ReviewCard`s + Success pane ("Add another").

Edit mode = same wizard pre-filled, **requires a `change_summary`** (so each save writes a version), and if
the procedure was `approved` it returns to `under_review` on content change (existing behaviour — keep it).

---

## 5. Surfacing it elsewhere (read-only) — and the HR question

**HR: it is NOT in HR today (audited — zero references).** It needs to be, because procedures carry
`applicable_roles` and `related_training` — i.e. "which staff/roles must know this procedure". **The HR
build is Claude Code's job, not yours** (separate brief: `PROCEDURES_HR_SURFACING_PROMPT.md`). Your job
here is only to **design the read-only presentation** so Claude Code can wire it:
- A read-only **"Safe Work Procedures" panel** for the **staff profile** (`resources/js/pages/hr/employees/show.tsx`)
  and the self-service **`/hr/my`** — list of procedures applicable to the person's role(s), each with
  status + review date + an **Acknowledge** affordance, click → the same `ProcedureDetailDialog`
  (read-only mode). Make it visually obvious these are **library documents owned by H&S** (deep-link out
  for any edit). Hand the visual spec to Claude Code via the handover doc; do not build the backend.

**Site profile** (optional, read-only): a "Procedures that apply here" section scoped by `applicable_sites`,
click/right-click → the read-only detail dialog, "Open in library" deep-link. No create/edit here.

**Client profile** (optional, read-only): surface inside the existing risk/support tab as
"Procedures relevant to this person's support" (resolved from the person's home/site + support type),
read-only, deep-linking to the library. Never make it look like the procedure belongs to the person.

All mutation lives in **one place** — the `/health-safety/procedures` library.

---

## 6. Backend changes → WRITE THESE INTO THE HANDOVER DOCS

**Run your own backend audit pass, then record every change below (and anything you discover) in the
handover documents** so Claude Code can implement them. Create/append:
- `docs/health-safety-procedures-redesign/HANDOVER.md` — overview, screenshots, per-pass diff notes,
  open questions.
- `docs/health-safety-procedures-redesign/BACKEND_CHANGES.md` — the concrete controller/route/model/perm
  changes, each with file + line.
- Drop your visual exports in `.design-drops/procedures-redesign/`.

Backend changes to specify (confirmed by audit):
1. **`index()`** — return `tabCounts` (per §3.2), a `hero` block (the two clusters + NZ-badge counts), a
   `detail` prop loaded only when `?procedure=` is present (eager-load `versions.changedBy`, `approvedBy`,
   `creator`, `updater`), and a `can: { view, create, manage, approve }` block. Keep `paginate(25)`.
2. **New transitions** (model already supports the columns; lifecycle is painted but unreachable):
   - `POST /…/procedures/{id}/request-changes` → status `draft`/`under_review`, with a note.
   - `POST /…/procedures/{id}/archive` and `/restore` → status `archived` ↔ previous.
   - `POST /…/procedures/{id}/record-review` → set new `review_date` (+ optional version snapshot + note).
   - Convert `approve()` to return cleanly to an in-place partial reload (keep the route + name).
3. **Permissions** — add dedicated `procedures.{view,create,manage,approve}` to `RbacSeeder` and the
   `HandleInertiaRequests` `can` map (today procedures borrow `hazards.*`, and `hazards.manage` isn't even
   exposed to the frontend). Migrate the route middleware in `routes/health-safety.php` (lines ~109–132).
4. **Link integrity** — keep `applicable_roles` / `applicable_sites` / `related_training` as the
   integration hooks; note for Claude Code whether to formalise pivots later (HR acknowledgement is in the
   HR brief). Surface `hazards_addressed` in the detail modal.
5. **Analytics + hub** — add the procedures metric to the analytics controller and a hub card datum to the
   `dashboard.tsx` controller (counts: approved, review-due, coverage-gap categories).
6. **Seeder** — add Safe Work Procedures to `HealthSafetyDemoSeeder` so the redesign demos with real data.
7. **HR surfacing endpoints** — note (do not build) the read-only feeds for staff profile + `/hr/my`;
   full spec lives in `PROCEDURES_HR_SURFACING_PROMPT.md` (Claude Code).

---

## 7. Definition of done (acceptance criteria)
- [ ] `/health-safety/procedures` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`, two
      clusters, `WorkflowRibbon`, and **right-click quick actions**.
- [ ] `TabStrip` with live server counts (incl. **Review due**); filters live in the hero footer and drive
      `router.get`; `paginate(25)` + shared pagination.
- [ ] Every row supports **left-click → detail modal** and **right-click → ShiftContextMenu**; keyboard
      accessible; **semantic tokens only** (zero raw hex/oklch/`border-l-*`; bespoke `categoryBadge`/
      `statusBadge` removed in favour of `StatusBadge` + tone maps).
- [ ] **New / Edit procedure is the "Add client"-style modal wizard** (left rail, primitives, review step,
      Success pane "Add another"); **no full-page create/edit/show** as a primary path (routes kept as
      deep-link fallbacks).
- [ ] Full lifecycle **draft → under_review → approved → archived** (+ request-changes, record-review) is
      reachable from the UI and persisted in place — nothing navigates away.
- [ ] Version history renders in the detail modal; each edit writes a `SafeWorkProcedureVersion` with a
      `change_summary`.
- [ ] Procedures appear on the **H&S hub** and as **one analytics metric**.
- [ ] Read-only presentation designed for **HR staff profile + `/hr/my`** (and optionally Site/Client),
      with the HR backend handed to Claude Code via the handover doc.
- [ ] **Handover docs written**: `docs/health-safety-procedures-redesign/HANDOVER.md` +
      `BACKEND_CHANGES.md`, with every backend change (§6) recorded by file + line.
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` violations except the sanctioned
      on-dark hero buttons (copy the existing eslint-disable comments from the kit).

## 8. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0 and copy
  `add-client-dialog.tsx`.
- ❌ Don't keep any navigate-away workflow, the full-page create/edit form, or the bare "View" link as the
  primary action.
- ❌ Don't build the HR backend (model/migration/controller) — that's Claude Code (§5).
- ❌ Don't add raw colours, GBP/US/OSHA framing, or mobile-app framing.
- ❌ Don't fork a parallel filtering engine — server-side, like Events.

## 9. Suggested order
1. Backend audit + write handover docs (§6): `index()` (tabCounts + hero + detail + can), new transitions,
   permissions.
2. Register page: hero (with right-click) → tabs → table + right-click → detail modal.
3. New/Edit modal wizard mirroring **Add client** → wire approve/submit/request-changes/record-review/
   archive as in-place modals.
4. Hub card + analytics metric.
5. Read-only HR/Site/Client presentation (visual only; hand HR backend to Claude Code).
6. Cleanup, lint/types, screenshot each surface and diff against Events/Incidents/Analytics.
