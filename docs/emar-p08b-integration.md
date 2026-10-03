# P08b medication errors: implementation and integration contract

This package implements the medication error record and investigation workflow for one operating organisation across approved Sites. Person readability, canonical medicine/incident ownership, exact controlled-medicine permission and the reporter/account axis apply before rows, counts, search, detail and Tasks are exposed. Legacy organisational columns are preserved.

## Owned behavior

- Reports record when the event occurred in New Zealand time, reach (`no`, `yes`, `unknown`) and harm (`none`, `minor`, `moderate`, `severe`, `death`, `unknown`). The original account is retained. New accounts and investigation history append; the legacy update endpoint appends a note and rejects replacement of the original report.
- UUID receipts bind the actor, person and normalized report payload. Exact retry creates no additional report, account, incident or event. Changed payload under an already-used UUID returns 409 and retains the original. Nearby open reports for the same person and primary medicine offer a neutral duplicate prompt, an account on the existing record, or a separate report with a reason.
- Triage assigns a permitted investigator and due date. The default deadline is the end of the next New Zealand day, unreviewed until Settings records a review. Investigation actions have owner/due/completion records. The Actions & close view includes completed actions and records ready to close.
- Closure requires triage, an owner, completed actions, structured disclosure for errors other than near misses, any mandatory incident, a close note and an actor other than the reporter. Reopening requires an independent actor and reason. Earlier close history is retained.
- Moderate or greater harm, or the P01 more-than-ordered source, requires one canonical linked incident. Actual immediate action must be recorded before a serious incident is created. Its narrative remains inside the medication record. Incident, Timeline, H&S and operational projections receive generated neutral text.
- Closing a medication error does not bypass Incidents authority, reviewed status or the authoritative follow-up/investigation/H&S close gate. An incident that cannot close remains open and is presented as ready for Incidents review.
- SAC uses P09 `RecordsReportingSettings::confirmation()` inside the locked close command. A proposal is not confirmation; the choice is explicit, severe/permanent harm requires 1 or 2, and a near miss receives no SAC. The stored confirmation includes actor and time. No historical SAC backfill occurs.
- Settings recipients receive neutral configured alerts, including recipients without controlled-medicine access. Error Tasks and investigation-action Tasks use neutral descriptions and link to the permitted error record.
- Register rows are server-paginated in groups of 25. Trends use occurred time with reported-time fallback, New Zealand day/week boundaries, and no future records. Cross-person controlled rows are omitted. Archived medicine classification still governs attachment permissions.
- `emar:review-copied-error-incidents` is read-only. It lists references, incident IDs and copied field names, including soft-deleted errors, without printing private narrative. Approved redaction remains an audited Incidents edit.

## Transaction and shared-service seams

`MedicationErrorReporter::report()` is the central report/incident path for the page and P01. Its caller must authorize and provide the governing transaction. P08b controller commands append P09 events once, after all domain writes and receipt writes. No locks or domain writes follow `MedicationEventRecorder::appendMany()`. Recorder failure rolls back the entire report/account/incident operation.

P01 must append the report's newly created error entries from its final transaction after its recording receipts and other writes. The reporter itself deliberately does not append P09 events, to avoid taking a chain-head lock before P01's remaining domain work.

Investigation actions remain `medication_error_actions`, with neutral `med_error_action` Tasks. P08a's `MedicationFollowupService` does not currently support an error-investigation action type. Do not invent an `error_action` follow-up type or duplicate the existing medication follow-up identity.

The shared permission prerequisite is P05 source `43c1a0bb3cb134d1e1855fc218e9d59cf640e0b6`. This worktree has the identical service change as `85fab7f50`; its P05-only test was omitted because that package is not present here. Main should retain its integrated P05 commit once, not cherry-pick this prerequisite again. P08b adds the remaining report-reader and linked-Incidents permission evidence used inside its command callback.

P09 prerequisites already included in this worktree:

| Source | Local prerequisite | Purpose |
| --- | --- | --- |
| `466ce69df` | `899d3815f` | Event recorder and site chain heads |
| `d0c3d2b19` | `86445c3e9` | Recorder concurrency/deadlock contract |
| `9bfb5f988` | `fab56b030` | Reports/export and SAC Settings service |

Keep both `errorTriage()` and `RecordsReportingSettings::group()` in `MedicationSettingsRegistry` when resolving combined integration.

Owned P08b commits before the final failure corrections: `84b32b1ec`, `26d9b45b2`, `ccc596036`, `a166a8c03`. The imported Tasks privacy regression in `a166a8c03` came from Main security source `89535b640`; Main already has that regression as `d14c1def1`, so retain one copy.

## Verification evidence and remaining release gates

The focused backend run executed exactly `a166a8c036e35162a40045367af43f7decc0a73a` with a physical local vendor directory and verified application base. It finished with **10 failed, 41 warning-marked tests, 521 assertions, 584.30 seconds**, without a displayed skip.

Six failures came from missing per-person permission evidence in the locked command actor, causing valid operations to return 404. One assertion compared the New Zealand deadline with an uncopied UTC cast. Three legacy assertions incorrectly retained the old optional-incident behavior for moderate harm. The corrections retain operational-only historical rollback fixtures where that old state is the subject of the regression. Successful command tests now require redirects as well as absence of validation errors, and triage verifies its persisted stage.

The 41 warnings came from the absent `.env`. A minimal ignored, synthetic testing file now supplies only `APP_ENV=testing` and `APP_DEBUG=true`; primary or production configuration was not copied. The corrected snapshot has not had another backend run: Main explicitly holds worker database tests and owns the consolidated run after integration.

Failure corrections are owned commit `2f66414ccb46737ff43de438963f34ebad84d227`. PHP syntax passed for all seven changed PHP files and the commit passed whitespace checks. The one authorized scoped frontend formatting/syntax attempt entered the repaired queue and exited before inspecting the source because the primary checkout's Prettier module was unavailable. It is not a frontend pass. The final formatting/handoff commit makes only TypeScript whitespace and documentation changes; Main retains the full frontend gate.

Main's consolidated run must include:

- `tests/Feature/Emar/MedicationErrorWorkflowTest.php`
- `tests/Feature/Emar/MedicationErrorsTest.php`
- `tests/Feature/Emar/MedicationErrorTaskPrivacyTest.php`
- the shared Incidents closure and P09 export/Settings tests selected by Main

Additional regression coverage in the corrected snapshot includes archived controlled-medicine attachment permissions, completed actions remaining visible, and neutral manual Control Room escalation with denial of an unassigned same-Site person.

The following combined-package items remain release gates rather than claims of completion:

1. Wire P01 and P08a report entry points to the shared form's person/medicine/occurred-time prefill and P01's final recorder append. P08b supports the form props and central reporter but does not edit those packages' recording transactions.
2. Reconcile `/emar/errors/export` with P09's common export purpose and final current-evidence release audit. P08b's current neutral CSV audits purpose and omits free text, but does not yet use `MedicationExportAudit::record()` or P09's shared export button. Resolve manager/auditor export authorization explicitly rather than silently granting report export.
3. Apply the P08b person/reporter scope to P09 medication-error Reports/builder/exports. P09's current dataset also uses reported time, treats `resolved` as terminal, and links with `error_id`; align it with occurred-time fallback, only `closed` terminal, and `/emar/errors?error=`. The legacy CSV's narrative is neutralized here, but its shared final release guard belongs to the combined export work.
4. Join P07b discrepancy/loss incidents into the approved unified medication Incidents view. P08b currently projects linked medication-error incidents only.
5. Confirm the approved multiple-medicine/off-chart report detail and nonblocking controlled-medicine name prompt. The current form has one canonical primary medicine or no chart selection; its recognized controlled name check requires that named order to be selected. This is narrower than the approved prompt that permits leaving the wording as entered and needs an explicit combined design/privacy resolution.
6. Main owns complete TypeScript/build and browser verification at 1440/1280 widths and 200% zoom. No worker frontend server, full TypeScript run or build has been started. Backend success, browser acceptance, push and deployment are not claimed by this package.

No primary checkout files were changed, and this branch has not been pushed or deployed.
