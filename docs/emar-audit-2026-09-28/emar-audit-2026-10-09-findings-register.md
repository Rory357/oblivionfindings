# eMAR audit (8–9 Oct 2026): full findings register

This register comes from a read-only audit of `origin/main` at `58a7cae79`. Every finding cites code on main.

**Verification status**
- **verified:** survived adversarial re-checking. P0 findings had 2 independent refuters and P1 findings had 1.
- **confirm first:** not re-checked by an agent. Audit agents historically have about a 25% false-positive rate, so every fix in this group must start with a failing test that reproduces the problem.

**Counts**
- Total: **231** findings.
- By severity: P0 12, P1 64, P2 106, P3 49.

Not covered: the cross-module consistency finder and the 4 completeness critics did not complete because of usage limits. The scoped plan covers the consistency themes from the phase-1 map (§5–6.7).

## P0 (12)

### EA-001 · Paper doses given during downtime cannot be posted unless pack tracking had already started for that stock before the dose. With lots on and no backfill, that covers almost all current stock, and incomplete entries can never be completed
- **Area:** Downtime paper reconciliation (P10) / stock (P06)
- **Audit dimension:** workflows-leads
- **Status:** verified
- **Refs:** config/medications.php:11, app/Services/Medication/Downtime/PaperAdministrationWriter.php:50-64, app/Services/Medication/Downtime/PaperDoseFacts.php:96, app/Services/Medication/Downtime/PaperDoseFacts.php:109, app/Services/Medication/Downtime/PaperEntryService.php:379, app/Services/Medication/Stock/HistoricalPackEvidence.php:28-33, app/Services/Medication/Stock/HistoricalPackEvidence.php:71, app/Services/Medication/Stock/MedicationStockService.php:63-76, app/Services/Medication/Downtime/HistoricalPaperContext.php:36, routes/emar-downtime.php:15-23
- **Scenario:** 1. Network outage at a house from 07:00 to 13:00. 2. Staff give 08:00 scheduled doses on paper: metformin, plus methadone from the controlled register. They also give 2 x PRN paracetamol. 3. The stock rows predate packs (stock_lots_enabled defaults to true and nothing is backfilled), so lots_started_at is NULL. 4. The lead declares the downtime and captures the paper facts. Stock lines cannot be completed, so each 'given' entry is retained as incomplete. 5. Reconcile returns 'Historical physical stock disposition ... need complete signed actual facts'. 6. There is no route to amend the facts. If the lead starts pack tracking now, the opening lot has received_at = now(), which is after the dose, so validateHistoricalEvidence rejects it. 7. The doses never reach the eMAR: the MAR shows them as not given. The controlled register is never decremented for the methadone, so the balance is overstated against the physical stock. PRN 24-hour totals and 'last given' omit the paper doses.
- **Fix:** Allow a clinical-only posting of a signed given dose when pack tracking was not established at the dose time. Record the stock effect as an explicit 'untracked at the time' settlement that raises a stock or controlled discrepancy follow-up. For controlled doses, allow a witnessed register entry with source paper_recovery and no lot lines. Alternatively, let a counted opening carry an explicit as-at time before the outage. Add an amend-facts step for incomplete, unposted entries.

### EA-002 · Fleet medication-transit register and its ?export=csv ignore the person rule, use fleet.manage as an all-Site bypass, show controlled names, and skip MedicationExportGuard
- **Area:** Fleet › Transports › Medications
- **Audit dimension:** privacy
- **Status:** verified
- **Refs:** routes/fleet-assets.php:565-576, app/Http/Controllers/FleetAssets/ResidentTransportController.php:839-845, app/Http/Controllers/FleetAssets/ResidentTransportController.php:895, app/Http/Controllers/FleetAssets/ResidentTransportController.php:930-957, app/Http/Controllers/FleetAssets/ResidentTransportController.php:1020-1025, app/Http/Controllers/FleetAssets/ResidentTransportController.php:318, app/Http/Controllers/FleetAssets/ResidentTransportController.php:354-372, app/Services/Fleet/ResidentTransportJourneyScope.php:18, app/Services/Fleet/ResidentTransportJourneyScope.php:96-113, app/Services/Fleet/ResidentTransportJourneyScope.php:316-323, app/Services/Medication/MedicationGovernanceScopeService.php:50, app/Services/Medication/Reporting/MedicationReportAccess.php:57-68
- **Scenario:** (1) A support worker at House A (has fleet.viewAny and medications.view, but no medications.reports.export) opens /fleet-assets/transports/medications?export=csv. They download a CSV of every resident's packed medicines at House A, with name, controlled Yes/No, packer, witness and notes, including residents they are not assigned to. There is no purpose step and no MedicationExportAudit row. (2) A coordinator based only at House A holds fleet.manage, the transit Site bypass, so the same page and CSV list every house's transit medicines. /fleet-assets/transports/create?client_id=<House B resident> also lists that resident's active orders (name, dosage, instructions, barcode, controlled flag), although the coordinator's eMAR scope excludes House B. (3) An auditor (assets.viewAny, no controlled.view) sees 'Morphine 10mg — Controlled'. The same row already nulls pack_stock and dose for controlled medicines.
- **Fix:** For all medication content in ResidentTransportController (medicationIndex, CSV, show availableMedications, formOptions clientMedications), intersect with MedicationGovernanceScopeService::readerSiteIds(actor,'medications.view') instead of the fleet.manage bypass. Filter client_id through MedicationRecordAccess::readableClientIds. For readers without medications.controlled.view, drop controlled rows (scopeWithoutControlledMedicationRows) or redact the name. Remove ?export=csv, or route it through MedicationExportGuard with a new 'transit' type, the purpose step and MedicationExportAudit.

### EA-003 · Controlled as-needed doses with pack tracking can never be saved; back-dated tracked PRN doses are always refused
- **Area:** Meds today › As-needed (PRN recording)
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:874-893, app/Http/Controllers/Emar/WorkerMedsController.php:915-949, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1131-1148, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1189-1204, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1039-1078, app/Services/EnhancedMarService.php:1270-1286, app/Services/EnhancedMarService.php:1659-1680, app/Services/Medication/Stock/IntegratesPackEvidence.php:235-247, app/Services/Medication/Stock/IntegratesPackEvidence.php:369-374
- **Scenario:** Case 1: a support worker gives PRN oxycodone 5 mg for breakthrough pain. Packs were received after rollout, so lots are started. The dialog makes them choose the pack and enter waste 0, plus witness PIN and balance. On save the server answers 422 'Select each actual pack once and enter its quantity.' every time. The dose cannot go on the MAR, and the controlled register is never decremented. Case 2: paracetamol PRN with lot tracking, given at 14:05 and recorded at 14:12 with the actual time. It is refused with 'The connected recording review no longer matches…'.
- **Fix:** In recordPrn, forward ...Arr::only($data, RecordingContract::fields()) as recordDose does, or add pack_lines, live_recording_context, quantity_wasted and waste_reason to the list. Add feature tests: a controlled PRN with lots started, and a back-dated tracked ordinary PRN.

### EA-004 · Offline replay of a scheduled dose that someone else already recorded is reported 'sent' and silently discarded, so a double dose or wrong outcome goes unrecorded
- **Area:** Meds today / offline replay
- **Audit dimension:** concurrency
- **Status:** verified
- **Refs:** app/Services/EnhancedMarService.php:1367-1429, app/Services/EnhancedMarService.php:1839-1845, app/Services/EnhancedMarService.php:2042-2093, app/Http/Controllers/Emar/WorkerMedsController.php:754-777, resources/js/lib/offline-queue.ts:790, resources/js/lib/offline-queue.ts:1009-1014, resources/js/lib/offline-queue.ts:1131-1135, app/Http/Controllers/Emar/GuidedRoundController.php:166-190, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1335-1345
- **Scenario:** The house internet is down. Worker A gives Mary her 08:00 Epilim on laptop 1 and records it; it is queued in IndexedDB. Worker B on laptop 2 cannot see A's queued record, sees the dose as due, gives it again and records it (also queued). Connectivity returns: B's item lands first. When A's item replays, the server finds B's record for the slot and returns HTTP 200 with sync.status='duplicate' and replayed=false. A's queue removes the item and shows 'Queued item sent.' Mary got two doses, the chart shows one, and no medication error is raised. The same thing happens when B recorded 'refused' or 'withheld': A's 'given' is discarded and the MAR says not given.
- **Fix:** In WorkerMedsController::recordDose (and recordPrn for parity), when queued_offline is true and the result is duplicate && !replayed, return 409 using buildMedicationConflictPayload. Include duplicate_of, the slot and the captured outcome, and the text 'Someone else recorded this dose while you were offline — a lead must review'. Also create a medication follow-up or Control Room review for the slot. Separately, offline-queue.ts should never treat data.replayed===false as confirmed.

### EA-005 · Chart alerts set to 'Prompt staff' never appear where doses are recorded (P02 dropped the legacy prompt)
- **Area:** Meds today / Record dose dialog / Person record (P02)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/emar/record/safety.tsx:195, resources/js/pages/emar/MarCharts.tsx:228-242, resources/js/pages/emar/MarCharts.tsx:569, app/Services/Medication/Recording/DoseRecordingRequirements.php:280-345, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1742, resources/js/pages/operations/clients/tabs/mar.tsx:445-455, resources/js/pages/emar/components/mar-governance-dialogs.tsx:769-774
- **Scenario:** A clinical lead adds a warfarin chart alert, such as 'Hold if INR > 3.5, check today's INR', in Person record › Allergies & alerts and leaves it on 'Prompt staff'. A support worker opens Meds today and records the warfarin dose. Neither the schedule row nor RecordDoseDialog shows the alert, and the default P02 person record (person_record='p02') never prompts on open. The dose is given without the configured warning.
- **Fix:** Add the person's active `prompt_on_open` alerts to the dose-requirements payload. Render them as a required read notice on RecordDoseDialog step 0 (warning or critical tone, from the alert type), with an acknowledgement that is stored once per NZ day. Also show them when the P02 record first opens. Until that is built, change the 'Prompt staff' label so it does not promise a prompt.

### EA-006 · Shared-support house: a lone worker can record medicines only for the one person their shift is attached to
- **Area:** Meds today / Rounds / Shifts (recording authority)
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Refs:** app/Services/Medication/MedicationScopeDecisionService.php:881-917, app/Services/Medication/MedicationScopeDecisionService.php:525-569, app/Services/Medication/Recording/DoseRecordingRequirements.php:401-423, app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php:818, app/Services/ShiftConflictService.php:42-50, app/Services/ShiftStaffEligibilityService.php:227-243, database/migrations/2026_03_23_006400_add_multi_client_and_tags_to_shifts.php:17-23, app/Domain/Shifts/Timesheets/TimesheetAllocationService.php:24-31, app/Services/GuidedRoundService.php:105-119, app/Services/GuidedRoundService.php:243-262, app/Http/Controllers/Emar/WorkerMedsController.php:1131-1166
- **Scenario:** A 3-person house (A, B, C) has one sleepover worker, 22:00–08:00. The roster shift must name one person (A). At 22:00 the worker opens Meds today: A's doses are recordable; B's and C's 22:00 doses show 'Why can't I record? — not on your shift' (or are not listed at all if the worker isn't assigned to B/C). The worker gives B and C their medicines but cannot sign the MAR. The 22:00 house round lists only A and can never be finished. A second, overlapping shift for B cannot be created for the same worker.
- **Fix:** Give a shift a supported-people set. Either write shift_clients when a house or shared-support shift is created or assigned (the scope already honours the pivot), or let a clocked-in shift at the person's Site count when the worker is assigned to that person (supportWorkers). Mirror the change in DoseRecordingRequirements::blockAll and WorkerMedsController::boardPeopleFor. This changes the person rule, so it needs Stephan's decision.
- **Collision:** The scope change is in a safe zone (app/Services/Medication/**). Writing shift_clients touches ShiftController.php (HIGH #5), ShiftLifecycleService.php (#7) and the eligibility chain (#8).

### EA-007 · Service worker caches every page and /emar GET response (MAR, controlled register PDFs, CSV exports) in Cache Storage with no purge on logout or user switch
- **Area:** Offline / shared device privacy
- **Audit dimension:** concurrency
- **Status:** verified
- **Refs:** public/sw.js:1-10, public/sw.js:12-14, public/sw.js:33-47, public/sw.js:65-87, public/sw.js:109-120, resources/js/lib/emar-offline.ts:107-115, resources/js/lib/emar-offline.ts:218-224, resources/js/app.tsx:48-50, resources/js/components/user-menu-content.tsx:22-25, routes/emar.php:510-517, routes/emar.php:580-587, routes/emar.php:654-658, app/Http/Middleware/MedicationExportGuard.php:58
- **Scenario:** A team lead on a shared house PC opens /emar/mar?client_id=5 (a full page load), the controlled register PDF (/emar/pdf/controlled-register) and an audit CSV (/emar/audit/export), then logs out. A support worker without medications.controlled.view, or anyone else using that browser, opens DevTools > Application > Cache Storage > of-app-shell-v3 and reads the cached MAR HTML (its data-page JSON holds the page props), the controlled register PDF and the CSV. Or they disconnect the network and go to /emar/mar?client_id=5: networkFirst serves the lead's cached page even when nobody is logged in. Any failed navigation falls back to the cached /emar or /meds/today page.
- **Fix:** Stop caching authenticated data. Limit sw.js to static build assets plus one offline notice page that holds no data. Remove isCacheableMedicationGet, stop putting navigation responses into the cache, and remove /emar and /meds/today from OFFLINE_URLS. Bump CACHE_NAME so the activate step deletes the existing of-app-shell-v3 cache on every device. Also send 'Clear-Site-Data: "cache", "storage"' from a Fortify LogoutResponse, and call caches.delete in handleLogout.

### EA-008 · If an order is checked while the dialog is open, the saved record shows the new dose rather than the one the worker gave
- **Area:** Record dose dialog / order change mid-round
- **Audit dimension:** workflows-frontline
- **Status:** verified (claimed P1)
- **Refs:** resources/js/components/emar/record-dose/use-dose-requirements.ts:26-49, app/Http/Controllers/Emar/WorkerMedsController.php:695, app/Services/Medication/Recording/RecordingContractEnforcer.php:182-197, app/Services/EnhancedMarService.php:1270-1286, app/Services/EnhancedMarService.php:1552-1553, app/Models/ClientMedication.php:27-38, app/Services/Medication/MedicationOrderWorkflow.php:230
- **Scenario:** At 08:02 a worker opens the 08:00 dose: 'Quetiapine 50 mg'. At 08:04 the clinical lead checks a new version (25 mg), which is published onto the same order row. At 08:05 the worker gives 50 mg as shown and saves 'as ordered'. The administration stores dose_given '25 mg' and quantity_given 25: a wrong dose record with no warning. Orders without pack tracking (all stock until its counted opening) have no guard.
- **Fix:** Send the requirements' order fingerprint (or always the live_recording_context token) with every save. In recordAdministration, compare PaperEntryService::orderFingerprint for all given or reoffered doses, not just lot-tracked ones. Refuse with 'Order changed — check the new instructions' and reload the requirements.

### EA-009 · A controlled scheduled dose with pack tracking cannot be signed as given when the dose unit differs from the stock unit
- **Area:** Record dose dialog → EnhancedMarService (controlled stock)
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Refs:** app/Services/Medication/Recording/RecordingContractEnforcer.php:258-299, app/Services/EnhancedMarService.php:1459-1486, resources/js/components/emar/record-dose/record-dose-dialog.tsx:884-900, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1206-1224, app/Services/Medication/Recording/DoseRecordingRequirements.php:786-800
- **Scenario:** The order is 'Methadone 25 mg', its stock unit is mL, and lots are started after the counted opening. At 08:00 the worker records Given. The dialog asks for the amount taken from stock and the per-pack waste; waste must total 0 because the units can't be compared. On save the server returns 'Enter the actual waste in the stock unit, including zero…' (field quantity_wasted). The dialog has no such field and never sends it. 'Less' fails with 'can't be recorded here' and 'More' hits the same waste error, so 'given' is impossible. The dose goes unrecorded and the register is not decremented.
- **Fix:** Server: when lots are started and quantity_wasted is absent, derive it from the sum of pack_lines.*.quantity_wasted before calling controlledStockUse. Or dialog: send quantity_wasted (the pack waste sum) and a waste_reason when it is above 0. Add a dialog-shaped feature test with mg vs mL.

### EA-010 · A server error or timeout while online is saved to the device as 'offline' and is not retried while the tab stays visible. Rows give no sign that a dose is waiting on the device.
- **Area:** Recording dialog / offline queue / Meds today / guided round
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/emar/record-dose/record-dose-dialog.tsx:1285-1300, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1321-1325, resources/js/lib/offline-queue.ts:759-768, resources/js/lib/offline-queue.ts:912-955, resources/js/lib/offline-queue.ts:310-336, resources/js/lib/offline-queue.ts:1185-1209, resources/js/components/offline-status-banner.tsx:107-111, resources/js/pages/meds/today/index.tsx:1151-1152, resources/js/pages/meds/today/index.tsx:1209-1210, resources/js/pages/emar/components/guided-round-dialog.tsx:81-86, resources/js/pages/emar/components/guided-round-dialog.tsx:274-288, resources/js/components/emar/followups/followup-list.tsx:100-125, resources/js/lib/emar-offline.ts:238-254
- **Scenario:** 1. A support worker records an ordinary PRN (paracetamol) as given at 2:00 pm on a connected desktop. It has no PIN, no override and no pack tracking. 2. The server answers 502, 503 or 504. Causes include a webhook deploy, maintenance mode, a DB lock-wait timeout or an event-log append failure. 3. The dialog closes with the toast 'Saved on this device — … isn’t on the chart yet. It will send when you reconnect. Don’t record it again.' 4. The banner says '1 item waiting to send. We’ll retry automatically.' Nothing retries until the tab is hidden and shown again or the page fully reloads. 5. The As-needed tab and the MAR show no 2 pm dose. A colleague on another device sees no recent PRN and gives a second dose. 6. When the first device finally replays, there are two outcomes. A PRN interval or limit rule rejects the original 422 (the given dose is never recorded). Or, with no limit, two doses are charted. 7. For a scheduled dose, the row keeps showing Due/Overdue with a live 'Record' button, and overdue alerts escalate. 8. In a guided round, the whole round is locked with 'Reconnect and check the round before recording more'. 'Refresh round' only reloads props; it does not replay the queue.
- **Fix:** - Do not convert a 5xx while `navigator.onLine` into an offline queue item for doses. Return an `unconfirmed` status, so the dialog stays in its existing 'uncertain' state with the same UUID and a 'Try again' button. Keep the recovery key until a confirmed result. - If queuing is kept, schedule an online backoff replay (15s/30s/60s) from `persistOfflineSubmission`, and make `retryOfflineSubmissionsNeedingAttention`/'Refresh round' call `replayOfflineQueue()` first. - Change the copy to 'The server didn’t answer'. - Add a 'Saved on this device' state for dose rows on Meds today, the MAR and the guided round, from `useOfflineQueueState().pendingSubmissions` keyed by `client_medication_id` + `scheduled_for` (the same pattern as `followup-list`). - Add a request timeout of about 30s to dose POSTs.

### EA-011 · Respite workspace sends medication chart alerts, allergies and full medical profiles for every house to any respite.viewAny holder
- **Area:** Respite workspace (/respite)
- **Audit dimension:** privacy
- **Status:** verified
- **Refs:** routes/respite.php:21-24, app/Http/Controllers/Respite/RespiteWorkspaceController.php:45-121, app/Http/Controllers/Respite/RespiteWorkspaceController.php:131-140, app/Http/Controllers/Respite/RespiteWorkspaceController.php:296-404, app/Http/Controllers/Respite/RespiteWorkspaceController.php:743, app/Http/Controllers/Respite/RespiteWorkspaceController.php:776, app/Http/Controllers/Respite/RespiteWorkspaceController.php:847-890, app/Http/Controllers/Emar/ClientMedicationDayController.php:117-124, app/Services/Medication/MedicationRecordSafetyPrivacy.php:12-15, database/seeders/RbacSeeder.php:801-834
- **Scenario:** A support worker approved only for House A (seeded support_worker holds respite.viewAny) opens /respite. The Inertia payload holds up to 200 referrals, 200 booking requests, 200 bookings and 200 stays from every house. Each booking and stay row carries criticalAlerts: eMAR chart-alert title and detail text (for example 'Crush tablets, never with dairy'), severe allergies with their reactions, and safeguarding alert details. Each referral and request row carries clientProfilePrefill.medical: medical, mental-health and surgical history, allergies, blood type, immunisation notes and NHI. An auditor (no medications.controlled.view) gets chart-alert detail for people on controlled medicines. eMAR's own reader replaces that text with 'Chart alert — details need controlled-medicine access'.
- **Fix:** Scope every workspace list to the viewer's approved Sites with UserSiteAccessService::applyClientScope / accessibleSiteIds. Build criticalAlerts only when Gate viewMedications(client) passes, and apply MedicationRecordSafetyPrivacy::hidesUnstructuredText to chart-alert text. Read allergies from ClientAllergyRecordService. Send clientProfilePrefill only to holders of the onboarding or edit capability (for example respite.bookings.manage plus clients.update) at the client's Site. Restrict the clients lookup the same way.

### EA-012 · Archiving a person or a house hides their remaining controlled-drug stock from the CD register, count escalation and every register action
- **Area:** Stock & controlled / Client profile
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/ClientController.php:296-306, routes/operations.php:299-301, app/Services/Medication/Controlled/ControlledProductPayload.php:47, app/Services/Medication/Controlled/ControlledProductPayload.php:55, app/Services/Medication/MedicationGovernanceScopeService.php:478, app/Services/Medication/Controlled/ControlledRegisterService.php:76-78, app/Services/Medication/Controlled/ControlledCountStatus.php:43-56, app/Http/Controllers/SiteController.php:427-442
- **Scenario:** A resident on oxycodone leaves the service or dies, and a coordinator archives them from the Clients index (DELETE /operations/clients/{id}, which soft-deletes the client). Their 14 tablets are still in the house cabinet. From then on, /emar/controlled no longer lists the medicine or its balance, and the weekly count stops coming due. Any attempt to count, destroy or return the tablets, report a loss or resolve a discrepancy returns 404. The physical CD balance can no longer be accounted for in eMAR, and nobody is prompted. The same happens when a house is archived or made inactive while it still holds CD stock.
- **Fix:** Block archiving a client (and archiving or deactivating a site) while any controlled medicine has stock on_hand>0, an open discrepancy or loss, or a pending destruction. Show the list and a link to the register. Separately, let the register read and forMedication include trashed clients (Client::withTrashed()) for controlled stock-out actions (count, return, destruction, loss) so that CDs which have already been orphaned can be closed out. Add a feature test that archives a person holding CD stock.
- **Collision:** app/Http/Controllers/ClientController.php is touched by the H&S lane (map §3 item 15). SiteController is not in any lane. ControlledProductPayload, MedicationGovernanceScopeService and ControlledCountStatus are in the safe zone.

## P1 (64)

### EA-013 · Client RAG snapshot uploads every medication (controlled, ceased, rejected) and the unfiltered internal timeline to OpenAI and answers the portal 'self' user from it
- **Area:** AI / RAG
- **Audit dimension:** privacy
- **Status:** verified
- **Refs:** app/Services/Rag/ClientRagIndexer.php:14-22, app/Services/Rag/ClientRagIndexer.php:34-35, app/Services/Rag/ClientRagIndexer.php:39-56, app/Http/Controllers/ClientRagController.php:16-17, app/Http/Controllers/ClientRagController.php:37-46, app/Http/Controllers/ClientRagController.php:79-88, app/Http/Controllers/RagController.php:101-110, routes/portal.php:66-68, app/Services/Portal/PortalClientSectionAccess.php:179-192
- **Scenario:** With OPENAI_API_KEY set, a person with the client role (rag.ask.self) posts /portal/clients/{self}/rag/ask 'What did staff record about my medicines last month?'. The snapshot holds all medication rows and 120 days of internal timeline events, such as 'Refused: Quetiapine 25mg' with the staff name, which the portal timeline never shows because it only shows visibility='portal'. The answer reveals them. Any staff asker's question also uploads the full controlled list and medication timeline to an OpenAI vector store, whatever the asker's medications.view or controlled.view rights. Old snapshot files are never removed from the store.
- **Fix:** Build the snapshot without medication rows or medication timeline types. If medicines are needed, use ClientMedication::active() without controlled rows plus MedicationTimelineVisibilityService for a least-privileged reader. For self askers, restrict events to visibility='portal' plus PortalClientSectionAccess::constrainTimeline. Delete the previous file from the vector store before attaching a new one. Join the array values (implode) instead of calling Str::squish on them.

### EA-014 · Timeline summaries (/summaries/staff/{user}, /summaries/clients/{client}) contain medication events, including controlled names from other Sites, with no medication or timeline filter
- **Area:** AI summaries
- **Audit dimension:** privacy
- **Status:** verified
- **Refs:** app/Jobs/GenerateSummaryJob.php:40-52, app/Jobs/GenerateSummaryJob.php:60-70, app/Jobs/GenerateSummaryJob.php:155-158, app/Services/Llm/OpenAiResponsesClient.php:33-48, app/Http/Controllers/SummaryController.php:26-49, app/Http/Controllers/SummaryController.php:53-72, app/Http/Controllers/TimelineController.php:53, app/Http/Controllers/TimelineController.php:87, app/Http/Controllers/Emar/WorkerMedsController.php:1224, database/seeders/RbacSeeder.php:859-863
- **Scenario:** A coordinator (summaries.generate) generates a 7-day staff summary for a support worker who works at Houses A and B. The job pulls every TimelineEvent with actor_user_id = worker from all Sites, including subjects such as 'Given: Oxycodone 5mg' and meta.medication_name, sends them to OpenAI, and stores the summary text. Afterwards, an HR user (staff.viewAny plus hr.employees.viewAllSites, no medications.view) opens /summaries/staff/{worker} for that range and reads the medication content. An auditor (no controlled.view) opening /summaries/clients/{id} for a summary a provider manager generated sees controlled medicine names.
- **Fix:** In GenerateSummaryJob, drop medication% event types and medication-sourced events, or apply MedicationTimelineVisibilityService for the least-privileged viewer, before both the LLM call and the deterministic summary. Restrict staff-scope events to Sites the requester can access. In SummaryController::client, also require ClientProfileSectionAccess::canViewTimeline. Because one stored summary is shared by every viewer, never store medication content in it.

### EA-015 · Medication alert recipients are chosen with a site-level gate, but the bell and the record use the per-person gate. Rostered staff and house leads are 'told' alerts they cannot open or acknowledge, and with privacy off the email and push carry resident and medicine names to them
- **Area:** Alerts & escalation (Medication Settings > Alerts)
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Alerts/MedicationAlertRecipients.php:244-266, app/Services/Medication/Alerts/MedicationAlertRecipients.php:275-290, app/Services/Medication/Alerts/MedicationAlertRecipients.php:293-315, app/Services/Medication/Alerts/MedicationAlertRecipients.php:122-156, app/Services/Medication/Alerts/MedicationNotificationVisibility.php:78-86, app/Services/Medication/Alerts/MedicationNotificationVisibility.php:128-136, app/Policies/ClientPolicy.php:65-112, database/seeders/RbacSeeder.php:801-802, database/seeders/RbacSeeder.php:914-955, app/Http/Controllers/NotificationInboxController.php:18-29, app/Http/Controllers/NotificationInboxController.php:47-55, app/Notifications/MedicationAlertNotification.php:109-141, app/Services/Medication/Alerts/MedicationAlertSources.php:67-85, app/Services/Medication/Alerts/MedicationAlerts.php:238-250
- **Scenario:** Kowhai House has residents A and B. Support worker W is rostered 20:00-08:00 on B's shift (shift.client_id = B) and has not clocked in yet. A's 20:00 dose becomes overdue. The Overdue doses alert has the locked-on groups 'rostered' and 'house lead', so W is selected and recorded as told (bell row, email, push). Every team_lead with access to the house is also selected. W is not assigned to A or covering A, and team_lead has neither clients.viewAny nor any medication operations key. Neither can read A's record. The bell hides the row, the link /emar/mar?client_id=A returns 404, and markRead/acknowledge return 404, so neither can attend it. If re-alerts are switched on, they keep going to them. The alert log and the Settings 'Goes to' counts say the alert reached them. When an org turns 'Keep client names and medicines out of email and push' off, W's work email and push get 'Aroha N. — Metformin, 8:00 pm dose — has no outcome…'. W is a staff member who is not allowed to read that person's medication record.
- **Fix:** In MedicationAlertRecipients::gate(), when subject->clientId is set, also require Gate::forUser($user)->allows('viewMedications', $client) (or MedicationRecordAccess::readableClientIds) for every client-bound alert, and report people who fail it as 'cannot open this person' in the alert log. Apply the same check in reach() so the Settings 'Goes to' counts are true. Separately decide whether team_lead should hold a house-wide read key (Stephan decision), because house lead is the locked-on recipient group.

### EA-016 · Running-low and out-of-stock alerts go to house leads by default with an action URL they get 403 on
- **Area:** Alerts → Stock
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Alerts/MedicationAlertSources.php:720-743, app/Services/Medication/Alerts/MedicationAlertCatalogue.php:149-152, app/Services/Medication/Alerts/MedicationAlertCatalogue.php:238-245, app/Services/Medication/Alerts/MedicationAlertRecipients.php:244-266, app/Services/Medication/Alerts/MedicationAlertRecipients.php:279, routes/emar.php:152-157, app/Notifications/MedicationAlertNotification.php:72,85,96
- **Scenario:** A resident's medicine reaches 0 on hand. The 'Out of stock' alert's default recipients are stock staff and the house lead. The team lead (no medications.stock.update) gets the in-app, email or push alert and clicks 'Open'. /emar/stock returns 403. The 'Stock running low' alert (default: house lead and stock staff) behaves the same way. Even recipients who can open the page land on the unfiltered legacy list, not on the medicine.
- **Fix:** Point stock alerts at a context deep link: packWorkflowUrl() for stock.update holders. For readers without stock.update, link to the person's MAR Medicines tab or /meds/today?view=stockalerts. Alternatively choose the URL per recipient when the notification is built (MedicationAlertNotification::toArray/toMail). Do not send a link the recipient cannot open.

### EA-017 · Emergency-access review uses medications.audit.view as an all-Site bypass, so Site-scoped coordinators and auditors see every house's grants
- **Area:** All Tasks / Emergency access
- **Audit dimension:** privacy
- **Status:** verified
- **Refs:** app/Services/Tasks/Providers/MedicationEmergencyAccessReviewProvider.php:32-35, app/Services/Tasks/Providers/MedicationEmergencyAccessReviewProvider.php:45-56, app/Http/Controllers/EmergencyAccessController.php:24-31, app/Http/Controllers/EmergencyAccessController.php:60-92, app/Services/Medication/EmergencyAccess/EmergencyAccessService.php:209, app/Policies/ClientPolicy.php:138-142, app/Services/Medication/MedicationGovernanceScopeService.php:50, database/seeders/RbacSeeder.php:748, database/seeders/RbacSeeder.php:892
- **Scenario:** A coordinator approved only for House A (seeded with medications.audit.view) opens /tasks. They see 'Review ended emergency access — <House B resident>'. On /emar/emergency-access?view=review they see House B grants with the person's name, the free-text reason, the staff member, review notes and chain-event summaries, and they can record the review. For House B, ClientPolicy::reviewBreakGlass and viewMedications deny this coordinator.
- **Fix:** Use MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS (or ClientPolicy::reviewBreakGlass) as the Site rule in the provider, controller, EmergencyAccessService (review, acknowledgeRepeat) and EmergencyAccessNotifications. If cross-house independent review is the intended product rule, record Stephan's approval and grant an explicit key instead of reusing audit.view.

### EA-018 · The All Tasks 3-day manager escalation sends medication task titles (resident names, 'Medication review', 'Review ended emergency access', 'Disputed dose') to every admin, provider_manager, coordinator, hr, finance and auditor user, at every site
- **Area:** All Tasks escalation / notifications privacy
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Console/Commands/EscalateOverdueTasks.php:119-130, app/Console/Commands/EscalateOverdueTasks.php:196-205, app/Services/NotificationService.php:26-33, app/Services/NotificationService.php:183-201, app/Services/NotificationService.php:137-161, config/notification_routing.php:1-203, app/Services/Tasks/Providers/MedicationReviewProvider.php:54-60, app/Services/Tasks/Providers/MedicationFollowupProvider.php:51-55, app/Services/Tasks/Providers/MedicationEmergencyAccessReviewProvider.php:51-55, app/Services/Medication/Alerts/MedicationNotificationVisibility.php:48-57, routes/console.php:324-330
- **Scenario:** A medication review for Aroha Ngata at Kowhai House is 3 days past its scheduled date. In the hourly tasks:escalate run, a clinical lead's aggregator pass sees the overdue item and runs level 2. notifyCrud(null, 'overdue', 'task', …) with include_managers=true and no routing rule sends a bell notification 'Overdue: R-12 Medication review — Aroha Ngata' (context: Module, Due, Assigned to) to every user holding admin, provider_manager, coordinator, hr, finance or auditor. That includes finance and HR staff with no medication permission and no access to that house. The same happens for overdue emergency-access reviews ('Review ended emergency access — <full name>') and medication follow-ups ('Disputed dose — <name>').
- **Fix:** In EscalateOverdueTasks level 2, resolve managers per item: role holders who also pass the provider's own authorization for that item (reuse aggregator->authorizedWatcherItemForDelivery or a provider canView plus site check). At minimum, for sources under the medication module, send a neutral title ('Overdue medication task at <house>') and set recipient_scope so filterRecipientScope applies viewMedications. Alternatively add a tasks.overdue_escalation route with include_managers=false and target_groups managers_core, plus a site filter.
- **Collision:** Low: app/Services/Tasks/* (H&S lane touches TaskAggregator/TaskSearch). EscalateOverdueTasks.php and NotificationService.php are in no lane.

### EA-019 · Clock-out blocks on doses that aren't due yet and on doses at the shift boundary; only managers can force
- **Area:** Attendance clock-out
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Refs:** app/Domain/Hr/Services/AttendanceService.php:1991-2063, app/Domain/Hr/Services/AttendanceService.php:1561-1571, app/Domain/Hr/Services/AttendanceService.php:368-381, app/Domain/Hr/Services/AttendanceService.php:1592-1597, app/Domain/Hr/Services/AttendanceService.php:1919-1927, resources/js/pages/attendance/components/clock-out-wizard.tsx:150
- **Scenario:** Case 1: a sleepover worker on 22:00–08:00 tries to clock out at 07:55. The 08:00 dose belongs to the day shift but is inside the scheduled window, so clock-out is blocked ('1 scheduled medication still needs a MAR entry'). Case 2: a worker sent home sick at 11:30 from 07:00–15:00 is blocked by the 12:00 and 14:00 doses. Neither a support worker nor a team lead can force it. If a manager forces it, the handover still says medications were completed, because the wizard defaults medsCompleted to true.
- **Fix:** Count only doses whose window has closed (or that are due) by the clock-out instant, inside the actual worked interval, and exclude doses due at or after the scheduled end. Feed the computed count into the handover instead of a self-declared tick. Consider letting team_lead force with a reason.
- **Collision:** AttendanceService.php (medium: another lane's territory, l.1991+)

### EA-020 · General audit log (/audit-logs, /settings/audit-logs and its CSV export) lists every person's medication and controlled-register audit rows across all Sites to audit.viewAny holders with no medication access
- **Area:** Audit log (general)
- **Audit dimension:** privacy
- **Status:** verified
- **Refs:** app/Services/Audit/AuditLogViewService.php:82-131, app/Services/Audit/AuditLogViewService.php:134-170, app/Http/Controllers/AuditLogController.php:15-41, app/Http/Controllers/Settings/AuditLogSettingsController.php:34-60, routes/reports.php:41-43, routes/settings.php:329-334, app/Http/Controllers/ModuleReportController.php:276-277, app/Http/Controllers/ModuleReportController.php:312-330, app/Services/EnhancedMarService.php:1704, app/Services/Medication/Controlled/ControlledRegisterService.php:645, database/seeders/RbacSeeder.php:860, database/seeders/RbacSeeder.php:1006
- **Scenario:** An HR user (audit.viewAny, no medications.* key) or a Board Trustee (audit.viewAny) opens /audit-logs?client_id=<Jane> or searches her surname. They see 'Medications Controlled Entry Record', subject 'Client Controlled Drug Entry', and 'Medications Administration Record' rows for Jane at any house, with actor and time. This shows she is on a controlled medicine and when doses were given. /settings/audit-logs/export downloads up to 5,000 such rows as CSV.
- **Fix:** Move excludeMedicationAuditFamilies into a shared helper and apply it in AuditLogViewService::query() for every viewer. Medication audit is served by /emar/reports?view=audit under MedicationReportAccess. Alternatively, apply it only for viewers lacking medications.audit.view and add Site scoping. Apply the same in the Settings CSV export.

### EA-021 · Leave with a recorded departure but no recorded return silences every later dose indefinitely
- **Area:** Away (client leave) / overdue alerts
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Refs:** config/medications.php:68-72, app/Services/Medication/DoseSlots/DoseAwaySources.php:92-98, app/Services/Medication/DoseSlots/DoseAwaySources.php:252-269, app/Services/Medication/DoseSlots/ScheduledDoseStates.php:209-217, app/Models/ClientLeaveRequest.php:21-22, app/Services/Medication/DoseSlots/OverdueDoses.php:93-100
- **Scenario:** A resident leaves for day leave at 09:00 and staff record the departure. The resident is back at 16:00, but nobody presses 'Return'. From then on the 17:00 and 20:00 doses, and every dose on later days, read 'Away · On leave (since …)'. They are never due or overdue, not on the badge, not in the clock-out count, not alerted, and not recordable from Meds today or rounds, until someone records the return.
- **Fix:** Bound leave Away by ends_on (end of the planned day plus a grace period). Past that point, treat doses as owed, or raise a 'Has X returned?' alert and follow-up to the house lead. Do the same review for hospital admissions with no discharge.

### EA-022 · Client profile Transport tab shows medication-transit names and the controlled flag without the medical-section or controlled-view checks the Medical tab applies
- **Area:** Client profile › Transport
- **Audit dimension:** privacy
- **Status:** verified
- **Refs:** app/Http/Controllers/ClientController.php:1717-1719, app/Http/Controllers/ClientController.php:3381-3398, app/Services/Clients/ClientProfileSectionAccess.php:78, app/Services/Clients/ClientProfileSectionAccess.php:107-109, app/Http/Controllers/ClientController.php:493-500, resources/js/pages/operations/clients/show.tsx:6183, resources/js/pages/operations/clients/show.tsx:6693
- **Scenario:** An auditor (seeded with clients.viewAny, assets.viewAny and medications.view, but no medications.controlled.view) opens a resident's profile › Transport. medication_logs lists 'Oxycodone 5mg' with the Controlled badge, packer and administrator. The same reader's Medical tab removes controlled rows. Any custom role with fleet.viewAny or assets.viewAny and client view but no medications.view sees the names too.
- **Fix:** In buildTransportData, include medication_logs only when $sectionAccess['medical'] is true. When the reader lacks medications.controlled.view, drop controlled rows or replace the name with 'Controlled medicine', as the Medical tab does. Pass both flags into buildTransportData.
- **Collision:** ClientController.php: H&S lane (§3 medium #15). The Transport fix lives here, so agree the order first.

### EA-023 · Client-profile order routes write ClientMedication directly, bypassing MedicationOrderWorkflow; PUT can hold or resume a checked order with no prescriber instruction
- **Area:** Client profile / Orders (P04)
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** routes/clients.php:106-133, routes/operations.php:287, routes/operations.php:352-356, app/Http/Controllers/ClientMedicalController.php:174-299, app/Http/Controllers/ClientMedicalController.php:301-412, app/Http/Controllers/ClientMedicalController.php:348, app/Http/Controllers/ClientMedicalController.php:359, app/Http/Controllers/ClientMedicalController.php:377-378, app/Models/ClientMedication.php:40-71, app/Models/ClientMedication.php:148-170, app/Http/Controllers/Emar/MedicationOrdersController.php:266-298, app/Services/Medication/MedicationLegacyOrderBridge.php:20-41, app/Http/Controllers/Emar/EmarController.php:8384-8541
- **Scenario:** A nurse holds a Warfarin order through Orders › Hold and records the GP's instruction. Later a coordinator with clients.update and orders.manage and a covering shift sends PUT /operations/clients/12/medical/medications/88 with {"name":"Warfarin","state":"active"}. The name is unchanged, so no VERIFICATION_SENSITIVE_FIELDS value is dirty and approval_status stays 'verified'. state='active' and active=true are saved, and paused_at is left set. The held anticoagulant becomes administrable and due on Meds today again. There is no prescriber instruction, no MedicationOrderAction 'resumed' and no 'order.resumed' P09 event; P04 resume requires a reason and records both (MedicationOrdersController.php:283-297). Sending state:'paused' silently holds an order the same way. POST .../medical/medications creates an order with no version, revision or allergy check; it is forced to pending_verification and then sits in Orders › To check with no version to check, so the checker is sent to re-enter it ('entry'). /emar/medications/import (CSV, reachable from the legacy Medications page) bulk-creates orders the same way. It uses Site access only, with no covering-shift authority, and never sets controlled_drug, so a CSV 'Morphine' is created as ordinary.
- **Fix:** Delete clients.medical.medications.store\|update and the operations.* copies. If a compatibility endpoint is needed, delegate it to MedicationLegacyOrderBridge::enter like EmarController::storeMedication. Delete importMedications, or turn it into a P04 draft importer that runs MedicationOrderWorkflow::enter per row. Allow state, active and paused_at changes only through P04 hold, resume and stop.
- **Collision:** routes/operations.php is shared (clinical-chart gates l.455-538 belong to another lane) — edit only l.352-356; ClientMedicalController and routes/clients.php are not on the lane list

### EA-024 · Orphan client-profile administration route can record an unslotted 'given' for a scheduled medicine, so the slot stays due (double-dose risk)
- **Area:** Client profile / Record dose
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** routes/clients.php:196-199, routes/operations.php:614-617, app/Http/Controllers/ClientMedicalController.php:606-890, app/Http/Controllers/ClientMedicalController.php:644-660, app/Http/Controllers/ClientMedicalController.php:701-710, app/Http/Controllers/ClientMedicalController.php:846, app/Services/EnhancedMarService.php:893-916, app/Services/EnhancedMarService.php:1367-1378, app/Services/Medication/DoseSlots/DoseSlotOutcomeWriter.php:68-71, app/Http/Controllers/Api/MedicationsApiController.php:971-975, resources/js/components/clients/profile/emar-dialog.tsx:321, resources/js/lib/emar-offline.ts:124
- **Scenario:** A support worker with administer.record and a covering shift sends, or a stale offline-queue item replays, POST /operations/clients/12/medical/medications/55/administrations with {status:'given'} and no scheduled_for, for a scheduled 08:00 Metformin. EnhancedMarService runs the time-window check and the slot-duplicate check only when scheduledFor is set (:894, :1368). DoseSlotOutcomeWriter::syncFor returns early when scheduled_for is null. So a 'given' row is saved but the 08:00 slot stays due or overdue. Overdue alerts fire, and another worker records and gives the 08:00 dose again. The MAR then holds two 'given' records. The controller's own pre-check also uses Carbon::parse in the app timezone (UTC) with a fixed 30/60 minutes (:648-660), unlike the configured NZ window.
- **Fix:** Delete storeAdministration and both routes, together with EmarRecordDialog and its test; the profile already uses RecordDoseDialog through use-dose-recorder. Before deleting, check for persisted offline-queue entries that target /medical/medications/*/administrations and convert or fail them visibly. If the routes must stay for a while, require scheduled_for for non-PRN orders exactly as the API does and remove the UTC pre-check.
- **Collision:** routes/operations.php (shared file; edit only l.614-617)

### EA-025 · Prescriber stop/change requests from the portal notify nobody and can only be found person by person
- **Area:** Connected care / clinical portal
- **Audit dimension:** connected-care
- **Status:** verified
- **Type:** UI
- **Refs:** app/Services/Medication/ExternalClinical/ExternalClinicalProposals.php:74-84, app/Http/Controllers/Emar/MedicationExternalClinicalController.php:65-67, app/Http/Controllers/Emar/MedicationExternalClinicalController.php:160-163, resources/js/pages/emar/ConnectedCare.tsx:134-141, resources/js/pages/emar/ConnectedCare.tsx:152-156, resources/js/pages/emar/ClinicalPortal.tsx:493-495
- **Scenario:** A named GP uses the portal to request 'Stop warfarin' for a resident and is told it was 'sent for internal clinical review'. Nothing is created to prompt review: no alert, notification, All Tasks item, Orders to-check row or MAR banner. The Connected care header shows 'Requests 1', but clicking it shows 'Choose a person…', so the coordinator has to pick each person in turn to find it. Staff keep giving warfarin from the unchanged chart. This is dormant today because nobody holds medications.external.manage. Once a prescriber is granted access it is P0-class.
- **Fix:** When a proposal is submitted, raise a medication follow-up or alert routed to orders.manage holders for the person's house, plus an All Tasks item until it is decided. Add a Requests list that works with no person selected. Show a 'Prescriber request waiting' marker on the person's MAR and Orders row.
- **Collision:** app/Services/Tasks/TaskAggregator.php (Low) if an All Tasks provider is added; the alert work stays in app/Services/Medication/** (safe)

### EA-026 · Control Room maintenance windows suppress medication signals while ignoring each rule's suppress_in_maintenance=false. Required-delivery medication signals then fail closed and roll back the dose or error record (latent: nothing on main activates a window)
- **Area:** Control Room / dose recording / medication errors
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/ControlRoom/SignalProcessingService.php:189-194, app/Services/ControlRoom/SignalProcessingService.php:1040-1059, database/migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php:96, app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:51, app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:250, app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:381-398, app/Services/Medication/MedicationSignalService.php:103-104, app/Services/Medication/MedicationSignalService.php:157-165, app/Services/Medication/MedicationSignalService.php:182-211, app/Services/Medication/MedicationErrorReporter.php:54-58, app/Services/Medication/MedicationErrorReporter.php:82-99, app/Services/EnhancedMarService.php:1697-1699, app/Services/EnhancedMarService.php:1832-1837, app/Services/Medication/MedicationScopeDecisionService.php:52-66, app/Services/Medication/OverdueDoseAlerts.php:243-246, tests/Feature/Emar/MedicationErrorsTest.php:629-660
- **Scenario:** An active Control Room maintenance window exists with signal_source_id NULL and site_id = the house, or NULL for all sites. Today that can only come from data, a seed or a future activator. A worker records a dose with 'More than ordered was given' at severity major. raiseMoreThanOrderedError → ensureIncident → emitError is a required delivery with an incident claim. process() marks the signal suppressed and returns null, and emit() throws MedicationSignalDeliveryException. That exception is not handled anywhere, so the forAdministration DB::transaction rolls back and the dose that was actually given is not recorded (500). The same happens for a refused controlled or high-risk dose and for a dose >2 h late, because their hooks emit with incident_id. /emar/errors major or critical reports also fail, as MedicationErrorsTest:629 asserts.
- **Fix:** Agree the order with the H&S lane. In SignalProcessingService::process, resolve the matching rule first and suppress only when $rule->suppress_in_maintenance is true. Never suppress CATEGORY_MEDICAL_WELLBEING (the H&S change). Keep the eMAR tests consistent by updating OverdueDoseAlertsTest ~l.166 and MedicationErrorsTest ~l.629, and remove the dead suppressed branch. A Control Room-side problem could otherwise stop a clinical record, so never let required delivery roll back a dose record: record the failure in an outbox and retry.
- **Collision:** HIGH: app/Services/ControlRoom/SignalProcessingService.php (H&S lane, isInMaintenanceWindow at main l.1040 / H&S l.1044).

### EA-027 · House leads and clinical leads cannot make the downtime pack, against the approved P10 design
- **Area:** Downtime (P10) / Reports › Print & exports
- **Audit dimension:** rbac
- **Status:** verified
- **Refs:** app/Services/Medication/Downtime/DowntimePackService.php:54-56, app/Services/Medication/Downtime/DowntimePackService.php:195-200, app/Services/Medication/Downtime/DowntimeAccess.php:27-32, app/Http/Controllers/Emar/MedicationReportsController.php:98, app/Http/Controllers/Emar/MedicationDowntimeController.php:41, app/Services/Medication/Reporting/MedicationReportingPermissions.php:13, database/seeders/RbacSeeder.php:915-956, database/seeders/RbacSeeder.php:1019-1034, resources/js/pages/emar/reports/hub.tsx:876-905
- **Scenario:** Before a planned outage, the house lead (team_lead) at Rimu opens Reports & audit › Print & exports. 'Make pack' is disabled with 'Export access is required for this pack.' A direct POST to /emar/downtime/pack returns 403. A clinical lead gets the same result. Only coordinators, provider managers and admins (reports.export) can produce the paper MAR that staff need during downtime.
- **Fix:** Gate the pack as P10 does: reports.view plus a lead capability (errors.manage, or a new medications.downtime.pack key with a grant migration to team_lead, clinical_lead, coordinator and PM). Apply it in assertPackAuthority, DowntimeAccess::siteIds(pack) and both 'allowed' flags. Keep the finance-only exclusion and the controlled-pages rule.

### EA-028 · A downtime record cannot be finished, and its paper doses cannot be captured or posted, if the order or its medicine rule changes after the downtime is declared
- **Area:** Downtime paper reconciliation (P10)
- **Audit dimension:** workflows-leads
- **Status:** verified
- **Refs:** app/Services/Medication/Downtime/PaperEntryService.php:47-56, app/Services/Medication/Downtime/PaperEntryService.php:60-70, app/Services/Medication/Downtime/PaperEntryService.php:115-117, app/Services/Medication/Downtime/PaperEntryService.php:452-497, app/Services/Medication/Downtime/DowntimeService.php:115-122, app/Services/Medication/Downtime/PaperAdministrationWriter.php:38-41, app/Services/Medication/Downtime/HistoricalPaperContext.php:44
- **Scenario:** 1. Monday: the clinical lead declares a downtime for Saturday and Sunday, listing 40 paper doses. 2. Tuesday: the GP changes the paracetamol dose. The office enters version 3 and it is checked. Separately, someone holds an order, or edits a site medicine rule in Settings. 3. Wednesday: staff try to enter the Saturday 08:00 paracetamol paper dose. The preview fails with 'The order changed after this downtime was listed. The clinical lead must resolve the historical order before entry.' 4. No route exists to do that: resolve only links existing eMAR or paper evidence. 5. finish() then refuses until every listed dose has an entry or a resolution, so the downtime stays open for good. Entries already captured before the change are refused at reconcile with 'The order or its clinical requirements changed'.
- **Fix:** Bind paper entries to the order version in effect at the paper time (MedicationOrderVersion or DoseOrderTimeline), not to the live row. Add a clinical-lead 'review historical order' resolution that records the reviewed version and lets capture continue. Let finish() accept a reasoned 'reviewed, not capturable' resolution. Stop treating a later stop or hold as a reason to refuse posting a dose that was given while the order was active.

### EA-029 · Overview 'CD balance check due › Start count' opens a register modal that always gets a 422 and never records the count
- **Area:** eMAR overview / Controlled register
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/MedicationOverviewService.php:748-760, resources/js/pages/emar/Index.tsx:949-965, resources/js/pages/emar/Index.tsx:1862-1872, resources/js/pages/emar/components/cd-register-modal.tsx:62-69, resources/js/pages/emar/components/cd-register-modal.tsx:90-105, resources/js/pages/emar/components/cd-register-modal.tsx:226-235, routes/emar.php:406-408, app/Http/Controllers/Emar/ControlledProductController.php:36-37, app/Http/Controllers/Emar/ControlledProductController.php:87-89, app/Http/Controllers/Emar/ControlledProductController.php:163-174
- **Scenario:** A clinical lead or house lead with controlled.view and controlled.record opens /emar. The action centre shows 'Rimu House — CD balance check due' with a 'Start count' button. Clicking it opens CdRegisterModal. The modal offers entry types Receipt/Administration/Disposal/Transfer/Adjustment, none of which is a count. The lead completes three steps, including the witness PIN, and submits. POST /emar/controlled/entries reaches ControlledProductController::legacy, which maps the route to 'movement' and runs abort_unless(in_array(movement_type, [going_out, coming_back, breakage, spillage])) → 422 'Choose a witnessed movement in the controlled register.' Every submission fails. Even with a movement_type, action() would still reject the payload: it requires expected_balance and actual_balance, and expected_entry_id must be present, and the modal sends none of these. The 422 is an HttpException, not a validation error, so Inertia shows an error overlay instead of the modal's onError toast. The witnessed count is never recorded and the count stays due.
- **Fix:** For cd_balance items, open the canonical count instead: link to /emar/controlled?client_medication_id=…, or mount components/emar/controlled/action-dialog with action='count'. Delete cd-register-modal.tsx and CD_REGISTER_ENTRY_TYPES. Move the MedicationOption type that stock-movement-modal imports into a shared types file. Add a feature test that posts the exact modal or overview payload.

### EA-030 · medications.breakglass.end is also used as a Site bypass, so a coordinator can end live emergency access at houses they are not approved for
- **Area:** Emergency access › End access (P10)
- **Audit dimension:** rbac
- **Status:** verified
- **Refs:** app/Services/Medication/EmergencyAccess/EmergencyAccessService.php:172-181, app/Http/Controllers/BreakGlassController.php:56-66, app/Http/Controllers/BreakGlassController.php:111-115, app/Http/Controllers/EmergencyAccessController.php:73, routes/emar.php:546-548, database/seeders/RbacSeeder.php:1064-1066
- **Scenario:** A coordinator approved for Kōwhai House opens /emar/emergency-access. The list shows every Site because of the audit.view bypass in the previous finding. They see an active grant a night worker started at Rimu House to give a seizure PRN, and click End access with a 10-character reason. The grant ends immediately, and the worker loses access to the record in the middle of the emergency, from someone with no authority over that house.
- **Fix:** In EmergencyAccessService::end and BreakGlassController::assertClientSiteAccess, use MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS as the bypass for the non-owner path, and compute can_revoke against the grant's Site in EmergencyAccessController. Add a test: a Kōwhai coordinator gets 404 ending a Rimu grant.

### EA-031 · An emergency grant that expires while a dose is being recorded has no countdown and returns a generic 403. The purpose-built 'access ended' response is wired only to follow-ups.
- **Area:** Emergency access (P10) × recording dialog / MAR
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/emar/emergency-access-strip.tsx:17-80, resources/js/pages/emergency/access.tsx:362-371, app/Services/Medication/MedicationScopeDecisionService.php:986-1004, app/Services/Medication/MedicationScopeDecisionService.php:1136-1139, app/Exceptions/MedicationEmergencyAccessEnded.php:7-17, app/Services/Medication/Followups/MedicationFollowupClinicalScope.php:45, resources/js/components/emar/followups/followup-dialog.tsx:555-561, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1452-1502, resources/js/lib/emar-offline.ts:380-393, resources/js/components/emar/record/record-dose-launch.tsx:160-165, resources/js/pages/operations/clients/tabs/mar.tsx:281-285
- **Scenario:** 1. A worker starts emergency access for Aroha (a 30-minute grant), opens her MAR and starts recording insulin. The witness takes 25 minutes to arrive. 2. The grant expires. Nothing on the MAR or in the dialog showed a countdown, an 'ending soon / Extend' prompt or an ended state. 3. On 'Record outcome' the server returns 403 'You do not have a current assignment for this medication action.' 4. The dialog shows 'Not recorded — this dose was not saved. You do not have a current assignment…' with a 'Record outcome' retry that can never succeed. The recovery is cleared. 5. The approved P10 'Grant expired mid-task' state ('Your emergency access for X ended at 9:10 am… Start it again') never appears. The MAR's no-shift copy ('Clock in to a shift covering X…') never offers 'Start emergency access' to break-glass holders.
- **Fix:** - Throw `MedicationEmergencyAccessEnded` from `MedicationScopeDecisionService::activeBreakGlass` when the actor held a grant for this person that has now expired. - In `submitEmarMutation`, exclude `code === 'emergency_access_ended'` from the 409 `conflict` mapping. In `RecordDoseForm`, map it to the approved ended state, keeping the draft (do not clear the recovery). - Add the grant (`id`, `expires_at`) to the requirements payload when authority is break-glass. Mount `EmergencyAccessStrip` in the dialog rail and on record/show.tsx. - Add 'Start emergency access' to the no_shift message for `breakGlass` holders.

### EA-032 · Fleet medication-in-transit register: the compliance CSV prints UTC times without a zone, the date filters cut at UTC midnight, and 'Packed today' starts at 13:00 NZDT
- **Area:** Fleet / Medications in transit
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/FleetAssets/ResidentTransportController.php:906-912, app/Http/Controllers/FleetAssets/ResidentTransportController.php:929-955, app/Http/Controllers/FleetAssets/ResidentTransportController.php:959-963, resources/js/pages/fleet-assets/transports/medications.tsx:229, resources/js/pages/fleet-assets/transports/medications.tsx:319-338, resources/js/pages/fleet-assets/transports/medications.tsx:426
- **Scenario:** (1) A coordinator exports the 'medication-transit-audit' CSV (the button at medications.tsx:426) for a controlled-drug custody check. A morphine pack signed out at 08:15 NZDT on 9 Oct shows 'Packed At 2026-10-08 19:15'; the administered and returned columns are shifted the same way, so the custody timeline reads as the previous evening. (2) Filtering date_from = date_to = 2026-10-09 runs packed_at >= '2026-10-09' AND <= '2026-10-09 23:59:59' on the UTC column. Every pack from 00:00–12:59 NZDT on 9 Oct is excluded, and packs from 00:00–12:59 NZDT on 10 Oct are included. (3) At 14:00 NZDT the 'Packed today' stat (packed_at >= now()->startOfDay(), which is UTC midnight = 13:00 NZDT) no longer counts the 08:15 pack; at 09:00 it counts yesterday afternoon's packs.
- **Fix:** Parse date_from/date_to as NZ days: CarbonImmutable::parse($d, 'Pacific/Auckland')->startOfDay()->utc() and ->endOfDay()->utc(). Make 'today' now('Pacific/Auckland')->startOfDay()->utc(). In the CSV, format ->timezone('Pacific/Auckland')->format('Y-m-d H:i T'), as MedicationsReportController:374 already does. The CSV should also go through MedicationExportGuard; that is a separate privacy finding.

### EA-033 · Fleet transport still records with a given-only wizard; the shared dialog's transport mode is built but never called
- **Area:** Fleet / transport medication
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.tsx:1276-1340, resources/js/pages/fleet-assets/transports/show.tsx:1252, resources/js/pages/fleet-assets/transports/medications.tsx:717, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1731-1740, resources/js/components/emar/record-dose/record-dose-dialog.tsx:2577
- **Scenario:** On an outing, the person refuses their 12:00 dose that was packed for the trip. The driver opens 'Record transport administration'. The only input is 'quantity administered', which must be at least 0.01, so there is no Refused, Withheld or Not given outcome, no time, and no reason. The driver either records a false 'given' or records nothing, and the refusal never reaches the MAR or follow-ups.
- **Fix:** Replace AdministerTransportMedicationWizard in both Fleet pages with RecordDoseDialog using entry='transport' and the transport context: vehicle, trip and packed-by. Keep the transit stock and pack-line capture as a step within that dialog, or post it alongside, and retire the wizard.

### EA-034 · Handover 'Discrepancy found' controlled-drug count goes nowhere, and 'Counts verified' doesn't count as the register check
- **Area:** Handover (eMAR lens) › Controlled-drug count
- **Audit dimension:** workflows-frontline
- **Status:** verified (claimed P0)
- **Refs:** app/Services/ShiftHandoverService.php:2087-2213, app/Services/ShiftHandoverService.php:316, app/Services/Operations/HandoverPresenter.php:110-114, resources/js/pages/operations/handovers/components/handover-wizard.tsx:1460-1523, app/Services/Medication/Controlled/ControlledRegisterService.php:237-270, app/Services/Medication/Controlled/ControlledPolicy.php:108-136
- **Scenario:** At 22:00 shift change the outgoing and incoming workers count the CD cupboard together. The count is 2 tablets short. Following the wizard's 'Reconcile the controlled-drug register with the incoming worker at shift change', they pick 'Discrepancy found', add notes and enter the witness PIN. Nothing reaches the register: no discrepancy record, no house-lead owner, no incident, no alert. The register balance stays wrong. Where the house counts every shift ('verified' each time), the register still shows the count overdue and escalates every 5 minutes.
- **Fix:** Route the handover check through ControlledRegisterService::count, per controlled medicine, with expected and actual balances and the same witness. Or, at minimum, have 'Discrepancy found' open the register's discrepancy workflow (discrepancy row, lead owner, incident) and stop presenting 'Counts verified' as a register count unless per-medicine counts are posted.
- **Collision:** The service fix is in ShiftHandoverService.php (not listed) plus ControlledRegisterService (safe). UI changes touch handover-wizard.tsx and HandoverController.php (medium: handover seams).

### EA-035 · Auto-created medication incidents, Control Room signals and dashboard alerts print UTC times as if they were NZ times
- **Area:** Health & Safety / Incidents ← eMAR recording
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Services/MedicationIncidentIntegrationService.php:82, app/Services/MedicationIncidentIntegrationService.php:91, app/Services/MedicationIncidentIntegrationService.php:413-416, app/Services/MedicationIncidentIntegrationService.php:944, app/Services/MedicationIncidentIntegrationService.php:992, app/Services/MedicationIncidentIntegrationService.php:997-999, app/Services/MedicationIncidentIntegrationService.php:1021, app/Services/EnhancedMarService.php:2251-2267, app/Services/Medication/Recording/RecordingContractEnforcer.php:412, app/Services/Medication/MedicationSignalService.php:146-147, app/Models/ClientMedicationAdministration.php:128-129, config/app.php:99
- **Scenario:** A support worker records Kōwhai House resident X's 08:00 NZDT dose on 9 Oct 2026 (stored scheduled_for 2026-10-08 19:00:00 UTC) as 'missed'. fireIncidentHooks calls handleMissedDose, which always creates the incident (shouldAutoCreateIncident returns true). The draft incident description reads 'Scheduled time: 08/10/2026 19:00': wrong date and a time 13 hours off. The Control Room signal title/description and the MedicationDashboardAlert read 'Missed dose: Paracetamol scheduled for 19:00'. A dose given late at 10:30 NZDT produces 'Scheduled: 08/10/2026 19:00 / Given: 08/10/2026 21:30'. Unsafe-correction incidents ('- Time:') and refusal-escalation incidents ('Follow-up due:') have the same fault. The incident reviewer, and any HQSC/HDC submission built from the narrative, sees evening times on the previous day for a morning event.
- **Fix:** Add one private helper, e.g. nz(?Carbon $at, string $format) returning $at?->copy()->timezone(config('app.worker_timezone'))->format($format), and use it at all 8 format sites. Append the zone abbreviation (format 'd/m/Y H:i T') in incident narratives so the time is unambiguous. For line 998, parse the request value with MarScheduleService::parseWorkerDateTime instead of `new Carbon(...)`.

### EA-036 · Auto-created medication incidents (dose >2 h late, refused high-risk or controlled, missed) are orphan drafts: no shift_id, no notification, and Control Room rules with no recipients
- **Area:** Incidents / Control Room routing
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/MedicationIncidentIntegrationService.php:61-75, app/Services/MedicationIncidentIntegrationService.php:87-103, app/Services/MedicationIncidentIntegrationService.php:408-425, app/Services/MedicationIncidentIntegrationService.php:437-455, app/Services/MedicationIncidentIntegrationService.php:480-498, app/Services/MedicationIncidentIntegrationService.php:509-525, app/Services/EnhancedMarService.php:1832-1837, app/Services/EnhancedMarService.php:2251-2266, app/Domain/Hr/Services/AttendanceService.php:1541-1559, app/Observers/ClientIncidentObserver.php:37-41, database/migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php:160-181, database/migrations/2026_04_12_150000_expand_medication_signal_types_for_exceptions.php:51-60, app/Services/Medication/Alerts/MedicationAlertCatalogue.php:110-274
- **Scenario:** At 20:00 a support worker on a clocked-in shift at a house records that the resident refused their controlled or high-risk 08:00 medicine, or records a dose given 3 hours late. fireIncidentHooks creates a 'draft' ClientIncident and a Control Room alert ('Refused dose', 'Late dose'). The draft has no shift_id. At clock-out, AttendanceService's 'Submit draft incidents' blocker counts only drafts WHERE shift_id = this shift, so the worker is never prompted. A draft triggers no ClientIncidentObserver journey or governance escalation, and no 'incidents.draft_created' notification is sent. The Control Room rules for missed, late and refused dose were seeded with notify_roles [], and Medication Settings has no alert type for these events, so nobody is pushed. The incident never gets submitted or reviewed. The same applies to 'missed' records from the legacy API and correction paths, which also settle the overdue alert as 'recorded'.
- **Fix:** In the three handlers set $incident->shift_id = $locked->shift_id (and keep reported_by), so the clock-out blocker and the incident list's shift filter find them. Then either (a) route these Control Room rules to recipients through a grant/data migration (timestamp after 2026_10_08_235100), or (b) add a catalogue alert (for example 'Dose late or refused – incident to submit') sent to house lead and clinical lead. Decide with Stephan which channel owns it.

### EA-037 · The EMAR_PERSON_RECORD=legacy rollback shows a MAR allergy rail that leaves out every allergy entered since canonical allergies shipped
- **Area:** MAR & medicines / flags
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** config/medications.php:4-6, app/Http/Controllers/Emar/EmarController.php:1250-1258, app/Http/Controllers/Emar/EmarController.php:1377-1386, resources/js/pages/emar/MarCharts.tsx:628-636, app/Services/Medication/ClientAllergyRecordService.php:16-27, app/Services/Medication/ClientAllergyRecordService.php:89-140
- **Scenario:** Ops set EMAR_PERSON_RECORD=legacy for 'rollout recovery', as the config comment invites. A nurse opens /emar/mar?client_id=12 for a resident whose penicillin anaphylaxis was entered last week through the canonical health-profile allergy record. The legacy MarCharts ClinicalRail lists only the old medication_allergies register, so it shows 'no allergies' or an outdated list. A removed (entered-in-error) canonical entry also reappears from its preserved register row.
- **Fix:** In the legacy branch, build 'allergies' from app(ClientAllergyRecordService::class)->forClient($selectedClient), mapped to the allergen, reaction and severity shape, or retire the legacy fallback (decision §2A.14). Add a legacy-mode test that asserts a canonical-only allergy appears.

### EA-038 · Legacy correction routes give site-wide correction and approval power without the person rule (support workers can correct people they cannot open)
- **Area:** MAR corrections
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** routes/clients.php:201-204, routes/operations.php:619-622, routes/medications.php:52-58, routes/emar.php:569-575, app/Http/Controllers/MedicationAdministrationCorrectionController.php:35-75, app/Http/Controllers/MedicationAdministrationCorrectionController.php:180-235, app/Http/Controllers/MedicationAdministrationCorrectionController.php:307-312, app/Services/Medication/MedicationGovernanceScopeService.php:417-453, app/Services/Medication/MedicationGovernanceScopeService.php:1602-1660, app/Http/Controllers/Emar/PersonMedicationCorrectionController.php:19-29, app/Policies/ClientPolicy.php:65-124, database/seeders/RbacSeeder.php:763-824, resources/js/pages/emar/components/mar-governance-dialogs.tsx:1140-1147
- **Scenario:** Support worker Sam at Rimu House is not assigned to Jo and is not on shift with Jo, so the P02 record returns 404 for him (ClientPolicy::viewMedications is person-scoped for clients.viewAssigned). Support workers hold administer.correct (RbacSeeder support_worker block, l.824). Sam sends POST /operations/clients/{Jo}/mar/administrations/{id}/corrections with {status:'refused'}. Within 30 minutes no correction_reason is needed (MAC::store validator). MedicationAdministrationCorrectionController::store runs MedicationGovernanceScopeService::forClient, which checks only capability and Site, and creates a pending correction to Jo's dose. A second support worker at the Site sends POST /medications/corrections/{c}/approve; approve checks only that the approver is not the requester. Jo's effective dose record flips from given to refused. The canonical P02 route (/emar/clients/{c}/record/doses/{a}/corrections/request) would 404 both of them, because PersonMedicationCorrectionController wraps the same handler with MedicationRecordAccess and requires a reason.
- **Fix:** Delete clients.mar.administrations.corrections.store, operations.clients.mar.administrations.corrections.store and medications.corrections.approve\|reject. Gate emar.corrections.approve\|reject with MedicationRecordAccess, or delete them along with legacy MarCharts. PersonMedicationCorrectionController then remains the single correction seam. Also add the person rule inside MedicationAdministrationCorrectionController so any future caller inherits it.
- **Collision:** routes/operations.php (shared; edit only l.619-622)

### EA-039 · Meds today before clock-in says 'No scheduled doses on your shift today', and shows on-call as 'Not configured' even when it is configured.
- **Area:** Meds today (P01) empty / not-clocked-in state
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:1154-1165, app/Http/Controllers/Emar/WorkerMedsController.php:242-255, app/Http/Controllers/Emar/WorkerMedsController.php:377-382, app/Services/Medication/DoseSlots/DoseSlotReaderScope.php:72-97, app/Policies/ClientPolicy.php:99-105, resources/js/pages/meds/today/_schedule.tsx:48-51, resources/js/pages/meds/today/_schedule.tsx:290-316, resources/js/pages/meds/today/_schedule.tsx:339-376, resources/js/pages/meds/today/_schedule.tsx:347-410, resources/js/pages/meds/today/index.tsx:921-924
- **Scenario:** 1. A relief support worker is rostered on a 7 am shift for two residents who are not assigned to them. They open Meds today before clocking in, or because attendance is down. 2. Their people are filtered out until clock-in. The page shows:    - an empty-state title 'No scheduled doses on your shift today' (the caption says medicines for 2 more people show once clocked in)    - a banner 'You can read today’s medicines…' although none are shown    - 'Can’t clock in? Coordinator on call: [Not configured]', even though Settings › On-call has a contact for the house 3. If some people are visible (assigned) and their doses are done, a success notice claims 'Nothing left to record on your shift… No further staff doses are due', while the hidden people's doses are outstanding.
- **Fix:** - Compute `on_call` and `house_label` from the unfiltered shift client IDs (or the shift's site). - When `people_after_clock_in > 0`, use a title such as 'Clock in to see your shift’s medicines'. - Suppress the 'Nothing left to record' notice while people are hidden. - Change the banner copy to 'You can’t see or record these medicines until you clock in'.

### EA-040 · An actual leave departure, hospital admission or respite check-in with no recorded return makes every later dose 'Away' indefinitely. Never chased, badged or alerted (away.from_leave defaults ON)
- **Area:** Meds today / Away sources
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** config/medications.php:66-72, app/Services/Medication/DoseSlots/DoseAwaySources.php:73-99, app/Services/Medication/DoseSlots/DoseAwaySources.php:28-38, app/Services/Clients/ClientLeaveWorkflow.php:101-110, app/Models/ClientLeaveRequest.php:47-57
- **Scenario:** Staff record a resident's actual departure for a weekend home visit (planned ends_on Sunday). They bring the resident back on Sunday but nobody presses 'Returned'. From Sunday evening every scheduled dose shows 'Away · On leave (since Fri …)' and is never due, late or overdue. Nothing appears in the overdue badge, overdue alerts or clock-out checks, so a week of missed doses goes unnoticed. The same applies to a hospital admission with no linked discharge event, and to a respite stay that was checked in but never discharged.
- **Fix:** Cap Away at a safe bound when the return or discharge is missing. For leave, stop treating doses as Away after ends_on plus a grace period (end of that NZ day), then let them become due or overdue again. For all three sources, add an 'Away past its planned end, no return recorded' item to Meds today, All Tasks and the alert catalogue. Alternatively, require a planned return for actual departures and raise an overdue-return follow-up. Stephan should confirm the cap.

### EA-041 · Stopping or pausing an order quietly settles that morning's missed doses
- **Area:** Meds today / overdue alerts after an order stop or pause
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Refs:** app/Services/Medication/DoseSlots/ScheduledDoseStates.php:56-66, app/Services/Medication/DoseSlots/OverdueDoses.php:93-100, app/Services/Medication/DoseSlots/OverdueDoses.php:151-155, app/Services/Medication/DoseSlots/OverdueDoses.php:171-182, app/Services/Medication/DoseSlots/DoseOrderTimeline.php:88-92
- **Scenario:** The 08:00 dose is not given and is overdue, and a Control Room overdue alert is raised. At 10:00 the GP stops the medicine and a lead ceases the order. The 08:00 row disappears from Meds today, the badge and rounds, and the overdue alert resolves itself as 'settled'. Nobody records why the 08:00 dose was missed.
- **Fix:** Keep doses whose due time is before the order's ceased_at or paused_at as owed: include those orders for that NZ day in listedOrders, and settle only on a recorded outcome (e.g. 'missed' with a reason), not on the order leaving the active scope.

### EA-042 · Live dose recording during the repeated hour on the April DST night saves the wrong instant: worker clock time with no offset is resolved to the first (NZDT) occurrence
- **Area:** Meds today / Record dose (P01) and PRN
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** resources/js/components/emar/record-dose/record-dose-dialog.tsx:216-230, resources/js/components/emar/record-dose/record-dose-dialog.tsx:728, resources/js/components/emar/record-dose/record-dose-dialog.tsx:955-958, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1134, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1284, app/Services/MarScheduleService.php:30-40, app/Services/EnhancedMarService.php:882-886, app/Http/Controllers/Emar/WorkerMedsController.php:897-900, app/Services/Medication/DoseSlots/DoseSlotRules.php:118-145, app/Services/Medication/Downtime/PaperReconciliationRules.php:26-37, resources/js/pages/emar/support/time.ts:2-30
- **Scenario:** First Sunday of April 2027 (4 Apr): clocks go back from 03:00 NZDT to 02:00 NZST, so 02:00–02:59 happens twice. A patient's PRN paracetamol has a 4-hour minimum interval, and the last dose was 22:45 NZDT Sat (09:45 UTC). During the second 02:xx hour, at 02:50 NZST (14:50 UTC), a night worker gives a dose and records it with the default 'now'. The dialog posts administered_at '2027-04-04T02:50:00' with no offset. parseWorkerDateTime runs Carbon::parse(value, 'Pacific/Auckland'), which picks the first occurrence, 02:50 NZDT (13:50 UTC). The dose is stored one hour earlier than it happened. At 05:55 NZST (17:55 UTC) a second dose passes checkPrnInterval: 4h05 since the stored 13:50, but only 3h05 since the real dose. A scheduled 02:30 dose (due at its first occurrence per DoseSlotRules) given at 02:35 NZST, 65 minutes late, is stored as 5 minutes late, so no late reason or late incident is raised. Also during the second pass the string check f.when > nowLocal rejects a real first-pass time (e.g. 02:50) as 'in the future'.
- **Fix:** Send an offset-qualified instant from the dialog, using the existing workerTimeOffsets() and toDatetimeLocal() helpers in lib/datetime.ts. When the wall time maps to two offsets, show the same 'which occurrence' choice P03 uses (or default 'now' to new Date().toISOString()). On the server, reject an offset-less administered_at whose NZ wall time is ambiguous or non-existent, reusing PaperReconciliationRules::instant semantics. Replace the string comparisons f.when > nowLocal and windowClosesLocal with comparisons of instants.

### EA-043 · At midnight, a still-due or late dose from the evening disappears from Meds today and the sidebar badge
- **Area:** Meds today schedule / sidebar badge
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:103-105, app/Http/Controllers/Emar/WorkerMedsController.php:135-142, app/Services/Emar/MedsBoardPayloadService.php:128-145, app/Services/Medication/DoseSlots/ScheduledDoseStates.php:75-79, app/Http/Middleware/HandleInertiaRequests.php:1411-1417, resources/js/pages/meds/today/index.tsx:330, app/Services/EnhancedMarService.php:523-531
- **Scenario:** A night worker on 22:00–08:00 has a 22:00 dose (window to 23:00) that is late at 23:30. At 00:00 Meds today auto-refreshes to the new NZ day. The late 22:00 row, its 'Record' action and any 'Record re-offer' vanish, and the sidebar overdue badge drops to 0. Meds today has no day picker, so the worker has no frontline way back to the dose. Likewise a 23:30 dose that is still inside its window at 00:10 is not shown.
- **Fix:** Include the previous NZ day's doses that are still due, late or awaiting a re-offer, for the board's people, in scheduleForDate when showing today (e.g. dosesBetween(yesterday, today) filtered to open rows). Add a 'From yesterday' group, and apply the same window to medsOverdueTodayCount.
- **Collision:** The board change is safe. The badge change is in HandleInertiaRequests.php (HIGH #4).

### EA-044 · Safety & oversight › Handovers and the Meds today handover button lead team leads, clinical leads and finance to a 403
- **Area:** Navigation / Handovers
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:475-481, app/Http/Controllers/Emar/EmarController.php:3598-3601, app/Services/ShiftHandoverService.php:1143-1160, app/Http/Controllers/Emar/WorkerMedsController.php:268-271, resources/js/pages/meds/today/index.tsx:875-884, database/seeders/RbacSeeder.php:914-955, database/seeders/RbacSeeder.php:1018-1034, database/seeders/RbacSeeder.php:844-856
- **Scenario:** A house lead (seeded team_lead role) opens Medication › Safety & oversight, sees 'Handovers' in the rail and search, and clicks it. They get 403 Forbidden. A clinical lead gets the same. A team lead, clinical lead or finance user who clicks the 'Shift handover — medication' icon on Meds today also gets 403.
- **Fix:** Decide whether house and clinical leads should hold handovers.viewAny. If yes, ship a grant migration (do not edit RbacSeeder). If no, add a server-supplied nav flag such as can.medications.handoversWorkflow = canAccessWorkflow(). Gate the EMAR_HUBS handovers view and WorkerMedsController view_handovers on that flag rather than on lead or medications.view.
- **Collision:** HandleInertiaRequests.php (collision #4) if a new auth.can flag is added; RbacSeeder.php (#2), so use a grant migration only

### EA-045 · Downtime & paper records cannot be reached from any nav, rail, search or Meds today link, so a lead cannot start entering paper doses after an outage
- **Area:** Navigation / P10 downtime
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:445-507, routes/emar-downtime.php:9-14, resources/js/pages/emar/downtime/index.tsx:86-91, resources/js/pages/emar/downtime/index.tsx:156-161, app/Services/Medication/Downtime/DowntimeAccess.php:21-25, app/Services/Medication/Downtime/DowntimeService.php:129-148, app/Services/Medication/Downtime/PaperEntryService.php:466, frozen/P10/assets/index-BtJlEoMB.js (~byte 569000: Safety & oversight hub = ['/emar/emergency-access','/emar/downtime'])
- **Scenario:** The internet is down at a house from 07:00 to 09:30. Staff give the 08:00 doses on the printed downtime pack. Afterwards the team lead (or a coordinator or clinical lead; DowntimeAccess::manages lists these roles) needs 'Record a downtime' to enter the paper doses. They look in the Medication sidebar hubs, the Safety & oversight rail, command search ('downtime', 'paper') and Meds today, and none of them offer it. The doses stay 'not recorded' or missed in the MAR, reports and follow-ups, or get re-entered later as ordinary late doses, which skips the P10 paper reconciliation (both times, giver confirmation, witness).
- **Fix:** Add a 'downtime' view to the safety hub in EMAR_HUBS (href '/emar/downtime', label 'Downtime & paper records'). Show it when the user can view medications and either holds a lead capability or can record administration, mirroring DowntimeAccess::siteIds. Switch downtime/index.tsx and show.tsx to useEmarBreadcrumbs(). Optionally add a Meds today header link for record holders. The safety hub stays within the 8-view test limit.

### EA-046 · The banner for a rejected offline dose does not say which person or medicine. One 'Dismiss' permanently deletes every rejected item, and the banner hides the offline/pending status.
- **Area:** Offline queue / app-wide banner
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/offline-status-banner.tsx:41-70, resources/js/lib/offline-queue.ts:554-565, resources/js/lib/offline-queue.ts:967-982, resources/js/lib/offline-queue.ts:1016-1018, resources/js/components/emar/followups/followup-list.tsx:100-125
- **Scenario:** 1. A worker records three doses during a Wi-Fi outage, two of them given. 2. On reconnect, the server refuses two with 422. Example reasons:    - 'This medicine uses exact pack records… a queued dose cannot guess packs from current stock'    - a PRN over-limit refusal 3. The banner reads '2 saved medication actions were not recorded: <reason of the first item only>'. It names no person, medicine or time. 4. The worker clicks 'Dismiss'. Both refused items are deleted from IndexedDB. The given doses are now on neither the server nor the device, and nobody knows which doses need paper recovery or a re-record. 5. While any rejected item exists, the banner also replaces the 'You’re offline' and 'N items waiting to send' messages.
- **Fix:** - List each rejected dose in an expandable panel: person, medicine, scheduled/given NZ time (store a display snapshot when queuing) and the server reason. - Require a per-item acknowledgement before deletion, with a link to the MAR or paper recovery. - Render the rejected panel in addition to the offline/pending line, not instead of it. - Show a rejected state on the matching Meds today/MAR row.

### EA-047 · A stale browser tab replays worker A's queued doses under worker B's session, so the doses are recorded as given by B
- **Area:** Offline queue / attribution
- **Audit dimension:** concurrency
- **Status:** verified
- **Refs:** resources/js/lib/offline-queue.ts:174-178, resources/js/lib/offline-queue.ts:311-338, resources/js/lib/offline-queue.ts:985-1010, resources/js/lib/offline-queue.ts:1196-1201, resources/js/lib/offline-queue.ts:655-687, app/Http/Controllers/Emar/WorkerMedsController.php:717, app/Services/EnhancedMarService.php:2095-2116
- **Scenario:** On a shared house PC, A has two tabs open and has queued doses offline. A logs out in tab 1, and B logs in in tab 1, so the browser's session and XSRF cookies now belong to B. Tab 2 still holds currentActorId=A in memory. When tab 2 becomes visible or the 'online' event fires, replayOne's check itemActorId === currentActorId (A === A) passes, and axios posts A's queued payloads with B's session cookie. WorkerMedsController records them with $user = B, so the MAR says B gave doses B never gave.
- **Fix:** Add the capturing actor to the payload (captured_by_user_id). The server should reject (409 or 422) a queued_offline submission whose captured_by_user_id differs from the session user. Also broadcast actor changes across tabs (a BroadcastChannel or localStorage 'emar:actor' key) and re-verify the session user (a GET of the user id) before each replay sweep.

### EA-048 · Queued doses are stranded when a different worker signs in on a shared device: they are never sent, they are invisible to the new user and to leads, and logout gives no warning
- **Area:** Offline queue / shared device
- **Audit dimension:** concurrency
- **Status:** verified
- **Type:** UI
- **Refs:** resources/js/lib/offline-queue.ts:387-410, resources/js/lib/offline-queue.ts:129-134, resources/js/lib/offline-queue.ts:311-338, resources/js/lib/offline-queue.ts:462-491, resources/js/app.tsx:48, resources/js/app.tsx:64, resources/js/components/user-menu-content.tsx:22-25, resources/js/pages/meds/today/index.tsx:1150-1153
- **Scenario:** The house internet drops during the night shift. Worker A records the 22:00 doses and they are queued with actorId=A. A finishes, closes the browser, and A's session expires overnight. At 07:00 Worker B opens the app (login page, actor null, no replay) and signs in. listQueue drops A's items as 'different actor'. B gets a one-off toast, 'A saved action belongs to another signed-in worker and was not sent', with no person, medicine or time. The banner shows nothing because pendingCount is 0. Meds today shows A's 22:00 doses as missed or overdue. A's doses never reach the server unless A signs in on that same laptop, and B may give or record them again.
- **Fix:** Before logout, if getOfflineQueueSnapshot().pendingCount>0, block with 'X doses are saved only on this device — reconnect and send them before signing out'. When a different actor is detected, show a persistent banner listing the stranded items (person, medicine, time from the payload) with 'Hand to lead / record on paper'. Optionally, when a later actor syncs, POST a minimal 'undelivered offline item' notice to the server so the house lead gets a follow-up.

### EA-049 · A dose the server refuses on replay (physically given, but refused for PRN limit/interval or competency) disappears with one 'Dismiss' click: no item details, no server trace, no lead follow-up
- **Area:** Offline replay / banner
- **Audit dimension:** concurrency
- **Status:** verified
- **Type:** UI
- **Refs:** resources/js/components/offline-status-banner.tsx:41-69, resources/js/lib/offline-queue.ts:967-983, resources/js/lib/offline-queue.ts:554-563, resources/js/lib/offline-queue.ts:1054-1071, app/Http/Controllers/Emar/WorkerMedsController.php:957-983
- **Scenario:** A worker gives a PRN offline at 14:00. Meanwhile a colleague's online PRN at 13:30 lands, so on replay the server returns 422 'INTERVAL NOT ELAPSED'. The banner says 'A saved medication action was not recorded: ⛔ INTERVAL NOT ELAPSED…', with no person, medicine or time, and a single Dismiss that deletes every rejected item. Re-recording is refused for the same reason. The dose was given but now exists nowhere: no MAR row, no medication error, no lead task.
- **Fix:** List each rejected item with person, medicine, outcome and captured time. Replace 'Dismiss' with 'Report to lead', which opens the medication-error or report flow prefilled from the payload. Only allow dismissal after the worker chooses 'reported' or 'recorded on paper'. For queued_offline given doses refused by a safety check, the server should raise a lead follow-up or Control Room signal with the refused facts.

### EA-050 · Queued doses refused with 403/404/401/419 are retried forever and parked as 'needs attention' with misleading 'Retry safely / won't create a duplicate' copy and no way to dismiss
- **Area:** Offline replay / banner
- **Audit dimension:** concurrency
- **Status:** verified
- **Type:** UI
- **Refs:** resources/js/lib/offline-queue.ts:1030-1089, resources/js/lib/offline-queue.ts:1148-1154, resources/js/lib/offline-queue.ts:544-563, resources/js/components/offline-status-banner.tsx:91-94, resources/js/components/offline-status-banner.tsx:122-131, app/Services/Medication/MedicationScopeDecisionService.php:97-104, app/Services/Medication/MedicationScopeDecisionService.php:167, app/Services/Medication/MedicationScopeDecisionService.php:984-1004, app/Services/Medication/MedicationScopeDecisionService.php:1131-1134, resources/js/components/emar/record-dose/record-dose-dialog.tsx:216, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1134
- **Scenario:** A worker gives a dose offline at 08:00 and it is queued. At 09:00 the GP changes the order, so a new version supersedes it. On reconnect the replay hits forAdministration, which locks the medication with whereNull('superseded_by'), and gets a 404. The queue retries up to 8 times and then marks the item needsAttention. The banner says 'needs attention. Check the chart before trying again. Trying again won't create a duplicate' and offers 'Retry safely', which 404s again. Nothing says the dose was NOT recorded. The item cannot be dismissed, and the given dose never reaches the MAR. The same dead end happens when the device clock runs more than about 1 minute fast: administered_at comes from new Date(), and the server rejects anything later than now()+1min with a 404. It also happens when an emergency grant has expired by replay time (403 from activeBreakGlass, which requires expires_at > now()), or when the worker's current site staff profile has ended.
- **Fix:** Treat 403 and 404 (and 400) on replay as definitive refusals: call markRejected with the text 'Not recorded — the order changed / you no longer have access; report this dose to your lead'. Keep 401/419 as 'sign in again' but not as needsAttention. Let the worker dismiss needsAttention items only after an explicit 'I have recorded this on paper / told my lead' confirmation. On the server, return 422 with a specific reason for a superseded order or a future time on queued_offline submissions. On the client, record administered_at from the server-offset clock, not raw new Date().

### EA-051 · A new order that is sent back and then re-entered unchanged gets checked but never produces any due doses
- **Area:** Orders (P04) / dose slots
- **Audit dimension:** workflows-leads
- **Status:** verified (claimed P0)
- **Refs:** app/Services/Medication/MedicationOrderWorkflow.php:152, app/Services/Medication/MedicationOrderWorkflow.php:230, app/Services/Medication/MedicationOrderWorkflow.php:262-264, app/Models/ClientMedication.php:27-37, app/Models/ClientMedication.php:159-170, app/Services/Medication/DoseSlots/DoseScheduleHistory.php:52-78, app/Services/Medication/DoseSlots/DoseScheduleHistory.php:116-126, app/Services/Medication/DoseSlots/DoseSlotRules.php:63-66, resources/js/pages/emar/orders/_parts.tsx:86
- **Scenario:** 1. A lead enters a NEW order (for example Quetiapine 25 mg at 20:00) from a phone order. 2. The checker sends it back because the source or read-back evidence was wrong, not the prescription. 3. The Orders UI says 'This new order cannot be given' and opens Entry. 4. The lead re-enters the same prescription with corrected source evidence, and another lead checks it. 5. The order is now 'Checked', active and administrable, but it owns no dose slots. It never appears as due on Meds today, My Day or the MAR schedule, and no overdue or missed alerts fire. The person silently misses every scheduled dose.
- **Fix:** In DoseScheduleHistory::updated: when approval_status changes to 'verified' and latestAwaitingVersion() is null, insert a new verified version from the current row (insertVersion($order, $now, verified_at, null)). Add a feature test: enter, send back, re-enter identical, check, then assert slots exist for the next dose time. Also scan production for verified active orders whose schedule versions all have verified_at NULL, and backfill them.

### EA-052 · The allergy-class map is permanently 'Not configured' (there is no way to set it), and the order check and the dose check disagree. A severe class match passes the order check, then blocks every dose at the bedside.
- **Area:** Orders (P04) × recording (P01) allergy checks
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/OrderAllergyMatcher.php:13-42, resources/js/pages/emar/orders/_entry.tsx:661-670, resources/js/pages/emar/orders/_detail.tsx:663-671, app/Services/Medication/CheckedOrderAllergyConfirmation.php:12-28, app/Models/MedicationAllergy.php:75-108, app/Services/MedicationSafetyService.php:68-135, app/Services/Medication/Recording/DoseRecordingRequirements.php:435-451
- **Scenario:** 1. A resident has a 'Penicillin — severe' allergy on the register. A lead enters amoxicillin 500 mg. 2. The order screen shows 'Drug-class matching: Not configured. Check the prescription against all recorded allergies.' No match is found, so no prescriber confirmation is asked for, and the order is checked and verified. 3. At the first dose, `MedicationAllergy::matchesMedication` applies a hard-coded class list (penicillin → amoxicillin). It finds a severe match and has no prescriber confirmation (that is keyed only to `OrderAllergyMatcher` matches), so 'given' is blocked: 'Severe allergy to Penicillin detected'. 4. The worker can only record withheld. The lead cannot get the order into the 'Prescriber must confirm' path, because the order-time matcher will never see the match. 5. The reverse also applies: any class rule placed in `medication_allergy_class_rules` would apply only at order time, never at the dose.
- **Fix:** - Use one matcher for both checks. Either make `MedicationSafetyService::checkAllergies` use `OrderAllergyMatcher` (and seed the reviewed class rules), or add the same hard-coded classes to `OrderAllergyMatcher`, so a class match triggers 'Prescriber must confirm' at order check. - Until there is a Settings surface for class rules, stop presenting the map as a configurable 'Not configured' setting.

### EA-053 · The legacy prescriber-order register (/emar/prescriptions/legacy) still creates and confirms New/Change/Verbal orders that never reach the chart, and a confirmed 'Cease' bypasses the P04 stop
- **Area:** Orders & reviews (P04)
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Orders.tsx:757-760, resources/js/pages/emar/Prescriptions.tsx:707-714, resources/js/pages/emar/Prescriptions.tsx:458-465, resources/js/pages/emar/_prescription-dialogs.tsx:134-200, resources/js/pages/emar/_prescription-dialogs.tsx:318-336, routes/emar.php:173-174, routes/emar.php:332-345, app/Http/Controllers/Emar/EmarController.php:2620-2621, app/Http/Controllers/Emar/EmarController.php:3717-3911, app/Http/Controllers/Emar/EmarController.php:3919-3950, app/Http/Controllers/Emar/EmarController.php:4152-4208, app/Http/Controllers/Emar/EmarController.php:4265, app/Http/Controllers/Emar/EmarController.php:4287-4363, app/Services/Medication/MedicationOrderWorkflow.php:426-439, docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md:1020-1027 (worktree, untracked)
- **Scenario:** A coordinator with orders.manage and a covering shift gets a GP fax raising Metformin from 500 mg to 1 g. From Orders she follows 'Open supply and dispensing records' (Orders.tsx:757) to /emar/prescriptions/legacy and clicks 'New prescriber order'. She chooses type Change and enters 1 g. A clinical lead with orders.verify then confirms it in a dialog that says 'Confirm the order details are correct as charted'. The status becomes 'confirmed', but confirmPrescription only calls applyCeaseOrder, which returns early for anything but 'cease'. The chart order stays at 500 mg. No P04 version is created and nothing appears in Orders › To check, so Meds today keeps asking for 500 mg. If the type is Cease, confirm/countersign stops the chart order through MedicationOrderLifecycleService::discontinue directly. That records no MedicationOrderAction 'stopped' and no 'order.stopped' P09 event. Open 'order-check:{revision}' and 'second-check' follow-ups for that order also stay open, because they close only in MedicationOrderWorkflow::finishActions:436-439.
- **Fix:** Make /emar/prescriptions/legacy read-only. Remove the New prescriber order, Confirm, Countersign, Cancel, Dispense and Link actions. Make POST /emar/prescriptions, /{order}/confirm\|countersign\|dispense\|cancel and PUT /{order} answer through MedicationLegacyOrderBridge::enter (409 or redirect to Orders), or return 410. Keep only read access to historic records and covert history. Rename the Orders link to 'Old prescriber-order records (read only)'.

### EA-054 · Approved P04 amendment not applied: team_lead still lacks medications.orders.manage
- **Area:** Orders & reviews (P04/P05), Self-admin (P03)
- **Audit dimension:** rbac
- **Status:** verified
- **Refs:** database/seeders/RbacSeeder.php:915-956, routes/emar.php:179-215, routes/emar.php:299, routes/emar.php:339-385, app/Services/Medication/Reviews/MedicationReviewOrderAdapter.php:26, app/Services/Medication/Reviews/MedicationReviewOrderAdapter.php:47, app/Services/Medication/Reviews/MedicationReviewOrderAdapter.php:76, app/Http/Controllers/Emar/StaffEligibilityController.php:65-68, docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md:1253-1256, docs/emar-audit-2026-09-28/claude-second-review/P11-build-plan.md:133
- **Scenario:** A GP phones the Rimu house lead to stop a medicine, or a review the house lead recorded (they hold reviews.manage) ends with 'agreed: stop'. The house lead cannot enter the change in Orders: POST /emar/orders, /orders/{id}/stop and /hold return 403, and the review's 'Enter in Orders' link is hidden (MedicationReviewReader:137). Covert authorisation, reconciliation, allergy and written confirmation, and self-administration agreements are refused the same way. The order stays live until an office coordinator or clinical lead acts.
- **Fix:** Add a grant migration (timestamp after 2026_10_08_235100) that does insertOrIgnore of role_permission (team_lead, medications.orders.manage), first grant only, and add the key to the team_lead block. Check with Stephan first, because map decision §2A.3 still lists it as awaiting a ship.
- **Collision:** database/seeders/RbacSeeder.php (High #2, H&S lane); ship the migration first and coordinate the seeder line

### EA-055 · Pausing a person's due/late dose alerts saves with no 'Loosens this check' confirm and leaves only a plain sentence on the record
- **Area:** Person record (P02) › Allergies & alerts
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/components/mar-governance-dialogs.tsx:790-795, resources/js/pages/emar/components/mar-governance-dialogs.tsx:836-844, resources/js/pages/emar/components/mar-governance-dialogs.tsx:862-877, resources/js/components/emar/record/safety.tsx:139-144
- **Scenario:** A manager opens 'Add alert / settings', moves to the Reminders step, switches Active→Suppressed, picks a basis and clicks the default-primary 'Save setting'. Due and late alerts stop at once, with no confirm. Afterwards the record shows only 'Administration alerts are suppressed · reason.' in body text, with no warning banner. Staff and leads have no visible cue that a safety alert is off.
- **Fix:** Split pausing into its own dialog. Use a Switch, the basis tile picker in plain words ('Team decision' rather than 'MDT decision') and a reason. Confirm through ConfirmDialog variant='destructive' with the approved 'Loosens this check' copy. Render a status-warning Notice on the record (and the Overview) while paused.

### EA-056 · The PRN interval and daily-limit checks read a stale InnoDB snapshot taken before the Client lock, so two simultaneous PRN doses both pass
- **Area:** PRN recording / concurrency
- **Audit dimension:** concurrency
- **Status:** verified
- **Refs:** app/Services/Medication/MedicationScopeDecisionService.php:82-91, app/Services/EnhancedMarService.php:1292-1298, app/Services/MedicationSafetyService.php:452-456, app/Services/MedicationSafetyService.php:650-653, app/Services/MedicationSafetyService.php:673-676, app/Services/MedicationSafetyService.php:703-711, config/database.php:46-74
- **Scenario:** Two staff answer the same person's pain request at about the same moment. Both record paracetamol PRN (min 4 h between doses), or one records online while an offline replay arrives. T2 starts its transaction while T1 holds the Client lock. T2's first plain read fixes its REPEATABLE READ snapshot, T2 then waits for the lock, and T1 commits its dose. T2 runs checkPrnInterval and checkPrnLimits with plain SELECTs from that old snapshot, does not see T1's dose, is not blocked, and inserts a second dose minutes after the first. No over-limit incident is raised.
- **Fix:** In prnGivenHistory, use a locking (current) read when it is called inside recording: ->lockForUpdate() or ->sharedLock() on the history query. Or remove the plain snapshot read before the Client lock in forAdministration and forMedication (use a locking read for medicationSnapshot, or read it before DB::transaction). Add a concurrent-PRN feature test.

### EA-057 · Backup PDF downloads and password reveals leave no audit trail
- **Area:** Protected backups
- **Audit dimension:** connected-care
- **Status:** verified
- **Refs:** app/Http/Controllers/Emar/MedicationBackupDeliveryController.php:122-127, app/Http/Controllers/Emar/MedicationBackupDeliveryController.php:129-153, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:240-262, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:152, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:213, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:423-426, app/Services/Medication/Downtime/DowntimePackService.php:187-189
- **Scenario:** A coordinator is an approved recipient for House A, which has up to 100 residents including controlled pages. They open /emar/backups, download chart-backup-2026-10-09.pdf, then reveal its password using their account password and a TOTP code. Nothing records that this person now holds a readable copy of every resident's medication chart. A later privacy investigation cannot tell who opened which house's backup. The printable downtime pack writes a P09 export event for the same content.
- **Fix:** Inside readable()'s transaction, append a MedicationEvent for download (kind backup.downloaded) and, after step-up succeeds, for the password reveal (kind backup.password_revealed). Include site, delivery id, actor, nz_date and the controlled-pages flag. Show both in Reports › Export history.

### EA-058 · 'Withheld › Other (say what happened)' can never be saved from the recording dialog
- **Area:** Record dose dialog (Meds today, rounds, MAR)
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Type:** UI
- **Refs:** resources/js/components/emar/record-dose/record-dose-dialog.tsx:937-940, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1131-1136, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1206-1224, app/Http/Controllers/Emar/WorkerMedsController.php:643-644, app/Http/Controllers/Emar/WorkerMedsController.php:693-694, app/Services/EnhancedMarService.php:2346-2378, app/Services/Medication/Recording/RecordingContract.php:35-42
- **Scenario:** A worker withholds a dose for a reason not on the list, picks 'Other (say what happened)' and types the required note. On save the server returns 422 'Add a short note when using Other as the reason.', shown on the reason picker. The note is already filled in and there is no other field to fill, so the dose cannot be recorded as withheld with its real reason. The worker must pick a different, inaccurate reason or leave it unrecorded.
- **Fix:** In payload(), send reason: f.note.trim() when reason_code is 'other' (withheld or away). Or have recordDose map notes to reason for Other. Add a Vitest and a feature test with the dialog's payload shape.

### EA-059 · Approved 'Order changed — check the new instructions' acknowledgement is missing from RecordDoseDialog
- **Area:** Record dose dialog (P01)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/emar/record-dose/record-dose-dialog.tsx:1731-1760, resources/js/components/emar/record-dose/record-dose-dialog.tsx:2574-2579, resources/js/components/emar/record-dose/copy.ts:133-137
- **Scenario:** Mid-round, a prescriber changes paracetamol from 500 mg to 750 mg, and the house lead verifies the new version. The next worker opens the dose. The dialog does not say the order changed, gives no Before/Now comparison, and does not require 'I've checked the new instructions and the label'. The worker can pick from the pack still labelled 500 mg and record 'as ordered'.
- **Fix:** Expose 'changed since the last recorded dose, with the previous dose and amount' in the requirements payload. On step 0, show the approved warning notice with Before/Now and a required acknowledgement checkbox, and add it to blockedContinue.

### EA-060 · For pack-tracked medicines the 30-minute 'connected recording review' expires inside an open dialog. Retrying can never succeed, and the only way out discards the draft or forces a later dose time.
- **Area:** Recording dialog (P01) × stock lots (P06)
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/Recording/LiveRecordingContext.php:15, app/Services/Medication/Recording/LiveRecordingContext.php:48-57, app/Services/EnhancedMarService.php:1270-1284, app/Services/Medication/Recording/DoseRecordingRequirements.php:368-369, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1132, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1425-1450, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1483-1502, resources/js/components/emar/record-dose/use-dose-requirements.ts:26-53, resources/js/components/emar/record-dose/record-dose-dialog.tsx:2800-2806
- **Scenario:** 1. Pack tracking has started for a controlled medicine (stock_lots_enabled defaults ON). 2. A worker opens 'Record dose' at 8:00, gives the dose, and waits 35 minutes for a witness. 3. On save the server returns 422 `live_recording_context`: 'The connected recording review expired after 30 minutes. Keep the draft. Review the current dose again…' 4. The dialog shows 'Not recorded'. The 'Record outcome' button resends the same expired token, so it fails every time, and nothing in the dialog refreshes the review. 5. To 'review again' the worker must cancel ('What you entered for this dose will be lost') and reopen. 6. If they then enter the true 8:00 time, the server refuses: 'The actual dose time is earlier than this connected review… use reviewed paper recovery'. The only way to record now is a wrong, later administration time, or lead-reviewed paper recovery.
- **Fix:** - Map `error_field === 'live_recording_context'` to a 'Refresh the safety check' action. It should call `requirements.reload()` and swap in the new `req.live_recording_context` while keeping `f` (the draft), then let the worker re-save. - Server side: either issue the token when the dialog opens and let a refresh keep the original lower bound for `checked_at`, or say up front in the dialog that pack-tracked doses must be saved within 30 minutes of opening.

### EA-061 · General Reports › Medication Administrations and Controlled Drug Discrepancies filter by UTC date and show raw UTC timestamps as local time; the reports index 'Last activity' and the Care-quality report rows do the same
- **Area:** Reports (general /reports hub)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/ModuleReportController.php:163-169, app/Http/Controllers/ModuleReportController.php:207-218, app/Http/Controllers/ModuleReportController.php:46-49, app/Support/ReportCatalog.php:176-213, resources/js/pages/reports/module.tsx:206, app/Http/Controllers/ReportsController.php:99-107, resources/js/pages/reports/index.tsx:237-239, app/Http/Controllers/CombinedReportController.php:197-207
- **Scenario:** An auditor checks whether 9 Oct morning doses were given on time and opens /reports/modules/medication_administrations?date_from=2026-10-09&date_to=2026-10-09. whereDate('administered_at') uses the UTC date, so every dose given 00:00–12:59 NZDT on 9 Oct is missing and the next morning's doses appear. Each row shows 'Scheduled For 2026-10-08 19:00:00 / Administered At 2026-10-08 19:05:00' for an 08:00 NZDT dose given at 08:05, so it reads as an evening dose on the wrong day. /reports shows 'Last activity: 2026-10-08 19:05:00' for the MAR module. The Care-quality combined report's 'Recent Medication Exceptions' lists 'Administered At' via toDateTimeString() in UTC.
- **Fix:** In buildQuery, when date_from/date_to are set, compare against NZ-day UTC bounds (CarbonImmutable::parse($d,'Pacific/Auckland')->startOfDay()->utc() / ->endOfDay()->utc()) instead of whereDate. In serializeRow, render DateTimeInterface values as ->timezone('Pacific/Auckland')->format('Y-m-d H:i T'). Send lastActivity as an ISO string and format it with formatDateTime on index.tsx. Apply the same conversion in CombinedReportController L183/L207. Better still, retire the medication modules from the generic hub in favour of P09 /emar/reports, which is already NZ-correct.

### EA-062 · An archived person's whole medication audit trail and P09 report history disappears, and new events for them are written without the person
- **Area:** Reports & audit (P09 event chain)
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Reporting/MedicationReportAccess.php:46-55, app/Services/Medication/Audit/MedicationEventReader.php:14-33, app/Services/Medication/Audit/MedicationEventReader.php:38-43, app/Services/Medication/Audit/MedicationEventRecorder.php:34-39, app/Services/Medication/Followups/MedicationFollowupService.php:743, app/Services/Medication/ForgottenWitnessPinService.php:297
- **Scenario:** A resident is archived after leaving. Three weeks later the HDC asks for their medication record. In /emar/reports?view=audit, the person filter cannot select them, and an unfiltered audit export for their house leaves out every event that carries their client_id: doses, corrections, CD entries and order checks. Dose, MAR and error reports leave them out as well. A follow-up or second-person confirmation that closes after archiving is chained with client_id NULL, so even after a restore it is not attributed to them.
- **Fix:** For audit and report readers with medications.audit.view, include trashed clients in clientIds (Client::withTrashed() plus the same policy check) and show them as '(archived)'. In MedicationEventRecorder, accept trashed clients (Client::withTrashed()) so the chain keeps person attribution, and remove the null workaround in the two callers. Stephan's §2A.10 decision should cover retention display.

### EA-063 · The P09 audit trail only receives doses recorded from Meds today; fleet doses and all legacy stock and controlled-drug writers never reach the event chain
- **Area:** Reports & audit (P09) / write seam
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:793, app/Http/Controllers/Emar/WorkerMedsController.php:1008, app/Http/Controllers/Emar/WorkerMedsController.php:1033-1048, app/Services/Fleet/ResidentTransportJourneyService.php:1116-1145, app/Http/Controllers/Emar/EmarController.php:6711, app/Http/Controllers/Emar/EmarController.php:6959-7150, app/Http/Controllers/Emar/EmarController.php:7152-7311, app/Http/Controllers/Emar/EmarController.php:7383-7515, app/Http/Controllers/Emar/EmarController.php:6080, app/Services/Medication/Audit/MedicationEventReader.php:14-31, app/Http/Controllers/Emar/MedicationReportsController.php:80-81, app/Http/Controllers/Emar/MedicationStockController.php:116
- **Scenario:** On one day at Rimu House, three things happen through live UI. A driver gives Paracetamol on an outing through the Fleet transport wizard. The house lead receives a controlled-drug pharmacy delivery via Stock › Pharmacy orders, which is the legacy /emar/stock page. The lead also adjusts an ordinary count with the overview 'Record stock'. An auditor then opens Reports & audit › Audit for that Site and day. That screen and its export read only medication_events (MedicationEventReader::query). None of the three events appears. The 'doses' total (kind LIKE 'dose.%', MedicationReportsController.php:81) leaves out the fleet dose, and paper-posted doses are logged as 'downtime.paper_reconciled'. 'Verify chain' still reports intact, because it only checks the links that were written.
- **Fix:** Move the dose event into EnhancedMarService::recordAdministration. Append it at the end of its transaction and skip it on exact replays. Remove WorkerMedsController::appendDoseEvent so doses are not double-appended. Every dose writer (fleet, paper, any surviving legacy route) then emits it. Retire the EmarController legacy stock and controlled-drug writers (see the related findings), or add the matching events. Count downtime.paper_reconciled in the doses total.

### EA-064 · The 'Doses' CSV export for any period before dose-slot coverage silently leaves out every scheduled dose (backfill never run on the test server)
- **Area:** Reports & audit / rollout
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/MedicationReportsController.php:207-232, app/Http/Controllers/Emar/MedicationReportsController.php:252-262, app/Services/Medication/Reporting/MedicationReportDataset.php:249-277, app/Services/Medication/Reporting/MedicationReportDataset.php:64-85, app/Services/Medication/DoseSlots/DoseSlotCoverage.php:33-45, app/Console/Commands/BackfillMedicationDoseSlots.php:33
- **Scenario:** On the .com test server (or in production before 'emar:backfill-dose-slots' has run), an auditor exports Reports › Print & exports › Doses for 1–30 September. The CSV has a header and only the as-needed rows. Every scheduled dose, including given, refused, missed and not-recorded ones, is absent. There is no 'Not available before 1 October' line, so the file looks like a complete dose register.
- **Fix:** In export(), call DoseSlotProjection::coverage($period->from). When incomplete, either reject with 422 ('Dose records are available from {date}; choose a later start or run the backfill') or write a first CSV row and PDF note stating the gap and record it in the export audit. Run the dry-run and then the approved backfill on .com.

### EA-065 · Emergency-access reviewing treats medications.audit.view as an all-Sites bypass, so reviewers are notified and listed for every house but get 403 when they review
- **Area:** Safety & oversight › Emergency access (P10)
- **Audit dimension:** rbac
- **Status:** verified
- **Refs:** app/Http/Controllers/EmergencyAccessController.php:24-27, app/Http/Controllers/EmergencyAccessController.php:76, app/Http/Controllers/EmergencyAccessController.php:135, app/Services/Medication/EmergencyAccess/EmergencyAccessNotifications.php:17-22, app/Services/Medication/Alerts/MedicationNotificationVisibility.php:104, app/Services/Medication/Alerts/MedicationNotificationVisibility.php:138, app/Services/Medication/Alerts/MedicationAlertRecipients.php:250-251, app/Services/Tasks/Providers/MedicationEmergencyAccessReviewProvider.php:46-48, app/Services/Medication/EmergencyAccess/EmergencyAccessService.php:209, app/Services/Medication/EmergencyAccess/EmergencyAccessService.php:252-276, app/Http/Controllers/BreakGlassController.php:69-74, app/Http/Controllers/BreakGlassController.php:95-109, app/Policies/ClientPolicy.php:14-17, app/Policies/ClientPolicy.php:138-142, app/Services/Medication/MedicationGovernanceScopeService.php:50
- **Scenario:** Tomasi is a coordinator (Site-scoped, with no sites.viewAll or clinical.accessAllSites) approved for Kōwhai House only. A worker at Rimu House starts emergency access for a resident. Tomasi gets the 'Emergency access started — <worker> … <client full name>' notification, the All Tasks item 'Review ended emergency access — <client>', and the Rimu grant on /emar/emergency-access with its reason and events and can_review=true. When he submits the review, ClientPolicy::reviewBreakGlass refuses it with 403. He can still dismiss Rimu's 'Repeat emergency access' misuse flag through POST /emar/break-glass-flags/dismiss, which removes it for every reviewer. The auditor role behaves the same way.
- **Fix:** Replace ['medications.audit.view'] with MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS in all eight places, so they match ClientPolicy::reviewBreakGlass. If Stephan wants organisation-wide reviewers (decision §2A.15), add a dedicated key (for example medications.breakglass.review_all) with a grant migration, and use it in ClientPolicy as well, so that list, notification and action agree.

### EA-066 · /emar Overview shows every resident's medicines and recent doses to support workers, bypassing the P02 person rule
- **Area:** Safety & oversight › Overview (/emar)
- **Audit dimension:** rbac
- **Status:** verified (claimed P0)
- **Refs:** routes/emar.php:105-107, resources/js/lib/emar-navigation.ts:454-460, app/Http/Controllers/Emar/EmarController.php:1218-1235, app/Services/MedicationOverviewService.php:135-152, app/Services/MedicationOverviewService.php:287-292, app/Services/MedicationOverviewService.php:337-345, app/Services/MedicationOverviewService.php:366-379, app/Services/MedicationOverviewService.php:1090-1110, app/Policies/ClientPolicy.php:65-101, resources/js/pages/emar/Index.tsx:1702-1707
- **Scenario:** A support worker (seeded support_worker: medications.view, clients.viewAssigned, controlled.view/record) works at House A and is assigned to 2 of its 6 residents. The sidebar gives them only 'Meds today', but they type /emar. The route only needs medications.view, so the Overview renders. Its props hold the last 20 administrations (resident name, medicine name, who gave it), active medication alerts, the full active-medicine list for every House A resident (medicationOptions, kept because they hold controlled.record), INR, syringe-driver and review lists, and clientOptions. Four of those residents are people ClientPolicy::viewMedications refuses this worker.
- **Fix:** In MedicationOverviewService::scopeToReader, build readerClientIds from MedicationRecordAccess::readableClientIds($actor, <site client ids>) (or from doseScope->clientIds) so every list uses the same person rule as the counts. Optionally also tighten the /emar route to the lead capability the nav uses (orders.verify\|orders.manage\|settings.manage\|audit.view). Add a feature test: an unassigned support worker gets no rows for a non-assigned resident.

### EA-067 · Overview action-centre 'CD balance' button opens a register modal whose every submission is rejected (422)
- **Area:** Safety & oversight › Overview (Index.tsx)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Index.tsx:950-963, resources/js/pages/emar/components/cd-register-modal.tsx:74-106, resources/js/pages/emar/components/cd-register-modal.tsx:226, app/Http/Controllers/Emar/ControlledProductController.php:37, app/Http/Controllers/Emar/ControlledProductController.php:87-89
- **Scenario:** A house lead with controlled.view and controlled.record sees a CD balance item in the Action centre and clicks its action. CdRegisterModal opens, they complete entry type, quantities, witness and PIN, and submit. The server answers 422 'Choose a witnessed movement in the controlled register.' The entry can never be saved from the Overview.
- **Fix:** Point the cd_balance action at the canonical controlled-register count/movement dialogs (components/emar/controlled/action-dialog, count-dialog) or link to /emar/controlled with the medicine preselected. Delete CdRegisterModal and its legacy entry types.

### EA-068 · The forgotten-PIN fallback nominates colleagues who can't see the person, so the confirmation silently expires
- **Area:** Second-person confirmation (PIN-2 fallback)
- **Audit dimension:** workflows-frontline
- **Status:** verified
- **Refs:** app/Services/Medication/Recording/DoseRecordingRequirements.php:314-323, app/Services/Medication/Recording/DoseRecordingRequirements.php:527-554, app/Services/Medication/MedicationSecondPersonService.php:28-43, app/Services/Medication/ForgottenWitnessPinService.php:86-90, app/Services/Medication/ForgottenWitnessPinService.php:142-173, app/Services/Medication/ForgottenWitnessPinService.php:186-190, app/Policies/ClientPolicy.php:65-124, app/Http/Controllers/Emar/WorkerMedsController.php:470-501
- **Scenario:** Worker W1, on person A's shift, gives A a half dose ('less than ordered' needs a second person). W1 ticks 'forgot PIN' and names W2, a relief worker at the same house on person B's shift who is not assigned to A. The dose saves as 'not verified'. W2 never sees the request (it isn't on their Meds today and the follow-up link returns 404) and cannot answer. After 30 minutes it expires and A's dose is flagged 'Second person did not confirm — check this dose' for the lead.
- **Fix:** Offer only nominees who can read the person (MarLinkService::openableClientIds) in the forgotten-PIN picker. Or authorise pendingFor/readable/respond for the named nominee by the nomination itself (current Site presence and permission), showing minimal dose facts.

### EA-069 · Shift page serialises the client's whole medical profile (medical, mental-health and surgical history, allergies) to any viewer of the shift
- **Area:** Shifts › show
- **Audit dimension:** privacy
- **Status:** verified
- **Refs:** app/Http/Controllers/ShiftController.php:281-311, app/Http/Controllers/ShiftController.php:542-543, app/Models/ClientMedicalProfile.php:81-109, app/Services/Clients/ClientProfileSectionAccess.php:78, app/Services/Medication/MedicationScopeDecisionService.php:492-532
- **Scenario:** A relief support worker is rostered (or claims a job-board shift) for a resident they are not linked to in client_user. They open /operations/shifts/{id} before clocking in, or any time after the shift. Inertia props.shift.client.medical_profile contains medical_history, mental_health_history, surgical_history, allergies, immunisation and GP details. The same worker's client profile Medical section is denied, because viewMedications needs assignment or a clocked-in covering shift.
- **Fix:** Stop serialising the Shift model with medical relations: drop 'client.medicalProfile' and 'client.risks' from the load, or send an explicit client array (id, names, site_id). Rely on ClientSafetyPayload::forViewer for allergies and risk flags.
- **Collision:** ShiftController.php: workforce integration and foundation lanes (§3 high #5). The integration lane's shifts.viewAny-opens-any-shift change would widen this to coordinators and auditors.

### EA-070 · Shift cancellation rewrites dose records with saveQuietly: no audit-log row, no event-chain entry, no follow-up
- **Area:** Shifts / append-only integrity
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** app/Services/ShiftCancellationService.php:173-232, app/Services/ShiftCancellationService.php:198, app/Services/ShiftCancellationService.php:219, app/Models/Concerns/AuditableChanges.php:25-51, app/Models/ClientMedicationAdministration.php:27-51, app/Services/Medication/Followups/MedicationFollowupService.php:180-188
- **Scenario:** A coordinator cancels a shift after a support worker has recorded three doses on it. Each ClientMedicationAdministration gets review_required=true, a new review_reason and extra text appended to its clinical notes. No 'clientmedicationadministration.update' audit row is written, the P09 chain gets no event, and because review_reason_key stays null, no 'dose-review' follow-up is created. Nobody is asked to review the doses, and nothing shows that the notes were edited.
- **Fix:** Use a governed adapter in the medication domain. It locks client → order → administration, sets review_reason_key (e.g. 'shift_cancelled'), and saves with events (or calls AuditLogger::logOrFail explicitly), appends a MedicationEventRecorder event and calls MedicationFollowupService::syncAdministration. Do not append to clinical notes. Keep the reason in review_reason.
- **Collision:** app/Services/ShiftCancellationService.php is an eMAR finding in another lane's territory (map §3 'coordinate before fixing'). Agree order with the workforce lanes.

### EA-071 · Shift cancellation rewrites clinical dose records via saveQuietly (notes and review flags) with no audit, no follow-up and no guards
- **Area:** Shifts / dose records
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** app/Services/ShiftCancellationService.php:173-232, app/Services/ShiftCancellationService.php:192-199, app/Services/ShiftCancellationService.php:21, app/Models/ClientMedicationAdministration.php:26-50, app/Console/Commands/RefreshMedicationFollowups.php:49
- **Scenario:** The worker on the 07:00 shift records the 08:00 doses, and later a coordinator cancels that shift. cascadeMedication then calls forceFill on each linked administration: it appends 'This medication record requires review because its linked shift was cancelled.' to the clinical notes and sets review_required, review_flagged_at and review_flagged_by, then calls saveQuietly(). That skips the model's lockOrder, SupportRecordingGuard, AuditableChanges 'updated' audit row, DoseSlotOutcomeWriter sync and OverdueDoseAlerts. Nothing records that the notes of a dose record were changed, and no P09 event is written. The legacy review_required flag is read only by the manual emar:workflow-followups import (RefreshMedicationFollowups.php:49), so no house-lead review follow-up appears.
- **Fix:** Stop mutating administration rows. Raise a MedicationFollowup (for example a 'review' type with review_reason_key) through MedicationFollowupService::ensureForSource('shift-cancelled:{shift}:{administration}', …) and append a P09 event. Keep the shift link in the follow-up context.
- **Collision:** app/Services/ShiftCancellationService.php — Medium: eMAR finding in another lane's territory (coordinate before editing)

### EA-072 · Shift › Medications card for an overnight shift is built only for the NZ day the shift starts: day-2 doses are missing, last night's doses show as overdue, and 'Open MAR' links to the wrong day
- **Area:** Shifts / Operations shift page
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/ShiftController.php:403-455, app/Services/Emar/ShiftMedicationSnapshotService.php:51-63, app/Services/EnhancedMarService.php:2805
- **Scenario:** A support worker on a 22:00 Fri 9 Oct – 08:00 Sat 10 Oct sleepover opens /operations/shifts/{id}?tab=medications at 06:45 Saturday. shiftDate is Friday, so EnhancedMarService::build only yields Friday's doses. Saturday's 07:00 morning meds never appear in 'due'. Any unrecorded Friday 20:00 dose shows as missed_auto/overdue in the 'due' list, the stats are Friday's, and mar_url opens Friday's MAR. The handover snapshot for the same shift does iterate every NZ day the shift covers, so the two surfaces disagree. The shift-summary API also returns 'date' => starts_at->toDateString() in UTC: a shift starting 08:00 NZDT 9 Oct reports date 2026-10-08.
- **Fix:** Reuse ShiftMedicationSnapshotService::forShift (or its day loop) for the card: build each NZ day from starts_at to ends_at (or now for an open shift) and filter rows to the shift window. Point mar_url at the NZ day that contains 'now' (clamped to the shift). Change getShiftSummary 'date' to ->timezone(worker tz)->toDateString().
- **Collision:** HIGH: app/Http/Controllers/ShiftController.php (workforce integration uncommitted shifts.viewAny change at l.74/172/293-299; workforce foundation +469/-116). Agree order with the workforce lane first.

### EA-073 · A held controlled medicine's pharmacy delivery cannot be entered in the CD register, and held medicines cannot be received or reordered
- **Area:** Stock (P06) / Controlled register (P07b)
- **Audit dimension:** workflows-leads
- **Status:** verified
- **Refs:** app/Services/Medication/Stock/MedicationStockService.php:149, app/Services/Medication/Stock/MedicationStockService.php:481-486, app/Services/Medication/Stock/IntegratesPackEvidence.php:28-32, app/Http/Controllers/Emar/MedicationStockController.php:125, app/Http/Controllers/Emar/MedicationStockController.php:163-176, app/Http/Controllers/Emar/EmarController.php:7103-7106, app/Http/Controllers/Emar/MedicationOrdersController.php:276
- **Scenario:** 1. The prescriber holds Oxycodone liquid for 3 days. Orders > Hold sets state 'paused' and active false. 2. The pharmacy's weekly controlled delivery arrives anyway. 3. The lead records the witnessed receipt in Stock & controlled. 4. ControlledRegisterService::recordReceipt writes the register entry, then the attachLot callback runs controlledDeliveryAfterRegister. That calls assertActive and throws 'This medicine is no longer active', which rolls back the whole receipt. 5. The legacy /emar/stock path calls the same adapter once lots have started. 6. The controlled drug is physically on the premises with no register entry, so the next count shows a discrepancy. Ordinary held medicines also cannot be received (receive :149), and supply cannot be reordered (Controller:125) while the order is held.
- **Fix:** Permit receipt, supply orders and register entries for paused orders (block only ceased or superseded orders, or route those to a 'received for return' flow). Keep administration blocked by isAdministrable(). For ceased controlled orders, allow a witnessed 'received for return/destruction' register entry so the stock can still be accounted for and disposed of.

### EA-074 · Destructions page 'Record disposal' can never record a controlled-drug destruction: the dialog sends legacy fields the canonical command rejects
- **Area:** Stock & controlled › Destructions
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Destructions.tsx:510-512, resources/js/pages/emar/Destructions.tsx:795-802, resources/js/pages/emar/_cd-dialogs.tsx:1548-1565, resources/js/pages/emar/_cd-dialogs.tsx:1578-1625, app/Http/Controllers/Emar/EmarController.php:3525-3531, app/Http/Controllers/Emar/EmarController.php:3542, app/Http/Controllers/Emar/ControlledProductController.php:81-86, app/Http/Controllers/Emar/ControlledProductController.php:154-155, app/Http/Controllers/Emar/ControlledProductController.php:172-173, app/Http/Controllers/Emar/ControlledProductController.php:193, tests/Feature/Emar/DestructionsTest.php:355-385
- **Scenario:** A house lead with controlled.view and controlled.record opens /emar/destructions (nav: Stock & controlled › Destructions & returns), clicks 'Record disposal' and picks a controlled medicine, which the page lists for controlled readers (EmarController.php:3525-3531). The lead fills in two witnesses with PINs, the authoriser and the denaturing confirmation, then submits. POST /emar/destructions goes to legacy(), which sees a controlled medicine and skips the ordinary path, then to action('destruction'). The validator requires witnessed_by (needsWitness), expected_balance, and expected_entry_id (must be present). The dialog sends witness_1_id, witness_1_credential, witness_2_id and disposal_method instead, so every controlled-drug destruction gets a 422. The errors land on fields the dialog doesn't render. Nothing is recorded and the lead cannot complete the approved one-path destruction from this page.
- **Fix:** For controlled rows, have the Destructions page hand off to the register's action-dialog ('destruction'), or make RecordDestructionDialog non-controlled only. Better: implement the planned redirect of /emar/destructions to /emar/controlled with a destructions view. Fix the test to post only what the UI sends.

### EA-075 · Destructions 'Export register' builds a client-side CSV that skips the export guard and audit, and ignores the active filters
- **Area:** Stock & controlled / Destructions
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Destructions.tsx:137-196, resources/js/pages/emar/Destructions.tsx:502, resources/js/pages/emar/Destructions.tsx:783, routes/emar.php:314-318, app/Http/Middleware/MedicationExportGuard.php:21-24
- **Scenario:** A finance user (medications.view plus stock.update, but no medications.reports.export) opens /emar/destructions, filters to one person, and clicks Export register. The browser downloads every loaded row for all permitted houses: person names, medicines, batches, the CD class, witnesses and notes. No purpose is asked for and no MedicationExportAudit row is written. The file is named after the UTC date, so between 00:00 and 13:00 NZDT it carries yesterday's date.
- **Fix:** Remove the client-side CSV. Use MedicationExportButton (or a guarded server route of type 'controlled' or 'destructions' behind MedicationExportGuard), passing the current site, client and search filters, and use the NZ date in the file name.

### EA-076 · Approved P06 stock grants not applied: no medications.stock.receive key, and team_lead has no stock.update
- **Area:** Stock & controlled drugs (P06), Downtime paper settlement (P10)
- **Audit dimension:** rbac
- **Status:** verified
- **Refs:** routes/emar-stock.php:7-15, routes/emar.php:150-163, routes/emar.php:438-446, database/seeders/RbacSeeder.php:802-843, database/seeders/RbacSeeder.php:915-956, app/Services/Medication/Downtime/PaperEntryService.php:376, app/Services/Medication/Downtime/PaperEntryService.php:389, app/Services/Medication/Downtime/HistoricalPaperContext.php:56, docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md:1082, docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md:1091
- **Scenario:** A pharmacy delivery arrives at Rimu at 7 pm. With stock lots on by default, it must be received as a lot with batch and expiry. The support worker and the house lead on shift both get 403 on /emar/stock/packs and /emar/stock/receive, because neither holds stock.update. The hub shows the house lead only the controlled views. After a downtime, the house lead (DowntimeAccess::manages = true) also cannot record the paper stock settlement (can_record_settlement=false).
- **Fix:** Add medications.stock.receive (seeder definition plus a grant migration to support_worker and team_lead) and a receive-only command path (for example a route group with permission:medications.stock.receive\|medications.stock.update, with MedicationStockController::command checking each command). Grant stock.update to team_lead through a migration. Record Stephan's finance decision (§2A.2). Pending §2A.4 confirmation.
- **Collision:** database/seeders/RbacSeeder.php (High #2); grant migration timestamped after 2026_10_08_235100

## P2 (106)

### EA-077 · Audit log date filter and Today/This week/This month counters use UTC days, so medication audit rows land on the wrong date
- **Area:** Audit log (/audit-logs, Settings › Audit log, HR audit)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Audit/AuditLogViewService.php:122-128, app/Services/Audit/AuditLogViewService.php:203-210, app/Http/Controllers/AuditLogController.php:17-41, app/Http/Controllers/Settings/AuditLogSettingsController.php:30
- **Scenario:** A manager investigating a medication error reported at 08:30 NZDT on 9 Oct filters /audit-logs to date_from = date_to = 2026-10-09. The medications.error.* and medications.competency.acknowledged rows written by AuditLogger at 08:30 (19:30 UTC 8 Oct) are excluded, and 10 Oct morning rows are included. Each row's displayed time (ISO, formatted NZ) shows 9 Oct, so the filtered list contradicts its own rows. In Settings › Audit log at 09:00 NZDT, 'Today' counts events since 13:00 NZDT yesterday; 'This week' and 'This month' start at Monday/1st 13:00 NZDT.
- **Fix:** Convert filter dates to NZ-day UTC bounds (parse in Pacific/Auckland, startOfDay/endOfDay, ->utc()) and use where/whereBetween. Base the stats on now('Pacific/Auckland')->startOfDay/startOfWeek/startOfMonth()->utc().

### EA-078 · A dose that comes due just before the person returns stays 'Away' and can't be recorded, though its window is still open
- **Area:** Away (leave, respite, hospital) / Meds today and rounds
- **Audit dimension:** workflows-frontline
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/DoseSlots/DoseAwaySources.php:77-81, app/Services/Medication/DoseSlots/DoseAwaySources.php:89, app/Services/Medication/DoseSlots/DoseAwaySources.php:96-97, resources/js/pages/meds/today/_rows.tsx:520, resources/js/pages/meds/today/_rows.tsx:595, resources/js/components/emar/rounds/types.ts:113-131
- **Scenario:** A person on day leave returns at 12:20 and the return is recorded. The 12:00 lunchtime dose (window 11:30–13:00) still reads 'Away · On leave', with only 'View'. Nothing prompts staff to check whether family gave it, and it is never overdue. If it wasn't given, the dose is missed with no record or alert.
- **Fix:** When an away period ends inside a dose's window and nothing is recorded, show the dose as 'Back — check whether it was given' (due), with Record and Not given actions, or end Away at the return for doses whose window is still open. Needs a C7 decision.

### EA-079 · Breadcrumbs break the canonical trail: one page is not Home-rooted, some use Home→/my-day, 'Medication' targets vary, and history labels conflict
- **Area:** Breadcrumbs
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/reports/history-logs.tsx:142-147, DESIGN.md:440-451, resources/js/pages/emar/ConnectedServices.tsx:8-13, resources/js/pages/emar/connected/_shared.tsx:137-143, resources/js/pages/emar/stock/StockHub.tsx:357-365, resources/js/pages/emar/reports/hub.tsx:440-445,466, resources/js/pages/emar/downtime/index.tsx:156-161, resources/js/pages/emar/downtime/show.tsx:300-305, resources/js/pages/emar/record/show.tsx:54-58, resources/js/pages/emar/WitnessOverrides.tsx:239-248, resources/js/pages/emar/reviews/index.tsx:546-552, resources/js/lib/emar-navigation.ts:749-775
- **Scenario:** On /emar/reports/history/logs the trail starts at 'Reports & audit' with no Home, the named DESIGN.md anti-pattern. On Connected services and Connected care, 'Home' opens /my-day. 'Medication' opens /emar (the lead-only Overview) on Stock packs, Reports, Downtime and the unavailable-record state, /meds/today on Controlled register, Witness overrides and Connected pages, and /emar/mar on Reviews. A frontline worker on a record they can't open is sent by the 'Medication' crumb to the lead Overview. The same history page is 'Medication history' in useEmarBreadcrumbs and 'Clinical history' in history-logs and the Reports button.
- **Fix:** Replace every hard-coded eMAR trail with useEmarBreadcrumbs() or useEmarRecordBreadcrumbs(), adding a mapped view for downtime and backups where needed. Root history-logs at Home. Pick one label for /emar/reports/history.

### EA-080 · Licensed picture catalogue contradicts the 29 Sep 'staff photos, no picture library' decision and appears on every Stock medicine
- **Area:** Catalogue / Stock
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md:397-413, resources/js/pages/emar/stock/StockHub.tsx:1015-1017, resources/js/pages/emar/stock/StockHub.tsx:1086, resources/js/pages/emar/catalogue/_medicine-picture.tsx:50, resources/js/pages/emar/catalogue/_medicine-picture.tsx:100-103, app/Services/Medication/MedicineCatalogue/MedicineCatalogueBindingService.php:42-50, app/Services/Medication/MedicineCatalogue/MedicineCatalogueBindingService.php:87-93, routes/emar-catalogue-backups.php:7-19
- **Scenario:** Any stock user who opens a medicine in Stock › Packs sees a 'Verified product picture' card saying 'No package-label product has been confirmed for this medicine', on the same step as the approved staff 'Pack photos'. Each open fires a row-locking GET /emar/catalogue/medicines/{id}/binding. No licensed dataset exists, so the card is always empty. If one were loaded, a second picture system labelled 'Verified' would sit beside the approved staff photos.
- **Fix:** Remove the MedicinePicture card and the catalogue entry points, or flag them off, until Stephan explicitly reverses the 29 Sep decision. Keep staff pack photos as the only picture source.

### EA-081 · Compliance dashboard sparklines and trend charts bucket break-glass, CD discrepancies, incidents, audit and Control Room alerts by UTC day, beside a MAR series bucketed by NZ day
- **Area:** Compliance (/compliance)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Compliance/ComplianceMetricsService.php:63-80, app/Services/Compliance/ComplianceMetricsService.php:117-158, app/Services/Compliance/ComplianceMetricsService.php:270-279, app/Services/Compliance/ComplianceMetricsService.php:320-331, app/Services/Compliance/ComplianceMetricsService.php:369-386
- **Scenario:** At 10:00 NZDT Fri 9 Oct, Carbon::today() is 8 Oct (UTC). An emergency-access (break-glass) grant at 09:30 NZDT 9 Oct lands in the bucket DATE()='2026-10-08', the last point, while the MAR sparkline's last point is NZ 9 Oct. After 13:00 NZDT that morning event moves to the second-to-last ('yesterday') point. The CD discrepancy trend chart labels a discrepancy raised 08:00 NZDT 9 Oct as 2026-10-08, and the 14-day Control Room alert trend (which includes medication alerts) is shifted the same way. Managers comparing MAR exceptions and CD discrepancies day by day see them a day apart.
- **Fix:** Bucket by NZ day: compute $from as now('Pacific/Auckland')->startOfDay()->subDays(n-1)->utc(), select the raw timestamps (or use CONVERT_TZ with named zones only if MySQL tz tables are loaded) and group in PHP by ->timezone('Pacific/Auckland')->toDateString(), as doseTotalsByDay does.

### EA-082 · Compliance KPIs link to /medications?tab=…, which redirects twice to the Overview and drops the tab (CD discrepancies never reaches the register)
- **Area:** Compliance → eMAR links
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Compliance/ComplianceMetricsService.php:123-128, app/Services/Compliance/ComplianceMetricsService.php:150-158, routes/medications.php:16-20, app/Support/EmarUrl.php:24-27, app/Http/Controllers/MedicationsController.php:14-20
- **Scenario:** A provider manager on /compliance sees 'CD discrepancies: 3' (critical) and clicks it. /medications?tab=controlled redirects to /emar/daily with no query, which 301s to /emar, the Overview. They have to find the controlled register's Discrepancies view themselves. 'MAR exceptions' also lands on the Overview, not a MAR or exceptions list. An auditor, for whom the nav hides Overview (lead-only), lands on it anyway.
- **Fix:** Emit canonical links from ComplianceMetricsService: '/emar/controlled?view=discrepancies' for CD, and for MAR exceptions /emar/reports?view=standard&report=doses with today's NZ period, or /emar/mar?date=today. Optionally make /medications map tab=controlled\|mar to those targets.

### EA-083 · External clinician portal disclosures (chart, allergies, source files) are not logged
- **Area:** Connected care / clinical portal
- **Audit dimension:** connected-care
- **Status:** verified (claimed P1)
- **Refs:** app/Http/Controllers/Emar/MedicationExternalClinicalController.php:92-134, app/Http/Controllers/Emar/MedicationExternalClinicalController.php:180-200, app/Services/Medication/ExternalClinical/ExternalClinicalAccess.php:175-202, app/Services/Medication/ExternalClinical/ProviderMedicationTransfers.php:189
- **Scenario:** A GP with a 90-day named grant opens /clinical-portal?client_id=42 each day, views the person's full current medication chart and allergies, and downloads proposal source files. Later the person or their family asks who outside the organisation has seen the records (HIPC disclosure). No view or download was recorded; only grant and proposal changes exist.
- **Fix:** Log 'medications.external.chart_viewed' (clinician, grant id, client, controlled included, medication count) when selected_client is built. Log 'medications.external.source_downloaded' in portalSource() and source(). Show this disclosure history per grant in Connected care › Prescriber access.

### EA-084 · Internal decision notes are shown word for word to the outside prescriber, with no warning
- **Area:** Connected care / clinical portal
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/connected/_forms.tsx:657-662, app/Http/Controllers/Emar/MedicationExternalClinicalController.php:130, app/Http/Controllers/Emar/MedicationExternalClinicalController.php:297-304, resources/js/pages/emar/ClinicalPortal.tsx:345-352
- **Scenario:** A coordinator rejects a GP's request with the note 'Declined – sister says she's hoarding pills; staff member made a dose error last week'. The GP's 'My requests' table shows that note exactly, disclosing family and staff information to an outside party.
- **Fix:** Rename the field to 'Reply to the prescriber (they will see this)' with helper text. Add a separate internal-only note stored in decision_evidence and never sent to the portal.

### EA-085 · Portal and outgoing handovers give prescribers an incomplete clinical picture without saying what is missing
- **Area:** Connected care / clinical portal
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/MedicationExternalClinicalController.php:100-110, resources/js/pages/emar/ClinicalPortal.tsx:182-189, resources/js/pages/emar/ClinicalPortal.tsx:195-216, app/Services/Medication/ExternalClinical/ProviderMedicationTransfers.php:221-226, app/Services/Medication/ClientAllergyRecordService.php:38-46
- **Scenario:** A GP whose grant excludes controlled medicines views a resident who takes oxycodone and plans to start tramadol. The chart simply leaves out the oxycodone. The notice says controlled medicines 'may be withheld' but not whether any actually are. Separately, a resident reviewed as 'No known allergies' and one never assessed both show 'No allergy entries are recorded here.'
- **Fix:** Return hidden_controlled_count and show '1 controlled medicine is not shown — contact the organisation'. Include the allergy review status ('No known allergies, reviewed <date>' or 'Not assessed') in both the portal and the packet.

### EA-086 · Connected care is shown to every orders.manage role and opens on tabs those users cannot use
- **Area:** Connected care / navigation
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:369-381, resources/js/pages/emar/connected/_entry-points.tsx:42, resources/js/pages/emar/connected/_entry-points.tsx:92-97, resources/js/pages/emar/ConnectedCare.tsx:34-38, resources/js/pages/emar/ConnectedCare.tsx:68-72, resources/js/pages/emar/ConnectedCare.tsx:178-253, resources/js/pages/emar/ConnectedCare.tsx:357-395, app/Http/Controllers/Emar/MedicationExternalClinicalController.php:54-70, database/seeders/RbacSeeder.php:686, database/seeders/RbacSeeder.php:744, database/seeders/RbacSeeder.php:1031
- **Scenario:** A coordinator has orders.manage but no connected-care keys. They see 'Connected care' in the Orders & reviews hub, and a 'Connected services › Prescriber requests' menu on every MAR record. The page opens on 'Prescriber access', which says 'No named access has been granted.' above an empty 'Verified prescribers' table. A 'Provider handovers' tab says 'No provider handovers for this person.' In fact the server never loaded those lists for this user. No outside clinician can exist yet anyway, because nobody holds medications.external.manage.
- **Fix:** Hide Connected care and its menus unless the user has externalManage or transfersManage, or a pending request exists for them. Filter tabs by props.can and default to the first allowed tab. Replace 'none granted' with 'You don't manage prescriber access'.

### EA-087 · Provider handover packet export bypasses the P09 export guard and export register
- **Area:** Connected care / provider transfers
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** routes/emar-external-clinical.php:19-20, app/Http/Controllers/Emar/MedicationExternalClinicalController.php:202-215, app/Services/Medication/ExternalClinical/ProviderMedicationTransfers.php:175-201, app/Http/Middleware/MedicationExportGuard.php:19-60, routes/emar.php:654
- **Scenario:** A user with transfers.manage and reports.export downloads /emar/connected-care/transfers/7/packet. The packet holds name, DOB, NHI, every current order including pending ones, last doses, and allergies with notes. No export purpose is captured, and the download does not appear in Reports › Export history. A second endpoint, /handover, returns the same JSON again and writes another generic AuditLogger row.
- **Fix:** Record both endpoints through MedicationExportAudit, with type 'provider_handover' and the transfer's purpose and disclosure basis as the purpose. Make /handover a non-download preview or remove it.

### EA-088 · Control Room and incident alert text prints UTC times as if they were local ('Missed dose … scheduled for 20:00' for an 08:00 NZ dose)
- **Area:** Control Room / incident notifications
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/MedicationIncidentIntegrationService.php:82, app/Services/MedicationIncidentIntegrationService.php:91, app/Services/MedicationIncidentIntegrationService.php:414-415, app/Services/MedicationIncidentIntegrationService.php:944, app/Services/MedicationIncidentIntegrationService.php:992, app/Services/MedicationIncidentIntegrationService.php:998, app/Services/MedicationIncidentIntegrationService.php:1021, config/app.php:99
- **Scenario:** A resident's 08:00 NZST dose (20:00 UTC the previous day) is recorded as missed, or as given 3 h late. The Control Room alert title and the dashboard alert say 'Missed dose: X scheduled for 20:00', and the late-dose incident description says 'Scheduled: 14/10/2026 20:00'. Operators and leads act on the wrong time and day.
- **Fix:** Format through WorkerClock, or ->copy()->timezone(config('app.worker_timezone')), at every listed line. Reuse OverdueDoseAlerts::dueLabel for alert titles.

### EA-089 · One 'Destructions & returns' tab opens two different pages: the legacy /emar/destructions page and the register's own Destructions view
- **Area:** Controlled drugs / destructions
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:435-442, app/Http/Controllers/Emar/ControlledProductController.php:23-26, app/Http/Controllers/Emar/EmarController.php:3463,3539, resources/js/pages/emar/ControlledRegister.tsx:56-62, resources/js/pages/emar/ControlledRegister.tsx:282-291, resources/js/pages/emar/ControlledRegister.tsx:381, resources/js/pages/emar/Destructions.tsx:450-459
- **Scenario:** A coordinator clicks the register's 'Pharmacy receipt' meter and lands on /emar/controlled?view=destructions. The rail highlights 'Destructions & returns' because the alias matches. They switch to Loss reports and click 'Destructions & returns' again. This time they get /emar/destructions, a different legacy page titled 'Disposal & returns' with its own layout and record actions. Two destruction registers are reachable from one tab.
- **Fix:** Make /emar/destructions redirect to /emar/controlled?view=destructions (keep site and client filters), set the nav href to the register view and keep /emar/destructions as an alias. A destruction-only stock.update holder needs the register's destruction view allowed, or keep the legacy page for them only.

### EA-090 · Hard-coded role names in controlled-drug, downtime and house-lead authority contradict the documented Settings › Roles path
- **Area:** Controlled register (P07b), Downtime (P10), RBAC
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Controlled/ControlledRegisterService.php:424-425, app/Services/Medication/Controlled/ControlledRegisterService.php:594-595, app/Services/Medication/Controlled/ControlledRegisterService.php:258, app/Services/Medication/Controlled/ControlledRegisterService.php:677-680, app/Services/Medication/Controlled/ControlledProductPayload.php:141, app/Services/Medication/Controlled/ControlledProductPayload.php:216, app/Services/Medication/Downtime/DowntimeAccess.php:21-25, app/Services/Tasks/Providers/MedicationPaperConfirmationProvider.php:27-31, database/seeders/RoleCatalogSeeder.php:19-56, docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md:1220
- **Scenario:** (1) Following Approval-record l.1220 ('an admin grants medications.controlled.view and medications.controlled.manage to Clinical Lead in Settings › Roles. No code change is needed'), an admin gives clinical_lead controlled.manage. The clinical lead still cannot close a loss investigation (403, PM role only) or sign off override doses (button hidden, team_lead/PM only). (2) The organisation's catalogue role 'onsite_team_leader' (RoleCatalogSeeder) is given the house-lead keys. That lead cannot start or finish downtimes or authorise paper postings, because DowntimeAccess::manages needs one of five role slugs, and is never named owner of a discrepancy. (3) A discrepancy at a house whose lead has the house only as a secondary site, or that has no team_lead, gets owner_id null, or the lowest-id 'is_active' team_lead regardless of employment end date.
- **Fix:** Replace the role checks with permission keys, each with a grant migration: loss close and override sign-off under medications.controlled.manage (or a distinct key), downtime management under a medications.downtime.manage key or errors.manage. Resolve the discrepancy owner through the same role-group or current-staff resolver the alerts use (HrCurrentStaffService, primary and secondary Sites). Correct Approval-record l.1220 if role names are kept.

### EA-091 · Controlled register breadcrumb 'Stock & controlled drugs' points to /emar/stock, which is 403 for house leads
- **Area:** Controlled register / breadcrumbs
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/ControlledRegister.tsx:861-868, routes/emar.php:152-157, resources/js/lib/emar-navigation.ts:384-419, resources/js/lib/emar-navigation.ts:643-646
- **Scenario:** A team lead (controlled.view and orders.verify, no stock.update) opens Medication › Stock & controlled drugs, which lands on Discrepancies, the first view they may open. In the register they click the 'Stock & controlled drugs' breadcrumb and get 403. The 'Medication' crumb takes them to /meds/today instead of their module landing.
- **Fix:** Replace the hard-coded trail with useEmarBreadcrumbs(), which already maps /emar/controlled[?view=…] to the right hub and view.

### EA-092 · Legacy controlled-drug pharmacy delivery writes the register itself, duplicating ControlledRegisterService (no register workflow event, no P09 event)
- **Area:** Controlled register / Stock
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** routes/emar.php:410-411, app/Http/Controllers/Emar/EmarController.php:6959-7150, app/Http/Controllers/Emar/EmarController.php:7080-7101, resources/js/pages/emar/StockManagement.tsx:481-488, resources/js/pages/emar/StockManagement.tsx:977-990, resources/js/pages/emar/_stock-dialogs.tsx:1284, app/Services/Medication/Controlled/ControlledRegisterService.php:189-208, app/Services/Medication/Controlled/ControlledRegisterService.php:624-648, app/Http/Controllers/Emar/MedicationStockController.php:116, app/Http/Controllers/Emar/MedicationStockController.php:167
- **Scenario:** A house lead receives 20 morphine tablets from a dispensed controlled-drug order on /emar/stock?view=orders. ControlledPharmacyDeliveryDialog posts to /emar/stock/pharmacy-orders/{order}/controlled-delivery. EmarController::receiveControlledPharmacyOrder then calls ClientControlledDrugEntry::create itself. Presence, witness PIN and balance are checked (:7022-7067), so the entry is valid, but no ControlledWorkflowEvent is written, there is no 'controlled.*' P09 event, and the audit action is 'medications.controlled.pharmacy_delivery.receive' instead of the register's 'medications.controlled.entry.record' (source P07). The same delivery received through Stock hub › receive_controlled uses ControlledRegisterService::recordReceipt and appends events. The register now has two receipt writers whose audit trails differ.
- **Fix:** Have receiveControlledPharmacyOrder delegate to ControlledRegisterService::recordReceipt, plus MedicationStockService::controlledDeliveryAfterRegister for packs, inside the same transaction. Alternatively, send controlled-drug deliveries from the legacy page to the Stock hub receive flow.

### EA-093 · A witness override signed off before it expires is never marked signed off and shows as an overdue follow-up indefinitely
- **Area:** Controlled witness overrides (P07a/P07b)
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Controlled/ControlledRegisterService.php:594-616, app/Services/Medication/Controlled/ControlledProductPayload.php:97, app/Services/Medication/Controlled/ControlledProductPayload.php:215, app/Services/Medication/Controlled/ControlledProductPayload.php:253-254
- **Scenario:** 1. A manager approves a controlled witness override for 07:00 to 15:00. 2. A worker gives one dose under it at 08:00. 3. At 10:30 the house lead joins a matching witnessed count and signs that dose off. 4. Because the override has not yet expired, signed_off_at is not set. 5. No later step sets it: a repeat sign-off returns 409 'already signed off', and no scheduler job does it. 6. After followup_due_at (the end of the next shift), the override shows followup_overdue = true. It stays in the outstanding list and the 'pending' and 'overdue follow-up' counters indefinitely.
- **Fix:** When the last dose is signed off before expiry, record that, and set signed_off_at when the override expires. Either the expiry or escalation job sets it, or the payload treats 'all recorded doses signed off' as complete. Alternatively, let a manager close an expired override whose doses are all signed off.

### EA-094 · A completed dose-slot backfill claims the whole period as covered even when orders were skipped
- **Area:** Dose slots / rollout commands
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** app/Console/Commands/BackfillMedicationDoseSlots.php:159-178, app/Services/Medication/DoseSlots/DoseSlotCoverage.php:33-45, app/Services/Medication/DoseSlots/DoseOrderTimeline.php:30-40
- **Scenario:** Main runs the approved backfill on .com. Two orders with out-of-order version history are skipped ('Order 812 skipped: its history could not be read'), but the run is marked completed. availableFrom() now reports coverage back to October 2025. For those two orders, every report and export in that period shows nothing due and nothing recorded, with no 'Not available' notice, because coverage is computed for the whole period and not per order.
- **Fix:** Do not mark a run completed when orders_skipped > 0. Use 'completed_with_gaps' and have availableFrom ignore those runs, or record skipped order ids and have the projection report them as not available. Show the skipped ids in the command output for repair with --order.

### EA-095 · Downtime list meters count only the current page ('Open on this page') and include a decorative 'PDF' meter; the approved To enter / To confirm meters are missing
- **Area:** Downtime & paper records (P10)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/downtime/index.tsx:107-136, resources/js/pages/emar/downtime/index.tsx:172, resources/js/pages/emar/downtime/show.tsx:241-264
- **Scenario:** After a 2-hour outage, a support worker opens Downtime & paper records to confirm the doses they gave on paper. The header shows 'Open on this page' and 'Collected on this page', counted from the paginated rows, plus a 'Today's downtime pack · PDF' block. Nothing says how many paper records are waiting for them to enter or confirm.
- **Fix:** Return to_enter and to_confirm counts (scoped to the viewer's paper entries) and the monthly total from the controller. Render the approved three meters with value-driven tones. Move 'Make the pack' to the actions cluster only. On the show page, link each meter to a filtered subset.

### EA-096 · Emergency-access review work appears in All Tasks only after its review deadline has passed, and no notice is sent when a review falls due
- **Area:** Emergency access (P10) / All Tasks
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Tasks/Providers/MedicationEmergencyAccessReviewProvider.php:38-56, app/Services/Medication/EmergencyAccess/EmergencyAccessService.php:183-197, app/Services/Medication/EmergencyAccess/EmergencyAccessNotifications.php:75-94
- **Scenario:** A grant ends at 10:00 on Monday with review_days 2, so review_due_at is 10:00 Wednesday. Between Monday and Wednesday no reviewer has a task: the provider filters COALESCE(review_due_at, …) < now(), and no notification is sent when the grant ends or expires. The only notices are the 'Emergency access started' bell at the start and the 08:00 daily report. From Wednesday 10:00 the task appears already overdue. Three days later it triggers the level-2 manager escalation (see the All Tasks escalation finding).
- **Fix:** List ended, unreviewed grants from the moment they end (dueAt = reviewDueTime), so they show as due and then overdue. Optionally send a bell notice to reviewers when a grant ends or expires.
- **Collision:** Low: app/Services/Tasks/* only

### EA-097 · The copied-error privacy clean-up is a read-only list with no tracking, so copied medication-error text stays in linked incidents
- **Area:** Errors / Incidents / rollout commands
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** app/Console/Commands/ReviewCopiedMedicationErrorIncidents.php:8-40, app/Services/Medication/MedicationErrorReporter.php:81-91
- **Scenario:** Before PR #16, linking a medication error to an incident copied the error's free text (description, immediate action, review notes, outcome) into client_incidents. This could name a controlled medicine or a resident's health details, and incident readers without medications.view or controlled.view can see it. New links write only MedicationErrorSummary (Reporter l.81-91), but nothing has fixed the old rows. If nobody runs emar:review-copied-error-incidents and hand-redacts each incident, the exposure stays. Even when someone does, there is no record of which incidents were reviewed.
- **Fix:** Run it on .com and production and record the output. Add a durable review marker (e.g. a medication_error_incident_reviews row, or a flag on the incident) and a Safety & oversight task listing unreviewed copies, so completion can be checked. Redaction itself goes through the Incidents audited edit, with Stephan's approval.
- **Collision:** Any redaction UI would touch app/Http/Controllers/IncidentController.php and incident-detail-dialog (H&S lane, map §3 items 9 and 16). The marker and task in the medication domain do not collide.

### EA-098 · The P08b 'entered in error' status cannot be stored (enum), yet the export dialog offers 'Include records marked in error'
- **Area:** Errors / Reports
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** database/migrations/2026_03_27_000001_create_medication_errors_table.php:38, app/Services/Medication/Reporting/MedicationReportDataset.php:161-162, app/Http/Controllers/Emar/MedicationReportsController.php:187, app/Http/Controllers/Emar/MedicationReportsController.php:266-269, app/Services/Medication/Reporting/MedicationGovernanceReports.php:39, app/Services/Medication/Reporting/MedicationGovernanceReports.php:48, resources/js/pages/emar/reports/_export-dialog.tsx:337-346, resources/js/pages/emar/reports/_export-dialog.tsx:446-454
- **Scenario:** An auditor exporting Errors ticks 'Include records marked in error', and the review step says 'Included and labelled'. The file is identical either way, because no row can ever have status in_error. When P08b's held entered-in-error writer is built, its first UPDATE … status='in_error' fails under MySQL strict mode ('Data truncated for column status') and returns a 500.
- **Fix:** Until the writer is approved, hide the checkbox and the review row (hide-unbuilt rule) and drop the dead include_in_error parameter. When building P08b, add a forward migration (after 2026_10_08_235100) that widens the enum or converts it to a string with a check, before the writer ships.

### EA-099 · Family portal medication lists show unchecked, sent-back, ceased and paused orders as current medicines, as raw models
- **Area:** Family portal
- **Audit dimension:** privacy
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/PortalClientController.php:33-35, app/Http/Controllers/PortalClientController.php:143-145, app/Http/Controllers/Portal/PortalHealthController.php:34-36, app/Http/Controllers/Portal/PortalHealthController.php:54-56, app/Services/Medication/MedicationOrderWorkflow.php:138-141, app/Services/Medication/MedicationOrderWorkflow.php:263, app/Models/ClientMedication.php:150-157, app/Models/ClientMedication.php:317-327, resources/js/pages/portal/client.tsx:150-185
- **Scenario:** A coordinator enters a new order (state active, active=true, approval pending_verification) and the checker sends it back as wrong (approval_status rejected, still active=true). The next-of-kin, with consent and can_view_medications, opens /portal/clients/{id}. 'Medications' lists the rejected order, plus every ceased and paused order, with no status shown. The raw JSON also carries rejection_reason, ceased_reason, created_by and verified_by. /portal/clients/{id}/health ('N active') also counts the unchecked and rejected orders.
- **Fix:** Use ClientMedication::active() (or ->current() plus an explicit state and approval filter) on both portal endpoints. Map to an allowlist of name, dosage, frequency, route and patient instructions. Decide the controlled-medicine rule for family viewers explicitly.

### EA-100 · A transport journey can record only one dose per medicine, so a second dose on a long outing cannot be recorded in Fleet
- **Area:** Fleet transport medication recording
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Fleet/ResidentTransportJourneyService.php:741-745, app/Services/Fleet/ResidentTransportJourneyService.php:1053-1054, app/Services/Fleet/ResidentTransportJourneyService.php:2084-2091
- **Scenario:** 1. A day outing runs 10:00 to 19:00, with a scheduled medicine at 12:00 and 17:00 (or a PRN given twice). 2. The driver packs the medicine once. A second pack of the same medicine for the same journey is refused: 'This medication is already packed for the journey.' 3. The 12:00 dose is recorded through the transit log. 4. At 17:00 the log is already resolved: 'This medication custody record has already been resolved.' 5. The second dose has no Fleet path. Staff must record it from Meds today while the physical stock is still in transit custody, so stock and custody evidence diverge.
- **Fix:** Allow several administrations per transit log, linking each administration to the log and drawing from the transit pack lines, or allow re-packing the same medicine for each dose. Settle the return quantity against the sum of the doses.

### EA-101 · Expired or disputed second-person confirmations, and other P08a follow-ups that have due times, never raise an alert. 'Follow-ups overdue' covers only legacy refusal follow-ups
- **Area:** Follow-ups / alerts
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/ForgottenWitnessPinService.php:199-216, app/Services/Medication/ForgottenWitnessPinService.php:301-331, app/Services/Medication/Followups/MedicationFollowupService.php:731-735, app/Services/Medication/Followups/MedicationFollowupService.php:895, app/Services/Medication/Alerts/MedicationAlertSources.php:353-390, app/Services/Medication/Alerts/MedicationAlertCatalogue.php:123-136
- **Scenario:** The named colleague answers 'No, I wasn't there' to a dose recorded with the fallback, which suggests a possibly false witness on a dose. Alternatively, the 30 minutes lapse. finish() flags the dose for review, and completeFromSource creates a 'disputed' lead follow-up with owner null, due by the next shift end. Nobody is notified: not the recorder, not the house lead, not the clinical lead. When that follow-up passes its due time, the 'Follow-ups overdue' alert still does not fire. It only reads MedicationRefusalFollowup. The issue surfaces only if a lead opens /medication-followups or All Tasks.
- **Fix:** Extend overdueFollowUps() (or add a source) to raise FOLLOW_UPS for overdue MedicationFollowup rows, at minimum the LEAD_TYPES disputed, unconfirmed and countersign. Key them 'followup:{id}', reconcile on completion, and update the catalogue subline. Raise an immediate alert (to the house lead and clinical lead) when a confirmation is disputed.

### EA-102 · The follow-up import (emar:workflow-followups --import) previews legacy 'review required' doses but cannot create work for them
- **Area:** Follow-ups / rollout commands
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** app/Console/Commands/RefreshMedicationFollowups.php:48-54, app/Console/Commands/RefreshMedicationFollowups.php:60-90, app/Services/Medication/Followups/MedicationFollowupService.php:180-188, database/migrations/2026_10_03_100000_add_p01_recording_contract_columns.php:41, app/Http/Controllers/Emar/WorkerMedsController.php:548-615
- **Scenario:** Before running the one-off import on .com, Main runs --preview. Rows with 'Review required: Yes' are listed, including doses flagged before P01 and doses flagged by shift cancellation. Main runs --import, which reports 'Prepared N administration sources'. None of the keyless review flags become follow-ups, so /medication-followups and All Tasks still show nothing for them. Until the import runs at all, pre-deploy refusal re-offers appear on Meds today (legacy MedicationRefusalFollowup reader) but not on the lead's Follow-ups register.
- **Fix:** In the import, map review_required rows that have no key to a legacy key (e.g. 'legacy_review'), passing the existing review_reason as context, so they become 'unconfirmed' follow-ups. Or exclude them from the preview and say why. Run --preview and --import on .com and record the batch boundaries.

### EA-103 · Connected care, outside prescriber portal, pharmacy bridge, catalogue and backups are live on main with no recorded approval
- **Area:** Governance (all connected surfaces)
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** routes/emar.php:666-670, database/migrations/2026_10_07_095000_register_connected_medication_permissions.php:10-20, database/seeders/RbacSeeder.php:30-47, docs/emar-connected-care/ACCEPTANCE.md, docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md (worktree-only, untracked; no entry)
- **Scenario:** Stephan's 3 Oct instruction was to build approved mockups only. PR #18 merged five new modules: external login accounts, an outbound pharmacy bridge, a public API endpoint, emailed whole-house charts, and a licensed picture library. Pushing main auto-deploys to the .com test server, so these routes and migrations are probably live there. The six new keys are granted to nobody, so the owning workflows are dead, yet parts still appear on baseline roles' screens (see the related findings).
- **Fix:** Put all five surfaces behind one config flag, default off, that also removes their nav entries, menus and cards. Keep it off until Stephan approves each one (with a mockup) or orders it removed, and record that decision in the Approval record on main.

### EA-104 · Identity verification, catalogue review and handover review can each be done by one person alone
- **Area:** Governance (external access, catalogue, transfers)
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/ExternalClinical/ExternalClinicalAccess.php:27-51, app/Services/Medication/ExternalClinical/ExternalClinicalAccess.php:54-83, app/Services/Medication/MedicineCatalogue/MedicineCatalogueService.php:34-41, app/Services/Medication/MedicineCatalogue/MedicineCatalogueService.php:100-116, app/Services/Medication/ExternalClinical/ProviderMedicationTransfers.php:100-109, app/Services/Medication/ExternalClinical/ProviderMedicationTransfers.php:175-193
- **Scenario:** One staff member with medications.external.manage creates an approved 'external clinician' login for any email address. They tick identity_confirmed themselves, type a free-text registration number, and are recorded as verified_by. They then grant that identity access to a resident's chart, including controlled medicines if they hold controlled view and record. In the same way, one catalogue manager can attest the licence, import, upload photos and 'review' their own source. One transfers manager can create an outgoing handover, 'review' it and download the packet.
- **Fix:** Require a different person for: identity verification versus granting, catalogue review versus creation, and handover review versus creation (reject when the actor ids match). Store the professional-register check (MCNZ/NCNZ) as structured evidence.

### EA-105 · The handover snapshot pre-fills self-managed and waiting-for-check doses as 'Medications due'
- **Area:** Handover › Medications due (eMAR lens)
- **Audit dimension:** workflows-frontline
- **Status:** confirm with a failing test first
- **Refs:** app/Services/EnhancedMarService.php:540-550, app/Services/Emar/ShiftMedicationSnapshotService.php:82-86, app/Services/Emar/ShiftMedicationSnapshotService.php:129, app/Services/Emar/ShiftMedicationSnapshotService.php:138-145, app/Domain/Hr/Services/AttendanceService.php:2051, resources/js/pages/operations/handovers/components/handover-wizard.tsx:436-460
- **Scenario:** A resident self-manages their inhaler (support mode self_managed). At the 15:00 handover the wizard pre-fills 'Ventolin — due 17:00' under Medications due, and the due count includes it. The incoming worker is told to give a dose that staff shouldn't give. Clock-out and Meds today correctly leave it out.
- **Fix:** Map STATE_SELF_MANAGED and STATE_PENDING_CHECK explicitly in getScheduleState (e.g. 'self_managed' and 'pending_check'), and exclude both from the snapshot's $pending and from can_record.

### EA-106 · /api/medications is a second dose-write surface: a client-supplied override_window skips the late-dose reason, and 'missed' and safety overrides are accepted
- **Area:** Legacy /api/medications
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** routes/api_medications.php:12, routes/api_medications.php:35-37, routes/api_medications.php:63-66, app/Http/Controllers/Api/MedicationsApiController.php:918-1211, app/Http/Controllers/Api/MedicationsApiController.php:934, app/Http/Controllers/Api/MedicationsApiController.php:950-957, app/Http/Controllers/Api/MedicationsApiController.php:1213-1454, app/Services/EnhancedMarService.php:903-916, resources/js/lib/medication-scan.ts:44, resources/js/pages/emar/_dialogs.tsx:90
- **Scenario:** At 15:30 a support worker sends POST /api/medications/clients/12/medications/55/administrations with {status:'given', scheduled_for:'2026-10-09T08:00', override_window:true}. EnhancedMarService skips the 'Outside time window… Please provide a reason' block (:903-909), so a dose 7.5 hours late saves with late_reason null. The same submission to /meds/today/record is rejected, because WorkerMedsController never forwards override_window. The endpoint also accepts the retired 'missed' status. Its correctAdministration is a separate correction implementation: it scopes by accessibleSiteIds only, with no ClientPolicy::viewMedications person rule, and writes no idempotency receipt and no P09 event.
- **Fix:** Keep scan-code, scan-verify and allergies GET/POST. Remove administrations.record, administrations.correct, scheduled-counts, alerts ack/resolve, widgets, mar, reports and interactions GET once callers are confirmed absent. Until then, drop override_window and 'missed' from the validator, and delegate corrections to PersonMedicationCorrectionController.

### EA-107 · Legacy /api/medications/alerts returns every resident's medication alert messages at the reader's houses, ignoring the per-person rule
- **Area:** Legacy medications API / alerts
- **Audit dimension:** privacy
- **Status:** confirm with a failing test first
- **Refs:** routes/api_medications.php:77-83, app/Http/Controllers/Api/MedicationsApiController.php:1498-1545, app/Services/MedicationIncidentIntegrationService.php:78-83, app/Services/Medication/MedicationRecordAccess.php:83-96, app/Services/ControlRoom/ControlRoomAlertAccessService.php:34-47
- **Scenario:** A support worker assigned to one resident at House A calls GET /api/medications/alerts (session auth works). They receive up to 50 active alerts for every House A resident, such as 'Missed dose: Methadone 20mg scheduled for 08:00' (support workers hold controlled.view), with client names. The canonical rule says ordinary support workers keep only the residents they are assigned to or covering.
- **Fix:** After readerSiteIds, add whereIn('client_id', app(MedicationRecordAccess::class)->readableClientIds($user, <site client ids>)). Do the same in getDashboardWidgets. If the endpoint has no live caller, retire it.

### EA-108 · MAR hub person-filter loop: after 'Back' from a record, the 'MAR charts' tab and meters reopen the same person's record
- **Area:** MAR & medicines
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/record/show.tsx:97-108,181, resources/js/lib/emar-navigation.ts:184-198, resources/js/pages/emar/record/hub.tsx:94-127, resources/js/pages/emar/record/hub.tsx:166, resources/js/pages/emar/record/hub.tsx:329-344, app/Http/Controllers/Emar/EmarController.php:1249-1258, app/Http/Controllers/Emar/MedicationRecordHubController.php:127
- **Scenario:** A team lead opens Aroha's record from the MAR hub and clicks the header back arrow. They land on /emar/medications?client_id=12, which silently shows only Aroha's medicines, with no person chip or clear control. They click the 'MAR charts' rail tab, or the Due/Overdue meter, to see everyone's charts and are taken back into Aroha's record. The cross-person chart list can only be reached by the breadcrumb.
- **Fix:** In hub.tsx visit(), drop client_id when the target view is 'charts'. Show a removable person chip whenever filters.client_id is set. Build the record backHref without client_id, using emarScopedHref with site_id and date only.

### EA-109 · Person record filter row has a disabled decorative 'As at' pill and none of the approved per-section filters; Day/Week sits below the header
- **Area:** MAR & medicines › Person record (P02)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/record/show.tsx:308-321, resources/js/pages/emar/record/show.tsx:333-361, resources/js/components/emar/record/history.tsx:112-260
- **Scenario:** On the History tab an auditor wants to see only 'Not given' doses from the last 7 days. The header filter row holds just a greyed-out 'As at 9:12 am · Pacific/Auckland' button. History has no range or outcome filter, only paging. On Chart, the Day/Week switch sits on the page ground beside the tier-2 tabs rather than in the header.
- **Fix:** Supply each section's approved filters through `filters`, wiring History range and outcome to the server query. Move ChartViewSwitch into the header filter row. Make the 'As at' chip a working refresh, as in Meds today, or drop it.

### EA-110 · Person record header lacks the approved Due now / Late / Recorded-today meters and Print MAR, and adds an unapproved 'Connected services' menu
- **Area:** MAR & medicines › Person record (P02)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/record/show.tsx:206-221, resources/js/pages/emar/record/show.tsx:223-305, resources/js/pages/operations/clients/tabs/mar.tsx:570-578
- **Scenario:** A house lead opens Aroha's medication record to see whether anything is due or late. The header shows only Medicines, Allergies, INR and Syringe driver. To learn that two doses are late they must open Chart and scan the grid. To print the MAR they must find the 'Report' button inside the Chart section, which no other section has.
- **Fix:** Add the three dose meters from the record's day payload (honest n/a when nothing is due) with the mockup's targets. Add a 'Print MAR' glass button that opens MarReportDialog when reports.export is held. Move ConnectedServicesMenu off the person header, or get it approved.

### EA-111 · MAR & medicines hub: approved meters, 'Needs help' filter and row menu missing; no right-click; hand-rolled pagination
- **Area:** MAR & medicines hub (P02)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/record/hub.tsx:161-232, resources/js/pages/emar/record/hub.tsx:258-271, resources/js/pages/emar/record/hub.tsx:375-640, resources/js/pages/emar/record/hub.tsx:616-635, resources/js/pages/emar/record/hub.tsx:643-668
- **Scenario:** A team lead on MAR charts wants the people whose doses are blocked. There is no Needs help meter or filter, because Work offers only Due now and Overdue. Right-clicking a person row opens the browser's own menu. The kebab offers only 'Open medication record'; Record dose, Why can't these be recorded, Open client profile and Print MAR are missing. Paging uses bespoke Previous/Next buttons.
- **Fix:** Build the six approved meters, adding Needs help and a Recorded-today donut to the controller meters. Add 'help' to the Work filter. Pass onRowContextMenu with the same MenuItem[], and move Record dose, Why and Print MAR into it. Swap in LaravelPagination.

### EA-112 · Medication-error incidents are created with withoutEvents, so the standard 'Incident submitted' and 'High severity incident' notifications and the incident creation audit row are skipped
- **Area:** Medication errors (P08b) / Incidents
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/MedicationErrorReporter.php:82-99, app/Http/Controllers/IncidentController.php:1098, app/Http/Controllers/IncidentController.php:1296-1338, config/notification_routing.php:40-53, app/Models/ClientIncident.php:18, app/Models/Concerns/AuditableChanges.php:10-23
- **Scenario:** A worker reports a critical wrong-dose error from /emar/errors, or a 'More than ordered' dose at severity major, with 'create incident' on. ensureIncident creates a 'submitted' ClientIncident inside ClientIncident::withoutEvents. Coordinators and assigned workers never get the 'incidents.submitted' bell that incidents filed through IncidentController produce. managers_core never get 'incidents.high_severity_alert'. The AuditableChanges 'clientincident.create' audit row is not written. The Medication Settings 'errors' alert goes only to house lead and clinical lead by default, and the error's Control Room rule recipients were cleared by the C1 migration.
- **Fix:** After ensureIncident commits, send the same incidents.submitted notification (and the high-severity one for high or critical) through a shared IncidentSubmissionNotifier, and write the incident creation audit entry explicitly. Coordinate with the H&S lane, whose incident-owner routing also relies on these events.
- **Collision:** Medium: app/Http/Controllers/IncidentController.php (H&S hunks) if the notifier is extracted from it. ClientIncident owner routing is in-flight in the H&S lane.

### EA-113 · Completing a medication review leaves out held medicines and orders still waiting for their first check
- **Area:** Medication reviews (P05)
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Reviews/MedicationReviewWorkflow.php:185-195, app/Services/Medication/Reviews/MedicationReviewReader.php:64, app/Models/ClientMedication.php:317-327, app/Http/Controllers/Emar/MedicationOrdersController.php:276
- **Scenario:** 1. A person has warfarin on hold (Orders > Hold, pending an INR result) and a new antibiotic waiting for its first check. 2. The GP's 3-monthly review is recorded. 3. The review form lists only active, verified orders, and complete() rejects any other set as 'The current orders changed'. 4. The clinician cannot record an outcome (stop, restart, change or watch) for the held warfarin. The review evidence and its follow-ups say nothing about a held anticoagulant, and there is no review item to drive an Orders stop or restart.
- **Fix:** Build the review item set from current() with state != 'ceased' (including paused and pending orders), show the held or pending state on each row, and allow continue, stop or change outcomes for them.

### EA-114 · An agreed review recommendation stays linked to its sent-back order version; it can't be re-entered from the review and disappears from tasks
- **Area:** Medication reviews (P05) to Orders (P04)
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Reviews/MedicationReviewOrderAdapter.php:32-36, app/Services/Medication/Reviews/MedicationReviewOrderAdapter.php:57-60, app/Services/Medication/MedicationOrderWorkflow.php:250-268, app/Services/Tasks/Providers/MedicationReviewChangeProvider.php:52, app/Services/Medication/Reviews/MedicationReviewReader.php:172
- **Scenario:** 1. A GP review agrees to 'reduce quetiapine to 25 mg'. 2. The lead uses 'Enter in Orders' from the review, which links the version to the review item. 3. The checker sends that version back because of a typo. 4. The review still says 'Entered in Orders — check status there'. The All Tasks 'review change' item and the reviews 'changes to make' meter drop it, since linked_order_version_id is set. 5. Re-entering from the review handoff fails with 'This recommendation is not waiting to be entered in Orders.' A plain Orders re-entry is not linked to the recommendation. The prescriber-agreed change can quietly stall, with the person kept on the old dose, unless someone happens to notice the 'Sent back' row in Orders > To check.
- **Fix:** In sendBack, when the revision's version is linked to a review item (linked_order_version_id = version id), clear the link, keep the history in a review event 'order_sent_back', and let the item return to 'Agreed — to enter in Orders'. Alternatively, let lockRecommendation accept items whose linked version's revision status is sent_back.

### EA-115 · /emar/rounds Activity tab lists each dose with its UTC clock time
- **Area:** Meds rounds (legacy /emar/rounds, still linked)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/EmarController.php:3180, resources/js/components/emar/rounds/round-activity.tsx:167, resources/js/components/emar/rounds/round-activity-dialog.tsx:119
- **Scenario:** A house lead opens /emar/rounds (linked from the Overview and from MedicationReportDataset) › Activity for today. The 8 am round's doses given at 08:05 NZDT are listed as '19:05 · Jane · Morning round', and evening doses at 20:10 NZDT as '07:10'. Clicking a row opens the detail dialog, which formats the ISO administered_at correctly (8:05 am), so the list and the detail contradict each other.
- **Fix:** Change L3180 to `$a->administered_at?->copy()->timezone(app(MarScheduleService::class)->workerTimezone())->format('H:i')`, or drop 'time' and format administered_at on the client with formatTime.

### EA-116 · Meds today keeps showing a dose queued on this device as due or overdue, with no 'saved on this device' marker
- **Area:** Meds today
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/meds/today/index.tsx:1150-1153, resources/js/pages/meds/today/index.tsx:1208-1211, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1321-1326, resources/js/components/prn-sheet.tsx:146-157
- **Scenario:** Offline, a worker records the 08:00 dose and gets the toast 'Saved on this device … Don't record it again'. The dialog closes and the row still shows Due, then Overdue. A colleague on the same device and login, or the same worker after a break, sees an overdue dose and gives it again. The only cue was a transient toast.
- **Fix:** Merge useOfflineQueueState().pendingSubmissions into the board rows, matching client_medication_id and scheduled_for, and show a 'Saved on this device — not on the chart yet' StatusBadge on the row. Block reopening the record dialog for that slot until the item syncs or is rejected.

### EA-117 · Expired or not-yet-started as-needed orders are offered on Meds today, then rejected with a generic error on save
- **Area:** Meds today › As-needed
- **Audit dimension:** workflows-frontline
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Emar/MedsBoardPayloadService.php:545-552, app/Services/Medication/Recording/DoseRecordingRequirements.php:127-136, app/Services/Medication/Recording/DoseRecordingRequirements.php:261-273, app/Services/Medication/MedicationScopeDecisionService.php:200, app/Services/Medication/MedicationScopeDecisionService.php:865-878, app/Services/EnhancedMarService.php:121-131
- **Scenario:** A 5-day PRN course ended yesterday (end_date passed; state still 'active', and no job ceases orders). It stays in Meds today › As-needed with 'Record'. The worker completes the whole dialog (reason, check time, second person) and the save fails with 'The requested medication action is not available.' A future-dated PRN (start next week) is offered the same way.
- **Fix:** Filter PRN lists and requirements by NZ start_date and end_date, as build() does, and add a block_all key such as 'orderNotCurrent' with copy ('This as-needed order ended on …').

### EA-118 · The guided round shows 100% recorded but can't be finished, and doesn't say why
- **Area:** Meds today › Rounds (guided round)
- **Audit dimension:** workflows-frontline
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:1487-1511, app/Services/GuidedRoundService.php:229-262, resources/js/pages/emar/components/guided-round-dialog.tsx:106-107, resources/js/pages/emar/components/guided-round-dialog.tsx:252-266
- **Scenario:** An assignee walks the 08:00 house round and records every dose they can see (their shift's person), so progress reads '4 of 4 recorded'. Instead of 'Finish round', the dialog shows 'Round completion is not available yet.' Other residents' doses at the house, hidden from this worker, are still open. The worker gets no count or reason, and the round stays in progress.
- **Fix:** Return a concealed count (e.g. 'n doses for other people at the house are still open') and show it in the caption. Or allow the assignee to close their part. Root cause tied to the shared-support finding.

### EA-119 · PRN 'interval not elapsed' block message shows the last dose time in UTC
- **Area:** Meds today / PRN recording
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/MedicationSafetyService.php:637-664, app/Services/EnhancedMarService.php:1292-1337, app/Http/Controllers/Emar/WorkerMedsController.php:957-975, app/Services/Medication/Recording/DoseRecordingRequirements.php:453-458
- **Scenario:** Worker A gives ibuprofen (6 h minimum) at 14:00 NZDT (01:00 UTC). Worker B had the PRN dialog open from 13:55, so the requirements check passed, and submits at 14:02, or back-dates 'given at'. The server re-runs the check at the dose time and returns 422 with '⛔ INTERVAL NOT ELAPSED: Minimum 6 hours between doses required. Last dose was 01:00. Please wait 358 more minutes.' Staff see a 1 am 'last dose' that never happened and may distrust or work around the block. On the legacy /api/medications path with a safety override, the same string is written permanently into the administration notes ('Blocked check: …').
- **Fix:** Format as `$lastAdmin->administered_at->copy()->timezone(config('app.worker_timezone'))->format('g:i a')`. Better, keep the message keyed and let the client format details.last_administered_at, which is already ISO.

### EA-120 · Legacy /emar/rounds page still renders and the Rounds report links into it, while the nav calls it 'Meds today › Rounds'
- **Area:** Meds today / Rounds / Reports
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:239-246, app/Http/Controllers/Emar/EmarController.php:2965,3187, app/Services/Medication/Reporting/MedicationReportDataset.php:104, resources/js/pages/emar/reports/hub.tsx:349-350, resources/js/pages/emar/Rounds.tsx:481-488,591-605, resources/js/lib/emar-navigation.ts:749-775
- **Scenario:** A clinical lead opens Reports & audit › Rounds and clicks a 'not completed' round row. They land on the old 'Medication rounds' page (/emar/rounds), with Generate rounds, board and chart tabs, not the approved Meds today › Rounds view. Its breadcrumb reads Home › Medication › Meds today › Rounds, and both last crumbs link to /meds/today pages, so the current page's own crumb opens a different page.
- **Fix:** Point MedicationReportDataset round hrefs at /meds/today?view=rounds&date=…&site_id=…. Make GET /emar/rounds redirect there, or record the decision to keep it (§2A.12). Until then, map /emar/rounds as its own view so its crumbs do not lie.

### EA-121 · Readers without a shift (auditor, finance, office leads, support workers on a day off) are told they are 'Not clocked in' and given a 'Clock in' button on Meds today, which is their module landing.
- **Area:** Meds today / single-capability landing
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:1171-1193, app/Http/Controllers/Emar/WorkerMedsController.php:242, resources/js/pages/meds/today/index.tsx:810-832, resources/js/pages/meds/today/index.tsx:895-903, resources/js/pages/meds/today/_schedule.tsx:290-316, resources/js/lib/emar-navigation.ts:213-217, resources/js/lib/emar-navigation.ts:733-736, database/seeders/RbacSeeder.php:803, database/seeders/RbacSeeder.php:847, database/seeders/RbacSeeder.php:892
- **Scenario:** 1. An auditor (medications.view + audit.view) clicks 'Medication'. The module landing is the first visible hub, Meds today. 2. With no shift, they get the 'lead fallback board' of every person they may open. Because `has_shift_context` is true whenever that board is non-empty, the header shows a 'Not clocked in' chip. The primary action becomes 'Clock in' (replacing 'Record as-needed dose'), with the banner 'You can’t record anything until you clock in on a shift that includes these people'. 3. The same happens to finance and to coordinators. 4. A support worker on a day off also has medications.view (RbacSeeder l.803), so they too get the fallback board of their assigned residents with a clock-in prompt, instead of the designed 'not on your shift' section.
- **Fix:** - Send `has_shift_context` from `$hasShift` only, and add a `board_mode: 'oversight'` for the fallback, which hides the clock-in chip, CTA and banner. - Restrict the fallback to `hasLeadCapability`/audit holders; frontline readers keep the `off_shift` section.

### EA-122 · Meds today turns server failures into confident empty states: no follow-ups, no rounds, rows with no requirement lines, and the 'not on your shift' list disappearing.
- **Area:** Meds today payload degradation
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:287-319, app/Http/Controllers/Emar/WorkerMedsController.php:425-445, app/Http/Controllers/Emar/WorkerMedsController.php:449-465, app/Http/Controllers/Emar/WorkerMedsController.php:548-615, app/Http/Controllers/Emar/WorkerMedsController.php:1543-1592, resources/js/pages/meds/today/_lists.tsx:668-676, resources/js/pages/meds/today/_rows.tsx:76-92, resources/js/pages/meds/today/_rows.tsx:441, app/Http/Controllers/Emar/ClientMedicationDayController.php:205-212
- **Scenario:** 1. One malformed order (for example a missing site) makes `DoseRecordingRequirements::forBoard` or the refusal follow-up query throw. 2. Meds today still renders:    - every open row looks recordable, with no allergy-match, competency or 'needs help' lines, and the 'Needs help' filter is empty    - the Follow-ups tab says 'No follow-ups open — A refused dose … adds one here', so a refused dose's re-offer is missed    - rounds, the active round and the 'not on your shift' rows ('Shown so nothing is missed') vanish 3. Nothing tells the worker that the data is incomplete.
- **Fix:** - Return an `unavailable` flag per section (`requirements_unavailable`, `followups_unavailable`, `rounds_unavailable`, `off_shift_unavailable`). - Render a warning Notice ('Couldn’t load follow-ups — refresh before relying on this list') instead of the affirmative EmptyState. - Mark rows whose `req` failed with 'Checks not loaded'.

### EA-123 · The same late dose is 'Late' (warning) on Meds today but 'Overdue' (critical) on the MAR day, record chart and hub; Refused is critical
- **Area:** Meds today vs MAR chart / client MAR tab / hub
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/clients/profile/mar-day/dose-cell.ts:27-60, resources/js/pages/meds/today/_rows.tsx:175, resources/js/pages/emar/record/hub.tsx:213, resources/js/pages/emar/record/hub.tsx:268, resources/js/pages/emar/record/hub.tsx:412, resources/js/pages/operations/clients/tabs/mar.tsx:1-4
- **Scenario:** A worker sees '8:00 am Late' (amber) on Meds today, then opens the client profile MAR tab and sees the same dose as 'Overdue' in red. The hub meter also says 'Overdue', with critical tone. A dose the person refused shows red 'Refused' on the chart, as if it were an error.
- **Fix:** Make CELL_META and the hub follow the P00 map: 'Due' info, 'Late' warning, Refused and Withheld warning. Keep 'Not recorded' critical only for past days. Rename the hub's Overdue meter, filter and column to Late.

### EA-124 · Connected-care and actual-absence migrations have unguarded down() methods that drop clinical and identity evidence before a sibling guard can stop the rollback
- **Area:** Migrations / rollback safety
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** database/migrations/2026_10_07_096000_expand_medication_provider_transfer_event_evidence.php:16-23, database/migrations/2026_10_07_094000_create_medication_backup_delivery.php:79-86, database/migrations/2026_10_07_093000_create_medicine_catalogue.php:60-65, database/migrations/2026_10_07_092000_create_medication_provider_transfers.php:51-55, database/migrations/2026_10_07_091000_create_medication_external_clinical_access.php:72-78, database/migrations/2026_10_07_090000_create_medication_pharmacy_connections.php:77-88, database/migrations/2026_10_06_020000_record_actual_client_absence.php:30-43, database/migrations/2026_10_03_160000_add_medication_error_workflow.php:57-66, database/migrations/2026_10_03_212000_create_medication_downtime_evidence.php:96-101, database/migrations/2026_10_03_210000_complete_medication_emergency_access.php:60-70
- **Scenario:** Stephan decides to retire the unapproved connected-care surfaces (§2A.6), and someone runs `php artisan migrate:rollback` twice on .com. The first rollback undoes the workforce batch; the second undoes the PR #18 batch. In reverse order, 096000 passes (no evidence over 64 KB). 094000, 093000 and 092000 drop backup, catalogue and provider-transfer tables, including encrypted incoming allergy evidence. 091000 drops external clinician identities, grants, proposals and users.external_clinical_account. Only then does 090000 throw because pharmacy evidence exists. MySQL DDL auto-commits, so the batch is left half rolled back with the evidence gone. Rolling back PR #17 likewise drops departed_at, returned_at and the hospital admission/discharge columns (2026_10_06_020000) without a check.
- **Fix:** Add the same evidence guard used in 090000 and 2026_10_03_200500 to each of these down() methods (throw if any row or non-null column value exists), or make them throw unconditionally as 2026_10_03_210000_create_medication_event_chain does. Ship this as forward-only edits to down() (no schema change), or as a new guard-only migration timestamped after 2026_10_08_235100.

### EA-125 · Approved P01 Q1 'retire the My Day routes' not done: /my-day/medications/{id}/administer\|refuse\|snooze are live with no screen
- **Area:** My Day
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** routes/web.php:432-438, app/Http/Controllers/MyDayMedicationsController.php:41-113, app/Http/Controllers/MyDayMedicationsController.php:121-190, app/Http/Controllers/MyDayMedicationsController.php:200-241, app/Http/Controllers/MyTasksController.php:887-897, resources/js/pages/my-day/components/stream-context-menu.tsx:33-36, resources/js/pages/my-day/components/whats-next-rail.tsx:14-15, docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md:720 (worktree, untracked)
- **Scenario:** Any support worker with administer.record can send POST /my-day/medications/88/administer with {scheduled_for}. That records 'given' with dose_given defaulting to the prescribed dosage (:65), without the P01 recording-contract fields (amount mode, second-person kind). It writes no P09 dose.recorded event and no timeline event, only an AuditLogger 'meds.administer' row. POST .../snooze stores a cache key that MyTasksController::getShiftMedicationsDue honours (:887-897), hiding that due dose from the worker's own My Day briefing for up to 120 minutes. No screen calls any of the three routes.
- **Fix:** Delete the three routes, MyDayMedicationsController and the snooze read in MyTasksController. Delete the orphan components stream-context-menu, whats-next-rail, stream-item, now-rule and hover-action. Delete tests/Feature/MyDayMedicationActionTest.php and the route-based cases in DoseSlotOutcomeWriterTest, and remove the mock in pages/my-day/index-audit-fixes.test.tsx:97.
- **Collision:** routes/web.php (low: rebase noise); app/Http/Controllers/MyTasksController.php (Medium #13, H&S lane) for the snooze read; resources/js/pages/my-day/** (index.tsx is #14) — test mock edit only

### EA-126 · My Day 'next shift' briefing lists medicine names for the shift's client without the person rule the main My Day list applies
- **Area:** My Day
- **Audit dimension:** privacy
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/MyTasksController.php:1001-1006, app/Http/Controllers/MyTasksController.php:1071-1110, app/Http/Controllers/MyTasksController.php:827
- **Scenario:** A relief worker has a shift tomorrow for a resident they are not assigned to. On My Day, next_shift_briefing.medications_due_during_shift lists up to 6 doses ('Sertraline 50mg at 08:00'), up to 36 h before they hold any medication authority for that person.
- **Fix:** Pass [$shift->client_id] through DoseSlotReaderScope::forViewerClients(user, …) before querying. When the person is not readable, return a count only, or nothing.
- **Collision:** MyTasksController.php: H&S lane (§3 medium #13)

### EA-127 · The Meds today hub is shown to finance and auditor, unlike the approved P00 rule (administer or lead only)
- **Area:** Navigation › Meds today
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:225-229, resources/js/lib/emar-navigation.ts:745-750, database/seeders/RbacSeeder.php:845-855, database/seeders/RbacSeeder.php:891-893, routes/emar.php:64-66
- **Scenario:** A finance user (view, reports.export, stock.update, reports.view) opens Medication. Their first hub is 'Meds today', so the module landing and the 'Medication' breadcrumb go to /meds/today, a frontline dose board with nothing for them, instead of Stock & controlled drugs. The read-only auditor also gets a Meds today hub. The approved per-role nav (Revised-navigation l.122-123) lists Stock and Reports for finance, and MAR, Safety and Reports for auditor.
- **Fix:** Change the today hub to `visible: any(administer, hasLeadCapability)` (P00). If the HandleInertiaRequests badge rule (l.134-143) is meant to mirror the frontline mode, align it in the same change.

### EA-128 · Emergency access is hidden from the nav for audit.view reviewers, although the approved P00 nav shows it to them (NF-12)
- **Area:** Navigation › Safety & oversight
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:497-505, routes/emar.php:541-543, app/Http/Controllers/EmergencyAccessController.php:24, database/seeders/RbacSeeder.php:738-748, database/seeders/RbacSeeder.php:891-893, database/seeders/RbacSeeder.php:1019-1034
- **Scenario:** The clinical lead (P10 persona 'Hana reviews'), coordinators and the auditor hold audit.view but not breakglass. Safety & oversight has no 'Emergency access' entry and command search does not list it. They can reach the review queue only from an All Tasks item or a notification link. Coordinators, who hold the approved breakglass.end ending authority, also have no way to find running grants.
- **Fix:** Set `visible: any(breakGlass, auditView)` on the emergency view and update the stale comment. Ideally land this after the Site-scope fix above, so the page lists only the reviewer's houses.

### EA-129 · Health data persists in IndexedDB and localStorage after logout (queued payloads, rejected and quarantined items, follow-up drafts)
- **Area:** Offline / shared device privacy
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** resources/js/lib/offline-queue.ts:117-119, resources/js/lib/offline-queue.ts:473-487, resources/js/lib/offline-queue.ts:396-410, resources/js/components/emar/followups/followup-dialog.tsx:278-285, resources/js/hooks/use-form-autosave.ts:39, resources/js/components/user-menu-content.tsx:22-25
- **Scenario:** After worker A logs out of a shared house PC, IndexedDB 'oblivion-offline' still holds A's queued, rejected or quarantined medication payloads: client_medication_id, outcome, free-text notes, BP and glucose readings, refusal reasons. localStorage holds A's medication follow-up drafts (medication-followup-draft:<actor>:<id>). Anyone using the browser can read them in DevTools. Quarantined and unrejected items never expire.
- **Fix:** On logout, once the pending-queue check (previous finding) passes, delete this actor's synced, rejected and draft entries and keep only unsent items. Encrypt queued payloads with a per-session key held in memory or a non-extractable WebCrypto key. Expire follow-up drafts after a short TTL.

### EA-130 · A queued dose that the server refuses on replay with 403 or 404 is treated as a network failure. It loops through 'needs attention, retry safely' forever, its reason is never shown, and it cannot be dismissed.
- **Area:** Offline queue replay
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/offline-queue.ts:1047-1090, resources/js/lib/offline-queue.ts:1157-1170, resources/js/lib/offline-queue.ts:544-565, resources/js/components/offline-status-banner.tsx:91-94, resources/js/components/offline-status-banner.tsx:122-131, app/Services/Medication/MedicationScopeDecisionService.php:986-1004
- **Scenario:** 1. A worker records a refusal offline under an emergency grant. 2. The grant expires before the device reconnects. On replay, `activeBreakGlass` returns 403 'You do not have a current assignment…'. A person who moved house gives 404 instead. 3. The queue silently retries on 8 visibility events, then shows '1 queued item needs attention. Check the chart before trying again. Trying again won’t create a duplicate.' with 'Retry safely'. 4. Every retry hits 403 and returns straight to 'needs attention'. The server's reason is never shown, the item is never marked rejected, and it cannot be dismissed. The refusal never reaches the chart.
- **Fix:** - Treat 403 and 404 as definitive: call `markRejected` with `serverRejectionMessage`. - Treat 401/419 as `requires_authentication` ('Sign in again to send'). - Allow acknowledged dismissal of `needsAttention` items once a definitive status is known.

### EA-131 · No maximum age for queued medication actions: weeks-old offline doses are accepted as new administrations without review
- **Area:** Offline replay
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** resources/js/lib/offline-queue.ts:473-487, resources/js/lib/offline-queue.ts:1092-1129, app/Http/Controllers/Concerns/HandlesMedicationSync.php:24-54, app/Services/Medication/MedicationScopeDecisionService.php:167, app/Services/Fleet/ResidentTransportJourneyService.php:2159-2185
- **Scenario:** A dose queued and stranded on a house laptop (for example after a user switch) is replayed 3 weeks later when its worker signs in again. The server accepts it as given at the original time. That retroactively settles a slot that had already been reported as missed, with its overdue alert, follow-up and possibly a medication error. Stock is decremented today, and PRN windows in the past change. Nobody reviews it.
- **Fix:** Add a policy setting such as 'offline replay max age' (for example 12 h or the end of the shift). The server should refuse queued_offline medication submissions older than that with 422 'Too old to sync — use reviewed paper recovery'. That routes them into the existing paper reconciliation flow, not a silent insert.

### EA-132 · Stopping an order writes yesterday's date as end_date (UTC) and flips the stopped order back to 'pending_verification', clearing who checked it
- **Area:** Orders (P04) stop / MAR print
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/MedicationOrderLifecycleService.php:54, app/Services/Medication/MedicationOrderLifecycleService.php:117-125, config/app.php:99, app/Models/ClientMedication.php:66, app/Models/ClientMedication.php:159-170, app/Services/Medication/DoseSlots/DoseOrderVersion.php:23, resources/js/pages/emar/Orders.tsx:723-726, app/Services/Medication/Reporting/MedicationPdfDataset.php:46
- **Scenario:** 1. At 09:00 NZDT on 9 Oct (20:00 UTC on 8 Oct) a lead stops an order. 2. end_date is saved as 2026-10-08, the previous NZ day. 3. Because end_date is verification-sensitive, the updating hook resets approval_status to 'pending_verification' and nulls verified_by and verified_at on the ceased order. 4. Orders > Stopped shows the medicine's current version as 'Cannot give yet', and the canonical row loses its checker. 5. A printed MAR PDF for 9 to 15 Oct leaves the stopped medicine out of its orders list, because end_date is earlier than period.from, even though its 08:00 dose on the 9th was given.
- **Fix:** Compute end_date as $effectiveCeasedAt->copy()->timezone(config('app.worker_timezone'))->toDateString(). In ClientMedication's updating hook, skip the verification reset when the change is a cessation (state becoming ceased), so the checked status and checker are kept.

### EA-133 · The legacy prescriptions page (linked from P04 Orders) ejects the user to /emar/prescriptions when the house filter changes, and shows a rail that claims it is 'Prescriptions'
- **Area:** Orders / legacy prescriptions
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Orders.tsx:755-761, resources/js/pages/emar/Prescriptions.tsx:307-313, resources/js/pages/emar/Prescriptions.tsx:741-749, resources/js/pages/emar/Prescriptions.tsx:652,707-720,1449-1467, resources/js/lib/emar-navigation.ts:692-700, routes/emar.php:173-174
- **Scenario:** From Orders a coordinator clicks 'Open supply and dispensing records' (/emar/prescriptions/legacy). The page header says 'Prescriptions & dispensing', and the rail shows the Orders & reviews hub with 'Prescriptions' active, so it looks like the P04 page. They pick a House in the filter and are taken to /emar/prescriptions (the P04 Orders page), losing the dispensing view. The legacy page also offers its own 'New order' and 'Covert' dialogs, a second order-entry path next to P04's.
- **Fix:** Make onSite route to '/emar/prescriptions/legacy'. Give the legacy page its own nav identity (an alias view or no hub rail) and a clear 'Back to orders' link. Hide its New order and Covert actions in favour of P04 entry (single write seam, dimension 6.6) pending the §2A.12 retirement decision.

### EA-134 · Orders & reviews rails don't match the hub map: Connected care is missing and labels drift; ConnectedCare has its own rail and wrong hard-coded crumbs
- **Area:** Orders & reviews / Connected care
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:324-383, resources/js/pages/emar/Orders.tsx:614-646, resources/js/pages/emar/reviews/index.tsx:96-102,709-721, resources/js/pages/emar/ConnectedCare.tsx:59-72, resources/js/pages/emar/connected/_shared.tsx:104-143,176-183
- **Scenario:** A coordinator finds 'Connected care' in command search under 'Orders & reviews', but the Orders and Reviews rails show only Orders / To check / Covert / Reconciliation / Medication reviews. The page's rail is Prescriber access / Requests / Provider handovers, the last crumb has href '#', and 'Home' goes to /my-day. The rail tabs are labelled 'Orders' and 'To check', but search and breadcrumbs say 'Prescriptions' and 'Orders to check'. Covert reads 'Covert giving' on Orders and 'Covert' on Reviews. Moving from Reviews to Orders drops the site_id and client_id scope.
- **Fix:** Mount EmarHubRail on Orders, Reviews and ConnectedCare (it supports query-selected views and carries scope), or derive both hand-written rails from visibleEmarViews(hub). Use useEmarBreadcrumbs() in ConnectedHeader. Whether Connected care is visible at all waits on the §2A.6 approval.

### EA-135 · Stopping an order through a reconciliation (or a legacy prescriber cease) records no 'stopped' action, which leaves order-check and second-check follow-ups open with no way to close them
- **Area:** Orders reconciliation (P04) / follow-ups (P08a)
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/MedicationReconciliationWorkflow.php:190-195, app/Services/Medication/MedicationOrderWorkflow.php:432-440, app/Http/Controllers/Emar/MedicationOrdersController.php:255-260, app/Services/Medication/MedicationOrderWorkflow.php:192, app/Services/Medication/MedicationOrderWorkflow.php:253, app/Services/Medication/Followups/MedicationFollowupService.php:647-650, app/Http/Controllers/Emar/EmarController.php:3937-3949
- **Scenario:** 1. A lead checks a dose change alone overnight (checked_alone), which opens a 'second-check' follow-up due tomorrow. Alternatively, a change is still waiting for its order check. 2. Next day, at a hospital-discharge reconciliation, the medicine is marked 'stop' and the reconciliation is applied. 3. The order is ceased through MedicationOrderLifecycleService directly, so no MedicationOrderAction 'stopped' is written. 4. finishActions never completes 'second-check:{rev}' or 'order-check:{rev}'. 5. Opening the follow-up and trying the check fails with 'This order is stopped', and sign-off is refused with 'Finish this work in its source record'. 6. The follow-up stays overdue in Follow-ups and All Tasks indefinitely, and the P09 chain has no order.stopped event for this stop.
- **Fix:** After discontinue in the reconciliation apply 'stop' branch and in EmarController's prescriber cease, call $this->orders->action($actor, $ended, 'stopped', ['version' => ..., 'reason' => ..., 'reconciliation_id' => ...]) so finishActions completes the open follow-ups and emits order.stopped. Ideally move this into MedicationOrderLifecycleService::discontinue itself.

### EA-136 · A late pharmacy acceptance overwrites manually recorded contact evidence after staff confirmed the order was not received
- **Area:** Pharmacy connections
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:227-231, app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:266-298, app/Services/Medication/PharmacyConnect/PharmacyDispatchGuard.php:30-35, app/Http/Controllers/Emar/MedicationStockController.php:206-212
- **Scenario:** A dispatch goes 'unknown'. Staff phone the pharmacy, are told it never arrived, and record 'confirmed not received', so the dispatch becomes failed. They then record a phone order (method phone, reference, recorded_by themselves). An hour later the pharmacy system processes the original and sends a signed 'accepted'. The order's communication method, reference and recorded_by are silently replaced with secure_message, the dispatch uuid and the original requester. The dispatch flips to 'accepted'. The pharmacy now holds two orders and nothing tells staff.
- **Fix:** If the dispatch was resolved as not received, or the order already has manual contact evidence, store the acknowledgement as conflicting evidence (applied=false, code 'contradicts_recorded_check'). Never overwrite communication_*, and raise a follow-up to check for duplicate supply.

### EA-137 · Signed pharmacy acknowledgements ignore the global kill switch and reveal setup state before authentication
- **Area:** Pharmacy connections (public API)
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** routes/emar-pharmacy-connect.php:26-27, app/Http/Controllers/Emar/MedicationPharmacyConnectionController.php:75-87, app/Services/Medication/PharmacyConnect/PharmacyPartnerRegistry.php:11-34, app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:92, app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:244-306, app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:330, config/emar-pharmacy-connect.php:9
- **Scenario:** (a) The bridge has been live. After a partner incident the owner sets 'enabled' => false but keeps the partner block, which is still needed to verify acknowledgements. A signed 'accepted' still arrives and moves draft or submitted supply orders to 'confirmed'. (b) Any unauthenticated caller can POST to /api/emar/pharmacy-connections/{n}/acknowledgments before any signature check and learn from the response code whether connection n exists (404) and whether a partner is configured (422 partner_not_ready versus 401).
- **Fix:** In acknowledge(), keep the evidence but never change orders when !$this->partners->enabled(); record code 'connection_disabled'. Return one identical 401 for unknown connection, partner-not-ready and bad signature.

### EA-138 · Pharmacy rejections, unknown sends and uncertain backup emails are not surfaced to anyone
- **Area:** Pharmacy connections / Protected backups
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:296-302, app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:425-444, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:287-290, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:428-441
- **Scenario:** A pharmacy sends a signed 'rejected' for a supply order. The dispatch state changes and an event is appended, but no alert, task or list badge appears; the only place to see it is inside that order's dialog. The same happens when recover() moves a dispatch to 'unknown' and when a backup send is marked 'uncertain'. Stock can run out before anyone notices.
- **Fix:** Add alert sources for dispatch rejected/unknown and backup failed/uncertain, routed to stock.update and backups.manage holders for the house. Show the dispatch state on the Pharmacy orders list rows.

### EA-139 · The second-person 'Were you there?' request is bell-only, with no push, although the colleague has 30 minutes to answer before the dose is flagged
- **Area:** PIN-2 forgotten-PIN fallback
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Notifications/MedicationSecondPersonConfirmationNotification.php:12-25, app/Services/Medication/ForgottenWitnessPinService.php:117-137, app/Services/Medication/ForgottenWitnessPinService.php:224-263, app/Notifications/WitnessPinReminderNotification.php:30-33, routes/console.php:993-994
- **Scenario:** A worker records a dose using the forgotten-PIN fallback and names colleague C, who was at the house but is not logged in, or is logged in on another device. C has push turned on. C gets only a database bell row and never sees it in time. emar:expire-second-person-confirmations expires it after 30 minutes and the dose is flagged 'Second person did not confirm within 30 minutes — check this dose'. That review work is avoidable.
- **Fix:** Add PushChannel to MedicationSecondPersonConfirmationNotification::via() for users with enabled pushSubscriptions, following WitnessPinReminderNotification's pushOn pattern. Use the same PHI-free text, with a toPush() whose title is 'Were you there?' and whose url is /medication-followups?open={id}.

### EA-140 · Backup emails and files do not say which house they belong to
- **Area:** Protected backups
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/BackupDelivery/BackupMailTransport.php:25-26, app/Http/Controllers/Emar/MedicationBackupDeliveryController.php:126
- **Scenario:** A coordinator approved for three houses gets three emails a day, all titled 'Protected chart backup', each with an attachment named chart-backup-2026-10-09.pdf. In an outage they cannot tell which file is which house, and each file needs its own password.
- **Fix:** Put the house name and a short delivery reference, matching the password screen, in the subject and filename. Do not include resident names.

### EA-141 · Protected backups cannot be opened during an outage, and recipients without 2FA can never open them
- **Area:** Protected backups
- **Audit dimension:** connected-care
- **Status:** verified (claimed P1)
- **Refs:** app/Services/Medication/BackupDelivery/BackupMailTransport.php:25-26, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:134, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:151, app/Http/Controllers/Emar/MedicationBackupDeliveryController.php:129-153, app/Services/Medication/BackupDelivery/BackupDeliveryAccess.php:55-62, docs/emar-connected-care/ACCEPTANCE.md (Deployment step 6)
- **Scenario:** The app is down at 07:30, which is exactly when the backup is needed. A house lead opens today's emailed 'Protected chart backup'. The email says to get the password 'through the approved application', which is unreachable. Every preparation has a new random password, so a password retrieved for an earlier day does not open today's file. Separately, a recipient approved without authenticator 2FA can never retrieve any password, even while the app is up.
- **Fix:** Hold this unapproved surface until Stephan chooses an outage key model, for example a per-house recipient key retrieved once and kept offline, or a sealed printed password procedure. At minimum: require 2FA at recipient approval, show 'can open: yes/no' per recipient, and create a daily 'retrieve today's password' task for the duty lead.

### EA-142 · Scheduled backups run as whoever last saved the schedule, fail silently, and re-render every minute after a send failure
- **Area:** Protected backups
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/BackupDelivery/BackupDeliveryService.php:46, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:106, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:217-218, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:329-363, app/Console/Commands/DeliverMedicationChartBackups.php:14-20, routes/console.php:999
- **Scenario:** (a) The manager who saved House A's schedule leaves or loses medications.backups.manage. From then on every scheduled run fails with a 403 inside manager(). It is counted only as failed++ with no alert, so the house silently stops getting backups. (b) Sending is enabled while the Microsoft mailbox still needs reconnecting, which is an open rollout item. BackupEmailSender::prepare() throws MailNotSubmitted and the state becomes 'failed'. The next minute, prepareArtifact rebuilds the whole-house pack, renders it, runs qpdf three times with a new password, and retries the send. This repeats every minute for the rest of the NZ day.
- **Fix:** Store an explicit scheduling authority and warn when it lapses. Raise a medication alert to backups.manage holders on the first failed or uncertain result per house and day. Do not automatically re-prepare after a failed send on the same day, or allow it at most hourly.
- **Collision:** routes/console.php (Low) only if the cadence changes; otherwise none

### EA-143 · A protected backup schedule can be switched on when encryption or sending is not configured. The every-minute job then fails silently, and the page keeps saying 'Daily schedule enabled'.
- **Area:** Protected backups (unapproved surface)
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/BackupDelivery/BackupDeliveryService.php:37-49, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:84, app/Services/Medication/BackupDelivery/BackupDeliveryService.php:340-358, routes/console.php:999, app/Console/Commands/DeliverMedicationChartBackups.php:14-20, resources/js/pages/emar/backups/index.tsx:173-178, resources/js/pages/emar/backups/index.tsx:212-213
- **Scenario:** 1. A manager enables a 6 pm daily backup for a house on the test server, where `EMAR_BACKUP_QPDF_PATH` is unset. 2. Saving succeeds and the row reads 'Daily schedule enabled'. 3. From 6 pm, `medications:chart-backups` runs every minute. `prepareArtifact` aborts 503 before any delivery row exists, and the catch only increments a `failed` counter. 4. No delivery, failure code or 'last attempt failed' is ever shown. The manager believes daily downtime charts exist when none do. 5. With qpdf set but `send_enabled` false, artifacts are prepared but never emailed, and the row text is unchanged.
- **Fix:** - Refuse `enabled=true` while encryption is not ready, or show the row as 'Not running — encryption not configured'. - Record `last_attempt_at`/`last_failure_code` per schedule in `dispatchDue` and surface them on the row.

### EA-144 · 'Protected backups' is offered to report exporters while the feature is unconfigured and they cannot use it
- **Area:** Protected backups / navigation
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:544-549, resources/js/pages/emar/connected/_entry-points.tsx:116-121, app/Http/Controllers/Emar/MedicationBackupDeliveryController.php:36, app/Http/Controllers/Emar/MedicationBackupDeliveryController.php:48, app/Services/Medication/BackupDelivery/BackupDeliveryAccess.php:33, config/emar-catalogue-backups.php:5-7
- **Scenario:** provider_manager, coordinator and finance (all with reports.view and reports.export) see 'Protected backups' in Reports & audit, and in the Connected services menu on MAR, Orders, Stock and Reports. The page shows 'Configure a reviewed qpdf executable for AES-256 backups.' and empty tables. Finance can open the page even though every backup action rejects finance-only users.
- **Fix:** Show the entry only to backupsManage holders or current approved recipients, and only when encryption is ready (pass readiness as a page prop). Apply the finance exclusion in index().

### EA-145 · Migration-only grants leave admin without medications.errors.manage on deployed servers. A seeded environment differs
- **Area:** RBAC / rollout
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** database/migrations/2026_10_03_160000_add_medication_error_workflow.php:45-54, database/seeders/RbacSeeder.php:649-653, app/Http/Controllers/Emar/MedicationErrorController.php:110, app/Http/Controllers/Emar/MedicationErrorController.php:234, app/Http/Controllers/Emar/MedicationErrorController.php:368, routes/emar.php:626-632
- **Scenario:** On the .com test server, where deploys run migrations but skip seeders, the verification account admin@demo.test opens /emar/errors. They can read everything through audit.view, but triage, add-action and close routes return 403, and can.manage and can_close are false. On any environment where RbacSeeder has run, admin can triage. Behaviour differs between environments, and the walkthrough on .com will report the error workflow as broken.
- **Fix:** Add an additive grant migration (timestamp after 2026_10_08_235100) that inserts medications.errors.manage for the admin role with insertOrIgnore. Do not edit the seeder.

### EA-146 · Re-running RbacSeeder (demo reseed) strips medications.emergency_policy.manage from provider_manager
- **Area:** RBAC / Settings › Emergency access
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** database/migrations/2026_10_03_211100_grant_emergency_medication_policy_permission.php:14-19, database/seeders/RbacSeeder.php:656-662, database/seeders/RbacSeeder.php:665-689, app/Http/Controllers/Emar/MedicationSettingsController.php:254-257
- **Scenario:** The pending 'Demo reseed' rollout action (§2E) runs RbacSeeder on .com. Afterwards provider managers can no longer change the emergency medication access policy in Settings (emergencyPolicyAccess=false), although they could before the reseed. Every feature test that seeds RbacSeeder also runs without that grant, so the test suite does not exercise the migrated state.
- **Fix:** Add 'medications.emergency_policy.manage' to the seeder's permission catalogue and to the provider_manager list, or restore it with syncWithoutDetaching after the role syncs, as is already done for breakglass.end.
- **Collision:** database/seeders/RbacSeeder.php is HIGH collision with the H&S lane (provider_manager block ~l.686-693). Agree order, or use an additive syncWithoutDetaching block at the end of run().

### EA-147 · After midnight a refusal can't be re-offered, even though the follow-up time the worker chose falls after midnight
- **Area:** Record dose dialog › refusal follow-up and re-offer
- **Audit dimension:** workflows-frontline
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/Recording/RecordingContractEnforcer.php:344-350, app/Services/Medication/Recording/DoseRecordingRequirements.php:860-864, app/Services/EnhancedMarService.php:1392-1402, resources/js/components/emar/record-dose/record-dose-dialog.tsx:943-947, resources/js/components/emar/record-dose/record-dose-dialog.tsx:848-858
- **Scenario:** At 22:05 a resident refuses the 22:00 dose. The worker sets 'Follow up by 00:30', which the dialog accepts. At 00:20 the resident agrees, but 'Record re-offer' is disabled ('This refusal can't be offered again'). A server attempt fails with 'This refusal can't be re-offered any more: … or it was on another day.' Recording 'given' is a duplicate, so taking the dose can't be documented without a correction.
- **Fix:** Allow a re-offer while the refusal's follow-up is open, until the dose's next scheduled time, regardless of the NZ date. Or limit 'Follow up by' to the same NZ day and say so in the copy.

### EA-148 · An expired session during recording shows raw 'CSRF token mismatch.' or 'Unauthenticated.' with a retry that cannot work. MAR sections tell the user the record 'is no longer available'.
- **Area:** Recording dialog / MAR record sections
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/emar/record-dose/record-dose-dialog.tsx:1459-1502, resources/js/components/emar/record-dose/use-dose-requirements.ts:40-44, resources/js/components/emar/record/use-record-json.ts:39-50, resources/js/components/emar/record/reading.tsx:76-91, bootstrap/app.php:90-158
- **Scenario:** 1. A house lead leaves a person's MAR open past the session lifetime. The MAR page does not poll, unlike Meds today, which refreshes every 60s. 2. They record a dose. The POST returns 419 or 401. The dialog clears the recovery and shows 'Not recorded — this dose was not saved. CSRF token mismatch. … Your draft is kept.', and 'Record outcome' fails the same way every time. 3. Reloading or signing in loses the draft. 4. The chart sections return 401, which `useRecordJson` maps to 'forbidden', shown as 'This medication record is no longer available. Access follows the person’s current house and your medication permissions.' The user thinks their access was removed.
- **Fix:** - Map 401/419 in `handleError`, `useDoseRequirements` and `useRecordJson` to 'Your session ended — sign in again in a new tab, then try again; this entry stays here'. - Keep the form, and do not label it 'forbidden'. - Optionally give `/meds/today/record` responses a friendly 419 JSON message.
- **Collision:** bootstrap/app.php (devices lane §3 #18) only if 419 is mapped globally; client-side mapping avoids it

### EA-149 · When a dose is never sent (offline before sending, no device storage, or no confirmed actor), the dialog still says 'This dose may already have been saved' and creates a recovery entry.
- **Area:** Recording dialog offline states
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-offline.ts:313-325, resources/js/lib/offline-queue.ts:887-910, resources/js/lib/offline-queue.ts:129-136, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1274, resources/js/components/emar/record-dose/record-dose-dialog.tsx:1327-1333, resources/js/components/emar/record-dose/record-dose-dialog.tsx:2316-2347
- **Scenario:** 1. A worker records a witnessed controlled dose while the laptop's Wi-Fi is down. `navigator.onLine` is false, so nothing is sent. 2. The toast says 'Stay on this screen and reconnect. Check the chart before trying again…', and the dialog switches to 'Not confirmed — check before trying again. This dose may already have been saved… Do not start another record for this dose.' 3. The witness must re-enter their PIN. 4. If the worker closes the dialog, the recovery is kept, and reopening shows 'Check an unconfirmed dose: The server has not confirmed the original request. This does not mean it failed.' for a request the server never received. 5. The same contradiction happens for `storage_unavailable` (the toast says 'This action was not saved because secure offline storage is unavailable').
- **Fix:** - Return a distinct `not_sent` result from the pre-send paths. - In the dialog, clear the recovery and show 'Not sent — nothing was saved. Reconnect and record again', keeping the form. - Reserve `uncertain` for requests that actually left the browser.

### EA-150 · Audit export zip (/audit-exports/clients/{client}) dumps the person's raw medication audit rows with no MedicationExportGuard purpose step or export audit
- **Area:** Reports › audit exports
- **Audit dimension:** privacy
- **Status:** confirm with a failing test first
- **Refs:** routes/reports.php:54-61, app/Http/Controllers/AuditExportController.php:80-122, app/Http/Controllers/AuditExportController.php:163-173, app/Http/Middleware/MedicationExportGuard.php:19-62, app/Services/Medication/MedicationProfileAuditPrivacy.php:34-60
- **Scenario:** A provider manager downloads /audit-exports/clients/{id}. audit_logs.json holds up to 5,000 raw AuditLog rows for the person, including medications.administration.record and medications.controlled.entry.record meta (status, witnessed_by, on_hand_after, offline device ids, IP and user agent). It is produced without the purpose prompt, without a MedicationExportAudit event, without the medications.audit.export check, and without MedicationProfileAuditPrivacy. A custom role holding the four route keys but no controlled view gets the controlled rows too.
- **Fix:** Exclude medication audit families from the zip (reuse ModuleReportController::excludeMedicationAuditFamilies) and point to the eMAR audit export. Alternatively, apply MedicationProfileAuditPrivacy and require MedicationReportAccess::canExport($actor,'audit') plus the purpose step and MedicationExportAudit::record.

### EA-151 · Orphan GuidedRoundController::administer hard-codes override_window=true; the round window it claims to rely on is never enforced
- **Area:** Rounds
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** routes/emar.php:265-266, app/Http/Controllers/Emar/GuidedRoundController.php:113-355, app/Http/Controllers/Emar/GuidedRoundController.php:214-219, app/Models/MedicationRound.php:126-136, resources/js/pages/emar/components/guided-round-dialog.tsx:1, resources/js/pages/emar/components/guided-round-dialog.tsx:504, resources/js/lib/emar-offline.ts:120
- **Scenario:** At 16:00 a support worker assigned to the 08:00 round sends POST /emar/rounds/301/guided/items/55 with {status:'given', scheduled_for:'…T08:00'}. The dose saves as given with no late reason. The code comment says 'the round's own window_minutes is the authoritative schedule', but no code checks window_minutes on recording; it is used only for display and generation. The record also gets no P09 dose event. The guided-round UI itself records through RecordDoseDialog → /meds/today/record, which rejects the same late submission without a reason.
- **Fix:** Delete route meds.round.administer and GuidedRoundController::administer; keep show, start and complete. First drain or convert any persisted 'round_admin' offline-queue entries (emar-offline.ts:120).

### EA-152 · /emar/handovers is the old Operations card/week page, not the approved P08a Handovers register
- **Area:** Safety & oversight › Handovers (P08a)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Handovers.tsx:1, resources/js/pages/emar/Handovers.tsx:195, resources/js/pages/emar/Handovers.tsx:543, resources/js/pages/emar/Handovers.tsx:615-686, resources/js/pages/emar/Handovers.tsx:728, resources/js/pages/operations/handovers/components/handover-wizard.tsx:999-1013
- **Scenario:** A house lead opens Safety & oversight › Handovers to check whether the incoming worker has read the medication handover. They get a weekly card board with Drafts / Submitted / Open incoming tabs, a red 'open incoming shift — needs cover' rostering alert, and a 'New handover' wizard. That wizard offers 'Add a client' to anyone who can create a handover. The approved 'Shift handovers, last 24 hours' table (the medication part of each handover, with Remind and Open in Operations actions) does not exist.
- **Fix:** Build the P08a register view (EntityTable with kebab and right-click, honest states), leaving creation and editing to Operations › Handovers. Remove AddClientDialog from the eMAR page. Drop the file-level lint disable. Use EmptyState and band tokens.
- **Collision:** handover-wizard.tsx is in the workforce/shift-notes/handover seam (map §3, Medium). Change only pages/emar/Handovers.tsx, or agree the order with that lane first.

### EA-153 · Admin role lacks medications.errors.manage on any environment upgraded by migrations (the grant migration leaves out admin)
- **Area:** Safety & oversight › Medication errors (P08b)
- **Audit dimension:** rbac
- **Status:** verified (claimed P1)
- **Refs:** database/migrations/2026_10_03_160000_add_medication_error_workflow.php:45-54, database/seeders/RbacSeeder.php:648-652, routes/emar.php:613-650, app/Http/Controllers/Emar/MedicationErrorController.php:109-111, app/Http/Controllers/Emar/MedicationErrorController.php:230-234
- **Scenario:** On the .com test server, deploys run migrations but not seeders. The demo admin (admin@demo.test, role admin) opens a medication error to verify P08b. Triage, review, resolve, close, note, action and reopen all return 403, and can_close/can_reopen are false. Admin also never appears in the owner picker, because the staff picker filters on errors.manage. Admins on a fresh seed do have it, so local and test environments behave differently.
- **Fix:** Add a migration (after 2026_10_08_235100) that inserts the admin role_permission row for medications.errors.manage with insertOrIgnore, first-grant only, so a deliberately revoked grant stays revoked. Review the other grant migrations against the seeder's admin rule.

### EA-154 · Medication errors header lacks the approved 'Open with harm', 'Actions due' and 'Incidents to close' meters
- **Area:** Safety & oversight › Medication errors (P08b)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/MedicationErrors.tsx:230-292, resources/js/pages/emar/MedicationErrors.tsx:142-163
- **Scenario:** A provider manager opens Medication errors. An error that reached the person with moderate harm is still investigating, and two corrective actions are overdue. The header shows To triage, Investigating, 'Actions & close', Closed and two 90-day donuts. Nothing flags harm, overdue actions, or incidents ready for them to close.
- **Fix:** Return open_with_harm (plus a moderate-or-worse count), actions_due and actions_overdue, and incidents_ready from the controller. Render the approved six meters with their tones and targets, and move the 90-day split to the Trends view.

### EA-155 · Overview recolours default Buttons with bg-status-* and text-white, and hand-rolls status pills and headline sizes
- **Area:** Safety & oversight › Overview (Index.tsx)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Index.tsx:299-311, resources/js/pages/emar/Index.tsx:937, resources/js/pages/emar/Index.tsx:956, resources/js/pages/emar/Index.tsx:975, resources/js/pages/emar/Index.tsx:994, resources/js/pages/emar/Index.tsx:1013, resources/js/pages/emar/Index.tsx:866-867, resources/js/pages/emar/Index.tsx:1356-1380, resources/js/pages/emar/Index.tsx:1066, resources/js/pages/emar/Index.tsx:1655
- **Scenario:** In the Action centre, a critical 'Record now' button and a warning 'Review' button both render as the purple soft-primary gradient: the unlayered .btn-soft-primary paints over bg-status-critical and bg-status-warning. Severity is lost, and where the fill does apply, white text on the dark theme's status fills fails AA. The '{n} shown' count pill is always critical red, even at 0.
- **Fix:** Use variant='destructive' for critical actions and variant='outline' for warning and info, conveying severity through the row's StatusBadge and left accent. Replace the spans with StatusBadge (variant from severity), and the 3xl numbers with ops-stat-card or the typography helpers.

### EA-156 · Staff eligibility has no 'Can witness' meter, witness view or Witness column (deferred 'until P07b', which has since shipped)
- **Area:** Safety & oversight › Staff eligibility (P11 v5)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/StaffEligibility.tsx:7-9, resources/js/pages/emar/StaffEligibility.tsx:341-424
- **Scenario:** Before a night shift, a house lead needs to know who can witness controlled drugs. Eligibility shows five meters about giving competency and no witness count. The register has no 'Can witness' column explaining 'Not a witness — controlled drugs area not passed · no witness PIN set', so the lead finds out at the cabinet.
- **Fix:** Compute witness eligibility server-side with the same rule P07b enforces (controlled area passed, witness flag, not restricted, PIN set). Add the sixth meter, the Witness column with reasons, and the witness view. Align meter labels with the mockup.

### EA-157 · Competency exemptions use ['sites.viewAll'] instead of SITE_BYPASS_PERMISSIONS, so the clinical lead sees every house but can exempt only at their own
- **Area:** Safety & oversight › Staff eligibility (P11)
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/StaffEligibilityController.php:61-72, app/Http/Controllers/Emar/StaffEligibilityController.php:86-88, app/Http/Controllers/Emar/CompetencyExemptionController.php:50-51, app/Http/Controllers/Emar/CompetencyExemptionController.php:107, app/Http/Controllers/Emar/CompetencyExemptionController.php:115-118, app/Services/Medication/MedicationCompetencyExemptionService.php:133-142, app/Services/Medication/MedicationGovernanceScopeService.php:50, database/seeders/RbacSeeder.php:1019-1034, database/migrations/2026_08_13_000003_add_medication_competency_exemption_permission.php:20-22
- **Scenario:** Hana, the clinical lead (competency.exempt and clinical.accessAllSites, no sites.viewAll), has her HR profile at Head Office. Staff eligibility lists unassessed workers at every house, because readerSiteIds uses SITE_BYPASS_PERMISSIONS. The 'Exempt' and 'End' actions do not appear for any house, and a direct POST to /emar/competency/exemptions returns 404. A new worker rostered tonight cannot get the time-limited exemption from the designated approver unless a provider manager is reachable.
- **Fix:** Use MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS in all three places, or record an explicit decision that exemption authority requires sites.viewAll and hide the clinical lead's out-of-scope rows' exempt affordance with an explanation.

### EA-158 · eMAR Overview syringe-driver card shows the start time and 'Check due' time in UTC
- **Area:** Safety & oversight / Overview (/emar)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/MedicationOverviewService.php:862-895, resources/js/pages/emar/Index.tsx:1474-1505
- **Scenario:** A palliative syringe driver is started at 12:00 NZDT on 9 Oct (23:00 UTC 8 Oct), and its last 4-hourly check was at 12:00. At 16:30 the card turns red with 'Check due 03:00' and 'started 8 Oct 23:00'. The correct values are 16:00 and 9 Oct 12:00. A lead deciding whether a 24-hour driver needs replacing reads a start time 13 hours earlier than real. The P02 record clinical tab shows the correct 12:00, so the two surfaces disagree.
- **Fix:** Convert with ->timezone($this->schedule->workerTimezone()) before format, or send ISO strings and format them with formatDateTime/formatTime in Index.tsx.

### EA-159 · Emergency access is hidden from audit.view reviewers, contrary to the approved P00 and P10 maps
- **Area:** Safety & oversight / P10
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:497-505, resources/js/lib/emar-navigation.ts:129-144, routes/emar.php:541-543, app/Http/Controllers/EmergencyAccessController.php:21-41, database/seeders/RbacSeeder.php:744-748,890-893,1031-1032, frozen/P00/mockup.js:86, frozen/P10/assets/index-BtJlEoMB.js:~417960 (zu = breakglass \|\| audit.view), resources/js/lib/emar-navigation.test.ts:175-179
- **Scenario:** A coordinator, clinical lead or auditor (audit.view, no breakglass) must review an ended break-glass grant. Safety & oversight has no 'Emergency access' entry and search can't find it. The review queue (EmergencyAccessController's $queue) can only be reached through a task, notification or compliance-KPI link, so oversight of who opened health records under emergency access depends on a task row.
- **Fix:** Set the emergency view's visible to any(breakGlass, auditView). The emergency-access review bypass scope (§2A.15) is still a separate decision. Extend the nav test to the view+breakGlass and view+auditView personas.

### EA-160 · Medication competency acknowledgement checks the HR profile against the UTC date, so a first-day starter gets a 404 until 13:00 NZDT and cannot record 'given' doses
- **Area:** Safety & oversight / Staff eligibility (competency)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/EmarController.php:5349-5400, resources/js/components/meds/pending-assessment.tsx:47, routes/emar.php:302, app/Services/Medication/MedicationAdministratorCompetencyPolicy.php:184-190
- **Scenario:** A new support worker's HR profile starts on 10 Oct. Their assessor signs the medication competency the day before. At 07:30 NZDT on 10 Oct (18:30 UTC 9 Oct) they tap 'Acknowledge' on the pending-assessment card in Meds today. today() is 9 Oct, so `start_date <= today()` fails, $currentProfile is null, and the server returns 404. Because a bare 'passed' assessment without staff_acknowledged_at counts as unassessed, they cannot record any dose as given on the 8 am round. It starts working at 13:00 NZDT. Conversely, a worker whose profile ended on 9 Oct can still acknowledge until 12:59 NZDT on 10 Oct.
- **Fix:** Use WorkerClock::today()->toDateString() for both comparisons, as MedicationRoundGenerationService::profileIsCurrent does with the NZ date.

### EA-161 · Safety-critical scheduled jobs use withoutOverlapping() with Laravel's default 24 h mutex, and one failing alert source aborts the rest
- **Area:** Scheduler / alerts
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** routes/console.php:274-279, routes/console.php:714-729, routes/console.php:988-999, app/Console/Commands/SendMedicationAlerts.php:17-31, config/cache.php:18
- **Scenario:** A deploy restart or out-of-memory kill interrupts a running emar:send-alerts. The cache lock row in the 'database' store survives for its default 1440 minutes, so the scheduler skips emar:send-alerts for up to 24 h. No overdue-dose Control Room alerts, low-stock or follow-up alerts fire, and nothing reports it. The same applies to emar:alert-follow-ups, emar:escalate-overdue-cd-checks and the every-minute expiry and delivery jobs. Separately, an exception in the overdue sweep for one person aborts lowStock, renewals, refusalClusters and overdueFollowUps for that run.
- **Fix:** Use withoutOverlapping(10), or a value just above the expected run time, for the eMAR minute and 15-minute jobs. Wrap each alert source in try/catch with report() so one failure doesn't block the others. Add these jobs to a scheduler heartbeat or health check so a stalled overdue-alert job is visible.
- **Collision:** routes/console.php (Low: both lanes append at end of file)

### EA-162 · medications.emergency_policy.manage exists only in a migration; any RbacSeeder run removes provider_manager's grant
- **Area:** Settings › Alerts & access › Emergency access policy (P10/P11)
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Refs:** database/migrations/2026_10_03_211100_grant_emergency_medication_policy_permission.php:15-19, database/seeders/RbacSeeder.php:299-328, database/seeders/RbacSeeder.php:656-662, database/seeders/RbacSeeder.php:665-689, app/Http/Controllers/Emar/MedicationSettingsController.php:238-257, app/Services/Medication/Settings/EmergencyAccessPolicySettings.php:10, resources/js/pages/emar/Settings.tsx:543
- **Scenario:** Map §2E lists a demo reseed as pending. When `db:seed --class=RbacSeeder` runs, or on any `migrate:fresh --seed`, the provider manager (Rangi, 'holds emergency access' and 'ea.policy' in the P10 mockup) loses medications.emergency_policy.manage. Settings › Emergency access policy becomes read-only for them ('You can read this policy…'), and a save returns 403 even though they hold settings.manage. Only admin can then change the policy.
- **Fix:** Add 'medications.emergency_policy.manage' to RbacSeeder's permission definitions, and grant it to provider_manager in the post-sync syncWithoutDetaching block (as breakglass.end is at l.1064-1066), so the seeder matches migration 211100.
- **Collision:** database/seeders/RbacSeeder.php (High #2, H&S lane)

### EA-163 · Settings opens on an unapproved 'Connected services' view, placed first in the rail and rendered as static link cards
- **Area:** Settings (P11)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/settings/_nav.ts:29, resources/js/pages/emar/settings/_nav.ts:131-138, resources/js/pages/emar/Settings.tsx:346, resources/js/pages/emar/Settings.tsx:894-895, resources/js/pages/emar/connected/_entry-points.tsx:49, resources/js/pages/emar/connected/_entry-points.tsx:61-78
- **Scenario:** A provider manager who holds any connection key (for example catalogue.manage, after a reseed) opens /emar/settings with no hash. parseHash falls back to views[0], so they land on 'Connected services' (a 2-column grid of four link cards, one of them 'Medicine picture library') instead of the approved Medication rules Overview.
- **Fix:** Move connections after history (or hide it pending Stephan's approval, map §2A.6). If kept, render it as an Overview of ReviewCards with 'Review … ↗' links, per the Fleet settings pattern, using gap-5.

### EA-164 · Settings: the unapproved 'Connected services' view is first, so it becomes the default landing, and it duplicates /emar/connections
- **Area:** Settings / Connected services
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/settings/_nav.ts:25-29, resources/js/pages/emar/settings/_nav.ts:110-139, resources/js/pages/emar/Settings.tsx:297-304,346,893-895, resources/js/lib/emar-navigation.ts:566-574,614-629, resources/js/pages/emar/ConnectedServices.tsx:6-25, resources/js/pages/emar/connected/_entry-points.tsx:32-59, routes/emar-pharmacy-connect.php:7-9, frozen/P11/assets/index-BfS_Cqen.js:~638964 (rail: Medication rules, Rounds & timing, Staff & PINs, Alerts & access, Change history)
- **Scenario:** A clinical lead is explicitly given medications.external.manage. They click Medication › Settings, labelled 'Medication rules' with href /emar/settings, and the page opens on 'Connected services' cards instead of the rules. Search entry 'Connected services' goes to /emar/connections, a separate page showing the same cards with no Settings rail and a Home(/my-day) › Medication(/meds/today) trail. A coordinator with only orders.manage can open /emar/connections (the route allows it) and sees a 'Connected care' card, but has no nav entry for it.
- **Fix:** Move `connections` to the end of SET_VIEWS, or drop it and keep the single /emar/connections page. Pick one destination and make the other an alias, so the EMAR_HUBS view points where the Settings rail does. Use one gate everywhere. These surfaces still need Stephan's approve-or-hide decision (§2A.6).

### EA-165 · Cancelling an in-progress shift flags its doses for review, but nothing ever surfaces the flag
- **Area:** Shift cancellation
- **Audit dimension:** workflows-frontline
- **Status:** verified (claimed P1)
- **Refs:** app/Services/ShiftCancellationService.php:101-113, app/Services/ShiftCancellationService.php:187-199, app/Services/Medication/Followups/MedicationFollowupService.php:180-187, app/Models/ClientMedicationAdministration.php:27-49, app/Models/Concerns/AuditableChanges.php:24-50
- **Scenario:** A coordinator cancels a shift that is in progress, after the worker has already recorded 08:00 doses on it. The doses get review_required = true and their notes are edited. No follow-up, task or lead work item is created, because the follow-up sync needs a review_reason_key and saveQuietly skips model events. The note change to the clinical record has no per-record audit entry, and the shift's actual times are wiped.
- **Fix:** Set a review_reason_key (e.g. 'shift_cancelled'), save through the model inside the medication lock order, and call MedicationFollowupService::syncAdministration. Don't rewrite clinical notes; keep the reason in review_reason only.
- **Collision:** ShiftCancellationService.php (medium, l.173-232)

### EA-166 · Shift medication card and /api/medications/shifts/{id}/medication-summary show the person's MAR without the per-person rule; only the links are gated
- **Area:** Shifts › Medications card
- **Audit dimension:** privacy
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/ShiftController.php:313-318, app/Http/Controllers/ShiftController.php:403-447, app/Http/Controllers/ShiftController.php:426-433, app/Http/Controllers/Api/MedicationsApiController.php:1758-1786, app/Services/Medication/MarLinkService.php:26-56
- **Scenario:** The same relief worker, outside a clocked-in covering shift, opens their shift's Medications tab, or calls GET /api/medications/shifts/{id}/medication-summary. They get the day's due doses with medicine names, the PRN list, allergies and recent administration history. mar_url and medical_url are null for them, because both are gated by viewMedications.
- **Fix:** Compute $canReadPerson = Gate::allows('viewMedications', $shift->client). Build $medicationSummary only when it is true; otherwise send a counts-only 'Open from Meds today' state. In getShiftSummary, call MedicationRecordAccess::assertReadable($user, $shift->client) (404).
- **Collision:** ShiftController.php: workforce lanes (§3 high #5; eMAR shift-card fixes at l.313-318 and 398-472 live here). MedicationsApiController: none.

### EA-167 · Shift cancellation flags recorded doses with saveQuietly: no follow-up, no P09 event, lock order skipped, and existing review evidence overwritten
- **Area:** Shifts / append-only medication records
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** app/Services/ShiftCancellationService.php:173-233, app/Models/ClientMedicationAdministration.php:27-50, app/Services/Medication/Followups/MedicationFollowupService.php:180-187, app/Services/Medication/Recording/RecordingContractEnforcer.php:483-484
- **Scenario:** A coordinator cancels yesterday's shift after the worker recorded 6 doses on it. Each administration and its round get review_required=true, a new review_reason, review_flagged_by=coordinator and text appended to notes. No follow-up is created and nothing lists these rows, so the review never happens. A dose already flagged 'second person not confirmed' has its review_reason and review_flagged_by overwritten, which loses the original flagger. None of this reaches the hash-chained medication event log.
- **Fix:** Move the cascade into an eMAR service. It should lock Client, then order, then administration per row, set review_reason_key='shift_cancelled', and leave existing review_reason and flagged_by alone if already set (append a review event instead). It should use save() and not touch notes, and append a P09 'dose.review_flagged' event in the same transaction. MedicationFollowupService then creates the lead follow-up.
- **Collision:** app/Services/ShiftCancellationService.php (map §3 'eMAR findings in another lane's territory', coordinate with the workforce lane)

### EA-168 · Auditor and finance get a Meds today hub the approved P00 map excludes, and it becomes their Medication landing; finance can reach the MAR hub and a 403 from it
- **Area:** Sidebar persona walk
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:224-230, resources/js/lib/emar-navigation.ts:284-292, resources/js/lib/emar-navigation.ts:732-736,749-755, frozen/P00/mockup.js:40, app/Http/Controllers/Emar/WorkerMedsController.php:233,268-271,1107-1120, resources/js/pages/meds/today/index.tsx:863-884, app/Http/Controllers/Emar/MedicationRecordHubController.php:29-46, database/seeders/RbacSeeder.php:844-856,890-912
- **Scenario:** An auditor (view, audit.view, reports.view) or a finance user (view, reports.export, stock.update, reports.view) opens Medication. Meds today is their first hub, so the module landing and every 'Medication' crumb go to the frontline shift board. With no shift, that board falls back to everyone at their Sites. The finance user is deliberately kept out of 'MAR & medicines', yet the board's 'MAR charts' button opens /emar/mar listing people and medicines. Its 'Shift handover' button returns 403.
- **Fix:** Change the today hub visibility to any(administer, hasLeadCapability), per P00. In WorkerMedsController, gate view_emar on a MAR-hub-capable rule (exclude finance-only users, matching the nav at :288-292) and view_handovers on canAccessWorkflow.

### EA-169 · Bedroom door card reads legacy allergies for any sites.viewAny holder and returns 500 whenever the resident has an allergy
- **Area:** Sites › Rooms › Door card
- **Audit dimension:** privacy
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** routes/sites.php:639-641, app/Http/Controllers/Sites/SiteRoomController.php:365-385, resources/views/sites/rooms/door-card.blade.php:248-268, app/Models/ClientMedicalProfile.php:105-109, app/Services/Medication/ClientAllergyRecordService.php:200
- **Scenario:** A Maintenance Coordinator or H&S Officer (sites.viewAny, no client or medication access) prints /sites/{site}/rooms/{room}/door-card and gets the occupant's medical section (blood type, dietary needs, emergency contacts, next of kin). For any occupant with a recorded allergy the page fails with a 500, because Blade echoes the array-cast allergies. The emergency door card cannot be printed for exactly the residents whose allergies matter.
- **Fix:** Render allergies from ClientAllergyRecordService::summary() as a joined string. Gate the medical section on Gate viewMedications(client), or on a deliberate 'door card' disclosure setting, rather than site view alone.

### EA-170 · An offline non-controlled stock count (/emar/stock/adjust) replays as an absolute overwrite, with no expected-balance or stale check
- **Area:** Stock (legacy /emar/stock)
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/EmarController.php:7383-7469, resources/js/pages/emar/_stock-dialogs.tsx:1714, resources/js/pages/emar/_stock-dialogs.tsx:2109, app/Services/Medication/Controlled/ControlledRegisterService.php:225-234
- **Scenario:** At 08:00 a worker counts 30 paracetamol on the legacy Stock page while offline, and it is queued. Between 08:00 and 12:00 colleagues on another device record 4 doses online, so on_hand goes to 26. The count replays at 12:00 and sets on_hand=30. The 4 used tablets vanish from stock, the reorder point is missed, and no discrepancy is raised.
- **Fix:** Send expected_on_hand (the balance shown when the count was entered) and require it in adjustStock. If it no longer matches, return 409 'Stock changed since you counted — count again' (as checkSnapshot does). Or set allowQueueWhenOffline:false for counts.

### EA-171 · Overview 'Record stock', the stock nav and stock alerts still drive the legacy scalar stock page; pack-tracked medicines dead-end after a 3-step wizard
- **Area:** Stock (P06)
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Index.tsx:1580-1600, resources/js/pages/emar/Index.tsx:1629-1633, resources/js/pages/emar/Index.tsx:1873-1881, resources/js/pages/emar/components/stock-movement-modal.tsx:105-130, resources/js/pages/emar/_stock-dialogs.tsx:723-725, app/Models/ClientMedicationStock.php:25-46, app/Http/Controllers/Emar/EmarController.php:7251, app/Http/Controllers/Emar/EmarController.php:7463, app/Http/Controllers/Emar/EmarController.php:6823, resources/js/lib/emar-navigation.ts:392-412, app/Services/Medication/Alerts/MedicationAlertSources.php:738, resources/js/pages/meds/today/_rounds-stock.tsx:57, resources/js/pages/meds/today/_rounds-stock.tsx:250, config/medications.php:11
- **Scenario:** With MEDICATION_STOCK_LOTS_ENABLED=true (the default), a lead opens /emar and clicks 'Record stock' › Receive. They pick Paracetamol, which is pack-tracked since its counted opening, enter 56 tabs with batch and expiry, and save. /emar/stock/receive calls rejectScalarWrite, which returns 422 'This medicine uses pack records… Your entries have not been applied.' The modal's MedicationOption has no pack_workflow_url, unlike the legacy page's own dialog, which redirects (_stock-dialogs.tsx:723-725). For medicines not yet opened, the same modal still writes scalar on_hand, batch and expiry, so two stock models stay live side by side. None of these legacy writes reaches the P09 chain. Stock alerts (bell/email actionUrl), the Meds today stock tab and the nav's 'Stock' and 'Pharmacy orders' tabs all point to /emar/stock, not /emar/stock/packs. Stock alerts for controlled medicines also point there, although controlled stock is handled in the register.
- **Fix:** Point 'Record stock' and 'Manage stock & reorders' to /emar/stock/packs, or remove them. Change MedicationAlertSources actionUrl and the Meds today links to /emar/stock/packs?medication_id=…. Drop the nav 'Stock' and 'Pharmacy orders' views once P06 covers orders. After every stock row has lots_started_at, retire receiveStock, adjustStock, updateStockItem (scalar fields), the pharmacy-order advance delivery, ClientMedicalController::updateMedicationStock and the API scheduled counts.

### EA-172 · Every pharmacy order dialog shows and polls a 'Connected pharmacy delivery' card although the bridge is hard-disabled
- **Area:** Stock / Pharmacy connections
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** config/emar-pharmacy-connect.php:8-13, resources/js/pages/emar/pharmacy/_dispatch.tsx:26-36, resources/js/pages/emar/pharmacy/_dispatch.tsx:99-106, resources/js/pages/emar/stock/_dialogs.tsx:322-326, app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:80-87
- **Scenario:** A stock user opens any supply order in Stock › Pharmacy orders. A 'Connected pharmacy delivery' card appears containing only 'Pharmacy acceptance does not receive stock…'. While the dialog is open the browser calls /emar/stock/pharmacy-orders/{id}/connection every 15 seconds, for a feature that cannot be turned on without a code change.
- **Fix:** Render the card only when status.enabled is true or a dispatch exists, and skip polling when the bridge is disabled.

### EA-173 · Voiding a controlled-drug destruction from /emar/destructions takes the legacy path: no register workflow event, no P09 event, no idempotency
- **Area:** Stock & controlled › Destructions
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/_cd-dialogs.tsx:2034-2056, app/Http/Controllers/Emar/ControlledProductController.php:64-68, app/Http/Controllers/Emar/EmarController.php:8337-8381, app/Services/Medication/Controlled/ControlledRegisterService.php:146-166, app/Services/Medication/Controlled/ControlledRegisterService.php:497-516, routes/emar.php:416
- **Scenario:** A house lead with controlled.manage opens /emar/destructions › Controlled, picks a row and chooses Void, then enters a reason. VoidDestructionDialog posts only {void_reason}, with no client_request_uuid. ControlledProductController::legacy line 66 then falls back to EmarController::voidDestruction, which sets voided_at directly and writes only an AuditLogger row. It writes no ControlledWorkflowEvent, no 'controlled.destruction_void' P09 event and no controlled_product_requests receipt. The same void done from /emar/controlled (action-dialog destruction_void) writes all three. The register workflow history and the Reports & audit trail then disagree on whether the controlled-drug destruction was voided, and a retried void has no replay protection.
- **Fix:** Have VoidDestructionDialog send client_request_uuid and notes for controlled rows, or route Void to the register action-dialog. In legacy(), return a 422 for controlled voids that have no uuid instead of falling back. Then retire EmarController::voidDestruction for controlled records.

### EA-174 · Turning the stock_lots_enabled rollback flag off leaves pack-tracked medicines with no way to receive or adjust stock
- **Area:** Stock & controlled / flags
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** config/medications.php:8-11, app/Http/Controllers/Emar/MedicationStockController.php:56, app/Http/Controllers/Emar/MedicationStockController.php:150, app/Http/Controllers/Emar/EmarController.php:7251, app/Models/ClientMedicationStock.php:27-45, app/Services/Medication/Stock/StockReadPayload.php:130-131
- **Scenario:** After some houses have started pack tracking, ops set MEDICATION_STOCK_LOTS_ENABLED=false 'for rollout recovery'. A lead tries to receive a pharmacy delivery for a pack-tracked paracetamol. The legacy /emar/stock/receive rejects it with 'This medicine uses pack records… Stock & controlled drugs'. The pack hub it points to shows can.receive=false, and any POST returns 422 'Stock pack workflows are disabled'. Stock can only fall until it runs out.
- **Fix:** Let the flag gate only starting new pack tracking ('initialise' and first receipt). Keep receive, count, move and review available for stocks with lots_started_at set. Alternatively, document that the flag cannot be turned off once any stock has started and remove the 'rollout recovery' wording.

### EA-175 · Retained legacy controlled-drug register PDF uses UTC day bounds and prints UTC times; the legacy MAR CSV prints UTC columns, and P07b's 'Export register' buttons point at the PDF route
- **Area:** Stock & controlled / legacy exports
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/EmarPdfController.php:116-160, resources/views/pdf/controlled-drug-register.blade.php:58, app/Http/Middleware/MedicationExportGuard.php:24-36, app/Http/Controllers/MedicationsReportController.php:286-289, resources/js/components/emar/cd-detail-dialog.tsx:47, resources/js/components/emar/cd-detail-dialog.tsx:324, resources/js/pages/emar/ControlledDrugs.tsx:406
- **Scenario:** A request to /emar/pdf/controlled-register?client_id=…&purpose=audit&date_from=2026-10-01&date_to=2026-10-07 is audited by MedicationExportGuard as NZ days 1–7 Oct. The PDF actually contains register entries from 13:00 NZDT 1 Oct to 12:59 NZDT 8 Oct, so morning entries on 1 Oct are missing. Each row's time is UTC: an 08:00 NZDT 2 Oct dose prints '01/10/2026 19:00'. A printed CD register with wrong dates is a register error, and it disagrees with the P09 cd_register PDF, which uses MedicationPdfDataset in NZ. The legacy /reports/medications/export-mar CSV filters NZ days (L221-227) but writes 'Administered At' and 'Scheduled For' via toDateTimeString() in UTC. The in-app 'Export register' buttons (cd-detail-dialog, ControlledDrugs) open the route with no client_id or purpose, so they fail validation instead of producing a file.
- **Fix:** Either retire both legacy routes and repoint the 'Export register' buttons to the P09 exports (/emar/reports?view=exports, cd_register type), or fix them: NZ-day UTC bounds as in marChart, and ->timezone('Pacific/Auckland')->format('d/m/Y H:i T') in the blade and CSV.

### EA-176 · An ordinary 'initialise' opening carries forward a scalar balance that doses never decremented, on a single confirm, with no counted figure
- **Area:** Stock & controlled / rollout
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** config/medications.php:8-9, app/Http/Controllers/Emar/MedicationStockController.php:236, app/Services/Medication/Stock/MedicationStockService.php:50-81, app/Services/EnhancedMarService.php:1511-1533, resources/js/pages/emar/stock/StockHub.tsx:963-970, resources/js/pages/emar/stock/StockHub.tsx:1090-1110
- **Scenario:** On .com (lots ON, no openings) a lead opens a resident's metformin in the Stock hub and clicks 'Use the checked recorded balance'. The legacy on_hand of 120 was typed at a delivery two months ago. Doses never reduced it, so about 60 tablets are actually left. An opening pack of 120 is created ('Recorded balance carried forward') with the legacy expiry. Low-stock and out-of-stock alerts then work from a figure that is double the real one.
- **Fix:** Make the ordinary opening a counted opening. Require a counted quantity (as CountWizard does), create the opening pack from the count, and record a stock-count record or discrepancy against the recorded on_hand when they differ. Keep 'recorded balance' only as the displayed comparison.

### EA-177 · Two stock surfaces: the sidebar hub and every inbound link land on the legacy /emar/stock page, while the approved P06 hub sits under 'Packs & counts' with a different rail and crumbs
- **Area:** Stock & controlled drugs
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:384-444, app/Http/Controllers/Emar/EmarController.php:2571-2574, resources/js/pages/emar/StockManagement.tsx:603-625, resources/js/pages/emar/stock/StockHub.tsx:328-366, resources/js/pages/emar/stock/StockHub.tsx:545-555, resources/js/pages/emar/Index.tsx:820-828, resources/js/pages/meds/today/_rounds-stock.tsx:50-58,248-255, resources/js/pages/emar/pharmacy/index.tsx:57-62, app/Models/ClientMedicationStock.php:38-51, routes/emar-stock.php:7-15
- **Scenario:** A coordinator clicks Medication › Stock & controlled drugs. emarHubLanding picks the first visible view, so they land on the legacy 'Stock & pharmacy' page (StockManagement.tsx). Overview, Meds today stock alerts and the pharmacy-connections 'Pharmacy orders' button also lead there. Receiving stock for a lot-started medicine then fails with 'This medicine uses pack records…'. On unstarted medicines the scalar legacy writer still runs while stock_lots_enabled defaults to true. Opening 'Packs & counts' switches to a page whose rail has no Discrepancies, Loss reports or Destructions tabs and whose breadcrumb says Home › Medication(/emar) › Stock & controlled drugs with no view crumb.
- **Fix:** Make /emar/stock render the P06 StockHub, or redirect it to /emar/stock/packs, and collapse the three stock views into one 'Stock' view with the legacy URLs as aliases. Point the Overview :822, Meds today _rounds-stock and pharmacy 'Pharmacy orders' links at the P06 hub, with medication_id context where one is known. Give StockHub useEmarBreadcrumbs() and either EmarHubRail or rail tabs that reach the controlled views. This needs the pending §2A.12 retirement decision.

### EA-178 · Stock hub lands on the legacy StockManagement page; the approved P06 StockHub is demoted to /emar/stock/packs with a second, conflicting rail
- **Area:** Stock & controlled drugs (P06)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:393-411, resources/js/pages/emar/stock/StockHub.tsx:328-353, resources/js/pages/emar/stock/StockHub.tsx:359-366, resources/js/pages/emar/stock/StockHub.tsx:415, resources/js/pages/emar/StockManagement.tsx:605-619
- **Scenario:** A stock manager clicks 'Stock & controlled drugs' in the sidebar and lands on 'Stock & pharmacy' (legacy). Its rail shows Stock / Pharmacy orders / Packs & counts / …. Clicking 'Packs & counts' opens a different page titled 'Stock & controlled drugs' with a different rail (Stock / Deliveries & orders / Counts / Expiring / Removals / Movements / Controlled) and a 'Stock overview' button back to the legacy page. There are two 'Stock' tabs with different contents in one hub.
- **Fix:** Serve StockHub at /emar/stock as the hub landing and redirect the legacy views to its tabs. Drive its rail from EMAR_HUBS rather than a local array. Remove 'Stock overview' and the hard-coded crumbs. Keep the legacy page reachable only if Stephan's duplicate-surface decision (map §2A.12) says so.

### EA-179 · CheckMedicationStock is pack-aware (map claim refuted) but raises daily low and out-of-stock alerts for stopped or held orders and inactive people
- **Area:** Stock alerts / scheduler
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** app/Console/Commands/CheckMedicationStock.php:26-28, app/Console/Commands/CheckMedicationStock.php:59-61, app/Console/Commands/CheckMedicationStock.php:112-154, app/Console/Commands/CheckMedicationStock.php:174-181, app/Models/ClientMedicationStock.php:142-161, app/Services/Medication/Stock/StockAvailability.php:22-30, app/Services/Medication/Stock/StockAvailability.php:91-103
- **Scenario:** An Amoxicillin course is stopped on 3 Oct with 0 tablets left and reorder_level 10. Every day at 06:00, ClientMedicationStock::lowStock() matches it (usable 0 ≤ 10). The job then upserts a critical 'stock_low' dashboard alert and emits a Control Room STOCK_OUT 'high' signal, 'Amoxicillin for Jo: OUT OF STOCK', for a medicine nobody should give. Expired and expiring packs of stopped orders alert the same way. The only dedupe is 'an active alert created in the last 24h'.
- **Fix:** Add ->whereHas('medication', fn ($q) => $q->active()->whereHas('client', fn ($c) => $c->where('status', 'active'))) to all three queries. Skip the STOCK_OUT signal when the order is held.

### EA-180 · The person who counted a stock difference can sign off their own write-off; there is no separation of duties
- **Area:** Stock counts (P06)
- **Audit dimension:** workflows-leads
- **Status:** verified (claimed P1)
- **Refs:** app/Http/Controllers/Emar/MedicationStockController.php:51-56, app/Http/Controllers/Emar/MedicationStockController.php:180-187, app/Services/Medication/Stock/MedicationStockService.php:373-406, resources/js/pages/emar/stock/_dialogs.tsx:935-940, resources/js/pages/emar/stock/StockHub.tsx:686-693
- **Scenario:** 1. A coordinator, finance user or provider manager (the holders of medications.stock.update) runs a blind count of sertraline: counted 26, expected 28, reason 'unknown'. 2. The same user opens 'Review this count' and signs off the adjustment. reviewCount writes a -2 count_correction and closes the stock-discrepancy follow-up. 3. Missing medicine is written off by the person who found it, with no independent review. The approved P06 design says differences wait for the house lead to sign off ('Counted 26, expected 28 — waiting for the house lead to sign off'), and the dialog itself says 'A house lead reviews differences'.
- **Fix:** In reviewCount, reject the sign-off when $actor->id === $record->counted_by. Ideally also require the house-lead and manager sign-off capability from the approved P06 grants (held decision A4). Add a test that the counter cannot review their own count.

### EA-181 · Starting pack tracking carries forward an unchecked legacy balance that doses have never reduced
- **Area:** Stock with lots ON and no counted opening (P06)
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/EnhancedMarService.php:1511-1517, app/Services/Medication/Stock/MedicationStockService.php:50-81, app/Http/Controllers/Emar/MedicationStockController.php:236, resources/js/pages/emar/stock/StockHub.tsx:1099-1110, resources/js/pages/emar/stock/StockHub.tsx:1457-1464
- **Scenario:** 1. A legacy stock row shows on_hand 56, recorded at the last receipt two weeks ago. 2. Since then, 28 tablets were given. Doses never decrement unstarted ordinary stock, so on_hand is still 56. 3. A coordinator clicks 'Use the checked recorded balance' and confirms. 4. An opening pack of 56 is created with no physical count entered. 5. Days remaining and low-stock alerts treat the person as having 4 weeks of supply when there is 2. The reorder is late and the first blind count shows a -28 discrepancy.
- **Fix:** Make the ordinary opening a blind physical count: enter the counted quantity, show the difference against the recorded balance, and create the opening lot from the counted figure. Any difference goes through the existing count-review path. Do not offer a carry-forward without a count.

### EA-182 · Witness PIN pepper: any non-empty value switches to the peppered hash, but fewer than 32 characters blocks every PIN set, and rotating it locks out every PIN
- **Area:** Witness PIN / config
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** config/medications.php:61-63, app/Services/Medication/WitnessPinService.php:176-186, app/Services/Medication/WitnessPinService.php:275, app/Services/Medication/WitnessPinService.php:301, app/Services/Medication/WitnessPinService.php:493-511
- **Scenario:** (a) Ops set MEDICATION_WITNESS_PIN_PEPPER to a 24-character secret. From then on every 'Set witness PIN' and every post-reset PIN fails with 'Witness PIN security is not configured', so staff whose PINs were reset can no longer witness controlled doses. (b) Later the pepper is rotated, or lost when .env is rebuilt. Every hmac_sha256_v1 PIN then fails Hash::check as 'Incorrect PIN' (or 'not configured' if the pepper is lost), failed attempts climb, and after 5 tries every staff PIN locks for 15 minutes. All controlled witnessing stops.
- **Fix:** Use one predicate: newHashVersion() should return HASH_PEPPERED only when the pepper is a string of at least 32 characters, and the app should fail at boot or in a health check (not per PIN) when it is set but too short. Store a pepper key id in hash_version (e.g. hmac_sha256_v1:k1) and support a previous-pepper list so rotation re-hashes on the next successful check instead of counting a failure.

## P3 (49)

### EA-183 · An alert that reached nobody when raised is never re-evaluated or escalated: later-rostered staff and escalation groups are never told
- **Area:** Alert follow-up engine
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Alerts/MedicationAlerts.php:53-67, app/Services/Medication/Alerts/MedicationAlerts.php:129-131, app/Services/Medication/Alerts/MedicationAlerts.php:157-178, app/Services/Medication/Alerts/MedicationAlertFollowUps.php:66-73
- **Scenario:** Escalate-to is set to on-call and provider manager. An overdue-dose alert is raised during a gap in the roster with no reachable house lead or settings manager, so it reaches nobody (reached_nobody=true). next_follow_up_at is only set when someone was told, so the follow-up tick never escalates it. Each later 15-minute sweep hits the open_key unique constraint and raise() returns null, so day staff who start at 07:00 are never told either.
- **Fix:** When told is empty and follow_up is on, still set next_follow_up_at, and let the step re-resolve the first groups (not just toldSoFar) as well as escalate_to.

### EA-184 · Emergency-access daily report alerts are never closed or attended, so each house adds one permanently 'open' alert per day to the alert log
- **Area:** Alert log / break-glass report
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Console/Commands/SendDailyBreakGlassReport.php:49-56, app/Services/Medication/Alerts/MedicationAlertCatalogue.php:203-213, app/Services/Medication/Alerts/MedicationAlerts.php:205, app/Services/Medication/Alerts/MedicationAlertAttendance.php:224-227, app/Services/Medication/Alerts/MedicationAlertLog.php:76, app/Services/Medication/Alerts/MedicationAlertLog.php:118
- **Scenario:** Each morning at 08:00, every house that had emergency-access use or pending reviews gets an alert keyed 'daily:<date>:<site>'. Nothing resolves these alerts. follow_up is false, so the bell asks for no acknowledgement, and opening it is weaker than the default 'ack' rule, so it is never attended. Settings › Alert log's 'open' count grows without limit, which hides real open alerts.
- **Fix:** Close the previous day's report when the next one is raised, for example reconcile(BREAKGLASS, [todayKeys]). Alternatively raise it as dealt-with on creation, or resolve it when all its listed grants are reviewed.

### EA-185 · The bell pin pins every step of an unattended follow-up alert (first, each re-alert, escalation), which can fill the 8-slot bell with copies of one alert
- **Area:** Bell (header inbox)
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/Alerts/MedicationBellOrder.php:13-22, app/Services/Medication/Alerts/MedicationAlerts.php:225-229, app/Services/Medication/Alerts/MedicationAlertFollowUps.php:125, app/Services/Medication/Alerts/MedicationAlertFollowUps.php:137, app/Http/Middleware/HandleInertiaRequests.php:411-416
- **Scenario:** 'Keep unattended alerts at the top of the bell' is on and re-alert is every 15 min up to 10 times. A house lead with two unattended overdue-dose alerts has up to 22 notification rows that all match the pin. The header shows 8 items, all copies of 2 alerts, and pushes unrelated notifications such as witness requests and HR items out of view.
- **Fix:** Pin only the newest notification per medication_alert_id, for example by restricting the CASE to the max(created_at) row per alert id, or collapse the re-alert steps into one bell row.

### EA-186 · /emar/catalogue is open to every medications.view reader, including draft and revoked sources
- **Area:** Catalogue
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** routes/emar-catalogue-backups.php:7-8, app/Services/Medication/MedicineCatalogue/MedicineCatalogueService.php:21-32, app/Services/Medication/MedicineCatalogue/MedicineCatalogueService.php:168-171
- **Scenario:** A support worker types /emar/catalogue and sees every source version (draft, expired and revoked), with supplier licence references and full product lists. Photos from draft sources return 404 for them.
- **Fix:** Gate the index page on medications.catalogue.manage; readers only need match and photo for reviewed products. Alternatively, filter to reviewed sources for non-managers.

### EA-187 · Blocked 'Record dose' and 'Check chain' buttons hide their reason in a title tooltip that never shows on a disabled button
- **Area:** Client profile MAR tab / Reports & audit
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/operations/clients/tabs/mar.tsx:367-379, resources/js/pages/emar/reports/hub.tsx:479-489, resources/js/pages/emar/reports/hub.tsx:505-508
- **Scenario:** A support worker who is not clocked in opens the client profile MAR tab. 'Record dose' is greyed out and hovering shows nothing, so they cannot tell they need to clock in. On Reports › Audit, 'Check chain' is greyed out with no visible hint to choose one house.
- **Fix:** Render the reason as visible caption text next to the button, as Orders.tsx does with entryUnavailableReason and aria-describedby, and describe the permission rather than listing role names.

### EA-188 · No expiry reminders or owner tasks for outside-prescriber grants, identities or catalogue sources
- **Area:** Connected care / Catalogue
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Refs:** config/emar-external-clinical.php:4-5, app/Services/Medication/ExternalClinical/ExternalClinicalAccess.php:146-157, app/Services/Medication/MedicineCatalogue/MedicineCatalogueService.php:183-190, routes/console.php
- **Scenario:** A GP's 90-day grant ends in the middle of an acute medication review. The person simply disappears from the GP's portal and staff get no warning. A catalogue source passes its review expiry and every linked picture silently becomes 'review required'.
- **Fix:** Add a reminder or task 14 days ahead of each expiry for the granting manager and the catalogue owner.
- **Collision:** app/Services/Tasks/TaskAggregator.php (Low) if added as an All Tasks provider

### EA-189 · Connected-care screens show raw codes and break the full-width rule
- **Area:** Connected care / clinical portal UI
- **Audit dimension:** connected-care
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/ConnectedCare.tsx:336, resources/js/pages/emar/ClinicalPortal.tsx:82, resources/js/pages/emar/ClinicalPortal.tsx:261, DESIGN.md:572-578
- **Scenario:** Staff see request types as the lowercase codes 'start', 'change' and 'stop'. Outside prescribers see 'pending_verification · version 3' against medicines. The portal body is capped at 1600 px.
- **Fix:** Map kind and approval_status through a labels helper ('New medicine' / 'Change' / 'Stop'; 'Waiting for check' / 'Checked'). Remove the max-width cap.

### EA-190 · Pharmacy dispatch makes its HTTP call while holding the person's Client row lock, the same mutex dose recording uses (dormant)
- **Area:** Connected care / pharmacy
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/PharmacyConnect/PharmacyDispatchService.php:149-176, app/Services/Medication/MedicationGovernanceScopeService.php:992-1016, app/Services/Medication/PharmacyConnect/HttpJsonPharmacyTransport.php:25-26, config/emar-pharmacy-connect.php
- **Scenario:** Once the pharmacy bridge is enabled, sending a supply order for Mary holds Client(Mary) FOR UPDATE for up to about 20 s (5 s connect plus up to 15 s timeout). Any dose recorded for Mary in that window blocks. With a slow partner it can hit innodb_lock_wait_timeout, return 5xx, and push the dose into the uncertain or queued path.
- **Fix:** Keep the durable claim, but do the HTTP call outside any transaction. Re-lock the dispatch only by claim token afterwards to record the result, and recheck authority before the call with a short transaction that commits before the network I/O.

### EA-191 · Control Room shift alerts can never flag medicines due: medications_due_soon reads 'pending' administration rows that no longer exist
- **Area:** Control Room shift context
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/ControlRoom/SignalProcessingService.php:1767, app/Services/ControlRoom/SignalProcessingService.php:2057-2078
- **Scenario:** A support worker no-shows a 19:00 shift at a house where 20:00 doses are due. The Control Room no-show or late-start alert context always shows medications_due_soon {count: 0, has_due: false}, so operators cannot see the medication urgency when they triage it.
- **Fix:** Count owed dose slots for the shift's client or house in the window via ScheduledDoseStates or DoseSlotProjection (count only, no names), or remove the field.
- **Collision:** HIGH: app/Services/ControlRoom/SignalProcessingService.php (H&S lane)

### EA-192 · Way back is lost from Follow-ups, the MAR hub and StockHub: return_to allowlists skip /medication-followups and others, and some targets never render the return button
- **Area:** Cross-module return_to
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/medication-navigation.ts:25, app/Support/MedicationJourney.php:28, app/Services/Medication/Followups/MedicationFollowupService.php:325,397, resources/js/components/emar/followups/followup-list.tsx:146-151, resources/js/pages/emar/record/hub.tsx (no MedicationJourneyReturn), app/Http/Controllers/Emar/MedicationRecordHubController.php:127, resources/js/pages/meds/today/index.tsx:863-874, resources/js/pages/emar/stock/StockHub.tsx:197-212, app/Services/Sites/Calendar/Providers/MedicationObligationProvider.php:132-138
- **Scenario:** A lead on Safety & oversight › Follow-ups opens a dose's record via 'Open record'. The record's back arrow goes to /emar/medications?client_id=…, not back to the follow-up queue. A worker clicks 'MAR charts' on Meds today, which attaches return_to, but the MAR hub never shows 'Return to previous task'. A site-calendar 'Stock expires' event opens /emar/stock/packs with return_to, which StockHub carries along but never offers.
- **Fix:** Add /medication-followups (and decide on /incidents, which the H&S lane also restricts) to both allowlists. Pass return_to on follow-up record links. Render <MedicationJourneyReturn/> on record/hub.tsx and StockHub.tsx, since both already carry filters.return_to and medicationReturnParams.
- **Collision:** Incident navigation (H&S #16: lib/incidents/navigation.ts) only if /incidents is added; otherwise none

### EA-193 · The medication-cabinet open listener ('NotifyOn…') only writes a log line. No alert, no audit entry, no tie to the controlled register
- **Area:** Devices / controlled drugs
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Listeners/Care/NotifyOnMedicationCabinetOpen.php:21-58, app/Providers/EventServiceProvider.php:47
- **Scenario:** A cabinet flagged meta.medication_cabinet opens at 02:30 with no dose recorded and no controlled count in progress. Only Log::info('care.medication_cabinet_opened') is written. Nobody is alerted, nothing enters the medication event chain or audit log, and nothing is matched against controlled register activity.
- **Fix:** Rename it to match what it does, or record an AuditLogger or MedicationEventRecorder entry. If wanted (Stephan), raise a cdDiscrepancy-style alert when an out-of-hours opening has no matching register entry within N minutes.
- **Collision:** Medium: devices domain (eMAR finding in another lane's territory, §3)

### EA-194 · Paper recovery and duplicate-review rejections in Downtime are raised as HTTP exceptions on Inertia forms, so their messages never appear in the wizard.
- **Area:** Downtime paper reconciliation (P10)
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/Downtime/PaperEntryService.php:424, app/Services/Medication/Downtime/PaperEntryService.php:433-434, app/Services/Medication/Downtime/PaperEntryService.php:440, app/Services/Medication/Downtime/PaperEntryService.php:485-489, app/Http/Controllers/Emar/MedicationPaperEntryController.php:77-85, app/Http/Controllers/Emar/MedicationDowntimeController.php:150-159, resources/js/pages/emar/downtime/_paper-review.tsx:199-202, resources/js/pages/emar/downtime/_paper-review.tsx:217-226
- **Scenario:** 1. A lead opens 'Review historical recording authority' for a paper dose, and the retained grant cannot be found or changes between preview and submit. 2. `authorizeRecovery` calls `abort_unless($grant, 422, 'No retained server grant proves authority…')`. 3. `form.post` gets a non-Inertia HTML error response. The wizard's alert area shows only `form.errors` (validation errors), so the lead sees Inertia's generic error modal with no explanation. 4. Duplicate review gives the same result: 409 'This paper collection item is already complete.'
- **Fix:** In `PaperEntryService::authorizeRecovery` and `resolveDuplicate`, replace the message-bearing `abort(422\|409, …)` calls with `ValidationException::withMessages(['reason' => …])`, so Inertia shows them inline and keeps the form values.

### EA-195 · Unreferenced 'Coming Soon' stub page remains in the eMAR pages bundle
- **Area:** eMAR pages (hide unbuilt)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Placeholder.tsx:14-38
- **Scenario:** No current route renders it. Any future Inertia::render('emar/Placeholder') would ship a 'Coming Soon … being developed' card on a legacy PageHero, and it stays in the page glob as a template to copy.
- **Fix:** Delete Placeholder.tsx along with the other dead eMAR pages listed in map §6.6.

### EA-196 · Break-glass revoke gates drift between the two routes and the UI flag
- **Area:** Emergency access › End access
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Refs:** routes/clients.php:210-212, routes/emar.php:546-548, app/Http/Controllers/BreakGlassController.php:56-60, app/Http/Controllers/Emar/EmarController.php:166
- **Scenario:** An auditor (audit.view, no breakglass or breakglass.end) passes the DELETE /clients/{client}/break-glass/{access} middleware and gets a controller 403. The /emar copy refuses them at the route. EmarController sends can.revoke_break_glass=true to every audit.view holder, so legacy pages show an 'End' affordance that always fails for them.
- **Fix:** Align clients.php to breakglass\|breakglass.end (or remove the duplicate route), and compute revoke_break_glass as breakglass \|\| breakglass.end.

### EA-197 · Emergency access and Downtime ship phone card layouts and a bespoke table, against the desktop-only and list contracts
- **Area:** Emergency access (P10) / Downtime
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emergency/access.tsx:249-251, resources/js/pages/emergency/access.tsx:357, resources/js/pages/emergency/access.tsx:408-409, resources/js/pages/emergency/access.tsx:531, resources/js/pages/emar/downtime/index.tsx:194, resources/js/pages/emar/downtime/index.tsx:249
- **Scenario:** At narrow desktop widths or 200% zoom, below md, the grants table is swapped for a stack of phone cards whose actions are outline buttons with no kebab or right-click. The 'Running now' meter stays amber at 0. An 'Emergency access policy' navigation link sits in the filter row as if it were a filter.
- **Fix:** Remove the md:hidden branches and let EntityTable scroll horizontally. Migrate the grants table to EntityTable with actionsFor and onRowContextMenu. Make the tone value-driven, and move the policy link to the actions cluster.

### EA-198 · Accepting an external clinician proposal writes two 'order.entered' events for one action to the P09 chain
- **Area:** External prescriber / Connected care
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/ExternalClinical/ExternalClinicalProposals.php:112, app/Services/Medication/ExternalClinical/ExternalClinicalProposals.php:167-170, app/Services/Medication/MedicationOrderWorkflow.php:343-365, app/Services/Medication/MedicationOrderWorkflow.php:367-386
- **Scenario:** 1. An office lead accepts a portal proposal to start a new medicine. 2. decide() runs inside orders->forClient, which captures marker M. 3. The nested orders->enter() runs its own forClient or forMedication with the same marker, writes the 'entered' action and calls finishActions, which appends 'order.entered'. 4. The outer forClient then collects all actions after M for the client, which includes the same action, and runs finishActions again. 5. The audit chain records the entry twice, and any break-glass use is also recorded twice.
- **Fix:** Call enter() outside the outer forClient, or have finishActions remember the action IDs already finished in this request and skip them.

### EA-199 · The 'Handover not acknowledged' lead follow-up stays open after the handover is acknowledged
- **Area:** Follow-ups and handover (P08a)
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Followups/MedicationFollowupService.php:861-893, app/Services/Medication/Followups/MedicationFollowupService.php:896-918, app/Console/Commands/RefreshMedicationFollowups.php:95-101
- **Scenario:** 1. The incoming worker doesn't acknowledge the handover within an hour of shift start. 2. The every-minute job creates the 'handover:{id}' lead follow-up 'Handover not acknowledged'. 3. At 1h15 the worker acknowledges. 4. acknowledged() carries the follow-ups over but never completes 'handover:{id}'. 5. The lead still sees an open 'Handover not acknowledged' task and has to sign it off by hand.
- **Fix:** In acknowledged(), complete 'handover:'.$handover->id with outcome 'acknowledged' (through completeFromSource, or close() when the row exists) in the same transaction.

### EA-200 · emar:workflow-followups locks Client rows every minute for every unacknowledged handover ever submitted, with no age bound or per-row isolation
- **Area:** Handovers / scheduler
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** app/Console/Commands/RefreshMedicationFollowups.php:95-100, app/Services/Medication/Followups/MedicationFollowupService.php:896-918, routes/console.php:988
- **Scenario:** Months of submitted-but-never-acknowledged handovers build up. Every minute the job opens one transaction per handover and takes Client FOR UPDATE, the same mutex dose recording uses. This adds lock contention at round times. One handover whose ensure() throws aborts the chunk, so the heads-ups for all later handovers stop each minute.
- **Fix:** Filter to incoming shifts that started within, for example, the last 48 h and are already an hour past start, before taking locks. Wrap each headsUp call in try/catch with report().

### EA-201 · Overview and task links point to alias or contextless URLs (/emar/competency, the CD loss list) and use the retired 'eMAR' label
- **Area:** Inbound links
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Index.tsx:814-819, app/Http/Controllers/Emar/StaffEligibilityController.php:45-55, app/Services/Tasks/Providers/CdLossReportProvider.php:82, app/Http/Controllers/Emar/ControlledProductController.php:28-31, app/Http/Controllers/Operations/WorkforceSettingsController.php:152
- **Scenario:** A coordinator opens an 'Open CD loss' task in All Tasks and lands on the all-house losses list with no person, house or report selected. Medication-error tasks open the exact error. The Overview's 'staff competencies expiring' link goes through the /emar/competency redirect, which drops any view such as renewals. Workforce settings lists 'eMAR settings' although the module is now 'Medication'.
- **Fix:** Link CD loss tasks to /emar/controlled?view=losses&site_id=…&client_id=… plus the report id if the register supports opening one. Link the Overview to /emar/safety/eligibility?view=renewals. Rename the label to 'Medication settings'.

### EA-202 · Legacy 'Today's Medications' widget counts the UTC day (13:00–13:00 NZDT) and assumes today is active for orders ending today
- **Area:** Legacy /api/medications dashboard widgets
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Services/MedicationAlertService.php:736-835, app/Services/MedicationAlertService.php:536-546, app/Http/Controllers/Api/MedicationsApiController.php:1611-1631, routes/api_medications.php:14-16
- **Scenario:** At 09:00 NZDT, GET /api/medications/dashboard/widgets returns todays_summary covering 13:00 NZDT yesterday to 13:00 NZDT today. This morning's 08:00 doses count toward 'completed/refused/missed', and so do yesterday's 20:00 doses, while total_scheduled counts today's dose_times. completion_percentage can exceed real completion. No in-app page calls this endpoint (grep finds no frontend caller), so impact is limited to API clients.
- **Fix:** Use MarScheduleService::utcDayWindow(now(worker tz)), or delete the widget with the rest of the unused legacy API (map 6.6).

### EA-203 · Out-of-scope medication reads answer 403 instead of the documented indistinguishable 404
- **Area:** Legacy medications API / Emergency access / Summaries
- **Audit dimension:** privacy
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/MedicationRecordAccess.php:10-20, app/Http/Controllers/Api/MedicationsApiController.php:188-200, app/Http/Controllers/Api/MedicationsApiController.php:553-555, app/Http/Controllers/Api/MedicationsApiController.php:1790-1792, app/Http/Controllers/EmergencyAccessController.php:29, app/Http/Controllers/SummaryController.php:34, app/Http/Controllers/SummaryController.php:58
- **Scenario:** A support worker probes GET /api/medications/clients/{id}/mar, /allergies, /medications/{m}/versions and so on, iterating ids. Ids of people at other houses return 403 ('This action is unauthorized'), while non-existent ids return 404. This confirms which client ids exist org-wide. EmergencyAccessController returns 403 for an out-of-scope ?site_id=.
- **Fix:** Replace authorize('viewMedications') with app(MedicationRecordAccess::class)->assertReadable($user, $client) in authorizeReadableMedication, getMar and getAllergies. Change EmergencyAccessController:29 to 404, and SummaryController staff and client scope failures to 404.

### EA-204 · Dead code and orphan pages, dialogs and routes safe to retire (about 15k lines)
- **Area:** Legacy surfaces
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/CDLossReportController.php:1-377, app/Http/Controllers/Emar/EmarReportController.php:1-890, app/Http/Controllers/MedicationsReportController.php:22, app/Http/Controllers/Emar/EmarController.php:2025-2310, app/Http/Controllers/Emar/EmarController.php:2854-2964, app/Http/Controllers/Emar/EmarController.php:3213-3462, app/Http/Controllers/Emar/EmarController.php:4419-4658, app/Http/Controllers/Emar/EmarController.php:5893-6079, app/Http/Controllers/Emar/EmarController.php:7541-7582, app/Http/Controllers/Emar/EmarController.php:7596-8218, app/Http/Controllers/ClientMedicalController.php:893-955, resources/js/pages/emar/Placeholder.tsx:1-40, resources/js/pages/emar/ControlledDrugs.tsx, resources/js/pages/emar/Reviews.tsx, resources/js/pages/emar/Reports.tsx, resources/js/pages/reports/medications.tsx, resources/js/pages/medications/audit.tsx, resources/js/pages/clients/medical-simple.tsx, resources/js/components/clients/profile/emar-dialog.tsx, resources/js/components/prn-sheet.tsx, resources/js/components/medications/ScheduledStockCounts.tsx, resources/js/components/active-shift-card.tsx, resources/js/pages/emar/_error-dialogs.tsx, resources/js/pages/emar/_self-admin-dialogs.tsx, resources/js/pages/emar/components/audit-log-modal.tsx, resources/js/pages/emar/components/reports-modal.tsx, resources/js/components/emar/cd-detail-dialog.tsx
- **Scenario:** None of these paths is reachable from a route or a mounted component on main, apart from the legacy-mode pages noted below. Several contain unwitnessed controlled-drug writers that would become live again if someone re-routed them.
- **Fix:** Delete in one PR together with their tests (each item has an import or route check above). Decide Stephan's open question on EMAR_PERSON_RECORD=legacy before removing the legacy-mode group; when it goes, remove emar.corrections.* and /emar/medications/import with it. Run eslint and tsc in 200-file chunks per memory.

### EA-205 · Legacy Medications register (EMAR_PERSON_RECORD=legacy) shows stock-movement, created and verified times in UTC
- **Area:** MAR & medicines (legacy rollback path)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/EmarController.php:2333, app/Http/Controllers/Emar/EmarController.php:2360, app/Http/Controllers/Emar/EmarController.php:2536-2538, app/Http/Controllers/Emar/EmarController.php:2419-2423, resources/js/pages/emar/_dialogs.tsx:211, resources/js/pages/emar/_dialogs.tsx:449-470
- **Scenario:** If the site rolls back with EMAR_PERSON_RECORD=legacy, the /emar/medications detail dialog's 'Recent stock activity' shows a dose given 8:05 am NZDT on 9 Oct as '8 Oct 2026, 7:05pm', and completed counts and the order's created_at/verified_at are shifted the same way. With the default 'p02' this page is not rendered.
- **Fix:** Convert to the worker timezone before formatting, or send ISO strings and format them on the client. Alternatively remove the legacy page if the rollback flag is retired (decision 2A.14).

### EA-206 · P03 consent-change dialog: on an ambiguous or skipped DST wall time it sends the bare wall time, which the server parses as UTC
- **Area:** MAR & medicines / Self-administration support (P03)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/support/_dialogs.tsx:1559, resources/js/pages/emar/support/_dialogs.tsx:1567-1577, resources/js/pages/emar/support/_dialogs.tsx:1741-1755, resources/js/pages/emar/support/time.ts:2-30, app/Http/Controllers/Emar/MedicationSupportController.php:153-156, app/Services/Medication/Support/MedicationSupport.php:284
- **Scenario:** On 4 Apr 2027 a worker records at 02:30 that the person asked staff to take back their inhaler. candidates.length is 2 and the 'which occurrence' choice appears, but the Record button is not disabled. If they save without choosing, `occurrence \|\| form.data.occurred_at` sends '2027-04-04T02:30'. The server runs CarbonImmutable::parse with no zone, i.e. UTC, and stores occurred_at as 14:30 NZST: the support record says the person asked at 2:30 pm. For a skipped spring-forward time (26 Sep 2027 02:30), candidates is empty and the raw value is sent the same way. The mode change itself uses effective_at = now, so dosing is unaffected; only the recorded time of the consent event is wrong.
- **Fix:** Disable 'Record change' until an occurrence is chosen when candidates.length === 2, and show an error when it is 0. On the server, require an offset (regex) or parse with PaperReconciliationRules::instant.

### EA-207 · Hand-rolled Previous/Next pagination instead of LaravelPagination on several eMAR lists
- **Area:** MAR hub / Follow-ups / Emergency access / Person record history
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/record/hub.tsx:643-668, resources/js/pages/emar/Followups.tsx:371-399, resources/js/pages/emergency/access.tsx:569-597, resources/js/components/emar/record/history.tsx:265-280
- **Scenario:** On page 1 of 14 of emergency-access history, a reviewer cannot jump to page 10 and has to click Next nine times. Each of these lists pages differently from Orders, Errors, Reviews and the stock lists, which use the canonical paginator.
- **Fix:** Return Laravel paginator links from the controllers and render LaravelPagination with preserveState and preserveScroll.

### EA-208 · Each recorded dose blanks the whole MAR chart to a loading skeleton, and a failed refresh replaces the chart with an error. Meds today keeps stale data instead.
- **Area:** MAR record (P02) sections
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/emar/record/use-record-json.ts:20-24, resources/js/components/emar/record/use-record-json.ts:58-62, resources/js/components/emar/record/reading.tsx:112-113, resources/js/components/emar/record/chart.tsx:119-144, resources/js/pages/meds/today/index.tsx:307-327
- **Scenario:** 1. A lead records a dose from a person's chart. 2. `onMedicationRecorded` reloads every section. `setState({data: null, load: 'loading'})` blanks the chart, medicines and history to skeletons. 3. If that refresh fails (a Wi-Fi blip), the chart that was just visible is replaced by 'We couldn’t load this part of the record'. 4. Meds today, by contrast, keeps the last board with 'Not updated since … — refresh before you record'.
- **Fix:** - Keep the previous `data` while reloading (`load: 'refreshing'`). - On failure, keep the data and show a stale Notice with a 'Refresh' button, matching the Meds today pattern.

### EA-209 · Meds today view order differs between the page rail, the hub map/search and the approved P00 order
- **Area:** Meds today
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/meds/today/index.tsx:767-807, resources/js/lib/emar-navigation.ts:232-281, frozen/P00/mockup.js:41-48
- **Scenario:** The Meds today rail reads Schedule, Rounds, As-needed, Follow-ups, Stock alerts, Activity, Controlled checks. Command search lists Follow-ups before As-needed. The approved P00 order puts Controlled checks before Stock alerts. Users see three different orders for the same seven views.
- **Fix:** Order EMAR_HUBS today views to match the approved P00 order and build the Meds today rail from that list (keeping its counts and alert decorations).

### EA-210 · Controlled-check and register buttons use min-h-11 (38.5 px at the 14 px root) instead of .frontline-tap
- **Area:** Meds today › Controlled checks / Controlled register dialogs
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/emar/controlled/controlled-checks.tsx:229, resources/js/components/emar/controlled/controlled-checks.tsx:258, resources/js/components/emar/controlled/controlled-checks.tsx:307, resources/js/components/emar/controlled/controlled-checks.tsx:352, resources/js/components/emar/controlled/controlled-checks.tsx:426, resources/js/components/emar/controlled/count-dialog.tsx:439, resources/js/components/emar/controlled/record-views.tsx:223, resources/js/pages/emar/ControlledRegister.tsx:621
- **Scenario:** On Meds today › Controlled checks, the shift-change count and witness buttons render 38.5 px tall at the default 14 px root font, below the 44 px frontline floor. Users on the 13 px density setting get about 35.75 px.
- **Fix:** Replace min-h-11 with `frontline-tap` on frontline buttons and labels. In dense desktop rows use `frontline-hit`. Verify with getBoundingClientRect.

### EA-211 · Main already contains out-of-order migration timestamps, and the in-flight lanes collide with each other
- **Area:** Migrations / rollout process
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** database/migrations/2026_10_05_210000_create_workforce_eligibility_refresh_tables.php, database/migrations/2026_10_05_210100_add_workforce_refresh_progress.php, database/migrations/2026_10_07_096000_expand_medication_provider_transfer_event_evidence.php
- **Scenario:** The test server ran 2026_10_07_090000–096000 (PR #18) before 2026_10_05_210000/210100 (merged afterwards by the workforce commit 2050a789f). A fresh install runs them in the opposite order. The lanes also share timestamps (devices and workforce both use 2026_10_08_230000), and every lane carries copies of the seven 2026_10_07_09xxxx eMAR migrations. A new eMAR migration timestamped between 2026_10_07 and 2026_10_08_235100 would sort differently on .com than on a fresh database.
- **Fix:** Give every follow-up eMAR migration from this audit (guards, enum widening, admin grant) a timestamp after 2026_10_08_235100, e.g. 2026_10_09_1xxxxx. Never let a migration depend on one with a later timestamp. Add a CI check that fails when a PR adds a migration timestamped earlier than the newest one on main.
- **Collision:** All three lanes' migration sets (map §3 'Migration timestamps').

### EA-212 · emergencyPolicyAccess is never sent in auth.can, so the nav's emergency-policy flag is always false
- **Area:** Navigation › Settings
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Middleware/HandleInertiaRequests.php:641-673, resources/js/lib/emar-navigation.ts:52, resources/js/lib/emar-navigation.ts:124, resources/js/lib/emar-navigation.ts:176, resources/js/lib/emar-navigation.ts:600-604, app/Http/Controllers/Emar/MedicationSettingsController.php:778
- **Scenario:** A user granted only medications.emergency_policy.manage plus all-Sites access, with no settings.manage or audit.view, can edit the policy server-side. The Settings hub and the 'Emergency access policy' view stay hidden because the flag never arrives. isFrontlineMedication also never counts it.
- **Fix:** Either share the server value (canManageEmergencyPolicy) in auth.can and bump PERMISSIONS_CACHE_VERSION, or remove the dead flag from the nav.
- **Collision:** app/Http/Middleware/HandleInertiaRequests.php (High #4: workforce bumps PERMISSIONS_CACHE_VERSION v11→v12)

### EA-213 · Legacy medication and emergency-access notification event keys are missing from config/notification_events.php, and notifyMedicationEvent skips preferences entirely
- **Area:** Notification preferences
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** config/notification_events.php:36-39, app/Http/Controllers/ClientMedicalController.php:278, app/Http/Controllers/ClientMedicalController.php:390, app/Http/Controllers/ClientMedicalController.php:574, app/Http/Controllers/ClientMedicalController.php:810, app/Http/Controllers/ClientMedicalController.php:948, app/Http/Controllers/ClientMedicalController.php:988, app/Http/Controllers/ClientMedicalController.php:1223-1276, app/Http/Controllers/MedicationAdministrationCorrectionController.php:281-299, app/Services/Medication/EmergencyAccess/EmergencyAccessNotifications.php:82, app/Services/Medication/EmergencyAccess/EmergencyAccessNotifications.php:91-101
- **Scenario:** A provider manager wants to stop or route 'Emergency access started' or 'Medication correction pending approval' bells. These keys never appear in the preferences UI because it is built from notification_events.php, so no user or role preference can be set. The legacy ClientMedicalController notices (medication.created and others) bypass NotificationService::applyPreferences completely, so even a stored preference would be ignored.
- **Fix:** Add these keys to the 'Medication' group, or retire the legacy routes, which have no UI (6.6). Route notifyMedicationEvent through applyPreferences.
- **Collision:** Low: config/notification_events.php (H&S lane adds a 'Tasks' group)

### EA-214 · The Orders 'To check' count disagrees with the To check list, which also includes rows awaiting written confirmation
- **Area:** Orders (P04) UI
- **Audit dimension:** workflows-leads
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Http/Controllers/Emar/MedicationOrdersController.php:73, app/Http/Controllers/Emar/MedicationOrdersController.php:89-92, app/Http/Controllers/Emar/MedicationOrdersController.php:496-508
- **Scenario:** 1. A house has 2 versions waiting for a check and 3 checked phone orders waiting for written confirmation. 2. The 'To check' tab badge shows 2. 3. Opening view=to_check lists 5 rows. Leads can't tell which rows actually need a check, and the written-confirmation rows also have their own 'written' count.
- **Fix:** Use the same predicate for the badge and the list. Either pass checksOnly=true for the to_check view and keep written confirmations under their own filter, or count with the same clause the list uses.

### EA-215 · The reload button on the page-load failure screen silently discards the in-memory recovery for an unconfirmed dose, although the dialog told the user to 'keep this tab open'.
- **Area:** Page-module load failure × recording recovery
- **Audit dimension:** states
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/inertia-pages.ts:10-19, resources/js/components/page-load-error.tsx:5-28, resources/js/components/emar/record-dose/recovery.ts:19-34, resources/js/components/emar/record-dose/record-dose-dialog.tsx:2800-2806
- **Scenario:** 1. A worker closes an 'uncertain' dose with 'Keep draft and close' and is told 'Keep this browser tab open; check the chart before recording anything else for this dose'. 2. The next navigation's page chunk fails to load. PageLoadError ('This page couldn’t load') offers only 'Reload this page', with no app shell and no mention of the unconfirmed attempt. 3. Reloading (after the generic browser 'Leave site?' prompt) wipes the in-memory recovery map. Reopening the dose gives a fresh form with a new request ID, and the original attempt can no longer be checked from the dialog.
- **Fix:** In PageLoadError, read `pendingDoseRecoveries()`. When any exist, show 'You have an unconfirmed dose for X — check its chart before reloading' with a link to `/emar/mar?client_id=…&tab=history`, and offer navigation back to Meds today rather than only a reload.

### EA-216 · Person record title chip shows the raw client status enum
- **Area:** Person record (P02)
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/record/show.tsx:186-191, app/Http/Controllers/Emar/PersonMedicationRecordController.php:500
- **Scenario:** Opening a medication record shows a neutral chip reading the raw database value (for example 'active') next to the person's name, in lowercase developer wording and the same neutral tone whatever the status.
- **Fix:** Map the client status through the shared label and tone map (status-colors / getStatusColor) server-side or client-side, and show a sentence-case label.

### EA-217 · Row right-click is missing on the person record's section tables, the hub and the Meds today second-person list; correction actions are kebab-only
- **Area:** Person record (P02) / MAR hub / Meds today
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/components/emar/record/history.tsx:112, resources/js/components/emar/record/history.tsx:199-255, resources/js/components/emar/record/safety.tsx:148-219, resources/js/components/emar/record/clinical.tsx:265-275, resources/js/components/emar/record/chart.tsx:255-268, resources/js/components/emar/record/support.tsx:45-51, resources/js/pages/emar/record/hub.tsx:375, resources/js/pages/meds/today/components/second-person-followups.tsx:24-35, resources/js/pages/emar/Followups.tsx:291-345
- **Scenario:** A clinical lead right-clicks a dose in History to 'Request a correction' and gets the browser menu. The action is reachable only through the row's kebab. On the week chart and the clinical tables, rows have no menu at all (actionsFor returns []). The Follow-ups page's 'Effect checks awaiting review' is a bare <ul> with no row menu.
- **Fix:** Pass onRowContextMenu wired to EntityContextMenu with the same actionsFor items on each table. Give chart and clinical rows their approved actions (open dose, record, why), and render the Followups legacy list as an EntityTable.

### EA-218 · The 'As-needed dose over the limit' alert and the critical Control Room 'PRN Over Limit' fire when the daily limit is merely reached, and they are re-raised by the 07:05 daily sweep
- **Area:** PRN alerts
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Alerts/MedicationAlertSources.php:460-472, app/Services/MedicationAlertService.php:299-336, app/Models/ClientMedication.php:579-599, app/Services/Medication/Alerts/MedicationAlertCatalogue.php:227-237, app/Console/Commands/CheckMedicationReviews.php:19-26
- **Scenario:** An order allows 4 doses a day, and 4 were given legitimately between 08:00 yesterday and 06:00 today. At 07:05 emar:check-medication-reviews → generateClientAlerts emits a critical TYPE_PRN_OVER_LIMIT Control Room signal ('PRN limit reached (4/4)'). forClient raises 'As-needed dose over the limit' to the house lead and clinical lead. No dose went past the limit. The alert stays open until the next 07:05 run sees the count under the limit.
- **Fix:** Raise PRN_LIMIT and TYPE_PRN_OVER_LIMIT only from the blocked-attempt path (prnOverLimit and handlePrnOverLimit). If 'limit reached' is wanted, make it a separate, non-critical, dashboard-only state.

### EA-219 · medications.controlled.override was repurposed without a description migration, so Settings › Roles still shows the old meaning
- **Area:** RBAC › Settings › Roles
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Refs:** database/seeders/RbacSeeder.php:322, app/Services/Medication/Controlled/ControlledRegisterService.php:67-69
- **Scenario:** An admin on the .com server opens Settings › Roles to decide who may approve unwitnessed controlled doses. The only key that authorises override_decide is labelled 'Override controlled drug discrepancy blocks', the pre-3 Oct text that stays in DB rows created before then, so they cannot tell this key decides witness overrides.
- **Fix:** Add a small migration that updates the description of medications.controlled.override to the seeder text.

### EA-220 · 'Medication exceptions (7d)' on the Reports hub and the Care-quality report starts at UTC midnight and counts by record creation time
- **Area:** Reports (general /reports hub)
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/ReportsController.php:34-35, app/Http/Controllers/ReportsController.php:72-76, app/Http/Controllers/CombinedReportController.php:128-130, app/Http/Controllers/CombinedReportController.php:159
- **Scenario:** At 10:00 NZDT 9 Oct, from7 = UTC startOfDay of 1 Oct 21:00 UTC, i.e. 1 Oct 13:00 NZDT. The '7d' tile covers about 7.9 NZ days. It counts refused/withheld/missed rows by created_at, so a dose refused on 1 Oct at 08:00 but entered late at 14:00 is counted, and unrecorded doses never are. The number differs from /compliance 'MAR exceptions' and from P09 for the same week.
- **Fix:** Use now('Pacific/Auckland')->subDays(6)->startOfDay()->utc() over scheduled_for (or administered_at), or reuse ComplianceMetricsService::doseTotalsByDay / the P09 dataset so every surface shows the same number.

### EA-221 · The Reports rail shows 'Audit trail' to readers the nav hides it from, and omits 'Protected backups'; the backups entry is shown to finance, who can never use it
- **Area:** Reports & audit
- **Audit dimension:** navigation
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/reports/hub.tsx:755-789, resources/js/lib/emar-navigation.ts:179-181,522-550, app/Http/Controllers/Emar/MedicationReportsController.php:71-73, app/Http/Controllers/Emar/MedicationBackupDeliveryController.php:32-41, app/Services/Medication/BackupDelivery/BackupDeliveryAccess.php:33, resources/js/pages/emar/connected/_entry-points.tsx:53-58,116-121
- **Scenario:** A team lead (reports.view, no audit.view) sees an 'Audit trail' tab on Reports, clicks it and gets a locked message. The sidebar and search correctly hide it. Finance sees 'Protected backups' in nav and search. It opens an always-empty page, because finance can never be a recipient. The Reports rail has no Backups tab, so the rail and the hub map disagree.
- **Fix:** Build the Reports rail from visibleEmarViews(reports hub), so the audit tab is hidden when props.can.audit is false. Decide whether backups belongs in the Reports rail. Use one backups visibility rule that excludes finance-only users, pending §2A.6.

### EA-222 · Finance sees 'Protected backups' in Reports & audit; the page opens but every action is forbidden for finance-only users
- **Area:** Reports & audit › Protected backups (unapproved surface)
- **Audit dimension:** rbac
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/lib/emar-navigation.ts:544-550, app/Http/Controllers/Emar/MedicationBackupDeliveryController.php:32-37, app/Services/Medication/BackupDelivery/BackupDeliveryAccess.php:28-33
- **Scenario:** A finance user (view, reports.view, reports.export) sees 'Protected backups' under Reports & audit and opens /emar/backups. They get an empty schedules/deliveries page. Any recipient search, prepare or download returns 403 because BackupDeliveryAccess::complete refuses finance-only users.
- **Fix:** Hide the view for finance-only users (add a server flag, or apply MedicationReportAccess::financeOnly in index with a 403) as part of the approve-or-hide decision for backups.

### EA-223 · P09 'dose.recorded' events for offline replays carry the sync time as occurred_at and no offline provenance
- **Area:** Reports & audit (P09)
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:1033-1049, app/Services/Medication/Audit/MedicationEventReader.php:27
- **Scenario:** A dose given at 23:30 on 1 Oct offline syncs at 07:10 on 2 Oct. The audit log, filtered by day on occurred_at, shows the dose under 2 Oct, while the MAR shows 1 Oct. The event facts do not say it was captured offline or on which device.
- **Fix:** Set occurredAt to the administration's administered_at, since recorded_at already captures the sync time. Add queued_offline, captured_offline_at and origin_device_id to the facts.

### EA-224 · emar:send-alerts runs the overdue sweep unguarded. One exception aborts low-stock, renewals, refusal-cluster and follow-up alerts for that run
- **Area:** Scheduler / alert jobs
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Console/Commands/SendMedicationAlerts.php:17-31, app/Console/Commands/SendMedicationAlerts.php:41-50, app/Services/Medication/OverdueDoseAlerts.php:102-116, app/Services/Medication/OverdueDoseAlerts.php:391-420
- **Scenario:** A deadlock or bad JSON row in OverdueDoses::at() or releaseEndedSpells() throws. handle() never reaches lowStock(), renewals(), refusalClusters() or overdueFollowUps(), and the overdue medication alerts are not raised either. This repeats every 15 minutes while the bad row persists.
- **Fix:** Wrap the sweep in try/report like the other sources, so the remaining checks still run, and log the failure.

### EA-225 · Medication idempotency prune is scheduled at 02:35 NZ, so it does not run on the spring-forward day
- **Area:** Scheduler / DST
- **Audit dimension:** nz-time
- **Status:** confirm with a failing test first
- **Refs:** routes/console.php:228-236
- **Scenario:** On Sunday 26 Sep 2027, NZ clocks jump from 02:00 to 03:00, so 02:35 never occurs and 'medication.idempotency.prune' does not run that day. Expired ordinary replay bindings remain one more day; it runs twice on the April fall-back day (harmless). This is the only eMAR daily job inside the 02:00–03:00 band; generate-rounds 00:05, stock 06:00, reviews 07:05 and support-reviews 06:00 are all DST-safe.
- **Fix:** Move it outside 02:00–03:59 NZ (e.g. 04:35), or schedule it in UTC.
- **Collision:** Low: routes/console.php (both lanes append at the end; edit is mid-file)

### EA-226 · The Settings subline for 'Controlled-drug balance check overdue' says 'No balance check for 7 days', but the alert follows the configured count policy
- **Area:** Settings › Alerts (UI copy)
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** app/Services/Medication/Alerts/MedicationAlertCatalogue.php:252-262, app/Console/Commands/EscalateOverdueControlledChecks.php:94-105, app/Services/Medication/Controlled/ControlledCountStatus.php:101-134
- **Scenario:** The organisation configures counts at every shift change, or a weekly anchor. Settings tells managers the alert means 'No balance check for 7 days'. In fact it fires whenever ControlledPolicy reports a configured count overdue. With the weekly count 'Not configured' default, it may never fire.
- **Fix:** Derive the subline from the saved count policy, for example 'A configured count (every shift change) is overdue', and show 'Not configured — this alert can't fire' when no policy is set.

### EA-227 · Sidebar overdue badge is cleared only for the recording user on the recordDose path, and fails open to 0 on any error
- **Area:** Sidebar badge
- **Audit dimension:** concurrency
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Middleware/HandleInertiaRequests.php:1350-1353, app/Http/Middleware/HandleInertiaRequests.php:1364-1438, app/Http/Controllers/Emar/WorkerMedsController.php:786-791
- **Scenario:** Worker A records the overdue 08:00 dose. Colleague B on the same shift keeps seeing the overdue badge for up to 60 s. Doses recorded through the MAR, legacy client routes, Fleet transport or paper posting never clear anyone's badge early. If the count query throws, every user sees 0 overdue rather than an unknown state.
- **Fix:** Key the cache by site and date, or bust it for every user on the shift's people from the shared administration saved hook (OverdueDoseAlerts::queueAfterCommit already runs there). On error, return null and show a neutral badge state rather than 0.
- **Collision:** app/Http/Middleware/HandleInertiaRequests.php (High #4: workforce PERMISSIONS_CACHE_VERSION and the devices lane's can keys)

### EA-228 · Destructions page repeats its header numbers as body KPI cards with ad-hoc text-2xl
- **Area:** Stock & controlled › Destructions
- **Audit dimension:** ui-fidelity
- **Status:** confirm with a failing test first
- **Type:** UI
- **Refs:** resources/js/pages/emar/Destructions.tsx:461-489, resources/js/pages/emar/Destructions.tsx:726-765, resources/js/pages/emar/Destructions.tsx:1004-1022, resources/js/pages/emar/Destructions.tsx:1
- **Scenario:** On the Reports tab, 'Live records' and 'Destroyed (30 days)' repeat the header's 'Recorded disposals' and 'Disposed in 30 days' figures in bespoke cards. The same register is also a tab of ControlledRegister, so destructions appear on two pages with different layouts.
- **Fix:** Drop the duplicated StatCards, keeping the breakdown cards. Use ops-stat-card for any residual stat. Per map §2A.12, redirect /emar/destructions to the register's destructions view.

### EA-229 · 'Stock running low' alerts are raised for stopped or ceased orders and stay open forever
- **Area:** Stock alerts
- **Audit dimension:** alerts
- **Status:** confirm with a failing test first
- **Refs:** app/Services/Medication/Alerts/MedicationAlertSources.php:135-165, app/Services/Medication/Alerts/MedicationAlertSources.php:211-214, app/Models/ClientMedicationStock.php:127-130, app/Models/ClientMedicationStock.php:158-161
- **Scenario:** An order is ceased while 5 tablets remain and its reorder level is 10. Every 15 minutes lowStock() finds the stock row and raises 'Stock running low: X for Aroha N.' to the house lead and stock staff. The alert's 'until' is 'Until restocked', so it never closes.
- **Fix:** Add ->whereHas('medication', fn ($q) => $q->active()) to lowStock() (and to stockCheck's expiring list where appropriate), so reconcile() closes the ceased orders' alerts.

### EA-230 · With no witness PIN pepper configured, PINs are stored as plain bcrypt of a 6-digit number
- **Area:** Witness PIN / config
- **Audit dimension:** data-rollout
- **Status:** confirm with a failing test first
- **Refs:** config/medications.php:61-63, app/Services/Medication/WitnessPinService.php:493-501, database/migrations/2026_10_03_210100_add_hash_version_to_user_witness_pins.php:11-13
- **Scenario:** If a database dump leaks (backup, test copy), every user_witness_pins.pin_hash can be brute-forced offline across only 10^6 candidates. That recovers every staff witness PIN, which is the second factor for controlled-drug witnessing.
- **Fix:** Set a server-managed pepper of at least 32 characters (not APP_KEY) on .com and production, after fixing the length and rotation issues above, and have staff re-set PINs over time (pin_v1 rows upgrade on the next set). Show a Settings › Staff & PINs notice while the pepper is unset.

### EA-231 · Write-seam inventory: every route, controller and job that creates or changes doses, orders, stock, controlled-register entries and corrections
- **Area:** Write seams (reference)
- **Audit dimension:** write-seams
- **Status:** confirm with a failing test first
- **Refs:** app/Http/Controllers/Emar/WorkerMedsController.php:627-830, app/Services/EnhancedMarService.php:893-1700, app/Services/Medication/MedicationOrderWorkflow.php:90-460, app/Http/Controllers/Emar/MedicationStockController.php:56-170, app/Services/Medication/Controlled/ControlledRegisterService.php:64-187, app/Http/Controllers/Emar/PersonMedicationCorrectionController.php:19-50
- **Scenario:** Reference list for the retirement plan; the defects are reported separately.
- **Fix:** Use this list as the retirement checklist. The goal is one writer per record type: WorkerMeds/EnhancedMarService for doses, MedicationOrderWorkflow for orders, MedicationStockService for stock, ControlledRegisterService for the register and PersonMedicationCorrectionController for corrections. Move the P09 append into the services rather than the controllers.

