# HR "Recruitment" (Hiring & Onboarding intake) Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/recruitment` (and `?tab=` for each tab + each modal open) **and** the candidate detail surface, and diff against the gold‑standard pages/components before continuing. Start with the audit in §A, then build §B–§M. **Anything you discover that needs backend/data work goes into §K "Backend handoff for Claude Code" — append to it as you go so Chane has one clean hand‑off list when the design is done.**

**Page:** `https://oblivionfindings.com/hr/recruitment` (recruiter / hiring‑manager lens)
**Frontend (tabs, today = 5 separate route‑pages):** `resources/js/pages/hr/recruitment/{index,kanban,jobs,analytics,kits}.tsx` · scorecard pages `resources/js/pages/hr/recruitment/{scorecard,scorecard-summary}.tsx` (off‑cluster) · **tab strip:** `resources/js/components/hr/recruitment-tabs.tsx` · candidate dossier `resources/js/pages/hr/candidates/show.tsx` + `candidates/create.tsx` + `candidates/create-offer.tsx` · components `resources/js/components/recruitment/*`
**Backend:** `app/Http/Controllers/Hr/RecruitmentController.php` · `RecruitmentJobController.php` · `CandidateController.php` (the workhorse) · `InterviewKitController.php` · `OnboardingController.php` · `OnboardingEmailController.php` · **legacy** `JobPostingController.php` + `ScorecardController.php` (orphaned) · public `App\Http\Controllers\Careers\CareerPortalController` (new, requisition‑based) **and** root `App\Http\Controllers\CareerPortalController` (legacy, posting‑based) · routes in `routes/hr.php` (`:124‑201` recruitment, `:335‑357` onboarding) + `routes/web.php` (`:97‑103` careers)
**Engine / services:** `app/Domain/Hr/Services/RecruitmentService.php` (pipeline core: `advanceStage`, `convertToEmployee`) · `RecruitmentAnalyticsService.php` (real SQL) · `app/Services/EmployeeIntakeService.php` (the shared door into User+profile creation) · `OnboardingService.php` · `HrWebhookService`
**Models:** `HrCandidate`, `HrApplication`, `HrInterview`, `HrInterviewScore` (+ **dead** `HrInterviewScorecard`), `HrReferenceCheck`, `HrOffer`, `HrInterviewKit`, `HrJobRequisition` (live), `HrJobPosting` (legacy/richer), `HrCandidateDocument` (all `app/Domain/Hr/Models/`) · `HrEmployeeProfile`, `HrPosition`, `HrOnboardingTemplate/Checklist/Task`, `App\Models\User`
**Data:** `database/migrations/2026_02_12_100001_create_hr_recruitment_tables.php` (+ `expand_hr_ats…`, `enhance_hr_job_postings…`, `create_hr_job_postings_table`, `create_hr_interview_scorecards_table`, `create_hr_candidate_documents_table`, `add_offer_letter…`, `add_position_links_and_jd_to_hr…`, `create_hr_onboarding_tables`, `create_hr_onboarding_emails_table`)
**Gold‑standard modal to clone:** `resources/js/components/clients/add-client-dialog.tsx` (the quality bar), built on `resources/js/components/wizard/primitives.tsx`. **Build new modals on the prop‑driven `resources/js/components/wizard/shell.tsx` (`WizardShell`) kit** — the same shell the **Leave "New Request"** modal Chane loves uses.
**Premium modal Chane cited ("/hr/leave → New Request"):** `resources/js/components/hr/leave-request-dialog.tsx` *(note: there is no `my-hr-leave-wizard.tsx`)* — built on `@/components/hr/wizard` (re‑exports `WizardShell` + `useWizard` + primitives).

---

## 0. Mission

Turn `/hr/recruitment` into a **premium, end‑to‑end Applicant Tracking System (ATS)** that feels identical in quality to our gold‑standard pages — **`/hr/people`**, **`/hr/leave`**, **`/meds/today`**, **`/health-safety`** — and reuses their exact components and tokens. This is the recruiter + hiring‑manager command centre for the **whole hire lifecycle**: requisition → posting → applicants → screening → interviews & scorecards → references & **NZ pre‑employment safety checks** → offer → **convert to employee** (login + staff profile + onboarding). Because recruitment is **the front door to new‑user and staff creation**, the conversion handoff must be **audited, bulletproof and visible on the page** (§H) — nothing silently dropped.

Today `/hr/recruitment` is functional but dated and fragmented:
- **Five standalone "old view" pages masquerading as tabs** (Pipeline / Board / Jobs / Analytics / Kits) wired by `recruitment-tabs.tsx` with a hard `router.visit` **full reload per tab** (`recruitment-tabs.tsx:38‑39`), **no `?tab=` deep link**, **no per‑tab counts**, and two scorecard pages that aren't even in the strip.
- A **generic `PageHero` gradient** (`PageHero category="hr"`), **not** the golden My‑HR band with live `HeroStat`s, `QuickAction`s and "needs you" chips.
- A **Kanban that only looks like a board** — cards are plain `<Link>`s (`candidate-card.tsx:29`), **no drag‑to‑move**, no stage mutation.
- **Thin single‑screen modals**: the Jobs create/edit dialog crams ~12 fields into one scroll (`jobs.tsx:315‑713`); the Kits dialog is one screen with a **dead `GripVertical` reorder handle** (`kits.tsx:321`). The core flows — **interview scheduling, references, offers, documents** — are **exiled to the candidate `show.tsx` detail page** and two off‑cluster full‑page scorecard routes, not in the hub.
- **Four different status‑badge colour systems** and a **forked** `@/components/recruitment/status-badge.tsx` instead of the shared `@/components/ui/status-badge`.
- **No right‑click menus**, **no bulk actions**, **no export**, **no saved views**, **no talent pool**, **no skeletons**, and native `confirm()` on `candidates/show.tsx:328` (reject) + `:397` (delete document).
- Under the hood, **recruitment is built twice** (a split‑brain `HrJobRequisition` vs `HrJobPosting`, two careers controllers, two scoring tables) and the **hire handoff has real gaps** — the candidate is never emailed their offer, `position_id` is never written so hires don't land in an establishment seat, and converting a candidate **mints a login account under `hr.recruitment.manage`, bypassing `hr.employees.manage`**.

Bring it to parity: give it the **golden HR hero band (no clock, fitted to recruitment)**, a real **`RecruitmentTabs`** shell with right‑click menus, a **genuinely draggable board**, premium **Add‑Client‑grade wizard modals for every flow** (full workflows, not thin forms), **right‑click everywhere**, a **consolidated single job model** (§G — decided), a **hardened, visible hire→user→staff→onboarding handoff** (§H — decided), the **missing ATS comms** (offer email, interview invites, reference outreach, rejection — §I), and **NZ care‑sector safety‑check capture** (§J). Result: hiring that is **fast, glanceable, auditable and premium** — not five grey route‑pages with the real work hidden on a detail page.

---

## 1. Non‑negotiables

1. **Introduce a real tab model.** The five **separate Inertia pages** wired by `recruitment-tabs.tsx` are the "old views" Chane means — replace them with a proper in‑page **`RecruitmentTabs`** shell built on `HrTabs` + `useHrTab` (`?tab=` deep‑link, refresh‑safe, no server round‑trip), per‑tab counts as badges, right‑click tab menu. **You propose the final tab set during the §A audit and get Chane's sign‑off before building** (recommended set in §C). Bring the **exiled flows** (interviews, references, offers, documents) **onto the hub** — don't leave the real work stranded on `candidates/show.tsx`.
2. **Reuse the kit — never hand‑roll a primitive we already have** (§2). Every hero, modal, badge, status colour, context menu, empty/skeleton state, calendar and toast comes from the shared kit. **No new bespoke widgets, no raw hex** (ESLint blocks it — colours come from design tokens). Retire the forked 12‑colour recruitment badge in favour of one canonical stage map + the shared `StatusBadge` for semantic states (§2.4).
3. **Information‑gathering = full‑workflow modals.** Every create/edit/schedule/score/offer/convert/reject flow becomes a **multi‑step wizard dialog** cloning the Add‑Client shell quality bar, implemented on `@/components/wizard/shell` (`WizardShell`) like the Leave "New Request" — **not** a thin one‑screen `Dialog`, **not** an inline form, **not** a full‑page route. **"Each modal must be a full workflow"** (Chane): stepper rail + completeness/validation + per‑step validation + server‑error→step mapping + Save & add another (where it makes sense) + `SuccessPane`. The thin Jobs/Kits dialogs and the full‑page candidate/offer forms are exactly what to replace (§F).
4. **One job model.** *(Decided — §G)* Consolidate onto **`HrJobRequisition`** as canonical; **port** salary range, screening questions and the **requisition‑approval workflow** from the legacy `HrJobPosting`; retire `HrJobPosting` + the duplicate root `CareerPortalController`. One requisition, one public careers portal, one `hr_applications` FK. Spec the migration in §K and **confirm before building**.
5. **The hire handoff is sacred and visible.** *(Decided — §H)* Audit and harden `convertToEmployee` → `EmployeeIntakeService` (login `User` + `HrEmployeeProfile` + role + onboarding + invite). Surface it as a **premium Convert‑to‑Employee wizard on the page** that fills the **seat** (`position_id`), sets the **work email**, picks the **onboarding template**, seeds **compliance/safety checks**, and sends a **branded welcome email** — not a bare reset link. **Creating the login/staff account additionally requires `hr.employees.manage`** (segregation of duties — decided). Nothing about new‑user/staff creation may be silently dropped.
6. **Close the ATS comms gaps** (§I): the candidate **must be emailed their offer portal link** on send; interviews must produce **calendar invites (.ics) + emails** to interviewers and candidate; references must **send the referee a request**; rejections must (optionally, gated) **notify the candidate**. Today none of these fire on the live path.
7. **Web‑only desktop app.** No phone frames, **no clock** in the hero. Design for mouse + keyboard: hover states, **right‑click menus** (rows **and** the tab strip), keyboard shortcuts, **drag‑and‑drop** on the board. Responsive down to a small laptop is fine. (A dedicated mobile app comes later — not now.)
8. **Locale & statute stay NZ.** NZD / `en-NZ` formatting and dates. Pre‑employment **safety checks** follow NZ practice for supported living (identity & right‑to‑work, **NZ Police vetting**, **Children's Act 2014 safety checking** where applicable, referee checks, qualification/registration verification) and flow into onboarding compliance (§J). Do **not** switch to GBP/US.
9. **Respect scoping & permissions.** Everything tenant‑scoped via `ResolvesHrTenant` / `resolveHrTenantIdForUser`. View gated by `hr.recruitment.view`, manage by `hr.recruitment.manage`; **convert‑to‑employee additionally gated by `hr.employees.manage`** (§H). Hide manager‑only UI when the user lacks the gate.
10. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint` (no raw hex); screenshot the changed surface; confirm it matches the reference page's hero/modal/menu. Don't move on with a broken pass.

---

## A. Audit & benchmark first (do this before building)

Study `/hr/people`, `/hr/leave`, `/meds/today`, `/health-safety` and **interact** with them — they are the parity bar. Then study the three patterns you must clone:

- **Golden hero** → `resources/js/components/hr/people-hero.tsx` (admin/manager lens, **no clock** — its own comment: *"No clock — this is the admin/manager lens"*; `HERO_STYLE` brand‑gradient band, clickable `HeroStat`s, `QuickAction`s, "needs attention" amber‑dot chips, **right‑rail toggle persisted to `localStorage`** `hrp.heroRight`) and `resources/js/components/hr/my-hr-hero.tsx` (same gradient + `HeroStat` + `QuickAction` + "needs you" chips — but **note its right column is the `MyHrClockCard`**, which Recruitment must **omit**). **There is no shared `hero-kit.tsx`** — `MyHrHero` and `PeopleHero` each define their own `HERO_STYLE`. **Recommended: extract a shared `resources/js/components/hr/hero-kit.tsx` (`HERO_STYLE` + `HeroStat` + `QuickAction` + needs‑chip) and build `RecruitmentHero` on it**, refactoring My HR / People / Leave onto the same spine (the standardisation win Chane keeps asking for). Follow the **People hero shape (manager lens, no clock)**.
- **Gold‑standard modal** → `resources/js/components/clients/add-client-dialog.tsx` — the quality bar: full‑height bespoke shell (`Dialog`+`DialogContent` `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, **left stepper rail** `w-[248px] bg-sidebar` with per‑step icon+blurb+check, a **completeness meter** at the rail foot, header "Step X of N", **top progress bar**, scroll‑contained body, footer Back / Cancel / **Save & add another** / primary), a `STEPS` array (`{key,label,icon,blurb}`), `validateStep()`, `stepForError()`, `SuccessPane`, `forceFormData` for uploads. **Build the new modals on the prop‑driven `resources/js/components/wizard/shell.tsx` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`, `WizardStep`) + `useWizard()`** — the modern version of that exact pattern that the **Leave `leave-request-dialog.tsx`** uses (`maxWidth`, `maxHeight`, `railExtra` for a live context card, `pct={null}` to hide the meter, `footerStart/End`, `success` body). Match Add‑Client's quality; use Leave's plumbing.
- **Tab strip + right‑click** → `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, keyboard arrows/Home/End, `onItemContextMenu(id,event)`, `badge` per tab, `decorations` slot, `trailing` slot) wrapped by `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab,{param:'tab',syncUrl:true})`). `recruitment-tabs.tsx` does **not** use these — rebuild it on them. Right‑click menu via `resources/js/components/rostering/shift-context-menu.tsx` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState` — portal‑rendered, viewport‑flipping, Esc/outside‑click close) or HR's `resources/js/components/hr/leave-context-menu.tsx`.

Then audit `/hr/recruitment` **and** the candidate dossier (`candidates/show.tsx`) against this **best‑in‑class ATS + care‑sector checklist** (mark each **Present / Partial / Missing**, then close gaps in §B–§M). Benchmarks: **Greenhouse / Lever / Ashby** (structured interviewing, scorecards, kits, interview scheduling with calendar, offer approvals, reporting), **Workable / BambooHR / Workday** (requisition approval, careers site, job‑board syndication, offer letters, e‑sign), **JobAdder / Employment Hero / SEEK Talent / Trade Me Jobs** (AU/NZ market — SEEK/Trade Me syndication, talent pool, candidate SMS/email), and **care‑sector specifics** (right‑to‑work, **NZ Police vetting**, **Children's Act 2014 safety checks**, referee checks, qualification/registration verification, immutable audit trail).

**Checklist (fill this in as the first pass and paste back the results):**

- **Hero:** golden brand band • recruiter stats that matter (open requisitions, active candidates, interviews this week, offers out / awaiting response, **time‑to‑hire**, applications needing acknowledgement) • quick actions (Add candidate / New requisition / Schedule interview / Review offers / Export) • live "needs you" badges (offers awaiting **my** approval, interviews to score, candidates stuck > N days in a stage, offers expiring soon, **applications with no acknowledgement sent**) • **no clock**.
- **Tabs:** real `RecruitmentTabs` (not five route pages) • per‑tab counts • **right‑click tab menu** (set default, open, pin) • `?tab=` deep‑link • interviews/references/offers/documents **on the hub**.
- **Pipeline (list):** filter by stage/source/requisition/site/date + search • saved/default view • **bulk actions** (advance, reject, email, export, add‑to‑pool) • per‑row history • real empty/skeleton/error states • **right‑click row menu**.
- **Board (Kanban):** **real drag‑and‑drop** between stages that **persists** (`applications.advance`) with optimistic UI + toast + undo • WIP/aging signals • right‑click • not a read‑only wall.
- **Requisitions (Jobs):** create/edit **wizard** • **JD inheritance from `HrPosition`** + writes **`position_id`** • **requisition‑approval workflow** (ported from `HrJobPosting`) • posting channels + honest external‑syndication status • salary range + screening questions • cards/table toggle • hiring‑manager workload • right‑click.
- **Interviews (NEW on hub):** **schedule wizard** (panel, date/time/location/type, kit, **calendar invite + emails**) • a **scheduling calendar** using the shared FullCalendar wrapper • **scorecards** + **cross‑interviewer consensus/decision roll‑up** (today scoring is per‑interviewer with no panel summary on the hub).
- **References & safety checks (NEW on hub):** request a reference **and actually email the referee** a questionnaire link • record structured responses • **NZ pre‑employment safety checks** (right‑to‑work, Police vetting, Children's Act safety check, qualification verification) tracked as a gate before offer • these feed onboarding compliance (§J).
- **Offers:** create **wizard** (terms, **seat/`position_id`**, letter **generate or upload**, conditions, **approval**) • **email the candidate the portal link on send** • offer pipeline view (draft / pending approval / sent / accepted / declined / expired) • e‑sign portal state • **convert‑to‑employee**.
- **Convert → employee (the handoff):** premium wizard that creates the **login `User` + `HrEmployeeProfile` + role**, **fills the seat** (`position_id`), sets **work email**, kicks off **onboarding** (template + welcome email + compliance seeding), gated by **`hr.employees.manage`** • idempotent • portal‑accept and in‑app‑accept behave consistently.
- **Candidate documents:** upload/replace/download/delete via modal (no native `confirm()`), category + expiry, transfer to `hr_documents` on hire.
- **Talent pool (NEW):** rejected/withdrawn candidates re‑engageable (today they're terminal and purged) • tags/search • "add to pool" / "re‑activate".
- **Analytics:** keep the real `RecruitmentAnalyticsService` • add **date filters** + **export** + drill‑through • fix the position‑title‑string grouping (group by requisition).
- **Cross‑cutting:** right‑click menus (rows **and** tabs), bulk actions, export (CSV/Excel/PDF), saved views, real empty/skeleton/error states, sonner toast on **every** action, no dead buttons, no native `confirm()/alert()`.

> **Known gaps the audit already surfaced** (confirm, then fix):
> - **Split‑brain job model.** `HrJobRequisition` (live `/hr/recruitment/jobs`) vs `HrJobPosting` (legacy, **richer**: salary range, screening questions, `requires_approval`/`pending_approval`/`approve` workflow, `views_count`). `hr_applications` has **both** `requisition_id` **and** `job_posting_id`; the careers route group (`routes/web.php:97‑103`) **mixes** the new `Careers\CareerPortalController` and the old root `CareerPortalController` — a candidate's apply, job‑detail and status pages can read **different models**. *(Decided: consolidate onto Requisition — §G.)*
> - **Kanban is read‑only.** No drag, cards are `<Link>`s (`kanban.tsx`, `candidate-card.tsx:29`).
> - **Thin modals + exiled flows.** Jobs/Kits dialogs are one‑screen; candidate create + offer are full‑page; **interview scheduling, references, offers, documents live only on `candidates/show.tsx`**, not the hub. Kits has a **dead drag handle** (`kits.tsx:321`).
> - **Offer is never delivered.** `sendOffer` (`CandidateController:780`) mints `candidate_portal_token` + 14‑day expiry and fires a **webhook only** — the candidate is **never emailed** the `careers.offer.show` link. Biggest single workflow break.
> - **Acceptance is inconsistent.** In‑app `respondOffer` auto‑calls `convertToEmployee` (`:964`); the **public portal accept** (`Careers\CareerPortalController::respondToOffer`) only sets `accepted` and does **not** convert — so portal‑accepted hires need a manual click.
> - **`position_id` never written.** The June migration added `hr_job_requisitions.position_id` + `hr_offers.position_id` and `convertToEmployee` passes it through — but **no form writes it** (`RecruitmentJobController::store/update` and `storeOffer` validation omit it). Every hire lands with **`position_id = null`** — it never fills an establishment seat. (See `docs/positions-recruitment-audit.md`.)
> - **Account creation bypasses the staff gate.** `convertToEmployee` creates a login `User` + `HrEmployeeProfile` under **`hr.recruitment.manage`** only — sidestepping `hr.employees.manage`. The same person can draft → approve → send → accept‑on‑behalf → convert. *(Decided: add SoD — §H.)*
> - **Missing comms.** No offer email, no interview invites/.ics, no referee outreach, no rejection notice. The **live** requisition apply path sends **nothing** (no acknowledgement) — only the **legacy** posting path notifies (`JobApplicationReceivedNotification`/`ApplicationConfirmationNotification`).
> - **No requisition approval on the live model.** Draft → publish is a single click on `HrJobRequisition`; the only approval workflow is on the **legacy** `HrJobPosting` (being deleted). Port it.
> - **Fake external syndication.** `syncPosting` (`RecruitmentJobController:227‑262`) fabricates `external_reference` IDs; no real SEEK/Indeed/LinkedIn call; `sync_failed` is unreachable. Either label it honestly ("manual / copy‑link") or build a real integration.
> - **Dead duplicate scoring.** `hr_interview_scorecards` + `HrInterviewScorecard` + `ScorecardController` (richer: strengths/concerns/recommendation) are **unreachable**; the live path uses `hr_interview_scores`. Pick one.
> - **Forked badges.** Module ships its own `@/components/recruitment/status-badge.tsx` (12 raw colours) + further one‑off maps in `jobs.tsx`/`scorecard-summary.tsx` — four systems, none the shared `ui/status-badge`.
> - **Native `confirm()`** on `candidates/show.tsx:328` (reject) + `:397` (delete document) — the module's only native dialogs; replace with `alert-dialog`/review modal.
> - **Stubbed columns:** `hr_offers.template_id`, `hr_offers.work_email_provisioned`, `hr_applications.answers` (superseded by `screening_answers`), `hr_candidate_documents.application_id` (never set), the whole `hr_interview_scorecards` table.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — copy the gradient treatment from `resources/js/components/hr/people-hero.tsx` / `my-hr-hero.tsx`: `HERO_STYLE` (the `linear-gradient` over `--primary` with `color-mix`, `boxShadow`, injected `--hr-amber`), `HeroStat` (uppercase label + big `tabular-nums` value, clickable / `href`), `QuickAction` (icon + label), the on‑gradient **needs‑you chip** + `NeedsDot` amber dot, and the **right‑rail toggle persisted to `localStorage`** (People uses `hrp.heroRight`; use `hrRecruit.heroRight`). **Build `RecruitmentHero`** — ideally on a new shared `hero-kit.tsx` (extract from People/My‑HR), else lift the three primitives. **No `MyHrClockCard`.** Tokens: `--primary`, `--primary-foreground`, `--category-hr`, `--hr-amber`. Generic fallback `@/components/page` (`PageHero`) is **fallback only** — the current page uses it and that's the look we're upgrading away from.

**2.2 Modals / wizards** — build on `@/components/wizard/shell` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`, `type WizardStep`) + `useWizard(stepCount)` (from `@/components/hr/wizard`, which bundles shell + primitives) and `@/components/wizard/primitives` (`Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `TilePicker`, `Ring`, `type IconType`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`). **References: clone `resources/js/components/clients/add-client-dialog.tsx` for the quality bar; mirror `resources/js/components/hr/leave-request-dialog.tsx` for the `WizardShell` plumbing + `railExtra` live‑context card.** For employee/interviewer/manager pickers reuse `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`). Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right‑click menus + hover actions** — reuse `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState` — portal‑rendered, viewport‑flipping, Esc/outside‑click close, icon+label+`sub`+`kbd`+tone) or the HR `@/components/hr/leave-context-menu`. Build a `RecruitmentContextMenu` in the same mould; wire `onContextMenu={(e) => onCtx(e, row)}` on rows, board cards, **and** the tab strip (`TabStrip.onItemContextMenu`).

**2.4 Cards / states / badges / calendar** — **`@/components/ui/status-badge` (`StatusBadge`, `type StatusVariant`)** for every **semantic** state (application active/rejected/hired; offer draft/pending/sent/accepted/declined/expired; interview scheduled/completed; reference requested/received) via `@/lib/status-colors`. The **12 pipeline stages** legitimately need distinct tints — keep **one canonical token‑based `STAGE_COLORS` map** (rename/relocate the existing recruitment map to a single source) and **delete the per‑page one‑off colour maps** in `jobs.tsx` / `scorecard-summary.tsx`. Net: one stage map + the shared `StatusBadge`, not four systems. Also `@/components/ui/{card,avatar,badge}`, `@/components/ui/empty-state` (`EmptyState`), `error-state`, `loading-state`, `skeleton-card`, `@/components/ui/laravel-pagination`. **For interview scheduling reuse the shared `@/components/calendar/calendar-view.tsx`** (the FullCalendar wrapper that powers `/my-calendar`, `/hr/calendar` and Finance) so the scheduling view is visually identical — no new calendar library. `getAvatarColor` is **not** shared yet (it's page‑local at `hr/employees/show.tsx:334`) — extract it to `@/lib` if you need consistent avatar colours.

**2.5 Tokens & flourishes** — tokens only in `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Toasts: sonner** (`<Toaster>` mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action. **Confetti** (`@/lib/confetti` `fireConfetti()`) on a hire/offer‑accepted, `motion-reduce`‑safe. Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## B. Hero rethink — the golden band (NO clock, fitted to recruitment)

**Current:** every recruitment page uses a generic `PageHero category="hr"` gradient (e.g. `index.tsx:174`) with `KpiCard` rows — not the golden My‑HR/People band, inconsistent stat treatment, and `scorecard-summary.tsx` even drops to flat `variant="compact"`.

**Do:** build a **`RecruitmentHero`** (in `resources/js/components/hr/recruitment/recruitment-hero.tsx`) using the **same gradient + `HeroStat` + `QuickAction` language as `people-hero.tsx`**, sized to this page. **No clock.** Compose:

- **Left column:** title **"Recruitment"** (or "Hiring") + one‑line context ("Fill roles fast and bring people on safely at {tenant}"). Small icon medallion (`UserPlus` / `Briefcase`).
- **Glanceable `HeroStat`s** (each click‑filters or deep‑links a tab): **Open requisitions** (→ Requisitions) • **Active candidates** (→ Pipeline) • **Interviews this week** (→ Interviews) • **Offers out / awaiting response** (`--hr-amber` if >0 → Offers) • **Time‑to‑hire** (→ Analytics). Tabular figures.
- **`QuickAction`s:** **Add candidate** (§F‑1) • **New requisition** (§F‑2) • **Schedule interview** (§F‑3) • **Review offers** (→ Offers) • **Export** (gated).
- **Needs‑you chips** (drill‑down popover, like People/My‑HR): "{n} offers awaiting **your** approval", "{n} interviews to score", "{n} candidates stuck > {N}d", "{n} offers expiring soon ⏰", "{n} applications **not acknowledged**". Reuse the chip + `NeedsDot` pattern; collapse to a single "{n} tasks need you" chip when many.
- **Right column (where My HR puts the clock):** since there's **no clock**, fill it with a page‑appropriate cluster — a compact **pipeline funnel mini‑view** (counts per stage) **or** a **time‑to‑hire / source `Ring`**, with a `RailTab` toggle persisted to `localStorage` (`hrRecruit.heroRight`) exactly like `people-hero`. Keeps the band balanced without a clock.

---

## C. Tabs — replace the five pages with a real `RecruitmentTabs` shell

Replace the five standalone pages with a standardised in‑page tab strip (mould of `HrTabs`), `?tab=` deep‑linked via `useHrTab`, per‑tab counts as badges, **right‑click menu on the tab strip** (§E). **Propose the final set to Chane in the §A audit and get sign‑off before building.** Recommended starting set:

1. **Pipeline** (default) — the candidate funnel + list: filter toolbar (stage / source / requisition / site / date / search), **bulk actions**, export, real `EmptyState` + `skeleton-card`. Each row: avatar, name, **stage chip** (canonical `STAGE_COLORS`), source, requisition, applied date, days‑in‑stage (amber when stale), **right‑click menu**, hover actions. (Keep the funnel + source‑distribution + needs‑attention + recent‑activity rails from today's `index.tsx`, restyled.)
2. **Board** — a **genuinely draggable Kanban** (§D). Columns = stages; drag a card to move stage → persists via `applications.advance` with optimistic UI, toast + undo; aging/WIP badges; right‑click card menu. No more read‑only wall.
3. **Requisitions** — req cards/table (keep the view toggle), create/edit **wizard** (§F‑2) with **JD inheritance + `position_id`**, **approval workflow**, posting channels + honest syndication status, salary + screening questions, hiring‑manager workload, right‑click.
4. **Interviews** (NEW on hub) — a **scheduling calendar** (shared FullCalendar wrapper) + a list of upcoming/completed interviews; **Schedule** wizard (§F‑3); **scorecards** with a **cross‑interviewer consensus** roll‑up and a clear hire/no‑hire decision summary (fold the off‑cluster `scorecard.tsx`/`scorecard-summary.tsx` in here).
5. **Offers** (NEW on hub) — offer pipeline by status (draft / pending approval / sent / accepted / declined / expired), email/e‑sign state, **Create offer** wizard (§F‑6), **Convert‑to‑employee** wizard (§F‑9). Bring offers out of `show.tsx`.
6. **Talent pool** (NEW, optional — confirm) — rejected/withdrawn candidates, tags/search, re‑engage.
7. **Analytics** — keep `RecruitmentAnalyticsService`; add date filters + **export** + drill‑through.
8. **Interview kits** — keep the criteria builder, but **move it to a settings/secondary position** (a "⋯ More" affordance or page settings) since it's config, not daily flow; add **real drag‑reorder** for criteria (kill the dead handle). Confirm placement with Chane.

> Candidate detail = a premium **drawer/sheet** (reachable from any tab) replacing the native‑`confirm()` `candidates/show.tsx` — the dossier (timeline, interviews, scores, references, offer, documents) reads in a sheet; all **actions** are wizard modals (§F). Per tab: shared list/card + `StatusBadge`/stage chips; real **empty + skeleton + error** states; every create/edit/action flow is a **modal** (§F); every row has a **right‑click menu** (§E) + hover actions; **toast** every result.

---

## D. The board — make the Kanban real (drag‑and‑drop)

Today the board is decorative — cards are `<Link>`s (`candidate-card.tsx:29`), no drag, no stage mutation. Make it a working board:

- **Drag a candidate card between stage columns** → optimistic move + `POST /hr/recruitment/applications/{application}/advance` (existing route) with the new stage; on failure, revert + error toast. **Undo** toast action.
- Use an accessible DnD approach consistent with any existing board in the app (check the rostering/board components first; reuse rather than add a new DnD lib if one is already in use). Keyboard‑movable (grab/move/drop) for a11y.
- **Aging & WIP signals** per column (avg days in stage already computed; turn the warning/critical colours into real stall flags), count badge, and a **right‑click card menu** (§E): Open dossier · Advance · Reject… · Schedule interview · Create offer · Add to pool · Copy link.
- Empty columns get a friendly `EmptyState`, not bare text.

---

## E. Right‑click everywhere (rows **and** tabs)

Chane explicitly wants right‑click options "under tabs etc." Build a `RecruitmentContextMenu` (mould of `ShiftContextMenu`) and wire `onContextMenu` on:

- **Candidate rows / board cards:** **Open dossier** · **Advance stage** · **Reject…** (review modal, reason required — replaces native `confirm()`) · **Schedule interview…** · **Create offer…** · **Add to talent pool** · **Email candidate** · **Copy careers link** · (manager) **Edit candidate** · gated destructive items with `kbd` hints.
- **Requisition rows/cards:** **Open** · **Edit…** · **Submit for approval / Approve** · **Publish / Close** · **Copy public link** · **Preview careers page** · **Duplicate** · **View applicants**.
- **Offer rows:** **Open** · **Approve…** · **Send (email candidate)** · **Resend link** · **Record response…** · **Convert to employee…** · **Download letter** · **Withdraw**.
- **Interview rows / calendar entries:** **Open** · **Reschedule…** · **Score…** · **View consensus** · **Email panel** · **Add to calendar (.ics)**.
- **The tab strip itself:** right‑click a tab → **Set as default view**, **Open**, **Pin**. Persist default + pins to `localStorage` (`hrRecruit.defaultTab`) and render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§K). No dead items.

---

## F. Modals = full Add‑Client‑grade workflows (not the thin dialogs we have)

**"Each modal must be a full workflow."** Every create/edit/schedule/score/offer/convert/reject flow clones the Add‑Client shell quality bar on `@/components/wizard/shell` (`WizardShell` + `useWizard`), same as the Leave `leave-request-dialog.tsx`: full‑height shell, left **stepper rail** with per‑step icons/blurbs/check, **completeness/validation**, header "Step X of N", top progress bar, scroll‑contained body, footer Back / Cancel / **Save & add another** (where sensible) / primary, client‑side `validateStep`, `stepForError` jump, `WizardSuccessPane`, `forceFormData` for uploads, `railExtra` live‑context card where useful. Replace the thin Jobs/Kits dialogs and the full‑page candidate/offer forms.

1. **Add candidate** — replace full‑page `candidates/create.tsx`. Steps: **Person & consent** (name, contact, **privacy consent** capture) → **Application** (link **requisition** via picker, role/site, source + detail) → **CV & documents** (`forceFormData` upload, category) → **Tags / pool** → **Review** (+ **Save & add another**). Posts `/hr/recruitment/candidates`.
2. **New / Edit requisition** — replace the thin Jobs dialog (`jobs.tsx:315‑713`). Steps: **Role & position** (pick `HrPosition` → **write `position_id`**; title/role/site/employment‑type/openings) → **Job description** (summary/description/responsibilities/requirements, **inherited from the position** and editable) → **Hiring team & interview kit** (hiring manager via `PeoplePicker`, default kit) → **Posting & approval** (channels, salary range + show‑salary, screening questions, **requires‑approval** toggle) → **Review / Publish**. Wire the **approval workflow** (draft → pending_approval → approve → published). Posts `/hr/recruitment/jobs` (+ approval routes, §K).
3. **Schedule interview** — bring off `show.tsx` onto the hub. Steps: **Type & panel** (interview_type, interviewers via `PeoplePicker`) → **When & where** (date/time/duration/location, conflict hints) → **Kit & agenda** (interview kit, focus areas) → **Invites** (email candidate + panel, **generate `.ics`**) → **Review**. Posts `applications/{application}/interviews`.
4. **Interview scorecard** — keep a focused full‑surface (it's long), but premium and **consistent**: per‑criterion rating from the kit, recommendation, strengths/concerns/notes; on submit, show the **cross‑interviewer consensus** (decide between `hr_interview_scores` and the dead `hr_interview_scorecards` — §K). Reachable as a modal/sheet from the Interviews tab and the dossier.
5. **Request reference** — Steps: **Referee** (name/email/phone/relationship) → **Request** (send the referee a **questionnaire link** — real email, §I) → **Review**. A second **Record response** modal captures structured answers. Posts `applications/{application}/references`.
6. **Create offer** — replace full‑page `candidates/create-offer.tsx`. Steps: **Position & seat** (pull from requisition; **write `position_id`**) → **Terms** (employment type, hours/week, hourly rate / annual salary, start date, primary site, **work email**) → **Letter** (**generate from a template** or upload; kills the dead `template_id` stub) → **Conditions & approval** (conditions text, **requires‑approval**) → **Review / Send** (Send **emails the candidate the portal link**, §I). Posts `/hr/recruitment/offers` (+ send/approve).
7. **Reject / decline candidate** — review modal replacing native `confirm()` (`show.tsx:328`): reason (required), **add‑to‑talent‑pool** toggle, **notify‑candidate** toggle (§I). Posts `applications/{application}/reject`.
8. **Document upload / delete** — modal replacing the `show.tsx` document flow + native `confirm()` (`:397`): category, title, expiry, `forceFormData`; delete via `alert-dialog`. Posts `candidates/{candidate}/documents`.
9. **Convert to employee (the headline handoff, §H)** — Steps: **Confirm hire & seat** (candidate + offer summary, **`position_id`** seat assignment, start date) → **Account** (**work email**, role, "create login" — gated **`hr.employees.manage`**) → **Onboarding** (onboarding **template**, **welcome email** template, **compliance/safety‑check seeding** toggle) → **Review**. Calls the convert route; surfaces what `EmployeeIntakeService` will create so it's never a black box. Idempotent. Confetti + toast on success.
10. **Interview kit** create/edit — rebuild the thin Kits dialog as a wizard: **Details** (name, role) → **Criteria** (the weight builder, **with real drag‑reorder** — fix the dead `GripVertical`) → **Guidance** → **Review**. Posts `/hr/recruitment/kits`.
11. **Bulk action** modal — from the Pipeline bulk bar: advance / reject (reason) / email / add‑to‑pool / export for N selected, with a shared note and a clear count. (Needs a bulk endpoint, §K.)

> Wire each modal from the page like Leave does (`open` state + `<XDialog open … onClose … />`), opened from hero `QuickAction`s, tab CTAs and row/context menus. Use `railExtra` for live context (e.g. the candidate's balance‑style summary, the requisition's applicant count, the offer's seat/budget check).

---

## G. Consolidate the split‑brain job model (DECIDED: Requisition is canonical)

Recruitment is built twice; resolve it. **`HrJobRequisition` is canonical.** Port the genuinely better bits of `HrJobPosting` onto it, then retire the legacy half.

- **Port to `HrJobRequisition`:** salary range (`salary_range_min/max`, `show_salary`), `screening_questions` (json) + a structured `screening_answers` capture on `hr_applications` (the existing `answers` column is dead — standardise on `screening_answers`), and the **approval workflow** (`requires_approval`, `pending_approval` status, `approve`/`rejectApproval`, `JobPostingApprovalRequestNotification`). `is_internal`/`is_remote` and `views_count` if wanted.
- **Unify the public careers portal** on `Careers\CareerPortalController` (requisition‑based) for **index / show / apply / status / offer**; retire the root `CareerPortalController` and the `/hr/job-postings` routes + `JobPostingController` (its index already just redirects).
- **Collapse `hr_applications` FKs** to `requisition_id` (migrate any `job_posting_id` rows; drop the dead `answers` column in favour of `screening_answers`).
- **Make the live apply path notify** (it currently sends nothing — §I).
- Retire `HrJobPosting` + `create/enhance_hr_job_postings` once data is migrated; keep `postings:*` commands only if repurposed onto requisitions (today unscheduled and posting‑based).

> All schema moves go in **§K** — write the migration spec and **confirm before building**. This is a data‑migration; sequence carefully (additive columns → backfill → switch reads → drop legacy).

---

## H. The hire handoff — convert → user → staff → onboarding (DECIDED: harden + surface; SoD gate)

This is the "recruitment touches new‑user/staff creation" concern. **`RecruitmentService::convertToEmployee` → `EmployeeIntakeService::intake()`** is the single door (good — it's shared with the manual Add‑Employee modal so they can't diverge). Audit confirms it already, idempotently: creates/links a login `User` (`firstOrCreate` by email, random password, role synced), upserts `HrEmployeeProfile` (`employee_number` stamped), starts onboarding **if a role/site template exists**, and sends a **password‑reset link as the invite**. Harden the gaps and make it **visible on the page** via the §F‑9 wizard:

- **Fill the seat.** Write **`position_id`** on the requisition (§F‑2) and offer (§F‑6) so `convertToEmployee` lands the hire in an establishment seat (today always `null`). Show a **seat/budget check** in the convert wizard (does this position have an open vacancy? — `HrPosition` vacancy accessors per `docs/positions-recruitment-audit.md`).
- **Segregation of duties (decided).** **Creating the login/staff account additionally requires `hr.employees.manage`.** If the converting user holds only `hr.recruitment.manage`, the wizard can prepare the hire but the **account‑creation step is gated** (hand off / request approval). Don't let recruitment silently mint system logins.
- **Branded welcome.** Replace the bare reset link with a proper **welcome email** (use the onboarding email templates `hr_onboarding_emails` / `OnboardingEmailController`, or a new mailable) — credentials/first‑login + what to expect. Surface which email will send in the wizard.
- **Compliance & safety‑check seeding.** On convert, seed the new hire's **compliance/safety‑check** records (right‑to‑work, Police vetting, Children's Act safety check, qualifications) from what was captured in the pipeline (§J) — today compliance seeding only happens if the separate onboarding wizard's box is ticked. Make it a first‑class step.
- **Consistent acceptance.** Make the **public portal accept** behave like the in‑app accept (either both auto‑convert, or both stop at `offer_accepted` and require an explicit Convert) — pick one and document it. No more "portal accept silently doesn't convert".
- **Provision flag.** Either implement `work_email_provisioned` (mark when the work email/login is set up) or remove the dead column.
- **Idempotent + auditable.** Keep the re‑run‑safe upsert; emit the existing `employee.created` / `recruitment.offer.converted` webhooks; write an audit trail entry the dossier can show.

> Don't rebuild the `/hr/onboarding` module — **hand off to it** cleanly (template kickoff + welcome email + compliance seed) and link to the created checklist. Schema/endpoint specifics → §K.

---

## I. ATS communications — close the comms gaps

A care provider can't have offers that never arrive. Add the missing notifications/mailables (specs → §K):

- **Offer email (critical).** On `sendOffer`, **email the candidate** the `careers.offer.show` portal link (+ expiry) — today only a webhook fires. Add `OfferSentNotification`/mailable; "Resend link" action.
- **Application acknowledgement.** The **live** requisition apply path must send the candidate a confirmation and notify the hiring manager (today only the legacy posting path does). Reuse `ApplicationConfirmationNotification` / `JobApplicationReceivedNotification`.
- **Interview invites.** Scheduling emails the **candidate + panel** and attaches a **`.ics`** calendar invite; reminders the day before.
- **Reference outreach.** Requesting a reference **emails the referee** a questionnaire link to submit structured responses.
- **Rejection (gated/optional).** Declining a candidate or offer can **notify the candidate** with a respectful template (toggle in the reject modal §F‑7).
- **Offer response acks.** Accepted/declined sends an acknowledgement; converts notify the hiring manager.

Every send is logged (the dossier timeline shows "Offer emailed 12 Jun") and toasts on the manager side.

---

## J. NZ correctness & care‑sector safety checks

- **Locale:** NZD / `en-NZ` dates & currency throughout (offers show NZD; analytics dates `en-NZ`).
- **Pre‑employment safety checks (supported living).** Capture and track, as a **gate before offer/hire**, the NZ checks a care provider must do: **identity & right‑to‑work/visa**, **NZ Police vetting**, **Children's Act 2014 safety check** (where the role works with children/young people), **referee checks**, and **qualification/registration verification**. Surface these as a checklist on the candidate dossier and the offer/convert wizards; **feed them into onboarding compliance** on hire (§H). Don't let a candidate be converted with mandatory checks outstanding (warn, gate per Chane's call).
- **Privacy.** Keep `privacy_consent_given_at`/`_ip` capture on apply; respect `ArchiveCandidateDataJob` retention; keep candidate PII tenant‑scoped.
- Flag any statute‑sensitive default (which roles require a Children's Act check, vetting validity windows) as config in §K — don't hard‑code policy.

---

## K. Backend handoff for Claude Code (append to this as you design)

> Claude design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code. Gate manager actions on the right permission, respect `ResolvesHrTenant`, and **confirm any schema before building**. Seed list from the audit:

**Bugs / scoping to fix:**
1. **Native `confirm()`** on `candidates/show.tsx:328` (reject) + `:397` (delete document) → review modal / `alert-dialog`.
2. **Forked badges** — collapse `@/components/recruitment/status-badge.tsx` + the `jobs.tsx`/`scorecard-summary.tsx` one‑off maps into one canonical token‑based `STAGE_COLORS` + the shared `ui/status-badge`.
3. **Fake external syndication** — `RecruitmentJobController::syncPosting` fabricates `external_reference`; either build a real SEEK/Indeed/LinkedIn integration or relabel honestly ("copy link / mark posted") and make `sync_failed` reachable or removed.
4. **Dead code** — `hr_interview_scorecards` + `HrInterviewScorecard` + `ScorecardController` are unreachable; either promote them over `hr_interview_scores` (richer) or delete. Decide one scoring table.

**Job‑model consolidation (Decided — §G):**
5. **Port to `HrJobRequisition`:** `salary_range_min/max`, `show_salary`, `screening_questions` (json); add the **approval workflow** (`requires_approval`, `pending_approval` status, `approve`/`rejectApproval`, notification). Additive migration + controller actions + routes.
6. **`hr_applications`:** standardise on `screening_answers` (drop dead `answers`); migrate `job_posting_id` rows to `requisition_id`; then drop `job_posting_id`.
7. **Retire legacy:** unify careers on `Careers\CareerPortalController`; remove root `CareerPortalController`, `/hr/job-postings` + `JobPostingController`, `HrJobPosting` + its migrations **after** data migration. Sequence: additive → backfill → switch reads → drop.

**Seat linkage & hire handoff (Decided — §H):**
8. **Write `position_id`** — add to `RecruitmentJobController::store/update` validation + form, and to `storeOffer` validation + the offer wizard, so `convertToEmployee` lands the seat (column + service plumbing already exist; only the write path is missing).
9. **SoD gate** — convert/account‑creation requires **`hr.employees.manage`** in addition to `hr.recruitment.manage`; gate the account‑creation step in the controller + route.
10. **Welcome email** — branded credentials/first‑login email on convert (mailable or onboarding‑email template), replacing the bare `Password::sendResetLink`.
11. **Compliance/safety‑check seeding on convert** — seed right‑to‑work / Police vetting / Children's Act / qualification records from pipeline capture; link to the onboarding checklist.
12. **Consistent acceptance** — make public‑portal accept and in‑app accept converge (both auto‑convert, or both stop at accepted); document the choice.
13. **`work_email_provisioned`** — implement or remove.

**Missing endpoints / comms to build (spec → confirm → implement):**
14. **Offer email + resend** — `OfferSentNotification`/mailable delivering the portal link; `sendOffer` calls it; "Resend" route.
15. **Live‑path apply notifications** — confirmation to candidate + hiring‑manager alert on the requisition apply path.
16. **Interview invites + `.ics`** — email candidate + panel on schedule; calendar attachment; day‑before reminder (scheduled command).
17. **Reference outreach** — email the referee a questionnaire link; structured response capture (extend `hr_reference_checks` beyond free‑text `reference_notes`).
18. **Rejection notification** — gated candidate‑facing decline template.
19. **Board move** — confirm `applications/{application}/advance` accepts an explicit target stage for drag‑drop (it should; verify payload).
20. **Bulk actions** — endpoint for bulk advance / reject / email / add‑to‑pool / export on selected candidates.
21. **Talent pool** — pool membership (reuse `tags` or a small table), "add to pool" / "re‑activate", a pool query; ensure `ArchiveCandidateDataJob` doesn't purge pooled candidates.
22. **Export** — CSV/Excel/PDF for pipeline, requisitions, offers, analytics (streamed export controller / `xlsx`/`pdf` skills server‑side).
23. **Analytics fix** — group by requisition (not `position_title` string); confirm dialect (currently MySQL `DATE_FORMAT`/`DATEDIFF` — guard for SQLite tests).
24. **Offer letter generation** — template‑merge → PDF (implement `template_id`), alongside upload.
25. **`hr_candidate_documents.application_id`** — populate on upload when in an application context.

> For each item: short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

---

## L. Premium polish & delight

- **Avatars** with real photos (fall back to coloured initials — extract `getAvatarColor` to `@/lib`).
- **Toasts with personality** on every create/advance/schedule/score/offer/convert/export (sonner). Tasteful **confetti** on a hire / offer accepted (`motion-reduce`‑safe).
- **Micro‑interactions** — card move transitions on the board, `animate-in` on new candidates, hover lift on cards, progress `Ring` in the hero — all guarded by `motion-reduce:*`.
- **Keyboard:** `/` focuses search, `c` adds a candidate, `j` new requisition, `i` schedule interview, `Esc` closes menus/dialogs; arrow/Enter on rows; grab/move/drop on the board.
- **Loading/empty/error:** every tab gets a `skeleton-card` while loading and a friendly `EmptyState` (icon + line + primary CTA) — no bare "No candidates." line. Special empty states for an empty pipeline / no open requisitions / cleared offer queue.
- **Consistency sweep:** all semantic chips via `StatusBadge`; one canonical stage map; delete hand‑rolled colour maps; replace native `confirm()/alert()`; no raw hex anywhere; the off‑cluster scorecard pages fold into the Interviews tab.

---

## Definition of done

- `/hr/recruitment` hero is the **golden HR band** (gradient, `HeroStat`s, `QuickAction`s, needs‑you badges, funnel/time‑to‑hire right‑column) — **no clock** — visually on par with `people-hero`; ideally on a shared `hero-kit.tsx`.
- The five standalone pages are gone, replaced by a real **`RecruitmentTabs`** shell (`?tab=`, per‑tab counts) with the Chane‑approved set (recommended: **Pipeline · Board · Requisitions · Interviews · Offers · Analytics**, Kits + Talent pool secondary), and the candidate dossier is a premium **sheet** (no native `confirm()`).
- The **Board** is genuinely **drag‑and‑drop** and persists stage moves; the **exiled flows** (interviews, references, offers, documents) live **on the hub**, not on `show.tsx`.
- Every create/edit/schedule/score/offer/reject/convert flow is an **Add‑Client‑grade wizard** on `WizardShell` (stepper rail + validation + server‑error→step + Save & add another + `SuccessPane`) — **no thin one‑screen dialogs, no full‑page forms**.
- **Right‑click menus** on rows, board cards **and** the tab strip; default‑tab/pin persisted; every item wired + toasted; `kbd` hints shown.
- **One job model:** `HrJobRequisition` canonical with salary/screening/**approval workflow** ported; legacy `HrJobPosting` + duplicate careers controller retired; one careers portal; `hr_applications` on one FK.
- **The hire handoff works and is visible:** convert fills the **seat** (`position_id`), creates login + profile + role, kicks off onboarding + **branded welcome email** + **compliance/safety‑check seeding**, gated by **`hr.employees.manage`**; portal/in‑app acceptance consistent; idempotent + audited.
- **ATS comms fire:** offer email (+ resend), application acknowledgement on the live path, interview invites + `.ics`, reference outreach, optional rejection notice.
- **NZ care‑sector:** safety checks (right‑to‑work, Police vetting, Children's Act, referees, qualifications) captured as a pre‑offer gate and seeded into onboarding; NZD / `en-NZ` throughout; privacy consent retained.
- **End‑to‑end:** pipeline, board, requisitions+approval, interviews+scoring, references, offers+send+convert, documents, talent pool, export all hit real routes; **no dead buttons**, no fake data (or honestly labelled).
- `ResolvesHrTenant` scoping + `hr.recruitment.*` / `hr.employees.manage` gates respected; **no regressions** to `/hr/onboarding`, the careers portal, `EmployeeIntakeService`, or the manual Add‑Employee flow.
- Clean `build`, `types`, `lint`; screenshots of each tab + each modal match the reference pages. **§K backend handoff list is filled in** for Chane → Claude Code.
- **Signals to watch:** time‑to‑hire, offers‑sent‑to‑accepted rate, applications acknowledged %, interviews scored on time, candidates stuck per stage, % hires landing in a seat, safety‑checks complete before hire, dead‑button count (zero).

**Build order:** §A audit + propose tab set (paste results, get sign‑off) → **`hero-kit.tsx` + `RecruitmentHero`** (golden band, no clock) → **`RecruitmentTabs`** shell (retire the five pages, `?tab=`) → **Pipeline** list polish (filters/bulk/export/right‑click) + candidate **sheet** (kill native `confirm()`) → **Board** drag‑and‑drop (§D) → **Requisition** wizard + **approval** + `position_id` (§F‑2/§G) → **Interviews** tab (schedule wizard + `.ics` + consensus, §F‑3/§I) → **Offers** tab (offer wizard + **email on send**, §F‑6/§I) → **Convert‑to‑employee** wizard + **SoD gate** + welcome email + compliance seed (§F‑9/§H) → **References & safety checks** (§F‑5/§J) → **Talent pool / Analytics / Kits** polish → **job‑model consolidation** (§G/§K, confirm schema) → delight pass (§L). Verify each pass against the reference pages, and keep appending discovered backend work to **§K**.
