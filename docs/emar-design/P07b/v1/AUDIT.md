# P07b v1 — today’s code vs the design

This file records what today’s code does, with file and line references, as the evidence behind P07b’s decisions and build notes.

- **When and where it was read:** at `ada567669`, then re-based to `21bfb4ce4` (this version’s base).
- **What moved in between:**
  - PIN-1 merged. Controlled witnesses now confirm with their witness PIN (`WitnessPinService`), not a login password.
  - `EmarController.php` moved: +52 around `controlled()`, +73 at `destructions()`, +94 from `storeSyringeDriver()` to `storeDestruction()`, and +150 from `storeCDEntry()` on.
  - `_cd-dialogs.tsx` moved by +2 to +20.
  - `RbacSeeder.php` gained one line after 302.
  - `EnhancedMarService.php` gained one line at the top.
- **Tags:** [R] = read; [I] = inferred.
- **Design only:** nothing here was changed.

| Short | File |
|---|---|
| EC | `app/Http/Controllers/Emar/EmarController.php` |
| LRC | `app/Http/Controllers/Emar/CDLossReportController.php` |
| CMC | `app/Http/Controllers/ClientMedicalController.php` |
| GSS / TWS / WPS | `app/Services/Medication/MedicationGovernanceScopeService.php` / `ControlledMedicationTransportWitnessService.php` / `WitnessPinService.php` |
| MIS / EMS | `app/Services/MedicationIncidentIntegrationService.php` / `EnhancedMarService.php` |
| RB | `database/seeders/RbacSeeder.php` |
| RE | `routes/emar.php` |
| CDP / DP / DLG | `resources/js/pages/emar/ControlledDrugs.tsx` / `Destructions.tsx` / `_cd-dialogs.tsx` |
| M/ | `database/migrations/` |

**Changed since P07a’s audit:**

- The discrepancy incident’s title no longer names the medicine. It reads “Controlled medicine count discrepancy — {site}” (MIS:1144).
- The worker medication list needs `controlled.view` (EM-12).

## 1. Pages (→ Q1)

1. **`/emar/controlled`** → `EC::controlled` (EC:1978). [R]
   - It needs `medications.view` and `controlled.view` (RE:80-85).
   - It’s a PageHero (CDP:22, 699) with seven tabs: Register, Recent Entries, Reconciliation, Discrepancies, Destructions, Loss Reports, Audit Trail (CDP:600-636).
   - Movements and destructions show only the selected day (EC:2046, 2075).
   - Discrepancies show only open or under review (EC:2053). Closed ones are listed nowhere.
2. **`/emar/destructions`** → `EC::destructions` (EC:3835). [R]
   - A second PageHero (DP:11, 438) with three tabs: Destruction log, Controlled drugs, Reports & export (DP:398-412).
   - It lists non-controlled destructions too. Voided rows are struck through (DP:635).
3. **Loss reports** are JSON only, from `GET /emar/controlled/loss-reports` (LRC:28-54; RE:417-420). [R]
   - Store, investigate and resolve need only `controlled.record` (RE:423-429).
4. **Other writes that need `controlled.record`** (RE:246-252): register entries, balance checks, resolving a discrepancy, destructions and voiding a destruction. [R]
   - A second close route exists: `POST /clients/{client}/medical/controlled-discrepancies/{id}/close` (`routes/clients.php:215-217`).
5. No page lists witness or safety overrides. [R]

## 2. Data (→ Q2, Q3)

1. **`client_controlled_drug_entries`** (M/`2026_01_24_000020`:17-40) [R]:
   - Each row has `on_hand_before`/`on_hand_after`, `recorded_by`, **one** `witnessed_by`, `reason` and `notes`.
   - `disposal_method`, `disposal_authorisation`, `batch_number` and `expiry_date` were added later (M/`2026_02_15_000001`:129-146).
2. **Nothing makes the register append-only** [R]:
   - There are no void or correction columns, no soft delete and no witness method.
   - The policy blocks delete but allows update for `controlled.record` (`ClientControlledDrugEntryPolicy.php:25-34`). No update route exists.
3. **Discrepancies** (M/`2026_01_24_000050`) [R]:
   - Columns: before, after, difference, reason, notes, reported_by, witnessed_by, status and resolved_*/resolution_notes.
   - `incident_id` and `immediate_action_taken` were added later.
   - Status is open / under_review / closed (M/`2026_03_31_210000`:30-42), but nothing ever writes `under_review`.
4. **`controlled_drug_loss_reports`** (M/`2026_03_27_000005`) [R]:
   - Columns: quantity, circumstances, discovered_by, police and pharmacy fields, investigation status (reported / investigating / resolved) and soft deletes.
   - Officer, regulator and `incident_id` were added later.
5. **`medication_destructions`** (M/`2026_03_26_000001`:191-220) [R]:
   - Columns: reason, disposal_method, controlled_drug_class, authorised_by_name/registration (“pharmacist”), destroyed_by, `witness_1_id`, `witness_2_id` and `photo_path`.
   - Void and soft deletes are annotation only (`MedicationDestruction.php:14-19`).
6. **`cd_schedule`** is 2/3/4 on `client_medications` (M/`2026_06_16_000220`). [R]

## 3. Flows (→ Q2–Q6)

1. **Register entries.** `storeCDEntry` (EC:8199) takes receipt, administration, disposal, transfer_in, transfer_out or adjustment (EC:8207). [R]
   - `before` must equal the current balance (EC:8330).
   - **An adjustment needs no reason:** `notes` is optional (EC:8218), and the stored reason is the entry type itself, “Adjustment” (EC:8409).
   - The dashboard’s register modal offers the same form, including “Disposal / destruction” (`components/cd-register-modal.tsx:50-57`).
2. **Balance check.** A mismatch overwrites stock to the counted figure and opens a discrepancy (EC:8664-8686). [R]
3. **Resolving a discrepancy** (DLG:861-951; EC:8768) [R]:
   - The actions are Recount confirmed / Recording error corrected / Stock located / Escalated to manager / Loss report raised (DLG:855-859).
   - The server adds an “Action: …” prefix to the notes, closes the discrepancy and audits it (EC:8791-8796).
   - **The dialog says “Resolution is logged against the linked incident.” (DLG:921).** In fact `resolveControlledDiscrepancy` clears only alerts and signals; the incident is untouched (MIS:774-795).
   - “Loss report raised” is only a label; no loss report is created (DLG:859).
   - No witness is taken, and the resolver may be the person who reported it (EC:8770 only needs `controlled.record`).
   - The second close route takes `resolution_notes` as optional (CMC:865).
4. **Losses** (DLG:954; LRC:56-240) [R]:
   - Medicine, quantity, circumstances and immediate action are required; officer and regulator are optional (LRC:74-76).
   - No witness is taken. There is an offline queue (DLG:1032-1034).
   - A loss raises a critical incident, alert and signal (MIS:634), but the register and stock are unchanged (LRC:169-184).
   - Investigate and resolve (DLG:1376; LRC:283, 303) overwrite the notes and aren’t audited.
   - The dialog says “Logged against the linked incident.” (DLG:1451).
5. **The incident text.** It says “Further controlled drug transactions for this medication are blocked until resolved.” (MIS:964). Nothing blocks them. [R]
6. **Destruction** (DLG:1500; EC:6339) [R]:
   - Reasons and methods are fixed lists in the dialog (DLG:1477-1487), but the server accepts any string (EC:6362-6363).
   - Controlled destructions need `witness_2` (EC:6431), a free-text `authorised_by_name` and denaturing confirmed. Both witnesses’ credentials are checked, and destroyer and witnesses must differ (EC:6439-6455).
   - Registration and photo are never collected: the form has no field for either (DLG:1529-1547).
   - **The disposal register entry records only witness 1** (EC:6617).
   - The class is forced to null (EC:6422).
7. **Voiding a destruction** (EC:8934) [R]:
   - Needs a reason and any `controlled.record` holder (EC:8936). No second person is involved.
   - Its own message says stock and register balances were not changed (EC:8970), so the disposal entry stays live.
   - On `/emar/controlled`, voided destructions look live (EC:2070-2079, 2184-2199).
8. **Two disposal paths with different rules:** the register’s “disposal” entry (EC:8207) and the destruction flow (EC:6339). [R]
9. **“Audit Trail” and “append-only”** [R]:
   - The Audit Trail tab repeats Recent Entries (CDP:1381-1402).
   - The page calls itself “append-only” (CDP:730) while the policy allows update (§2.2).
10. **Entry type mismatch:** `administered` (EMS:2153) vs `administration` (EMS:2174, EC:8207). [R]
11. **UK classes.** “Schedule 2/3/4” (DLG:371-376; EC:8217) vs the NZ Class A/B/C. [R] [I]
12. **“Report discrepancy” opens the balance check** (CDP:674). [R]

## 4. Witnesses (→ Q8)

1. **PIN-1 is live.** The witness confirms with their own witness PIN; `authenticate` records the method `WitnessPinService::METHOD` (TWS:137-155, 152). [R]
2. **Self-witness is blocked** (TWS:99; GSS:1387-1391). [R]
3. **The witness must** (TWS:177-227, 264-294) [R]:
   - be approved;
   - not hold a client or next-of-kin portal role;
   - hold `controlled.witness`;
   - be employed at the Site;
   - have a valid competency with `can_witness_controlled`;
   - be present.
4. **The picker** lists people without a usable PIN but disables them (GSS:377-390). [R]
5. **Not checked** [R]:
   - The competency’s `restricted` flag (`MedicationCompetencyAssessment.php:43, 70`).
   - Relatives beyond the portal roles.
   - The recorder’s own presence. Only `storeSyringeDriver` locks it (EC:5363-5432).

## 5. Overrides (→ Q7)

1. **`medications.controlled.override`** is defined as “Override controlled drug discrepancy block” (RB:302) and granted to admin (RB:627-631) and the provider manager (RB:665). [R]
   - It is exposed only as `controlledOverride` (`HandleInertiaRequests.php:609`). No frontend reads it, and the “block” doesn’t exist (§3.5).
2. **`safety_override` is API only.** Its reasons are listed in `SafetyOverrideReason.php`. [R]
   - It is gated by `medications.administer.override_safety` (EMS:915-922) and audited as `medications.safety_override.authorized` (EMS:1244).
3. **There is no witness-override flow for controlled doses:** a witness is always required (EMS:2032-2066). No list of overrides exists for leads. [R]

## 6. Cadence (four sources; settled by P07a)

1. **7 days overdue** from four places [R]:
   - The controller (EC:2116-2119).
   - The strip, “(≥ 7 days)” (CDP:368).
   - The daily 07:30 job `emar:escalate-overdue-cd-checks --days=7` (`EscalateOverdueControlledChecks.php:20`).
   - A stale TODO (EC:2025).
2. **“Weekly”** in the balance check copy (DLG:755). [R]
3. **Daily:** “no balance count recorded today” on the dashboard (`MedicationOverviewService.php:697`). [R]
4. **Every handover:** “Controlled-drug count · two-person check” (`resources/js/pages/operations/handovers/components/handover-wizard.tsx:1490`). [R]

## 7. Who can do what (→ Q10)

1. **Today’s grants** [R]:
   - Admin: all four controlled keys (RB:627-631).
   - Provider manager: view, record, witness and override, plus `override_safety` (RB:663-665).
   - Coordinator (RB:722) and support worker (RB:800): view, record and witness.
   - Clinical lead: `override_safety` only (RB:983-997).
   - Team lead: none (RB:890-918).
2. **The result:** a support worker can resolve discrepancies, void destructions and write reason-less adjustments. Nothing separates the counter from the resolver (EC:8770, 8936; LRC:303). [R]
3. No migration grants `controlled.*` keys. [R]

## 8. Bugs P07b’s build fixes

| # | Today | P07b |
|---|---|---|
| 1 | Resolve copy claims the incident is updated (DLG:921, 1451; MIS:774-795) | The resolution adds a note to the incident; Medication errors (P08b) closes it |
| 2 | “Transactions … are blocked until resolved” (MIS:964) with no block | Removed — doses are never blocked (P07a) |
| 3 | “Loss report raised” is a label (DLG:859) | The outcome creates a real loss |
| 4 | Close via CMC:865 with optional notes | One resolve path; outcome and notes required |
| 5 | No separation of duties (RB:800; EC:8770, 8936) | `controlled.manage`; never the counter or the count’s witness |
| 6 | Voided destructions look live (EC:2070-2079) | “Voided” badge and the reversing entry |
| 7 | Entries can’t be voided; destruction void leaves the disposal entry (EC:8970) | Void with a reason and witness PIN; void reverses |
| 8 | Two disposal paths (EC:8207, 6339) | One path: return for destruction |
| 9 | Registration and photo never collected; reasons unvalidated (EC:6362-6363) | Pharmacist name and registration on receipt; fixed lists; optional photo |
| 10 | Register records witness 1 only (EC:6617) | Both witnesses on the entry |
| 11 | Adjustment needs no reason (EC:8218, 8409) | No free adjustment; named reasons only |
| 12 | Losses don’t change the balance (LRC:169-184) | A loss is a witnessed register entry |
| 13 | Loss notes overwritten, not audited (LRC:283-328) | Append-only investigation; a manager closes it |
| 14 | “Audit Trail” duplicates Recent Entries; “append-only” untrue (CDP:730, 1381-1402) | Append-only register; audit stays in Reports & audit |
| 15 | `administered` vs `administration` (EMS:2153, 2174) | Build note: one entry type |
| 16 | UK “Schedule 2/3/4” (DLG:371-376; EC:8217); class forced null (EC:6422) | NZ Class A/B/C; existing values flagged for review |
| 17 | `under_review` never set; closed discrepancies unlisted (EC:2053) | “Escalate” sets it; Closed column |
| 18 | Restricted competency and recorder presence not enforced | Both enforced (Q8) |
| 19 | “Report discrepancy” opens the balance check (CDP:674) | Discrepancies start from a count (P07a) |
| 20 | `/emar/destructions` mixes non-controlled medicines | Controlled-only; redirects to the register’s Destructions view |
