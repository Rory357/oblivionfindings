# P04 v1 — today’s code vs the design

What today’s code does, with file and line, as the evidence behind P04’s decisions and build notes. Read at `origin/main` `31d597415`, and re-checked at `1b4b6e23e`, this version’s base. Only two of these files changed in between — `_dialogs.tsx` and `MedicationsApiController.php`, by Main’s fix-first allergy fix (`bbc7705ad`) — and their references below are at `1b4b6e23e`. Design only — nothing here was changed.

Short names used below:

| Short | File |
|---|---|
| EC | `app/Http/Controllers/Emar/EmarController.php` |
| RT | `routes/emar.php` |
| PX | `resources/js/pages/emar/Prescriptions.tsx` |
| PD | `resources/js/pages/emar/_prescription-dialogs.tsx` |
| ODD | `resources/js/components/emar/prescriptions/order-detail-dialog.tsx` |
| MD | `resources/js/pages/emar/_dialogs.tsx` |
| MGD | `resources/js/pages/emar/components/mar-governance-dialogs.tsx` |
| IDX | `resources/js/pages/emar/Index.tsx` |
| API | `app/Http/Controllers/Api/MedicationsApiController.php` |
| CM | `app/Models/ClientMedication.php` |
| MOV | `app/Models/MedicationOrderVersion.php` |
| LIFE | `app/Services/Medication/MedicationOrderLifecycleService.php` |
| EMS | `app/Services/EnhancedMarService.php` |
| MSS | `app/Services/MedicationSafetyService.php` |
| MAS | `app/Services/MedicationAlertService.php` |
| RSC | `app/Http/Controllers/Respite/RespiteStayController.php` |
| RSA | `resources/js/components/respite/modals/stay-actions.tsx` |
| RBAC | `database/seeders/RbacSeeder.php` |

## 1. Two records that don’t talk to each other (→ Q1)

1. **A prescriber order is a paper trail; the chart entry is what allows a dose.** Orders live in `medication_prescriber_orders`; doses are given from `client_medications`.
2. **Confirming an order changes nothing on the chart** for a new or changed order. EC:4391-4393 sets the order to `confirmed` and applies only a cease order.
3. **An order can only be linked to a chart entry that is already verified** (EC:2713-2714). A chart entry can be verified with no order at all.
4. **Versions exist but are barely used.** `medication_order_versions` is immutable (MOV:90-99) but is only written when a medicine is discontinued (LIFE:127-163). `createVersion`, pause and resume on the chart entry are unused (CM:547-685).

## 2. The check (→ Q2)

1. **The creator can verify their own ordinary chart entry.** Only high-risk, controlled or witness-required entries need someone else (CM:336-341).
2. **The waiver** — verifying your own entry with a reason and another verifier’s password — exists on the server (EC:7801-7821) but has no screen.
3. **Saving an edit always un-verifies,** even when nothing changed (EC:7716-7719). Discontinuing also sends the entry back to pending [inferred from the same path].
4. **Confirming an order is one click with no step** (PX:456-464). The confirmer must not be the recorder (EC:4378-4382).
5. **The wording varies.** VerifyOrderDialog says “awaiting pharmacy sign-off” (MGD:740), elsewhere it is “prescriber verification”. The “awaiting verification” query also returns rejected entries (CM:308-315).

## 3. Phone and verbal orders, and allergies (→ Q3, Q4)

1. **Read-back:** the witness types their own **login password** into the recorder’s dialog (PD:583-642), 5 tries per 300 s (EC:79-83). P04 uses the witness PIN (PIN-1).
2. **The new-order allergy step was broken in the browser — fixed on main in `bbc7705ad` (Main’s fix-first item, 30 September).** Before the fix, `GET /api/medications/clients/{client}/allergies` returned `{ allergies: [...] }`, but AddMedicationDialog read `data`, or a bare array, so it always showed “No recorded allergies for this client.” — even for a severe penicillin allergy. Now the endpoint adds `recorded_allergies` (the medication register plus the health profile, the source dose-time checks use; API:1772), the dialog parses it (MD:72-117), and a failed load says “Allergy record couldn’t be loaded — check the health profile before ordering” (MD:157-162), never “none recorded”. **Still open, for P04’s build:** the clash check is a first-word name match in the browser; drug-class matching on the server is P04’s.
3. **There is no allergy check on the server** when an order is created, confirmed or countersigned, or when a chart entry is added or verified. There is no field for “the prescriber confirmed it’s safe”.
4. **At dose time** allergies are checked: a severe allergy on the register blocks, and the health profile warns or blocks per organisation setting (MSS:61-119; matching in `MedicationAllergy.php:75-100`). P04 keeps this and adds the prescriber’s confirmation to it.
5. **“Countersign” is internal.** CountersignDialog asks staff to “confirm this order as the prescriber (or on their authority)” (PD:680-780). The countersigner must not be the recorder or the witness (EC:4451-4456), but it is still staff, not the prescriber. The banner says it “must be countersigned by the prescriber within 24 hours” (PX:1094-1095; PD:374-377). The deadline is `order_date + 1 day` (EC:2809-2811), not enforced, and a cease order can’t be countersigned at all (EC:4442 vs 4084).
6. **No attachments on orders.** Supporting documents are limited to administration, correction, discrepancy, loss report and error.

## 4. The page and its dialogs (→ Q7)

1. **The page** is `GET /emar/prescriptions` (RT:106-108, `medications.view`), rendered at EC:2899 as a PageHero — “Prescriptions & orders · live”, “Prescriber orders for {site}” (PX:669-675) — with stats and five tabs: Prescriber Orders · Awaiting Countersign · Dispensing · Covert · Activity (PX:404-454). All orders load in one query, with no pagination (EC:2747-2750); “expired” is computed, not stored (EC:2790).
2. **NewOrderDialog** (PD:129-677) has four steps and types new / change / cease / verbal / telephone. It can’t pick the charted medicine for a change or cease, allows a future order date, and offers the controlled-drug option to people who then get a 404 (EC:4009-4015).
3. **OrderDetailDialog** (ODD:69-359) is read only, with no actions.
4. **AddMedicationModal** has no permission check on its button (IDX:1440-1446). **EditMedicationDialog** shows a controlled-drug toggle that the server rejects (MD:807-811 vs EC:7705-7714).
5. **Stop and cancel reasons are kept only in the audit log** (EC:4590-4599).
6. **Routes and keys:** confirm is `orders.verify` (RT:169-171); countersign is `orders.verify` on the route (RT:172-174) but the controller also needs `orders.manage` (EC:4417-4422); everything else is `orders.manage` (RT:176-186, 227-230); chart verify and reject are `orders.verify` (RT:269-272).
7. **RBAC:** only `orders.manage` and `orders.verify`, no covert key. Provider manager and coordinator have both; team lead has verify only (RBAC:915); clinical lead has both but no controlled-medicine access (RBAC:993-994); support worker has neither.

## 5. Covert (→ Q6)

1. **CovertDialog** (PD:1211-1582) has four steps, but the answers are merged into one `clinical_justification` text. The legal basis is fixed as “Best interests (PPPR Act)” (PD:1244-1288), and “Lacks capacity: No” can still be submitted.
2. **Covert needs `orders.manage` and an active, verified chart entry** (EC:4613-4692), one active authorisation per medicine.
3. **An overdue review blocks the dose** (EMS:1997-2018) — kept in P04.
4. **Revoking** keeps the reason only in the audit log; the table has no `revoked_at`, `revoked_by` or reason, and status never becomes expired (EC:4714-4727).
5. **Covert is not shown when a dose is recorded.** The `covert_admin_knowledge` competency is enforced.

## 6. Reconciliation (→ Q5)

1. **It exists only in Respite** (`respite_medication_reconciliations`): counts and free text (RSC:326-376).
2. **The modal always posts “completed”** (RSA:164-304), is not linked to chart entries, and `created_by` is overwritten on update (RSC:355).
3. **Check-in is blocked** unless the arrival reconciliation is complete or an override reason is given (RSC:120-139, 682-720). P04 keeps this rule.

## 7. End dates, dispensing and notifications (→ Q8, P11)

1. **Ending soon:** an alert fires 7 days before (MAS:381-394; CM:438-445); the dashboard widget uses 14 days (MAS:707-744). Nothing warns before an order’s expiry. P11 v5 Q7 set 14 days.
2. **Dispensing** (DispenseDialog, PD:783-911, and the Dispensing tab) moves to P06.
3. **No All Tasks provider or notification** for the verification queue, a countersign due, or a covert review.
4. **CSV import** skips the shift check (EC:8848-8851) and silently drops rows (EC:8806-8833).

## 8. Live reference

Not re-checked live for this version: the local site (oblivionfindings.test) had signed out, and signing in wasn’t needed for a read. The page structure in §4.1 is from the code (PX:404-454, 669-675; EC:2899).
