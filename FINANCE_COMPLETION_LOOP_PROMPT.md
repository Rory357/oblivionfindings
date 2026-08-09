# Finance Module — COMPLETION loop prompt (end-to-end finish + one visual language)

**Purpose:** paste the fenced block below into a **new Claude Code session** (highest reasoning mode).
It resumes the paused finance rebuild loop and runs **audit → plan → build → verify → merge** until the
Finance module is finished **end to end**: every remaining flow completed full-stack, every one of the
~105 finance pages speaking **one visual language** (the current complaint: it looks all over the place),
and the deferred cross-module money work (payroll, funding & client money, budgets, capture-at-source) done.

**How to run**

1. New Claude Code session, highest reasoning mode.
2. Paste everything between the ``` fences.
3. Let it loop. It re-audits first (with a browser — the prior loop was headless and deferred all visual
   work), refreshes `docs/finance-module-rebuild-plan.md` with a **C-series completion backlog**, then
   builds one milestone at a time, passing every gate before each `--no-ff` merge to `main`.
4. If it pauses, reply **"continue with the next milestone."** It must pause and ask before the two
   **canonical-store decisions** (client money, budgets) — those are destructive migrations.

**Prior state (verified 2026-07-07):** the original loop (`docs/finance-module-rebuild-prompt.md`) reached
steady state 2026-06-15. Shipped: the finance design spine (`--category-finance`, `FinanceHero`,
`FinanceTabs`/TabStrip, `money.tsx`, `PostingPreview`, wizard kit), 7 hubs + Settings + Calendar, GL
integrity, AR/AP pipelines, banking, Xero mapping, demo seeders, Edit-via-modal for draft bills+invoices.
**It is NOT done.** Confirmed remainder: no Overview hub (4 overlapping dashboards), ~12 flows still
full-page or missing modals, `donor-funds/Show.tsx` still on raw `ui/tabs`, **72 pages** hand-rolling
`Intl.NumberFormat` instead of `formatMoney`, tab count-badges patchy, no right-click/command layer,
a11y+responsive sweep never run, and milestones M6-1/2(remainder)/3/4, M8-2(store)/3/4, M9-2 open.
**Payroll (M5-2/5, M7-2) has moved since the plan was written** — `PayrollJournalService::postNetPayPayment`
+ `buildNetPayDirectCreditCsv` are wired from `PayrollExportController`, and `IrdFilingService::createPaydayFiling`
is reachable from `IrdFilingController` — so C5 below is a verify-and-close-residuals milestone, not a build.
Treat every prior claim (including this paragraph) as untrusted and re-verify from code.

**Companion docs (read + keep updated):** `docs/finance-module-rebuild-plan.md` (the living plan — extend
it, don't fork it), `docs/finance-module-gap-analysis.md`, `docs/finance-operations.md`,
`docs/DESIGN_TOKENS.md`, `docs/POPUP_STYLE_GUIDE.md`, `docs/hr-nz-statutory-notes.md`,
`docs/nz-localisation-plan.md`, `docs/timesheet-payroll-paid-flow-handoff.md`, and the HR-side seam
claims in the repo-root `*_REDESIGN_PROMPT.md` / `*_BACKEND_HANDOVER.md` files (esp.
`COMPENSATION_HUB_REDESIGN_PROMPT.md` for payroll, `HR_TRAINING_REDESIGN_PROMPT.md` §I for the shared
Claim Expense modal, `LEAVE_REDESIGN_PROMPT.md` for leave liability).

**Reference bar (read FIRST):** `resources/js/pages/operations/rostering/index.tsx` (hero + footer
TabStrip + modals + SignalRail), `resources/js/components/rostering/tab-strip.tsx`,
`resources/js/components/rostering/signal-rail.tsx`, `resources/js/components/rostering/shift-context-menu.tsx`,
`resources/js/components/clients/add-client-dialog.tsx` + `resources/js/components/wizard/{shell,primitives}.tsx`,
and the finance spine itself: `resources/js/components/finance/` (finance-hero, finance-tabs, money,
posting-preview, summary-card, needs-attention-strip, the 7 hub footers, the 7 wizard dialogs).

---

```
GOAL (north star)
FINISH the Finance module end to end. "Finished" means two things, equally weighted:
1. ONE VISUAL LANGUAGE. Every finance surface — all ~105 pages, every hub, tab, table, modal, badge,
   amount and empty state — is indistinguishable in quality and idiom from the Rostering gold standard
   and from every OTHER finance page. Right now it looks all over the place: dashboards that never
   joined a hub, old ui/tabs surfaces, full-page forms next to wizard modals, 72 hand-rolled currency
   formatters, missing count badges, no command layer. Kill every divergence.
2. END-TO-END COMPLETE. Every remaining flow works full-stack with no dead buttons and no stubs; the
   deferred cross-module money work ships: payroll disburses net pay and files payday returns, funding
   & client money live in ONE canonical, segregated, audited home, budgets have ONE engine, and money
   events are captured at the source module and posted through FinancialEventService.

You have a browser this run (Playwright + the dev server). The prior loop deferred all visual work
because it was headless — that excuse is gone. Screenshot-verify every pass.

Treat any prior session's claims, the plan doc's ticks, and this prompt's own remainder list as
UNTRUSTED until re-derived from current code. Use parallel subagents for audit sweeps.

────────────────────────────────────────────────────────────────────────
OPERATING MODE — the loop
────────────────────────────────────────────────────────────────────────
PHASE 0 — RE-AUDIT (no code changes)
  - Read the reference bar + companion docs. git log --oneline -50 and skim what shipped since 2026-06-15.
  - BROWSER SWEEP: log into the dev site as demo admin and open EVERY finance surface (all hubs, every
    tab, every drill-down, every modal). Screenshot each. Grade each against the Rostering bar: hero
    (real stats? actions? footer TabStrip?), tabs (TabStrip? count badges? permission-filtered?),
    workflows (wizard modal or full-page?), tables (states? pagination? row actions?), money format,
    empty/loading/error states, console errors. This produces the CONSISTENCY LEDGER — a per-page grid
    in the plan doc of every divergence. The complaint you are fixing is "it looks all over the place";
    the ledger is your evidence and your burn-down list.
  - CODE SWEEP: verify the confirmed remainder below, plus hunt for anything new: dead buttons,
    swallowed throwables, endpoints without UI, UI without endpoints, orphaned jobs, unseeded
    permissions, non-formatMoney currency, raw hex, ui/tabs imports under pages/finance.
  - SEAM SWEEP: read the repo-root *_REDESIGN_PROMPT.md / *_BACKEND_HANDOVER.md files and
    docs/hr-module-rebuild-plan.md for claims on shared seams (payroll, expenses, approvals inbox,
    shared primitives). List every seam + its owner in the plan doc. Reuse, never fork.
  - UPDATE docs/finance-module-rebuild-plan.md: append a "## Completion phase (C-series)" section —
    the consistency ledger, the seam table, and the C-milestones below finalised with
    Problem → Evidence(file:line) → Fix → Acceptance per item. Then start the loop.

LOOP (repeat until DEFINITION OF DONE)
  1. Pick the next C-milestone from the plan doc.
  2. Build it FULL-STACK (UI + controllers + routes + permissions/seeders + jobs + tests). No stubs:
     build the backend or hide the control. Every page you touch comes up to the FULL consistency
     contract in the same pass — never leave a page half-converted.
  3. Gates (below). Fix red before proceeding. Never merge red.
  4. BROWSER PARITY CHECK: screenshot the touched surfaces + every modal; compare side-by-side with
     Rostering AND with the finance hub you finished previously. Same hero anatomy, same tabs, same
     modal shell, same badges, same money format — or it's not done.
  5. Update the plan doc (tick acceptance, update the ledger) + project memory.
  6. git fetch; skim main + other loops' branches for seam movement; reconcile before merging.
  7. Merge --no-ff to main, push. One milestone = one merge. Continue.

PAUSE-AND-ASK (the only two): C4's canonical client-money store and C6's canonical budget store are
destructive data migrations. Present the evidence + recommendation and WAIT for Chane's call before
migrating. Everything else: keep looping without asking.

────────────────────────────────────────────────────────────────────────
THE CONSISTENCY CONTRACT — every finance page, no exceptions
────────────────────────────────────────────────────────────────────────
This is the heart of the request. A finance page is "on contract" when ALL of these hold:

HERO — PageHero category="finance" (or the FinanceHero preset) with: real-state description, 3-4 REAL
  KPI stats (clickable where they filter), primary verb actions opening modals (no dead actions), and
  the hub's TabsFooter in the footer slot. No calendar/week nav in heroes. Dashboards/drill-downs that
  aren't hub members still use the same hero anatomy.
TABS — TabStrip via FinanceTabs ONLY. Every hub sub-page renders its hub footer so context never
  drops. Count badges on every tab that lists things (wire real counts). Permission-filtered (no 403
  tabs). Kill the ui/tabs import in donor-funds/Show.tsx and any other stragglers found in Phase 0.
MODALS — every create/edit/process flow is a WizardShell wizard cloned from the existing finance
  dialogs (which follow add-client-dialog.tsx): stepper rail, per-step validation, PostingPreview with
  live debits==credits wherever a journal results, review step, success pane, toast. Edit follows the
  M10-6 bills/invoices pattern (draft-only edit, GL-safe). Destructive actions use alert-dialog, never
  native confirm(). Genuine workspaces (bank reconcile, payment-run batch selection) may stay pages —
  by prior design decision — but their confirm/complete steps are modals.
MONEY — formatMoney/formatMoneyCompact/AmountField/MoneyBadge from components/finance/money.tsx
  EVERYWHERE. Migrate all ~72 pages off inline Intl.NumberFormat (mechanical sweep, own milestone).
  Amounts decimal-safe (bcmath server-side); en-NZ / NZD; never cents-as-int, never toFixed maths.
STATES — StatusBadge (@/components/ui/status-badge) for every status — no hand-rolled colour maps.
  EmptyState/EmptySearch for every empty list; skeletons for loading; error-state for failures.
  NeedsAttentionStrip (or SignalRail pattern) on every hub that has a worklist (overdue AR, bills to
  approve, unreconciled lines, failed postings, GST due).
COMMAND LAYER — on every list tab: search, status/date filter chips synced to the URL, sort, export
  (CSV at minimum — add endpoints where missing), pagination. Right-click context menus on rows
  (mould of shift-context-menu / the handover-context-menu hook): Open · Edit · the row's real verbs ·
  Export. Every menu item hits a real route — no dead items.
TOKENS — design tokens only, zero raw hex (charts read CSS variables). tailwindcss-animate micro-
  interactions with motion-reduce guards. Full-width layouts, no centered max-width caps.
LOCALE — en-NZ everywhere: NZD, GST 15%, IRD/PAYE, KiwiSaver, ESCT, payday filing. Dates
  dd MMM yyyy. UTC storage, Pacific/Auckland boundary (fiscal/GST periods and due dates are NZ-local).

────────────────────────────────────────────────────────────────────────
C-SERIES COMPLETION BACKBONE (finalise in the plan doc after Phase 0)
────────────────────────────────────────────────────────────────────────
C1 OVERVIEW HUB — the missing 8th hub. Merge the 4 overlapping dashboards (Dashboard.tsx,
   executive-dashboard, sites-overview; site-dashboard + clients/Financials stay drill-downs) into ONE
   role-aware hub at /finance (tabs: Summary · Executive · By site · Cash position), FinanceHubsBar
   intact, old routes → redirects, sidebar collapsed. De-dup the shared KPI/chart blocks into the
   existing FinanceSummaryCard/OpsStatCard idioms. This kills the most visible "all over the place".

C2 MODAL SWEEP — convert every remaining full-page/missing flow to a WizardShell wizard, retiring old
   Create/Edit pages via redirects. Confirmed list (verify + extend in Phase 0): New/Edit Quote,
   Credit note, Recurring charge, Price book (+items), Bank account, Fixed asset (+ disposal),
   Petty-cash fund (+ top-up/adjust — already posts GL), FX revaluation, Period close (guarded,
   preview impact), Prepare GST return (wizard ending in the return, not a page), Donor fund +
   fund transaction, Funding stream, Payment-run approve/process confirms. Edit-Invoice/Bill modal
   pattern (M10-6) is the template for all edits.

C3 CONSISTENCY SWEEP — the mechanical burn-down of the Phase-0 ledger: formatMoney migration (72
   pages), tab count badges everywhere, StatusBadge/EmptyState/skeleton/error adoption, command layer
   + right-click on every list tab, export endpoints, donor-funds ui/tabs kill, raw-hex kill, axe
   (no criticals) + responsive pass on every hub. Big but mechanical — split into per-hub ticks and
   screenshot-diff each.

C4 FUNDING & CLIENT MONEY HUB (M6 — supported-living core, regulated). Build /finance/funding hub
   (tabs: Funding streams · Funding claims · Client/resident funds · Donor/trust funds · Service
   billing). Migrate the operations UI (operations/funding/**, client-funds/**, routes in
   routes/operations.php ~1045+) into it; old routes redirect. ⚠️ PAUSE-AND-ASK: canonical store —
   legacy ClientFund/ClientFundTransaction (populated, working 1010/2500 trust journals) vs
   ClientLedgerEntry (richer, observer-wired, EMPTY; ClientLedgerService reads it and the netting
   flaw is already fixed — commit f9d1fbf9). Present evidence + a migration/backfill/rollback sketch,
   get Chane's call, THEN unify: one store, client profile finance tabs + family portal + insights all
   read it, the other retired/flagged. Then: Client-Money Transaction modal (deposit/withdrawal/
   purchase/reimbursement → canonical trust path, permission-gated, audited, receipt upload) and
   funder remittance reconciliation (approved vs claimed vs received; match a remittance to claims).
   Client money NEVER nets against operational accounts. Every mutation audited.

C5 PAYROLL — VERIFY THE PIPELINE, CLOSE RESIDUALS (M5-1..5 + M7-2). Much of this shipped after the
   plan doc's last update: postNetPayPayment (DR 2300/CR Bank) + buildNetPayDirectCreditCsv are wired
   from PayrollExportController (disburse + bank CSV + payslips marked paid), createPaydayFiling is
   reachable from IrdFilingController (posted runs → payday artefact), and the finance calendar has a
   PayrollObligationProvider. So: walk the WHOLE pipeline in the browser — lock run → payslips →
   balanced GL journal → disburse net pay → download bank file → create payday filing → submit
   (honest SIM-labelled states only, per M7-3) — and close only what's actually broken or missing.
   Known candidates to verify: M5-1 payslip-in-lock (HR-owned — confirm, don't rebuild); the pay-run
   lifecycle surface (are GL-post status + failures surfaced, or do they die in failed_jobs?); bridge
   hardening — per-org role→GL mapping replacing hardcoded findAccountByCode codes
   (PayrollJournalService), preflight (open period + seeded accounts) with surfaced errors, run-gross
   vs payslip-gross reconcile; ESCT still hardcoded '0.00' in the payday payload (IrdFilingService —
   coordinate the payslip-side fix with HR); cash-flow forecast including unpaid posted runs as
   outflows WITHOUT double-counting. ONE bridge — read the HR seam docs first; statutory numbers come
   from NzPayrollCalculatorService, never re-derived; leave liability follows HR's leave engine
   (Holidays Act 2003 / incoming Employment Leave Bill — see LEAVE_REDESIGN_PROMPT.md).

C6 BUDGETS + APPROVALS (M8-2/3/4). ⚠️ PAUSE-AND-ASK on the store: make Finance SiteBudgetLine +
   variance services the ONE engine; implement + bind the orphaned BudgetSyncInterface; Governance
   Budgets/CeoReports consume through it; retire denormalised actual_amount writes + collapse the
   double hourly sync; unify category vocab. Point BudgetActualsController at the canonical engine.
   SpendApproval: the CORRECT direction (per the M8-3 investigation) — bills/POs link to their
   pre-authorisation via the source morph + optional threshold enforcement (a bill over $X requires a
   linked approval); do NOT create bills on approve. Cash-flow forecast gains the payroll outflow per
   C5's verified approach (unpaid posted runs, no double-counting; FinPaymentRun has no type column —
   add one only if the verified design needs it).

C7 CAPTURE-AT-SOURCE (M9-2). Embedded, permission-gated, audited WizardShell modals in the source
   modules, each posting through existing canonical paths (FinancialEventService / FinBill /
   FinInvoice / HouseLedger) — no new ledgers: Sites damage/repair → AP (+ optional insurance-recovery
   AR); Catering shopping complete → HouseLedger groceries per site; Respite booking confirmed → AR
   invoice vs funder + funding drawdown; Asset/Fleet purchase → FinFixedAsset capitalisation + journal;
   operational spend → FinBill + FinVendor attribution; SiteVendor.fin_vendor_id FK. Stay in-lane:
   these touch other modules' pages — add the capture modal + wiring, don't redesign their surfaces.

C8 FINAL PARITY PASS — the whole module side-by-side vs Rostering in the browser: every hub, every
   tab, every modal, console clean, demo-seeded data everywhere (extend FinanceDemoSeeder for funding/
   client-money/payroll surfaces), route:list clean, the consistency ledger 100% green, plan doc DoD
   re-ticked with evidence links (screenshots), memory updated.

────────────────────────────────────────────────────────────────────────
HOUSE RULES (non-negotiable — carried from the original loop)
────────────────────────────────────────────────────────────────────────
1. Tests: NEVER --parallel. php artisan test tests/Feature/Finance + every suite you touch.
2. UTC storage; Pacific/Auckland at the boundary; ->utc() before persisting tz-aware Carbons.
3. Permissions are SEEDED not migrated — every new key goes in a seeder DatabaseSeeder calls + the
   deploy runbook note in the plan doc.
4. Accounting correctness: double-entry, BALANCED, idempotent (state-machine guards), open-period
   enforced; never invent a chart code — resolve via FinAccount/config('finance') mapping.
5. Design tokens only; hero contract + POPUP_STYLE_GUIDE for modals; full-width layouts.
6. No stub UI: build the backend or hide the control. Every mutation audited + permission-gated.
   Client/resident money is regulated: segregated, logged, never netted.
7. Safe collapse: before deleting/redirecting a route, grep the repo for the name/path/component,
   update callers, leave Route::redirect, run route:list + build. Never delete a backend with callers.
8. Lane discipline: finance/* branches; touch HR/Sites/Governance/Operations files only at the agreed
   seam; if the other half doesn't exist yet, leave a commented integration point + plan-doc note.
9. Rebase on main before every merge; re-run gates after rebase.

────────────────────────────────────────────────────────────────────────
VERIFICATION GATES — all green before every merge
────────────────────────────────────────────────────────────────────────
- npm run types (0 errors) · npm run build (clean) · npm run lint (clean on touched)
- php artisan test tests/Feature/Finance (non-parallel) + touched suites; every new posting path gets
  a balanced+idempotent journal test; migrations get up/down tests where destructive
- php artisan route:list — no dead routes; every redirect resolves
- Playwright visual on touched pages; axe — no criticals on touched pages
- BROWSER SMOKE (required, every pass): dev server as demo admin — click through the milestone's hub
  and EVERY modal; no console errors; real data; money posts and the journal balances; screenshots
  attached to the plan-doc tick. A milestone without screenshots is not done.
- Update plan doc + memory → merge --no-ff → push.

────────────────────────────────────────────────────────────────────────
DEFINITION OF DONE
────────────────────────────────────────────────────────────────────────
The consistency ledger from Phase 0 is 100% green: every finance page on the consistency contract —
one hero anatomy, one tab system (with counts), one wizard-modal idiom for every flow, one money
format via money.tsx, StatusBadge/EmptyState/skeletons everywhere, command layer + right-click on
every list, zero raw hex, zero ui/tabs, axe-clean, responsive. The Overview hub exists and the 4
dashboards are gone. Funding & client money live in ONE canonical, segregated, audited hub serving
the client profile tabs and family portal, with a transaction modal and remittance reconciliation.
Payroll runs end to end: locked run → balanced GL journal → net-pay payment run + bank file → payday
filing artefact — no re-keying, no silent failures. ONE budget engine consumed by Governance through
BudgetSyncInterface; approvals link to spend. Capture-at-source modals post from Sites/Catering/
Respite/Assets/Operations through canonical paths. Demo data populates every surface; all gates
green; every milestone merged --no-ff with screenshot evidence; docs/finance-module-rebuild-plan.md
fully ticked with a completion note; no seam forked with HR.

FIRST ACTION
Start PHASE 0 now: read the reference bar + companion docs + seam docs, run the browser sweep and the
code/seam sweeps (parallel subagents welcome), build the consistency ledger, verify this prompt's
remainder list against code, and append the finalised C-series to docs/finance-module-rebuild-plan.md.
Then begin C1. Only pause for the two canonical-store decisions (C4 client money, C6 budgets) or a
genuinely destructive ambiguity — otherwise keep looping and merging until the Definition of Done.
```

---

### Notes for Chane (not part of the paste)

- **Why a new prompt instead of re-running the old one:** the old loop legitimately hit its headless
  ceiling — everything left either needs a browser (the visual mess you're seeing) or a cross-module
  decision. This prompt makes the browser mandatory, seeds the exact verified remainder, and only
  interrupts you twice (client-money store, budget store — both destructive migrations worth your call).
- **Run order matters:** C1–C3 are the "looks all over the place" fix and are low-risk — you'll see the
  module snap into one language before the deeper money work starts. If you want visible wins fastest,
  let it run C1→C3 uninterrupted.
- **Seams:** payroll/expenses/approvals are shared with the HR-side redesign prompts. The prompt forces
  a seam sweep first and reuse-over-rebuild, so nothing gets built twice.
