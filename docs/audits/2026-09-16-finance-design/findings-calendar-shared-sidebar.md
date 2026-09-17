# Finance design audit — calendar, shared finance components, sidebar

Lead auditor findings (2026-09-16). Companion files: `findings-overview-ledger.md`, `findings-payables-receivables.md`, `findings-banking-tax-reports.md`. Rules: `DESIGN.md`, `design_styles/*`.

## Area summary

- Files audited: `resources/js/pages/finance/Calendar.tsx`; `resources/js/components/finance/{finance-hero,finance-tabs,finance-hubs-bar,overview-hub,ledger-hub,payables-hub,receivables-hub,banking-hub,tax-hub,reports-hub,settings-hub,needs-attention-strip,summary-card,confirm-dialog,wizard,index}.tsx`; `resources/js/components/app-sidebar.tsx` (`buildFinanceSubPanelGroups` ~L1969–2115, `matchScore` ~L290–335); `app/Domain/Finance/Http/Controllers/FinanceCalendarController.php`; `app/Domain/Finance/Services/Calendar/FinanceCalendarAggregator.php`; `app/Domain/Finance/Services/FinanceHubCountsService.php`; `app/Http/Middleware/HandleInertiaRequests.php` (`can.finance`, `financeHubCounts`).
- Counts: P0 6 · P1 9 · P2 4.

### The module-wide shape problem (state once, applies to all 92 pages)

Finance was built on the **pre-2026-09-05 design spine**: `FinanceHero` → `PageHero` (superseded), hub tabs as a Rule 2 toned `TabStrip` in the hero **footer** (`*TabsFooter`), a "Finance hubs" jump bar below the hero on the dashboard, and sidebar links per register. Every one of those is now a named migration target in DESIGN.md:

| Today | Rule | Target |
|---|---|---|
| `FinanceHero`/`PageHero` on all 92 pages | "Page headers — the Event Horizon header"; anti-pattern "Page tops that bypass the Event Horizon header" | `PageHeader` (index or profile variant) with meter row, in-header search/filters, connected rail |
| `*TabsFooter` → `FinanceTabs` → rostering `TabStrip` (Rule 2 chips) in hero footer | "Index view tabs — main tabs live in the hero (connected-tab rail)"; anti-pattern "Legacy two-tier nav styling" | `PageHeaderRail` (Rule 1 connected tab) fed by one `lib/finance-sections.ts` config, mirroring `lib/governance-sections.ts` + `GovernanceSectionRail` |
| Sidebar: 6 separate Receivables links + hub links | anti-pattern "One sidebar link per register" | ONE sidebar entry per hub; siblings are the hub's rail; entry stays lit across the hub (`governanceHubContainsUrl` equivalent) |
| `FinanceHubsBar` card below the hero | "Lists render nothing between the header and their content" | delete — cross-hub jumps are the sidebar + Find chip |
| Breadcrumbs `[Finance, …]` | anti-pattern "Missing or non-Home-rooted breadcrumbs" | `[Home(/dashboard), Finance(/finance), <Hub>, <Page>]` |
| Hand-rolled tables + custom pagination | LIST_STYLE_GUIDE; probe 15/18 | `EntityTable`/`EntityCard` + `laravel-pagination.tsx` |

### Hub → rail proposal (module-wide, the single config to build)

`resources/js/lib/finance-sections.ts` — same shape as `governance-sections.ts` (`key, label, icon, group, tabs[{key,label,href,icon,prefixes,visible}]`), plus `financeSectionForUrl(url)` and `visibleSectionTabs(section, can)`. A `FinanceSectionRail` component (copy of `GovernanceSectionRail`) resolves hub + active tab from the URL and passes `counts` from `financeHubCounts`. Sidebar shows ONE entry per section and `matchScore` gets a `financeHubContainsUrl` branch so the entry stays lit across its tabs.

| Section (sidebar entry) | Rail views (label · href · `can.finance.*`) | Notes |
|---|---|---|
| **Overview** `/finance` | Summary `/finance` · Executive `/finance/executive-dashboard` · By site `/finance/sites` · Cash position `/finance/cash-position` · Calendar `/finance/calendar` (all `dashboard`) | Calendar joins Overview (today a separate sidebar link). 5 views. |
| **General ledger** `/finance/ledger`→redirect | Chart of accounts `/finance/accounts` (ledger.view) · Journals `/finance/journals` (ledger.view) · Fixed assets `/finance/fixed-assets` (assets.view) · Cost centres `/finance/cost-centres` (admin) · Fiscal periods `/finance/fiscal-periods` (admin) · Currencies `/finance/currencies` (admin) · FX revaluations `/finance/fx-revaluations` (ledger.manage) | 7 views — under the ≤8 cap; `PageHeaderRail` overflow handles narrow viewports. |
| **Payables** `/finance/payables`→redirect | Bills `/finance/bills` · Purchase orders `/finance/purchase-orders` · Vendors `/finance/vendors` · Credit notes `/finance/credit-notes` · Payment runs `/finance/payment-runs` (all ap.view) | 5 views. |
| **Receivables** `/finance/receivables`→? | Invoices `/finance/invoices` · Quotes `/finance/quotes` · Recurring charges `/finance/recurring-charges` · Billing `/finance/billing` · Aged AR `/finance/receivables` · Statements `/finance/receivables/statements` · Price books `/finance/price-books` · Allocations `/finance/payment-allocations` (ar.view) | 8 views — AT the cap. **Owner decision D1**: fold Statements into Aged AR as a tier-2 strip? Fold Allocations into Invoices? Recommend: Aged AR + Statements become tier-2 sub-tabs of one "Receivables" view (→ 7 rail views). `/finance/receivables` today renders Aging directly; the hub landing should be Invoices (the list people work in). `/finance/receivables/aging` is a second route for the same page — keep one. |
| **Banking** `/finance/banking`→redirect | Accounts `/finance/bank-accounts` · Transactions `/finance/bank-transactions` · Reconciliation `/finance/bank-reconciliation` · Matching `/finance/payment-matching` · Feeds `/finance/bank-feeds` · EFTPOS `/finance/eftpos/terminals` · Petty cash `/finance/petty-cash` (petty_cash.view) · Match rules `/finance/match-rules` (bank.view/manage) | 8 views — AT the cap. **Owner decision D2**: Match rules is configuration; recommend moving it to Settings (→ 7). EFTPOS Terminals/Batches become a tier-2 strip inside the EFTPOS view. |
| **Tax & compliance** `/finance/tax`→redirect | GST returns `/finance/gst-returns` (tax.view) · IRD filing `/finance/ird-filings` (tax.manage) · Audit exports `/finance/audit-exports` (reports.view) · Donor funds `/finance/donor-funds` (reports.view) | Donor funds joins Tax & compliance (today a stray "Other" sidebar link). Consolidation is quarantined (`RejectUnsupportedConsolidation`) — **drop its tab** (dead tab today for everyone; see banking-tax-reports findings). |
| **Reports** `/finance/reports`→redirect | Profit & loss · Balance sheet · Trial balance · Cash flow · Aged AR · Aged AP · Funding summary · Budget vs actuals · Cash-flow forecast (reports.view) | **9 views — over the cap.** **Owner decision D3**: recommend one "Reports" rail of 5 views — Statements (P&L / Balance sheet / Trial balance / Cash flow as a tier-2 strip), Ageing (AR / AP tier-2), Funding summary, Budget vs actuals, Cash-flow forecast. Each report keeps its canonical URL; the tier-2 strip just switches between siblings. |
| **Settings** `/finance/settings`→redirect | Integrations `/finance/integrations` · Funding streams `/finance/funding-streams` (admin) · (+ Match rules if D2 accepted) | |

Record pages (Show.tsx) render the same hub rail with their list tab active, `variant="profile"`, plus a tier-2 strip for in-record sections (details / lines / payments / history…).

## Pages

### `/finance/calendar` — `resources/js/pages/finance/Calendar.tsx` (calendar, 393 lines)

**Current top:** `PageHero` (category finance, icon CalendarDays), title "Finance Calendar", description sentence, 4 hero stats (Obligations · Overdue · Money in · Money out — computed client-side from the loaded range), no actions, no footer/tabs.
**Body:** a source legend row of hand-rolled `rounded-full` toggle buttons (L215–262) + "Overdue" legend + `animate-pulse` "loading…" text; then a `rounded-2xl` card wrapping `CalendarView` (the FullCalendar wrapper `components/calendar/calendar-view.tsx`, whose ONLY remaining consumer is this page) with FullCalendar's own toolbar (`prev,next today | title | dayGridMonth,listMonth`), month + list views only; event click → a simple detail `Dialog` with a hand-rolled status pill (`STATUS_TONE` map, L89–96) and an "Open record" link.
**Breadcrumbs:** `[Finance, Calendar]` → required `[Home /dashboard, Finance /finance, Calendar /finance/calendar]`.
**Hub/rail:** Overview section, "Calendar" view. Calendar views (Month/Week/Day/Agenda/Timeline) come from `SiteCalendar`'s own rail; the Overview hub membership is carried by the breadcrumb + sidebar (same as Governance Calendar, which keeps a glass back link "Governance home").

**Findings:**
- [P0] CALENDAR_STYLE_GUIDE "All new or updated calendars use the look and feel of /calendar"; DESIGN.md "Calendars — always the Site Calendar style" — `Calendar.tsx:15,220–270` — a separate FullCalendar skin with its own toolbar, month grid, colour scheme and legend. **Fix:** replace the page body with `<SiteCalendar context="page" scope="global" canCreate={false} dataAdapter={createFinanceCalendarAdapter(...)} />` exactly as `pages/Governance/Calendar/Index.tsx` does (64 lines). Delete the FullCalendar usage; after this `components/calendar/calendar-view.tsx` has zero consumers — delete it. Do NOT remove the `@fullcalendar/*` packages: `pages/hr/calendar/index.tsx`, `pages/operations/clients/calendar.tsx`, `pages/operations/clients/tabs/legacy-profile-sections.tsx` and `pages/portal/calendar.tsx` still import them directly (those are separate migration targets outside this audit).
- [P0] CALENDAR_STYLE_GUIDE "Prominent date anchor" / PAGE_HEADER §5 — `Calendar.tsx:196–205` — no date anchor; the viewed month lives only in FullCalendar's toolbar title. **Fix:** inherited from `SiteCalendar` (its first meter is the anchor) once migrated.
- [P0] Anti-pattern "Page tops that bypass the Event Horizon header" — `Calendar.tsx:196–205` (`PageHero`), `Calendar.tsx:215–262` (filter legend below the header). **Fix:** inherited: `SiteCalendar` renders `PageHeader` with source pills in the header.
- [P1] Anti-pattern "Hand-rolled status pills" — `Calendar.tsx:89–96,286–293` — `STATUS_TONE` map + `<span className="rounded-full …">`. **Fix:** `<StatusBadge status={selected.status}>`. `lib/status-colors.ts` already has `overdue`, `paid`, `filed`; add `due` (INFO), `processed` (SUCCESS) and `scheduled` (INFO) there once (DESIGN.md "New status key? Add it to status-colors.ts once").
- [P1] Anti-pattern "Generic spinners on loading surfaces" — `Calendar.tsx:254–258` — `animate-pulse` "· loading…" text. **Fix:** inherited (SiteCalendar handles loading with `LoadingState`).
- [P1] Non-lucide/ad-hoc chrome: `rounded-full` legend pills (`Calendar.tsx:225`) violate PAGE_HEADER §9 "No full-capsule pills"; `eslint-disable no-restricted-syntax` at L219 exists only to allow this. **Fix:** delete with the migration.
- [P2] Copy — "Finance Calendar" Title Case → "Finance calendar"; status `capitalize` of raw enum (`processed`, `filed`) → label map. Inherited via `SiteCalendar` labels + `StatusBadge`.

**Migration spec (the implementer needs all of this):**

1. **Tokens** — add four calendar-source triples to `resources/css/app.css` next to the Governance set (L345–360), light + `.dark`, and document them in `design_styles/DESIGN_TOKENS.md` and DESIGN.md's colour-token table ("Calendar sources" row now lists `--src-meetings/decisions/obligations/policies`; add the finance four):
   - `--src-invoice-due` / `-bg` / `-ln` (money in — a green family distinct from `--src-obligations` hue 150; suggest hue ~165)
   - `--src-bill-due` / `-bg` / `-ln` (money out — amber family, hue ~60, distinct from `--src-policies` 65 → use ~40)
   - `--src-payment-run` / `-bg` / `-ln` (brand-adjacent violet, hue ~280, distinct from `--src-decisions` 310)
   - `--src-gst-due` / `-bg` / `-ln` (teal/blue, hue ~215, distinct from `--src-vendor` 205 → use ~225)
   Safety rule: an **overdue** entry must still read critical regardless of source (today's `OVERDUE` override, L79–82). Verified: `_parts.tsx` already renders `status === 'overdue'` in `text-status-critical` with an `AlertTriangle` (L313, L343, L430–481, L656, L787) — so the feed only has to emit `status: 'overdue'`; no shared-component change needed.
2. **Feed** — `FinanceCalendarController@events` currently returns `FinanceObligation[]` (`id, source, title, start, status, amount, direction, ref, counterparty, link, meta`). `SiteCalendar` consumes `CalendarItem[]` (`resources/js/lib/calendar/recur.ts:54`: `id, source, group:'auto', title, start, end:null, allDay:true, status, owner:null, room:null, ref, site:null, link, editable:false, desc`). Two options; pick **(a)**: add a `toCalendarItem()` mapping in `app/Domain/Finance/Services/Calendar/FinanceCalendarAggregator::arrayForRange` (or a new `itemsPayload()`), keeping amount/direction/counterparty in `desc` ("$1,234.00 · Money out · Acme Ltd") and `ref`. Also return `totals` (`{invoice_due: n, bill_due: n, payment_run: n, gst_due: n, overdue: n}`) so the header meters are server-authoritative like Governance. Keep the JSON route name `finance.calendar.events`; existing feature test asserts `component('finance/Calendar')` only — keep the page name.
3. **Adapter** — new `resources/js/lib/finance-calendar-adapter.ts` mirroring `governance-calendar-adapter.ts`: `FINANCE_CALENDAR_SOURCES: SourceDef[]` (`key: invoice_due|bill_due|payment_run|gst_due`, plain-word labels "Invoices due" / "Bills due" / "Payment runs" / "GST returns due", `group:'auto'`, lucide icon names, `origin`, `note`), `createFinanceCalendarAdapter({ title:'Calendar', subline:'Invoice and bill due dates, payment runs and GST deadlines', allowSubscriptions:false, showApprovalMeter:false, mineLink:{href:'/finance', label:'Open Finance overview'}, backLink:{href:'/finance', label:'Finance overview'}, searchPlaceholder:'Search invoices, bills, payment runs…', exportFilename:'finance-calendar.ics', initialView:'month', sourceFilters, loadItems → fetch('/finance/calendar/events?start&end&sources') → {events, totals}, onOpenItem → router.visit(item.link) })`. No `onCreate` (finance obligations aren't created from the calendar). Verified: `SiteCalendar` gates every create affordance on `canCreate` (L1027, L1390, L1573, L1904), so `canCreate={false}` hides the New button and the right-click create entries.
4. **Page** — rewrite `Calendar.tsx` to the Governance shape (AppLayout with Home-rooted crumbs, `<Head title="Calendar" />`, `useMemo` adapter, `<SiteCalendar …/>`). The detail dialog goes: `SiteCalendar` has its own entry detail treatment ("detail treatment" in CALENDAR_STYLE_GUIDE) with the link; the finance-specific fields (amount, direction, counterparty, period) ride in `desc`/`ref`.
5. **Server props** — controller `index()` passes `sources` (array of `SourceDef`, server-filtered — e.g. hide `gst_due` unless `finance.tax.view`) and `initialSources`; page no longer needs `eventsUrl`.
6. **Tests** — `tests/Feature/Finance/*Calendar*` (feature test asserts component + feed keys: update feed assertions to the `CalendarItem` keys); add `resources/js/lib/finance-calendar-adapter.test.ts` mirroring `governance-calendar-adapter.test.ts` (item mapping + totals).
7. **Verification** (CALENDAR_STYLE_GUIDE "Interaction verification"): desktop + narrow viewport, date anchor shows day/month/year and updates on Prev/Next/Today/Jump, Month/Week/Day/Agenda/Timeline all render finance entries, source pills filter, overdue reads critical, click opens the record.

## Shared finance components (`resources/js/components/finance/`)

### `finance-hero.tsx` (18 lines)
- [P0] Superseded system — wraps `PageHero`; header comment cites the deleted `GOVERNANCE_HERO_GUIDE.md`. **Fix:** delete once no page imports it (0 pages import `FinanceHero` today — pages import `PageHero` directly; grep showed zero `FinanceHero` usages). Remove its export from `index.ts` and the `PageHeroProps`/`PageHeroStat` re-exports.

### `finance-tabs.tsx` (94 lines) + all `*-hub.tsx` (8 files, 1,046 lines)
- [P0] NAVIGATION_STYLE_GUIDE Rule 1 / DESIGN.md "Index view tabs — main tabs live in the hero" — every `*TabsFooter` renders a Rule 2 `TabStrip` (toned chips, hand-picked `tone:` per tab — `ledger-hub.tsx:44–100`, etc.) inside `PageHero footer`. Hand-picked tones also breach "Hand-picked or semantic sub-tab tones". **Fix:** replace the eight `*_TABS` arrays + `*TabsFooter` components with `lib/finance-sections.ts` + `FinanceSectionRail` (see proposal above). Keep `requires` semantics as `visible(can)`; keep `financeHubCounts` → rail `count`. Delete `finance-tabs.tsx` (`FinanceTabs`, `useFinanceTab`, `tabCountBadge`) after migration; `useFinanceTab` (in-page `?tab=` sync) survives only where a record page keeps a tier-2 strip — move it to `lib/use-query-tab.ts` if still needed (HR has an identical `useHrTab`; DESIGN.md says hoist duplicates).
- [P1] `tabCountBadge` caps at "999+" and hides zero — `PageHeaderRail` already hides `count` when it is not `> 0` (`page-header.tsx:1202`) and renders counters in the state-colour rule; pass the raw number from `financeHubCounts` and drop `tabCountBadge`.
- Tests to update/delete: `hub-counts.test.tsx` (tests `tabCountBadge`), `finance-primitives.test.tsx` (`useFinanceTab` block).

### `finance-hubs-bar.tsx` (78 lines)
- [P0] PAGE_HEADER §2 "Index/list pages: nothing below the band" — a card of cross-hub jump links rendered between the hero and content on `Dashboard.tsx:818`. Its hrefs also disagree with the hub config (Payables → `/finance/bills`, Reports → `/finance/reports/profit-loss`, Funding & Claims → `/finance/funding-streams` which is a Settings tab). **Fix:** delete the component; the sidebar sub-panel + Find chip cover navigation.

### `needs-attention-strip.tsx` (134 lines)
- [P1] Anti-pattern "Hand-rolled status pills" + `rounded-full` tag pill (L108–113); `rounded-2xl` cards; `gap-3` grid; `text-[13px] font-bold` ad-hoc heading. Content-wise this is a legitimate in-page "needs attention" section. **Fix:** on the Overview Summary page the four attention counts (overdue invoices, unpaid bills, unreconciled transactions, GST due…) become **toned meter blocks in the header** (each linking to its filtered list — that is exactly what the meter row is for). If a body section is still wanted, restyle: `ListCaption` title row, `gap-5` grid, `<StatusBadge>`/`CounterPill` for the tag, `rounded-[14px]` card per LIST_STYLE_GUIDE §1.

### `summary-card.tsx` (61 lines)
- [P1] Ad-hoc KPI card (`text-xl font-bold`, `pt-6`) used in list-page heroes/bodies as stat tiles. DESIGN.md "Stat/KPI cards" points to `components/ops-stat-card.tsx`; but on migrated pages these numbers move INTO the header meter row and the card disappears. **Fix:** delete after migration; any residual in-body KPI uses `OpsStatCard`.

### `confirm-dialog.tsx` (75 lines)
- [P1] Anti-pattern "Rebuilding an existing primitive" — a second confirm dialog beside `components/confirm-dialog.tsx` (the DESIGN.md-named one). Also `text-white` + `dark:focus-visible:ring-destructive/40` (L63) — a `dark:` pair and a raw colour on a token-styled element. **Fix:** the shared `components/confirm-dialog.tsx` already takes `variant: 'destructive' | 'default'` (default `destructive`) but has no `processing` prop. Add `processing?: boolean` to the shared one (disables both buttons, ring-only loader on confirm per LOADER guide), re-export it from `components/finance/index.ts`, delete this file, and grep every finance caller — note the default-variant difference (finance's default is `default`, shared's is `destructive`), so each call site passes `variant` explicitly.

### `wizard.ts` (61 lines)
- OK — re-exports the shared `WizardShell` kit (not a fork); `useWizard` duplicates HR's `useWizard` (documented). [P2] Hoist to `components/wizard/use-wizard.ts` in the de-dup sweep; not blocking.

### `index.ts`
- [P1] Re-exports `StatusBadge` from `@/components/hr/status-badge` (L45–49) while DESIGN.md names `components/ui/status-badge.tsx` as the one status component (and 36 finance pages already import the `ui` one). **Fix:** re-export the `ui` StatusBadge (or drop the re-export) and grep for any finance import of the HR one. Also drop the `useRowContextMenu` re-export in favour of `components/lists/entity-menu.tsx` (`EntityKebab` + `EntityContextMenu` + `useEntityContextMenu`, the LIST_STYLE_GUIDE contract) once lists migrate — 19 finance pages use `useRowContextMenu` today; their `RowCtxItem[]` maps 1:1 to `MenuItem[]`.

### `chart-palette.ts`, `money.tsx`, `posting-preview.tsx`
- OK (tokens only). `formatMoney` is en-NZ NZD — keep.

## Sidebar — `resources/js/components/app-sidebar.tsx`

### `buildFinanceSubPanelGroups` (~L1969–2115)
- [P0] Anti-pattern "One sidebar link per register" (corrected 2026-09-14) — the Accounts Receivable group lists **six** links (Billing, Receivables, Invoices, Price Books, Quotes, Recurring Charges — L2014–2048) that are all tabs of one hub; "Calendar" and "Donor Funds" float as loose links; groups are labelled "Finance / Accounts Payable / Accounts Receivable / Banking / Other / Reports" (the "Other" bucket is a smell). **Fix:** one `NavItem` per section from `finance-sections.ts` — Overview · General ledger · Payables · Receivables · Banking · Tax & compliance · Reports · Settings — built by iterating the sections config (visible if any tab is visible), no captions (per the 2026-09-16 "Section captions inside a sidebar module" anti-pattern the groups are flattened anyway — but keep the group builders only for ordering).
- [P0] Hub entry doesn't stay lit — `matchScore` (~L312) has a `governanceHubContainsUrl` branch but nothing for finance, so on `/finance/accounts` the "General Ledger" entry (`/finance/ledger`) is inactive and NO finance entry is lit; likewise `/finance/bills` vs Payables. **Fix:** add `financeHubContainsUrl(itemPath, currentPath)` using `financeSectionForUrl` prefixes, same score tier (2000).
- [P1] Title Case labels ("Price Books", "Recurring Charges", "Donor Funds", "General Ledger") — DESIGN.md plain-language rule: sentence case → "General ledger", "Tax & compliance".
- [P1] Module gate (L791–797) omits `bank.view`, `tax.view`, `reports.view`, `assets.view`, `petty_cash.view`, `admin` — a treasurer with only `finance.reports.view` gets no Finance entry at all although `/finance/reports/*` authorises them. **Fix:** gate = any section visible.
- [P2] Icon reuse: `Receipt` used for Payables, Recurring Charges AND Tax & Compliance; `DollarSign` for Billing and Receivables; `BookOpen` for General Ledger and Price Books. Give each section a distinct lucide icon (Overview `LayoutDashboard`, Ledger `BookOpen`, Payables `Receipt`, Receivables `Banknote`, Banking `Landmark`, Tax `Percent`, Reports `BarChart3`, Settings `Settings`).

## Server-side notes for the plan

- `FinanceHubCountsService` keys mirror the `*_TABS` ids — when `finance-sections.ts` is created, keep its tab keys identical (`accounts, journals, cost-centres, fiscal-periods, fixed-assets, bills, purchase-orders, vendors, credit-notes, payment-runs, invoices, quotes, recurring-charges, billing, price-books, allocations, accounts(bank), transactions, petty-cash, match-rules, gst-returns, ird-filings, audit-exports`) so counts flow unchanged. Note the collision: `ledger.accounts` and `banking.accounts` share the key `accounts` — fine because counts are keyed hub → tab.
- Hub landing controllers (`LedgerController`, `PayablesController`, `BankingController`, `TaxController`, `ReportsController`, `SettingsController`) redirect to the first permitted tab — keep; `finance-sections.ts` tab order must match their redirect order (or better, have the controllers read one PHP-side list… out of scope; just keep them consistent and note it in a comment).
- `can.finance` tree (`HandleInertiaRequests` L1081–1115) is sufficient for every `visible()` predicate above; no new permissions needed. Ship no new RBAC keys (rule: new keys need a grant migration).

## Open decisions for the owner

- **D1** Receivables rail at 8 views: fold Statements under Aged AR (tier-2) → 7? (Recommended yes.)
- **D2** Move Match rules from Banking to Settings → Banking 7 views? (Recommended yes.)
- **D3** Reports rail: 9 flat views vs 5 views with tier-2 strips (Statements: P&L/BS/TB/CF; Ageing: AR/AP)? (Recommended the 5-view shape.)
- **D4** Calendar in the Overview rail vs standalone sidebar entry under Finance? (Recommended: Overview rail view + no separate sidebar link, matching Governance where Calendar is a hub tab.)
- **D5** (withdrawn — `@fullcalendar/*` is still used by the HR, client and portal calendars, which are outside this audit; only `components/calendar/calendar-view.tsx` becomes orphaned and is deleted.)
