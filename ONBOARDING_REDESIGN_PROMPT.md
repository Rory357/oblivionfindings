# HR "Onboarding" Hub Redesign — PROMPT (anchored on `/hr/onboarding`)

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/onboarding` (and `?tab=overview|checklists|templates|emails`, the checklist detail/drawer, and every modal), and diff against the gold‑standard pages/components before continuing. Start with the audit in §A, then build §B–§M.
> **Handover rule (Chane's ask):** anything you discover that needs server/data work goes into **§L "Backend handoff for Claude Code"** — append to it as you go, mirror the final list into a new **`ONBOARDING_BACKEND_HANDOVER.md`** at repo root (the clean list Claude Code implements from), and keep a running checklist in **`docs/ONBOARDING_HUB_GAP_ANALYSIS.md`**. Every backend item = short spec + migration sketch + **confirm with Chane before building. No silent schema.**

**Page (canonical):** `/hr/onboarding` · `/hr/onboarding/emails` · `/hr/onboarding/{checklist}`
**Frontend files:** `resources/js/pages/hr/onboarding/index.tsx` (Checklists + the inline template editor to delete), `emails.tsx`, `show.tsx` (detail), `create.tsx` (**retire**); `resources/js/components/hr/onboarding-wizard-dialog.tsx`, `resources/js/components/hr/onboarding-tabs.tsx`
**Backend:** `app/Http/Controllers/Hr/OnboardingController.php`, `OnboardingEmailController.php`; routes `routes/hr.php` (≈L353–375); `app/Console/Commands/Hr/SendScheduledOnboardingEmailsCommand.php`; `app/Domain/Hr/Jobs/SendOnboardingEmailJob.php`; `app/Domain/Hr/Notifications/Onboarding*.php`
**Engine:** `app/Domain/Hr/Services/OnboardingService.php` (**shared with Offboarding — don't fork; new task‑lifecycle methods must serve both**), `OnboardingEmailService.php`
**Models:** `HrOnboardingChecklist`, `HrOnboardingTask`, `HrOnboardingTemplate`, `HrOnboardingEmail`, `HrOnboardingEmailLog` (migrations `2026_02_12_100005_create_hr_onboarding_tables.php`, `2026_03_22_600005_create_hr_onboarding_emails_table.php`, `2026_02_17_220000_expand_hr_workflow_foundations.php`)
**Cross‑loop consumers:** `/hr/people` (`AddEmployeeDialog` `start_onboarding` toggle already triggers onboarding server‑side) · compliance matrix (`ComplianceMatrixService::evaluateStaff`, seeded on launch) · training enrolment, asset/IT provisioning, documents/e‑sign (**all currently un‑wired — see §L**)
**Gold‑standard references to clone (exact paths):**
- Modal shell: `resources/js/components/clients/add-client-dialog.tsx` → extracted kit `resources/js/components/wizard/shell.tsx` + `resources/js/components/wizard/primitives.tsx`; HR entry point `resources/js/components/hr/wizard.ts` (`useWizard`, re‑exports the whole kit)
- Premium modal reference (most recent): `resources/js/components/hr/leave-request-dialog.tsx`
- Employee‑create modal to share: `resources/js/components/hr/add-employee-dialog.tsx` (`AddEmployeeDialog`), hosted in `resources/js/pages/hr/employees/index.tsx`
- Golden hero (clock‑free command band): `resources/js/components/hr/people-hero.tsx` (+ `leave-hero.tsx`, `leave-hub-hero.tsx`); slot hero `resources/js/components/page/page-hero.tsx` / `hr-hero.tsx`. **Never import `my-hr-hero.tsx`/`MyHrClockCard` — it embeds a clock.**
- Right‑click: `resources/js/components/hr/leave-context-menu.tsx` (`useLeaveContextMenu`), in the mould of `resources/js/components/rostering/shift-context-menu.tsx`
- Tabs: `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab` for `?tab=`) on `resources/js/components/rostering/tab-strip.tsx`
- People picker: `resources/js/components/hr/people-picker.tsx` · Status: `resources/js/components/ui/status-badge.tsx` · Tokens: `resources/css/app.css`

---

## 0. Mission

Make `/hr/onboarding` a **premium, end‑to‑end onboarding‑admin surface** that feels identical in quality to our gold‑standard pages — **`/meds/today`**, **`/my-day`**, **`/health-safety`**, **`/hr/people`**, **`/hr/leave`** — and reuses their exact components and tokens.

Today the page is functional but dated and the loop is broken in three places: the **tabs are old views** (just Checklists + Emails, with a giant **inline template editor** dumped on the Checklists page), the **create flow is a thin wizard** that can only start a checklist for someone who already exists, and the **checklist detail page** — where onboarding actually gets done — only lets you *tick a task complete*. There's no reassign, no edit, no ad‑hoc task, no due dates, no evidence upload, no notes, no right‑click, no bulk, no real hero, and several dead/buggy bits (a malformed Tailwind class, an `overdue` status nothing ever sets, hero counts computed from one page of data, a plain `confirm()` on delete).

Bring it to parity: give it the **golden HR hero band** (no clock), a real **4‑tab hub** (Overview · Checklists · Templates · Emails), a **premium checklist workspace** (detail page + quick drawer) with **full per‑task workflows**, swap every create/edit flow to the **exact Add‑Client wizard pattern** (full, not thin), unify the **Add‑Employee modal** with `/hr/people` and add a **"+ New hire"** path so HR can create a person *and* start their checklist in one flow, wire **right‑click on rows and tabs**, and close the **backend gaps** into the handover doc. **Result:** an HR can hire, onboard, assign, chase, sign‑off and complete a new starter without ever leaving a premium surface.

## 1. Non‑negotiables

1. **Keep the tab model; standardise it.** Use the canonical `HrTabs` + `useHrTab` (`?tab=`) on the shared `TabStrip`. Don't hand‑roll tab bars (the current Emails sub‑tabs and the nested `tabs.filter()` strip in `emails.tsx` get deleted).
2. **Reuse the kit — never hand‑roll a primitive we already have.** Wizards come from `@/components/hr/wizard` (`WizardShell`/`useWizard`/`Field`/`StepHead`/`SelectInput`/`ReviewCard`/`ReviewRow`). **No new bespoke widgets, no raw hex** (ESLint blocks it — colours come from design tokens in `resources/css/app.css`).
3. **Web‑only desktop app. No phone frames, no clock in the hero.** Design for mouse + keyboard (right‑click, hover actions, keyboard nav). A dedicated mobile app comes later — not now.
4. **Information‑gathering = modals,** not inline forms and not full‑page routes. The inline template editor on `index.tsx` and the legacy `create.tsx` page both go.
5. **Single source of truth / don't fork.** `OnboardingService` is shared with Offboarding — new task‑lifecycle methods serve both. The People↔Onboarding seam (`AddEmployeeDialog.start_onboarding`) must reuse the same store path, not a parallel one.
6. **No dead UI.** Every button, badge, filter and menu item hits a real route or is removed. `overdue` either becomes a real derived state (server) or stops being shown.
7. **Status colour only via `StatusBadge`.** Delete the hand‑rolled `statusConfig` maps in `index.tsx` and `show.tsx` (including the malformed `bg-muted-foreground/80/10`).
8. **Locale stays NZ.** NZD / `en-NZ` / `Pacific/Auckland`. Do **not** switch to GBP/US.
9. **Destructive actions confirm via `alert-dialog`,** never native `confirm()`/`alert()` (kill the `confirm()` in `emails.tsx` delete).
10. **Verify each pass:** clean `build`, `types`, `lint`; screenshot each tab + each modal + the detail/drawer and diff against the reference pages.

## 2. The shared kit you MUST reuse (exact imports, verified)

**2.1 Hero (golden band, NO clock).** Clone the shape of `resources/js/components/hr/people-hero.tsx` — the explicit clock‑free admin lens — into an `OnboardingHero`. Reuse its `HERO_STYLE` constant (the gold gradient + `--hr-amber` accent + `--shadow-hero`), its stat‑as‑link buttons, quick‑action row, "needs you" chips, and the right‑rail donut⇄ring toggle (persist with `localStorage`, key `hrOnboarding.heroRight`). If you'd rather not fork the band, the slot hero `PageHero`/`HrHero` (`@/components/page`, `@/components/hr/hr-hero`) is also clock‑free and acceptable — but the page already uses the *basic* `PageHero`, and Chane wants the **premium golden band like My HR / People / Leave**. **Never import `MyHrHero`/`MyHrClockCard`.**

**2.2 Modals / wizards.** `WizardShell` chrome from `@/components/hr/wizard` (rail of `{key,label,icon,blurb}` steps, 3px progress, custom `[&>button]:hidden` close, `footerStart`/`footerEnd`, `success` pane). Markers to match from `add-client-dialog.tsx`: a `STEPS` array, `validateStep()` per‑step gating, `stepForError()` jump‑to‑first‑error, `WizardSuccessPane`, **Save & add another** (create mode), `forceFormData` for uploads, spinner on submit. Field primitives: `Field`, `FieldErr`, `StepHead`, `SubHead`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `PeoplePicker`. Premium touches to lift from `leave-request-dialog.tsx`: a pinned `railExtra` summary card, live preview pane on Review, `WizardStepPane` 300ms transitions, `toast` + confetti on success.

**2.3 Right‑click menus + hover actions.** `useLeaveContextMenu` from `@/components/hr/leave-context-menu` (a portal + `open(items)` handler usable for both `onContextMenu` and a `⋯` button; typed `LeaveCtxItem` union with `tone:'critical'|'success'`, dividers, `kbd`). Tabs support right‑click natively: `HrTabs` accepts `onItemContextMenu` + `decorations`.

**2.4 Cards / tables / states / badges.** `Card`, `StatusBadge` (everywhere — delete hand‑rolled colour maps), `Progress`, `LaravelPagination`. Every list needs a real **empty state** (icon + line + CTA) and a **skeleton**. Replace the hand‑rolled `<table>`s with the standard table styling used on `/hr/people` / `/hr/leave`.

**2.5 Tabs.** `HrTabs` + `useHrTab('overview', { param:'tab' })` from `@/components/hr/hr-tabs`. One page, four tabs, deep‑linkable `?tab=`, refresh‑safe, no server round‑trip for tab switches (load all four tabs' data on the index controller, or lazy‑load Emails — your call, but keep one URL surface).

**2.6 Tokens & flourishes.** From `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Toasts: sonner** (`<Toaster>` already mounted in `resources/js/app.tsx` — `toast.success/error` on **every** action). **Animations: `tailwindcss-animate`** (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

## A. Audit & benchmark first (do this before building)

Open the four reference surfaces side‑by‑side (`/meds/today`, `/my-day`, `/hr/people`, `/hr/leave`) and the onboarding files, then **paste the results back as your first pass and seed `docs/ONBOARDING_HUB_GAP_ANALYSIS.md`.**

**Checklist**
- [ ] Map every tab/view today: Checklists (`index.tsx`), Emails (`emails.tsx` — note its nested Templates/Preview/Log sub‑bar), detail (`show.tsx`), legacy create (`create.tsx`).
- [ ] List every modal/flow and grade thin vs full: `OnboardingWizardDialog` (6 steps, no `validateStep`, no success pane, can't customise tasks/owners/dates, existing‑employee only), `EmailTemplateDialog` (plain shadcn `Dialog`, not the wizard), inline template editor (not a modal at all).
- [ ] List every row action and whether it's a real route: checklist row = "View" only; template row = "Edit" only; email row = preview/edit/delete (`confirm()`).
- [ ] Confirm the bugs: malformed `bg-muted-foreground/80/10` (`index.tsx`≈L102, `show.tsx`≈L45); hero stats from `checklists.data` (current page only, `index.tsx`≈L262‑267); `overdue` in the TS union + filter but never set server‑side; `any`‑typed email log rows.
- [ ] Trace where assets/actions land (close the loop): completed‑task evidence (`evidence_path` string only — no upload), sign‑off (`signed_off_by`/`_at` only — no artifact), welcome email (`hr_onboarding_email_log`), new hire from "+ New hire" (→ `/hr/people` store), compliance seeding (matrix), reassignment/overdue (→ who gets notified? today: nobody after assign‑time).

> **Known gaps this audit already surfaced (confirm, then fix):**
> **Tabs:** templates are an inline form, not a tab; there's no Overview/dashboard; Emails has a non‑standard nested tab bar. **Build the 4‑tab hub (§C).**
> **Detail/workspace:** complete‑only checkboxes; no reassign/edit/add/reorder/uncomplete/due‑date/evidence/notes; no grouping by category; no hero; raw status maps. **This is the worst surface — rebuild as §F.**
> **Create flow:** existing‑employee only, thin. **Make it the full Add‑Client wizard with a "+ New hire" path (§I/§J).**
> **Emails:** plain dialog + `confirm()`; preview is a separate route, not in‑modal; no "send test". **§H + §I.**
> **Backend:** task lifecycle is essentially read+complete; no edit/reassign/add/delete/reorder/uncomplete, no manual complete/cancel/archive, no checklist/template delete route, no send‑test, no evidence upload, no overdue reminders, no FormRequests. **All into §L.** (Note: `dependency_task_ids` **does** exist via the `2026_02_17_220000` migration — not a bug.)

## B. Hero rethink — the golden band (NO clock, fitted to onboarding)

**Current:** basic `<PageHero category="hr" icon={UserPlus}>` with four stats computed from one page of rows; "Overdue" is always 0.
**Do:** build `OnboardingHero` (clone `people-hero.tsx`'s `HERO_STYLE`, no clock). Left: title "Onboarding", subtitle, **stat‑as‑link** chips driven by a **server summary** (all rows, not the page): **Active checklists · In progress · Overdue · Due this week · Avg completion %** — gold (`--hr-amber`) flags the counts needing a decision (Overdue, Due this week). Quick actions: **Start onboarding** (primary → wizard), **New template**, **Email templates**, **Export**. "Needs you" chips: *N overdue tasks*, *N awaiting sign‑off*, *N starters this week* — each deep‑links into the filtered Checklists/Tasks view. Right rail: toggle a **status donut** (pending/in‑progress/completed/overdue) ⇄ **completion ring** (avg %), persisted to `localStorage` `hrOnboarding.heroRight`.

## C. Tabs — the hub shell (Overview · Checklists · Templates · Emails)

Replace `OnboardingTabs` (`checklists`/`emails`) and the separate routes with **one `?tab=` hub** using `HrTabs` + `useHrTab`:

1. **Overview** (default, NEW, §D) — dashboard: KPIs, overdue & due‑this‑week queues, awaiting sign‑off, recent activity, starters calendar strip.
2. **Checklists** (§E) — the active‑onboarding list, premium table + filters + bulk + right‑click; opens the detail page/drawer (§F).
3. **Templates** (NEW tab, §G) — move the inline editor here; card/table of templates + the **Template modal** (§I).
4. **Emails** (§H) — templates + sent log, all via modals; preview in‑modal.

> Per tab: shared list/card + `StatusBadge` chips; real **empty state** (icon + line + CTA) and **skeleton**; every create/edit flow is a **modal** (§I); every row has a **right‑click menu** (§K) + hover actions; **toast** every result. Keep view‑only users from seeing manage‑only tabs/actions (respect `hr.onboarding.view` vs `hr.onboarding.manage`, as `onboarding-tabs.tsx` already does for Emails).

## D. Overview / Dashboard tab (NEW)

A scannable command view (mirror the dashboards on `/hr/leave` and `/hr/training`):
- **KPI row** (reuse hero stat tiles): Active · In progress · Overdue · Due this week · Avg completion · Completed (30d).
- **"Needs attention" lanes:** *Overdue tasks* (task, assignee, checklist, days late → Reassign/Remind/Open), *Awaiting sign‑off* (→ Sign‑off modal), *Starters this week* (employee, start date, checklist progress → Open).
- **Recent activity** feed from `HrAuditLog` (`onboarding.*` create/update/complete) — who did what, when.
- **Starters strip:** next 14 days of start dates with checklist progress bars.
- Empty states for each lane. Every item deep‑links into Checklists/detail with the right filter.

## E. Checklists tab redesign

**Current:** hand‑rolled `<table>`, raw `statusConfig`, "View" link only, bare filter row, no bulk/right‑click/skeleton.
**Do:** standard table (parity with `/hr/people`): columns **Employee** (avatar + name + position), **Template**, **Status** (`StatusBadge` incl. derived **Overdue** = due date past & not complete), **Progress** (`Progress` + `x/y`), **Owner**, **Due**, **Started**, row `⋯`. Filters as a proper toolbar: search (debounced, not Enter‑only), Status, Site/role, Owner, Due range, "Overdue only", "Awaiting sign‑off". **Bulk select** → bulk reassign owner, send reminder, mark complete, cancel/archive, export. Row click → detail page (§F); hover actions (Open, Quick peek, Remind); right‑click (§K). Real empty + skeleton states. Stats/counts come from the server summary, not `checklists.data`.

## F. Checklist detail page + quick drawer (the workspace) — **the headline rebuild**

This is where onboarding happens and today it's complete‑only. Build **both** a premium **detail page** (`show.tsx`) and a **quick‑peek drawer** (slide‑over from a row) that share one `ChecklistTasks` component.

**Detail page chrome:** golden compact `OnboardingHero`/`HrHero` (back to `/hr/onboarding`, employee name + position + start date, status `StatusBadge`, overall `Progress`, due date) with header actions: **Add task**, **Reassign owner**, **Send reminder**, **Mark complete**, **Cancel/Archive**, **Export**.

**Tasks, grouped by category** (General · IT · Compliance · Payroll · Induction) with per‑group progress. Each task row shows: title + description, **assignee** (avatar via `PeoplePicker` options), **due date** (relative + overdue tint), **Required**/**Sign‑off** chips, status. **Per‑task actions** (hover + right‑click + `⋯`), each a real route in §L:
- **Complete** → opens a **Complete‑task modal** when evidence/notes/sign‑off apply (upload evidence, add note, sign‑off as current user); plain one‑click when not.
- **Reopen** (uncomplete), **Reassign** (`PeoplePicker`), **Edit** (title/desc/category/due/required/sign‑off), **Add note**, **Upload evidence**, **Delete** (ad‑hoc; `alert-dialog`).
- **Drag to reorder** within a group (persists `sort_order`).
- **Add task** (ad‑hoc) via the Task modal (§I).

**Quick drawer:** the same `ChecklistTasks` in a right‑hand slide‑over opened from the Checklists row (no page nav) for fast triage — complete/reassign/remind without leaving the list. "Open full page" link in the drawer header.

**Close the loop:** completing the last required task auto‑completes the checklist (existing `checkChecklistCompletion`) → toast + activity entry + `onboarding.checklist.completed` webhook; reassigning fires a notification (§L); evidence lands against the task (upload endpoint, §L) and is viewable from the row.

## G. Templates tab + Template modal (NEW — move off the inline form)

**Current:** a ~450‑line inline create/edit form **on the Checklists page** (`index.tsx`≈L316‑768) + a hand‑rolled templates table with "Edit" only; no delete/duplicate/preview.
**Do:** a Templates **tab** with a card/table (Role · Site type · #Tasks · Active · Updated · `⋯`) and **all template editing in the Template modal** (§I). Row actions: Edit, **Duplicate**, **Preview tasks** (read‑only list), Activate/Deactivate, **Delete** (`alert-dialog` → needs the delete route, §L). Empty + skeleton states. Delete the inline editor from `index.tsx` entirely.

## H. Emails tab redesign

**Current:** compact hero; a **non‑standard nested tab bar** (Templates/Preview/Log); `EmailTemplateDialog` is a plain shadcn `Dialog`; delete uses `confirm()`; preview is a separate route; log is `any`‑typed with raw status ternaries.
**Do:** fold into the hub. **Templates:** card/table with right‑click (Edit, **Send test**, Duplicate, Preview, Activate/Deactivate, Delete via `alert-dialog`) + the **Email modal** (§I) with **in‑modal live preview** and **merge‑token insert buttons** (not just a token list) — tokens `{{employee_name}} {{position_title}} {{start_date}} {{manager_name}} {{company_name}}`. **Sent log:** typed rows, `StatusBadge` (sent/failed/pending), filters (status, template, date), pagination; surface scheduling context (`send_days_before_start`). Kill the secondary tab bar and the standalone preview route (preview becomes in‑modal).

## I. Modals = exact Add‑Client wizard pattern (full, not thin)

Every flow below uses `WizardShell`/`useWizard` with `validateStep` + `stepForError` + `WizardSuccessPane` + `toast`; destructive confirms via `alert-dialog`; uploads via `forceFormData`. **No thin flows.**

1. **Start Onboarding** (rebuild `onboarding-wizard-dialog.tsx`). Step 0 gains a `Segmented`: **Existing employee** ⇄ **+ New hire**.
   - *Existing employee* → `PeoplePicker` (as today).
   - *+ New hire* → embed the **shared employee‑create step group** from `AddEmployeeDialog` (§J): name*, email*, position, employment type, department, primary site, manager, start date, RTW, emergency contacts.
   - Then: **Role & start** (auto‑match preview) → **Template** (`SelectInput`, auto‑match sentinel) → **Customise tasks** (NEW: edit titles, toggle required/sign‑off, set per‑task owner via `PeoplePicker`, set due‑day offsets, add/remove ad‑hoc rows *before* launch) → **On launch** (assign compliance, send welcome email **with inline preview** of the chosen template) → **Review & launch** (`ReviewCard`s + edit pencils). Success pane (confetti, like `leave-request-dialog`) → "Open checklist" / "Start another". `validateStep` gates employee + a resolved template; jump‑to‑error on server fail. Posts to `onboarding.store` (one path, whether existing or new hire — see §J/§L).
2. **Template modal** (Create/Edit/Duplicate) — Steps: **Basics** (role*, site type, active) → **Tasks** (repeatable rows: category `Segmented`, title*, description, assigned role, **due‑day offset**, required, sign‑off; drag to reorder) → **Review**. Save & add another (create). Posts to `onboarding.templates.update`; Duplicate pre‑fills then creates.
3. **Email template modal** (Create/Edit/Duplicate) — single rich step: name*, subject*, body (token insert buttons), `send_days_before_start` (−90..90 with the friendly helper), active; **live preview** pane; **Send test** action. Posts to emails store/update.
4. **Complete‑task modal** — evidence upload (`forceFormData` → §L endpoint), note, sign‑off (current user) when `sign_off_required`. Posts to `onboarding.tasks.complete` (extended) / new edit endpoints.
5. **Reassign / Edit‑task modal** — `PeoplePicker` owner, due date, required/sign‑off, title/desc/category. New routes (§L). Reassign fires a notification.

## J. The Add‑Employee unification (shared component + "+ New hire")

Chane's call: **share one employee‑create component across `/hr/people` and `/hr/onboarding`** and improve it.
- Extract the person/job/RTW/emergency step group from `AddEmployeeDialog` into a **shared `EmployeeCreateSteps`** (or export the step bodies) used by **both** `AddEmployeeDialog` (People) and the **"+ New hire"** branch of Start Onboarding. One schema, one validation, one store path.
- On `/hr/people`, `AddEmployeeDialog` keeps its **`start_onboarding`** toggle (already present) — when on, it kicks off onboarding server‑side. On `/hr/onboarding`, "+ New hire" creates the person **and** continues into template/tasks/launch in the same wizard.
- **Don't fork the store path.** Confirm with Claude Code how `/hr/people` store + `start_onboarding` and `onboarding.store` should converge so a new hire created from either side produces the same employee + checklist (§L). Improve the shared modal (better validation, success pane, dedupe "Link to existing" already present — keep it).

## K. Right‑click everywhere (rows **and** tabs)

Chane explicitly wants right‑click "under tabs etc." Build `OnboardingContextMenu` with `useLeaveContextMenu` (in the mould of `ShiftContextMenu`). Wire `onContextMenu` + a `⋯` button on every row, and `HrTabs.onItemContextMenu` on the tab strip.
- **Checklist row:** Open · Quick peek (drawer) · Continue · Reassign owner · Send reminder · Mark complete · Cancel/Archive (critical) · Copy link.
- **Task row:** Complete / Reopen · Reassign · Edit · Upload evidence · Add note · Delete (critical).
- **Template row:** Edit · Duplicate · Preview · Activate/Deactivate · Delete (critical).
- **Email row:** Edit · Preview · Send test · Duplicate · Activate/Deactivate · Delete (critical).
- **The tab strip itself:** Set as default view · Open · Pin — persist to `localStorage` `hrOnboarding.defaultTab`, render a star/pin via `HrTabs` `decorations`.

Every menu action fires a toast and, where it writes, hits a real route (§L). **No dead items.**

## L. Backend handoff for Claude Code (append as you design)

Claude design: as you build the UI and discover anything that needs server work, **add it here**, mirror the finished list into **`ONBOARDING_BACKEND_HANDOVER.md`**, and tick it in **`docs/ONBOARDING_HUB_GAP_ANALYSIS.md`**. For each item: short spec + migration sketch + **confirm before building. Don't silently invent schema.** All mutations gated `hr.onboarding.manage`, respect `ResolvesHrTenant`, add **FormRequest** classes (none exist today — validation is inline), and write to `HrAuditLog` via the existing `AuditableChanges` trait.

**Bugs / scoping to fix:**
1. Checklist `status` migration default is `'not_started'` but code only uses `pending|in_progress|completed` — align default to `pending`, backfill stray rows.
2. `overdue` is computed only in reporting yet the list/filter/hero treat it as a status — expose a **derived overdue** (due date past & not completed) on the index payload **and** a server **summary** aggregate (counts over all rows, for the hero/Overview).
3. `OnboardingEmailController@index`/`log` are **not tenant‑scoped on read** — confirm intended; scope with `ResolvesHrTenant`.

**Source‑of‑truth & cross‑loop:**
4. Converge `/hr/people` store (`start_onboarding`) and `onboarding.store` so "+ New hire" (§J) yields the same employee + checklist via one path — don't fork.
5. New task‑lifecycle methods live on the **shared `OnboardingService`** so Offboarding inherits them.
6. Decide wiring (link now, auto‑create later) for `it`/`induction` tasks → **assets/IT provisioning** and **training enrolment**; and for `evidence_path`/sign‑off → **`HrDocument`/`HrDocumentSignature`** (real upload + signed artifact, not a bare string).

**Missing endpoints (spec → confirm → implement):**
7. `PATCH /onboarding/tasks/{task}` — edit (title/desc/category/due_date/is_required/sign_off_required) **and reassign** (`assigned_to_user_id`/`role`); notify on reassign.
8. `POST /onboarding/tasks/{task}/uncomplete` — reopen; recompute checklist to `in_progress`.
9. `POST /onboarding/{checklist}/tasks` — add ad‑hoc task · `DELETE /onboarding/tasks/{task}` — delete ad‑hoc.
10. `POST /onboarding/{checklist}/tasks/reorder` — persist `sort_order`.
11. Extend `onboarding.tasks.complete` to accept **multipart evidence upload** + note (store file, set `evidence_path`/link `HrDocument`).
12. `POST /onboarding/{checklist}/complete` and `/cancel` (or `/archive`) — manual close; add `cancelled`/`archived` to the enum · `DELETE /onboarding/{checklist}` — destroy (DB cascade exists, no route).
13. `DELETE /onboarding/templates/{template}` — delete; plus a duplicate path.
14. `POST /onboarding/emails/{email}/test` — send test email (preview is screen‑only today).
15. **Overdue reminder/escalation** command + notification (today only a reporting roll‑up) and **due‑soon** reminders to assignees; **completion** notification (only assign‑time notifications exist).
16. Server **summary** endpoint/props for the Overview KPIs and hero (all‑rows counts) and the Overview "recent activity" from `HrAuditLog` (`onboarding.*`).

## M. Premium polish & delight

`WizardStepPane` transitions; confetti on a launched checklist and a completed checklist; gold `--hr-amber` only for attention counts; skeletons on every list; optimistic toasts; keyboard nav (arrow‑rove menus, `Esc` closes, `Enter` advances steps); hover‑lift on cards; relative + absolute dates (`en-NZ`); avatars via `PeoplePicker`; per‑group progress on the detail page; "X of Y required tasks done" microcopy; empty states that teach the next action.

## Decisions confirmed with Chane (don't re‑litigate)
- **Tabs:** full 4‑tab hub — **Overview · Checklists · Templates · Emails** (templates leave the inline form).
- **Detail:** **premium detail page + quick‑peek drawer** sharing one `ChecklistTasks` component.
- **Add Employee:** **one shared employee‑create component** across People & Onboarding, with a **"+ New hire"** path inside Start Onboarding; improve the shared modal.
- **Backend:** **handover‑only** — spec into `ONBOARDING_BACKEND_HANDOVER.md` + `docs/ONBOARDING_HUB_GAP_ANALYSIS.md`, confirm before building.

## Definition of done
- `/hr/onboarding` is one `?tab=` hub (Overview · Checklists · Templates · Emails) with the **golden hero band, no clock**, stats from a server summary.
- The inline template editor and legacy `create.tsx` are gone; templates live in their tab + the **Template modal**; `/hr/onboarding/create` route retired.
- The checklist **detail page + quick drawer** support full per‑task workflows (complete/reopen/reassign/edit/add/delete/reorder/evidence/notes/sign‑off), grouped by category, with right‑click.
- **Start Onboarding** is the full Add‑Client wizard with a **"+ New hire"** path; People & Onboarding **share one employee‑create component**; all create/edit flows are wizard modals (no inline forms, no `confirm()`).
- **Right‑click on every row and on the tab strip**; tab "Set as default" persists; no dead items.
- `StatusBadge` everywhere; the malformed class and the dead `overdue` handling are fixed; email log is typed.
- **§L backend handoff is filled in**, mirrored to `ONBOARDING_BACKEND_HANDOVER.md`, checklist in `docs/ONBOARDING_HUB_GAP_ANALYSIS.md`.
- Clean `build`, `types`, `lint`; screenshots of each tab + each modal + detail/drawer match the reference pages.
- **Signals to watch:** time‑to‑complete onboarding, % checklists completed by start date, overdue‑task queue size, % tasks signed‑off on time.

**Build order:** §A audit (seed `docs/ONBOARDING_HUB_GAP_ANALYSIS.md`) → `OnboardingHero` (golden band) → `HrTabs`/`useHrTab` hub shell → **Checklists** table (StatusBadge, filters, bulk, right‑click) → **Checklist detail page + drawer** (§F, the headline) → **Templates** tab + Template modal (delete inline editor) → **Start Onboarding** wizard rebuild + **"+ New hire"** / shared employee modal (§I/§J) → **Emails** tab + Email modal (in‑modal preview, send‑test) → **Overview** dashboard → right‑click everywhere (§K) → delight pass (§M). Append backend discoveries to §L as you go. Verify each pass against the reference pages.
