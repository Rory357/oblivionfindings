# Finance Module — production-rebuild prompt (Rostering-parity + Xero/MYOB-grade)

**Purpose:** paste the fenced block below into a **new Claude Code session** running the
highest reasoning mode ("ultra"/Opus). It runs an autonomous, looped **audit → plan →
build → verify → merge** until the Finance module reaches Xero/MYOB/QuickBooks-grade feature
completeness *and* Rostering-grade UX, production-ready for a NZ supported-living provider —
one milestone at a time, collapsing today's ~100 finance pages into ~8 hubs of tabs + modals.

**How to run it**

1. New Claude Code session, ultra/Opus.
2. Paste the whole block below (everything between the ``` fences).
3. Let it run. It audits first, writes `docs/finance-module-rebuild-plan.md` +
   `docs/finance-module-gap-analysis.md`, then loops: build one milestone → pass every gate →
   **merge-commit to `main` and push** → continue.
4. If it pauses, reply **"continue with the next milestone."** If a gate fails it self-corrects
   before pushing — it must never push a red gate to the auto-deploying `main`.

> **Heads-up — run this ALONGSIDE the HR loop.** A second Claude Code session is (or will be) running
> `docs/hr-module-rebuild-prompt.md` against the same repo and the same auto-deploying `main`. They
> share one critical seam (payroll → Finance) plus expenses/approvals/shared design primitives. This
> prompt now tells the Finance agent to watch the HR loop's branches + plan doc, reuse rather than
> rebuild shared work, and reconcile any overlap — so the two loops don't duplicate each other. See the
> **PARALLEL WORK** section inside the block.

**Companion docs it will read and keep updated:** `FINANCE_READINESS_AUDIT.md`,
`docs/finance-operations.md`, `design_styles/DESIGN_TOKENS.md`, `design_styles/GOVERNANCE_HERO_GUIDE.md`,
`design_styles/POPUP_STYLE_GUIDE.md`, `docs/hero-unification-v2-plan.md`, `docs/hero-unification-v3-handoff.md`,
`docs/hr-nz-statutory-notes.md`, `docs/nz-localisation-plan.md`,
`docs/NZ_SUPPORTED_LIVING_GAP_ANALYSIS_2026-04-01.md`, and the HR loop's
`docs/hr-module-rebuild-prompt.md` + `docs/hr-module-rebuild-plan.md` (this prompt is the Finance
sibling — read the HR plan to stay coordinated and keep them consistent).

**Reference implementations (the bar to match — read these FIRST):**
`resources/js/pages/operations/rostering/index.tsx` (PageHero + footer TabStrip + per-tab Panes +
Dialog modals + SignalRail — the gold standard), `resources/js/components/rostering/tab-strip.tsx`
(tab look/feel), `resources/js/components/rostering/signal-rail.tsx` ("Needs you" rail),
`resources/js/components/clients/add-client-dialog.tsx` + `resources/js/components/wizard/{shell,primitives}.tsx`
(stepper-modal workflow), `resources/js/components/page/*` (`PageHero`/`PageTabs`/`PageHeroStats` —
note there is **no `finance` category yet**; you will add one), `resources/js/pages/my-calendar.tsx` +
`resources/js/pages/calendar/global.tsx` + `resources/js/lib/calendar/recur.ts` (site calendar
look/feel for the finance calendar).

> **Structural strategy = Aggressive collapse (chosen).** Finance today is ~100 page files across
> ~40 sub-areas and 236 route definitions in `routes/finance.php`. Collapse it to ~8 Rostering-style
> hubs (tabs + modals) and **remove the old page components/controllers**. The one safety rail you
> keep: when you retire a web route, replace its definition with a lightweight **redirect to the
> surviving `hub#tab`** (no controller needed) and update every internal `Link`/`route()` reference
> first — so the code is genuinely deleted but no existing deep-link 404s. Never delete a *working
> backend service/model*; only retire UI pages, dead controllers, and duplicates. Record every
> deletion + redirect in the plan doc.

---

```
GOAL (north star — do not lose sight of this across the loop)
Bring the Finance module to Xero/MYOB/QuickBooks-grade feature completeness AND Rostering-grade UX,
fully production-ready for a NZ supported-living provider. "Done" = the ~100 finance pages are
collapsed into ~8 hubs, each with a Rostering-style hero + standardised tabs + modal workflows;
every half-built feature is finished full-stack end-to-end (no dead buttons, no stubbed UI, no
empty states that should hold real data); the money actually moves and posts — AR, AP, payroll,
funding claims and client money all post balanced, traceable journals to the General Ledger;
payroll runs end-to-end from rostering/timesheets/HR through to a Finance journal + payment run +
IRD payday filing; bank reconciliation, payment matching and EFTPOS actually settle; a Finance
calendar matches the site calendar; finance-capture workflows are embedded into the other modules
that generate finance data; duplicates are removed; and every quality gate is green. You will get
there milestone by milestone, merging each verified milestone to main.

You are running on the highest reasoning mode. Treat any prior session's claims and any existing
audit doc as UNTRUSTED and re-derive from the code. Use parallel subagents for the audit sweeps.

────────────────────────────────────────────────────────────────────────
OPERATING MODE — the loop
────────────────────────────────────────────────────────────────────────
PHASE 0 — AUDIT (no code changes yet)
  - Read the reference implementations and companion docs listed in the prompt header. Re-read
    FINANCE_READINESS_AUDIT.md but VERIFY every claim against current code (it may be stale).
  - Adversarially audit the whole Finance module end to end: every page under
    resources/js/pages/finance/**, its controllers (App\Http\Controllers\Finance\**, per
    routes/finance.php), its services in app/Domain/Finance/Services/** (incl. FinancialEventService —
    the GL posting hub — and ProcessFinancialEventJob), its 60 models in app/Domain/Finance/Models/**
    (Fin*), the 236 routes in routes/finance.php, scheduled jobs, and the integration providers
    (Xero/MYOB) in app/Domain/Finance/**.
  - For each finance area produce: what EXISTS, what's HALF-BUILT (UI with no backend, backend with
    no UI, dead buttons, TODOs, computed-but-never-delivered, empty arrays returned as stubs), what's
    MISSING for Xero/MYOB parity + supported-living needs (see FUNCTIONAL TARGETS), and what DIVERGES
    from the Rostering hero/tabs/modal pattern.
  - Trace the money: for AR (FinInvoice), AP (FinBill), payroll, funding claims and client funds,
    confirm whether an approved/posted event actually creates a balanced GL journal via
    FinancialEventService, or silently no-ops. Flag every pipeline that doesn't settle.
  - INVENTORY CROSS-MODULE FINANCE: finance code does NOT live only under finance/. Sweep every
    other module for finance functionality, list it, and decide its canonical home (see the
    CROSS-MODULE FINANCE section for the known hotspots — funding/client-money/budgets are split
    across Operations + Governance + Finance with DUPLICATE backends). Nothing finance-related may be
    left unaudited just because it sits outside resources/js/pages/finance.
  - Hunt for DUPLICATION (see the De-dup section) and for swallowed fatals
    (catch(\Throwable){return [];} patterns that hide missing imports / type mismatches / unposted
    journals — these have bitten this repo before).
  - SYNC WITH THE HR LOOP (a second Claude Code session is rebuilding HR in parallel — see PARALLEL
    WORK below): read docs/hr-module-rebuild-plan.md + git log/branches (hr/*) to see what HR has
    shipped or claimed, so you don't rebuild the same shared seam (payroll, expenses, approvals).
  - WRITE docs/finance-module-gap-analysis.md (feature-by-feature vs Xero/MYOB/QuickBooks +
    supported-living-specific gaps, current vs target, with file:line evidence) AND
    docs/finance-module-rebuild-plan.md (a prioritized, milestone-grouped fix+collapse plan; every
    item gets Problem → Evidence(file:line) → Fix → Acceptance criteria, and the final hub/tab/route
    map). Then begin the loop.

LOOP (repeat until DEFINITION OF DONE is met)
  1. Pick the next milestone from docs/finance-module-rebuild-plan.md.
  2. Implement it FULL-STACK (frontend + backend + routes + permissions + seeders + scheduled jobs +
     tests). No stubs: if a control needs a backend, build the backend; if a backend genuinely can't
     be finished now, hide the control (house rule) and note it.
  3. Bring every page/area you touch to Rostering parity (hero + tabs + modals — see DESIGN PARITY)
     and fold its sub-pages into the hub's tabs/modals, retiring the old routes via redirects.
  4. Run ALL verification gates (below). Fix anything red. Never proceed with a red gate.
  5. UI/UX parity check: open the touched finance hub and the equivalent Rostering surface side by
     side (browse oblivionfindings.com as demo admin) and confirm hero, tabs, and modal patterns
     match in look, feel, and completeness; confirm the money actually posts where relevant.
  6. Update docs/finance-module-rebuild-plan.md (tick acceptance criteria) and project memory.
  7. CHECK THE HR LOOP before merging: git fetch; skim new commits on main + hr/* branches and
     docs/hr-module-rebuild-plan.md. If HR has touched a shared seam (payroll, expenses, approvals,
     a shared component/token), reconcile so there is ONE implementation — don't create a parallel
     copy. Resolve overlaps now, not later.
  8. Merge-commit the milestone to main and push (cadence below). Then continue to the next.

────────────────────────────────────────────────────────────────────────
PARALLEL WORK — an HR loop is running at the same time (coordinate, don't collide)
────────────────────────────────────────────────────────────────────────
A SECOND Claude Code session is running docs/hr-module-rebuild-prompt.md concurrently, rebuilding the
HR module on its own hr/* branches, merging to the same auto-deploying main. You share a repo and one
critical seam. Assume it is actively changing files while you work.

  - Single source of truth for shared things. These are co-owned and must NOT be duplicated:
      * Payroll → Finance bridge (the headline seam). HR owns pay CALCULATION
        (PayrollExportService / NzPayrollCalculatorService / PayslipService); Finance owns POSTING the
        pay run to the GL (journal) + the payment run + IRD payday filing. Build exactly ONE bridge at
        that boundary. Before you build M5, read what HR has already done and continue it — do not
        create a second posting path.
      * Expenses (hr/expenses/* → AP reimbursement), the unified Approvals inbox, and any shared
        design primitives/tokens (the TabStrip alignment, hero presets, wizard wrappers, status
        badges, people/account pickers). If HR has already created a shared primitive, import it;
        don't fork it.
  - How to check, every milestone (cheap, do it often): `git fetch`, then skim new commits on main and
    on hr/* branches, and re-read docs/hr-module-rebuild-plan.md. Grep for any new component/service
    that overlaps what you're about to build BEFORE you build it.
  - On conflict/overlap: prefer reuse over rebuild. If both loops independently built the same thing,
    keep the better implementation, redirect/delete the other, and record the merge in BOTH plan docs
    (note it under a "Cross-loop coordination" heading in docs/finance-module-rebuild-plan.md).
  - Lane discipline: stay in finance/* branches and finance-owned files. Touch HR-owned files only at
    the agreed seam, in coordination — never silently rewrite HR's domain. If a change you need spans
    both modules and HR hasn't done its half, leave a clearly-commented integration point + a note in
    the plan doc rather than reaching across and duplicating HR logic.
  - Rebase before merge: main moves under you because HR is merging too. `git fetch && rebase` (or
    merge main in) and re-run the gates before every merge so you never push on top of a stale tree.

────────────────────────────────────────────────────────────────────────
GIT / DEPLOY CADENCE  (main auto-deploys to oblivionfindings.com)
────────────────────────────────────────────────────────────────────────
- Work on a short-lived branch per milestone (e.g. finance/<milestone-slug>).
- BEFORE merging: every gate green + UI/UX parity confirmed + plan doc updated.
- THEN merge to main with a real merge commit (--no-ff) and push. One milestone = one merge.
- Commit messages: "Finance <hub>: <what shipped> (milestone N)" + acceptance criteria touched.
- If a milestone is large, land it as a sequence of green sub-commits on the branch, but only the
  fully-verified milestone gets merged to main. A red gate must never reach the auto-deploying main.
- Because consolidation DELETES routes/pages: before deleting, grep the whole repo for references to
  the route name/path/component and update them; leave a redirect for the old web path; run the build
  + a route:list check so nothing references a dead route. Deleting working code that still has live
  callers is a destructive mistake — verify first.

────────────────────────────────────────────────────────────────────────
GROUND TRUTH — architecture primer (verify before relying on it)
────────────────────────────────────────────────────────────────────────
- Stack: Laravel 11 + Inertia.js + React 19 + TypeScript + Tailwind v4 + Radix/shadcn UI. NZ context.
- Finance domain models live in app/Domain/Finance/Models (Fin* — ~60 of them: FinAccount, FinJournal,
  FinInvoice, FinBill, FinBankAccount, FinBankReconciliation, FinFinancialEvent, FinFundingStream,
  FinDonorFund, …); services in app/Domain/Finance/Services (~50, incl. FinancialEventService);
  jobs in app/Domain/Finance/Jobs (incl. ProcessFinancialEventJob); controllers in
  App\Http\Controllers\Finance; pages in resources/js/pages/finance; routes in routes/finance.php
  (name prefix finance., ~236 route defs).
- GL posting hub: FinancialEventService + FinFinancialEvent + ProcessFinancialEventJob is the intended
  single path money flows through to journals. Many callers may not dispatch it — that's the core bug
  class. Treat "an event posts a balanced journal" as the definition of a working pipeline.
- RBAC: User::canDo() (deny-override → allow-override → role permissions). No wildcard/admin bypass.
  EnsurePermission middleware treats permission:a|b as OR. Permissions are SEEDED, not migrated, and
  deploys skip seeders — any new permission key must be added to a seeder DatabaseSeeder calls, and
  the deploy runbook updated.
- Payroll pipeline ALREADY PARTLY EXISTS — build the Finance bridge ON TOP, don't reinvent pay calc.
  Rostering shift → operations Timesheet (resources/js/pages/operations/timesheets/*, incl.
  payroll-adjustments.tsx) → HR PayrollExportService / NzPayrollCalculatorService / PayslipService →
  payslips (resources/js/pages/hr/payroll/*, resources/js/pages/hr/my/payslips.tsx). The MISSING link
  (per the audit's P0-1) is posting the pay run THROUGH to the Finance GL + a payment run. Build that.
- Tenancy/scoping: operations scope by site_id; HR by tenant_id. Match whatever scoping the existing
  Finance models use (verify) and keep finance queries correctly scoped per entity/site/cost-centre.
- Endpoints called by BOTH Inertia and axios must content-negotiate (RespondsToInertiaOrJson trait).
- This is a DEV/demo environment; the strict gates are for CODE QUALITY and to keep the shared dev
  server usable — not client safety. But financial correctness (balanced journals, no double-posting,
  idempotency) IS a quality bar here — get the accounting right.

────────────────────────────────────────────────────────────────────────
HOUSE RULES (non-negotiable)
────────────────────────────────────────────────────────────────────────
1. Tests: NEVER `php artisan test --parallel` (per-worker DBs aren't migrated → thousands of false
   failures). Run scoped: `php artisan test tests/Feature/Finance` plus any suite you touch.
2. Timezones: store UTC; convert at app.worker_timezone (Pacific/Auckland) boundary; ->utc() before
   persisting tz-aware Carbons. Watch Carbon\Carbon vs Illuminate\Support\Carbon hints. Fiscal
   periods, GST periods and due dates are NZ-local — get the boundary right.
3. Permissions seeded not migrated (see above) — wire seeder + deploy runbook for every new key.
4. Money/accounting correctness: store amounts as integer minor units or decimal with explicit scale
   (match existing Fin* columns — verify, don't guess); every posting is double-entry and BALANCED;
   guard every posting path with idempotency (no double-post on retry — see audit P1-5); never invent
   a chart-of-accounts code (resolve via FinAccount/mapping — see audit P0-2).
5. Design tokens only — NEVER hardcode hex; every colour comes from semantic tokens
   (design_styles/DESIGN_TOKENS.md). Add the new finance category token set rather than inlining colours.
6. Hero contract: design_styles/GOVERNANCE_HERO_GUIDE.md. Modal/popup style: design_styles/POPUP_STYLE_GUIDE.md.
7. Full-width layout convention; no centered max-width caps on page bodies.
8. NZ locale & terminology everywhere: en-NZ, NZD, GST, IRD/PAYE, KiwiSaver, ESCT, payday filing,
   Holidays Act 2003. See docs/hr-nz-statutory-notes.md + docs/nz-localisation-plan.md. Treat the
   payroll calculator service as the source of truth for statutory pay.
9. Don't stub UI for missing backends — either build the backend or hide the control.
10. Every mutation is audited (existing AuditableChanges trait / AuditLogger) and permission-gated.
    Client/resident money handling is a regulated, audit-critical area — segregate it, log every
    transaction, and never let it net against operational accounts.
11. Aggressive collapse, done safely: retire UI pages/controllers and duplicates; replace old web
    routes with redirects to hub#tab; update all internal references BEFORE deleting; never delete a
    working backend service/model that still has callers.

────────────────────────────────────────────────────────────────────────
DESIGN PARITY — match Rostering exactly (this is the heart of the request)
────────────────────────────────────────────────────────────────────────
FIRST add a `finance` hero category so finance heroes are themed like every other module:
  - add --category-finance / --category-finance-bg token pair in resources/css (next to ops/hr/etc.,
    ~lines 104-213) and expose --color-category-finance(-bg);
  - add 'finance' to PageHeroCategory in resources/js/components/page/page-hero.tsx.

HERO BANNERS — every finance hub gets a PageHero (category="finance") matching the Rostering hero
(resources/js/pages/operations/rostering/index.tsx ~line 2196). Include the relevant subset of:
  - a status pill + a human, summarising title;
  - a one-line description reflecting real state (outstanding $, overdue count, what needs the user);
  - meta items (icon + label), badges, and 3-4 KPI stats sourced from REAL data (e.g. cash position,
    AR outstanding, AP due this week, unreconciled items, GST due, payroll status);
  - primary actions (the hub's main verbs) and optional icon quickActions;
  - the hub's TabStrip in the hero `footer` slot (exactly how Rostering puts its tabs in the footer).
  NO calendar/week-picker navigation controls inside the hero (explicit requirement) — but DO populate
  each hero with relevant stats, filters, badges and actions so it is never an empty banner. A small
  optional period selector (This month / quarter / FY) is fine where a hub is period-scoped.

TABS — standardise ONE tab component across ALL of Finance, matching Rostering's TabStrip look and
feel (resources/js/components/rostering/tab-strip.tsx): toned chips with icons, count badges, the
active underline-bar, full keyboard nav. Reuse TabStrip (or align the shared PageTabs to the same
visual language and use it everywhere) — tabs must be identical across modules. Every sub-area that is
a separate page today becomes a tab on its hub, old route redirecting to hub#tab.

MODALS / WORKFLOWS — every create/edit/process workflow matches the Add-Client modal UX
(resources/js/components/clients/add-client-dialog.tsx): a full-height stepper modal = left stepper
rail + scroll-contained body + sticky footer, built from the shared wizard kit
(resources/js/components/wizard/primitives.tsx + shell.tsx — Field, FieldErr, Segmented, TilePicker,
ChipMulti, SelectInput, StepHead, SubHead, InfoCard, Ring, etc.). Do the work IN modals like Rostering
does (CreateShiftDialog, ReassignDialog, …) instead of navigating to standalone form pages. Finance
workflows that should be modals: New/Edit Invoice, New Quote, Credit Note, Record Receipt, Allocate
Payment, New/Edit Bill, New Purchase Order, New Vendor, Schedule Payment Run, Approve Payment Run,
Bank Reconcile (workspace + confirm modal), New Journal, New Account, Period Close, FX Revaluation,
New Funding Claim, New Donor/Trust Fund, Client-Money Transaction, Prepare GST Return, File Payday/IRD,
Run Payroll → Post to GL.

SIGNAL RAIL — where a hub has a "needs you" worklist (overdue invoices, bills to approve,
unreconciled transactions, failed postings, GST/IRD due), use the Rostering SignalRail
(resources/js/components/rostering/signal-rail.tsx) pattern so finance "to-dos" look identical to ops.

FINANCE CALENDAR — build a Finance calendar that looks and behaves like the SITE calendar
(resources/js/pages/my-calendar.tsx + resources/js/pages/calendar/global.tsx, FullCalendar-based,
resources/js/lib/calendar/recur.ts for recurrence): same month/agenda views, same styling/tokens,
same interactions (click a day/entry → modal). Surface finance-dated events: invoice & bill due dates,
scheduled payment runs, recurring charges, pay-run dates, GST return periods + due dates, IRD payday
filing dates, fiscal-period close, budget periods, fixed-asset depreciation runs. Reuse the shared
calendar components — do not build a bespoke finance calendar.

EMPTY / LOADING / ERROR STATES, mobile responsiveness, and accessibility (WCAG 2.1 AA; the repo has
@axe-core/playwright) are part of "Rostering-grade" — bring touched pages up to that bar.

────────────────────────────────────────────────────────────────────────
TARGET HUB / TAB MAP — collapse ~40 sub-areas into ~8 hubs (finalise in the plan doc)
────────────────────────────────────────────────────────────────────────
This is the PROPOSED information architecture; validate against the code in PHASE 0 and finalise the
exact tab list + route map in docs/finance-module-rebuild-plan.md before building.

1. OVERVIEW  (finance/)  — merge the 4 dashboards (Dashboard, executive-dashboard, site-dashboard,
   sites-overview) into one role-aware hub. Tabs: Overview · By site · Executive · Cash position.
2. SALES & RECEIVABLES (AR)  (finance/receivables) — Tabs: Invoices · Quotes · Credit notes ·
   Recurring charges · Customer statements · Aged receivables · Price books · Payment allocations.
3. PURCHASES & PAYABLES (AP)  (finance/payables) — Tabs: Bills · Purchase orders · Vendors ·
   Payment runs · Aged payables.
4. BANKING & CASH  (finance/banking) — Tabs: Accounts · Transactions · Feeds · Reconciliation ·
   Match rules · EFTPOS · Petty cash.
5. GENERAL LEDGER  (finance/ledger) — Tabs: Chart of accounts · Journals · Cost centres ·
   Fiscal periods · Currencies · FX revaluations.
6. FUNDING & CLIENT MONEY  (finance/funding) [supported-living core] — Tabs: Funding streams ·
   Funding claims · Donor/trust funds · Client/resident funds · Service billing.
7. TAX & COMPLIANCE  (finance/tax) — Tabs: GST returns · IRD / payday filing · Audit exports ·
   Fixed assets · Consolidation · Intercompany.
8. REPORTS & PLANNING  (finance/reports) — Tabs: P&L · Balance sheet · Trial balance · Cash flow ·
   Aged AR · Aged AP · Budget vs actuals · Funding-stream summary · Cash-flow forecast & scenarios.
Plus: PAYROLL (headline pipeline, spans HR↔Finance — see FUNCTIONAL TARGETS) and
FINANCE SETTINGS & INTEGRATIONS (finance/settings) — Xero/MYOB integrations + account mapping, match
rules config, fiscal calendar, tax/GST config, currencies, finance permissions.

────────────────────────────────────────────────────────────────────────
FUNCTIONAL TARGETS — Xero/MYOB/QuickBooks parity + supported-living needs
────────────────────────────────────────────────────────────────────────
Complete the half-built and add what's missing. The bar:

CORE ACCOUNTING (general finance-app parity):
  - Chart of accounts + journals (manual + recurring) with balanced double-entry posting; period close.
  - AR: invoices (draft→sent→paid), quotes→invoice, credit notes, recurring/subscription billing,
    customer statements, online/EFTPOS payment + receipt, payment allocation/matching, aged AR.
  - AP: bills, purchase orders→bill, vendors, batch payment runs with approval, aged AP.
  - Banking: bank feeds, statement import, reconciliation that actually reconciles, auto-match rules,
    petty cash, EFTPOS settlement.
  - Fixed assets register + depreciation; multi-currency + FX revaluation; multi-entity consolidation
    + intercompany.
  - Reporting: P&L, balance sheet, trial balance, cash flow, aged AR/AP, budget vs actuals; budgets;
    cash-flow forecast + scenarios; export.
  - NZ tax: GST return preparation from the ledger + IRD filing; PAYE/payday filing surfaced from payroll.
  - Audit trail + immutable audit exports; role-based access on every action.

SUPPORTED-LIVING-SPECIFIC (the differentiators — most finance apps DON'T have these; the audit must
treat these as first-class):
  - Funding streams & claims: model government/NASC/disability funding (e.g. Individualised Funding,
    Whaikaha/MoH-style contracts), generate claims from delivered/rostered service, track approved vs
    claimed vs received, reconcile funder remittances. (Verify the real funders/terms in-repo + NZ docs.)
  - Client / resident money management: a strictly segregated per-resident ledger for personal funds,
    with deposits/withdrawals, receipts, balances, spending records and an audit trail — never netted
    against operational accounts; permission-gated and fully logged (regulated area).
  - Donor / trust / grant funds: restricted-fund tracking with restrictions, drawdown, and reporting.
  - Per-site and per-service cost centres so every cost and revenue is attributable to a site/service.
  - Service-level billing tied to care delivery (rostered hours / units of support → invoice/claim).

PAYROLL END-TO-END → FINANCE (headline, explicit ask):
  Build the full pipeline view and make it settle: Rostering shift → approved operations Timesheet →
  HR pay calc (PayrollExportService / NzPayrollCalculatorService / PayslipService) → pay run lifecycle
  (draft → review → approve → export → POSTED) → POST the payroll journal to Finance journals
  (resources/js/pages/finance/journals/*) AND/OR create a Finance payment run
  (resources/js/pages/finance/payment-runs/*) via the integration mapping
  (resources/js/pages/finance/Integrations/Mapping.tsx) with correct GL account + cost-centre mapping,
  → surface IRD/PAYE payday filing (resources/js/pages/finance/IrdFilings/*). Acceptance: an approved
  pay run produces a balanced, traceable journal in Finance + a payment run + a payslip per employee,
  with PAYE/KiwiSaver/ESCT/leave correctly reflected — no manual re-keying. Coordinate with (do not
  duplicate) the HR side described in docs/hr-module-rebuild-prompt.md.

AUTOMATION (automate as much of finance as possible — explicit ask). Register the scheduled jobs the
audit found unregistered (P0-6) and wire these end-to-end:
  - Recurring invoices/charges for funded residents auto-generate on schedule.
  - Bank feeds pull + auto-match rules auto-reconcile the obvious lines; surface only exceptions.
  - AR aging reminders + bill due-date notifications actually send (audit P1-3/P1-4).
  - Payroll journal auto-posts to GL on approval (audit P0-1).
  - Funding claims auto-generate from delivered service; funder remittances auto-match.
  - GST return auto-prepares from the ledger each period; payday filing batches assemble automatically.
  - Budget-vs-actual variance alerts get delivered (computed-but-undelivered today — P1-3).
  - Cash-flow forecast auto-refreshes from AR/AP/payroll due dates.

────────────────────────────────────────────────────────────────────────
CROSS-MODULE FINANCE — audit existing, reconcile duplicate backends, capture, implement gaps
────────────────────────────────────────────────────────────────────────
CRITICAL: a large amount of finance functionality ALREADY lives outside resources/js/pages/finance —
in Sites, Client profiles, Operations, Governance, HR and Fleet — and some of it is a DUPLICATE
backend of what's in the Finance domain. You must (a) audit all of it, (b) decide ONE canonical home,
(c) reconcile/migrate duplicate backends to a single source of truth, (d) implement any gaps, and
(e) embed capture workflows at the source. Do NOT leave finance code unaudited just because it sits in
another module, and do NOT build a third copy of something that already exists twice.

KNOWN DUPLICATE / SPLIT BACKENDS (verify, then unify — this is the headline cross-module problem):
  - FUNDING & CLIENT MONEY exists TWICE:
      * Legacy app/Models: ClientFund, ClientFundTransaction, FundingClaim, FundingClaimItem +
        services ClientFundJournalService, FundingClaimJournalService, FundingService +
        FundingClaimObserver / ClientFundTransactionObserver + PostFundingClaimJournalJob, surfaced in
        resources/js/pages/operations/funding/** (claims) and operations/client-funds/** and
        operations/clients/tabs/finance.tsx, routed in routes/operations.php.
      * Finance domain: app/Domain/Finance/Models/FinFundingStream, FinDonorFund(+Report,+Transaction),
        FinPettyCashFund, surfaced in resources/js/pages/finance/funding-streams/**,
        finance/donor-funds/**.
    → Decide the canonical home (the Finance "Funding & Client Money" hub, M6), migrate the legacy
      operations models/UI onto the Finance domain + FinancialEventService posting path, redirect the
      old operations routes to finance/funding#tab, and delete the duplicate. Preserve the working
      journal-posting behaviour (ClientFundJournalService / FundingClaimJournalService) by folding it
      into FinancialEventService, not by dropping it.
  - BUDGETS exist in BOTH Finance and Governance:
      * Finance: app/Domain/Finance/Models/SiteBudgetLine + BudgetActualsService / BudgetVarianceService
        / BudgetVarianceAlertService + BudgetVarianceAlertNotification + SyncBudgetActualsJob +
        BudgetActualsController/BudgetForecastApiController.
      * Governance: app/Domain/Governance/Http/Controllers/BudgetController + BudgetSyncInterface, UI in
        resources/js/pages/Governance/Budgets/**, plus Governance SpendApprovals + CeoReports.
    → Keep ONE budget engine (the Finance one) as source of truth; let Governance consume it via the
      existing BudgetSyncInterface rather than holding a parallel copy. Confirm whether the variance
      ALERT actually delivers (BudgetVarianceAlertNotification exists — audit P1-3 may already be
      partly done); wire it end-to-end if not. Don't duplicate budget logic into the Finance reports
      hub — reference the shared engine.

OTHER CROSS-MODULE FINANCE TO AUDIT + COVER + GAP-FILL:
  - CLIENT / RESIDENT PROFILES (resources/js/pages/clients/* incl. Financials.tsx;
    resources/js/pages/operations/clients/* incl. tabs/finance.tsx; components/clients/profile/*;
    operations/family-portal/Index.tsx): the resident's funding package, service agreement/price,
    recurring charge/claim, and a strictly-segregated personal-money ledger with a "record
    client-money transaction" modal. Make the client finance tab consistent with the Finance hub and
    backed by the canonical funding/client-money domain (not the legacy duplicate). The family portal
    must read the same canonical balances/invoices.
  - SITES (resources/js/pages/sites/* incl. ledger/index.tsx, _ledger-panel.tsx, damages/, vendors/,
    meal-planner/, calendar/): there is a per-site ledger and site-level costs (damages, vendors,
    catering shopping). Audit the site ledger against the Finance GL/cost-centre model; ensure site
    costs/revenue post to the canonical GL per site cost-centre; reconcile any bespoke site-ledger
    store with Finance rather than keeping a shadow ledger.
  - GOVERNANCE (resources/js/pages/Governance/* — Budgets, SpendApprovals, CeoReports, Cockpit,
    Resolutions, Strategy): spend approvals and CEO financial reports must read from the canonical
    Finance data; spend approvals should tie into AP / payment-run approval rather than a parallel flow.
  - ROSTERING / TIMESHEETS (operations/*): approved timesheets → payroll labour cost to GL per
    site/cost-centre; rostered/delivered funded hours → funding claim + service billing.
  - HR (hr/*): new hire → payroll setup (tax code, KiwiSaver rate, bank account, IRD number);
    hr/expenses/* → AP reimbursement; leave → leave-liability accrual; comp change → payroll.
    (Coordinate with the HR loop — this is the shared seam.)
  - PROCUREMENT / ASSETS / FLEET (assets/*, fleet-assets/* incl. reports/reimbursement.tsx +
    maintenance/work-orders/show.tsx, fleet-management/*): purchase → PO → bill → AP; capitalisable
    purchases → fixed asset; fuel/maintenance/reimbursement → AP + cost centre per vehicle/site.
  - CATERING / MEAL PLANNER (catering/*, sites/meal-planner/*): food purchases → bills/petty cash
    per site.
  - RESPITE (respite/*; note RespiteFundingSource model): bookings → billing/AR + respite funding.
  - INCIDENTS / HEALTH & SAFETY / MEDS / eMAR (emar/Medications.tsx): insurable losses / pharmacy
    invoices / chargeable items → AP or AR as appropriate.
For EACH of the above: in PHASE 0 record what exists, what's a duplicate, and what's a gap; then in
the relevant milestone implement the gap, reconcile to the canonical backend, post through
FinancialEventService, and make any capture a modal matching the Add-Client pattern (permission-gated
+ audited). List every cross-module touchpoint and every backend reconciliation in the plan doc.

────────────────────────────────────────────────────────────────────────
DE-DUPLICATION
────────────────────────────────────────────────────────────────────────
Find and unify duplicates created during the long build. Three classes of duplication exist here:
  (a) WITHIN finance: the 4 overlapping dashboards (Dashboard / executive-dashboard / site-dashboard /
      sites-overview); legacy App\Models\Invoice vs FinInvoice (audit P1-1 — migrate then retire the
      legacy model's UI/usage); overlapping payment concepts (payment-allocations vs payment-matching
      vs match-rules); any parallel finance dashboard services (audit P2-1); and near-identical
      hero/tab/table/card/list code across the CRUD page sets.
  (b) CROSS-MODULE duplicate BACKENDS (see the CROSS-MODULE section): funding/client-money in legacy
      app/Models (ClientFund/FundingClaim, operations UI) vs the Finance domain (FinFundingStream/
      FinDonorFund); budgets in Finance (SiteBudgetLine + variance services) vs Governance
      (BudgetController). Unify each to ONE canonical backend, migrate, redirect, delete the copy.
  (c) CROSS-LOOP duplicates with HR (see PARALLEL WORK): anything the HR loop also builds at the shared
      seam — payroll posting, expenses→AP, the approvals inbox, shared tokens/primitives. Reuse HR's,
      don't fork.
Extract shared finance primitives (finance hero presets, a finance data table, money/status badges,
an account picker, a vendor/customer picker, the amount-entry field, the posting-preview card) into
reusable components. Removing a duplicate must keep its route alive via a redirect to the surviving
hub#tab. Record every merge + deletion in the plan doc.

────────────────────────────────────────────────────────────────────────
SUGGESTED MILESTONE BACKBONE (finalise in the plan doc after the audit)
────────────────────────────────────────────────────────────────────────
M0  Foundations: add the `finance` hero category (tokens + PageHeroCategory); standardise the finance
    hero + tab (align to Rostering TabStrip) + modal primitives (wizard wrappers); build shared finance
    primitives (data table, pickers, money field, posting-preview). Land the design spine first.
M1  Ledger hub (chart of accounts, journals, cost centres, fiscal periods, currencies, FX) + FIX
    GL posting core: FinancialEventService balanced double-entry, account-code resolution (audit P0-2),
    idempotency (P1-5). Everything downstream posts through here, so it goes early.
M2  Sales & Receivables hub + AR GL posting (audit P0-3) + receipts/allocation/matching + aged AR.
M3  Purchases & Payables hub + payment runs/approval + AP posting + aged AP.
M4  Banking & Cash hub: reconciliation/payment-matching/EFTPOS that actually settle (audit P0-5);
    bank feeds (P1-6); auto-match rules.
M5  Payroll end-to-end → Finance bridge (journal + payment run + GL/cost-centre mapping + IRD payday
    filing), built on the existing HR payroll services. [headline]
M6  Funding & Client Money hub [supported-living core]: funding streams/claims + client/resident money
    ledger + donor/trust funds; dispatch the funding-claim & client-fund journals (audit P0-4). RECONCILE
    THE DUPLICATE BACKEND — migrate legacy app/Models (ClientFund/FundingClaim, operations/funding/** +
    operations/client-funds/** UI) onto the Finance domain + FinancialEventService, redirect the old
    operations routes, fold ClientFundJournalService/FundingClaimJournalService into the canonical path,
    delete the duplicate. Wire the client-profile finance tab + family portal to the canonical balances.
M7  Tax & Compliance hub: GST returns from ledger + IRD e-filing flow (audit P2-5) + audit exports
    (retention/encryption, P1-7) + fixed assets + consolidation/intercompany (P2-3).
M8  Reports & Planning hub: P&L/BS/TB/cash flow/aged/budget-vs-actual + cash-flow forecast/scenarios.
    UNIFY BUDGETS — one budget engine (Finance SiteBudgetLine/variance services) as source of truth;
    Governance Budgets/SpendApprovals/CeoReports consume it via BudgetSyncInterface (no parallel copy);
    deliver variance alerts (P1-3).
M9  Cross-module finance coverage + calendar: Finance calendar (site-calendar parity); register all
    scheduled jobs (P0-6) + notifications (P1-4); audit + reconcile + gap-fill every cross-module
    finance touchpoint (Sites ledger, client/resident profiles + family portal, Governance spend/
    reports, Fleet/Assets costs, Catering, Respite, Incidents/Meds) and embed the capture modals.
M10 Settings & Integrations (Xero/MYOB beyond stubs, P1-2; mapping; config-drift detection P1-8);
    final de-dup sweep (all three classes — within-finance, cross-module backends, cross-loop with HR);
    demo seeders so every hub renders populated; a11y + responsive polish; end-to-end finance pipeline
    tests (P0-7); final Rostering-parity pass + cross-loop reconciliation check with HR.
(Order so the design spine M0 and the GL core M1 land first — nothing posts correctly until the GL
core is right — and Payroll→Finance M5 isn't blocked by later work.)

────────────────────────────────────────────────────────────────────────
VERIFICATION GATES — run every one before merging a milestone to main
────────────────────────────────────────────────────────────────────────
- Types:  npm run types        → 0 TypeScript errors.
- Build:  npm run build        → clean.
- Lint:   npm run lint         → clean (on touched files at minimum).
- Tests:  php artisan test tests/Feature/Finance  (NON-parallel) + every suite you touched; ADD tests
          for new behaviour and especially for POSTING pipelines (AR/AP/payroll/funding/client-money
          each produce a balanced, idempotent journal), reconciliation settlement, and cross-module
          capture. End-to-end finance pipeline tests are a milestone deliverable (audit P0-7).
- Route check: php artisan route:list — no references to deleted routes; redirects resolve.
- Visual: playwright visual tests (playwright.config.ts) for touched finance pages; update snapshots
          only when the change is intended.
- a11y:   axe pass (no critical violations) on touched finance pages.
- Browser smoke on oblivionfindings.com (auto-deployed dev server) logged in as demo admin: click
          through the milestone's hub and EVERY modal; confirm no console errors, no dead buttons, real
          data renders, money posts where expected, and the hero/tabs/modals/calendar visually match
          the Rostering/site-calendar equivalents.
  (Local Herd alt: oblivionfindings.test needs Herd Desktop on PHP 8.4; delete public/hot if pages
   render blank.)
- Then update docs/finance-module-rebuild-plan.md + memory, merge --no-ff to main, push, continue.

────────────────────────────────────────────────────────────────────────
DEFINITION OF DONE
────────────────────────────────────────────────────────────────────────
Every milestone in docs/finance-module-rebuild-plan.md is checked off; Finance is collapsed into ~8
hubs, each with a Rostering-grade hero (finance category) + standardised tabs (TabStrip parity) +
Add-Client-grade modal workflows; no dead buttons or stubbed UI anywhere in Finance; AR, AP, payroll,
funding claims and client money each post balanced, idempotent, traceable journals to the GL; payroll
runs end-to-end from rostering/timesheets/HR into a Finance journal + payment run + payslips + IRD
payday filing with no re-keying; bank reconciliation/payment-matching/EFTPOS settle; the Finance
calendar matches the site calendar; ALL cross-module finance (Sites ledger, client/resident profiles
+ family portal, Governance budgets/spend/reports, Operations funding/client-funds, Fleet/Assets,
Catering, Respite, Meds) has been audited, reconciled to a single canonical backend, gap-filled, and
its capture workflows embedded; the scheduled automations run; duplicates are removed across all three
classes (within-finance, cross-module backends, cross-loop with HR) with routes preserved via
redirects; no overlap was created with the parallel HR loop; demo data populates every hub; all gates
green; and the whole module has been merged to main milestone by milestone with no red gate ever pushed.

FIRST ACTION
Start PHASE 0 now: read the reference files + companion docs (incl. docs/hr-module-rebuild-plan.md so
you know what the parallel HR loop is doing), run the audit (parallel subagents welcome), VERIFY the
existing FINANCE_READINESS_AUDIT.md against current code, INVENTORY all cross-module finance (Sites,
client/resident profiles, Operations funding/client-funds, Governance, Fleet, HR) and the duplicate
backends, and write docs/finance-module-gap-analysis.md + docs/finance-module-rebuild-plan.md with the
finalised hub/tab/route map, the cross-module reconciliation plan, the cross-loop coordination notes,
the milestone list, and per-item Problem→Evidence→Fix→Acceptance. Then begin M0. Do not ask me to
confirm between milestones unless you hit a genuine ambiguity or a destructive/irreversible decision —
otherwise keep looping and merging until the Definition of Done is met.
```

---

### Notes for you (not part of the paste)

- **Why audit-first-then-loop:** it matches how this repo already works (`FINANCE_READINESS_AUDIT.md`,
  `docs/hr-module-rebuild-prompt.md`, `docs/my-day-fresh-audit-prompt.md`) and forces the agent to
  re-derive truth from code instead of trusting a possibly-stale prior audit.
- **The two riskiest things you chose:** *aggressive deletion* on an auto-deploying `main`, and
  *auto-merge per milestone*. The prompt mitigates both — redirects keep deep-links alive, the
  "verify references before delete + route:list" gate stops dead-route merges, and a red gate can
  never reach `main`. If you want extra safety on the first run, change the last line to "pause after
  M0, M1 and M5 for my review" — those are the highest-blast-radius milestones (design spine, GL core,
  payroll→GL).
- **GL core (M1) is the real unlock.** The audit says AR, payroll, funding and client money don't
  actually post to the ledger today. Sequencing the GL core early means every later hub posts through
  one correct path instead of each reinventing posting.
- **If a milestone balloons,** tell the agent to split it (e.g. "land AR list+modals first, AR posting
  second") — one verified milestone per merge is the only hard rule.
- **Coordinate with the HR prompt:** payroll→Finance is the seam. Run this Finance prompt's M5 with
  `docs/hr-module-rebuild-prompt.md` open so the bridge isn't built twice.
