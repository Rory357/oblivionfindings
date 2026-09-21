# PKG-02A revision 4 — resident realtime fixture correction

21 September 2026. Main's `PKG-02A-main-r2-r3-review.md` released this exact test correction and read-only concrete Locate contract preparation. Main independently verified this turn as Astra/xhigh. R2/r3 application changes are reviewed within their stated bounds. This checkpoint changes only `tests/Feature/FleetAssets/FleetRealtimePrivacyTest.php` in permanent application/test source; it grants no next-feature, integration or operational release.

## Exact correction

The two positive resident-location alert cases now create existing **Personal Tracker (Wandering Risk)** consent through the original local helper and unchanged AuthoritativeConsentFixture. The helper accepts an optional consent type, defaulting to that resident type. Every existing ownership, other-site, device/asset provenance, withdrawal, collection-stop, channel and minimal-payload assertion remains intact.

One new negative test supplies **Fleet Tracking** explicitly. It proves this is valid general tracking consent, invalid resident-location consent, and cannot authorize either `canViewClientAlert` or `consentedClientForSignal` on an otherwise corresponding client/device/asset fixture. HTTP stray requests are prevented and Queue/Notification are faked for the family; the negative case asserts nothing sent/pushed. No application policy, permission, allowlist, canonical service or shared fixture/harness was changed.

Diff: 52 added / 2 removed lines. Corrected test SHA256: **BC0A566B7F4F0190977262A2FC0A888BBE975FCA3DBA20F3F0F4FD86608028AB**. The prior source is the unchanged HEAD version of this file. Historical broad failures and executed diagnostic remain in `realtime-regression.md` and original local logs. The old reflection diagnostic describes that earlier fixture state; it was not rerun against the corrected helper default.

## Executed result and boundary

PHP syntax check passed. Immediately afterward the reviewed read-only isolation proof passed, then PHP 8.4.16 ran `vendor/bin/pest --configuration phpunit.pkg02a.xml tests/Feature/FleetAssets/FleetRealtimePrivacyTest.php --compact` from this worktree.

Result: **4 tests, 26 assertions, exit 0**, 222.45 seconds. The runner reports four warnings; compact output does not expose their causes, so this is not described as warning-free. Local raw log `.pkg02a-realtime-corrected.log` SHA256 **F396100DAAB19F179072FE009DE41B787A905DFB3C46E769C33571DE48B5F565** remains excluded from publication. `git diff --check` passed. A post-run read-only isolation proof found no matching package test schemas left behind.

Both proofs resolved App/Tests into worktree `2b9f`, PHP 8.4.16, local MySQL 127.0.0.1:3306, exact package base `oblivion_findings_pkg02a_2b9f_test`, no inherited process-token/connection URL, no .env/testing/cached configuration, queue null/mail array/broadcast null. XML SHA256 remains **CAC8963A48816BB625AE8F0DE1ACB7EF999BD6061ED8B47F4B0CDF984CF1B29E**; unchanged composer/package lock and TestCase hashes match r3. The unchanged harness creates/drops only its process-suffixed isolated schema. No browser data or live unit was touched.

This resolves the two recorded fixture failures in the corrected affected family. It is not a pristine whole-HEAD baseline run or a claim that the whole backend is green. Existing care concurrency and broader test histories retain their recorded command outcomes. No frontend/build rerun was warranted for this test-only source change.

## Frozen identity and next work

Current source/build manifest SHA256 **4B497BAA08EB8E5687B40948749A95462D36E30073B917D09004E1BF6B6D9A74** records 31 application/test files and the same 6,030 local build files. Runtime build manifest remains **042F1007FD5414282DDA3B6D5542E65E23DFD4AA38827F55108A121F85219B3E**. The r3 source manifest, artifact index and checkpoint document were copied to their `-r3` names before updating the current pointers. Frozen v1/v2/v3 mockups, protected guides and previous evidence were not edited.

`locate-contract.md` revision 2 defines the concrete next slice, comparing HMAC key namespacing with the preferred explicit immutable signed origin context on the existing command request. It specifies exact endpoints, genuine step-up/retry identity, current consent/care locks, atomic delivery-claim enforcement and its physical-send limit, legal unsent lifecycle rejection, independently measured observations, frontend cancellation, migration compatibility and fake-transport tests. Main technical disposition is pending. No Locate application implementation occurred at this checkpoint. Operational zones, outing integration and recipient-sharing prerequisites remain outstanding as previously recorded.

No commit, stage, push, merge, deployment, operational migration, real tracker polling or expanded disclosure occurred.
