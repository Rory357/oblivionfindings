# Finance design audit — Payables + Receivables findings

Inventory agent output (Sonnet, 2026-09-16), saved verbatim by the lead auditor; lead verification notes are in `audit.md`.

## Area summary
- Files audited (30 pages + 9 dialogs): vendors/{Index,Show,Create,Edit}, purchase-orders/{Index,Show,Create,Edit}, bills/{Index,Show,Create,Edit}, credit-notes/{Index,Show}, payment-runs/{Index,Show,Create}, payment-allocations/Index, receivables/{Index,Aging,Statements}, billing/{Index,Entries}, invoices/{Index,Show,Create,Edit}, quotes/{Index,Show}, price-books/{Index,Show}, recurring-charges/Index; dialogs new-bill/new-invoice/new-po/new-vendor/credit-note/quote/price-book/recurring-charge/record-receipt.
- Hub → views observed: **Payables hub** (`payables-hub.tsx`, 5 tabs) = bills · purchase-orders · vendors · credit-notes · payment-runs. **Receivables hub** (`receivables-hub.tsx`, 8 tabs) = invoices · quotes · recurring-charges · billing · aged-ar (→`/finance/receivables`) · statements · price-books · allocations (→`/finance/payment-allocations`).
- Dead/duplicate files:
  - **Dead routed pages** (route exists, nothing in the app links to it — index pages open the dialog instead): `vendors/Create.tsx` (`routes/finance.php:218`), `purchase-orders/Create.tsx` (`:238`), `bills/Create.tsx` (`:264`), `invoices/Create.tsx` (`:683`). Confirmed no in-app `href`/`Link` to any of the four `*/create` URLs — only the pages' own breadcrumb self-reference. `price-books/create`, `quotes/create`, `recurring-charges/create` don't have Create.tsx pages at all — the routes are server-side redirects back to Index (`routes/finance.php:367,387,410`), so those three modules are already fully migrated off the routed-page pattern.
  - **Dead page**: `billing/Entries.tsx` — route `finance.billing.entries` exists (`routes/finance.php:360`) but nothing in `resources/js` links to `/finance/billing/entries`; the page's own "View in Billing" button (line 227) goes the other way (back to `/finance/billing`). Unreachable from the UI.
  - **Live-but-duplicated edit paths**: `bills/Show.tsx:171` and `invoices/Show.tsx` link to the routed `*/edit` full pages, but `bills/Index.tsx` and `invoices/Index.tsx` already reuse `NewBillDialog`/`NewInvoiceDialog` with a `bill=`/`invoice=` prop for the exact same draft-edit job (Index.tsx:545-555 and 574-583 respectively). Two different UIs edit the same draft record depending on whether you start from the list or the detail page.
  - **Duplicate "record a payment" implementation**: `receivables/Index.tsx` hand-rolls its own `PaymentDialog` (lines 69-158) posting to `/finance/receivables/allocate` with the same fields as the shared `RecordReceiptDialog` (`components/finance/record-receipt-dialog.tsx`, used by `invoices/Index.tsx` and the Finance Dashboard) — same endpoint, same `invoice_id/amount/payment_date/notes`, but reimplemented as a plain `Dialog` instead of the wizard, and it sends an extra client-generated `idempotency_key` the shared component doesn't send.
  - `receivables/Aging.tsx` is reachable (linked from `receivables/Index.tsx:200`) but is **not** one of the 8 canonical Receivables-hub tabs (`aged-ar`'s href is `/finance/receivables`, i.e. Index — not Aging). Aging duplicates the bucketed-total numbers already on its own summary cards/table/footer (see below) and, unlike every other AR sub-page, renders no `ReceivablesTabsFooter` rail — a hub dead-end reachable only by its own "Back to Receivables" button.
- Cross-page patterns worth one shared fix:
  - **7 pages ship zero breadcrumbs**: `billing/Index.tsx`, `billing/Entries.tsx`, `quotes/Index.tsx`, `quotes/Show.tsx`, `price-books/Index.tsx`, `price-books/Show.tsx`, `recurring-charges/Index.tsx` all call `<AppLayout>` with no `breadcrumbs` prop at all (not just non-Home-rooted — completely absent). These 7 also use `<PageHero>` + `<PageShell>` directly instead of `<PageLayout hero={...}>`, a second, divergent page-composition pattern from the rest of Payables/Receivables.
  - **Stat duplication**: nearly every page repeats the same 3-4 numbers three times — once in `PageHero.stats`, once in a `FinanceSummaryCard`/`OpsStatCard` grid immediately below, and (Aging, Statements, allocations) again in the table body/footer. Aging is worst: 4 hero stats + 6 bucket cards + a table row-per-client + a grand-total footer row all show the same 6 numbers.
  - **Hand-rolled status/type badges instead of `StatusBadge`**: `vendors/Index.tsx:342-353,365-380` and `vendors/Show.tsx` (`vendorTypeColors`/`billStatusColors`/`poStatusColors` lookup tables), `bills/Show.tsx:150,340` (Overdue + journal-status `Badge`), `credit-notes/Index.tsx:80-91,361-372` and `credit-notes/Show.tsx:78-87,120-129` (`typeConfig` AP/AR badge with a raw `dark:bg-primary dark:text-primary/70` pair), `billing/Entries.tsx:193-198` (status shown via `Badge variant="outline" capitalize`), `price-books/Index.tsx:230-241` and `price-books/Show.tsx:98,366-377` (Active/Inactive `Badge`), `recurring-charges/Index.tsx:235-246` (Active/Inactive `Badge`), `payment-allocations/Index.tsx:202-207` (type `Badge`). `vendors`, `credit-notes`, `billing`, `price-books`, `recurring-charges`, `payment-allocations` never import `StatusBadge` at all — only `purchase-orders`, `bills` (mostly), `payment-runs`, `invoices` (mostly) and `quotes` are converted.
  - **Inconsistent confirmation on GL-posting approvals**: `purchase-orders/Show.tsx` (Approve, Convert-to-bill) and `invoices/Show.tsx` (Send, Mark Paid) wrap the action in `<ConfirmDialog>`; `bills/Show.tsx:177` (Approve) and `credit-notes/Show.tsx:135` (Approve) fire `router.post` immediately on click with no confirmation at all, even though bill/credit-note approval posts a GL journal same as PO approval.
  - **`window.prompt()` used as the entire UI** for four bank-settlement actions on `payment-runs/Show.tsx` (`handleAccept` 112-128, `handleReject` 138-156, `handleReconcile` 158-178, plus a chained prompt in `handleSettle`) — raw browser prompts collecting bank references/evidence digests with no validation, no `Field`/`Label`, and no `WizardShell`/`Dialog`, for what the code comments describe as compliance-grade "immutable evidence" capture.
  - **Missing pagination UI despite paginated data**: `payment-allocations/Index.tsx` types `allocations.links`/`last_page` (lines 39-44) but never renders a pager — only page 1 is ever reachable.
  - Everywhere a raw `Table`/`Card` list is used, row menus mix inline "Edit" ghost buttons in the last cell (bills, invoices Index) with a separate right-click `useRowContextMenu` that only ever offers "Open" (+"Edit" where inline exists) — none of the 7 `ctx=2` pages have more than 2 menu items, so none currently need `separator`/`danger`.
- Counts: **P0 24 · P1 41 · P2 15**

## Pages

### `/finance/vendors` — `resources/js/pages/finance/vendors/Index.tsx` (index, 436 lines)
**Current hero:** title "Vendors", description "Manage your suppliers, contractors and service providers"; stats: Total→`vendors.total`, Active (this page)→`vendors.data.filter(is_active)` (page-local count mislabelled as if global); actions: Export CSV (`/finance/vendors/export`), Add Vendor→opens `NewVendorDialog` (155-188); footer `PayablesTabsFooter active="vendors"`.
**Breadcrumbs:** present but not Home-rooted: `[Finance /finance, Vendors /finance/vendors]` → needs `[Home /dashboard, Finance /finance, Payables, Vendors]`.
**Meter blocks:** | Block | Source | Form | Tone | Links to |
|---|---|---|---|---|
| Total vendors | `vendors.total` | Stat | neutral | self |
| Active | `vendors.data` active count (needs a real server-side count, not page-local) | Stat | success | filtered list |
| Contractors/Suppliers split | `vendor_type` counts (not currently computed server-side) | Donut | neutral | filtered list |
| Bills outstanding via vendors | not available on this page today | — | — | — |
Only 2 honest numbers exist today; a 3rd/4th need new backend counts (or drop to 2-3 blocks).
**Header filters:** search (196-207), type Select (209-242), active Select (243-268) — all in a `Card` below the hero (191-274) → move to `PageHeaderFilterSelect`/`PageHeaderSearch`.
**In-page tabs:** none (hub-level only, via `PayablesTabsFooter`).
**List contract:** `Table` (308-385); columns Name(link)/Trading Name/Type(Badge)/Email/Phone/Bills(count)/Status(Badge); row actions: right-click ctx menu = `[Open]` only (139-149), no inline row actions; pagination = hand-rolled `Button` loop (391-422), not `LaravelPagination`; empty state uses `EmptyList`/`EmptySearch` (already correct pattern). Propose `EntityTable`: identity = name+trading name (`EntityChip`), Type → `EntityChip`, Bills → `CounterPill`, Status → `EntityStatusChip`. No bulk actions/selection.
**Create/Edit:** Create is **dead** (`routes/finance.php:218`, no in-app link — only the page's own breadcrumb). Live edit is `vendors/Edit.tsx`, linked from `vendors/Show.tsx:178`. `NewVendorDialog` (used here for create only) is missing vs. `Edit.tsx`: `trading_name`, full address (`address_line_1/2, city, region, postal_code`), `bank_account_number`, `is_active` toggle, and the repeatable `contacts[]` (name/role/email/phone/is_primary) block — all present in `vendors/Edit.tsx:60-141,347-504` but absent from `new-vendor-dialog.tsx`. The dialog would need a 3rd step (Address + Bank + Contacts) to fully replace Edit.
**Findings:**
- [P0] Contract — page top uses `PageHero`, not `PageHeaderStatusChip`/meter row/filter row. `vendors/Index.tsx:157-188`.
- [P0] Breadcrumbs not Home-rooted. `vendors/Index.tsx:84-87`.
- [P0] List uses raw `Table`, not `EntityTable`/`LaravelPagination`/`ListCaption`. `vendors/Index.tsx:308-422`.
- [P1] Hand-rolled type/status `Badge` + colour lookup tables instead of `StatusBadge`. `vendors/Index.tsx:68-82,342-353,365-380`.
- [P1] "Active (this page)" stat label admits it's not a real total — misleading KPI. `vendors/Index.tsx:164`.
**Workflow/wiring:** Create.tsx is dead weight (verified: no `href`/`Link` to `/finance/vendors/create` anywhere in `resources/js`, only `routes/finance.php:218-219`).

### `/finance/vendors/{id}` — `resources/js/pages/finance/vendors/Show.tsx` (record, 518 lines)
**Current hero:** compact variant, title = vendor name + Active/Inactive `Badge` (154-167), description = trading name, action = Edit→`/finance/vendors/{id}/edit` (176-183).
**Breadcrumbs:** Home-rooted trail needed: `[Home, Finance, Payables, Vendors /finance/vendors, {vendor.name}]`; today `[Finance, Vendors, {name}]` (135-139).
**Meter blocks:** | Block | Source | Form | Tone | Links to |
|---|---|---|---|---|
| Total outstanding | `totalOutstanding` | Stat | warning | bills filtered by vendor |
| Total paid YTD | `totalPaidYtd` | Stat | success | bills filtered by vendor |
| Open bills | `bills.length` | Stat | neutral | bills tab |
| Open POs | `purchaseOrders.length` | Stat | neutral | PO tab |
**Header filters:** none (record page — correct, no filters expected here).
**In-page tabs:** none today; page is one long scroll of Details/Contacts/Recent Bills/Recent POs/Financial Summary cards (188-514) → candidates for `TierTwoTabs` (Details · Contacts · Bills · Purchase orders).
**List contract:** three inline `Table`s (Contacts 296-331, Bills 350-408, POs 429-479), no pagination (both "Recent" lists are unbounded arrays from the controller, not paginated) — needs `EntityTable` + real pagination or an explicit "recent N, see all" link.
**Create/Edit:** N/A (this is the Show/detail page; edit lives at `vendors/Edit.tsx`, see Index findings above for the dialog gap).
**Findings:**
- [P0] `PageHero` not `PageHeader`; no meter row/filter row. `vendors/Show.tsx:147-185`.
- [P1] Hand-rolled Active/Inactive `Badge`, bill-status colour map, PO-status colour map instead of `StatusBadge`. `vendors/Show.tsx:74-106,154-167,391-403,462-474`.
- [P1] "Recent Bills"/"Recent Purchase Orders" tables have no pagination and no "view all" — could silently truncate a vendor's history.
**Workflow/wiring:** Edit link (178) confirmed live and correct.

### `/finance/vendors/create` — `resources/js/pages/finance/vendors/Create.tsx` (routed-wizard, 654 lines) — **DEAD**
No `href`/`Link` anywhere in `resources/js` points at `/finance/vendors/create` (only its own breadcrumb self-link, line 117, and the Laravel route). `NewVendorDialog` fully replaces its "create" job from the Index page. **Recommend deleting the route+page** once Edit's dialog gap (above) is closed, or keep only as a fallback if direct-link/bookmark support is required.

### `/finance/vendors/{id}/edit` — `resources/js/pages/finance/vendors/Edit.tsx` (routed-wizard, 721 lines) — **LIVE**
Linked from `vendors/Show.tsx:178`. Full field set: name, trading_name, vendor_type, gst_number, email, phone, 5 address fields, payment_terms_days, bank_account_number, default_expense_account_id, `is_active` toggle (139-151), notes, repeatable contacts (347-504). This is the complete feature set `NewVendorDialog` needs to grow into (see Index gap analysis) before Edit can be retired in favour of the dialog.
**Findings:**
- [P0] Routed full-page edit, not a `WizardShell` dialog opened from Index/Show. `vendors/Edit.tsx` (whole file).
- [P0] No breadcrumbs rooted at Home. `vendors/Edit.tsx:160-165`.

### `/finance/purchase-orders` — `resources/js/pages/finance/purchase-orders/Index.tsx` (index, 355 lines)
**Current hero:** title "Purchase Orders"; stats: Total→`purchaseOrders.total ?? rows.length` (only 1 stat — no honest 2nd-4th number is currently returned by the controller); actions: Export CSV, New Purchase Order→`NewPoDialog` (105-141); footer `PayablesTabsFooter active="purchase-orders"`.
**Breadcrumbs:** not Home-rooted: `[Finance, Purchase Orders]` (51-54).
**Meter blocks:** only 1 honest number today (Total). Would need new backend fields for Draft count / Approved-awaiting-conversion count / This-month total to reach 4.
**Header filters:** Status Select (150-176), Vendor Select (179-206), Search input (208-217) — all in a `Card` (143-219).
**In-page tabs:** none.
**List contract:** `Table` (223-309); columns PO#/Vendor/Order Date/Expected Date/Total/Status(`StatusBadge`, already correct); ctx menu = `[Open]` only (93-100); pagination hand-rolled (313-340); empty state via `EmptyList`/`EmptySearch`. Propose `EntityTable`: identity = PO# (`EntityChip`), Status → `EntityStatusChip`, Total → money cell.
**Create/Edit:** Create is **dead** (`routes/finance.php:238`, no in-app link). Live edit is `purchase-orders/Edit.tsx`, linked from `Show.tsx:128`. Gap vs `NewPoDialog`: the dialog (`new-po-dialog.tsx`) has **no Cost Centre or Funding Stream fields at all** (grepped — zero matches for `cost_centre`/`funding_stream`), while `Edit.tsx:99-104,258-309` lets you set both at the PO header level. A PO created via the dialog can never carry cost-centre/funding-stream attribution unless later edited on the routed page.
**Findings:**
- [P0] `PageHero`/raw filter Card/raw Table — full contract break. `purchase-orders/Index.tsx:107-219,223-309`.
- [P0] Breadcrumbs not Home-rooted. `purchase-orders/Index.tsx:51-54`.
- [P1] Only 1 meter block possible with current data — flag for owner rather than fabricate 3 more.
**Workflow/wiring:** Create confirmed dead (`routes/finance.php:238-239`, no `href`).

### `/finance/purchase-orders/{id}` — `resources/js/pages/finance/purchase-orders/Show.tsx` (record, 388 lines)
**Current hero:** compact, title = PO# + `StatusBadge` (117-122), description = vendor name, actions = Edit (126-132, only if `status==='draft'`), Approve (133-141, ConfirmDialog-gated), Convert to Bill (142-151, ConfirmDialog-gated).
**Breadcrumbs:** `[Finance, Purchase Orders, {po_number} → href:'#']` (104-108) — the last crumb's `href: '#'` is a dead link (should be the PO's own URL or omitted as the current page).
**Meter blocks:** | Block | Source | Form | Tone | Links to |
|---|---|---|---|---|
| Total | `po.total_amount` | Stat | neutral | self |
| GST | `po.gst_amount` | Stat | neutral | self |
| Linked bills | `po.bills.length` | Stat | neutral | bills tab |
| Days to expected | `po.expected_date` vs today (needs computing) | Stat | warning | self |
**List contract:** Line Items `Table` (233-316, no pagination needed — bounded), Linked Bills `Table` (326-363, `StatusBadge` correct).
**Findings:**
- [P0] Breadcrumb href `'#'` for the current page. `purchase-orders/Show.tsx:107`.
- [P0] `PageHero`, no meter row. `purchase-orders/Show.tsx:113-154`.
**Workflow/wiring:** Approve/Convert both correctly `ConfirmDialog`-gated (368-385) — this is the pattern bills/credit-notes should match.

### `/finance/purchase-orders/create` — `Create.tsx` (routed-wizard, 512 lines) — **DEAD** (`routes/finance.php:238-239`, no in-app link).

### `/finance/purchase-orders/{id}/edit` — `Edit.tsx` (routed-wizard, 559 lines) — **LIVE**, linked from `Show.tsx:128`. Full per-line editing (description/qty/unit_price/gst_rate/account_id), header Cost Centre + Funding Stream selects (258-309), Notes — the field set `NewPoDialog` is missing.
**Findings:**
- [P0] Routed full-page edit instead of dialog. `purchase-orders/Edit.tsx` (whole file).
- [P0] Breadcrumb `Edit` crumb href `'#'`. `purchase-orders/Edit.tsx:181`.

### `/finance/bills` — `resources/js/pages/finance/bills/Index.tsx` (index, 558 lines)
**Current hero:** title "Bills"; stats: Total unpaid/Overdue/Due this week (all `formatMoney(summary.*)`, 209-221 — 3 honest money stats); actions: Export CSV, New Bill→`NewBillDialog` (202-246); footer `PayablesTabsFooter active="bills"`.
**Breadcrumbs:** not Home-rooted `[Finance, Bills]` (117-120).
**Meter blocks:** | Block | Source | Form | Tone | Links to |
|---|---|---|---|---|
| Total unpaid | `summary.total_unpaid` | Stat | info | filtered list (status≠paid) |
| Overdue | `summary.total_overdue` | Stat | critical | filtered list (overdue) |
| Due this week | `summary.due_this_week` | Stat | warning | filtered list |
| Bills this page | `bills.data.length`/`bills.total` (not returned) | Stat | neutral | self |
**Header filters:** search/status/vendor/date-from/date-to, all in a filter `Card` (270-361).
**List contract:** `Table` (364-504); columns Bill#/Vendor Ref/Vendor/Bill Date/Due Date(overdue-highlighted row, 429-433,461-473)/Total/Paid/Status(`StatusBadge`)/Actions(inline "Edit" ghost button for drafts, 484-499); ctx menu = `[Open, Edit(draft only)]` (177-196); pagination hand-rolled (506-529). Propose `EntityTable`: identity=Bill#, overdue → alert chip not row-tinted background, Status→`EntityStatusChip`.
**Create/Edit:** Create is **dead** (`routes/finance.php:264`, no in-app link). Edit is **dual-path**: `Index.tsx` reuses `NewBillDialog` inline (485-497, 545-555) for draft edits, **and** `bills/Show.tsx:171` links to the fully separate routed `bills/Edit.tsx`. `NewBillDialog` gaps vs `Edit.tsx`: no `purchase_order_id` linking (Edit.tsx:303-320), no per-line `cost_centre_id`/`funding_stream_id` (Edit.tsx:553-601; dialog only has account_id+gst_rate per line). Dialog does have `spend_approval_id` and `vendor_reference` that Edit also has.
**Findings:**
- [P0] `PageHero`/raw filter Card/raw Table. `bills/Index.tsx:202-361,364-504`.
- [P0] Breadcrumbs not Home-rooted. `bills/Index.tsx:117-120`.
- [P1] `dark:` pair violations (2). `bills/Index.tsx:432,468`.
- [P1] Two different UIs edit the same draft bill depending on entry point (Index dialog vs. Show→routed Edit).
**Workflow/wiring:** Create dead; dual edit-path is a real workflow confusion risk.

### `/finance/bills/{id}` — `resources/js/pages/finance/bills/Show.tsx` (record, 498 lines)
**Current hero:** compact, title = bill# + `StatusBadge` + hand-rolled Overdue `Badge` (146-154), description = vendor + ref, actions = Edit(draft, 169-176, routed page)/Approve(177-180, **no confirm**)/Cancel(183-190, `ConfirmDialog`).
**Breadcrumbs:** `[Finance, Bills, {bill_number}]` (131-135) — not Home-rooted.
**Meter blocks:** | Block | Source | Form | Tone | Links to |
|---|---|---|---|---|
| Total | `bill.total_amount` | Stat | neutral | self |
| Amount due | computed `total-paid` | Stat | critical/success | self |
| GST | `bill.gst_amount` | Stat | neutral | self |
| Payments made | `bill.payment_allocations.length` | Stat | neutral | payment history section |
**List contract:** Line Items `Table` (374-435, includes Cost Centre/Funding Stream columns per line — richer than the dialog can produce), Payment History `Table` (445-473).
**Findings:**
- [P0] `PageHero`, no meter row, breadcrumb not Home-rooted. `bills/Show.tsx:141-195,131-135`.
- [P1] Approve fires immediately with no `ConfirmDialog`, unlike Cancel on the same page and Approve on `purchase-orders/Show.tsx`. `bills/Show.tsx:112-114,177-180`.
- [P1] Hand-rolled Overdue `Badge` and journal-status `Badge` instead of `StatusBadge`. `bills/Show.tsx:150-153,340-342`.
**Workflow/wiring:** Edit link (171) confirmed live, goes to routed `bills/Edit.tsx` (duplicate of the Index dialog path).

### `/finance/bills/create` — `Create.tsx` (routed-wizard, 730 lines) — **DEAD** (`routes/finance.php:264-265`, no in-app link).

### `/finance/bills/{id}/edit` — `Edit.tsx` (routed-wizard, 707 lines) — **LIVE**, linked from `Show.tsx:171`. Per-line `cost_centre_id`/`funding_stream_id` (553-601), `purchase_order_id` header field (303-320), `TaxRate` support — all missing from `NewBillDialog`.
**Findings:**
- [P0] Routed full-page edit instead of reusing/extending the dialog. `bills/Edit.tsx` (whole file).

### `/finance/credit-notes` — `resources/js/pages/finance/credit-notes/Index.tsx` (index, 443 lines)
**Current hero:** title "Credit Notes"; stats: Total (this page)→`creditNotes.data.length`, AP→`payableCount`, AR→`receivableCount` — **both AP/AR counts are computed from the current page's data only** (145-150), not a real total; actions: Export CSV, New Credit Note→`CreditNoteDialog`; footer `PayablesTabsFooter active="credit-notes"`.
**Breadcrumbs:** `[Finance, Credit Notes]` (93-96) — not Home-rooted.
**Meter blocks:** | Block | Source | Form | Tone | Links to |
|---|---|---|---|---|
| Total (needs real `creditNotes.total`, not `.data.length`) | — | Stat | neutral | self |
| AP total (needs a real payable-count from backend) | — | Stat | primary | filtered `type=payable` |
| AR total (needs a real receivable-count) | — | Stat | info | filtered `type=receivable` |
| Draft awaiting approval | not returned today | Stat | warning | filtered `status=draft` |
**Header filters:** search/type/status/date-from/date-to in a filter `Card` (210-291).
**List contract:** `Table` (323-395); columns CN#/Type(hand-rolled Badge)/Vendor-Client/Date/Total/Status(`StatusBadge`); ctx menu=`[Open]` only (155-163); pagination hand-rolled (398-424).
**Create/Edit:** No routed Create/Edit pages exist for credit notes (Index+Show only) — `CreditNoteDialog` is the sole create path and there is no edit path at all (credit notes can only be approved, never edited, once created — confirm this is intentional with the owner).
**Findings:**
- [P0] `PageHero`/raw filter Card/raw Table. `credit-notes/Index.tsx:169-291,323-395`.
- [P0] Breadcrumbs not Home-rooted. `credit-notes/Index.tsx:93-96`.
- [P1] `dark:` pair violations (2) in the AP/AR badge classNames. `credit-notes/Index.tsx:84,89`.
- [P1] AP/AR stats computed from the current page of results, not a real total. `credit-notes/Index.tsx:145-150,176-183`.
- [P1] Hand-rolled type `Badge` instead of `StatusBadge`/`EntityChip`. `credit-notes/Index.tsx:80-91,360-372`.

### `/finance/credit-notes/{id}` — `resources/js/pages/finance/credit-notes/Show.tsx` (record, 348 lines)
**Current hero:** compact, title = CN# + `StatusBadge` + type `Badge` (116-130), description = vendor name, action = Approve (draft only, 134-139, **no ConfirmDialog anywhere in this file**).
**Breadcrumbs:** `[Finance, Credit Notes, {credit_note_number}]` (99-106) — not Home-rooted.
**Meter blocks:** Subtotal/GST/Total (Amounts card, 205-232) are the only 3 numbers; a 4th isn't available today.
**List contract:** Line Items `Table` (294-343), no pagination needed (bounded).
**Findings:**
- [P0] `PageHero`, no meter row, breadcrumbs not Home-rooted. `credit-notes/Show.tsx:110-142,99-106`.
- [P1] Approve fires immediately, no confirmation, despite posting a GL journal. `credit-notes/Show.tsx:92-94,134-139`.
- [P1] Hand-rolled type `Badge` + journal-status `Badge`. `credit-notes/Show.tsx:78-87,120-129,259-261`.

### `/finance/payment-runs` — `resources/js/pages/finance/payment-runs/Index.tsx` (index, 326 lines)
**Current hero:** title "Payment Runs"; stats Total/Settled/Awaiting bank/Drafts (all page-local counts except Total, 115-123); actions: Export CSV, **New Payment Run→routed `/finance/payment-runs/create`** (134-139, no dialog exists for this module); footer `PayablesTabsFooter active="payment-runs"`.
**Breadcrumbs:** `[Finance, Payment Runs]` (57-60) — not Home-rooted.
**Header filters:** single Status `Select` with an 11-option list (146-199) including **developer-jargon options "Legacy processing" and "Legacy completed"** shown verbatim to finance officers.
**List contract:** `Table` (228-291); columns Run#/Payment Date/Bank Account/Items/Total/Status(`StatusBadge`)/Processed At; ctx menu=`[Open]` only (95-102); pagination hand-rolled (293-316).
**Create/Edit:** **No dialog exists** — the one Payables module still fully on the routed-page pattern by design. `Create.tsx` is a full bill-selection UI (bank account, payment date, notes, multi-select bill table with running total) that doesn't map cleanly onto `WizardShell` without a dedicated "pick many records" step type.
**Findings:**
- [P0] `PageHero`/raw Table; "New Payment Run" opens a routed page, not a dialog. `payment-runs/Index.tsx:105-144,228-291`.
- [P0] Breadcrumbs not Home-rooted. `payment-runs/Index.tsx:57-60`.
- [P2] "Legacy processing"/"Legacy completed" developer language shown to users. `payment-runs/Index.tsx:171-174,192-194`.
**Workflow/wiring:** Both "New Payment Run" entry points (134, 218) go to the same routed create page.

### `/finance/payment-runs/create` — `Create.tsx` (routed-wizard, 351 lines) — **LIVE**, only entry point into creating a payment run. Bank account + payment date + notes + multi-select bill picker with live running total (65-347). No `WizardShell` equivalent exists; would need a bespoke "select records" step if migrated to a dialog.

### `/finance/payment-runs/{id}` — `resources/js/pages/finance/payment-runs/Show.tsx` (record, 498 lines)
**Current hero:** compact, title=run#, up to 7 conditional status-driven action buttons (192-266).
**Breadcrumbs:** `[Finance, Payment Runs, {run_number}]` (77-84) — not Home-rooted.
**List contract:** Payment Items `Table` (422-472), `StatusBadge` per item — already correct pattern.
**Findings:**
- [P0] `PageHero`, no meter row, breadcrumbs not Home-rooted. `payment-runs/Show.tsx:184-267,77-84`.
- [P0] **`window.prompt()` used for 4 compliance-grade actions** — Accept (112-128), Reject (138-156), Reconcile (158-178), and Settle has no dialog at all (130-136). Each prompt collects free-text "reference"/"evidence digest" with zero validation and silently returns if cancelled mid-sequence. Should be a proper `Dialog`/`WizardShell` form.
- [P1] Approve/Prepare-file correctly use `ConfirmDialog` (478-495) — inconsistent with the 4 prompt-based actions above.

### `/finance/payment-allocations` — `resources/js/pages/finance/payment-allocations/Index.tsx` (index, 235 lines)
**Current hero:** title "Payment Allocations"; stats Allocations/Total(this page) (84-89); action = type filter Select styled as a header pill (92-124); footer `ReceivablesTabsFooter active="allocations"`.
**Breadcrumbs:** `[Finance, Payment Allocations]` (52-55) — not Home-rooted.
**List contract:** `Table` (164-231); columns Date/Type(Badge)/Target(`allocatable_type`+`allocatable_id` shown raw)/Amount/Notes; no row actions/ctx menu; **no pagination controls despite `allocations.links`/`last_page` being typed and present in props** (37-44) — only page 1 is ever visible.
**Findings:**
- [P0] `PageHero`, duplicate stat cards (130-161 repeat the hero stats), raw Table. `payment-allocations/Index.tsx:77-128,130-231`.
- [P0] Breadcrumbs not Home-rooted. `payment-allocations/Index.tsx:52-55`.
- [P0] **Pagination data is fetched but never rendered.** `payment-allocations/Index.tsx:37-44`.
- [P2] `allocatable_type` rendered raw instead of a friendly label. `payment-allocations/Index.tsx:209-214`.

## Receivables

### `/finance/receivables` (Aged AR tab) — `resources/js/pages/finance/receivables/Index.tsx` (index, 479 lines)
**Current hero:** title "Receivables"; stats Outstanding/Overdue/Unpaid invoices (184-197); actions: Aging Report link (200-209), Statements link (210-219); footer `ReceivablesTabsFooter active="aged-ar"`.
**Breadcrumbs:** `[Finance, Receivables]` (64-67) — not Home-rooted.
**Meter blocks:** the 3 hero stats are duplicated a 2nd time as 3 more `Card`s (227-268), plus a 4th pie-chart card showing the same split (270-327) — 4 blocks achievable honestly, triplicated visually today.
**List contract:** `Table` (343-472); columns Invoice#/Client/Issue/Due/Total/Paid/Due/Status(hand-rolled destructive/secondary `Badge`, 404-414)/Actions (per-row "Record Payment" opens a **duplicate, hand-rolled** `PaymentDialog`, 416-467, instead of the shared `RecordReceiptDialog`); no pagination (plain array, not paginated).
**Findings:**
- [P0] `PageHero`, raw Table, no filter row. `receivables/Index.tsx:177-224,331-475`.
- [P0] Breadcrumbs not Home-rooted. `receivables/Index.tsx:64-67`.
- [P1] Hand-rolled status `Badge` instead of `StatusBadge`. `receivables/Index.tsx:404-414`.
- [P1] Duplicate "record payment" dialog reimplementing `RecordReceiptDialog`. `receivables/Index.tsx:69-158`.
- [P1] Invoice list has no pagination. `receivables/Index.tsx:337-341`.

### `/finance/receivables/aging` — `resources/js/pages/finance/receivables/Aging.tsx` (report, 275 lines)
**Current hero:** title "Aged Receivables"; stats Total/Current/31-90/90+ (92-111); action = Back to Receivables link (112-122); **no `ReceivablesTabsFooter`** — the only Receivables sub-page without the hub rail.
**Breadcrumbs:** `[Finance, Accounts Receivable /finance/receivables, Aging Report]` (60-64) — not Home-rooted but at least a real 3-level trail.
**Meter blocks:** the same 6 bucket totals shown 3 times: hero stats, a 6-card bucket grid (127-164), and the table's own footer row (235-267).
**List contract:** `Table` with `TableFooter` (179-268); no pagination, no row actions/ctx menu (rows aren't links to a client).
**Findings:**
- [P0] `PageHero`, raw Table, no filter row. `receivables/Aging.tsx:85-124,167-271`.
- [P0] Breadcrumbs not Home-rooted. `receivables/Aging.tsx:60-64`.
- [P1] `dark:` pairs (5) in `bucketColors`. `receivables/Aging.tsx:44-49`.
- [P1] Missing `ReceivablesTabsFooter` and not one of the 8 canonical tab destinations — breaks hub navigability once you're here.
- [P1] Triple-duplicated bucket totals.
- [P2] Client rows aren't links to the client/invoice detail.

### `/finance/receivables/statements` — `resources/js/pages/finance/receivables/Statements.tsx` (tool, 361 lines)
**Current hero:** title "Client Statements"; stats conditional on a selected client (141-158); footer `ReceivablesTabsFooter active="statements"` (correctly wired, unlike Aging).
**Breadcrumbs:** `[Finance, Accounts Receivable, Statements]` (122-130) — not Home-rooted.
**Header filters:** Client Select + As-of-Date input (187-225), correctly scoped but rendered in a body `Card`, not the header filter row.
**List contract:** statement invoice `Table` with `TableFooter` grand total (288-352); print-only via `window.print()` (117-119).
**Findings:**
- [P0] `PageHero`, filters in a body Card. `receivables/Statements.tsx:133-177,180-239`.
- [P0] Breadcrumbs not Home-rooted. `receivables/Statements.tsx:122-130`.
- [P2] "Print / Download" button only triggers `window.print()` — mislabelled as Download. `receivables/Statements.tsx:228-235`.

### `/finance/billing` — `resources/js/pages/finance/billing/Index.tsx` (index, 356 lines)
**Current hero:** title "Billing"; stats Billed this month/Outstanding/Paid this month/Pending (109-120); footer `ReceivablesTabsFooter active="billing"`. Uses `<PageHero>`+`<PageShell>` directly, not `<PageLayout>`.
**Breadcrumbs:** **none at all** — `<AppLayout>` called with no `breadcrumbs` prop. `billing/Index.tsx:102`.
**Meter blocks:** the 4 hero stats are immediately duplicated as 4 `OpsStatCard`s (125-151).
**List contract:** entries shown as `div` rows inside a `Card` (280-324), not `EntityTable`/`EntityCard` — no columns header, ad-hoc flex rows; pagination hand-rolled (330-352); empty state is a plain `<p>`, not `EmptyState`.
**Create/Edit:** N/A — billing entries are auto-generated from approved timesheets, no create action exists (correct — no stub to hide).
**Findings:**
- [P0] No breadcrumbs at all. `billing/Index.tsx:102`.
- [P0] `PageHero`+`PageShell` instead of `PageHeader`/`PageLayout`; list is ad-hoc `div`s, not `EntityCard`. `billing/Index.tsx:104-355`.
- [P1] Duplicate stat rendering. `billing/Index.tsx:109-120,125-151`.
- [P1] Empty state is a bare `<p>`, not `EmptyState`. `billing/Index.tsx:274-278`.
**Workflow/wiring:** "Invoices" shortcut (222-226) confirmed live; the module has a second, unreachable page (`Entries.tsx`).

### `/finance/billing/entries` — `resources/js/pages/finance/billing/Entries.tsx` (index, 243 lines) — **DEAD**
No link anywhere in `resources/js` points at `/finance/billing/entries`. Route exists (`routes/finance.php:360`) but is unreachable. Its "View in Billing" button (224-234) doesn't link back to the specific entry — it just re-filters `/finance/billing` by status.
**Findings:**
- [P0] Entire page unreachable — dead route/page.
- [P0] No breadcrumbs. `billing/Entries.tsx:81`.
- [P1] Hand-rolled status `Badge`. `billing/Entries.tsx:193-198`.

### `/finance/invoices` — `resources/js/pages/finance/invoices/Index.tsx` (index, 586 lines)
**Current hero:** title "Invoices"; stats Outstanding/Overdue/Drafts/Paid this month (217-231, all real) — best-covered page in this area for meter data.
**Breadcrumbs:** `[Finance, Invoices]` (118-121) — not Home-rooted.
**List contract:** `Table` (356-518); ctx menu = `[Open, Edit(draft), Record receipt(if payable)]` (176-204) — the richest `RowCtxItem[]` set in this audit; pagination hand-rolled (520-543).
**Create/Edit:** Create is **dead** (`routes/finance.php:683`, no in-app link). Edit is **dual-path** exactly like Bills: `Index.tsx` reuses `NewInvoiceDialog` inline (565-583), **and** `invoices/Show.tsx` links to the routed `invoices/Edit.tsx`. `NewInvoiceDialog` gaps vs `Edit.tsx`: no `terms`, `email_subject`, `email_body` (Edit.tsx:123-125,587-647), no per-line `account_id` (dialog only has `tax_rate_id` per line).
**Findings:**
- [P0] `PageHero`/raw filter Card/raw Table. `invoices/Index.tsx:210-353,356-518`.
- [P0] Breadcrumbs not Home-rooted. `invoices/Index.tsx:118-121`.
- [P1] `dark:` pairs (2). `invoices/Index.tsx:416,454`.
- [P1] Two edit UIs for the same draft invoice — same issue as Bills.
**Workflow/wiring:** Create dead; correctly reuses `RecordReceiptDialog` (549-563, the canonical implementation).

### `/finance/invoices/{id}` — `resources/js/pages/finance/invoices/Show.tsx` (record, 487 lines)
**Current hero:** compact, title = invoice# + `StatusBadge` + Overdue `Badge` (146-155), actions = Edit(draft)/Download PDF/Send Email(`ConfirmDialog`)/Mark Paid(`ConfirmDialog`).
**Breadcrumbs:** `[Finance, Invoices, {invoice_number}]` (100-106) — not Home-rooted.
**Findings:**
- [P0] `PageHero`, breadcrumbs not Home-rooted. `invoices/Show.tsx:141-*,100-106`.
- [P1] `dark:` pairs (3), incl. a redundant one on the Overdue badge. `invoices/Show.tsx:154`.
- [P1] "Mark Paid" (one click, `ConfirmDialog`-gated) bypasses the proper receipt-allocation flow (`RecordReceiptDialog`) entirely — two different ways to close out an invoice's balance.
**Workflow/wiring:** Edit link confirmed live, goes to routed `invoices/Edit.tsx` (duplicate of Index dialog path).

### `/finance/invoices/create` — `Create.tsx` (routed-wizard, 848 lines) — **DEAD** (`routes/finance.php:683-684`, no in-app link).

### `/finance/invoices/{id}/edit` — `Edit.tsx` (routed-wizard, 671 lines) — **LIVE**, linked from `Show.tsx`. `terms`/`email_subject`/`email_body` + per-line `account_id` are the gaps vs. `NewInvoiceDialog`.

### `/finance/quotes` — `resources/js/pages/finance/quotes/Index.tsx` (index, 423 lines)
**Current hero:** title "Quotes"; stats Total/Pending/Accepted/Converted (168-173); footer `ReceivablesTabsFooter active="quotes"`. `PageHero`+`PageShell` pattern.
**Breadcrumbs:** **none at all.** `quotes/Index.tsx:161`.
**List contract:** `Card`-per-row list (290-372), closest in shape to `EntityCard` already; row actions = Eye/Pencil icon buttons (both have `aria-label`) + ctx menu `[Open, Edit(draft)]` (138-158); pagination hand-rolled (376-397).
**Create/Edit:** No routed Create/Edit pages (route redirects `/quotes/create`→Index). `QuoteDialog` handles both, **but its own code comment (`quote-dialog.tsx:87`) states it "never persists line changes" in edit mode** — editing an existing quote cannot change its line items through the UI at all, only header fields.
**Findings:**
- [P0] No breadcrumbs at all. `quotes/Index.tsx:161`.
- [P0] `PageHero`+`PageShell`, not `PageHeader`/`PageLayout`. `quotes/Index.tsx:161-421`.
- [P1] `dark:` pairs (2). `quotes/Index.tsx:297`.
- [P1] Duplicate stat rendering. `quotes/Index.tsx:168-173,188-212`.
**Workflow/wiring:** Editing line items on an existing quote is not possible via any UI path — confirm with owner.

### `/finance/quotes/{id}` — `resources/js/pages/finance/quotes/Show.tsx` (record, 386 lines)
**Current hero:** compact, title = quote title, description = client. `PageHero`+`PageShell`.
**Breadcrumbs:** **none at all.** `quotes/Show.tsx:75`.
**List contract:** plain HTML `<table>` (264-345), not the shared `Table` component nor `EntityTable`.
**Findings:**
- [P0] No breadcrumbs. `quotes/Show.tsx:75`.
- [P0] `PageHero`+`PageShell`; plain HTML table. `quotes/Show.tsx:77-83,264-345`.
- [P0] **"Edit" button doesn't edit** — for a draft quote it links to `/finance/quotes` (the Index page) instead of opening `QuoteDialog` pre-filled for this quote (99-104). The user lands back on the list and must find and click the row's own Edit again to actually edit.
**Workflow/wiring:** the broken Edit button is a real "would confuse a finance officer" bug.

### `/finance/price-books` — `resources/js/pages/finance/price-books/Index.tsx` (index, 350 lines)
**Current hero:** title "Price Books"; stats Total books/Active items/Default book (117-123); footer `ReceivablesTabsFooter active="price-books"`.
**Breadcrumbs:** **none at all.** `price-books/Index.tsx:110`.
**List contract:** `Card`-per-row list (213-299), hand-rolled Active/Inactive + Default `Badge`s (230-250); row actions = Eye/Pencil icon buttons + no ctx menu (confirmed, no `useRowContextMenu` import); pagination hand-rolled (303-330).
**Create/Edit:** No routed pages. `PriceBookDialog` handles both via Index and Show.
**Findings:**
- [P0] No breadcrumbs. `price-books/Index.tsx:110`.
- [P0] `PageHero`+`PageShell`. `price-books/Index.tsx:109-349`.
- [P1] `dark:` pair (1). `price-books/Index.tsx:219`.
- [P1] Duplicate stat rendering. `price-books/Index.tsx:117-123,129-148`.
- [P1] Hand-rolled Active/Inactive/Default `Badge`s. `price-books/Index.tsx:230-250`.
- [P1] No right-click context menu on this list (every other list in this audit has one).

### `/finance/price-books/{id}` — `resources/js/pages/finance/price-books/Show.tsx` (record, 407 lines)
**Breadcrumbs:** **none at all.** `price-books/Show.tsx:85`.
**List contract:** plain HTML `<table>` (313-384) for items; hand-rolled Active/Inactive `Badge` (366-377); **items can be added but never edited or deactivated once created** — "Add Item" is an inline expanding form (136-302) rather than a `Dialog`.
**Findings:**
- [P0] No breadcrumbs. `price-books/Show.tsx:85`.
- [P0] `PageHero`+`PageShell`; plain HTML table. `price-books/Show.tsx:87-93,313-384`.
- [P1] "Add Item" is an inline in-page form, not a `Dialog`. `price-books/Show.tsx:136-302`.
- [P1] Hand-rolled Active/Inactive `Badge`. `price-books/Show.tsx:98,366-377`.
- [P2] No way to edit/deactivate/remove a price-book item after adding it.

### `/finance/recurring-charges` — `resources/js/pages/finance/recurring-charges/Index.tsx` (index, 339 lines)
**Breadcrumbs:** **none at all.** `recurring-charges/Index.tsx:117`.
**List contract:** `Card`-per-row list (221-292), hand-rolled Active/Inactive + frequency `Badge`s (235-254); row action = single Pencil edit icon, no view/detail link at all (no Show page exists) and no ctx menu; pagination hand-rolled (296-317).
**Create/Edit:** No routed pages; `RecurringChargeDialog` handles both via Index.
**Findings:**
- [P0] No breadcrumbs. `recurring-charges/Index.tsx:117`.
- [P0] `PageHero`+`PageShell`. `recurring-charges/Index.tsx:116-338`.
- [P1] `dark:` pairs (2). `recurring-charges/Index.tsx:227,263`.
- [P1] Duplicate stat rendering. `recurring-charges/Index.tsx:124-131,136-155`.
- [P1] Hand-rolled Active/Inactive + frequency `Badge`s. `recurring-charges/Index.tsx:235-254`.
- [P2] No detail/history view for a charge and no right-click ctx menu.

## Dialogs

### `new-vendor-dialog.tsx` — 2-step `WizardShell`. Missing vs. `vendors/Edit.tsx`: `trading_name`, full address block, `bank_account_number`, `is_active` toggle, repeatable `contacts[]`. Needs a 3rd step for parity.

### `new-po-dialog.tsx` — Missing **Cost Centre** and **Funding Stream** header fields entirely (zero references) — present in `purchase-orders/Edit.tsx:99-104,258-309`.

### `new-bill-dialog.tsx` — Has `vendor_reference`, `notes`, `spend_approval_id`, per-line `account_id`+`gst_rate`. Missing: `purchase_order_id` linking, per-line `cost_centre_id`/`funding_stream_id`.

### `new-invoice-dialog.tsx` — Has `funding_body`/`bill_to` toggle, per-line `tax_rate_id`. Missing: `terms`, `email_subject`, `email_body`, per-line `account_id`.

### `credit-note-dialog.tsx` — No competing routed page; fields look complete for the credit-note-only (no-edit-after-create) workflow.

### `quote-dialog.tsx` — No competing routed page. **Self-documented limitation** (line 87 comment): edit mode "never persists line changes."

### `price-book-dialog.tsx` — Header-only fields; item management lives entirely on `Show.tsx`'s inline add-form (should itself be a `Dialog`) with no edit/remove for existing items.

### `recurring-charge-dialog.tsx` — No competing routed page; fields look complete for the charge header. No detail page exists.

### `record-receipt-dialog.tsx` — Canonical "record a receipt" implementation (2-step `WizardShell`). **Not used by `receivables/Index.tsx`**, which reimplements the same job as a plain `Dialog` with an extra client-generated `idempotency_key`.

## Open decisions for the owner
- Retire the 4 dead routed Create pages (vendors, purchase-orders, bills, invoices) once their dialogs reach field parity, or keep as deliberate bookmark/deep-link fallbacks?
- Pick one edit UI for Bills and Invoices — the Index-page dialog reuse or the routed `Edit.tsx` — rather than keeping both live.
- Decide whether `receivables/Aging.tsx` should become the real content of the "Aged AR" hub tab (replacing Index's own outstanding-invoice table, which largely duplicates it) or gain its own rail entry.
- Confirm whether quotes are intentionally immutable-by-line-item after creation, or whether `QuoteDialog` edit mode needs full line-item editing.
- Confirm whether Payment Runs should get a `WizardShell`-style "select records" dialog, or stay routed by design.
- Reconcile `receivables/Index.tsx`'s duplicate payment dialog with the canonical `RecordReceiptDialog`.
- Decide on a consistent confirmation policy for GL-posting approvals (PO/Invoice confirm today; Bill/Credit-note approve does not).
- Replace the `window.prompt()`-based bank-settlement actions on `payment-runs/Show.tsx` with a real form — the single worst anti-pattern found in this area.
