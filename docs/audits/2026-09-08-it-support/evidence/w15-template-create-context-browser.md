# W15 template creation — changed-account recovery browser verification

12 September 2026, approximately 17:53–17:57 NZ. This closes the specific stale template-view defect recorded in `w15-template-create-recovery-browser.md`; it does not close all W15 acceptance.

## Environment and evidence

- Owned isolated token `7848ff84e0354644`, fingerprint `cda34fe15d2ab4cb7f00a4c43ed0af4be94399575467a53ad24bf4ac5eeecbc3`, exact checkout and build identity in `w15-template-create-context-browser-identity.json`.
- Build17887 and TypeScript23458 completed with exit0. Current entry `app-BvUX_Fd-.js`, manifest SHA256 `57d117276ffdcba69c7a258e8ebd873991fa796aa88ba90dc5db0699f374025a`. Fourteen source hashes still matched `w15-template-create-recovery-source-hashes-context.json` after browser verification.
- The prior tab34 no longer existed on resumption; the runtime process26804 was confirmed live. Opened owned in-app tabs35 and36 against that same runtime. Normal signed-in technician context was observed. No runtime restart, browser resize, private application-state evaluation, provider operation or real communication was used.
- Desktop screenshots were displayed inline for the requester return page and the reviewed template wizard. No screenshot file is claimed. Both owned tabs returned empty error-level console lists.

## Actual journeys

1. Created an unsaved draft named `W15 context footer private draft`. Footer Cancel opened the confirmation with the corrected text: “Discard this unsaved template? No template will be created.” Cancelling that confirmation retained the name and allowed progression through workflow steps to review.
2. In owned tab36, used the ordinary user menu to log out the technician and log in as `w06-other@demo.test`. Confirmed the current restricted account in its page header before submitting the still-open technician draft in tab35.
3. Save refused the changed context. The wizard displayed the unavailable-access status and concealed-draft explanation. The private name/instructions, previous template list, Completeness indicator and Save control were absent. `Return to service desk` remained available.
4. Activated that return button with Enter. The resulting page showed the current restricted account, requester IT & Support hub and requester navigation. There was no Setup link or former template list. No discard confirmation exposed the old view.
5. Restored the technician using ordinary sign-in, loaded fresh Setup and authored a separate `W15 context escape private draft`. Changed the other tab to the restricted account again, confirmed its header, then submitted the old draft. The same concealment occurred. Escape on the dialog navigated to the current requester hub with the correct account/navigation and no old template list.
6. Restored the technician once more, loaded fresh Setup and authored `W15 fresh authorised context template` with one Verify/Account step, `Record synthetic manual verification`, and explicitly synthetic manual instructions. Reviewed the wizard and saved with Enter. `Template saved` appeared; `Back to templates` loaded the new Version1 card and returned focus to New template.

## Persisted reconciliation and cleanup

The guarded read-only inspector exited0; its complete output is `w15-template-create-context-browser-records.json`.

- Exactly two templates: original fixture1 and intended new template2. Neither denied draft exists.
- Exactly one template-create receipt, actor3, UUID `cb8f8ce3-8458-45d6-ab67-f38dd223658a`, pointing to template2, committed and not cancelled.
- Exactly two immutable template versions: original fixture version1 and new template2/version1.
- Original workflow1 still points to template1/version1 and retains its original manual instructions and approval/evidence requirements.
- No catalogue submissions or catalogue-created tickets. The inspector does not query audit rows; browser audit counts are not claimed. Atomic required audit behavior is covered by the separately recorded PHP tests.
- Closed owned tabs35 and36 only. Exact StopAndRemove session11205 exited0. Independent postflight exited0 and confirmed both schema and owned directory absent. See the matching cleanup text and postflight JSON. Working Herd environment and other databases were unchanged.

This browser evidence combines with 32 focused PHP passes, the standalone real-worker concurrency pass, 31 UI tests, scoped lint and the current build/types checks recorded in `w15-template-create-recovery-results.md`. Full catalogue intake, scalable entity choices, provisioning lifecycle and final W15/release acceptance remain open.
