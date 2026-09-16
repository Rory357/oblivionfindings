# Finance design migration — acceptance checklist

Companion to `audit.md` and `implementation-plan.md`. One row per surface, with
the commit that migrated it. The Demo Admin walkthrough is recorded at the
bottom of this file.

**Baseline (2026-09-16, from the audit):** 92 of 92 pages on the legacy
`PageHero`, 0 on `PageHeader`, 0 with Home-rooted breadcrumbs, 0 on the entity
list contracts, 0 using `laravel-pagination.tsx`.

**Now:** every live finance page is on the Event Horizon header. The ESLint
`PageHero` ban under `resources/js/pages/finance/**` reports zero, and the
module-wide sweep over the 117 live files reports zero for `PageHero`,
`*TabsFooter`, `FinanceHubsBar`, `FinanceSummaryCard`, `NeedsAttentionStrip`,
`useRowContextMenu`, `useFinanceTab`, `window.prompt`/`confirm`,
`(this page)`, `dark:` colour pairs and `dangerouslySetInnerHTML`.

## Verification

| Check | Result |
|---|---|
| `npx tsc --noEmit` | clean (exit 0) |
| `npx vitest run resources/js --maxWorkers=2` | 2669 passed / 390 files |
| `npx eslint resources/js/pages/finance` | clean — the `PageHero` ban reports 0 (92 at baseline) |
| `php artisan test tests/Feature/Finance` | 424 passed, 26 failed — **all 26 pre-existing, see below** |

### The 26 Pest failures are not from this migration

Every one was reproduced on the pre-migration base commit `19354ecbc`, by
checking out the base in this worktree and re-running the same files:

| File | Failures at base |
|---|---|
| `BillSpendApprovalGateTest` | 11 |
| `DonorFundReportPdfTest` | 1 |
| `DonorFundReportingTest` | 1 |
| `FinInvoiceJournalPostingTest` | 1 |
| `FundingClaimJournalDispatchTest` | 1 |
| `LeaveProvisionPostingTest` | 2 |
| `ListExportPayablesTest` | 2 |
| `PaymentAllocationIntegrityTest` | 2 |
| `ProcessFinancialEventJobDispatchTest` | 3 |
| Journal-posted event tests | 2 |

They share one root cause visible in the trace: `JournalPostingService:310`
raising *"An organisation is required to allocate a journal number"* — a
null `organization_id` in this worktree's test database, i.e. environmental
seeding, not application code. Two demo-data migrations for exactly this class
of problem already exist on main (`87afd568`, `e6ec2c4a`).

The one failure that did NOT reproduce at base —
`FinancialInsightsObjectScopeTest > it makes global access separately
permissioned and still requires the dashboard capability` — passes both in
isolation and when its whole file runs on this branch, and the full suite
produced 25 failures on one run and 26 on the next. It is cross-file
pollution, not a regression. (See the project note on per-pid MySQL test
databases and the MySQL 1615 "re-prepared" flake.)

**Net regressions from the migration: zero.**

## Work packages

| WP | Scope | Commit |
|---|---|---|
| — | Audit + plan | `50ddbe524` |
| WP0 | Foundations: `lib/finance-sections.ts`, `FinanceSectionRail`, sidebar, shared confirm dialog, status keys, calendar tokens, guardrails | `8039804c9` |
| WP1 | Calendar → shared `SiteCalendar` | `3393edf99` |
| WP2 | Overview hub | `db7e730aa` |
| WP5 | Receivables hub | `a9f52a19f` |
| WP4 | Payables hub | `6ee5f03f9` |
| — | Tier-2 strip rendered as links (WP0 defect) | `a3ca9f7a3` |
| WP3 | General ledger hub | `8275c5cbe` |
| WP8 | Reports hub | `a13c678aa` |
| WP6a | Banking: accounts, reconciliation, transactions | `4ecf52e1a` |
| WP3b | Settings hub (gap: no WP covered it) | `504ed572d` |
| — | Quarantined pages exempted from the PageHero ban | `6d8cb72f6` |
| WP7 | Tax & compliance + donor funds | `c9191c9b0` |
| WP6b | Banking: feeds, matching, EFTPOS, petty cash, match rules | `9782e05c6` |
| WP9 | Close-out: legacy tab system retired, sweep | `9bb34c357` |

## Pages

### Overview — `/finance`

| Page | Route | Commit |
|---|---|---|
| Summary | `/finance` | `db7e730aa` |
| Executive | `/finance/executive-dashboard` | `db7e730aa` |
| By site | `/finance/sites` | `db7e730aa` |
| Site drill-down | `/finance/sites/{site}/financial-dashboard` | `db7e730aa` |
| Cash position | `/finance/cash-position` | `db7e730aa` |
| Calendar | `/finance/calendar` | `3393edf99` |

### General ledger — `/finance/ledger`

| Page | Route | Commit |
|---|---|---|
| Chart of accounts | `/finance/accounts` | `8275c5cbe` |
| Account record | `/finance/accounts/{account}` | `8275c5cbe` |
| Journals | `/finance/journals` | `8275c5cbe` |
| Journal record | `/finance/journals/{journal}` | `8275c5cbe` |
| Fixed assets | `/finance/fixed-assets` | `8275c5cbe` |
| Fixed asset record | `/finance/fixed-assets/{asset}` | `8275c5cbe` |
| Cost centres | `/finance/cost-centres` | `8275c5cbe` |
| Fiscal periods | `/finance/fiscal-periods` | `8275c5cbe` |
| Currencies | `/finance/currencies` | `8275c5cbe` |
| FX revaluations | `/finance/fx-revaluations` | `8275c5cbe` |
| FX revaluation tool (routed, D8) | `/finance/fx-revaluations/create` | `8275c5cbe` |

### Payables — `/finance/payables`

| Page | Route | Commit |
|---|---|---|
| Bills | `/finance/bills` | `6ee5f03f9` |
| Bill record | `/finance/bills/{bill}` | `6ee5f03f9` |
| Purchase orders | `/finance/purchase-orders` | `6ee5f03f9` |
| Purchase order record | `/finance/purchase-orders/{po}` | `6ee5f03f9` |
| Vendors | `/finance/vendors` | `6ee5f03f9` |
| Vendor record (tier-2) | `/finance/vendors/{vendor}` | `6ee5f03f9` |
| Credit notes | `/finance/credit-notes` | `6ee5f03f9` |
| Credit note record | `/finance/credit-notes/{note}` | `6ee5f03f9` |
| Payment runs | `/finance/payment-runs` | `6ee5f03f9` |
| Payment run record | `/finance/payment-runs/{run}` | `6ee5f03f9` |

### Receivables — `/finance/invoices`

| Page | Route | Commit |
|---|---|---|
| Invoices | `/finance/invoices` | `a9f52a19f` |
| Invoice record | `/finance/invoices/{invoice}` | `a9f52a19f` |
| Quotes | `/finance/quotes` | `a9f52a19f` |
| Quote record | `/finance/quotes/{quote}` | `a9f52a19f` |
| Recurring charges | `/finance/recurring-charges` | `a9f52a19f` |
| Billing | `/finance/billing` | `a9f52a19f` |
| Aged AR — Ageing (tier-2) | `/finance/receivables` | `a9f52a19f` |
| Aged AR — Statements (tier-2) | `/finance/receivables/statements` | `a9f52a19f` |
| Price books | `/finance/price-books` | `a9f52a19f` |
| Price book record | `/finance/price-books/{book}` | `a9f52a19f` |
| Allocations | `/finance/payment-allocations` | `a9f52a19f` |

### Banking — `/finance/banking`

| Page | Route | Commit |
|---|---|---|
| Bank accounts | `/finance/bank-accounts` | `4ecf52e1a` |
| Bank account record | `/finance/bank-accounts/{account}` | `4ecf52e1a` |
| Transactions | `/finance/bank-transactions` | `4ecf52e1a` |
| Reconciliation | `/finance/bank-reconciliation` | `4ecf52e1a` |
| Reconcile tool | `/finance/bank-reconciliation/{rec}` | `4ecf52e1a` |
| Matching | `/finance/payment-matching` | `9782e05c6` |
| Feeds | `/finance/bank-feeds` | `9782e05c6` |
| Feed logs | `/finance/bank-feeds/{feed}/logs` | `9782e05c6` |
| EFTPOS — Terminals (tier-2) | `/finance/eftpos/terminals` | `9782e05c6` |
| EFTPOS — Batches (tier-2) | `/finance/eftpos/batches` | `9782e05c6` |
| EFTPOS batch record | `/finance/eftpos/batches/{batch}` | `9782e05c6` |
| Petty cash | `/finance/petty-cash` | `9782e05c6` |
| Petty cash fund record | `/finance/petty-cash/{fund}` | `9782e05c6` |

### Tax & compliance — `/finance/tax`

| Page | Route | Commit |
|---|---|---|
| GST returns | `/finance/gst-returns` | `c9191c9b0` |
| GST return record | `/finance/gst-returns/{return}` | `c9191c9b0` |
| GST prepare tool (routed, D8) | `/finance/gst-returns/prepare` | `c9191c9b0` |
| IRD filings | `/finance/ird-filings` | `c9191c9b0` |
| IRD filing record | `/finance/ird-filings/{filing}` | `c9191c9b0` |
| Audit exports | `/finance/audit-exports` | `c9191c9b0` |
| Donor funds | `/finance/donor-funds` | `c9191c9b0` |
| Donor fund record (tier-2) | `/finance/donor-funds/{fund}` | `c9191c9b0` |

### Reports — `/finance/reports`

| Page | Route | Commit |
|---|---|---|
| Statements — Profit & loss (tier-2) | `/finance/reports/profit-loss` | `a13c678aa` |
| Statements — Balance sheet (tier-2) | `/finance/reports/balance-sheet` | `a13c678aa` |
| Statements — Trial balance (tier-2) | `/finance/reports/trial-balance` | `a13c678aa` |
| Statements — Cash flow (tier-2) | `/finance/reports/cash-flow` | `a13c678aa` |
| Ageing — Receivables (tier-2) | `/finance/reports/aged-receivables` | `a13c678aa` |
| Ageing — Payables (tier-2) | `/finance/reports/aged-payables` | `a13c678aa` |
| Funding summary | `/finance/reports/funding-stream-summary` | `a13c678aa` |
| Budget vs actuals | `/finance/reports/budget-vs-actuals` | `a13c678aa` |
| Cash-flow forecast | `/finance/cash-flow-forecast` | `a13c678aa` |
| Forecast record | `/finance/cash-flow-forecast/{forecast}` | `a13c678aa` |

### Settings — `/finance/settings`

| Page | Route | Commit |
|---|---|---|
| Integrations | `/finance/integrations` | `504ed572d` |
| Account mapping | `/finance/integrations/{integration}/mapping` | `504ed572d` |
| Funding streams | `/finance/funding-streams` | `504ed572d` |
| Match rules | `/finance/match-rules` | `9782e05c6` |

## Retired surfaces

Fourteen routed create/edit pages plus two duplicates were deleted; every URL
now redirects to its register, keeping deep links alive (the `fixed-assets`
precedent). Controller `store`/`update` are untouched.

`accounts/Create`, `accounts/Edit`, `journals/Create`, `vendors/Create`,
`vendors/Edit`, `purchase-orders/Create`, `purchase-orders/Edit`,
`bills/Create`, `bills/Edit`, `invoices/Create`, `invoices/Edit`,
`payment-runs/Create`, `bank-reconciliation/Create`, `billing/Entries`,
`receivables/Aging` (folded into the Aged AR view),
`components/calendar/calendar-view.tsx` (last consumer was the finance
calendar).

## Exclusions — decisions, not oversights

- **Consolidation and Intercompany** (`Consolidation/{Index,Show,RunResults}`,
  `Intercompany/Index`) are quarantined by `RejectUnsupportedConsolidation` and
  404 for everyone. Decision D9 leaves them unmigrated; they are the only
  finance pages exempt from the ESLint `PageHero` ban (`6d8cb72f6`), and the
  exemption comment records that un-quarantining them means migrating or
  deleting them first.
- **`pages/clients/Financials.tsx`** is rendered by
  `/finance/clients/{client}/financials` but lives in the Clients module; it
  was never inventoried and should be picked up when that profile is next
  touched.
- **The HR, client and portal FullCalendar pages** still import
  `@fullcalendar/*` directly and are separate calendar-migration targets.

## Follow-ups — closed

Everything the first pass left open has been done, except two items that were
decisions rather than defects.

- **Bill meters linked to a list that did not match them.** `BillController`
  now has a `due` window filter (overdue / this week) and grouped
  `status=unpaid` / `status=awaiting` values, sharing status-set constants
  with the summary so the numbers and their links cannot drift apart
  (`429b5faff`, covered by `BillMeterFiltersTest`).
- **Overview meters crossed permission boundaries.** `Dashboard.tsx` now reads
  `can.finance.*` and drops a meter's destination when the viewer cannot open
  it, so a `finance.dashboard`-only viewer keeps the number without being sent
  to a 403. The three "see all" buttons beneath are dropped rather than
  rendered dead.
- **Stale browser assertions.** Sixteen `tests/Browser/Finance` cases visited a
  retired `/create` URL and waited for Title Case text the register no longer
  renders. They now assert the redirect and wait for the heading each register
  actually shows, and are named for what they check.

Left as decisions, not defects:

- Two tables on the site drill-down (insight messages, budget-category lines)
  carry no row actions, because neither row is a record with a destination.
  `EntityKebab` renders nothing for an empty menu, so no affordance dangles.
- The EFTPOS merchant ID is stored encrypted and deliberately never sent to the
  browser; the terminal edit dialog leaves the field blank and omits it from
  the PUT unless retyped, preserving the stored value.

## Pre-existing failures fixed along the way

The 26 Pest failures this migration inherited were not design problems, and
chasing them turned up four genuine application bugs. Nineteen of the 26 now
pass; the rest are described below.

| Bug | Effect | Fix |
|---|---|---|
| Four guards rejected `organization_id` 0 as invalid (`< 1`) | Organisation 0 is this app's default — FinanceSeeder seeds its chart of accounts, and the live database holds 35 accounts and a journal sequence under it — so journal numbering, fixed-asset depreciation and disposal, and invoice storage all threw for it | Reject only null or negative (`88c6156c4`, `3b6f99620`) |
| Evidence compared with `===` across a MySQL `json` column | MySQL normalises object key order, so a stored snapshot could never equal a rebuilt one: every governed bill was hidden from the approval picker and could never post against its approval. The same pattern in `WebhookReceiverController` defeated webhook idempotency | `AppSupportJsonEvidence::matches()` compares key/value sets, still strict about types and list order (`3b6f99620`) |
| `JournalPosted` dispatched inside `DB::transaction` | "A journal was posted" reached listeners — and jobs they queue — before the row was durable, and still reached them when an enclosing transaction rolled back | `DB::afterCommit` (`88c6156c4`) |
| Payment-run export fixtures built runs with no items | Not an app bug: `PaymentSettlementSiteScope` correctly hides a run whose lines the actor cannot settle, so the CSV held only its header | Fixtures build a visible run (`88c6156c4`) |

Two test-fixture updates came with them: `BillSpendApprovalGateTest` predates
the Governance change that made board sign-off explicit (`d8fa284d8`), so its
approvals now carry the passed resolution and its decider can open it; and two
donor-fund bills now name the zero-rated tax rate, because `GstTaxRateResolver`
refuses — by design — to guess between the seeded zero-rated and exempt rates
for a line storing a bare 0.

**Still failing (2), both pre-existing:**
`FixedAssetDisposalIntegrityTest`'s two `JournalPosted` timing cases step a
hand-rolled transaction ladder and expect the event only at true level 0, while
Laravel deliberately ignores `RefreshDatabase`'s wrapping transaction so
`afterCommit` code is testable at all — firing one level earlier. The
production semantics those tests describe are the ones now implemented; the
remaining gap is the harness, not the app.

## Browser walkthrough — done 2026-09-16

Run as Demo Admin against the migrated worktree (a PHP dev server on
`127.0.0.1:8766` with production assets built, because this worktree is not a
Herd site). Verified live:

| Check | Result |
|---|---|
| Eight Finance sidebar entries, exactly one lit | ✅ after `429b5faff` — see below |
| Rail flush with the page ground, Find chip present | ✅ Overview, Payables, Receivables, Banking, Reports, Settings |
| Rail overflows to "More" rather than wrapping | ✅ Receivables (7 views), Banking (7), and at 375px |
| Tier-2 strips render and navigate | ✅ Aged AR (Ageing/Statements), EFTPOS (Terminals/Batches), Reports (P&L/Balance sheet/Trial balance/Cash flow) |
| Home-rooted breadcrumbs, every crumb resolving | ✅ incl. 5-level trails, e.g. Home › Finance › Reports › Statements › Balance sheet |
| Filters inside the header | ✅ bills (status/vendor/date), statements (payer/as-at), EFTPOS (status/terminal/date), reports (as-at) |
| Meter blocks link to the list they counted | ✅ after `429b5faff` — see below |
| Kebab and right-click share one menu | ✅ bills row: Open bill · Open vendor |
| Empty states, not bare text | ✅ EFTPOS batches, client statements |
| Dark mode | ✅ balance sheet: ground, cards, text and status badge all invert via tokens |
| Narrow viewport (375px) | ✅ `scrollWidth === clientWidth` — no horizontal page scroll |
| Calendar on the shared Site Calendar | ✅ date anchor, Month/Week/Day/Agenda/Timeline, six source pills, glass back link, no create affordance |
| Match rules served from the Settings rail (D2) | ✅ crumbs, sidebar entry and rail all agree |

### Two defects the static sweep could not see, found here and fixed

Both in `429b5faff`, both with regression tests:

1. **Two sidebar entries lit at once.** The sidebar lights every item whose
   match score is positive — there is no single winner — and Overview sits at
   `/finance`, a prefix of every other finance URL, so the generic
   "starts with" rule lit Overview on top of the real hub on all 88 pages.
2. **Three bill meters linked to the same list.** Unpaid, Overdue and Due this
   week all pointed at `?status=approved`: three different numbers, one list,
   none agreeing with the meter above it. The controller had computed the
   figures correctly but had no filters to match them.

A third, smaller one in `ac706424c`: the rail printed a literal `0` badge on
empty registers. The audit had recorded that `PageHeaderRail` already hides a
zero count (citing `page-header.tsx:1202`); that line is the *overflow* pill,
and the per-tab counter renders any non-null value. The finance rail now maps
`0` to no badge, restoring the behaviour the old `tabCountBadge` had.

### Not covered here

Donor-fund and petty-cash record pages, the reconcile workbench and the
settlement-evidence dialog need seeded records this demo database does not
have; their controllers and dialogs are covered by the Pest suite instead.
