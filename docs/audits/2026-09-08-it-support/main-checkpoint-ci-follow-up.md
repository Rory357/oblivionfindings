# Main checkpoint CI follow-up

Checkpoint: 5fa7c6a4db50fa1783200abf4d930f72046ef93e, pushed at the user's request on 11 September 2026. This is a saved implementation checkpoint, not the completed IT release gate.

- Database bootstrap: succeeded, run34584124901.
- Tests: failed, run34584124816. A concise failure-name inventory is in evidence/main-checkpoint-ci-failure-index.txt. Full retrieved log is locally retained under storage/framework/testing/main-checkpoint-ci-failed.txt.
- Linter and visual regression were still running when last checked; neither is claimed passed.

IT-related failures require follow-up before the release gate: ItSecurityDesktopReleaseAcceptanceBoundaryTest expects14 desktop specs while current configuration contains18. ItSecuritySingleTenantBoundaryTest reports two tenant-debt snapshot assertions and several source-string assertions (ticket header/site/task labels, attachment deletion location, waiting projection, routing owner reset/filter fields, saved-filter callback and API identity wording). These are confirmed CI failures, not yet classified as current behavior defects or obsolete source assertions. Inspect each against current canonical behavior and meaningful tests; do not weaken authorization or replace expectations merely to get green CI.

Other failures include Auth destination, Client permissions/creation, HR/H&S/SSR architecture, Attendance, Compliance, Assurance, Catering and Control Room behavior/fixtures. Some responses deny access with404 where tests expect403, but that alone does not establish which contract is correct. No unrelated production changes were made in response to these logs.

Next: finish the active API canonical-URL browser correction, then classify and fix applicable IT/shared-boundary failures in a focused slice alongside the remaining implementation plan. Full-suite and final release acceptance remain open.

Read-only classification during the API browser bootstrap: the two mailbox tenant-word matches refer to services.microsoft.tenant in the Microsoft identity-provider OAuth endpoint and its configuration fixture; they do not create application tenant partitioning. The reported browser-native confirm() is the local async resolution-submit callback in tickets/_dialogs.tsx rendered inside the approved Dialog; it is not window.confirm. These specific static matches are false positives; update the checks precisely while retaining real boundary enforcement. Other tenant-debt findings (two Workforce migrations and Monitoring MetricRetentionTest legacy reads) still need inspection.

Local follow-up: the desktop inventory, canonical Microsoft OAuth-setting matches, payroll column-positioning warning and native-dialog callback name collision have been corrected and verified. Full selected architecture still35passed/11failed; details and remaining scope in evidence/ci-it-boundary-corrections.md. No full CI or release success is claimed.
