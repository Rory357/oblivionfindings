# HR "Compliance" Hub Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/compliance`, `/hr/compliance/matrix`, `/hr/compliance/calendar`, `/hr/compliance/vetting`, `/hr/compliance/drivers`, `/hr/compliance/staff/{id}` (and each tab + each modal) and diff against the gold-standard pages/components before continuing. Start with the audit in §A, then build §B–§N. **Anything you discover that needs backend/data work goes into §L "Backend handoff for Claude Code" — append to it as you go, and mirror the final list into a new `HR_COMPLIANCE_BACKEND_HANDOVER.md` at repo root so Chane has one clean hand-off for Claude Code.**

**Page (canonical):** `/hr/compliance` — the Staff Compliance hub. Tabs: **Overview · Matrix · Renewals · Vetting · Drivers**.
**Frontend:**
- `resources/js/pages/hr/compliance/index.tsx` (Overview — staff compliance table)
- `resources/js/pages/hr/compliance/matrix.tsx` (Matrix — requirements × role/site grid)
- `resources/js/pages/hr/compliance/calendar.tsx` (Renewals — expiry calendar)
- `resources/js/pages/hr/compliance/staff-detail.tsx` (per-staff drill-down — **today a dead end**)
- `resources/js/pages/hr/vetting/{index,show,create,edit}.tsx` (Vetting register + detail/forms)
- `resources/js/pages/hr/drivers/index.tsx` (Driver eligibility register — **no detail/edit page exists**)
- `resources/js/components/hr/compliance-tabs.tsx` (the 5-tab strip)

**Backend:** `app/Http/Controllers/Hr/ComplianceController.php` (index, staffDetail — **read-only, no write methods**), `ComplianceMatrixController.php` (requirement CRUD + matrix assign), `ComplianceCalendarController.php` (read-only), `VettingController.php` (full CRUD + clear/renew/captureConsent), `DriverEligibilityController.php` (index/store/update/approve/suspend). Routes: `routes/hr.php` (compliance block ~L251–263, vetting block ~L279–294, drivers block ~L301–310).
**Services / jobs:** `app/Domain/Hr/Services/ComplianceMatrixService.php` (`evaluateStaff`, `getHardStopFailures`, `getSoftWarnings`, `canAssignToShift`, `getComplianceSummary`), `LiveComplianceValidator.php` (per-type hard-stop validators), `app/Domain/Hr/Jobs/EvaluateComplianceMatrixJob.php`, `SendExpiryRemindersJob.php`, `app/Domain/Hr/Notifications/ComplianceExpiryNotification.php`, contract `ComplianceProviderInterface.php`.
**Models:** `app/Domain/Hr/Models/HrComplianceRequirement.php` (`code, name, category, check_type, validity_months, renewal_reminder_days, hard_stop, is_active`), `HrComplianceMatrix.php` (`role, site_type, requirement_id, is_mandatory, notes`), `HrStaffComplianceStatus.php` (`user_id, requirement_id, status, evidence_type, evidence_id, valid_from, expires_at, exemption_reason, exempted_by, last_checked_at, next_check_at` — **no `notes`/`evidence_url` column despite the UI showing them**), `HrDriverEligibility.php`, and `app/Models/StaffBackgroundCheck.php` (vetting). Enums: `check_type ∈ {training_course, credential, background_check, policy_attestation, manual}`; `status ∈ {compliant, expiring_soon, expired, not_started}`.
**Gold-standard modal to clone:** `resources/js/components/clients/add-client-dialog.tsx`. **Premium modal reference:** `resources/js/components/hr/leave-request-dialog.tsx` (the "New Request" flow). **Hero reference:** `resources/js/components/hr/my-hr-hero.tsx`.

---

## 0. Mission

Make the **Compliance hub** (`/hr/compliance` + its five tabs and per-staff detail) a **premium, end-to-end staff-compliance surface** that feels identical in quality to our gold-standard pages — **`/meds/today`**, **`/my-day`**, **`/health-safety`**, **`/hr/people`** — and reuses their exact components and tokens.

Today the hub is functional but dated and **thin**, and the loop never closes:

- A generic flat `PageHero`; stat cards, filters and empty states differ on every tab.
- **Overview** is a read-only table → a single "View" link. **Per-staff detail is a dead end**: it shows `evidence_url` / `evidence_notes` and a per-requirement status, but there is **no way to record a check, upload evidence, set dates, or waive a requirement** — and **no backend endpoint exists for any of it**. So a manager can *see* that someone is non-compliant but can never *act*.
- **Matrix** edits requirements **inline in the table row** (awkward), has no confirm-on-delete, no toasts, no bulk assign.
- **Renewals (calendar)** is a **read-only list** — no drill-down, no date-range filter, no "remind / mark renewed" actions, employee names aren't even links.
- **Vetting** uses **full-page** create/edit forms + a **thin** consent modal; **Drivers** uses **small thin modals** (Add Driver, Suspend) and has **no detail/edit page at all** — two different interaction models for the same job.
- **Vetting and Drivers are siloed**: a person's background-check and driving status never appear on the Overview or their per-staff page, even though both feed shift eligibility.

**Result:** every record / edit / assign / waive / evidence / vetting / driver flow becomes a **full Add-Client-style wizard** (stepper rail, completeness meter, validation, review, Save & add another, uploads). One **golden hero** fitted to compliance (no clock). **Five real tabs** with right-click everywhere. The **per-staff page becomes the action hub** that finally closes the loop (record · evidence · waive · remind). Vetting + Drivers convert to the same wizard pattern, gain a Drivers detail page, and surface back onto Overview. Bring it to parity with the gold-standard pages.

---

## 1. Non-negotiables

1. **Keep the 5-tab model and make every tab premium.** Overview · Matrix · Renewals · Vetting · Drivers, on the shared tab kit (not the current bespoke strip wiring). Tabs stay permission-gated (`hr.compliance.view/manage`, `hr.vetting.view`, `hr.driver.view`).
2. **Reuse the kit — never hand-roll a primitive we already have.** No new bespoke widgets, no raw hex (ESLint blocks it — colours come from design tokens in `resources/css/app.css`). Everything in §2 is the source of truth.
3. **Information-gathering = modals.** Every record-status / edit-requirement / assign / waive / add-vetting / add-driver / record-consent flow is a **full wizard dialog** cloned from `add-client-dialog.tsx` — **not** an inline form, **not** a thin 1–3-field dialog, and **not** a full-page route. Each modal carries the full field set + a review step. Reading detail can navigate to a page or open a sheet.
4. **Close the loop.** No dead-end screens and **no dead buttons**. Every action either hits a real route or is appended to §L. The per-staff page must let a manager *do* something about every status it shows.
5. **Don't fork data another module owns.** Vetting lives on `StaffBackgroundCheck`; drivers on `HrDriverEligibility`; requirement status on `HrStaffComplianceStatus`. Unify how they *surface*, but don't invent a parallel store. Reconcile evidence storage (§L) rather than adding a fourth pattern.
6. **Web-only desktop app. No phone frames.** Design for mouse + keyboard: hover states, **right-click menus**, keyboard shortcuts. (Mobile app comes later.)
7. **Locale stays NZ.** `en-NZ`, NZ vetting vocabulary (Police vetting / MOJ / Children's Act safety checks), NZTA licence classes/endorsements. Do **not** switch to UK DBS / GBP.
8. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface and diff vs the reference pages. Don't move on with a broken pass.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero**
- Golden band: clone `resources/js/components/hr/my-hr-hero.tsx` — its `HERO_STYLE` (the `linear-gradient` over `--primary` + `boxShadow`; re-themes per tenant) and the injected amber accent `--hr-amber` / `--hr-amber-soft`. **Omit the clock** — on My HR the clock is a separate child `<MyHrClockCard>` (`resources/js/components/hr/my-hr-clock-card.tsx`); reuse means simply **not rendering that child**. Reuse `HeroStat` (label + big tabular value, clickable / `href`) and `QuickAction` (icon + label).
- Richer KPI-cluster + compliance-chip reference: `resources/js/pages/health-safety/components/hs-hero-kit.tsx` (`HeroShell`, `HeroStatusPill`, `HeroMedallion`, `HeroCluster`/`HeroClusterTile`, `HeroComplianceBadges`, `HeroSegmented`, `HeroSummaryStrip`). This is the closest parallel to compliance — use it for the at-a-glance risk cluster.
- Generic fallback only if needed: `PageHero` from `@/components/page/page-hero` with `category="hr"`.

**2.2 Modals / wizards**
- Clone `resources/js/components/clients/add-client-dialog.tsx`. Markers to match exactly: `Dialog`+`DialogContent` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, a **left stepper rail** (`w-[248px]`, `bg-sidebar`) with per-step icon + blurb + check-on-complete, a **completeness meter** at the rail foot, header "Step X of N", a **top progress bar**, scroll-contained body, footer with Back / Cancel / **Save & add another** / Create.
- Engine: Inertia `useForm`; a `STEPS` array (`{key,label,icon,blurb}`); client-side `validateStep(key, data)`; `stepForError(field)` to jump to the step that owns a server error; `SuccessPane` after create; `resetAll()` for "Save & add another"; **`forceFormData: true` whenever a file (evidence / certificate / licence scan) is involved.**
- Built from `@/components/wizard/primitives` (`Field`, `FieldErr`, `StepHead`, `SubHead`, `InfoCard`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `Ring`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`) and `@/components/wizard/shell` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`) + the `useWizard(stepCount)` state machine. HR re-exports the whole kit via `@/components/hr/wizard.ts` — import from there to stay visually identical.
- Premium idioms to copy from `leave-request-dialog.tsx`: a **live preview** side-panel pinned via `railExtra` fed by a debounced `/preview` fetch (use for "who/what this affects" — e.g. a waiver's shift impact, an assign's staff count), per-type accent tinting, review-step warning banners, optional confetti + `toast` on success. People-picker: `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`).
- Base shadcn: `@/components/ui/` — `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right-click menus + hover actions**
- `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState`) — portal-rendered, viewport-flipping, Esc/outside-click close, icon + label + `kbd` + tone. Cleanest reuse is to copy the hook shape in `resources/js/pages/operations/handovers/components/handover-context-menu.tsx` (`useHandoverContextMenu` → returns `{ openCtx, menu }`). Wire via `onContextMenu={(e) => openCtx(e, row)}`.
- Lightweight inline row hover: `resources/js/pages/my-day/components/hover-action.tsx` (`HoverAction`).

**2.4 Cards / tables / states / badges**
- `@/components/ui/status-badge` (`StatusBadge`) **everywhere** — do not hand-map status colours (the four compliance states + vetting/driver states all go through it).
- `@/components/ui/card`, `table`, `@/components/ui/empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `skeleton-table`, `@/components/ui/laravel-pagination`, `@/components/ui/checkbox`.
- KPI tiles: `@/components/page/stat-tile` (`StatTile` — `label/value/icon/tone/subtitle/trend/href/placement`; `tone="compliance"` exists). Use `placement="hero"` inside the golden band.
- Filters: `@/components/filter-bar` (`FilterBar` — `fields/values/onChange/onReset/activeCount`). One filter pattern across all five tabs.

**2.5 Tabs**
- `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab, { param, syncUrl })`) built on `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, arrow/Home/End keys, `onItemContextMenu`, `decorations`, `trailing`). Keep `compliance-tabs.tsx` as the wrapper but ensure it routes through this kit and supports right-click on the strip (§K).

**2.6 Tokens & flourishes**
- Tokens only, from `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--category-compliance`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`.
- Toasts: **sonner** (`<Toaster>` already mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action.
- Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## A. Audit & benchmark first (do this before building)

Study `/meds/today`, `/my-day`, `/health-safety`, `/hr/people` and **interact** with them — they are the parity bar. Then open all five compliance tabs + a staff-detail page and fill in the checklist; paste the results back as your first pass.

**Checklist**
- [ ] Screenshot each current surface (Overview, Matrix, Renewals, Vetting index/show/create/edit, Drivers, Staff detail). Note every hand-rolled element that has a kit equivalent.
- [ ] Confirm the **write-path gap**: there is no `ComplianceController@storeStatus/updateStatus/exempt`, no evidence upload route, and `HrStaffComplianceStatus` has **no `notes` column** (only `evidence_type`/`evidence_id`, with no polymorphic loader) — yet `staff-detail.tsx` renders `evidence_url`/`evidence_notes`. Document exactly where the UI promises data the backend can't store. Seeds §L.
- [ ] Confirm how a `HrStaffComplianceStatus` row is actually created/recalculated today: only via `ComplianceMatrixService::evaluateStaff()` (training/credential/background_check/attestation checkers) and `EvaluateComplianceMatrixJob`. Note that `check_type='manual'` **always passes** in `LiveComplianceValidator` (can never hard-stop) — so manual requirements have no completion path. Seeds §L.
- [ ] List every compliance/vetting/driver route that exists vs every action the new UI needs; the delta seeds §L.
- [ ] Trace **hard-stop → rostering**: `ComplianceMatrixService::canAssignToShift()` exists and Overview already computes `future_shifts_affected`, but confirm whether shift assignment actually calls it. Note the integration gap.
- [ ] Confirm Vetting (`StaffBackgroundCheck`) and Drivers (`HrDriverEligibility`) are **not linked** to `HrStaffComplianceStatus`, and that the validator hard-codes a single `police_check` background type. Seeds §L + §M.

> **Known gaps the audit already surfaced** (confirm, then fix):
> - **Overview:** read-only table, single "View" link; no bulk actions; no export; no risk stratification; vetting/driver status absent; stat cards bespoke.
> - **Matrix:** create/edit are **inline table forms** (thin, no validation surface, no toast); no confirm-on-delete; no bulk role assignment; renewal-period "required vs optional" unclear; matrix toggles have no feedback.
> - **Renewals (calendar):** purely read-only; type-filter only (no date range, no "next 30/60/90d"); rows non-interactive; employee not clickable; no per-month counts; no actions.
> - **Vetting:** create/edit are **full pages** (inconsistent with everything else); consent modal is **thin** (1 checkbox); no risk-assessment workflow UI; no evidence/disclosure upload; no link to a staff member's other checks; not on Overview.
> - **Drivers:** Add/Suspend are **thin modals**; **no detail/edit page**; approve/suspend fire with **no confirm**; endorsements are a comma string; no licence-expiry warning; not on Overview.
> - **Per-staff detail:** **dead end** — shows status + evidence fields but offers **zero actions**; no per-requirement drill-in; no vetting/driver panels; back-button only, no breadcrumb.
> - **Cross-cutting:** no toasts, no skeletons, no right-click anywhere, no bulk anywhere, no export anywhere; filter/stat/empty-state patterns differ on every tab.

---

## B. Hero rethink — the golden band (NO clock, fitted to compliance)

Replace the generic `PageHero` with the golden `MyHrHero`-style band (§2.1). One hero spans the hub; the active tab tunes the stats. Pull the risk-cluster + compliance-chip styling from `hs-hero-kit.tsx`.

**Do:**
- Title: a calm, role-aware line (e.g. "Staff compliance") + a context meta row (date, your role, site) like the My HR hero. **No clock card.**
- `HeroStat` / `HeroClusterTile` cluster (clickable, deep-link into the matching tab + filter): **Staff tracked**, **Fully compliant %**, **Expiring ≤30d**, **Expired** (amber/red via `--hr-amber` / `--status-critical`), **Hard-stops active** (people blocked from shifts), **Shifts affected**. Use `delta`/tone where a trend exists.
- A `HeroComplianceBadges`-style chip row for the NZ context buckets: **Police vetting**, **Children's Act safety checks**, **Driver licences**, **Mandatory training** — each green/amber/red by worst status.
- `QuickAction`s on the hero (each opens the matching wizard in §J — no dead actions): **Record compliance**, **Add requirement**, **Add vetting check**, **Add driver**, **Export**.
- Footer "Needs you" strip: expired hard-stops, checks awaiting consent, licences expiring, requirements with nobody assigned. Re-theme via `--primary`; amber only for "needs attention".

---

## C. Tabs — the Compliance hub shell (5 real tabs)

Route all five through `HrTabs` + `TabStrip` (§2.5), keeping the existing permission gates. Per tab: real loading (`skeleton-*`), empty (`EmptyState`/`EmptySearch`) and error (`error-state`) states; URL-synced filters (`?tab=`, `?status=`, `?q=`, date range); **right-click on rows and on the tab strip** (§K). Tabs:

1. **Overview** (`/hr/compliance`) — staff compliance roll-up + risk (§D).
2. **Matrix** (`/hr/compliance/matrix`) — requirements library + role/site assignment (§E).
3. **Renewals** (`/hr/compliance/calendar`) — expiry/renewal timeline with actions (§F).
4. **Vetting** (`/hr/compliance/vetting`) — Police/MOJ/safety-check register (§G).
5. **Drivers** (`/hr/compliance/drivers`) — licence eligibility register (§H).

Per-staff detail (`/hr/compliance/staff/{id}`) stays a full page reachable from Overview/Renewals/Vetting/Drivers and is the **action hub** (§I).

---

## D. Overview tab redesign (+ unify vetting & driver status)

**Keep** the paginated per-staff table, **upgrade** it to the kit and make it actionable:
- Risk-first layout: a `StatTile` row (Fully compliant %, Expiring, Expired, Hard-stops, Shifts affected) above a unified `FilterBar` (search, status, requirement, **+ site/role**, date range).
- Per-row: name (→ staff detail), compliance ring/%, badges (compliant/expiring/expired/not-started via `StatusBadge`), **Vetting** status chip, **Driver** status chip (the unification — surface `StaffBackgroundCheck` worst-status + `HrDriverEligibility` status per person; backend rollup in §L), shifts-affected, and a **row actions** menu.
- Row actions (buttons + right-click, §K): **Open** · **Record compliance** (opens §J.1 scoped to that person) · **Waive a requirement** · **Send reminder** · **Export**.
- **Multi-select + bulk bar:** record/assign requirement to a cohort, send reminders, waive with reason, export selected.
- Empty/filtered via `EmptySearch`/`EmptyList`; pagination stays `laravel-pagination`.

## E. Matrix tab redesign

Turn the inline-edit grid into a real **requirements library + assignment** surface:
- Two regions: a **Requirements** table (code, name, category, `check_type` badge, validity months, reminder days, `hard_stop` flag, active) and the **role × requirement assignment grid** (sticky first column, site-type aware, toggle = assigned/mandatory/none).
- **Replace inline editing** with the **Create / Edit Requirement wizard** (§J.2). Delete = **soft-deactivate** behind an `alert-dialog` confirm (never native `confirm()`), with a toast.
- **Bulk assign**: select requirements → assign to many roles/site-types at once (preview "staff affected" via the live `/preview` idiom). Each matrix toggle fires a toast.
- Sort + search the requirements list; `EmptyList` when none.

## F. Renewals tab redesign (calendar that does things)

Make the read-only list an interactive renewals timeline:
- Keep month grouping but add **per-month counts**, a **date-range / horizon filter** (next 30/60/90 days, overdue), and the existing type filter (compliance/vetting/driver/training) via `FilterBar`.
- Rows become **clickable** → open a **detail sheet** (`@/components/ui/sheet`) showing the person (linked), requirement, dates, history, and actions: **Record completion/renewal**, **Send reminder**, **Waive**, **Snooze**. Employee names link to staff detail.
- Optional calendar grid view toggle (the app already uses `@fullcalendar/react` elsewhere) — list stays default. Right-click rows for the same quick actions (§K).

## G. Vetting tab redesign (convert pages → wizard modals)

Bring vetting to the gold standard and retire the full-page forms:
- **Register** (`vetting/index.tsx`): kit `StatTile` row (Total, Clear, Pending, Expiring, Expired, Flagged), unified `FilterBar`, `StatusBadge` everywhere, multi-select + bulk bar, **Export**.
- **Replace** `vetting/create.tsx` and `vetting/edit.tsx` (full pages) with an **Add / Edit Vetting Check wizard** (§J.3) cloned from Add-Client. Steps: Person & check type → Provider & reference & dates → Disclosures & risk assessment → Consent & evidence upload → Review. (`StaffBackgroundCheck` already has the rich fields.)
- **Upgrade** the thin consent dialog into a real **Record Consent** step/modal: consent statement text, captured timestamp, method, optional notes — written to a real consent log (§L), not just `notes`.
- Detail (`vetting/show.tsx`) stays a page but gains: linked staff (→ staff detail), the person's **other checks**, evidence/disclosure attachments, and actions (Edit, Mark cleared, Request renewal, Record consent) all via the wizard/`alert-dialog`.

## H. Drivers tab redesign (convert thin modals → wizards + NEW detail page)

- **Register** (`drivers/index.tsx`): kit `StatTile` row (Total, Eligible, Pending, Suspended, Expiring), unified `FilterBar`, `StatusBadge`, multi-select + bulk bar, **Export**. Endorsements render as chips, not a CSV string.
- **Replace** the thin Add Driver + Suspend modals with the **Add / Edit Driver wizard** (§J.4): Person → Licence (number, class, endorsements via `ChipMulti`, expiry) → History (incident-free since, suspensions) → Review. Approve / Suspend become confirmed actions (`alert-dialog` + reason) with toasts.
- **Build the missing Drivers detail page** `/hr/compliance/drivers/{id}` (route + `drivers/show.tsx`, §L): full record, licence history, suspension history, linked incidents, and Edit/Approve/Suspend actions. Today there is no way to view or edit a driver after creation.

## I. Per-staff compliance detail — the action hub (close the loop)

This is the centrepiece. `staff-detail.tsx` must stop being read-only and become where compliance actually gets *done*:
- Header: compact golden hero variant with the person, overall ring, and quick actions (**Record compliance**, **Waive**, **Send reminder**, **Export**). Breadcrumb back to Overview.
- Keep the grouped status sections (Expired / Expiring / Not started / Compliant) but make **each requirement row actionable**: **Record / update** (opens §J.1 prefilled to that requirement — set status, valid-from, expiry, **upload evidence file**, notes), **Waive / exempt** (reason + optional expiry + approver, §J.5), **View evidence**, **History**.
- Add **Vetting** and **Driver** panels here (the unification) so one screen shows the person's full eligibility picture, each linking to its detail.
- Show **hard-stop banner** when `getHardStopFailures()` is non-empty ("Blocked from shifts — N upcoming affected") with a one-click path to fix or waive.

## J. Modals = exact Add-Client wizard pattern (full, not thin)

Every flow clones `add-client-dialog.tsx` (§2.2): full-height bespoke shell, left stepper rail with completeness meter, top progress bar, per-step `validateStep`, `stepForError` jump, `SuccessPane`, **Save & add another**, `forceFormData` for uploads, `toast` on success. Build these:

1. **Record / Update Compliance wizard** — the loop-closer. Person(s) (`PeoplePicker`, cohort-capable) → Requirement → Outcome (status, valid-from, expiry auto-suggested from `validity_months`) → **Evidence** (file upload + type + notes) → Review. On save, create/refresh `HrStaffComplianceStatus`, re-evaluate hard-stops, toast. (No write endpoint exists today — §L.)
2. **Create / Edit Requirement wizard** (replaces inline Matrix editing; edit mode like Add-Client's `clientId` toggle). Steps: Basics (code, name, category, `check_type` via `TilePicker`) → Rules (`validity_months`, `renewal_reminder_days`, `hard_stop`, active) → Assignment (roles/site-types via the matrix) → Review.
3. **Add / Edit Vetting Check wizard** (§G) — replaces the full-page forms.
4. **Add / Edit Driver wizard** (§H) — replaces the thin modals.
5. **Waive / Exempt wizard** — person + requirement → reason (required) → scope (permanent vs until-date) → approver/acknowledgement → Review. Writes `exemption_reason`/`exempted_by` + a real audit entry (§L). Used from Overview, Renewals and staff detail.
6. **Assign Requirement wizard** (bulk) — requirement(s) → audience (individuals / role / site / cohort) → preview count + shift conflicts via live `/preview` → write. Backs the Matrix + Overview bulk bars.

> Wire each from its tab/page and from the hero `QuickAction`s exactly like Add-Client is wired from `index.tsx`. Destructive actions (deactivate requirement, suspend driver, waive, mark adverse) confirm via `alert-dialog`, never native `confirm()`. **No thin modals, no inline forms, no full-page create routes.**

## K. Right-click everywhere (rows and tabs)

Chane explicitly wants right-click options "under tabs etc." Build a `ComplianceContextMenu` (mould of `ShiftContextMenu`, §2.3) and wire `onContextMenu` on:
- **Overview staff rows:** Open · Record compliance · Waive requirement · Send reminder · View vetting · View driver · Export.
- **Matrix requirement rows:** Edit · Assign to roles · Deactivate · Duplicate.
- **Renewals rows:** Open person · Record renewal · Remind · Waive · Snooze.
- **Vetting rows:** Open · Edit · Mark cleared · Request renewal · Record consent.
- **Driver rows:** Open · Edit · Approve · Suspend (reason) · View licence.
- **The tab strip itself:** right-click a tab → **Set as default view**, **Open**, **Pin**. Persist default-tab/pins to `localStorage` (allowed) so it survives reloads; render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§L). **No dead items.** Destructive items confirm via `alert-dialog`.

## L. Backend handoff for Claude Code (append to this as you design)

> Claude design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code — and copy the finished list into a new **`HR_COMPLIANCE_BACKEND_HANDOVER.md`** at repo root. Gate manager actions on the right permission (`hr.compliance.manage`, `hr.vetting.manage`, `hr.driver.manage`), respect `ResolvesHrTenant` tenant scoping, and **confirm any schema before building**. Seed list from the audit:

**What already EXISTS — wire to it, don't rebuild:** `ComplianceController@index/staffDetail`; `ComplianceMatrixController@index/storeRequirement/updateRequirement/destroyRequirement/updateMatrix`; `ComplianceCalendarController@index`; `VettingController@index/create/show/store/edit/update/destroy/clear/renew/captureConsent`; `DriverEligibilityController@index/store/update/approve/suspend`; `ComplianceMatrixService` (`getHardStopFailures`, `getSoftWarnings`, `canAssignToShift`, `getComplianceSummary`, `evaluateStaff`); `SendExpiryRemindersJob` + `ComplianceExpiryNotification`.

**Missing endpoints / schema to build (spec → confirm → implement):**
1. **Record/update staff compliance status (the critical gap).** New `ComplianceController@storeStatus` / `updateStatus` (`POST /hr/compliance/staff/{staff}/status`, `PUT /hr/compliance/status/{status}`): set `status`, `valid_from`, `expires_at`, notes. **Add a `notes` column** to `hr_staff_compliance_status` (migration). Define and implement **evidence storage**: the model has `evidence_type`/`evidence_id` (no loader) AND there is an unused `compliance_evidence` table — pick one, add an **evidence upload** endpoint (multipart), and make `staff-detail.tsx`'s `evidence_url`/`evidence_notes` real. Audit-trail every manual change.
2. **Waive / exempt.** `POST /hr/compliance/status/{status}/exempt`: writes `exemption_reason` + `exempted_by` (+ optional `exempted_until`), with an approval/acknowledgement record and audit entry. None exists today.
3. **Manual check completion.** `check_type='manual'` currently always "passes" in `LiveComplianceValidator` and has no completion path — define how a manual requirement is satisfied (ties to #1) so hard-stop manual requirements can actually block.
4. **Bulk endpoints:** assign requirement → cohort/role/site; bulk record; bulk remind; bulk waive. (Today `updateMatrix` is role-level only; there's no per-staff bulk.)
5. **Overview rollup of vetting + driver status** (so §D can show the chips without N+1): extend the `index` payload or add a rollup query joining latest `StaffBackgroundCheck` worst-status and `HrDriverEligibility` status per user.
6. **Drivers detail/edit:** `GET /hr/compliance/drivers/{driver}` + `PUT` (and a `show.tsx`). None exists.
7. **Vetting consent log + evidence:** real consent records (not `notes`) + disclosure/evidence file upload; re-evaluate compliance when a check changes status.
8. **Vetting ↔ compliance + Drivers ↔ compliance integration:** link `StaffBackgroundCheck` and `HrDriverEligibility` to `HrStaffComplianceStatus`; generalise the validator beyond hard-coded `police_check`; add a **driver-licence hard-stop** validator; re-dispatch `EvaluateComplianceMatrixJob` on vetting/driver/training change.
9. **Hard-stop → rostering:** call `ComplianceMatrixService::canAssignToShift()` on shift assignment and surface a block + override path (Overview already advertises `future_shifts_affected`).
10. **Renewals actions:** endpoints behind "remind", "mark renewed", "snooze".
11. **Export:** CSV/Excel for staff compliance, vetting register, drivers register, renewals — none exist.

> For each item: short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

## M. Premium polish & delight

- Micro-interactions from the kit: `animate-in fade-in-0 zoom-in-95` on modals/menus, hover lifts (`--shadow-float`), skeletons on load, optimistic toasts. `motion-reduce` guards throughout.
- Optional tasteful confetti + celebratory `SuccessPane` when a person hits 100% compliant or a hard-stop is cleared (mirror the Leave self-service flourish) — never noisy.
- Keyboard: `/` focuses search, `r` opens Record compliance, arrow/Home/End on tabs, Esc closes menus/modals; surface `kbd` hints in menus.
- Live preview where it helps (Waive shift impact, Assign cohort count/conflicts) via the debounced `/preview` idiom.
- Everything re-themes via `--primary`; amber only for attention.

---

## Definition of done

- The Compliance hub is **one golden hero (no clock)** + **five real tabs** (Overview · Matrix · Renewals · Vetting · Drivers) on `HrTabs`/`TabStrip`, matching the gold-standard pages.
- Overview is risk-first, actionable, multi-select + bulk, and **shows each person's vetting + driver status**; the **per-staff page is the action hub** (record · evidence · waive · remind) — no longer a dead end.
- Matrix uses the **Create/Edit Requirement wizard** (no inline editing); Renewals is interactive (detail sheet + actions); Vetting and Drivers are converted to **wizard modals**, with the **new Drivers detail page**.
- Every record/edit/assign/waive/vetting/driver flow is a **full Add-Client-style wizard** (stepper rail, completeness meter, validation, review, Save & add another, uploads) — **no thin modals, no inline forms, no full-page create routes**.
- **Right-click** works on every row **and** the tab strip (Set as default / Pin); every action toasts and hits a real route. No dead items.
- NZ vetting/licence vocabulary + `en-NZ` retained; `ResolvesHrTenant` scoping and `hr.*` gates respected; **no regressions** to rostering hard-stops, finance, or `/hr/people`.
- Clean `build`, `types`, `lint`; screenshots of each tab **and each modal** match the reference pages. **§L backend handoff list is filled in** and mirrored to `HR_COMPLIANCE_BACKEND_HANDOVER.md` for Chane → Claude Code.
- **Signals to watch:** % staff fully compliant, expired hard-stops outstanding, time-to-record a completion, evidence files attached, waivers logged with reason, shifts blocked by compliance.

**Build order:** §A audit → §B hero → §C tab shell → §D Overview → §I staff-detail action hub → §J modals (start with Record-compliance) → §E Matrix → §F Renewals → §G Vetting → §H Drivers (+ detail page) → §K right-click → §M delight. Verify each pass against the reference pages, and keep appending discovered backend work to **§L**.
