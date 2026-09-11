# W14 native availability episodes — 12 September 2026

Scope: W14 / F06 / B04 / E13. This is an intermediate lifecycle slice; direct nonurgent intake, Fleet-to-IT work, urgent handoff actions and browser verification remain open.

## Implementation

- Migration `2026_09_12_000026_add_monitor_availability_episode.php` adds nullable server-owned episode state to the existing Monitor. It is hidden from serialization and excluded from mass assignment. No new incident store is introduced.
- `MonitoringAvailabilityEpisode` identifies one confirmed outage using its canonical Monitor, Device, Site, first confirming observation and time. Native DeviceEvents carry bounded episode evidence. The existing profile evaluator retains responsibility for confirmations, durations, threshold hysteresis, maintenance and dependency suppression.
- `MonitoringObservationIngestor` retains an active episode through suppression and ends it only on confirmed health. Source publication, observation and Monitor state remain transactional.
- `DeviceEventObserver`, `ItMonitoringDeliveryService`, `CreateOrUpdateMonitoringTicket` and `SignalProcessingService` carry and verify exact episode identity. Older recovery cannot settle a later outage. Recovery already observed before delayed IT delivery becomes verification evidence on the open ticket. Legacy evidence retains its prior contract and cannot broadly recover new native episodes.
- A recovered outage delivered late creates no stale Control Room alarm. Its missing direct IT destination still reports `technical_routing_unavailable`; this gap is intentionally recorded, not counted as E13 completion.

## Verification

- Initial runner92428, token `it_0b15272d9fd14e62`: 3 passed, 1 error, 60 pending, 21 assertions, 178.63 seconds; wrapper exit1 / Pest exit2. The new maintenance fixture omitted the schema-required reason. Both maintenance fixtures now provide explicit synthetic reasons. All 14 postflight checks passed and the exact disposable schema was absent. Original evidence: `w14-native-episode-tests.txt`.
- Recheck runner81088, token `it_aa12024c4c86445c`: **64 passed, 503 assertions, 192.19 seconds, terminal0**. All14 postflight checks passed and the exact owned schema was absent. Evidence: `w14-native-episode-recheck.txt`.
- The existing independent-worker harness now creates native episodes through actual observation ingestion and asserts exact episode identity on the resulting ticket. Runner82888/token `it_0df99888873d413d` finished with 1 error / 25 assertions / 178.45s: the new test assertion used the wrong outbox relationship name. All14 postflight checks and schema absence passed; after cleanup, `deviceEvent` was corrected to canonical `event`. Evidence: `w14-native-episode-workers.txt`.
- Corrected worker recheck runner30438/token `it_edc443b7a9df4e1a`: **1 passed, 99 assertions, 182.65 seconds, terminal0**. All14 postflight checks and exact schema absence passed; independent `w14-native-episode-worker-cleanup.json` confirms no owned barriers remain. The harness verifies competing delivery, interruption before commit, interruption after commit, and competing manual retries, using actual native observation ingestion. Evidence: `w14-native-episode-worker-recheck.txt`. Pint passed (`w14-native-episode-worker-style.txt`).
- Exact11-file source hashes are retained in `w14-native-episode-source-hashes.json`. All10 protected design files match the original baseline (`w14-native-episode-design-preservation.json`); `git diff --check` passes. No active owned workers or test resources remain.
- The new 12-case suite covers delayed cross-episode recovery; recovery before IT or source delivery; maintenance continuity and resumed failure beyond generic alert deduplication; later outage after closure; threshold failures despite raw healthy observations; missing episode state; replay/older observations; transactional source failure/retry; forged Site evidence; and legacy recovery isolation.
- Selected regressions also cover monitoring policy, dependency suppression, durable IT delivery, ticket integration, context privacy and Fleet availability recovery.
- No browser or release-wide verification is claimed. Working database remains untouched; migrations17–26 are unapplied there. Only the wrapper-owned random schema receives migrations; all communications/providers are fake.

## Next dependency

Direct nonurgent intake must reuse canonical ticket/link/snapshot services while allowing absent Control Room alerts. The current snapshot FK and link/capture signatures require an alert. Extend them with explicit canonical source proof, retain existing history/FK restrictions, and update typed UI and permission projections. Classify operational urgency from the applicable configured policy, not the transport severity hint. Do not infer collector/Fleet capability from arbitrary payload labels. Then verify direct and urgent journeys, current-site denial, replay/concurrency, recovery and visible failure/retry states.
