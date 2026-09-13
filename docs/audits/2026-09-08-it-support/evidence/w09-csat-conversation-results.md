# W09 rating recovery and lifecycle conversation follow-up

10 September 2026. W09 In progress. This follows actual Build17 browser observations; it does not complete E08, related/merge or resolution quality acceptance.

## Changes

`ItTicketInteractionService.php` records speaker side and browser source on new resolution/reopen comments and updates the canonical public conversation pointer and next-response party. Internal reopen reasons leave the public pointer unchanged. An IT requester resolving their own issue does not manufacture a first technician response. Legacy comments are not reclassified.

`SubmitCsatRequest.php`, `ItTicketController.php`, `ItTicketPolicy.php` and the interaction service now bind the originating browser actor and required reviewed version. Current requester, access, resolved state and nonmerged record are checked again under the ticket lock; direct callers validate score/comment. The JSON acknowledgement identifies the actor, ticket, operation, current version and actual saved score/comment/original timestamp. Exact unchanged feedback is a no-op; stale proposals cannot overwrite newer feedback. The existing revision-until-closed policy and original submission timestamp are preserved. Audit failure rolls the whole change back.

`csat.tsx` reuses shared rating controls, buttons, confirmation and `TicketVersionConflict`. It compares the exact saved acknowledgement before success, retains feedback through uncertain saves, supports stop-wait/late-response protection and session review, conceals feedback on access loss, displays current saved feedback during read-only review, then requires a separate explicit update. Cancellation/navigation ask before discarding entered work. The acknowledged own refresh bypasses that discard guard. Arrow keys follow the rating radio group. Both ticket and My requests hosts supply the viewed ticket version. No new credential, provider, notification or tenant system.

## Actual checks

- Six-file PHP formatting exited0; subsequent policy/worker/test formatting exited0.
- Initial focused Feature7978: **27Passed/27Finished,447assertions,exit0**, token`it_9409e9593e344d70`; final wrapper preflight confirms exact schema absent. Files: CSAT, Lifecycle, ResolutionConfirmation, ConversationProjection. Log`w09-csat-conversation-feature-tests.txt`. This predates the final explicit merged-record denial assertion; its final focused run is separate.
- Standalone real-worker1980: **1Passed/1Finished,23assertions,exit0**, token`it_d5d229034c434d2a`; exact schema absent. Both processes reached the held parent lock. Competing ratings yielded one saved score and one stale rejection, one submission event/audit. Rating versus requester confirmation preserved the winning reviewed version with no second write. Log`w09-csat-concurrency-tests.txt`; new `ItTicketCsatConcurrencyTest.php` uses the existing guarded task worker. W27 README lists this explicit standalone target.
- Initial frontend sandbox invocation failed during esbuild path access before tests started. Escalated retry63889 **23passed/3files/17.45s,exit0**. Later expanded group56156 **46passed/1failed** because the test still expected Submit rating after reviewing an existing saved rating; the actual button correctly said Update rating. Corrected that assertion and added own-refresh regression. Final50249 **48passed/4files/9.38s,exit0**. Preserve failed logs; do not sum superseded groups.
- Strict five-file ESLint exited0. First full TypeScript69352 exited2 on the new test's unsupported Testing Library `exact` option; corrected it. Full TypeScript79200 then exited0. Subsequent added test has no runtime change; final build/check evidence follows.

Final merged-policy Feature44877 **9Passed/9Finished,138assertions,exit0**, token`it_d4091967874c4653`; final isolation check confirms exact schema absent. Build18/35070 **exit0 in3m48s**, asset`app-DOx1sy8M.js`, manifest`0c45ca1397aa67331a4f7cd2256f63de49b00ee174b69757cb344610ade9fe06`. Reviewed browser Preview confirms the six helper hashes and all migration hashes unchanged from Build17. CreateAndStart41624 is preparing new token`0dd5f10504da4d5d`, original fingerprint`a8b811e5fa55e94e9c31eabc20f90d85979bfd46c94ef5b83ad1ab19e856a117`. No browser proof of these post-Build17 changes yet. Do not use the disposed Build17 tab as evidence for the new assets.

## Remaining acceptance

Real browser concurrent rating/review/retry, latest lifecycle author labels and current assets remain to verify. W09 still needs meaningful resolution outcomes/verification/links, complete reopen/close recovery, merge audience/file/approval/history preservation, related/duplicate work and resolution-quality reporting/knowledge integration. The intermediate old draft-version message seen in Build17 must be checked against the eventual autosave response before changing it. No whole-package completion claimed.
