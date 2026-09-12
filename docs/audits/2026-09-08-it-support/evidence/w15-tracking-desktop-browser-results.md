# W15 requester tracking — desktop browser evidence

12 September 2026. Bounded tracking and validation recovery acceptance; full W15/E14 and release gate remain open.

Runtime: token96cdb831e547a20f, fingerprint93219c913add4d9f41c98031edd8f69c12f5e4fa7ee4a8d9028b757d25e94c21, bootstrap33132terminal0/server42344, owned in-app tab25. Identity endpoint confirmed this checkout, exact disposable schema, array mail/sync queue, normal CSRF and manifest e1ad64495d528392724ec1a01af8d0e48ceec6df1428ed63887c6861fd3a9e2a (app-_eEqmUF7.js, build65330terminal0/3m53s). Desktop viewport was not changed; existing user tab2 and Chrome tabs were preserved.

## Observed journeys

1. Normal requester1 sign-in. My requests initially shows five fixture tickets and zero equipment/access requests. Catalogue shows three published forms; unpublished draft and internal revised contract are not exposed.
2. Empty required intake remains in the open form without success. Exact read-only reconciliation confirms zero submissions and zero provisioning records at this point.
3. Submit one synthetic equipment request. Normal redirect opens `/it/provisioning/1` / IT-P000001, displaying original answer and form version1, pending approval, no scheduled date, one public submitted event, and no requester approval/fulfilment controls. Screenshot inspected inline: shared profile header and two-tier navigation, readable details/progress proportions. No screenshot file is claimed.
4. ArrowRight on Request details selects Activity and focuses its tab. Nonmatching search shows the explicit empty state. An initial automation fill-to-empty did not establish clearing; subsequent native Ctrl+A/Backspace did clear the search, restored the answer, and Refresh status retained it with the same reference/status. Do not claim the initial fill attempt as a pass.
5. Back to My requests lists the canonical request in the table and increments the tab count from five to six. Reference search first exposed a real500: undefined Eloquent `orWhereKey`. Isolated log evidence is retained. Changed only the application query to `orWhere('it_provisioning_requests.id', …)`; no assets/migrations/harness changed. Retrying the same URL returns exactly one provisioning result and no matching helpdesk tickets. The corrected backend is the source used for subsequent acceptance.
6. Cards toggle retains the filtered reference/status/approval and native canonical link. Keyboard Enter on Open returns to the same request. No duplicate work was created by search, refresh or layout changes.
7. Normal sign-out/sign-in as other requester2. Direct `/it/provisioning/1` returns a clean404 containing no request details. Their My requests page shows their own fixture ticket and zero equipment/access work. This proves normal cross-user access denial, not timed expiry or live role revocation.
8. Normal technician3 sign-in and Setup→Catalogue. Create with a name over255characters returns to focused Request details, displays the inline field error and summary, retains input, and shows no saved success. Correcting the name and explicitly saving creates one draft. Back to catalogue displays it and refreshes the header snapshot from2:20pm to2:21pm. Focus returns to New request form.

The browser console error query returned an empty list at the final setup view; this does not erase the earlier recorded500. Final read-only records: five catalogue items (four fixtures plus the corrected draft), one catalogue-create receipt, one requester submission/version1, one pending equipment request/approval pending/no approver/no fulfiller/evidence, one created event. No real fulfilment or communications occurred.

## Cleanup and follow-up

Owned tab25 closed. Exact cleanup85058terminal0 removed server/schema/runtime; independent postflight terminal0 confirms schema and owned directory absent. Working Herd environment unchanged.

The browser-discovered reference query fix and additional20-row pagination/literal-percent/reference regression passed in final isolated test48688/token it_567868f7d98e4a69: terminal0,20 diagnostic Test Passed events, no Failed/Errored/Skipped, all14postflight checks/exact schema absent. Normal assertion summary was not emitted. This supersedes the earlier19-test run for these changed sources.

After cleanup, corrected pending-status tone and repeated validation announcements: the field name stays stable and the error is announced once. UI24/3files/5.10s and lint0/Pint pass. These final presentation corrections still need their current-build browser recheck; this earlier run does not establish it. Broader W15 requester intake/preview/requested-for/Site/audience/attachments, template versioning and full approval/evidence/failure/cancel/reversal lifecycle remain open. Pagination and role-downgrade browser cases need their own evidence; no provider execution, deployment or production AI.

## Final presentation recheck — 12 September, 14:42 NZ

Build3251 completed successfully in4m16s; full TypeScript68166 exited0. Current asset app-DDkq5se2.js and manifest02264730d1f7518885a41f9fae55a57524d14bd8034f3e8ad621268a9e908941 are tied to35 source hashes and10 unchanged protected-design hashes in w15-tracking-presentation-source-hashes.json.

Fresh isolated CatalogueFixtures runtime e39f7158a6024dcb/fingerprint73be79ff3ff854616cfc711c79a7a8e8c54a3a363aa1fb1c0069147d7976a640, bootstrap85302terminal0/server28208, owned in-app tab26. Identity confirmed the exact checkout and disposable schema, array mail, sync queue and normal CSRF. No browser resize; user tab2 preserved.

Normal requester login and one synthetic submission opened /it/provisioning/1. Inline screenshot inspected: pending header badge and progress badge both use the warning tone, details/progress columns remain readable, original answer/version1 and pending approval remain visible. Normal logout and technician login then Setup/Catalogue/New request form: a name over255characters returned to the focused details form, retained input, kept the accessible textbox name Request name, and displayed exactly one alert for the server error. No saved success was shown for the invalid submission. Correcting the name and explicitly saving produced Draft saved; Back to catalogue displayed the new draft. Final console error query returned []. Screenshots were inspected inline; no screenshot file is claimed.

Read-only reconciliation:5items (4fixtures plus1corrected draft),1create receipt,1submission,1pending provisioning request,1created event. This completes the pending presentation recheck, not the remaining W15 lifecycle or release gate. Owned tab26 closed; exact cleanup39816 was dispatched and must be checked before any new isolated run.

Exact cleanup39816 exited0. Independent postflight exited0 and confirms schema and owned directory absent. No owned runtime remains.
