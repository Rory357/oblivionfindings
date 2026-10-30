# Document workflow and governance — 16 October 2026

Scope: approved audit items 6 and 16, plus document/publication/delivery evidence contributing to item 18. One operating organisation; current staff, roles, approved sites, canonical ownership and direct-object checks remain the access boundary.

## Delivered contract

- Existing `POST /company-hub/documents` accepts optional category, document type, collection, review date, audience, related teams, approval requirement and authorised ownership change. It creates a private canonical knowledge draft. All supplied metadata participates in the existing request fingerprint; reusing a receipt reference for different metadata fails with 409. Existing receipts without metadata retain their original fingerprint format.
- `GET /company-hub/documents/options` provides authorised organisation choices. Existing upload receipts add validated compact `details_url` and `context_url` destinations.
- Existing document context includes saved `metadata`, eligible `owners`, `readiness`, advisory `governance_warnings`, current capability flags and compact update/publication URLs.
- `PATCH /company-hub/manage/{page}/documents/{media}/details` checks the current actor, page version, document generation and page/file ownership; saves through `HubPublishing::save`; and uses canonical `changeOwner` for an ownership transfer. It retains the authored page structure. A standalone uploaded resource synchronises its page title and summary with its file; an attachment inside a larger page does not rename that page.
- `POST /company-hub/manage/{page}/documents/{media}/publication` accepts `submit` or `publish` and the same actor/version guards. It delegates to canonical immutable page submission/publication, including readiness, approval separation, audience, scheduled release and expiry. Review, schedule, archive and discarded-draft locks remain effective. Replacement history and stable document reader URLs remain intact.

## Compact workspace

The upload queue opens the confirmed compact document destination. The existing document editor now provides details, collection, owner, review date, audience, related teams, approval requirement, save, submit and publish controls alongside replacement history. Date and selection inputs reuse the existing shared Hub controls. Publishing uses the shared confirmation dialog with its audience and effect.

Metadata requests send only changed fields, preserving untouched legacy collection labels. Response checks compare JSON object values without depending on MySQL key order. Unsaved details share the existing navigation warning, actor/version protection, stale-response handling and explicit refresh/adoption flow. Uncertain outcomes disable blind retries while retaining local writing.

## Governance guidance

Resource/document readers and authoring readiness receive nonblocking warnings for an unavailable current contact, an unset review date or a passed review date. Reader metadata includes an owner work-profile URL only when that owner's current name passes staff/site visibility checks. No owner, review date or company policy was invented. Missing governance guidance does not change release completeness rules.

## Operational evidence

Page/document schedules use the existing canonical `currentRelease` relation: only due approved revisions become visible, and an expired latest edition does not resurrect an older edition. Event schedules use `HubEventScheduling::publishDue` via the existing `company-hub:deliver-notices` command. Its configured schedule is every five minutes with overlap and single-server guards; the command releases due event editions before delivering permission-aware page/news/review notices.

Current checks cover pre-release privacy, immutable snapshots, stale actor/version rejection, review/schedule/archive/discard locks, file replacement/restore history, metadata receipt replay/conflict, scheduled document activation/expiry, event scheduling safeguards, notice preferences, repeat-delivery protection, and scheduler command registration/failure reporting. Recurring event-change tests explicitly require no notice before release, exactly one database change notice to the registered reader after release, no fresh invite, and no duplicate on another scheduler pass.

No live dispatcher was run and no real recipient received a test message. The application database was not migrated or seeded. These checks prove isolated application behaviour and scheduler registration; they do not prove a deployment host's cron, cache locks or queue workers are running. Root owns live desktop/theme/browser acceptance and whole-app build/type integration.

## Executed checks

- Database-free governance/readiness/reader metadata: final exit **0**, **37 passed / 97 assertions**, 1.03 seconds, including available/unavailable owner-link assertions.
- Focused document UI: final exit **0**, **29 passed** across document editor/upload tests; 9.50 seconds. This includes permission withdrawal with retained local writing and rejection/recovery of another account's organisation choices. Log: `storage/logs/company-hub-document-workspace-ui-final-20261016.log`.
- Focused ESLint exit **0**; assigned files formatted with Prettier and Pint. No frontend build was run by this agent.
- Initial isolated MySQL run: process **614316**, actual exit **1**, **77 passed / 18 failed / 570 assertions**, 744.95 seconds. Log: `storage/logs/company-hub-document-backend-20261016.log`. It exposed a readiness advisory variable-placement error, a MySQL JSON key-order assertion, the shared feed's unqualified joined release columns, and stale recurring-event notification assertions. All identified causes were repaired by their owners. This failed run is retained as evidence, not counted as acceptance.
- Corrected combined social/document MySQL run: session **64264**, actual exit **1**, **156 passed / 2 failed / 1,898 assertions**, 1,354.19 seconds. All document backend behaviour checks passed except the companion-block test's strict comparison of JSON object key order; its values were identical, and the assertion now uses value equality. The other failure was a stale social spotlight permission expectation, corrected by its owner. The command/result/failure summary is captured in `storage/logs/company-hub-social-documents-combined-20261016.log`; it is a summary, not full raw stdout.
- The focused rerun74956 completed: actual **exit0, 2 passed / 34 assertions**, 804.59 seconds. Evidence: `storage/logs/company-hub-social-documents-failed-pair-20261016.log`. Together with the156 combined passes, all158 selected cases have passing evidence across the two runs, not a single clean full-suite run. No backend worker remains.
