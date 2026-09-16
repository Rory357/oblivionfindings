# Finance design migration — acceptance checklist

Companion to `audit.md` and `implementation-plan.md`. One row per surface, with
the commit that migrated it. "Browser" is the Demo Admin walkthrough on
`oblivionfindings.test` described at the bottom of this file.

**Baseline (2026-09-16, from the audit):** 92 of 92 pages on the legacy
`PageHero`, 0 on `PageHeader`, 0 with Home-rooted breadcrumbs, 0 on the entity
list contracts, 0 using `laravel-pagination.tsx`.

**Now:** every live finance page is on the Event Horizon header. The ESLint
`PageHero` ban under `resources/js/pages/finance/**` reports zero, and the
module-wide sweep over the 117 live files reports zero for `PageHero`,
`*TabsFooter`, `FinanceHubsBar`, `FinanceSummaryCard`, `NeedsAttentionStrip`,
`useRowContextMenu`, `useFinanceTab`, `window.prompt`/`confirm`,
`(this page)`, `dark:` colour pairs and `dangerouslySetInnerHTML`.

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

| Page | Route | Commit | Browser |
|---|---|---|---|
| Summary | `/finance` | `db7e730aa` | |
| Executive | `/finance/executive-dashboard` | `db7e730aa` | |
| By site | `/finance/sites` | `db7e730aa` | |
| Site drill-down | `/finance/sites/{site}/financial-dashboard` | `db7e730aa` | |
| Cash position | `/finance/cash-position` | `db7e730aa` | |
| Calendar | `/finance/calendar` | `3393edf99` | |

### General ledger — `/finance/ledger`

| Page | Route | Commit | Browser |
|---|---|---|---|
| Chart of accounts | `/finance/accounts` | `8275c5cbe` | |
| Account record | `/finance/accounts/{account}` | `8275c5cbe` | |
| Journals | `/finance/journals` | `8275c5cbe` | |
| Journal record | `/finance/journals/{journal}` | `8275c5cbe` | |
| Fixed assets | `/finance/fixed-assets` | `8275c5cbe` | |
| Fixed asset record | `/finance/fixed-assets/{asset}` | `8275c5cbe` | |
| Cost centres | `/finance/cost-centres` | `8275c5cbe` | |
| Fiscal periods | `/finance/fiscal-periods` | `8275c5cbe` | |
| Currencies | `/finance/currencies` | `8275c5cbe` | |
| FX revaluations | `/finance/fx-revaluations` | `8275c5cbe` | |
| FX revaluation tool (routed, D8) | `/finance/fx-revaluations/create` | `8275c5cbe` | |

### Payables — `/finance/payables`

| Page | Route | Commit | Browser |
|---|---|---|---|
| Bills | `/finance/bills` | `6ee5f03f9` | |
| Bill record | `/finance/bills/{bill}` | `6ee5f03f9` | |
| Purchase orders | `/finance/purchase-orders` | `6ee5f03f9` | |
| Purchase order record | `/finance/purchase-orders/{po}` | `6ee5f03f9` | |
| Vendors | `/finance/vendors` | `6ee5f03f9` | |
| Vendor record (tier-2) | `/finance/vendors/{vendor}` | `6ee5f03f9` | |
| Credit notes | `/finance/credit-notes` | `6ee5f03f9` | |
| Credit note record | `/finance/credit-notes/{note}` | `6ee5f03f9` | |
| Payment runs | `/finance/payment-runs` | `6ee5f03f9` | |
| Payment run record | `/finance/payment-runs/{run}` | `6ee5f03f9` | |

### Receivables — `/finance/invoices`

| Page | Route | Commit | Browser |
|---|---|---|---|
| Invoices | `/finance/invoices` | `a9f52a19f` | |
| Invoice record | `/finance/invoices/{invoice}` | `a9f52a19f` | |
| Quotes | `/finance/quotes` | `a9f52a19f` | |
| Quote record | `/finance/quotes/{quote}` | `a9f52a19f` | |
| Recurring charges | `/finance/recurring-charges` | `a9f52a19f` | |
| Billing | `/finance/billing` | `a9f52a19f` | |
| Aged AR — Ageing (tier-2) | `/finance/receivables` | `a9f52a19f` | |
| Aged AR — Statements (tier-2) | `/finance/receivables/statements` | `a9f52a19f` | |
| Price books | `/finance/price-books` | `a9f52a19f` | |
| Price book record | `/finance/price-books/{book}` | `a9f52a19f` | |
| Allocations | `/finance/payment-allocations` | `a9f52a19f` | |

### Banking — `/finance/banking`

| Page | Route | Commit | Browser |
|---|---|---|---|
| Bank accounts | `/finance/bank-accounts` | `4ecf52e1a` | |
| Bank account record | `/finance/bank-accounts/{account}` | `4ecf52e1a` | |
| Transactions | `/finance/bank-transactions` | `4ecf52e1a` | |
| Reconciliation | `/finance/bank-reconciliation` | `4ecf52e1a` | |
| Reconcile tool | `/finance/bank-reconciliation/{rec}` | `4ecf52e1a` | |
| Matching | `/finance/payment-matching` | `9782e05c6` | |
| Feeds | `/finance/bank-feeds` | `9782e05c6` | |
| Feed logs | `/finance/bank-feeds/{feed}/logs` | `9782e05c6` | |
| EFTPOS — Terminals (tier-2) | `/finance/eftpos/terminals` | `9782e05c6` | |
| EFTPOS — Batches (tier-2) | `/finance/eftpos/batches` | `9782e05c6` | |
| EFTPOS batch record | `/finance/eftpos/batches/{batch}` | `9782e05c6` | |
| Petty cash | `/finance/petty-cash` | `9782e05c6` | |
| Petty cash fund record | `/finance/petty-cash/{fund}` | `9782e05c6` | |

### Tax & compliance — `/finance/tax`

| Page | Route | Commit | Browser |
|---|---|---|---|
| GST returns | `/finance/gst-returns` | `c9191c9b0` | |
| GST return record | `/finance/gst-returns/{return}` | `c9191c9b0` | |
| GST prepare tool (routed, D8) | `/finance/gst-returns/prepare` | `c9191c9b0` | |
| IRD filings | `/finance/ird-filings` | `c9191c9b0` | |
| IRD filing record | `/finance/ird-filings/{filing}` | `c9191c9b0` | |
| Audit exports | `/finance/audit-exports` | `c9191c9b0` | |
| Donor funds | `/finance/donor-funds` | `c9191c9b0` | |
| Donor fund record (tier-2) | `/finance/donor-funds/{fund}` | `c9191c9b0` | |

### Reports — `/finance/reports`

| Page | Route | Commit | Browser |
|---|---|---|---|
| Statements — Profit & loss (tier-2) | `/finance/reports/profit-loss` | `a13c678aa` | |
| Statements — Balance sheet (tier-2) | `/finance/reports/balance-sheet` | `a13c678aa` | |
| Statements — Trial balance (tier-2) | `/finance/reports/trial-balance` | `a13c678aa` | |
| Statements — Cash flow (tier-2) | `/finance/reports/cash-flow` | `a13c678aa` | |
| Ageing — Receivables (tier-2) | `/finance/reports/aged-receivables` | `a13c678aa` | |
| Ageing — Payables (tier-2) | `/finance/reports/aged-payables` | `a13c678aa` | |
| Funding summary | `/finance/reports/funding-stream-summary` | `a13c678aa` | |
| Budget vs actuals | `/finance/reports/budget-vs-actuals` | `a13c678aa` | |
| Cash-flow forecast | `/finance/cash-flow-forecast` | `a13c678aa` | |
| Forecast record | `/finance/cash-flow-forecast/{forecast}` | `a13c678aa` | |

### Settings — `/finance/settings`

| Page | Route | Commit | Browser |
|---|---|---|---|
| Integrations | `/finance/integrations` | `504ed572d` | |
| Account mapping | `/finance/integrations/{integration}/mapping` | `504ed572d` | |
| Funding streams | `/finance/funding-streams` | `504ed572d` | |
| Match rules | `/finance/match-rules` | `9782e05c6` | |

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

## Known follow-ups

- `BillController@index` has no due-date filter (only `bill_date` from/to), so
  the Overview "Bills due ≤ 7 days" meter links to the unfiltered register. A
  real `due_within` filter would close it.
- Some Overview meter links cross permission boundaries (`reports.view`,
  `ap.view`, `ar.view`, `bank.view`) for a viewer holding only
  `finance.dashboard`. They follow the findings' own proposals; gating them
  means threading `can.finance.*` into those pages.
- Two tables on the site drill-down (insight messages, budget-category lines)
  carry no row actions, because neither row is a record with a destination.
  `EntityKebab` renders nothing for an empty menu, so there is no dangling
  affordance.
- The EFTPOS merchant ID is stored encrypted and is deliberately never sent to
  the browser; the terminal edit dialog leaves the field blank and omits it
  from the PUT unless retyped, which preserves the stored value.
- Four `tests/Browser` assertions still wait for Title Case strings on pages
  that no longer exist. CI skips `tests/Browser`.

## Browser walkthrough

As Demo Admin on `oblivionfindings.test`, per hub:

1. The sidebar shows eight Finance entries and the right one stays lit on a
   register, a record and a tier-2 sibling.
2. The rail's active tab sits flush with the page ground and the Find chip is
   present.
3. Breadcrumbs start at Home and every crumb resolves (no `#`).
4. Filters are inside the header; changing one re-queries.
5. Every list row opens from both the kebab and a right-click, with the same
   items.
6. Dark mode and a narrow viewport: no horizontal page scroll.
