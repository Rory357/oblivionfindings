# First Aid Register — Gold-Standard Redesign + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: rebuild `/health-safety/first-aid` to the
Health & Safety **gold standard** — same hero, right-click and detail-modal idioms as
`/health-safety/events`, `/incidents` and `/health-safety/analytics` — and make every **workflow
(create / edit / log) follow the Client page "Add client" modal UX** exactly. Then make the module
consistent across every surface First Aid touches.

**Before you build anything: run your own audit pass (see §7), then record all backend work and any
remaining frontend/QA work in handover files (see §6). Do not silently change backend code — capture
it for the engineer in the handover.**

---

## 0. Read these first (do not skip — match them, don't reinvent)

**Gold-standard PAGES to mirror exactly (page chrome):**
- `resources/js/pages/health-safety/events/index.tsx` ← the closest analogue. Copy its structure
  (hero → tabs → filter bar → right-click rows → detail-as-modal).
- `resources/js/pages/incidents/index.tsx` ← sibling register, identical chrome.
- `resources/js/pages/health-safety/analytics.tsx` ← hero + `HeroSegmented` period/lens filter reference.

**Gold-standard MODAL/WORKFLOW to mirror exactly (every create/edit/log flow):**
- `resources/js/components/clients/add-client-dialog.tsx` ← **the workflow reference.** Match its UX:
  full-height modal, left **stepper rail** with per-step icons + blurbs, a **profile-completeness
  meter**, top progress bar, scroll-contained body, custom footer (Back / Cancel / Continue, and on
  the review step **"Save & add another"** + primary **Create**), client-side per-step validation that
  jumps to the first failing step, and a **success pane** afterwards. It is built on the shared wizard
  primitives below — compose those, styled to read identically.

**Shared kits you MUST compose (never hand-roll a primitive these already provide):**
- Hero: `@/pages/health-safety/components/hs-hero-kit`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges,
  HeroSegmented, HeroSummaryStrip, HeroSummaryMetric, fmt, type Tone, DOT_CLASS`
- Rows: `@/pages/health-safety/components/register-row-kit`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Right-click + tabs + filters: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem,
  type RosterTabTone, EntityFilter, type EntityFilterOption`
- Wizard shell: `@/components/wizard/shell`
  → `WizardShell, type WizardStep, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow`
- Wizard primitives (the Add-client family): `@/components/wizard/primitives`
  → `Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput, Segmented, ChipMulti, TilePicker,
  Ring, type IconType`
- Workflow ribbon: `@/pages/health-safety/components/workflow-ribbon` → `WorkflowRibbon` (stage `report`)
- Pagination: `@/components/ui/laravel-pagination` → `LaravelPagination`
- Detail-as-modal reference: `@/components/health-safety/event-detail-dialog` → `EventDetailDialog`
  (build a `FirstAidDetailDialog` on the same chrome).

**The non-negotiable house rules (from the gold-standard file headers):**
- **Semantic tokens only.** No raw oklch / hex / `border-l-red-600` / `bg-amber-500`. Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` / `DOT_CLASS` maps.
- App-primary gradient only on the hero (no per-site brand tint).
- **NZ-only.** en-NZ dates, NZD, NHI, ACC, WorkSafe-notifiable, Ngā Paerewa NZS 8134:2021. Do not
  "fix" to GBP/US, do not use TRIR.
- **Web-only.** No phone frames, no mobile-app treatments. This is a desktop web app.

---

## 1. What this is, and the surfaces (CONFIRMED by audit — re-verify in §7)

First-aid treatments are **`FirstAidRecord`** rows (`app/Models/FirstAidRecord.php` — soft-deletes +
`AuditableChanges` already on). They belong to a `site`, an optional `treatedPerson` (User),
a `firstAider` (User), and an optional `relatedIncident` (`ClientIncident`). One controller drives
the register: `app/Http/Controllers/HealthSafety/FirstAidController.php` (`index` + `store` only).
Routes: `routes/health-safety.php` (`first-aid.` group, ~line 79; GET `index`, POST `store`).

| Surface | Route / file | Status today |
|---|---|---|
| **Register page** `/health-safety/first-aid` | `index()` → `resources/js/pages/health-safety/first-aid/index.tsx` | **Off-pattern. Primary target.** |
| **H&S command-centre launcher** | `report-launcher.tsx` `first_aid` tile (`inPlace`) → shared `form-wizard` driven by `wizard-configs.tsx` `firstAidConfig` (POSTs `/health-safety/first-aid`) | **A SECOND, divergent create UI. Reconcile to one (see §4).** |
| **Client profile** | `clients/profile/flows.tsx` records "First aid given" as an incident treatment type | **No read-only first-aid panel; no `client_id` link on the record (gap — see §5).** |
| **HR** | `is_first_aider` on `hr/employees/show.tsx`, `hr/directory/show.tsx` | First-aider pool — should feed the "First aider" picker (see §5). |
| **H&S dashboard** | `dashboard-tabs.tsx` ("Certified first-aiders on every shift") | Metric surface — keep counts consistent. |
| **Incidents / Injuries** | `report-incident-dialog.tsx` ("First aid only"), `injuries/create.tsx` ("First Aid Only") | First-aid ↔ incident linkage (see §2 dead column + §3.6). |
| **Sidebar nav** | `app-sidebar.tsx` → `/health-safety/first-aid` | Fine. |

---

## 2. Audit — gaps & issues to fix

Numbered so you can work through them. Severity: 🔴 breaks consistency / feature gap, 🟠 polish.

**Register page `first-aid/index.tsx`**
1. 🔴 Uses the generic `PageHero`, **not** `HeroShell` + hs-hero-kit. No status pill, no medallion,
   no Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**, no `WorkflowRibbon`.
   → Rebuild the hero to match Events/Incidents.
2. 🔴 **No right-click anywhere.** Rows expose no actions at all (no three-dot menu, no context menu).
   → Add `ShiftContextMenu` on every row **and** right-click quick-actions on the hero banner.
3. 🔴 **No tabs.** → Add a `TabStrip` (e.g. All / Last 30 days / Ambulance called / Linked to incident /
   Unlinked / This site) with server-side counts, like Events' `tabCounts`.
4. 🔴 **No filter bar — even though the controller already supports filters.** `index()` accepts
   `site_id, treated_person_type, injury_illness_type, from, to, q` and passes a `filters` prop, but
   the page ignores it entirely. → Add the filter bar in the **hero footer** (`HeroShell footer={…}`)
   driving `router.get`, mirroring Events/analytics.
5. 🔴 **The create form is a monolithic single `Dialog`**, not a wizard and not the Add-client UX.
   → Replace with a `WizardShell` create flow styled exactly like `add-client-dialog.tsx` (see §3.7).
6. 🔴 **Rows are not interactive** — no click, no detail view, no edit, no delete, not keyboard
   accessible. → Left-click opens a **detail-as-modal**; right-click opens the context menu; rows get
   `tabIndex={0}` + Enter/Space, focus ring, `hover:bg-muted/45` (copy the Events row exactly).
7. 🔴 **Dead "Incident" column.** The table reads `r.incident_id`, but the model field is
   `related_incident_id` (+ `incident_reported` boolean) — so the column is always "N". The create
   form never captures incident linkage at all. → Wire the real fields; add a "Link to incident /
   mark incident-reported" workflow (modal).
8. 🔴 **`any[]` types throughout** (`records: { data: any[] }`, `r: any`). → Define proper row/prop
   types like Events (`FirstAidRow`, `Paginated<T>`, `Filters`, `Props`).
9. 🟠 **FE/BE enum drift** (confirm in §7): `store()` allows `injury_illness_type: fall` — missing
   from the page's `INJURY_TYPES`; `treatment_outcome` allows `returned_to_work, sent_to_medical,
   sent_to_hospital` — missing from the page's `OUTCOMES`, which also lists `ambulance_called` as an
   outcome while it's *also* a checkbox. → Make one canonical option set, FE = BE.
10. 🟠 `store()` already honours a **`stay`** param (Save & add another) — the page never sends it.
    → The Add-client-style "Save & add another" button must POST with `stay`.

**Backend `FirstAidController` (record for the engineer — see §6)**
11. 🔴 `index()` returns no `tabCounts`, no `hero` cluster/badge block, no `can.manage`, and stats are
    only 3 raw counts. → Extend to feed the new hero + tabs.
12. 🔴 **No `show` / `update` / `destroy` / detail endpoint.** Right-click View/Edit/Delete and the
    detail-as-modal have nothing to call. → Add them (the model already soft-deletes + audits).
13. 🟠 **Permissions piggyback on `hazards.*`** (`hazards.view`, `hazards.manage|hazards.create`) —
    there is no dedicated first-aid permission. → Flag for a decision; keep behaviour identical unless
    told otherwise.
14. 🟠 No **client linkage**: `treated_person_id` is a `User` FK; a client treated as a patient can't
    be linked to their profile. → See §5 (backend gap to record, not silently add).

---

## 3. Target spec — Register page `/health-safety/first-aid`

Structure it **exactly** like `events/index.tsx`.

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon current="report"` at the top.
- `HeroMedallion icon={HeartPulse}`, `HeroStatusPill` ("First-aid register · synced…"), `h1`
  "First Aid Register", one-line description.
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab:
  - *Live · this period* → Treatments (30d), Ambulance called, Sent to hospital, Linked to incident.
  - *Needs attention* → Incident-reportable but unlinked, Awaiting follow-up/monitoring, Missing
    first-aider, Treatments today.
- **`HeroComplianceBadges`** NZ chip row (e.g. certified first-aiders on shift, AED/first-aid-kit
  checks if available, WorkSafe-notifiable arising from a treatment) — feed it counts/booleans from
  the controller, never pre-formatted strings.
- **Hero footer = the filter bar** (`HeroShell footer={…}`): `HeroSegmented` period pills ·
  `EntityFilter` Site · selects for Person-type / Injury-type / Outcome / First-aider · right-aligned
  search · Clear. All drive server requests via `router.get` (reuse the controller's existing filters).
- **Right-click on the hero** (`onContextMenu` → `ShiftContextMenu`): *Record first aid*,
  *Export CSV*, *Go to Events register*, *Go to Analytics*.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`, each with a `badge` from server `tabCounts`. Changing tab does
`router.get(… preserveScroll, preserveState)`.

### 3.3 Table + rows
- `RegisterTableHeader` with hint **"Right-click a row for treatment actions"** + `MousePointer2`.
- Columns: When · Person treated (name + type badge, tone dot via `entityTone`) · Injury / illness
  (type + body part) · Treatment given (truncated) · Outcome (tone) · First-aider · Incident (real
  `FlagBadge`: Linked / Reportable / —).
- Each `<tr>`: `onClick → openRecord(id)` (detail modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, focus ring, `hover:bg-muted/45` — copy the Events row exactly.
- All severity/outcome/type tone via `TONE_BG` / `TONE_DOT` / `FlagBadge`. No raw colours.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Events' `openRowCtx`):
- **View treatment** (opens detail modal) · **Edit treatment** · **Link to incident** / **Mark
  incident-reported** · **Add follow-up note** · **Record outcome / escalation** · separator ·
  **Copy link** · **Delete** (critical tone, soft-delete).
- Gate each on `can.manage`. Each mutating item opens the relevant **modal** (below) — never a bare
  navigate-away.

### 3.5 Detail-as-modal
- Add a `detail: FirstAidDetail | null` prop (Inertia partial reload, `only: ['detail']`, opened by a
  `?record=` param; closing drops the param so `detail` returns null) — exactly like Events' `openEvent`.
- Build a `FirstAidDetailDialog` on `WizardShell`/`EventDetailDialog` chrome: sections Overview /
  Injury & treatment / Incident link / History (audit trail), with an **Options footer bar** carrying
  the lifecycle actions. Support `initialAction` so a context-menu action (e.g. "Link to incident")
  opens the modal straight onto that step.

### 3.6 Workflow modals — **every one follows the Add-client UX** (§3.7)
All POST to first-aid endpoints and refresh in place (`preserveScroll`, partial reload):
- **Record first aid** → create wizard → `POST /health-safety/first-aid` (with `stay` for "Save &
  add another").
- **Edit treatment** → same wizard pre-filled → `PUT /health-safety/first-aid/{record}` *(needs the
  `update` route — record in handover, §6)*.
- **Link to incident / Mark incident-reported** → action modal writing `related_incident_id` /
  `incident_reported`.
- **Add follow-up note / Record outcome** → action modal *(may need a small endpoint — record in §6)*.
- **Delete** → confirm modal → `DELETE /health-safety/first-aid/{record}` (soft delete) *(needs route — §6)*.

### 3.7 The create/edit wizard = Add-client modal UX (non-negotiable)
Build on `WizardShell` + the wizard primitives, **styled to read identically to
`add-client-dialog.tsx`**: left stepper rail (icon + label + blurb per step), completeness meter,
top progress bar, per-step client-side validation that jumps to the first failing step on submit,
footer with Back / Cancel / Continue and — on the review step — **"Save & add another"** (secondary)
+ **"Record treatment"** (primary), then a `WizardSuccessPane`. Suggested steps (map the existing
`store` fields + `firstAidConfig`):
1. **Who & where** — Site (`SelectInput`), Person treated (name + `Segmented` person-type; if
   *client*, a client `SelectInput` that sets the linkage — see §5), Treatment date/time, First-aider
   (`SelectInput`, sourced from `is_first_aider` staff — §5).
2. **Injury / illness** — Injury type (`TilePicker` or `SelectInput`), Body part, Description
   (`Textarea`).
3. **Treatment & outcome** — Treatment given, Outcome (`SelectInput`), Ambulance called (`Switch`),
   any escalation.
4. **Incident link & notes** — Link to incident / mark incident-reported, first-aider notes.
5. **Review & save** — `ReviewCard` / `ReviewRow` summary, then Save & add another / Record.

Use the **same** wizard for the command-centre launcher path (§4) so there is exactly one
record-first-aid experience.

---

## 4. Reconcile the two create paths (single source of truth)

Today the **command centre** (`report-launcher.tsx` → `firstAidConfig` in `wizard-configs.tsx` →
shared `form-wizard`) and the **register page** (bespoke `Dialog`) are two different UIs that POST to
the same endpoint. Pick the §3.7 Add-client-style wizard as the canonical one and have **both**
entry points open it:
- Register page "Record first aid" CTA → the wizard.
- Command-centre `first_aid` launcher tile (`inPlace`) → the **same** wizard component (replace/realign
  `firstAidConfig` so the field set, labels and enums match the canonical wizard, or render the wizard
  directly). No divergent field lists between the two.

---

## 5. Other surfaces — parity & gaps

- **First-aider picker:** source the "First aider" select from staff with `is_first_aider = true`
  (HR), not all users. *(Backend: the controller currently returns all `staff` — record the filter
  change in §6.)*
- **Client profile (read-only):** where `treated_person_type = client`, surface a read-only
  "First-aid treatments" section on the client profile (compact rows → open the same
  `FirstAidDetailDialog` in read-only mode; no create/edit there). **This needs a client linkage that
  doesn't exist yet** (`FirstAidRecord` has no `client_id`; `treated_person_id` is a `User` FK). **Do
  not add the column silently — record it in the backend handover (§6) as the prerequisite**, and gate
  the panel behind it.
- **Dashboard / Analytics:** keep first-aid counts consistent with the hero clusters; if
  `/health-safety/analytics` should include first-aid volume/outcomes, note it (don't expand scope
  without recording it).
- **Incidents/Injuries:** ensure "first aid only" / "First Aid Only" options and the
  `related_incident_id` link stay coherent with the new incident-link workflow.

---

## 6. Backend changes → **record in handover files, do not silently apply**

Create/append two handover docs in the repo root and write everything an engineer needs there:
- **`FIRST_AID_BACKEND_HANDOVER.md`** — all server-side work, with file/line refs, proposed
  signatures, validation, and migration notes.
- **`FIRST_AID_HANDOVER.md`** — remaining frontend/QA/follow-ups, screenshots checklist, and anything
  deferred.

Backend items to specify in the handover (re-audit first, §7):
- `index()`: add `tabCounts`, a `hero` block (cluster counts + NZ badge counts/booleans), `can: { manage }`,
  and load `detail` only when `?record=` is present (eager-load `site`, `firstAider`, `treatedPerson`,
  `relatedIncident`, audit history). Keep the existing `paginate(25)` + `withQueryString()` + filters.
- Add **`show` / `update` / `destroy`** (soft delete) routes + controller methods in the `first-aid.`
  group under the same permission middleware.
- Add the **incident-link / mark-incident-reported** action (writes `related_incident_id`,
  `incident_reported`) and any **follow-up note** endpoint the detail modal needs.
- **Enum reconciliation:** one canonical list for `injury_illness_type` and `treatment_outcome`,
  matched FE↔BE (fix `fall`, `returned_to_work`, `sent_to_medical`, `sent_to_hospital`, and the
  `ambulance_called`-as-outcome duplication).
- **First-aider source:** filter `staff` to `is_first_aider`.
- **Permissions:** decision on dedicated `first_aid.*` vs continuing to reuse `hazards.*`.
- **Client linkage:** proposed `client_id` (nullable FK) migration + backfill rule for the read-only
  client panel (§5) — prerequisite, not in scope to apply here.

---

## 7. Claude Design — run your OWN audit pass FIRST

Before writing code, independently re-audit and confirm/correct everything above:
1. Re-read `first-aid/index.tsx`, `FirstAidController.php`, `FirstAidRecord.php`,
   `routes/health-safety.php`, `wizard-configs.tsx` (`firstAidConfig`), `report-launcher.tsx`, and
   each surface in §1.
2. Diff the gold-standard imports/exports in §0 against the current repo (kits move occasionally).
3. Confirm the FE/BE enum drift, the `incident_id` vs `related_incident_id` mismatch, and the missing
   `show/update/destroy`.
4. Grep for any other First-aid touch points not listed here and add them.
Write your findings (confirmed / corrected / newly found) at the top of `FIRST_AID_HANDOVER.md`, then
build.

---

## 8. Definition of done (acceptance criteria)
- [ ] `/health-safety/first-aid` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`,
      two clusters, `WorkflowRibbon`, and **right-click quick actions** on the hero.
- [ ] `TabStrip` with live server `tabCounts`; the **filter bar lives in the hero footer** and drives
      `router.get` using the controller's existing filters; `LaravelPagination`.
- [ ] Every row supports **left-click → detail modal** and **right-click → `ShiftContextMenu`**;
      keyboard accessible; semantic tokens only (zero raw hex/oklch/`border-l-*`).
- [ ] **Every workflow is a modal built on the Add-client UX** (`WizardShell` styled like
      `add-client-dialog.tsx`): Record, Edit, Link-to-incident, Add-note, Delete — none navigates away;
      "Save & add another" works via `stay`.
- [ ] The command-centre launcher and the register page open the **same** record-first-aid wizard
      (no divergent field sets).
- [ ] The dead "Incident" column is replaced with the real `related_incident_id` / `incident_reported`
      linkage + workflow.
- [ ] Proper TypeScript types (no `any[]`); FE option sets equal the BE enums.
- [ ] `FIRST_AID_BACKEND_HANDOVER.md` + `FIRST_AID_HANDOVER.md` written, with the §6 backend work and
      your §7 audit findings; no backend changed silently.
- [ ] `npm run lint` + typecheck clean; only the sanctioned on-dark hero `eslint-disable` comments
      (copy them from the kit), no other `no-restricted-syntax` violations.

## 9. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0.
- ❌ Don't keep the monolithic `Dialog` or any navigate-away workflow as the primary action.
- ❌ Don't leave two different "record first aid" UIs.
- ❌ Don't silently add backend routes/columns/migrations — record them in the handover.
- ❌ Don't add raw colours, GBP/US formatting, TRIR, or mobile-app framing.

## 10. Suggested order
1. §7 audit pass → write findings into `FIRST_AID_HANDOVER.md`; draft `FIRST_AID_BACKEND_HANDOVER.md`.
2. Page chrome: hero → tabs → hero-footer filters → typed table + right-click rows.
3. Detail-as-modal (`FirstAidDetailDialog`).
4. The Add-client-style create/edit wizard; wire "Save & add another" + reconcile the launcher path (§4).
5. Incident-link / note / delete action modals.
6. Surfaces parity (first-aider source, client read-only panel gated on the linkage gap).
7. Lint/types, screenshot each surface, finalise both handover docs.
