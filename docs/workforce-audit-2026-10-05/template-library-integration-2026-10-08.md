# Roster template library integration — 8 October 2026

Status: implementation and backend/frontend checks are saved locally; browser write journeys and main integration are still pending. This report does not certify the full Workforce programme.

## Scope

The existing roster Templates tab and existing templates URL now share one library and command coordinator. Users with template read permission can use that existing URL without needing broader roster access. No additional sidebar entry is introduced. The standalone page uses PageHeader; create and edit use WizardShell, searchable people/context pickers, minute-accurate time controls, a complete support-field review and explicit discard recovery.

Read and write capabilities are separate for view, create, edit, duplicate, delete and apply. The UI uses the current scoped template options rather than the broader roster pickers. The backend authorizes the current actor and complete existing pattern before replacement, copy or deletion; reports permission does not become a Site bypass. Existing canonical and controller/request permission aliases retain their boundaries. Control Room, eMAR and independent Fleet/H&S work remain outside this change.

Create, update, duplicate and delete now share current authority, whole-aggregate source revisions and atomic persistence checks. Saved feedback requires a physical-root, requester-only result for the exact action, attempt, source and normalized values. An unchanged pattern does not replace its rows. Copy preserves the complete stored pattern; deleting soft-deletes the parent and retains rows and existing roster history. Apply and recurring backend writers remain for the next packages, with their original method bodies preserved.

## Frontend recovery and field parity

One coordinator serializes commands and reloads, takes a synchronous lock before hashing, rejects stale/missing source authority, ignores late callbacks after actor changes/unmount, and catches only its own invalid-response event. A matching committed receipt can confirm a save even when the following list cannot be verified; that condition still requires a current reload before another action. Generic success text never confirms a write.

Validation and uncertain responses retain the editor and entered fields, including when returned library props are missing. Unknown saves require an explicit current library read and user review before resubmission; a changed source cannot be silently overwritten. Delete confirmation stays open until the exact committed result is received. Card keyboard events do not turn a nested menu keypress into opening the card.

Preserved fields: Client, staff/open assignment, service context, weekday, start/end minutes and overnight meaning, cadence, active state, sleepover/on-call/lone-worker flags, nullable versus zero expected break, ordered skills, location and notes. A real edit failure for stored HH:mm:ss values was corrected by presenting their exact HH:mm minutes. PHP/TypeScript normalization is pinned to the same Unicode/zero-value vector; raw source revisions still include full stored clocks and child IDs.

## Evidence so far

- Focused frontend: 34/34 tests passed in test-results/template-library-ui-fifth.json. The earlier second attempt exposed three failures caused by the actual stored-clock edit issue; the fourth attempt exposed two asynchronous test synchronization failures, corrected by waiting for the transport boundary. Those failed artifacts remain retained and are not counted as passes.
- Full TypeScript and final scoped lint: both terminal0 with empty final diagnostic logs.
- Backend: 62 unique cases / 1,745 passing assertions, reconciled from the original 61 passes / 1,722 assertions and one corrected fixture case / 23 assertions. This is not a fresh single run of all 62. The original failed case's 21 partial assertions are excluded. The repair preserves every domain assertion and places the complete queue baseline after the legitimate HR profile setup recheck. Both native runs are closed, their private schemas/connections removed, backend pins unchanged and five protected previews preserved. The corrective scope explicitly excludes concurrent frontend/docs edits. Composite receipt: `test-results/workforce-main-templatelibrary62-unique-final-receipt.json`, SHA256 `b3ee9e24a91cd3b597acd50cf37bdbb7999adc11454bd8a3c7a68ba3a4a3ba2a`.
- Production build: terminal0 in5m54s; assets/app-CEdIy7Iz.js; existing chunk-size advisory.
- Browser: the user explicitly authorized the seeded development login on 8 October. Demo Admin is authenticated on the isolated port8768 preview. The Templates tab loads with the selected week retained; this account currently exposes read-only template controls, so positive create/edit/copy/delete browser acceptance is not yet claimed. No account or grants were changed.

The current shared-header regression gate passes 81 cases across ten suites, including the template coordinator/library/helpers and the two added roster/standalone week-preservation cases. Initial 34-case evidence remains historical. Final header browser/build acceptance is tracked separately in header-consistency-2026-10-08.md.

## Prior evidence integrity note

A list-only test enumeration reused the older Suggestions113 bootstrap and overwrote its auxiliary bootstrap-proof JSON. The original auxiliary bytes are unavailable and were not reconstructed. The accepted113 receipt and all eight receipt-pinned raw artifacts remain identical; the original execution and cleanup were independently verified. The current incident note is test-results/template-library-integrity-implementation-20261008/list-bootstrap-auxiliary-incident.json. Subsequent list-only enumeration uses the strict no-artifact bootstrap.

## Remaining programme work

Finish this gate and browser verification, then integrate verified work into local main and GitHub main when the required checks are complete. Continue template apply/recurring scheduling, civil-time and full cross-module acceptance work from OUTSTANDING-WORK-HANDOFF.md. Held qualification/modified-duty policy decisions and protected cross-module boundaries are unchanged. This unit does not close the active goal.

The read-only browser journey confirmed all template row details and selected-week retention. Its screenshot exposed duplicate close controls (the shared Dialog default plus the existing labelled custom button); the redundant default is disabled in source, awaiting the next template visual build. This is outside the header-only delivery.

After the duplicate Close correction, the three template suites pass36/36 in test-results/template-library-ui-sixth-20261008.json. The current header-only delivery is on local/GitHub main atbc22a918e7d67217c8f844d4af11594a33a45f0a; it does not deliver this pending template unit.
