# PKG-09B canonical-history fixture successor

Parent: ba2e0aeec341ff68dedbfafd4fb4d64642e8bd5b. The exact successor is the commit containing this packet update. Only tests/Feature/SecurityDevices/CanonicalIntegrationEventHistoryTest.php:69 changes executable source: its shared success fixture now uses Personal Tracker (Wandering Risk), preserving its authoritative consent evidence, purpose and assignment metadata. Production code and all 48 previously reviewed source hashes are unchanged.

## Cause and bounded correction

The former Asset Location Tracking (Safety) fixture could not satisfy ConsentValidationService.php:28–30 and :259. PersonalTrackingPrivacyService.php:55–61 returns no authorised resident assignment, so client history (:3711), resident history (:973–980) and portal history (:137) reject access before canonical event reading. The fixture and these production guards are byte-identical between Reports base 926b4981b0289da08a20baca0995117fb53e413e and parent ba2e0aeec341ff68dedbfafd4fb4d64642e8bd5b. The narrow consent rule dates to 3917585b3b; the stale fixture name predates it.

The baseline reproduction's three substituted service files were independently checked against their original base Git blobs and match. Historical reports-baseline.log, final-tests.log, final-tests.xml and test-summary.json remain intact. The old failures are resolved by correcting the success fixture, without relaxing consent authorization.

## Verification

The bounded run passed all six cases with 136 assertions in 4m17.985s:

- Four canonical-history HTTP cases: client, resident, portal and narrow legacy fallback; 33 assertions.
- Generic Fleet/Asset consent denial: 9 assertions.
- Consent withdrawal across UI, API, export, direct URL and cached recheck: 94 assertions.

The latest deduplicated scoped evidence now contains 48 passing cases and 838 assertions, with no remaining failures among those cases. This combines the prior Reports runs with the latest bounded run; it is not a claim that a fresh full repository suite ran. Raw logs, JUnit, per-case results and cleanup evidence are in the corrections evidence directory.

PHP syntax and git diff whitespace checks passed. A production rebuild was unnecessary because application sources did not change; the authenticated 8974 preview remains on the previously verified build.

## Isolation and handback

The test used only of_pkg09b_fixture_20260928_34344. Tests\TestCase limits sibling pruning to this exact base prefix plus numeric process suffix; Main's of_main_pkg09b_review_20260928_* family is excluded. The owned database was automatically removed at process exit, confirmed by a read-only schema query. The preview database/server were retained.

The successor allowlist contains one test file, four evidence files and four packet files. No application code, unrelated package, Main checkout, merge or push was changed. The manifest now includes 49 complete application/test paths, explicitly adding this previously unchanged dependency test. Main review and the genuine 125% zoom acceptance/amendment remain pending.
