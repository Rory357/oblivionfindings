# P07a v1 — today’s code vs the design

This audit compares `origin/main` at `9b006825d` (30 September 2026) with the design.

- **Verified**: I read the lines myself.
- **Reported**: a code-search agent said so and I haven’t re-read it. Audit agents over-report, so treat these as leads.
- **Live**: seen on oblivionfindings.test at 1440 px as Demo Admin. Nothing was saved: I typed a mismatch into the balance check and then cancelled.

Paths are relative to the repository root.

## 1. The three dialogs

| # | Today | Status | P07a |
|---|---|---|---|
| 1.1 | All three are mounted only on `/emar/controlled` (`ControlledDrugs.tsx`), and Meds today has no controlled tab. Its tabs are Schedule, Rounds, PRN, Stock alerts and Activity (`resources/js/pages/meds/today/index.tsx:1626-1653`). | Verified (tabs); reported (mount points) | A Controlled checks view inside Meds today |
| 1.2 | `BalanceCheckDialog`:<br>• heading blurb “A mismatch auto-raises a discrepancy and an incident.” (`resources/js/pages/emar/_cd-dialogs.tsx:723`);<br>• “Overdue — checks are due at least weekly.” (`:746`);<br>• “Witness password or PIN” (`:788`);<br>• on a mismatch, “Counts differ — this will raise a discrepancy…” (`:805`) and “Immediate action actually taken” (`:827`).<br>Live, it opens as “Step 1 of 1 · Balance check”, and “Expected (register)” is an editable number. | Verified; Live | The count, §2 of the README: the register isn’t editable, counts are redone first, PIN-1 wording |
| 1.3 | `RecordCdEntryDialog`:<br>• six movement types (receipt, administration, disposal, transfer in/out, adjustment);<br>• before and after typed by hand;<br>• “Witness password or PIN” (`_cd-dialogs.tsx:467`). | Verified (label); reported (types, `components/emar/controlled/types.ts:123-130`) | Record a movement: going out or coming back only, with the other types sent elsewhere |
| 1.4 | `ResolveDiscrepancyDialog`:<br>• says “Resolution is logged against the linked incident.” (`_cd-dialogs.tsx:919`);<br>• action list starts with “Recount confirmed” (`:853`);<br>• no witness. | Verified | P07a covers starting only. Resolution is P07b. |
| 1.5 | The witness list leaves out the recorder, mirroring the server’s “different person” rule (`_cd-dialogs.tsx:61-74`). | Verified | Kept, and now shown: “you’re counting — the witness must be someone else” |
| 1.6 | Offline, witnessed controlled-drug actions are blocked: “Reconnect to record this witnessed controlled-drug action. Witness credentials are never saved on this device.” (`_cd-dialogs.tsx:92-97`) | Verified | Kept, with values retained in the dialog |

## 2. The backend

| # | Today | Status | P07a |
|---|---|---|---|
| 2.1 | A mismatch on a balance check **overwrites stock on hand with the count straight away** (`app/Http/Controllers/Emar/EmarController.php:8459-8462`) and creates an open discrepancy on the first count, with no recount (`:8464-8482`). | Verified | Recount first. The register still follows the count (Q3 asks Stephan to confirm). |
| 2.2 | The discrepancy creates a critical incident titled **“Controlled drug discrepancy: {medicine name}”** (`app/Services/MedicationIncidentIntegrationService.php:243-246`). | Verified | The design says the incident doesn’t name the medicine to people without controlled-medicine access. **Concealment gap for P07b / D9.** |
| 2.3 | Resolving sets `closed` (`EmarController.php:8583-8600`). `resolveControlledDiscrepancy` clears only the dashboard alerts and Control Room signals (`MedicationIncidentIntegrationService.php:767-788`); **the linked incident isn’t touched, despite the dialog copy (1.4).** | Verified | For P07b |
| 2.4 | The witness must be a different person: “The witness must be a different eligible staff member.” (`app/Services/Medication/ControlledMedicationTransportWitnessService.php:99-103`) | Verified | Shown in the picker; the server message is kept as the fallback |
| 2.5 | The witness credential is checked against the **login password**: “The second checker password or PIN did not match.” (`…WitnessService.php:140-149`), and the method is recorded as `password` (`:154`). | Verified | PIN-1 replaces this; it isn’t on main yet |
| 2.6 | The witness competency must be exactly `valid` (`…WitnessService.php:200-208`). **Nothing checks for a restricted competency.** “restricted” doesn’t appear in this file. | Verified | P11 answer 11 says restricted staff can’t witness. **The build must add this check.** |
| 2.7 | There is no rate limit on witness attempts for the controlled-drug endpoints; only the handover has one. | Reported | PIN-1 adds locking (5 attempts, 15 minutes) |
| 2.8 | The recorder’s presence isn’t checked for counts and entries; only the permission, site access and HR profile are. | Reported | The counter must be clocked in at the house (build item) |
| 2.9 | There is no route to create a discrepancy directly; a balance check is the only place one is created. | Reported | Matches the design: it starts only from a count |
| 2.10 | Nothing ever writes the `under_review` status. | Reported | For P07b |

## 3. Cadence, overdue counts and the handover

| # | Today | Status | P07a |
|---|---|---|---|
| 3.1 | `emar:escalate-overdue-cd-checks` runs daily at 07:30 (`routes/console.php:271-275`) with `--days=7` (`app/Console/Commands/EscalateOverdueControlledChecks.php:20-32`). It raises **a dashboard alert only**. | Verified (schedule, 7 days); reported (no notification) | One setting: every shift change, overdue after 1 hour. The P11 alert follows it and goes to the house lead. |
| 3.2 | The eMAR dashboard’s action centre treats a controlled drug with no count **today** as due (`MedicationOverviewService.php:667-701`). | Reported | **Three cadences today** (every 7 days, daily, and at every handover in 3.3). P07a replaces them with one. |
| 3.3 | The handover wizard has one **whole-register** controlled-drug count: “Controlled-drug count · two-person check”, with “Counts verified” or “Discrepancy found” (`resources/js/pages/operations/handovers/components/handover-wizard.tsx:1470-1480`) and “Reconcile the controlled-drug register with the incoming worker” (`:1497`). The server stores `verified` or `discrepancy` with notes (`app/Services/ShiftHandoverService.php:2088-2119`) and **never creates a discrepancy record**; the service has no reference to `ClientControlledDrugDiscrepancy`. | Verified | This is EM-21. The shift-change count counts each medicine with a witness and starts real discrepancies. P08a’s handover lens shows its status and opens it. |

## 4. Permissions and concealment

| # | Today | Status | P07a |
|---|---|---|---|
| 4.1 | Seeded grants (`database/seeders/RbacSeeder.php`):<br>• `controlled.view`, `record`, `witness` and `override` are defined at `:299-302`;<br>• the provider manager has all four (`:663-664`);<br>• the coordinator has view, record and witness (`:721`);<br>• the support worker has view, record and witness (`:799`). | Verified | The personas follow these |
| 4.2 | `medications.controlled.override` (“Override controlled drug discrepancy blocks”) is only passed to the frontend (`app/Http/Middleware/HandleInertiaRequests.php:609`) and is **checked nowhere**. `witness_override` **doesn’t exist.** | Verified | P00 v5 decided a new key, `medications.controlled.witness_override`. It needs a **grant migration** (a project rule for new keys). |
| 4.3 | Meds today includes controlled rows when the user has `controlled.view` **or** `controlled.record` (`app/Http/Controllers/Emar/WorkerMedsController.php:78-79`). | Verified | EM-12: rows need **view**. Someone with only record would see rows the concealment rule hides (build item). |
| 4.4 | Without `controlled.view`:<br>• `/emar/controlled` returns 403;<br>• the dashboard zeroes the controlled counts;<br>• medicine detail returns 404 for a controlled drug. | Reported | Kept. The design adds no tab, no rows, no search results and no bell items (§8). |
| 4.5 | There’s **no Tasks provider** for controlled-drug discrepancies or overdue counts. | Reported | The follow-up and discrepancies need to reach All Tasks, with concealment (P08a). |

## 5. Live register (oblivionfindings.test, 1440 px, 30 September)

`/emar/controlled` still uses the legacy **PageHero**:
- the eyebrow “CONTROLLED DRUG REGISTER · SYNCED”;
- the title “CD register for your services”;
- stat tiles for Active CDs, Open discrepancies, Overdue checks and Loss investigations;
- the strip “1 controlled drug overdue a balance check (≥ 7 days).”;
- the tabs Register, Recent entries, Reconciliation, Discrepancies, Destructions, Loss reports and Audit trail.

That page is P07b’s. P07a doesn’t copy it: frontline staff get Controlled checks in Meds today.

## 6. Build notes

- **A count posts several medicines with one witness PIN.** Today it is one POST per medicine. It needs a batch endpoint, or one PIN verification applied to several checks in a single transaction. That must be PIN-1’s durable-failure path: a wrong PIN has to be counted even if the transaction rolls back (PIN-1 review P0).
- **The recount:** store the first count on the check (for example, `first_count`) so the “matches on the second count” evidence is kept.
- **Witness requests are new.** They need a small model (from, to, subject, status, answer, reason), a bell notification, and closing the request when its count or dose is recorded.
- **The follow-up:** one record per override, listing its doses. The count step is satisfied by a count taken after the doses in which the owner was the counter or the witness.
- **The setting** goes into P11’s Controlled drugs view with the rule-loosening flag (README §10). Its default is Stephan’s answer.
