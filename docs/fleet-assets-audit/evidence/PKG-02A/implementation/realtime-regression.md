# Fleet realtime regression failures — unresolved

Baseline reference: `2302ca33a95616e442a78e957ddce82d8db98669`. These failures are **not established as a pristine whole-HEAD baseline** and are not waived.

The candidate's broad affected regression selection returned 41 tests / 508 assertions, with 39 passing and two failing. A second selection containing the new protected-location tests, migration rollback test and `FleetRealtimePrivacyTest` reproduced the same two failures: 16 tests / 93 assertions, 14 passing and two failing. Both commands exited 1. The second run did not include the broad selection's consent-withdrawal test family, so that earlier family is not required to reproduce the failures.

Failure 1:

```text
Tests\Feature\FleetAssets\FleetRealtimePrivacyTest::test_broadcast_auth_route_and_record_channels_fail_closed
Failed asserting that false is true.
tests/Feature/FleetAssets/FleetRealtimePrivacyTest.php:77
$this->assertTrue($authorizer->canViewClientAlert($user, $client->id));
```

Failure 2:

```text
Tests\Feature\FleetAssets\FleetRealtimePrivacyTest::test_wandering_alert_provenance_requires_exact_active_consent_device_asset_and_site
Failed asserting that null is true.
tests/Feature/FleetAssets/FleetRealtimePrivacyTest.php:120
$this->assertTrue($authorizer->consentedClientForSignal($signal)?->is($client));
```

Source comparison returned exit 0 for `git diff --exit-code HEAD --` these exact paths:

- `app/Services/Fleet/FleetRealtimeAuthorizationService.php`
- `app/Domain/SecurityDevices/Services/PersonalTrackingPrivacyService.php`
- `app/Services/ConsentValidationService.php`
- `tests/Feature/FleetAssets/FleetRealtimePrivacyTest.php`
- `tests/Support/AuthoritativeConsentFixture.php`

These unchanged paths were executed through the real test and authorizer in the changed candidate. The entire application was not reverted or checked out at HEAD for either run. No assertion about all baseline dependencies being unchanged follows from this comparison.

Source evidence consistent with the failures: the realtime test's `createTrackingConsent()` uses `Fleet Tracking` at line 206. The existing resident-location allowlist in `ConsentValidationService` lines 27–29 includes only `Personal Tracker (Wandering Risk)`. Both failing authorizer paths call `PersonalTrackingPrivacyService::authorisedClientAssignment`. This is a source-based diagnosis, not a pristine baseline run or authority to widen the allowlist. No privacy service, allowlist, realtime test or shared consent fixture was changed.

## Executed bounded diagnostic, 21 September 2026

The local diagnostic invoked the actual unchanged `FleetRealtimePrivacyTest` fixture methods (`makeSiteUser`, `createTrackingConsent`, `assignDeviceToClient`) through reflection, then the actual consent, privacy, device-access and realtime services. It ran through Pest with the reviewed forced XML profile and immediately preceding isolation proof, in its own actual-PID schema. Result: **one test, 11 assertions, exit 0**, with the same intentionally missing `.env` warning. HTTP was prevented; queues and notifications were faked.

Observed chain: the fixture produces `Fleet Tracking`; general tracking consent validation and `assignmentAuthorisesClient` return true; resident-location consent validation and `assignmentAuthorisesResidentLocation` return false. The actor's `assignableClient` is present, but `authorisedClientAssignment` is null and `canViewClientAlert` is false. With the matching active device/asset/site link and signal, `consentedClientForSignal` is null. Thus this fixture's general consent does not satisfy the resident-specific gate exercised by both failing expectations.

This is executed evidence of the fixture/dependency mismatch within the candidate, **not** a pristine whole-HEAD reproduction or a passing realtime suite. The two original failures remain unwaived for Main's disposition. No privacy allowlist, realtime fixture, consent fixture or authorization policy was changed. The exact five-path `git diff --exit-code HEAD -- ...` comparison above was repeated and returned exit 0. Local diagnostic source and `.pkg02a-realtime-diagnostic.log` are excluded from publication.

Local raw evidence, excluded from publication: `.pkg02a-backend-regression.log` and `.pkg02a-backend-final.log`. Warning source in these runs was the intentionally absent `.env`, surfaced from `vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73`. The forced isolated XML profile supplied configuration; no operational environment file was created.
