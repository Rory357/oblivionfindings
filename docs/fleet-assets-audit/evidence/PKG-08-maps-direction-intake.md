# PKG-08 — verified Maps direction and integration boundary

Owner: MAIN ASTRA. Revision: 2. Updated: 2026-09-27.

The Designer's telemetry research follow-up has now been received and independently spot-checked: [telemetry intake](PKG-08-telemetry-research-intake.md). Existing device-management/profile ownership should be reused; no universal tracker editor or shared retention slider is approved. Main independently reproduces existing MAIN-TELEM-01 (HDOP copied directly into metre accuracy) without database/device access. End-to-end impact and correction remain an explicit later acceptance item. The earlier pending-research wording below records the first handoff, not current absence of findings.

Main independently read actual user messages in **OF | PKG-08 Settings & Setup | DESIGNER…**, thread `01a0e1a8-1548-7873-a062-e0db43eed7e1`. Message `01a0e204-f048-7f62-a133-78ad9fe767d7` asks to fully bring in Google Maps as an optional provider. Later message `01a0e21a-f960-7632-8484-c28f7d1f468e` says “ok perfect just let main know so that the code for all location etc is ok”, then asks for further Settings/telemetry research and whether unique trackers belong in device settings. Main verified these user messages directly, rather than treating the peer handoff or ambient browser URL as authority.

The accepted **design direction** is OpenStreetMap by default, with a complete optional Google setup covering map display, Places New address search, Geocoding address labels/reverse lookup, and Routes/travel times. Each capability needs its own configuration and meaningful verification state; a working basemap does not establish search, labels or routing capability. This refines the existing08 provider/capability scope. It does not authorise live API use, keys, billing, implementation, publication or rewriting Rory's references. The Settings design remains under refinement and exact implementation approval has not been inferred from “ok perfect”.

Frozen synthetic v3 is [the Maps preview](http://127.0.0.1:8790/#maps), under `C:/Users/steph/.codex/worktrees/pkg08-settings-design/oblivionfindings/docs/fleet-assets-audit/previews/PKG-08/v3`. Main independently rehashed all45 freeze-manifest entries with zero mismatches and read20 passing owner browser journeys. Bundle SHA256 is `BE77669ED38C7AECC11C4E9507C19163CBB3813D9F9438F2EC9DDC5AB7C46E49`; freeze-manifest SHA256 is `7B61835544EC11DCEFEC3F0A579F1E2C541B3010FD51E7F0423B6C9E41427AF1`. Baseline262bf39cc and simulated/no-external-request status are recorded by the owner. This is artifact verification, not a new Main browser run or completed provider integration.

## Coordinated implementation review

Review shared map primitives, Fleet vehicle and Asset location, Maps & boundaries, Transport, Client Location and People Locations together. Include their reused Control Room/site-map surfaces when affected by the shared primitives. Keep canonical map geometry/edit history, authorised markers and selection independent of the provider; preserve usable loading/failure/list states and attribution. Google-derived content needs API-specific display, storage and fallback handling checked against the terms current at implementation; do not apply one blanket assumption to every API or overlay.

Separate browser-restricted map credentials from server-only credentials and verify each selected service independently. Provider administration cannot grant staff/client tracking, broaden site or record access, substitute consent, or choose a personal source on another owner's behalf. Existing role/site/privacy and canonical source boundaries remain authoritative. Preserve application-owned boundary editing. Main checked Google's official [completed deprecations](https://developers.google.com/maps/deprecations#drawing-library) on2026-09-27: Drawing Library is unavailable fromMay2026; this is not a basis for introducing that dependency into new work.

An initial read-only source inventory on published926b4981 confirms this cross-screen work is still an implementation dependency, not an already completed feature:

- `config/fleet.php:18` exposes separate boundary tiles, forward lookup and reverse-geocoding settings. A configured option or disabled-by-default Google reverse-geocoder path is not proof of a running service.
- `resources/js/components/leaflet-map.tsx:99`, `resources/js/components/client-location/client-location-map.tsx:50`, `resources/js/components/geofence-draw-map.tsx:122` and `resources/js/pages/control-room/map.tsx:258` contain OSM tile defaults or direct references. Verify how each receives settings and transitions providers before claiming shared coverage.
- `app/Http/Controllers/Sites/SiteGeocodingController.php:21` uses the existing Nominatim-compatible configurable endpoint and shared request budget; the published Transport lookup delegates to it. This does not implement Google Places by merely replacing a URL.
- `app/Services/Fleet/Geocoding/GoogleReverseGeocoder.php:19` is one existing reverse lookup path. Its presence does not establish Google map display, Places, Routes or correct capability/fallback handling in other modules.

This is a bounded inventory, not an exhaustive audit or a finding that every currently published OSM flow is defective. No application or configuration file was changed.

## Additional design research, still pending

Service health/last successful check/fallback visibility, usage/quota information with billing handoff, and review of affected screens before service changes were suggested and accepted in principle. They are proposals beyond frozen v3, not implemented v3 features. Practical setup tests and key replacement also remain candidate design ideas unless the later exact mockup makes their scope explicit.

The same Designer is researching telemetry ownership: shared policy versus reusable model profiles versus individual tracker settings, reusing the existing device-management owner. Main awaits the evidence-based findings; no new tracker system, protocol support, collection frequency, retention default, battery/movement inference or individual-device command is approved by this intake. Keep explicit capability/unknown/stale states and personal tracking authority intact. Do not start another worker or duplicate the research.
