# W13 — canonical API intake and public conversation

Status: Implemented and locally Verified for this bounded canonical adapter slice on 11 September 2026. Backend, actual-worker concurrency and desktop browser journeys passed; owned cleanup and independent postflight passed. Full W13/E12 and the goal remain incomplete.

## Scope

This slice extends the existing service identity/API request and ticket command records. Authenticated API create now calls canonical intake with a UUID derived from the persisted API receipt and identity. Ticket source remains `system`; created activity records trusted `service_api` provenance. Routing, priority, SLA evidence, command receipt, required audit and notification intent commit with the API result.

API public comments now use the canonical conversation command. Merged/settled work is rejected, public speaker/next-response/version and first-response SLA state are updated, requester-wait resumes through existing rules, and notification intent is durable. The current body-only contract takes its version after locking the authorized ticket; client-versioned updates and transitions remain later work. Post-root-commit notification draining uses the existing ticket outbox. API success publication requires a positive integer result ID bound to the canonical ticket/public comment; malformed completion cannot commit work with an unusable receipt.

Changed production files: `ItTicketCommandChannel`, `ItTicketIntakeService`, `ItApiWorkItemService`, `ItTicketInteractionService`, `ItApiWorkItemController`, `RecordItApiRequest`. Focused paths/hashes are recorded in `w13-api-canonical-source-hashes.json`. No new migration or UI change in this slice. Working database migrations17–23 remain unapplied. Ten protected design files match the saved baseline (`w13-api-canonical-protected-design-check.json`).

Two authorized Terra High agents supplied bounded tests and independent review. Parent owns production integration, verification commands and browser. No separate app tasks or pins were created.

## Initial failures and correction

Initial guarded25845/tokenit_de5f601ffde94e41 stopped after two assertion failures and one test setup error. Its full14-check postflight confirmed exact schema absence. Diagnostic JSONL retains the executed test outcomes; unexecuted later files are not counted as passed.

- The new requester-wait test force-filled a pause on a legacy factory ticket without a captured SLA policy; production correctly refused to invent measured minutes. The test now captures the policy, enters waiting through the canonical model and advances ten deterministic business minutes before the API reply. The measured-pause expectation remains.
- The older access-boundary test expected a surviving created ticket after final authority rejection. Atomic publication now rolls that write and its in-transaction authority mutation back. The corrected test requires no ticket and a known rolled-back404 receipt, then safe replay of that refusal.
- The old routing email fixture passed a bare Message-ID, which the current RFC parser correctly quarantines. It now supplies brackets and explicitly asserts successful ingestion before checking common fallback routing.

These corrections did not require production changes. The nine-file corrected suite13488/tokenit_7f28c13bf5574f58 exited0:117 tests,1187 assertions, zero failures/errors. All14 postflight checks passed, including exact schema absence. `w13-api-canonical-final.txt` and its diagnostic JSONL are authoritative. Counts overlap the initial run; they are not summed as unique coverage.

## Remaining verification and next slice

Standalone13860/tokenit_80f13c1c293b4615 exited0:1 test43 assertions. Two actual authenticated workers at the held actor mutex converged on one canonical command receipt/outbox intent as well as one ticket/API receipt; post-commit response loss/replay also retained one result. All14 postflight checks passed, exact disposable schema absent. `w13-api-canonical-concurrency.txt` and token diagnostic JSONL retain the results. This is a real transaction/HTTP-worker test, with a synthetic post-commit exception rather than a killed server or live provider.

The opt-in `ApiFixtures` browser mode creates two synthetic service identities and uses real authenticated HTTP-kernel requests/replays; bearer values stay in memory and are not saved in readiness evidence. Its desktop ticket/reply/privacy journey is pending. No browser success is claimed from source review. Initial launch was rejected by automatic review because a non-authenticating ownership run ID was interpreted as a credential; source checks proved it names only the owned schema/root/cookie name. The unnecessary extra output/file was removed and the reviewed launch88887 was accepted. No bearer credential is emitted.

Current RBAC role-permission and Site-state serialization remain open. Actor User locking covers cooperative profile writers, but current role-permission/Site writers can change other authorization evidence. The next slice must audit the complete lock order, extend canonical evidence locking and prove actual writer/command interleavings; a test that merely revokes before the first authorization read is insufficient. Versioned update/link, rotate/transient credential publication and scoped Operations recovery remain later W13 acceptance. Provider/operational W11–W12 dependencies and the final release gate remain open.

## Desktop browser evidence

Bootstrap88887 exited0, owned run31e0331788aa4417/PHP17748, schema `oblivion_it_draft_browser_31e0331788aa4417`, fingerprint45b1fcef78c417a483eb6cc9f7a5f0f6a5fdde1de84db4a2811a3fa9cc08081c. Runtime endpoint verified exact checkout, isolated storage/schema, real CSRF, array mail/sync queue and current asset manifest0c4f05f87e230e11d4fd82e56130427511d5c23d97d46b93f051c10c188b13f5 (`app-xgo-TVyg.js`). No asset rebuild was required because this slice changes backend adapters only.

- Actual HTTP-kernel fixture: create201/ticket8, same-key201 replay/ticket8; public-comment201/comment1, same-key201 replay/comment1. One ticket, one comment, two API receipts, two canonical receipts, two delivery intents, first response recorded, next response requester. No provider access; local accepted status does not establish external delivery. Readiness and runtime evidence are in `w13-api-canonical-browser-bootstrap.txt` and `w13-api-canonical-browser-runtime.json`.
- Owned Codex in-app tab38 at `http://127.0.0.1:8766/it/tickets/8`, synthetic cover requester: original API report and exactly one technician reply rendered. Desktop screenshot inspected inline; no saved PNG claim. First-response clock showed Met with its actual completion time.
- Entered a public requester response and submitted with Ctrl+Enter. UI showed the persisted second comment and correctly stated that no email notification was requested (no assigned agent/routed owner in this fixture). Full reload retained both comments and an ended draft, preventing accidental resubmission of that draft.
- Activity navigation to `?tab=history` showed canonical intake/priority/routing, first public response, and “added a public update through an approved API”. No secret/request payload dump appeared.
- Keyboard End/Return logout; signed in as unrelated request-only actor. Direct ticket8 navigation returned concealed404 with no ticket data. Navigating to own ticket1 recovered the permitted requester workspace; staff Work/Setup/internal-note controls were absent.
- No browser resize. Owned tab38 closed; original user tab3 preserved. No new UI enhancement claim is made for this backend slice. Exact guarded cleanup was then started; terminal and independent postflight remain to be recorded below.

Final cleanup22087 exited0. `w13-api-canonical-browser-cleanup.txt` confirms exact schema and owned directory removed without changing Herd/other databases; independent `w13-api-canonical-browser-postflight.json` confirms both absent. No active test/import/build/owned browser runtime or worker process remains. Final16-file source snapshot retains unchanged passing production/tests and the reviewed browser helper sources; protected designs unchanged. Scoped Pint/diff checks passed.

Precise next step: inspect the existing `app/Services/AuthorizationEvidenceLockService.php`, `ItWorkAccessService::approvedSiteIds/staffCanAccess`, `ItApiWorkItemService::executionAccount/authorizedTicket/canCreateWithScope`, RolesController permission writer, PeopleMutationLockService/profile writer and SiteController lifecycle writer. Decide the complete consistent User/identity/RBAC/profile/Site/ticket order before edits; reuse evidence locks and test an actual Role/Site writer blocked by a held command, plus writer-first current denial. Do not treat ordinary pre-lock revocation as proof of race safety. No new browser environment is active or reusable. W13 update/link/rotation/transient secret/Operations, remaining W11–W12 operational criteria and full release gate remain open.
