# V3 source contract findings

Read alongside frozen v1 source-contract.md and v2 source-contract-delta.md at baseline 2302ca33a95616e442a78e957ddce82d8db98669. Their privacy, retention, assignment, canonical geometry, job/realtime/cache and response boundaries still apply.

## Locate now

ClientController::locateNow authorises client viewing and tracker management, checks the current tracking privacy decision, assignment and canonical Device, then redirects to the governed Security Devices management workflow. LocateNowService::managementUrlForDevice requires the declared tracking.location_refresh capability and returns /security-devices/devices/{id}?section=management&action=tracking.location_refresh. The controller expressly requires identity confirmation and an operational reason before dispatch.

V3 demonstrates that existing workflow concept locally; it does not add a second command service or prove dispatch. A command acknowledgement is separate from a new timestamped device observation. Timeout/offline retain the older observation. Current access, assignment and command capability must be checked at dispatch and delivery in an eventual implementation. Approved device configuration must supply retry and timeout values. No displayed synthetic interval is operational policy. Location refresh does not silently refresh an older battery sample.

## Drawing and history

Canonical AssetGeofence circle/polygon geometry and existing Fleet drawing concepts remain reusable. New client-specific proposals keep purpose, schedule, authority and response separate. Geometry changes in the preview create inactive drafts, not a shared-boundary overwrite. The source findings about time_rules not being enforced by the inspected Fleet evaluation path remain unresolved. No new engine or automatic safe-area precedence is implied.

The synthetic editor supports pointer and keyboard adjustments, explicit completion, undo/redo and basic minimum-point/crossing/area checks. This is not production GIS validation or a real-world scale. Production circle containment, polygon topology, coordinate system and boundary version handling need the later agreed contract.

Date-range Activity restores v1's usable journey. Records, plans and staff responses have separate presentation; a selected historical observation does not replace the latest location. Failed requests are distinct from empty results. On loss of relevant access, map, history, drafts and pending local timers unmount. The preview's latest fixture date is not a retention policy. Backend enforcement, export, caching and realtime delivery remain unproved by UI fixtures.

## Scope

Single organisation across approved sites, roles, permissions, canonical ownership and privacy rules. No tenant transport, context switch, operational policy, production code or new package. The shared application and design references were read-only inputs.
