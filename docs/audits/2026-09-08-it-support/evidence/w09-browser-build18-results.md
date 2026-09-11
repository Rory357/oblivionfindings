# W09 Build18 rating conflict and session recovery

10 September 2026, approximately11:40–11:46 NZ. Functional slice verified; layout follow-up remains required. W09/E08 are not complete.

Environment: token`0dd5f10504da4d5d`, original fingerprint`a8b811e5fa55e94e9c31eabc20f90d85979bfd46c94ef5b83ad1ab19e856a117`, PID33148, exact disposable schema`oblivion_it_draft_browser_0dd5f10504da4d5d`. Build35070 exited0/3m48s, manifest`0c45ca1397aa67331a4f7cd2256f63de49b00ee174b69757cb344610ade9fe06`. Actual DOM script URL was read from the page: `http://127.0.0.1:8766/build/assets/app-DOx1sy8M.js`. No runtime/assets/six-helper/migration changes during verification. Array mail, sync queue, normal authentication/CSRF. No real communications or provider changes.

## Actual desktop journey

- Technician3 normally signed in and resolved ticket4 with a synthetic public note, notifications off. Requester1 then normally signed in. Public comment showed the new “IT” speaker label; private staff work controls were absent.
- Existing Codex in-app tab9 retained a three-star proposal and its comment. New in-app tab10, same requester, saved competing two-star feedback. Its explicit saved status appeared and the page refreshed without a discard warning.
- Submitting the older tab9 proposal did not claim success. Feedback remained in the disabled editor. Review current ticket displayed the saved two-star feedback and its comment; no write occurred through this read-only action. Explicit adoption retained the original three-star proposal.
- Cancel changes opened Discard this feedback. Choosing Cancel retained the exact text. Separate Update rating saved the three-star feedback. No automatic re-submission occurred during review/adoption.
- Edited to four stars with a new comment, then used normal Log out in tab10. Submitting from tab9 produced the session-recovery explanation and concealed the fields. No success was shown.
- Normally signed in as requester1 again in tab10. Review in tab9 showed the actual saved three-star feedback. Explicit adoption restored the exact retained four-star text; separate Update rating acknowledged its save. This verified actual session/CSRF recovery, not a mocked response.
- Explicit requester Confirm and close saved closure. The page showed the rating as read-only. Bounded tab9 console read returned an empty list for requested error/warn levels, limit8.

Keyboard Enter used on rating and confirmation controls. Actual screenshot of the conflict comparison was inspected at the naturally sized desktop window; no browser resize or mobile work. The long comparison/editor extends too far down the supporting rail and leaves a large empty conversation area. **This is an open UI defect:** move the full rating editor/recovery into Rory's approved simple-dialog pattern, keeping a compact rating summary/action in the rail. Do not mark this layout Verified from successful functional tests.

## Persisted evidence

Independent bounded read-only helper exited0: `w09-browser-build18-record-evidence.json`. Ticket4 closed/workflow closed/version8, score4, original submission timestamp23:42:26 UTC retained, final comment hash`309d79f4ae50122d7386bbfebb256ec59b0d3a9472e1de1cfeb5fce2e7957563`. One `csat_submitted`, two `csat_updated`, one requester `resolution_confirmed`, matching actual-actor audits. The stale and expired-session attempts produced no rating events. Public comment1 has speaker_side`it`, source_channel`browser`; canonical last-public-comment pointer1 and speaker`it` agree. No private values were emitted.

## Cleanup and continuation

Guarded StopAndRemove98271 exited0 using the original fingerprint. Independent postflight exited0: `w09-browser-build18-cleanup-postflight.json` confirms exact schema and owned directory absent; it performed no database mutations. The spacious approved rating dialog is now being implemented; its close/cancel/focus and conflict/session recovery have not yet been verified in this layout. Current browser bindings after a documentation-only REPL reset: `itRatingBrowser` browser1, `itRatingMain` tab9, `itRatingPeer` tab10. Old `itApprovalJourney` bindings no longer exist. These historical tabs point to the removed environment and must not submit.

Remaining W09 includes resolution outcome/verification/links, reopen/close recovery, related/duplicate/merge audience and canonical history, reports and reviewed knowledge. No whole-package or Goal completion claimed.
