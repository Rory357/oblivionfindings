# W09 Build17 desktop lifecycle verification

10 September 2026, approximately 10:58–11:11 NZ. W09 remains In progress; this verifies the requester-confirmation slice, not all E08 acceptance.

Environment: owned token `26a0705b30b848af`, original reviewed fingerprint `9cacb63d9939511d8b10608084e0e1153b8d1833554b5cf524a307be4f1d541a`, server PID46108. Build exited0 in4m23s. Actual browser asset `app-C0nsoapa.js`, manifest `4bebfed6e417f408f4cb55e86d41e0e824d05cdc60974f53999f13bf303c956b`. Codex in-app browser1/tab9, normal login/logout, synthetic technician3 and requester1, ticket4. Desktop window left unchanged; no mobile checks or resizing. Array mail, sync queue, no real communications or provider changes.

## Actual browser journey

1. Technician created required task1 through the task wizard and separate Review/Save: “Build17 verify restored sign-in”, evidence required, assigned to technician3. Saved Pending, 0 of1 verified complete.
2. Entered a public resolution note, switched notification off and submitted. Required-work refusal named task1 and offered its corrective link. The exact note remained in the textarea and the saved draft. No successful resolution was reported.
3. Cancel closed the saved form safely. Completed task1 with a synthetic completion note and canonical ticket reference `/it/tickets/4`. The acknowledged result and page showed 1 of1 verified complete.
4. Reopened resolution. The old draft required review of current ticket version3. Explicitly resumed the saved note, reviewed the current ticket, adopted its version, then separately submitted. The exact original note was saved publicly. Review before resuming led to a second review after resume; the stale “Review the current ticket…” draft status persisted even after adoption. This confusing status is a confirmed follow-up, not a claim of lost data or bypass.
5. Normal requester login showed the public resolution, new follow-up card, Confirm the fix and I still need help; private required task controls/details were absent. Screenshot visually inspected: desktop conversation and readable supporting rail, shared ticket header, no overlap in the follow-up card.
6. Keyboard Enter opened I still need help. Entered an explanation and explicitly reopened. Ticket became Open, public explanation appeared, reply composer became available.
7. Technician logged back in, explicitly started a new resolution draft after the previous one was consumed, entered a second public explanation and resolved with notification off.
8. Requester selected four stars with Enter, entered feedback and explicitly submitted. The saved rating displayed Update rating while resolved.
9. Keyboard Enter opened the new confirmation dialog. Screenshot visually inspected: shared480px confirmation, clear closure/rating-lock explanation and distinct Close/Confirm and close controls. Escape dismissed without closure; a fresh DOM snapshot showed focus restored to the triggering follow-up button. Two generic selector evaluate calls timed out; focus evidence is the subsequent actual DOM snapshot, not those failed calls.
10. Reopened with Enter and explicitly pressed Confirm and close. A focused status acknowledged the saved confirmation; underlying ticket became Closed. Done returned to the page. Rating became read-only; Activity publicly named requester1 as confirming the fix and closing the ticket. Private task events remained absent.

## Independent persisted evidence

`w09-browser-build17-record-evidence.json` was read without bootstrapping the application, from the exact owned schema; exit0. Ticket4 is closed/workflow closed/version9, reopened_count1, score4, original CSAT timestamp and second resolution summary hash. Three public comments have actors3/1/3 and exact expected note hashes. Events contain two resolutions, one reopen, one rating and one requester confirmation; audit contains the matching actual actors and requester_confirmation source. Task1 is required/evidence-required/completed by3, one evidence reference, with one create and one completion receipt. No raw private evidence values emitted. Existing model timestamp storage differs between comments/audits and UTC ticket events; do not compare raw strings as equivalent zones.

Bounded console read returned seven Axios Network Error entries dated21:56–22:43 UTC, before Build17 readiness; no new Build17 error appeared in that returned list. This is not an empty-console claim or a diagnosis of earlier network errors. Requester pushback displayed “Delivery tracking was not recorded”; real delivery acceptance is not claimed.

## Cleanup and remaining work

Guarded StopAndRemove55487 exited0 with the original fingerprint. Independent read-only postflight exited0: exact schema absent and owned directory absent (`w09-browser-build17-cleanup-postflight.json`). Frozen runtime/assets were not changed during the journey. Historical tab9 now refers to a disposed environment; never submit its stale forms.

Confirmed follow-ups: record speaker side/channel and canonical conversation pointer for resolution/reopen comments (currently “role not recorded”); clear obsolete draft-version status after reviewed/save recovery. Remaining W09: meaningful resolution outcome/verification/links, complete CSAT/reopen/close failure and concurrency recovery, merge/related work/audience preview, resolution quality reports and reviewed knowledge integration. Confirmation unknown/session/concurrent cases have automated coverage, not actual browser proof in Build17. W08/E07, W09/E08 and W10–W27 remain open. W19 multiple resources and schedules remains Planned.
