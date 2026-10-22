# Group maintenance policy preview

This is the read-only Devices entry to the existing Monitoring maintenance-history owner. It answers which recorded technical maintenance rule applies to one current group member, one of its current monitors and a chosen instant. It creates no rule, receipt, command, provider operation or health/safety outcome. Groups are navigation context; they are not a policy dimension.

## Request boundary

`GET /security-devices/device-groups/{group}/maintenance-policy-preview` accepts no request body. Every response is private and not stored in caches. Send `Accept: application/json` and one of these exact query shapes:

- `mode=choices`, `expected_actor_id`, `device_id`.
- `mode=preview`, `expected_actor_id`, `device_id`, `monitor_id`, `at`.

IDs are canonical positive decimal strings. The parser rejects missing, additional, repeated, array-valued or whitespace-padded parameters. `at` is a complete UTC date/time ending in `Z`, with seconds and optionally one to six fractional digits. Invalid dates, year zero, offset notation and excess precision are rejected instead of normalized. Successful timestamps preserve microseconds in the canonical six-digit UTC representation.

Choices returns at most 50 current monitors belonging to the selected current member, with an explicit `truncated` flag. Empty results say `no_monitors`. The endpoint does not create monitors or accept another device's monitor. A truncated list is not the complete inventory and does not prove that an omitted monitor is unavailable.

## Current authority

One organisation operates across approved Sites. This adapter retains the existing group-management all-Sites boundary. It requires current group-management and Monitoring-management authority, an approved and verified internal actor matching `expected_actor_id`, current group membership, an active canonical Site, device visibility and the selected monitor's current device binding. It does not add a tenant boundary or grant permissions.

The adapter reuses `CurrentAuthorizationReads`, `SecurityDevicesAccessService` and `MaintenancePolicyAccess` inside a transaction. Current evidence is pinned with non-waiting shared reads. A cached allowed principal cannot restore withdrawn current grants. Missing/foreign/withdrawn resources return the same opaque `404 unavailable` projection; validation returns `422 invalid_request`; temporary database or bounded-result failure returns `503 unavailable`. Route authentication/module middleware still applies independently.

Current access answers whether the person may read now. The unchanged `MaintenanceHistory::resolve` separately determines the occurrence-effective revision and recorded device assignment. A current Site association must not be presented as historical assignment proof.

## Original owner result

Schema version 1 identifies Monitoring as owner, declares only Site/device/monitor dimensions and orders precedence as monitor, device, Site. It explicitly declares unsupported group inheritance. `commits_change`, `execution_authority`, `provider_action`, `health_claim` and `safety_response_suppression` are all false.

The adapter retains the original history result: occurrence and interpretation timestamps, clock uncertainty, state/reason, winning revision, matching and overridden revisions, full attributed snapshots and recorded assignment. It bounds matching plus overridden revisions to 200 and the encoded resolution to 262144 bytes. It does not compute a replacement winner, flatten a conflict, invent history for legacy rules, infer footage or suppress a real safety response.

States remain `applied`, `none`, `conflict`, `unsupported` and `unavailable`. A winning rule uses the existing inclusive-start/exclusive-end schedule and immutable revision interpretation. Preview is a dated read; executing a future operation requires its own current policy and authority checks.

## Reader and recovery

The existing group member Actions menu opens the shared three-step reader: Monitor and time, Policy preview, Full records. New Zealand local-time entry rejects a skipped daylight-saving time and requires choosing which repeated time is intended. Exact UTC input preserves all supported fractional digits.

The reader binds each request to its actor, group, member, monitor and chosen instant. It aborts an older owned request and ignores late, aborted or unmounted results. A response with inconsistent identity, Site, declared capability, result instant or rule scope cannot become displayed evidence. Account/provenance/permission loss withdraws private facts and requires a full group refresh. Temporary failure, timeout or network loss retains an explicitly dated prior result for review and offers a fresh read. A prior result is not described as a newly successful read.

Each request has a 30-second timeout. Closing aborts the read and restores focus to the selected device's Actions button. Full records retain original fields and use the shared scrollable reader/footer on narrow screens.

## Verification status, 18 October 2026

Frontend K passes 41 tests across the affected three suites, including actual one-off rule shapes (`recurrence=null`), a winning monitor rule, overridden device/Site rules, conflicting one-off rules, malformed recurrence and foreign-monitor rejection. Full records display original null recurrence as Does not repeat without modifying the data. Scoped lint G passes. Full TypeScript F has no owned errors but still fails for unchanged workforce `ByRoleOptions.exact` and handover `Filters.status` errors; only final test heading-selector strings changed afterward in TypeScript source.

Native A is a preserved failed epoch: 11 failed / 4 passed / 71 assertions. Laravel's `getJson` helper sends an empty JSON array body even for GET. That deliberately violates the endpoint's no-body contract, so A does not prove positive resolver behavior or the intended query-negative conditions. B replaces every intended query-only test request with a body-free GET and JSON Accept, retaining explicit unexpected-body rejection. Production validation was unchanged.

Native B is also a preserved failed epoch: 13 passed / 2 failed / 317 assertions. A successful original owner response was exported, but empty-monitor and membership-chain positives were unavailable. The adapter had hydrated an unrestricted join, allowing the membership row ID to overwrite the canonical device ID. Both member reads now explicitly select `devices.*`. A read-only MySQL constant-query reproduction demonstrates the original collision. C adds a real foreign device whose ID is deliberately the current membership row ID, checks both modes return only the requested member/monitor/Site, and retains foreign-member and detach denial. Permission/account withdrawal cases now require successful reads before each withdrawal and after restoration so generic failure cannot masquerade as correct denial. Corrected whole-suite C passes all 16 cases with 378 assertions (terminal exit 0); both PHP sources remain identical to its pinned source.

Ten inspected browser frames retain separate source epochs. The earlier five cover desktop no-rule, narrow full-record reachability, access withdrawal and exact-device action focus. The later five show one-off winning/overridden records at effective390/312 widths and an actual stopped-loopback connection failure retaining an explicitly dated result. Frame08 is a viewport transition; frame09 verifies the settled bottom. Transport/data remain synthetic. Final epoch C adds six inspected frames (11–16) using the corrected C producer export with original identifiers. They show the winning rule and original revision, reachable one-off record bottom/footer at CSS390, a successful preview followed by temporary 503 and a successful third read, and denial after successful choices. Private reader facts are withdrawn after denial. Closing returns focus to that member’s Actions button. Epoch C uses original producer data through synthetic transport and fixture page/auth; it is not authenticated browser/backend acceptance. Earlier CSS312 evidence remains separately attributed to epoch B.

The first producer-reader runner accidentally inherited the full root frontend include through configuration merging; it was interrupted and is unaccepted. Its replacement overrides the include list and passes the exact test file as a positional filter. Test listing verifies exactly one intended test. The corrected native C producer-to-reader check passes exactly one intended test with source and producer output unchanged. B’s original exports remain separately preserved.

Evidence is under `.endpoint-tools/sd-group-maintenance-preview-20261018`; `CHECKPOINT.md` records later status. No real camera, installed collector, authenticated browser/backend session, concurrent-writer acceptance or complete customer release is implied by these selected checks. The H&S owner has installed a separately receipted thirteen-file dependency composition, rehashed here with thirteen matches, but runtime/consumer/window/attachment acceptance remains outstanding. Do not repeat that source import or treat it as an incident-workflow release.

18 October group maintenance preview: the selected read package now passes separate frontend K41/0, native C16/378 and original-producer reader C1/0, scoped lint/PHP style/diff checks, and final actual-component synthetic-browser producer/recovery/denial checks. The canonical-member join collision and one-off null recurrence defects are corrected. Current roles, approved Site, membership and monitor binding govern reads; unchanged Monitoring owns revision history and monitor>device>Site precedence, with no group inheritance or equipment/health/safety action. Original history/CCTV sources and declared prior evidence are preserved, and owned schema/process/listener/tab cleanup is complete. See GROUP-MAINTENANCE-PREVIEW.md, CHECKPOINT.md and .endpoint-tools/sd-group-maintenance-preview-20261018/final-check.json for exact source and separate evidence. Global types still have two unrelated errors. This is selected serial/source/synthetic acceptance; authenticated browser, concurrent-writer, complete policy/module/H&S/hardware/installed/operating/customer-release gates remain open. Human continuation resumed; durable app goal remains paused.
