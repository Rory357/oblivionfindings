# Finance Dashboard — Audit Findings (Phase 0)

> Source of truth for the rebuild loop. Audited the live page (`resources/js/pages/finance/Dashboard.tsx`),
> the backend (`DashboardAggregatorService` + `FinanceDashboardController`), the design spec
> (`.design-drops/finance-dashboard-redesign/.../README.md`) and the gap docs against **live code**
> (not the gap doc's assertions — several were fixed by the concurrent Finance loop). Date: 2026-06-15.

---

## 1. Frontend parity matrix (README §1–9 → status → file:line → reuse component)

Current page = a 6-KPI grid + area chart + bar chart + expense pie + 2 tables. It does **not** match the
redesign: wrong hero tint, no period control, no filters, no donut row, no hubs bar, no needs-attention
strip, no funding tables, no footer, and it links to routes instead of opening the wizard modals.

| README section | Status | Current file:line | Reuse component (target) |
|---|---|---|---|
| §1 Hero banner (primary purple, eyebrow/title/desc/3-meta, 4 actions, 4 stat tiles) | **Partial** | `Dashboard.tsx:178-219` — uses `PageHero` but `category="finance"` (teal, **wrong** — must omit for `--primary` purple), no `meta`, no `footer`, actions are `<Link>` not modal-openers | `PageHero` (`components/page/page-hero.tsx:342`) — supports `icon/title/description/meta/stats/actions/footer`; omit `category`+`brandColour` → `--primary` gradient. **Do NOT use `FinanceHero`** (it forces `category="finance"` teal). |
| §1 Hero footer (period segmented control + Site/Funding filters) | **Missing** | — | `PageHero` `footer` slot (`page-hero.tsx:235`) + segmented control (local `useState`) + `MultiEntityFilter` `onDark` (`components/rostering/multi-entity-filter.tsx:51`) |
| §2 Finance hubs quick-links bar | **Missing** | — | New small component `components/finance/finance-hubs-bar.tsx`; Inertia `<Link>` chips to hub routes (see §A3 routes below) |
| §3 Needs-attention strip (6 cards, severity left-border) | **Missing** | — | New `components/finance/needs-attention-strip.tsx`; severity via `getStatusColor()` (`lib/status-colors.ts:60`) / `StatusBadge` (`components/hr/status-badge.tsx:78`) |
| §4 KPI cards (8, delta + sub-label, accent icon tile) | **Partial** | `Dashboard.tsx:92-130` inline `KpiCard` (6 cards, no sub-label, generic muted icon) | Rebuild 8-card grid; reuse `components/dashboard/kpi-card.tsx:17` pattern (label/value/icon/trend/href) **or** keep a local card matching the design's accent-tinted tile + delta + `· sub`. No shared finance KpiCard exists. |
| §5 Donut row (3: revenue-by-funding-stream, claim-utilisation, AR aging) | **Missing** (current has a single expense **pie**, not donut) | `Dashboard.tsx:305-351` expense `PieChart` | `DonutCard` (`components/rostering/donut-card.tsx:48`) + `Donut`/`DonutLegend` (`components/rostering/donut.tsx:28,117`). `DonutCard` props: `tone:'primary'|'warning'|'success'`, `title`, `subtitle`, `segments:{key,label,value,color}[]`, `centerValue`, `centerLabel`, `accentKeys`, `active`, `cta`, `onClick`. Hover-to-focus built in. |
| §6 Charts row (area net-profit trend + grouped bar rev/exp) | **Partial** | `Dashboard.tsx:262-303` area + bar exist but **hex colours** (`#3b82f6/#10b981/#ef4444`), separate cards not a `1.35fr/1fr` row, no delta pill | Recharts `AreaChart`/`BarChart` (already dep). Colours from `--chart-1..5`/`--primary`/`--status-warning` via CSS vars. |
| §7 Tables row (upcoming bills + funding claims) | **Partial** | `Dashboard.tsx:353-399` upcoming bills table OK; **funding-claims table missing** | `Table` (ui). Bills from `upcomingBillsDue`. Funding claims = placeholder until Phase D. |
| §8 Recent journals table | **Present** | `Dashboard.tsx:401-448` | `Table` (ui). Wired to `recentJournals`. Needs the toned type pill + 5-col grid layout. |
| §9 Page footer (brand block + 4 link columns + bottom bar) | **Missing** | — | New `components/finance/finance-dashboard-footer.tsx`; Inertia `<Link>`s to finance routes. |

**Quick actions → modals (README interactions):** current actions navigate (`/finance/journals/create` etc.) — **must** open `NewJournalDialog`/`NewBillDialog`/`NewInvoiceDialog`/`RecordReceiptDialog` via a `modal` state. ⚠️ **These dialogs require reference-data props** (`accounts`, `costCentres`, `fundingStreams` for journal; `vendors`, `accounts` for bill; `clients`, `taxRates` for invoice; a `ReceiptInvoice` for receipt). The controller currently supplies **none** of these — A2/Phase B must add them (props use `open`/`onClose`, not `onOpenChange`).

---

## 2. Standardisation findings (raw colour literals — must become tokens)

All in `resources/js/pages/finance/Dashboard.tsx`:

| file:line | Literal | Replace with |
|---|---|---|
| `Dashboard.tsx:37` | `CHART_COLORS = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16']` | `--chart-1..5` (+ `--primary`, `--status-warning`) via CSS vars / a token array |
| `Dashboard.tsx:274` | `<Area stroke="#3b82f6" fill="#3b82f6">` | `stroke="var(--primary)"` + gradient `var(--primary)` |
| `Dashboard.tsx:297` | `<Bar dataKey="revenue" fill="#10b981">` | `fill="var(--primary)"` (design: revenue bars = primary) |
| `Dashboard.tsx:298` | `<Bar dataKey="expenses" fill="#ef4444">` | `fill="var(--status-warning)"` (design: expense bars = warning) |
| `Dashboard.tsx:341` | `style={{ backgroundColor: CHART_COLORS[...] }}` | token-derived colour |

Also non-token-but-acceptable: `formatCurrency` (`Dashboard.tsx:86`) is a local `Intl.NumberFormat` — replace with `formatMoney`/`formatMoneyCompact` from `components/finance/money.tsx:12,42`. `text-status-info` link colour (`:383,:430`) is a token, OK. Tabular-nums currently absent on money cells — add.

**Goal gate (Phase A):** zero `#`-hex literals in `Dashboard.tsx`; money via `formatMoney`.

---

## 3. Backend data-truth matrix (dashboard surface → source → status → severity → file:line)

`DashboardAggregatorService::getDashboardData()` (`app/Domain/Finance/Services/DashboardAggregatorService.php`) is **org-scoped but NOT period-aware** (always current calendar month) and has **no site/funder filter**.

| Surface | Source | Status | Sev | file:line |
|---|---|---|---|---|
| `totalRevenue` / `totalExpenses` / `netProfit` | posted `fin_journal_lines` by account type, current month | **Served** (truthful, real GL) but **month-only** | — | `DashboardAggregatorService.php:26-29,41-61` |
| `cashBalance` | `FinBankAccount.current_balance` sum, active | **Served** | — | `:30,63-69` |
| `accountsReceivable` | `App\Models\Invoice` where status=sent | **Data-blind** — reads orphaned legacy table (write-orphan); real AR = `FinInvoice` | 🔴 | `:10,31,71-77` |
| `accountsPayable` | `FinBill` outstanding | **Served** | — | `:32,79-86` |
| `revenueByMonth`/`expensesByMonth` (6mo) | posted GL per month | **Served** | — | `:33-34,88-107` |
| `topExpenseCategories` | posted GL expense accounts | **Served** | — | `:35,109-131` |
| `upcomingBillsDue` (next 7d) | `FinBill` due window | **Served** | — | `:36,133-152` |
| `recentJournals` (5) | posted `FinJournal` | **Served** | — | `:37,154-173` |
| Revenue-by-funding-stream donut | — | **Missing** | 🟠 | not fed; Phase B/D |
| Funding-claim-utilisation donut + util % KPI | — | **Missing** (needs funding pipeline + remittance — gap 1.1/1.2) | 🟠 | Phase D |
| AR aging buckets donut (current/31-60/61-90/90+) | — | **Missing** (compute from `FinInvoice` after 3.1) | 🔴→ | Phase B/C |
| Revenue / resident KPI | — | **Missing** (needs funded-resident count) | 🟠 | Phase B |
| AP due ≤7d / cash runway sub-labels | partial | **Missing** | 🟠 | Phase B |
| Needs-attention items | — | **Missing** (placeholder until B/D/F) | 🟠 | Phase A9 → B/D/F |
| Funding-claims table | — | **Missing** | 🟠 | Phase D |
| Finance calendar / period meta | `FinanceCalendarAggregator` exists | **Partial** (built, not on dashboard) | 🟣 | Phase F |

**Period/filter:** controller passes only `$orgId` (`FinanceDashboardController.php:18-22`); no `period`/`site`/`funder` request handling. Phase B adds these + real per-period queries (replace the prototype's ×3/×11.5 scaling, which lives only in the HTML reference, not in our code).

---

## 4. Gap-doc reconciliation (items 1.1–5.3 vs live code)

> ⚠️ The gap doc predates the concurrent Finance loop. Several "critical" items are **already fixed**. Verified live.

### 1. Funding streams & claims
- **1.1 Remittance reconciliation** — **Confirmed missing.** Grep `remittance` across `app/` = 0 hits. No `FundingRemittance` model, no match queue. → **Phase D.**
- **1.2 Auto-claim from delivered service** — **Partial.** `app/Services/Operations/FundingService.php:12-42` has `generateClaimFromBilling()` (claim from `BillingEntry`), but no scheduled trigger / roster→claim pipeline. → **Phase D.**
- **1.3 Stale `FundingService` dead columns** — **Confirmed.** `FundingService.php:70-78` references `budget_used`/`total_budget` on `ServiceAgreement` (not in schema). Still referenced. → **Phase D (verify-then-delete).**

### 2. Client / resident money
- **2.1 Two backends; `ClientLedgerEntry` zero writes** — **Confirmed.** No `ClientLedgerEntry::create` in `app/` (only tests). `ClientFund` actively used (`ClientController.php:703-718`). Observer posts GL on read-side only. → **Phase C (pick canonical store).**
- **2.2 Segregation netting flaw** — **Likely already fixed.** `ClientLedgerService.php:56-75` separates `$operationalOutflows` from the personal running balance ("Personal balance, unchanged by operational rows"). Re-verify in Phase C before un-stubbing. → **Phase C (verify).**
- **2.3 Two profile tabs / family portal** — **Partial.** `ClientController.php:702-740` + `ClientFinancialsController.php:21-38` both read `ClientLedgerService`; `PortalClientController.php` exposes no finance. Not a dashboard blocker. → roadmap.

### 3. Receivables data integrity
- **3.1 AR reads orphaned `App\Models\Invoice`** — **Partial (dashboard still broken).** Already repointed to `FinInvoice`: `AccountsReceivableService.php:33`, `AccountsReceivableController.php:24,66,82`, `FinancialReportService.php:442`. **Still orphaned:** `DashboardAggregatorService.php:73` (the dashboard AR KPI). → **Phase C: repoint this one read; the legacy read path is otherwise already dead.**
- **3.2 `markPaid` posts no receipt journal** — **Already fixed.** `InvoiceController::markPaid` (`:512-517`) → `AccountsReceivableService::allocatePayment` posts DR Bank / CR AR balanced journal (`:137-158`). → **Phase C: re-verify + add a guard test only.**
- **3.3 Recurring / quote→invoice / credit-note GST** — **Already fixed.** `RecurringChargeService.php:18-20` correct columns; `QuoteController::convertToInvoice:262-335` creates `FinInvoice`; credit-note GST handled in `AccountsPayableService`. → no action.

### 4. Payroll → GL bridge
- **4.1 Payslip pre-gen at lock** — **Already fixed.** `PayrollExportService::lockRun:171-173` generates payslips on lock if count===0; `PostPayrollJournalJob` then always finds them. → **Phase C: re-verify + guard test only.**
- **4.2 Net pay disbursement** — **Partial.** `PayrollJournalService::postNetPayPayment:253-269` posts DR Accrued Wages 2300 / CR Bank, wired via `PayrollExportController::payNet:182-213`. **Missing:** the direct-credit (bank batch) file. Payment runs still vendor-only (`PaymentRunService:28-32`). → **Phase E: add direct-credit file + surface state.**
- **4.3 IRD payday filing** — **Confirmed dead.** `IrdFilingService.php:185` `buildPaydayFilingPayload` is `protected`, no controller/route/UI (`IrdFilingController` is GST-only; `routes/finance.php:581-585` GST-only). → **Phase E: wire it.**

### 5. Budgets & cost control
- **5.1 Budget stores / double sync / `BudgetSyncInterface`** — **Partial.** `Governance/Contracts/BudgetSyncInterface.php` has **zero implementors** (confirmed). Only **one** sync job scheduled (`SyncBudgetActualsJob`, `routes/console.php:450-453`) — "double sync" **not-repro**. → **Phase F: implement interface, consolidate actuals.**
- **5.2 Spend approvals post nothing** — **Confirmed.** `SpendApprovalController::approve:166-191` only mutates status; no journal/AP. → **Phase F.**
- **5.3 No finance calendar** — **Partial (mostly built).** `FinanceCalendarAggregator.php:1-109` + `FinanceCalendarController.php:29-62` aggregate invoice/bill/payment-run/GST. **Missing:** payroll + period-close events, and **dashboard surfacing**. → **Phase F: extend + surface.**

---

## Key implementation notes carried forward

- **Hero:** plain `PageHero`, omit `category` (purple `--primary`). Add `meta` (3 items: Calendar/MapPin/Users), `stats` (Revenue/Expenses/Net/Cash, shape `{label,value,tone?}`), `actions` (modal-openers), `footer` (period + filters).
- **Wizard modals need data props** — controller must supply `accounts`, `costCentres`, `fundingStreams`, `vendors`, `clients`, `taxRates`. In A2 wire the modal state; supplying real ref-data can land in A2 or Phase B (stub `[]` is acceptable to make modals open, but note it).
- **Hub routes (verified in `routes/finance.php`, prefix `finance.`):** `finance.accounts.index` (:103), `finance.receivables.index` (:301), `finance.bills.index` (:227), `finance.bank-accounts.index` (:371), `finance.funding-streams.index` (:162), **`finance.reports.profit-loss`** (:560 — README says `…profit-and-loss`, the real name is `profit-loss`), `finance.gst-returns.index` (:474). Use `route()` / Wayfinder where available.
- **DonutCard `tone`** only accepts `'primary'|'warning'|'success'` — maps to the 3 donuts (revenue→primary, utilisation→warning, AR aging→success). Pass token colours into `segments[].color` (e.g. `'var(--chart-1)'`).
- **PageLayout** `width`: `'wide'` = `max-w-[1600px]` (design wants 1480px; `'wide'` is the closest existing cap — or leave default `'full'` and constrain in-page). Padding default `md`.
- **`DonutSegment` shape** = `{ key, label, value, color }`. **`MultiEntityFilter` items** = `{ id, name, description? }`, `value: number[]` (empty = All).

**Phase ordering reality:** Phase C is much smaller than the gap doc implies (3.2/3.3/4.1 already done; 3.1 = one line; 2.2 likely done). The substantive new backend work is Phase D (funding/remittance) and Phase E (payday filing + direct-credit file) and Phase F (budgets + calendar surfacing).
