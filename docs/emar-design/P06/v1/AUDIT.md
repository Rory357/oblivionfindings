# P06 v1 — today’s code vs the design

What today’s code does, with file and line, as the evidence behind P06’s decisions and build notes. Read at `origin/main` `1b4b6e23e` and re-based to `ada567669`, this version’s base: in between, only `EmarController.php` moved (lines +46 around 2,200–4,500 and +53 from 6,192), and `CheckMedicationStock.php` / `MedicationAlertService.php` changed only to show concealed medicine names (`unrestrictedName()`, EM-12). The references below are at `ada567669`. [R] = read; [I] = inferred. Design only — nothing here was changed.

| Short | File |
|---|---|
| EC | `app/Http/Controllers/Emar/EmarController.php` |
| MAC | `app/Http/Controllers/Api/MedicationsApiController.php` |
| CMC | `app/Http/Controllers/ClientMedicalController.php` |
| RE / RA | `routes/emar.php` / `routes/api_medications.php` |
| SM / SD | `resources/js/pages/emar/StockManagement.tsx` / `_stock-dialogs.tsx` |
| PD | `resources/js/pages/emar/_prescription-dialogs.tsx` |
| SMM | `resources/js/pages/emar/components/stock-movement-modal.tsx` |
| CMS / SSC | `app/Models/ClientMedicationStock.php` / `MedicationScheduledStockCount.php` |
| CHK / SMA / MAS | `app/Console/Commands/CheckMedicationStock.php` / `SendMedicationAlerts.php` / `app/Services/MedicationAlertService.php` |
| EMS | `app/Services/EnhancedMarService.php` |
| RS | `database/seeders/RbacSeeder.php` |

## 1. The page (→ Q1)

1. `/emar/stock` → `EC::stock` (EC:2448), needing `medications.view` and `medications.stock.update` (RE:98-103) [R]. A PageHero, “Medication stock for {site}” (SM:729-756), with stats Tracked / Low / Expiring / Orders (SM:705-718) and tabs All stock · Low stock · Expiring · Expired · Controlled drugs (with `controlled.view`) · Pharmacy orders (SM:656-703) [R].
2. There is no frontline stock view. P01 v1 and P07a v1 left Meds today › Stock alerts link-only for P06.
3. Writes need `stock.update` (RE:259-267); the controlled delivery needs `controlled.record` (RE:250) [R].

## 2. One row per medicine, and EM-10 (→ Q2)

1. `client_medication_stocks` has one row per medication (unique `client_medication_id`, `database/migrations/2026_01_24_000001…:26-36`), with a single `batch_number` and `expiry_date` (`2026_03_27_000004…:12-16`). There is no lots table [R].
2. There is no stock-movements table. History is rebuilt from the audit log (EC:2472-2484, 2664-2719) [R].
3. **EM-10 — every ordinary pharmacy delivery wipes the batch and expiry [R].** The Stock page’s “advance” sends only `expected_status` and a request id (SM:602-607). Moving an order to “dispensed” sets its batch and expiry to `validated ?? null` (EC:6966-6967); moving it to “delivered” copies them onto the one stock row (EC:7011-7016). The controlled delivery does the same (EC:7207-7212). Receiving directly keeps the old value when the new one is blank (EC:7364-7365), but a new batch still replaces the old one.
   **Main sent this to a fix-first session on 30 September** so the test site stops losing data before the P06 build. [Fixed-first status: pending Main’s confirmation.]
4. Receiving accepts already-expired stock — expiry is only validated as a date (EC:7286) [R].

## 3. Flows (→ Q3, Q4, Q7, Q10)

1. **Doses don’t use stock.** Only controlled administrations decrement it (EMS:1147-1181, 2124-2144) [R].
2. **Pharmacy orders** move draft → submitted → confirmed → dispensed → delivered, forward only (EC:6944-6949). There is no cancel route, no partial or short state; `quantity_received` is ≥ 0 with no upper bound, defaulting to the quantity ordered (EC:6878, 6977-6980); delivered orders can still be edited (EC:6833-6850) [R].
3. **Dispensing** (P04’s DispenseDialog, PD:783-911) saves pharmacy, batch, expiry, date and notes on the prescriber order — no quantity, never touching stock or the pharmacy order (EC:4583-4591) [R].
4. **Adjust** sets an absolute quantity with a free-text reason written to `stock.notes` (“Stock adjustment: …”, EC:7541), overwritten by the next write; it is kept only in the audit log [R].
5. **Counts:** the stocktake posts an absolute adjust with the reason “Physical stock count” (SD:1243-1254); scheduled-count completion drops the witness (MAC:2157) and overwrites `on_hand` (MAC:2159); `ScheduledStockCounts.tsx` is imported nowhere [R].
   **Every scheduled count shows as a discrepancy:** `$disc ? 'discrepancy' : 'counted'` treats the string “0.00” as true (EC:2258-2261). Main sent this to the same fix-first session. [Fixed-first status: pending Main’s confirmation.]
6. **Removal** of an ordinary medicine is only possible through controlled destruction (EC:6245-6581), which needs `controlled.record`; reason and method are free text (EC:6268-6269) [R].
7. **No going out / coming back** for ordinary medicines. Receipts and deliveries also set `last_counted_at`, falsifying the last count (EC:7015, 7362) [R].
8. The dashboard’s “Stock movement” receive always fails: it sends no scan fields (SMM:117-124), but the server requires a verified scan (EC:792) [R].
9. Non-controlled “receive against order” opens the generic receive dialog and never closes the order (SM:366-369), so receiving the order later counts it again [I].

## 4. Alerts (→ Q8)

1. The daily 06:00 job warns at 30 days and marks ≤ 7 days critical (CHK:25, 44); expired and zero stock raise Control Room signals (CHK:79, 128). Low stock notifies everyone with `medications.view`, throttled 24 hours (SMA:169-241). None of them filter discontinued medicines (CHK:25-99; SMA:173) [R].
2. `MedicationAlertService` “expiry” uses the medication’s end date, 7 days ahead, not the stock expiry (MAS:347-389); out of stock only fires at exactly 0 (MAS:411) [R].
3. Nothing is configurable (no stock settings in `MedicationSettingsController`); there is no days-of-supply calculation and no All Tasks provider for stock [R].
4. Day boundaries use `Carbon::today()` / `now()` with the app in UTC (config/app.php:99; CMS:52-62, 81, 93; SSC:67, 94, 102), so the 06:00 NZ job evaluates the previous day [I].

## 5. Photos (→ Q6)

`client_medications.photo_path` exists (`2026_03_26_000001…:330-331`) but isn’t fillable and nothing uses it; `medication_destructions.photo_path` is never set (EC:6257-6281) [R].

## 6. Controlled medicines (→ Q9)

1. Ordinary flows exclude them: receive (EC:7270-7278), adjust (EC:7470), the client-profile edit (CMC:396), scheduled counts (MAC:1935, 2073), ordinary delivery (EC:6970), and lists without `controlled.view` (EC:2467, 2587, 2630) [R].
2. **Leak:** `updateStockItem` lets `controlled.record` edit a controlled medicine’s batch, expiry, reorder level and storage with no witness or register entry (EC:7429-7443); the Adjust dialog opens for controlled rows (SM:1361-1372) [R].

## 7. Permissions (→ Q5)

Keys at RS:290-302. Provider manager: stock, orders and all controlled keys (RS:662-664). Coordinator: stock and controlled view/record/witness (RS:719-721). Support worker: controlled view/record/witness, **not** `stock.update` (RS:798-799). Finance: `stock.update`, no controlled keys (RS:821). Team lead: `medications.view` and `orders.verify` only (RS:915). Clinical lead: orders, no stock or controlled keys (RS:993-994) [R].

## 8. Also found

- Pharmacy orders are capped at the 40 latest including delivered ones (EC:2593); movements at 400 across all items (EC:2482); stock items aren’t paginated (EC:2470) [R].
- The adjust is two requests, not atomic (SD:1559-1578) [R].
- A low-stock alert’s timestamp write shows in the history as “Stock details updated” (SMA:237; EC:2696-2707) [I].
- The client-profile stock edit takes an optional reason and a back-dated `last_counted_at` (CMC:408-413) [R].
- Scheduled counts go overdue on their own date (`isPast()` on a midnight date, SSC:67 vs 93-94) [R].

## 9. Live reference

Not checked live for this version; see P04 AUDIT §8 (the local site had signed out). The page structure in §1 is from the code.
