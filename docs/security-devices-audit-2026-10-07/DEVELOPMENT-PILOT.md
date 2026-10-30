# Development pilot: unified devices and incident response

7 October 2026 · User confirmed that Oblivion is still being built and nothing is in production. The user authorized creation of a development pilot instead of supplying real rollout counts.

This is a concrete synthetic pilot definition and scenario set. It creates no live site, device connection, hardware command or database record. Counts are proposed test coverage, not assumptions about future deployment size. Provider simulators and application seed/execution work belong to the subsequent implementation scope.

**Latest scope:** the user requires one complete release and broad equipment coverage. The 21-record base scenario below is not an MVP or a limit on final scope. Extend it using the [24-family market catalogue](MARKET-RESEARCH.md), [configurable operating model](TARGET-SCOPE.md) and [Rory conformance requirements](RORY-CONFORMANCE.md).

## Pilot estate

Use the existing organisation with two synthetic approved sites:

- **Development Pilot — Care Home:** two rooms, one corridor/entrance, network equipment, two care sensors, two camera references and two doors/readers. This site exercises the fall response and evidence timeline.
- **Development Pilot — Outreach:** a small remote network, a Windows network collector, a lone-worker tracker and vehicle tracking. This site exercises disconnection, buffering and site-scoped access.

Do not invent production addresses, credentials or vendor enrollment identifiers. Use opaque development IDs with a `devpilot-` prefix and a visibly simulated provider/transport. Runtime test adapters must remain restricted to the development fixture path. Product pages must never label simulated evidence as hardware verified.

## Representative device inventory: 21 records

- Four network devices: main gateway, switch, access point and outreach gateway.
- Two cameras: one UniFi-shaped fixture and one Hikvision-shaped fixture. Simulated media has an explicit synthetic watermark; unavailable/expired states are also required.
- Two access-control doors/readers: entrance and staff area, using the intended UniFi contract shape.
- Two alarm hubs: an Ajax-shaped fixture and a generic second provider fixture. Exact UniFi alarm product support remains undecided and must not be fabricated.
- Two Milesight-shaped care sensors, one per room. Exact model properties remain unclaimed until equipment selection; the pilot tests canonical fall/bed-exit event contracts.
- Three trackers: one vehicle, one lone worker and one synthetic supported person. Use existing fleet/person/lone-worker boundaries and consent fixtures.
- Four endpoint records: one Windows computer with native support, one Linux computer registered for inventory/visibility only, one organisation-owned Android device and one shared Android kiosk. Native computer agents and Obli are Windows-only; Linux site collectors remain required. Use native Oblivion agent/command contracts and read-only Intune observation contracts, reflecting the user's latest direction. Exercise an overlapping Windows profile without adding a duplicate canonical device or allowing duplicate workload execution. These remain simulated test modes until actual licensed API acceptance.
- Two collector installation records: Linux for the main site's isolated-network scenario and Windows for outreach. Central reachability remains the default where available; collectors are exercised deliberately for inaccessible networks.

The inventory count includes collector records and both access-control records as individual logical devices. Actual physical hub/reader/camera modelling will follow supported provider identities.

## Synthetic people and permissions

Use explicitly fictional records: two supported people, two workers, one Control Room operator, one H&S investigator and one IT administrator. Keep the two supported people in separate rooms at the same site to exercise incorrect correlation and privacy denial.

Permission fixtures include an operator with approved care-home access, an outreach-only manager, a worker allowed to report but not view CCTV, an investigator allowed to view only approved evidence types, and an IT administrator without clinical/location detail. Do not add another organisation or tenant. Human role names are illustrative; map them to existing permissions rather than introducing broad superuser bypasses.

## Primary fall timeline

Use a frozen clock such as 7 October 2026 at 14:00 NZ time, retaining UTC and explicit timezone conversion in test data. The sequence is synthetic and asserts no diagnosis or causation.

1. T−120 seconds: record a door/access event and a healthy camera observation.
2. T−30 seconds: record the relevant room's sensor observation and current historical assignment version.
3. T0: room A sensor reports a fall with a unique provider event ID; preserve critical severity, canonical device/site and safe normalized evidence.
4. T+2 seconds: replay the same event. It must not create another detection/incident.
5. T+10 seconds: room B sensor reports a separate fall event. It must remain distinguishable from room A despite the same site and time window.
6. T+20 seconds: authorized Control Room operator acknowledges room A and opens a visibly **Live now** camera session; this does not prove the past fall is on screen.
7. T+45 seconds: operator records the actual response action and confirms/links the existing incident journey. The fixture does not automatically establish person identity from room proximity.
8. T+120 seconds: a delayed observation arrives with separate occurrence/receipt times and declared clock uncertainty.
9. Investigator opens the same evidence set, requests the selected incident-time vendor reference and sees that one camera's recording is unavailable.
10. Simulate preservation requested, provider-confirmed preservation and preservation failure as separate outcomes. H&S review retains evidence gaps and assigned follow-up.

Use a provisional collection window of five minutes before and fifteen minutes after detection for fixture coverage. This is a development default awaiting the user's operational preference.

## Required variations

- Genuine fall/SOS during technical maintenance versus explicitly approved bounded sensor-test mode.
- Site loses connectivity, collector buffers, reconnects and replays without duplicating observations or commands.
- Collector enrollment is revoked; stale configuration or off-scope targets are rejected.
- Device changes room/person after the event; historical evidence remains correct and current access still applies.
- Viewer cannot access another site, another person's source evidence, restricted staff tracking or CCTV merely through an incident link/export.
- Live session expires, provider is offline, historical footage has expired, timestamp seeking is unsupported and preservation fails.
- Door events suggest an attendance entry for My Day confirmation; missing swipe, offsite work and overnight shifts require explicit review. HR owns resulting time and corrections.
- Endpoint provider refuses an unsupported action or the device is offline; the UI shows actual failure/pending status rather than local success.
- Unsupported firmware/configuration/alarm action is unavailable with a truthful reason; fixture support does not imply vendor support.

## Acceptance levels

These are internal test-evidence levels, not separate customer releases. Parameterised fixture variations must also cover internal/external/combined response and failed handoff; configurable collection windows/triggers; role/site/device/source permission changes; manual/assisted/dedicated-reader attendance; and read-only provider-supported Windows/macOS/Linux/Android/iOS/iPadOS visibility/ownership variations plus provider-specific specialist endpoints; native computer-agent acceptance applies only to Windows. Add nurse-call, environment/cold-chain, power and nonconnected care-equipment maintenance fixtures from the full catalogue.

**D1 — Synthetic development:** fixtures, source permissions, UI states, real internal pipeline tests and provider-contract simulators pass. No live-hardware claim.

**D2 — Installable laboratory pilot:** the unified Windows Computer/Site/Both package and Linux site-collector package install and run on test hosts; upgrade, rollback, revocation and offline replay are demonstrated. Native computer-agent/Obli acceptance is Windows-only, with distinct enrolled service/site-collector/tray privileges.

**D3 — Supported hardware witness:** repeat relevant journeys with selected real models, firmware, vendor APIs and permissions; record exactly what is supported. This later level is required before relying on those integrations operationally.

Start with D1's fall journey and the two urgent audit findings. Expand to network/collector, attendance/access, tracking and endpoint scenarios in the agreed order. D1 success is useful completion evidence for development but must not be relabelled as D3.
