# W02 desktop browser command verification

Host: https://oblivionfindings.test. Checkout: C:/Users/steph/Herd/oblivionfindings. Browser: Codex in-app tab 1, requester fixture 231. Desktop viewport 1440×900 after the user's desktop-only clarification. Array mail, synchronous local queue; no real communications.

## Persisted creation and retained input

IT-000015 was created through Get help with the exact labelled disposable title permitted by w02-browser-results.php. The success screen displayed its canonical reference, and Open IT-000015 navigated to /it/tickets/15 with the submitted report. Before submission, opening a suggested approved guide preserved the request form underneath; closing the guide returned to the same draft. Escape opened discard confirmation; cancelling retained the details. An initial attempt to press Stop waiting was too late because the command had already completed; it is not counted as cancellation evidence.

The content-free server trace recorded validation at 332.343ms, commit at 399.676ms and completed HTTP201 at 405.635ms. This is server middleware timing, not browser/proxy latency. It does not establish the original audit's historical 504 cause. See w02-browser-requester-trace.txt and w02-browser-requester-saved.json.

## Actual cancelled wait and same-command retry

The guarded helper w02-browser-hold-requester.php acquired a transaction row lock on only synthetic requester231, with a hard45-second ceiling and no database writes. It released on an explicit local marker after33.718seconds and rolled back. Existing people/tickets were not modified by this helper.

With a prepared second synthetic request, Raise ticket entered Saving with disabled fields, file drop and submit button. Pressing Stop waiting succeeded: the focused alert explained that this does not cancel the server submission, retained the displayed details, and offered Check saved request / Retry same request. The helper was released immediately after this observation. Retry same request then returned Raised — IT-000016; Open navigated to /it/tickets/16.

Read-only reconciliation found exactly one ticket16, one actor/channel/operation receipt for UUID248bf09f-8d71-4d2f-abd7-f3d2d9e6f253, and one delivery with attempt_count1. Initial trace cfa677e1-45b2-4ffb-a185-53d954fa4c79 completed HTTP201 after18,266.656ms (including deliberately held lock). Retry trace5247f38e-5230-4c4a-b438-887c30b9d6c5 explicitly recorded replayed and completed HTTP200 after225.001ms. Array transport accepted once; this is not proof of provider delivery.

Current DOM loaded app-DPggLS6p.js, matching the local manifest. Manifest SHA256 c807d9c4cabf68e0012384b29ef5dea4a59d00cb4cf1930fbdeee633f8461ea1; IT asset index-0mG74B2V.js. Build exited0 in3m54s. Evidence: w02-browser-cancelled-wait-recovered.json and w02-browser-cancelled-wait-trace.txt.

## Technician creation, collision and restored-session verification

Restricted technician230 completed Log & triage at approved Site B9404 and opened the saved IT-000017. Site picker contained A/B only, not C. Exact creation metadata is in w02-browser-technician-saved.json.

Two desktop tabs opened synthetic ticket14 at version1/normal. The first saved high/version2/event count3. The second attempted urgent from version1 and received the retained-change conflict dialog; its proposed urgent value remained visible. Review current ticket showed high and required Use this version and keep my draft. Read-only reconciliation after review still showed high/version2/events3. Only pressing Apply my changes wrote urgent/version3/events4. See w02-browser-stale-edit-rejected.json and w02-browser-stale-edit-applied.json. No historical ticket was edited.

On rebuilt app-D9H4sY7b.js, a technician draft stayed open in tab1 while tab2 performed normal logout. Submission showed the distinct session-expired alert with unchanged details and original UUID2d217347-2b18-4742-88b3-d1d6c5e42d12. Signing the same actor back in via tab2 and Retry same request saved IT-000018 once. See w02-browser-restored-session.json. W03/W04 backend integration had begun by this creation; its new metadata is not claimed as W03/W04 acceptance.

## Acknowledged close and reference-only resume

A second controlled actor lock used only synthetic technician230, released after29.511seconds with no helper data writes. Stop waiting retained UUIDb035f3cd-e3a3-4d5d-9744-85081235c3f8. Cancel opened the explicit uncertainty dialog; keyboard focus defaulted to Go back. Going back retained the command. A second Cancel and explicit Close and keep reference closed it. Reopening Log ticket showed the same UUID, Check saved request and Start a different request, with the prior title/details and retry payload absent from the rendered form. Check saved request returned Logged — IT-000019 with generic recovered-result copy. Read-only pre-recovery metadata already contained exactly one19; lookup created no new request. Evidence w02-browser-acknowledged-close-before-recovery.json. The browser marker contains only the actor-scoped UUID, as separately verified in the33-case automated suite; no browser-storage introspection was used for this browser check.

Build exited0 in3m53s; manifest870e4139a5191e3686dc79074052304bb16b03465bc5b2d029c929d672409165, IT index-Dl17Cgxw.js. A minor recovery-only50% completeness display observed during this check has been corrected in the following W03 UI source using existing WizardShell hidden-progress/step props; that visual correction awaits the W03 asset build.

## Outstanding package acceptance

Later W03 staff approved-site choice, changed permission while an open form is displayed and full W06 persisted private drafts remain to verify in their dependent slices. These browser slices do not close E01/E06 or the final release gate. Backend changes must retain the W02 receipt fingerprint shape; a transient local W03 change that added absent null fields was corrected before release and covered by a compatibility regression. The above saved-result GET recovery remained unaffected.
