# Current workload checks for accepted assignments

8 October 2026. Follow-up to local checkpoint 1957a8094. The scoped implementation has passed corrective native and frontend checks. This batch has not been integrated into main or pushed.

## Result

Accepted roster suggestions now evaluate the whole accepted set together. For example, 40 existing weekly hours plus two accepted six-hour duties are checked as 52 hours, rather than two independent 46-hour proposals. The configured fatigue rules retain their existing warning/block and override behavior; this change adds no staffing policy.

The assignment writers read current existing duties inside the governed transaction, even when an earlier read or reused evaluator holds an older snapshot. One policy snapshot governs an accepted batch. Only accepted choices contribute; current run, source, worker and permission checks still apply. A prepared workload must match the exact candidate and source. The writers check what was actually persisted, and a late refusal or altered save rolls back earlier assignments and their related changes.

Single-shift creation and editing use the same current-workload preparation where an eligibility decision is required. Existing saved-warning receipts, governed warning retry, manual/source-owned tasks and coverage-hold behavior remain intact. Ordinary advisory evaluation retains its existing API and read behavior.

Suggestions show the full start and end dates, including year, in the configured worker timezone. Calendar week dates remain date-only values. A generating run refreshes the timezone with its other data; missing or invalid endpoints are labelled explicitly. Existing actions and permissions are preserved.

## Verification

- Seven focused UI cases pass: three existing action-sequencing cases and four date/time/refresh cases. The process timezone is America/Los_Angeles; these are rendered-component checks, not browser proof.
- Whole-application TypeScript and scoped ESLint pass.
- The final frontend production build passes in 4m17s, producing app-DJv_3VCN.js. The existing chunk-size advisory remains. The later repair changes backend eager-query handling and test fixtures only; frontend source and route definitions are unchanged.
- Initial native gate: 76 cases, 1,584 attempted assertions, 3 errors, 10 failures and 2 risky tests. It is not accepted as a passing run. Full process/schema cleanup was independently confirmed; 5,667 frozen files and five protected previews remained unchanged.
- Corrective native acceptance: 30 cases / 823 assertions, comprising all 27 new cases / 767 assertions and three existing lifecycle assignment cases / 56 assertions. No failures, errors or risky cases remain in that run.
- Earlier unchanged preservation: 46 cases / 990 assertions from the original run. The exact reconciled set is 76 distinct scenarios / 1,813 passing assertions across the two runs; this is not a fresh full 76 run. The repeated 17 earlier passing cases / 362 assertions and 13 failed partial cases / 232 assertions are excluded from the reconciliation.
- Independent final cleanup confirms all seven owned processes, the private schema and its connections are gone; 5,681 frozen files and five protected previews are unchanged. Missing isolated environment-file warnings are retained in the raw logs.

The initial run exposed a real framework mismatch: an eager-loaded client constraint receives a Relation, while the current-read wrapper accepts a query builder. Existing duties therefore produced a safe unavailable result. The correction extracts the relation query before applying the same current-read protection. Empty-duty cases did not encounter this branch.

The test corrections retain the original assertions. Two lifecycle fixtures distinguish their one assignment decision from the legitimate deferred eligibility refresh. The concurrency fixture distinguishes Symfony's Windows command wrapper from the actual PHP child while retaining exact process ownership, private database, lock-order and cleanup checks. Raw failed results remain available.

## Evidence and boundaries

Ignored test-results artifacts retain the initial workforce-main-acceptedworkload-final logs/XML and cleanup, the reviewed application correction, source/fixture reviews, and accepted-workload-suggestion-ui-final-acceptance-20261008.json with its actual UI/type/lint/build outputs. Corrective evidence is under workforce-main-awcorrective30-final.*. The exact identity reconciliation is workforce-main-acceptedworkload76-unique-final-receipt.json; it preserves the original failure and source transitions.

No schema, qualification, modified-duty, clinical-access or Control Room policy changes are included. Control Room remains independent. The unrelated FleetRealtimePrivacyTest.php work is excluded.

Browser verification still requires the user's outstanding login choice. The earlier browser helper correctly rejects changed source pins and remains unexecuted. No account/grant change, browser Shift write or main push is claimed.

Current-capacity memo and reservation reads, other assignment producers, recurring/template integration, suggestion privacy/action-receipt integration and the wider module/role acceptance matrix remain separate work. A traced historical Timeline staff-name snapshot limitation also remains separate; this batch does not certify that display metadata.
