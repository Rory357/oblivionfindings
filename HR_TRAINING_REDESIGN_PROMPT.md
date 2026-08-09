# HR Training Hub Redesign — PROMPT (anchored on `/hr/training/catalog`)

> One prompt for the whole job. Paste to the build agent (**Claude design** — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/training`, `/hr/training/catalog`, `/hr/training/courses/{id}`, the new `/hr/training/assignments`, and `/hr/my/training`, then diff against the gold-standard pages/components before continuing. Start with the audit in **§A**, then build **§B–§L**.
>
> **Handover rule (Chane's ask):** anything you discover that needs server/data work goes into **§K "Backend handoff for Claude Code"** — append to it as you go, mirror the final list into a new **`TRAINING_CATALOG_BACKEND_HANDOVER.md`** at repo root (the clean list Claude Code implements from), and keep a running checklist in **`docs/TRAINING_HUB_GAP_ANALYSIS.md`**. Every backend item = short spec + migration sketch + **confirm with Chane before building**. No silent schema.

**Page (canonical):** `/hr/training/catalog` — sits inside the **Training hub**: `/hr/training` (Dashboard) · `/hr/training/catalog` (Catalog) · **new** `/hr/training/assignments` (Assignments) · `/hr/training/courses/{id}` (course detail).

**Frontend files:**
- `resources/js/pages/hr/training/catalog.tsx` (catalog)
- `resources/js/pages/hr/training/course.tsx` (course detail)
- `resources/js/pages/hr/training/index.tsx` (Dashboard)
- `resources/js/components/hr/training-tabs.tsx` (the hub tab strip — already wraps `HrTabs`, just thin)
- `resources/js/pages/hr/my/training.tsx` (employee self-view, under `MyHrShell`)

**Backend:**
- `app/Http/Controllers/Hr/TrainingController.php` — `catalog`, `showCourse`, `storeCourse`, `enroll`, `completeEnrollment`, `downloadCertificate` (that's the whole surface).
- `app/Http/Controllers/Hr/TrainingDashboardController.php` — `index` only.
- `app/Domain/Hr/Services/TrainingService.php` — `createCourse`, `enroll`, `completeEnrollment` (+ private `syncComplianceTrainingRecord`), `getTrainingSummary`.
- `app/Http/Requests/Hr/StoreTrainingCourseRequest.php` — create-course validation.
- `app/Observers/HrCourseEnrollmentObserver.php` — posts the training-cost GL event on completion.
- Routes: **`routes/hr.php` lines 1114–1132** (catalog/course/store/enroll/complete/certificate) + **line 271** (dashboard); legacy redirects + competency + induction in `routes/training.php`. `app/Http/Controllers/Training/TrainingRecordController.php` is a **dead, unrouted stub** — do not build on it.

**Models:**
- Catalog side (`app/Domain/Hr/Models/`): `HrCourse` (thin), `HrCourseEnrollment` (has `journal_id`), `HrCourseSession` (read-only today).
- Compliance side (`app/Models/`): `TrainingCourse` (the **rich** model: `learning_outcomes`, `prerequisites`, `requires_renewal`, `validity_period_months`, `renewal_reminder_months`, `requires_assessment`, `pass_mark_percentage`, `cost_per_person`, `provider_reference`, `mandatory_for_roles`), `StaffTrainingRecord` (`expires_at`, `cpd_points`, `assessment_score`, `certificate_number`, `renewal_reminder_sent_at`, `renewed_by_record_id`, `exemption_reason`).
- Bridge: `app/Models/HsTrainingRequirement.php` (`validity_months`, `grace_period_days`, `enforcement_mode warn|block`) via an `HrComplianceRequirement` (`check_type = 'training_course'`, `reference_id → TrainingCourse`).

**Gold-standard references to clone (exact paths):**
- **Create/edit wizard modal:** `resources/js/components/clients/add-client-dialog.tsx`.
- **Premium "New Request" modal idioms:** `resources/js/components/hr/leave-request-dialog.tsx`.
- **Golden hero band:** `resources/js/components/hr/my-hr-hero.tsx` (+ `my-hr-clock-card.tsx` is the clock child — **don't render it**).
- **Tabs:** `resources/js/components/hr/hr-tabs.tsx` + `resources/js/components/rostering/tab-strip.tsx`.
- **Right-click:** `resources/js/components/rostering/shift-context-menu.tsx` (mould the hook in `resources/js/pages/operations/handovers/components/handover-context-menu.tsx`).
- **Wizard kit:** `resources/js/components/wizard/primitives.tsx` + `shell.tsx`, re-exported via `resources/js/components/hr/wizard.ts`.

---

## 0. Mission

Make the **Training hub** a **premium, end-to-end learning & compliance surface** that feels identical in quality to our gold-standard pages — **`/meds/today`**, **`/my-day`**, **`/health-safety`**, **`/hr/people`**, **`/hr/leave`** — and reuses their exact components and tokens.

Today the hub is functional but dated and **thin**: a generic flat `PageHero`, a 2-tab strip with no Assignments and no right-click, a **cards-only catalog with title-only search**, a **single thin create-only "New Course" dialog**, and a course page with two thin enrol/complete dialogs and a **read-only** sessions list. Worse, the hub silently sits on **two unrelated course databases** that only bridge if a field the create form never sets is populated — so in practice the **Dashboard and the Catalog show different worlds**, and most catalog completions never produce an expiry/renewal record. Course **fees** are displayed but there is **no way to act on them**.

**Result:** one **golden hero** fitted to training (no clock), three real tabs with **right-click on rows and on the tab strip**, a power-user catalog, a real Assignments surface, **every create/edit/session/assign/record/claim flow rebuilt as a full Add-Client-style wizard** (no thin modals), **one course source of truth**, and a clean backend handoff for Claude Code.

---

## 1. Non-negotiables

1. **Three real tabs.** Dashboard · Catalog · **Assignments** (new). Keep them on the shared `HrTabs`/`TabStrip` kit (the strip already uses `HrTabs` — extend it, don't fork it).
2. **Reuse the kit — never hand-roll a primitive we already have.** No bespoke widgets, **no raw hex** in classNames (use design tokens from `resources/css/app.css`). §2 is the source of truth.
3. **Information-gathering = full modals.** Every create / edit / add-session / assign / record-completion / claim-fee flow is a **full wizard dialog** cloned from `add-client-dialog.tsx` — **not** an inline form, **not** a thin one-step dialog, **not** a full-page route. Each modal carries the full field set, per-step validation, and a Review step. Reading detail can navigate to the course page or open a sheet.
4. **Single source of truth.** Unify the catalog (`HrCourse`) and compliance (`TrainingCourse`/`StaffTrainingRecord`) course models onto **one** entity (§K) so catalog, expiry, renewal, CPD and assignments all flow from one place. Until merged, **always set `compliance_requirement_id` on create** so completions reliably produce a `StaffTrainingRecord`. Don't invent a third store.
5. **Web-only desktop app. No phone frames.** Design for mouse + keyboard: hover states, **right-click menus**, keyboard shortcuts.
6. **Locale stays NZ.** NZD / `en-NZ` / `formatNzd`. Do **not** switch to GBP/US.
7. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface and diff vs the reference pages. **No dead buttons** — every action hits a real route or is appended to §K.

---

## 2. The shared kit you MUST reuse (exact imports, verified)

**2.1 Hero (golden band, NO clock)**
- Clone `resources/js/components/hr/my-hr-hero.tsx`: its `HERO_STYLE` (linear-gradient over `--primary` + `boxShadow`; re-themes per tenant), the injected amber accent `--hr-amber`/`--hr-amber-soft`, and the exported `MyHrHero`, `HeroStat` (label + big tabular value, clickable / `href`), `QuickAction` (icon + label). **Omit the clock** — on My HR the clock is a separate child (`my-hr-clock-card.tsx`); reuse simply means **not rendering it**.
- If you extract a shared spine, add `resources/js/components/hr/hero-kit.tsx` so My HR, People and Training share one hero (the standardisation win) — confirm the shape before refactoring the others.
- Richer KPI cluster reference: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`. The generic `PageHero` (`@/components/page`) is what we're **replacing** here — don't keep it on these pages.

**2.2 Modals / wizards**
- Clone `resources/js/components/clients/add-client-dialog.tsx`. Verified markers to match: `DialogContent` with `[&>button]:hidden` (≈L775); `flex h-[min(92vh,860px)]` shell (≈L937); **left stepper rail** `w-[248px] … bg-sidebar` (≈L939) with per-step icon + blurb + check-on-complete; a **completeness meter** at the rail foot; header "Step X of N"; a **top progress bar**; scroll-contained body; footer with Back / Cancel / **Save & add another** (≈L1094) / Create; a `SuccessPane` (≈L2708).
- Engine: Inertia `useForm`; a `STEPS` array (`{key,label,icon,blurb}`); client-side `validateStep(key,data)` (≈L710); `stepForError(field)` to jump to the step that owns a server error (≈L595); `SuccessPane` after create; `resetAll()` for "Save & add another"; `forceFormData: true` whenever a file (certificate/receipt) is involved.
- Built from `@/components/wizard/primitives` (`Field`, `FieldErr`, `StepHead`, `SubHead`, `InfoCard`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `Ring`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`) and `@/components/wizard/shell` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`, `WizardStep`) + the `useWizard(stepCount)` state machine. **HR re-exports the whole kit via `@/components/hr/wizard`** — import from there to stay visually identical.
- Premium idioms to copy from `leave-request-dialog.tsx` (all present in that file): a **live preview** side-panel pinned via `railExtra` fed by a debounced `/preview` fetch; per-type accent tinting; review-step warning banners; `forceFormData: true`; optional **confetti** (`fireConfetti` from `@/lib/confetti`) + `toast` (sonner) on self-service submit. People-picker: `resources/js/components/hr/people-picker.tsx` (`PeoplePicker`, `PersonOption`).
- Base shadcn: `@/components/ui/` — `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`. Destructive confirms use `alert-dialog`, **never** native `confirm()`/`alert()`.

**2.3 Right-click menus + hover actions**
- `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState`) — portal-rendered, viewport-flipping, Esc/outside-click close, icon + label + `kbd` + tone. Cleanest reuse is the hook shape in `resources/js/pages/operations/handovers/components/handover-context-menu.tsx` (`useHandoverContextMenu` → `{ openCtx, menu }`); wire via `onContextMenu={(e) => openCtx(e, row)}`.

**2.4 Cards / tables / states / badges**
- `@/components/ui/status-badge` (`StatusBadge`) **everywhere** — never hand-map status colours (today every page hand-maps `STATUS_COLORS`/`DELIVERY_COLORS`).
- `@/components/ui/card`, `table`, `@/components/ui/empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `skeleton-table`, `@/components/ui/laravel-pagination`, `@/components/ui/checkbox` (**replace** the plain `<input type="checkbox">` on the catalog, catalog.tsx:376).

**2.5 Tabs**
- `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab, { param, syncUrl })`) on `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, arrow/Home/End keys, **`onItemContextMenu`**, **`decorations`** per-tab node, `trailing`). `training-tabs.tsx` already renders `HrTabs` — extend it (add Assignments + right-click + default/pin), don't rebuild.

**2.6 Tokens & flourishes**
- Tokens only, from `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`.
- Toasts: **sonner** (`<Toaster>` already mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action.
- Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## A. Audit & benchmark first (do this before building)

Study `/meds/today`, `/my-day`, `/health-safety`, `/hr/people`, `/hr/leave` and **interact** with them — they are the parity bar. Then open the four training surfaces and fill in the checklist; paste the results back as your first pass and seed `docs/TRAINING_HUB_GAP_ANALYSIS.md`.

**Checklist**
- [ ] Screenshot each current surface (Dashboard, Catalog, Course detail, `/hr/my/training`). Note every hand-rolled element that has a kit equivalent.
- [ ] Confirm the **two-model split**: which fields live on `HrCourse` vs `TrainingCourse`/`StaffTrainingRecord`, and exactly when `TrainingService::completeEnrollment()` → `syncComplianceTrainingRecord()` does/doesn't create a `StaffTrainingRecord`. Document the dead bridge (below).
- [ ] List every training route that exists vs every action the new UI needs; the delta seeds §K.
- [ ] Trace the **fee path**: `HrCourse.cost` → `HrCourseEnrollmentObserver::updated()` posts a `training_cost` event (DR 6510 / CR 2000 AP) on completion when `cost > 0` and `journal_id` is null. Note the **double-count risk** if a fee is later reimbursed to staff.

> **Known gaps this audit already surfaced (confirm, then fix):**
>
> **The central disconnect — two course worlds that don't meet.** The Catalog reads/writes `HrCourse` + `HrCourseEnrollment` + `HrCourseSession`. The Dashboard reads `StaffTrainingRecord` + `TrainingCourse`. They only connect inside `TrainingService::syncComplianceTrainingRecord()` (TrainingService.php:77), which fires **only** when the `HrCourse` has a `compliance_requirement_id` pointing to an `HrComplianceRequirement` with `check_type='training_course'` and a `reference_id` → `TrainingCourse`. **The create-course form never sets `compliance_requirement_id`** (the request allows it — StoreTrainingCourseRequest.php:26 — but catalog.tsx omits the field). Net effect: completing a catalog course produces **no** `StaffTrainingRecord`, so the Dashboard's expiry/renewal/overdue numbers are blind to all catalog activity. This is the #1 thing to fix (surface the field now; unify the models in §K).
>
> **Catalog (`catalog.tsx`):** generic `PageHero` (L201), not the golden band; create-**only** thin `Dialog` (L540–687), single step, no edit/duplicate/archive/activate, no compliance/renewal/CPD/outcomes/prereqs fields; cards-only grid (L422) with on-click navigation, no table/density toggle, no sort; **search is `title LIKE` only** (TrainingController.php:39 — not code/provider/description); plain `<input type="checkbox">` (L376); no multi-select/bulk bar; no CSV/print export; fee shown but not actionable.
> **Course detail (`course.tsx`):** generic `PageHero` (L234); **Sessions panel is read-only** (L306–371) — no create/edit/cancel UI anywhere; thin Enrol dialog (L482) uses a single-user `Select` not `PeoplePicker`; thin Complete dialog (L579) sends score+notes only and **drops the certificate** (backend accepts `certificate_path` as a *string*, TrainingController.php:162 — no file upload); no edit/delete/assign/claim-fee; no right-click. **Method bug:** Complete posts via `router.post(...)` (L206) but the route is `PUT hr.training.enrollments.complete` (hr.php:1131) — verify/fix the verb.
> **Dashboard (`index.tsx`):** generic `PageHero` (L138); hand-rolled tables (L449); KPI/list numbers don't deep-link into filtered views; custom empty blocks instead of the `EmptyState`/`error-state`/`skeleton-*` kit; site filter only; no right-click.
> **Tabs (`training-tabs.tsx`):** on `HrTabs` already, but **only 2 tabs** (docstring admits "Assignments isn't a built page"), no right-click, no per-user default/pin.
> **Employee self-view (`my/training.tsx`):** **raw hex colours** (`#10b981/#f59e0b/#ef4444/#94a3b8`, L56+) — token violation; reads compliance requirements, links to catalog. Align to kit + tokens (secondary surface).
> **Backend:** no `updateCourse`, no `destroy`/archive (no `SoftDeletes` on `HrCourse`), no session CRUD, no assign-by-role/site/cohort, no bulk endpoints, no export, search limited to title, complete can't store an uploaded certificate file; `enroll` takes one `user_id`. `TrainingRecordController` is dead.
> **Adjacent surfaces (`routes/training.php`):** Competency frameworks (`competency.frameworks.*`) and Staff induction (`staff.induction.*`) are real training-world pages not linked from the hub. Decide whether they become hub tabs/quick-actions or just cross-links (§C, confirm with Chane).

---

## B. Hero rethink — the golden band (NO clock, fitted to training)

Replace the generic `PageHero` on all three hub pages with the golden `MyHrHero`-style band (§2.1). One hero spans the hub; the active tab tunes the stats.

**Do:**
- Title: a warm, role-aware line (e.g. "Training & development") + an org/site/date meta row like the My HR hero. **No clock card.**
- `HeroStat` cluster (clickable, deep-linking into the matching tab/filter): **Courses** (active), **Mandatory**, **Enrolments**, **Completion %**, **Expiring ≤90d**, **Overdue** (amber via `--hr-amber`). Use `delta`/tone where a trend exists.
- Hero `QuickAction`s (each opens the matching wizard in §H — no dead actions): **New course**, **Assign training**, **Record completion**, **Export**.
- Optional compliance chips (HSWA / role-requirement context) and a "Needs you" footer strip (overdue renewals, unconfirmed assignments).
- Re-theme via `--primary`; amber accent only for "needs attention" numbers. Course detail keeps a course-scoped hero (title, code, mandatory badge, delivery, duration, provider, fee) with a back link to Catalog.

---

## C. Tabs — the hub shell (Dashboard · Catalog · Assignments)

Extend `training-tabs.tsx` (it already uses `HrTabs`): three tabs gated on `auth.can.hr.training.view`:
1. **Dashboard** (`/hr/training`) — compliance & ops roll-up (§F).
2. **Catalog** (`/hr/training/catalog`) — browse / manage courses (§D).
3. **Assignments** (`/hr/training/assignments` — **new**) — who's assigned what, due/overdue, bulk (§G).

Wire **`onItemContextMenu`** + **`decorations`** on the strip for the tab right-click (§J). Per tab: real loading (`skeleton-*`), empty (`EmptyState`/`EmptySearch`) and error (`error-state`) states; URL-synced filters (`?tab=`, `?search=`, …). Course detail stays a full page reachable from any tab. **Cross-link** Competency frameworks + Staff induction from the hero/overflow (confirm with Chane whether they graduate to full tabs).

---

## D. Catalog tab redesign

- Keep a polished **card grid** but add a **table/density toggle** (`@/components/ui/table`) for power users, a **sort control** (title, completion, enrolments, cost, expiring), and widen **search to title + code + provider + description** (backend, §K).
- Each course card/row shows: title, code, category, delivery method, duration, mandatory flag (`StatusBadge`), enrolments, upcoming sessions, **fee (`formatNzd`)**, active/inactive, and (post-unification) **renewal cadence / CPD / # staff expiring**.
- Row/card actions (buttons + right-click, §J): **Open**, **Edit**, **Add session**, **Assign**, **Record completion**, **Duplicate**, **Archive/Activate**, **Claim course fee**, **Export**.
- Replace the plain checkbox with `@/components/ui/checkbox`; add **multi-select + bulk bar** (assign to cohort, archive, export selected).
- Empty/filtered states via `EmptySearch`/`EmptyList`. Pagination stays `laravel-pagination`.

## E. Course detail redesign

Make `course.tsx` a premium profile on the kit:
- **Overview**: description, learning outcomes, prerequisites, provider, fee, renewal/CPD, which `HsTrainingRequirement` it satisfies.
- **Sessions** panel: list + **create/edit/cancel** via the Session wizard (§H) — today it's read-only.
- **Enrolments** panel: status, score, certificate, **right-click row actions**.
- **Compliance** panel: who's expiring / due, linked requirement.
- Wire **Edit course**, **Add session**, **Assign**, **Record completion**, **Claim course fee** (§I), **Download/Upload certificate**. The complete flow must support **uploading** an externally-issued certificate (impossible today). Fix the POST-vs-PUT verb on complete.

## F. Dashboard tab redesign

Rebuild `index.tsx` as the compliance/ops roll-up on the **unified** model: overdue & due-soon renewals, completion by site/role, the training **matrix**, upcoming sessions, and spend (course fees) — all on `StatusBadge`, KPI tiles and the kit. **Every number deep-links** into a filtered Catalog/Assignments view. Right-click rows for quick actions (assign, remind, record). No hand-rolled tables; real `skeleton`/`empty`/`error` states.

## G. Assignments tab (new)

The missing surface: a managed view of **assignment records** (who must complete what, by when). Columns: person, course, **source** (manual / role rule / H&S requirement), assigned date, due date, status (`StatusBadge`: assigned / in-progress / completed / overdue / waived), score. Filters by site/role/course/status. **Bulk actions:** assign cohort, send reminder, waive with reason, record completion. This is what the **Assign training** wizard (§H) writes to. Needs backend (§K) — assignments don't exist yet.

---

## H. Modals = exact Add-Client wizard pattern (full, not thin)

Every flow clones `add-client-dialog.tsx` (§2.2): full-height bespoke shell, left stepper rail with completeness meter, top progress bar, per-step `validateStep`, `stepForError` jump, `SuccessPane`, **Save & add another**, `forceFormData` for uploads, `toast` on success. Build:

1. **Create / Edit Course wizard** (replaces the thin "New Course" dialog; same wizard in edit mode like Add-Client's `clientId` toggle). Steps:
   1. **Basics** — title*, code*, category (typeahead from existing categories), delivery method* (`Segmented`/`TilePicker`: online / in_person / blended / self_paced), provider, active.
   2. **Content** — description, **learning outcomes**, **prerequisites** (`ChipMulti`), duration_hours*, max_participants. (Outcomes/prereqs need columns — §K; today only on `TrainingCourse`.)
   3. **Compliance & renewal** — is_mandatory, **requires_renewal + validity_period_months + renewal_reminder_months**, **requires_assessment + pass_mark_percentage**, **CPD points**, and **link to an `HsTrainingRequirement`** (set `compliance_requirement_id` — surface the field that's allowed but never sent).
   4. **Fee & finance** — cost (NZD); whether the org pays the provider (AP) and/or staff can **claim it back** (§I); GL hint. (Reconcile double-count in §K.)
   5. **Review & create** — `ReviewCard`/`ReviewRow`; Save & add another.
2. **Session wizard** (`HrCourseSession` create/edit/cancel) — date/time, location/online link, trainer (`PeoplePicker`), capacity, notes; cancel flow with reason + notify enrolled. (No session write path exists — §K.)
3. **Assign Training wizard** — pick course(s); choose audience (individuals via `PeoplePicker`, or **by role / site / cohort**); set due date; optional session; **live `/preview`** of count + conflicts (Leave idiom); writes assignment records (§G). Replaces today's one-user-at-a-time enrol; bulk-capable.
4. **Record Completion wizard** — person(s), session, score (vs pass mark), **certificate upload** (file → `forceFormData`), completion date, notes; on save create/refresh the `StaffTrainingRecord` (expiry from validity), fire CPD, toast. (Today's complete dialog is thin and drops the certificate.)
5. **Claim Course Fee modal** — see §I.

> Wire each from its page/tab and from the hero `QuickAction`s exactly like Add-Client is wired from `index.tsx`. Destructive actions (archive course, cancel session, waive assignment) confirm via `alert-dialog`.

---

## I. Fees — make them actionable (Claim Course Fee modal)

Fees are shown across the hub but can't be acted on, and the GL already auto-posts a provider cost on completion (`HrCourseEnrollmentObserver`).

**Do (frontend):** build a **Claim Course Fee** modal on the Add-Client wizard shell, reusing the existing expense backend (`HrExpenseClaim` + `HrExpenseItem` via `ExpenseService`/`ExpenseController`). Full field set: claim title, notes, repeatable **items** (description, **category**, amount, expense_date, tax_amount, **receipt upload**), live total, **multipart** (`forceFormData`), Review step. Open it **pre-filled** from a course/enrolment: `description = course.title`, `category = 'training'`, `amount = course.cost`, hidden `source_type/source_id → HrCourseEnrollment/HrCourse`. Trigger from course detail and as a catalog row/right-click action. If a shared `claim-expense-modal.tsx` already exists or is being built for My HR/My Day, reuse it rather than forking.

**Backend deltas → §K:** add a **`training`** expense category; add nullable **`source_type`/`source_id`** to `hr_expense_items`; define the **double-count rule** (a reimbursed fee must not also post the `HrCourseEnrollmentObserver` `training_cost` event — use the existing `HrCourseEnrollment.journal_id` to reconcile).

---

## J. Right-click everywhere (rows and tabs)

Chane explicitly wants right-click "under tabs etc." Build a `TrainingContextMenu` (mould of `ShiftContextMenu`, §2.3) and wire `onContextMenu` on:
- **Course rows/cards:** Open · Edit · Add session · Assign · Record completion · Duplicate · Archive/Activate · Claim course fee · Export. Gate edit/archive by `can.manage`; show `kbd` hints.
- **Enrolment / assignment rows:** Open person · Record completion · Send reminder · Waive (reason) · Download/Upload certificate.
- **Session rows:** Edit · Cancel (reason + notify) · Assign to session.
- **The tab strip itself** (via `onItemContextMenu` + `decorations`): **Set as default view**, **Open**, **Pin**. Persist default-tab/pins to `localStorage` (allowed) so they survive reloads; render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§K). **No dead items.** Destructive items confirm via `alert-dialog`.

---

## K. Backend handoff for Claude Code (append as you design)

> As you build the UI and hit anything needing server work, **add it here** with a short spec + migration sketch, then mirror the finished list into **`TRAINING_CATALOG_BACKEND_HANDOVER.md`** (repo root) for Claude Code to implement, and tick it in `docs/TRAINING_HUB_GAP_ANALYSIS.md`. Gate manager actions on the right permission (`hr.training.manage` / `training.manageCourses`; `training.enrol`; `training.record`; `hr.expenses.*` for claims), respect tenant scoping (`forTenant`), and **confirm schema with Chane before building**. Seed list:

**Source-of-truth unification (decision → confirm before migrating):**
1. Merge the two course stacks onto **one** entity. Either (a) extend `HrCourse` with the rich columns it lacks (`learning_outcomes`, `prerequisites`, `requires_renewal`, `validity_period_months`, `renewal_reminder_months`, `requires_assessment`, `pass_mark_percentage`, `cpd_points`, `provider_reference`) and hang `StaffTrainingRecord` off `HrCourse`; or (b) make `TrainingCourse` canonical and point the catalog at it. Provide migration + data-backfill sketch + rollback note. **Until merged, always set `compliance_requirement_id` on create** so completions reliably produce a `StaffTrainingRecord`.

**Missing endpoints (spec → confirm → implement):**
2. `updateCourse` (PUT) + `destroy`/archive (add `SoftDeletes` or an `is_active` toggle endpoint) — none exist.
3. **Session CRUD** for `HrCourseSession` (create/update/cancel + notify) — no write path.
4. **Assignments**: new model/table (person, course, source, assigned_at, due_at, status, score) + endpoints for assign (individual / role / site / cohort), reminder, waive, and the Assignments index. Today `enroll` takes one `user_id`.
5. **Bulk** enrol/assign/complete endpoints to back the multi-select bars.
6. Widen catalog **search** to code/provider/description; add **sort** params.
7. **Export** endpoint(s) (CSV/Excel) for catalog, enrolments, assignments — none exist.
8. Complete-enrolment must accept and store an **uploaded `certificate_path`** (multipart) — today it's a string field only. Fix the **POST→PUT** verb mismatch on `hr.training.enrollments.complete`.

**Fees (back §I):**
9. Add **`training`** to the expense categories; add nullable **`source_type`/`source_id`** to `hr_expense_items`; wire the course-fee prefill to set them.
10. Define the **GL double-count rule**: when a course fee is reimbursed to staff, suppress/replace the `HrCourseEnrollmentObserver` `training_cost` posting (DR 6510 / CR 2000). Use the now-wired `HrCourseEnrollment.journal_id`.

> For each: short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

---

## L. Premium polish & delight

- Micro-interactions from the kit: `animate-in fade-in-0 zoom-in-95` on modals/menus, hover lifts (`--shadow-float`), skeletons on load, optimistic toasts. `motion-reduce` guards throughout.
- Optional confetti + celebratory `SuccessPane` on a completion recorded or a mandatory cohort hitting 100% (mirror the Leave flourish) — tasteful, not noisy.
- Keyboard: `/` focuses search, `n` opens New course, arrow/Home/End on tabs, Esc closes menus/modals; surface `kbd` hints in menus.
- Live preview where it helps (Assign count/conflicts, Claim total) via the debounced `/preview` idiom.
- Everything re-themes via `--primary`; amber only for attention.

---

## Decisions to confirm with Chane (don't block the design pass)

1. **Source-of-truth direction** — extend `HrCourse` (recommended: catalog stays canonical) vs make `TrainingCourse` canonical.
2. **Competency frameworks + Staff induction** — full hub tabs, hero quick-actions, or just cross-links?
3. **Assignment routing/source semantics** — manual + role rule + H&S requirement; default approver/owner.
4. **Fee model** — org-pays-provider (AP, current) vs staff-claims-reimbursement vs both, and the double-count rule.

---

## Definition of done

- One **golden hero (no clock)** + **three real tabs** (Dashboard · Catalog · Assignments) on `HrTabs`/`TabStrip`, matching the gold-standard pages.
- Catalog has table/density toggle, real sort, widened search, multi-select + bulk bar, and actionable fees; Course detail has full Sessions/Enrolments/Compliance panels; Dashboard and Assignments are real, kit-built surfaces; `/hr/my/training` aligned to tokens (no raw hex).
- Every create/edit/session/assign/record/claim flow is a **full Add-Client-style wizard** (stepper rail, completeness meter, validation, review, Save & add another, uploads) — **no thin modals, no inline forms, no full-page create routes**.
- **Right-click** works on course/enrolment/session/assignment rows **and** the tab strip (Set as default / Pin); every action toasts and hits a real route. No dead items.
- **One course source of truth**; expiry/renewal/CPD flow reliably; `compliance_requirement_id` always set until merged (Dashboard no longer blind to catalog completions).
- NZD / `en-NZ` retained; tenant scoping and `hr.training.*` / `training.*` gates respected; **no regressions** to `/hr/my`, the Dashboard, or finance GL posting.
- Clean `build`, `types`, `lint`; screenshots of each tab **and each modal** match the reference pages.
- **§K filled in** and mirrored to `TRAINING_CATALOG_BACKEND_HANDOVER.md`; `docs/TRAINING_HUB_GAP_ANALYSIS.md` checklist maintained.
- **Signals to watch:** % mandatory training completed on time, overdue-renewal queue size, time-to-record a completion, course-fee claims submitted, assignments created per week.

**Build order:** §A audit → §B hero → §C tab shell → §D Catalog → §E Course detail → §F Dashboard → §G Assignments → §H modals → §I fees → §J right-click → §L delight. Verify each pass against the reference pages, and keep appending discovered backend work to **§K**.
