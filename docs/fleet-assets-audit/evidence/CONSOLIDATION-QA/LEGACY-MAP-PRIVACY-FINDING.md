# Legacy Fleet map privacy gap — approval required before production correction

Observed on combined application source f3fd2f77657c7fe09022ff69562e8c3487c9668b, 28 September 2026. No application edit has been made for this finding.

The focused synthetic probe reproduces HTTP 200 on GET /fleet-assets/map for an actor with only assets.viewAny, and separately only assets.viewAssigned. Neither actor has fleet.viewAny, assets.geofences.manage or controlRoom.alerts.view. Both receive two local vehicle positions (one consent-blocked, one within an active personal trip) and an open-alert count of two. The original failure output and JSON observations are retained in map-checks.log, map-checks.xml and legacy-map-probe-observations.jsonl beside this note.

The route middleware admits asset readers. LiveMapController redirects Fleet/map-capable actors into the canonical workspace, but its remaining fall-through directly projects fleetState coordinates and counts alerts. Thus Site filtering alone does not enforce record permission, assignment, consent or personal-trip privacy. This is present in the existing Main source, not introduced by the package merge.

Proposed minimal correction for Main review: make LiveMapController a compatibility redirect only; require fleet.viewAny OR assets.geofences.manage before redirecting to /fleet-assets/geofences?tab=map, and return 403 for asset-only callers. Keep the existing route name and URL. Delete the obsolete raw projection branch so it cannot bypass the canonical workspace. The target workspace already enforces the same capabilities plus canonical approved-Site/resource and consent/personal-trip projections. No grant of tracking authority, schema change, or tenant boundary is proposed.

Required affected checks after authorization: asset-only denial for both asset permissions; Fleet and geofence-manager redirect; permitted/foreign Site resources; consent/personal-trip and alert-permission projections; existing PKG-07, PKG-02B maps, Fleet Overview contracts and architecture checks.

This proposal changes the previously admitted asset-only legacy route, so production code is held for Main's requested review. Other compatibility work continues.
