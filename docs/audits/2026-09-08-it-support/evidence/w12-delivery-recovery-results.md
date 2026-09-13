# W12 delivery recovery implementation and verification

11 September 2026. W12 / E05 / E11 continuation; whole package and goal remain incomplete.

## Confirmed defects and changes

- Legacy `ItEmailDeliveryService::send()` created queued records without durable dispatch intent and could enqueue inside a caller transaction. It now shares atomic preparation with canonical commands and requests exact-delivery dispatch after the outer transaction commits. Scheduled recovery finds committed intents if post-response dispatch is lost.
- Assignment notices now require the recipient to remain the actual assignee at dispatch and before either notification channel executes.
- Operations offered retry to recipients who had lost entitlement. The DTO now checks current eligibility; the locked retry command still rechecks it independently.
- The existing retry endpoint supports a typed JSON receipt tied to the signed-in actor and original delivery. Legacy redirect callers retain their existing behavior. A lost response must be recovered by reading history; a second retry cannot create another child of the same original attempt.
- UI review confirmed that scoped 50-row history was truncated to 25 in the component, provider acceptance and uncertain submission lacked explanation, and retry lacked pending/error recovery. Terra is implementing these bounded UI changes and tests; parent owns integration and actual browser verification.

## Isolation and current verification status

The working tree's 584 existing changed entries were preserved. No design source was edited. Disposable browser helpers now support exact-token, exact-ticket, two-recipient ArrayTransport outcomes for explicit rejection and uncertain acknowledgement. Only the isolated runtime loads these helpers; production transports are unchanged. Transport attempt counts are recorded separately from ledger outcomes so a repeated send is detectable.

Parent scoped Pint and helper PHP syntax checks passed. New behavior and browser helpers are **not yet verified**. Focused backend and UI tests and current-assets desktop browser journeys remain next. No live provider receipt or production readiness is claimed. No deployment, real communication, working-database reset or production AI execution.

## Remaining dependencies

Approved public-reply file delivery policy is unanswered. Production default remains `link_only`; DP03, DP04 and DP07 operational/provider criteria are not marked complete. Existing broader settings/provider evidence remains in `w12-email-settings-results.md` and `w12-support-transport-results.md`; earlier test counts apply to their recorded source versions.

## Initial guarded regression

Run93920/token `it_4f1eaa9216d04c3a` completed228 tests/2682 assertions:227 passed, one failed, no test errors. All14 postflight checks passed, including exact isolated schema absence. The one failure was `ItServiceOperationsTest.php:275`, an added `assertNothingSent()` after two HTTP requests had already executed their post-response jobs. Parent corrected the test to assert two notifications (original plus retry) and no additional dispatch from a repeated recovery drain. No production code changed in response. Targeted operations-file rerun43947 is active; no second concurrent import.

The broad run covered settings, notification outbox/access, Operations, ticket bulk/workspace, RFC identity, Gmail/support transports, catalogue and provisioning workflows/bulk. These counts are one combined run, not additive to earlier W12 baselines. Its15 source hashes are recorded in `w12-delivery-recovery-source-hashes.json`; only the corrected test changed afterward. UI and actual browser acceptance remain pending.

## Operations UI verification

Terra released `resources/js/components/it/it-service-operations.tsx` and its focused `__tests__/it-service-operations.test.tsx`:26/26 tests, scoped ESLint, full `tsc --noEmit` and scoped diff check passed. Parent reviewed the changes and requested corrections before release: fresh account-inclusive reload, failed-refresh recovery, distinct retry receipt identity, status-driven severity, accurate provider event time labels and unconfirmed submission language. Final tests cover all50 rows, status presentation, pending/double-click protection, malformed/lost/denied responses, fresh eligibility (including negative actor/permission cases), refresh failure and account-change cancellation/concealment.

No new modal or duplicate mail system. Production build86628 is active; actual desktop browser verification has not yet run on these assets.

## Corrected backend recheck

Targeted run43947/token `it_defb7983197840ce` exited0:23 tests/288 assertions, no failures/errors; all14 postflight checks passed and the exact isolated schema was absent. This reran the entire Operations file after the sole assertion correction, including the JSON retry receipt and restricted work-scope cases. Production sources remained unchanged from the broad228-test run. The23 cases overlap that run and are not additional unique coverage. Source-reviewed browser fixture isolation found no confirmed defect; actual execution is still pending.

## First current-assets browser pass

Build86628 exited0 (3m28s), `app-BRESRQGF.js`, manifest `fa7d491ab582335d21698faf3a6ffe51a45ad6da68c339772cc3221add54e711`. Bootstrap60973 exited0: owned token `c6c87d7c6bef4109`, fingerprint `a62549bd6d4f3a18160046cbf549cb58dd4358f5d20b8c66cf2da0aeb8528795`, PHP33324. `w12-delivery-recovery-browser-runtime.json` confirms the exact checkout/schema/storage/current assets, real CSRF, array mail, sync queue and synthetic outcomes. In-app tab35; existing user tab3 preserved. No resizing.

- Technician added their watch subscription and assigned ticket1 to the cover recipient through the real, reasoned triage flow. The assignment persisted and its legacy notification reached accepted once through the new durable path.
- Requester submitted comment1 (`W12 c6c87d7c6bef4109 browser partial delivery.`) using Ctrl+Enter. The actual public-comment presenter showed accepted1/failed1 and explicitly distinguished acceptance from delivery. It exposed no recipient detail or Operations link. Direct navigation to the scoped Operations route returned403; returning to the permitted ticket recovered normally.
- Requester submitted comment2 (`W12 c6c87d7c6bef4109 browser uncertain delivery.`) using Ctrl+Enter. The actual presenter showed sending1/accepted1 and unconfirmed outcome copy.
- Technician followed comment1's real Review delivery log link. It showed precisely two recipient attempts. A Tab move reached Retry delivery with visible focus; Return disabled it as Retrying delivery, then refreshed to three history rows: original accepted technician, retried cover original, and one accepted cover retry. The original failure message remained in history. Full reload preserved that result.
- Comment2's scoped Operations history showed unconfirmed Sending and reconciliation guidance, with no retry action, plus the independently accepted technician attempt. Desktop screenshots were inspected inline, including focused retry and uncertain rows; no saved screenshot file is claimed.
- Read-only observers `w12-delivery-recovery-browser-before-retry.json` / `...after-retry.json` corroborate the UI: comment1 delivery2 accepted once, delivery3 retried, its sole child6 accepted once; comment2 delivery4 accepted once and delivery5 sending once. Every synthetic transport counter remains1. All delivered_at fields remain null. No accepted or uncertain recipient was resubmitted by navigation/reload. This is isolated local capture evidence, not live provider receipt.

The pass found two UI gaps, so the final UI slice is not yet marked Verified: successful triage immediately triggered an unnecessary unsaved-navigation prompt, and retry refreshed rows but left the summary failure count stale until full reload. Terra corrected the former using the existing approved-navigation guard, preserving protection for new/restored proposals; parent corrected the latter by requesting canonical Operations audit and generated timestamp with delivery/auth refresh. Focused combined recheck:52 tests/2 files passed (7.31s), scoped lint passed; full TypeScript passed on the triage correction. Final build34527 is running. Both corrections need the next current-assets browser pass, including a stale second-editor retry error/recovery.

Tab35 closed. Cleanup6634 exited0; `w12-delivery-recovery-browser-postflight.json` independently confirms exact schema/root absence. No test/import/browser runtime remains; only build34527 is active. Runtime helpers were unchanged during their owned lifetime.

## Final current-assets browser recheck

Build34527 exited0 (3m18s), `app-xgo-TVyg.js`, manifest `0c4f05f87e230e11d4fd82e56130427511d5c23d97d46b93f051c10c188b13f5`. Bootstrap22896 exited0: token `9b4b0588b9a74e49`, fingerprint `b9fb0c7db35415b2dec8908a40c17cc08b2f9111e3ec2ab21ca414cd13d84b40`, PHP38856. `w12-delivery-recovery-final-runtime.json` confirms the correct checkout/current assets and isolated capture environment. Owned in-app tabs36/37; no resize.

- Repeated the real watcher and reasoned assignment flow. Confirmed save returned directly to the persisted cover assignee with no false leave-warning; screenshot and accessibility state inspected.
- Requester submitted the token-bound partial-delivery reply using Ctrl+Enter. It showed accepted1/failed1. Two technician Operations editors opened that failed history before either retried it.
- First editor showed disabled pending state, then one new accepted retry. Its scoped history count became3 and the canonical summary failure count cleared in the same partial refresh; no full reload was needed. The generated timestamp refreshed too.
- Second editor retained the old failed row. Its attempted retry was refused with the server error and an explicit Refresh current state control; it showed no queued success. Tab/Return activated recovery, with a disabled refresh state, and it then displayed the same existing accepted retry and corrected summary. It offered no further retry.
- `w12-delivery-recovery-final-observed.json` is read-only evidence: the original accepted recipient is delivery2 with one attempt; delivery3 is retried; its sole child4 is accepted with one attempt. The stale editor created no second child or submission. All delivered_at values remain null. This recheck adds real stale-editor failure/recovery to the first pass's uncertainty, privacy and keyboard evidence.

The bounded delivery recovery UI and the discovered triage navigation correction are now **Implemented and locally Verified** on the recorded assets. Whole W12/E11 and the goal remain incomplete: approved-file policy, operational defaults/settled-work decisions, broader real-process interruption/reconciliation criteria and approved live provider/recipient evidence are not replaced by this local capture pass.

Tabs36/37 closed; final cleanup14589 exited0. Independent `w12-delivery-recovery-final-postflight.json` confirms the exact schema and owned runtime directory are absent. Existing user tab3 is preserved. No active test, import, build or owned browser runtime remains.

Final TypeScript found TS2352 on the already runtime-validated retry receipt assertion (`w12-delivery-recovery-final-types.txt`). The receipt declaration was corrected from an interface to an equivalent type alias; its fields and runtime checks are unchanged. Corrected full TypeScript91450 exited0 (`w12-delivery-recovery-final-types-corrected.txt`). `w12-delivery-recovery-type-only-proof.json` verifies byte-identical emitted JavaScript before/after this type-only correction, so the recorded current-assets browser evidence still applies. No new runtime behavior or extra browser claim is inferred from that check.

Final four UI source hashes,52-test result, corrected TypeScript, build/manifest, browser oracle and cleanup references are appended to `w12-delivery-recovery-source-hashes.json`; initial results are preserved. Backend hashes remain those covered by the broad run and corrected test's targeted rerun. W13 prerequisite review by both Terra High workers is read-only; no W13 implementation/test/browser completion is claimed. Next independent work is the canonical API command/receipt and transient credential-publication contract, while full W11/W12 operational and provider gates remain open.
