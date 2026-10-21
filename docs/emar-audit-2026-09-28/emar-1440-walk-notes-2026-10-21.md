# Lane C 1440 walk — notes (20 Oct)

Shots: `lanec-walk/shots/lanec-1440-*.png` (18 Oct, served at 127.0.0.1:8781 on the
isolated `oblivion_findings_lanec_browser` walk DB, built from main 1c77a0115).
Mockup references: `lanec-walk/mockup/` (from the approved, frozen commits) and
`scratchpad/frozen/` (served by serve-all.mjs).

Labels: **real** = a UI mismatch with the approved mockup; **walk-data** = caused by the
walk database (no doses scheduled today, three sites named "Kōwhai House",
verified_by null, assessment rows with every area false); **deliberate** = an approved
or recorded decision that differs from the mockup on purpose.

## P02 — person record and MAR hub (compared by Main)

| # | Item | Label | Outcome |
|---|---|---|---|
| 1 | Medicine name had the dose text appended ("Losartan 50 mg tablet 1 tablet (5…") | real | fixed eb1aee005 |
| 2 | Empty day: Due now said "Nothing due right now" under n/a | real | fixed eb1aee005 ("No scheduled doses today" on Due now and Late) |
| 3 | History: bare "No records in this view." + hand-rolled Previous/Next | real | fixed eb1aee005 (EmptyState per sub-tab, shared paginator) |
| 4 | Nothing due today, no late doses, empty charts | walk-data | — |
| 5 | Three "Kōwhai House" groups on the hub | walk-data | — |
| 6 | Orders show "Not checked" (verified_by null) | walk-data | — |

## P08b — Medication errors (`/emar/errors`)

Mockup: P08b v1.1 `1440-01-errors-house-lead.png`, `1440-94-empty.png`.

| # | Item | Label | Outcome |
|---|---|---|---|
| 1 | Title "Medication errors" + generic subline; approved is the hub header: shield, "Safety & oversight", "n houses" chip, "Medication errors at <houses> · times in NZ" | real | fixed 2861b3305 |
| 2 | Search placeholder "Search person or error reference" vs approved "Search people or MED number" | real | fixed 2861b3305 |
| 3 | No "As at <time> · Pacific/Auckland" chip in the filter row | real | fixed 2861b3305 (refreshes the view) |
| 4 | Nothing reported at all showed the filtered "No errors in this view / Clear filters"; approved is "No medication errors reported" + where reports come from + Report an error | real | fixed 2861b3305 (filtered empty view keeps the old state) |
| 5 | Views are header chips (To triage · 0 …), mockup has tier-2 tabs below the band | deliberate | as Staff eligibility and Follow-ups ("nothing below the header band") |
| 6 | "Export neutral CSV" vs mockup "Export" | deliberate | the export is the neutral summary; wording kept |
| 7 | Extra filters Your reports / Reach / House | deliberate | approved filters, shown as chips |
| 8 | All meters 0, no rows | walk-data | — |

## P11 — Staff eligibility register (`/emar/safety/eligibility`)

Mockup: P11 v5 `1440-elig-register.png`.

| # | Item | Label | Outcome |
|---|---|---|---|
| 1 | Can witness caption cut off with two names ("Kōwhai House, Rimu House: …"); approved names one house | real | fixed 905c67b9f (one house named, several counted: "2 houses: nobody on shift now") |
| 2 | Meter labels "Given competency met / not met", column "Competency requirements" vs mockup "Can record alone / Can't record given / What they can do" | deliberate | Stephan's 4 Oct competency-requirements wording (a4f73d642) |
| 3 | Daniel Ahn "Current" with every area shown as not passed | walk-data | assessment rows with all areas false |
| 4 | Duplicate rows for the same name | walk-data | — |

## P11 — Witnesses & PINs (`?view=witness`)

Mockup: P11 v5 `1440-elig-witness.png`.

| # | Item | Label | Outcome |
|---|---|---|---|
| 1 | Three "Kōwhai House · on shift now" cards | walk-data | three sites with one name |
| 2 | No "Next witness-eligible: <name> from 3:00 pm" line | deliberate | not built in C9h (needs roster look-ahead; listed then) |
| 3 | Card footer wording "Checked against clock-ins and shifts in progress at …" vs "Checked against the roster and clock-ins at …" | deliberate | presence is clock-ins and shifts in progress, as the P07b rule reads it |
| 4 | Console "ERR_NO_BUFFER_SPACE / Unable to preload CSS" on this shot | walk-data | local machine resource limit during the walk; the page rendered |

## P06 — Stock

| # | Item | Label | Outcome |
|---|---|---|---|
| 1 | `/emar/stock` renders the older "Stock & pharmacy" page, not the approved P06 hub | deliberate | see "stock: kept both pages" below |
| 2 | Hub route group lacked EncryptHistory and MedicationCapturedByGuard (every other medication page has them) | real (privacy) | fixed 3c271a6d7 |
| 3 | Hub "Deliveries & orders" rows showed the connected-pharmacy send state only inside the order dialog (EA-138) | real | fixed 3c271a6d7 (Lane B's chip on the row, while the pharmacy connection runs) |
| 4 | Hub header: fixed chip "Supply and pack records" (approved "2 houses"), generic subline, no "As at" chip | real | fixed afaeb09cb (was 241b34755, amended unpushed); chip and subline follow the House filter |
| 5 | Meters: Tracked · Out of stock · To receive · Expiring · Counts to sign off (approved: Running low · Expiring · To receive · Counts to sign off · Pharmacy orders · Medicines in stock) | real | fixed 75d69aa99: computed in StockReadPayload in the list's scope; "+n controlled hidden" + MAR-board caption; Show › Running low or out matches the meter |
| 6 | No header "Receive a delivery" | real | fixed f11ef7160: picker (deliveries due first, then other medicines at the house) → existing receive dialog and `receive` command; can.receive; controlled only on the witnessed path |
| 7 | Rail: separate Movements tab (approved: recent movements under Stock). Find was already there (shared rail) | real | fixed d85e905c4; ?view=movements still opens All movements under Stock |
| 8 | Stock list columns and caption | real | fixed 669e34240: Medicine · Person · On hand · Packs · State + row button; "n shown · n people" (+ "of total" when paged) |
| 9 | "19.00 tablets usable" | real | fixed 669e34240 (+ orders, counts, packs and movements in later commits): MedicationStockQuantity::display |
| 10 | Show filter: approved All medicines · Needs attention · Running low or out · Self-managed | real | fixed ba575cf45 (Main 21 Oct): Needs attention = out/low, expiring ≤30 days, count to sign off, overdue order; Self-managed = P03 support (MedicationSupport::modes(), same rule as mode()); an old ?show=out link still works |
| 11 | Deliveries & orders: flat list (approved 1440-12: To receive · Waiting on the pharmacy · Drafts · Closed, state lines, row steps) | real | fixed 1130f670d; server orders by section so each stays together across pages |
| 12 | Counts: flat list (approved 1440-14: Differences to sign off, then Recent counts) | real | fixed 01eb5831b; totals as whole numbers; who signed off and when |
| 13 | Expiring: a medicine list (approved 1440-15: packs in Expired / Within 7 days / Within 30 days) | real | fixed a3cc3e0ad: new `expiry_packs` payload (scoped, soonest first, bounded 200) |
| 14 | Removals: a movement list (approved 1440-16: To remove, then Removed and returned) | real | fixed 91e63947f; movements carry unit and signed change ("-1 tablet"); approved movement words |
| 15 | Duplicate medicines per person in the shot (e.g. three Amoxicillin rows) | walk-data | seeded walk DB |

#### Deviations (accepted by Main 21 Oct unless marked new)
- "Counts to sign off" caption: approved "Due by the end of today" → "The house lead signs them off" (no same-day sign-off rule exists). Same for the Counts caption's "(a follow-up, due by the end of the day)".
- "To receive" caption: approved "n due today by 12:00 pm" → "n due by today" (orders carry a date, not a time).
- Days' supply: stays null as the payload always sent it, until P01's same-unit daily-use contract exists; On hand shows "Reorder at n" instead.
- **new** Deliveries & orders: the approved "Order from the pharmacy" section button is not built — it needs an order picker (a new flow); orders still start from a medicine's row menu.
- **new** Counts: the approved "Count stock" (whole-house count) button is not built — new flow; counts start from a medicine.
- **new** Counts: the approved "Scheduled counts · Off" card is not built — it points at a Settings › Stock option that does not exist.
- **new** Expiring: the approved footer (warnings at 30/7 days go to the house lead via P11 Delivery and All Tasks › Stock) is not shown until that routing is checked; the bands carry the approved captions.
- **new** Self-managed rows keep their stock badge when something needs saying (e.g. Running low) and show "Self-managed" only when the stock is fine — the app still tracks stock for self-managed medicines; the mockup's "not counted" claim is not made.
- **new** Movement words follow the approved list where the kinds match; Recorded balance, Pack taken out of use, Count correction and Controlled receipt keep their app words (no approved equivalent).

### stock: kept both pages (decision 20 Oct)

Main, under delegation: **no redirect**. The approved P06 hub at `/emar/stock/packs` is the
stock page people land on; `/emar/stock` stays as the "Stock overview" companion because:
- the hub links to it, so a redirect would loop;
- it still carries actions the hub doesn't: editing reorder level, supplier and storage
  details on a stock item; the cold-chain and controlled-only filters; controlled pharmacy
  orders (including the controlled delivery receipt); and the audit log's
  `pharmacy_order_id` deep link opens there.
Retiring it needs those moved into the hub first (a P06 follow-up, not part of this walk).
