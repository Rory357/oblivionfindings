# P10 completion contract

Owned checkout: emar-p10-breakglass/oblivionfindings. Branch: codex/emar-p10-completion. Base: 9747cf7cb. Main owns integration, push and deployment.

## Dependencies and owned changes

P09 foundation 466ce69df is cherry-picked locally as 8cc86d354. P08a foundation 8d799fd36 and batch correction a5b12397f are cherry-picked as a8cd69e22 and b78bef5a0. Main integrates those original dependencies once. P10 policy columns/model snapshot are the independently reusable owned commit 2a11723e5; P11 B3 owns the policy editor, save validation, revision history and authorization.

Source completion must collect P08a events and the P10 event, then appendMany once at the end of the same domain transaction. Paper giver/witness confirmations use their distinct immutable evidence and do not reuse PIN-2 nomination confirmations.

## Grant lifecycle

EmergencyAccessService is the canonical mutation adapter: start, extend, end, review. The policy snapshot fixes duration, second-person mode, review deadline and repeat rules for each grant. Start uses the Client mutex, current user/profile/Site authorization locks, server-validated acknowledgements and WitnessPinService. Extensions open only in the final ten minutes and respect the frozen cap. End retains history and review work. Review corrections append immutable rows and require the exact latest review id.

The page gate remains breakglass or audit.view. End another staff member's grant uses breakglass.end for admin/provider_manager/coordinator/clinical_lead. Care discovery/start use direct approved Site membership; reviews use existing oversight Site scope and an independent reviewer. Repeat acknowledgements use Site/staff keys and an access-id watermark so another grant in the same second resurfaces.

The daily report now uses P11 MedicationAlerts, saved channel/recipient rules, a per-day/per-Site deduplication key and permission-filtered independent review recipients. The legacy policy save endpoint returns a conflict directing users to Medication settings.

## P01/P02 recording seam

EmergencyAccessStrip accepts {id, client_id, client_name, expires_at, can_extend}, onExtend, onEnded and onStartAgain. Mount beside the existing form. onStartAgain must open the contextual wizard in place so the dose draft survives; default navigation is for the grant list. Context-selected people stay fixed.

MedicationEmergencyAccessEnded is a typed 409 response (code emergency_access_ended). It and OfflineGrantEvidence are supplied but require the P01 canonical recording connection. No live offline acceptance is claimed.

OfflineGrantEvidence requires queued_offline === true, an explicit-timezone captured_offline_at inside [grant start, actual end), the clinical time inside that window, and no required second person. P01 must resolve the prior canonical grant under current actor/client Site and recording/competency checks, retain its id on the administration, and include sent_after_grant_ended in review evidence/P09 facts. A retrospective ordinary online claim cannot use this route.

## Paper and pack seam

DowntimeService and PaperEntryService own immutable paper evidence, preview identity, conflicts, accountable giver/witness confirmations and successful MedicationPaperPosting links. Evidence alone is never labelled Entered from paper. Duplicate paper or synced administration evidence requires an explicit reviewed resolution link; it does not create another administration.

PaperAdministrationWriter delegates unchanged ordinary scheduled orders without a second person to EnhancedMarService only after the canonical recording-authority check at the actual clinical time. Controlled, changed-order and historical second-person posting stays held pending P07. Historical PRN entries stay held pending safety checks anchored to the clinical time. Refused/withheld entries stay held pending structured historical reason mapping. No historical stock insertion, balance replay or invented witness is performed.

P07's historical adapter must accept canonical paper identity, actual clinical time, accountable giver confirmation and signed witness confirmation; run register/stock/order/competency and closing-count checks under canonical locks; and return canonical administration/register evidence. P10 writes MedicationPaperPosting only after success and appends its audit event last. Failed/unavailable adapters leave evidence pending with no clinical posting.

The pack uses DoseSlotProjection and MedicationRuleService requirements, NZ today/tomorrow, one approved house, sticky controlled-view concealment and the recorded purpose Downtime. It uses the existing medications.reports.export permission. Main/P09 owns Print & exports placement/shared export dialog. P01 owns offline pack cache/opening.

## Follow-up and history connections

TaskAggregator registers a concrete overdue independent emergency review provider and separate named paper giver/witness providers. Stable source keys link to scoped ?grant and ?paper_entry details. The paper deep link opens the scoped evidence. The emergency detail combines retained legacy activity with matching P09 events, checking canonical Client/Site and controlled-view access.

## Verification and remaining integration

The first focused lifecycle/legacy run produced 15 failed cases and 22 warnings; the candidate repairs fixtures and changed-contract expectations plus review findings. Corrected combined focused PHP tests are pending through the shared heavy-lock, with a physical owned vendor and isolated synthetic database. No live clinical/schema writes.

Downtime checks passed: 23 PHP syntax checks, four TypeScript syntax checks, focused ESLint/Pint and ten pure tests/twenty assertions. Root TypeScript transpile checks passed for ten files. These checks do not establish full type/build or browser/PDF acceptance.

Main owns combined frontend type/build and browser acceptance at 1440, 1280 and 200%, plus mobile behavior and PDF visual verification.

Remaining shared work: P01/P02 recording strip, typed expiry and offline envelope; historical P01/P07 paper adapters; P09 history export/Print & exports placement. P09's recorder currently excludes soft-deleted Client/Site rows, so historical close evidence for removed records needs a shared P09 decision. The expiry job isolates failed grants, continues valid ones and reports the gap without inventing an event.

## Candidate follow-up review

The source review after e5cad7de0 found four defects now repaired: locked authorization evidence includes breakglass.end; overdue Tasks use the grant's frozen review_days before an expiry sweep; repeat acknowledgements recheck current authorization and canonical Client/Site ownership under Client -> grant -> user -> Site locks; manual end/review reject withdrawn account approval. Focused regressions cover those boundaries. Offline capture parsing also rejects normalized invalid calendar dates and accepts explicit millisecond timestamps; unit fixtures use a deterministic clock and date format. These follow-up cases remain unrun while Main repairs the shared heavy-command guard.
