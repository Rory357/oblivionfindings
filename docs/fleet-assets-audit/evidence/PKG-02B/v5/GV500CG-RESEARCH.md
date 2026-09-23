# GV500CG capability research — 22 September 2026

The user confirmed GV500CG. This note supports the isolated mockup; it is not a device certification or a completed production integration.

## Manufacturer sources

- [Queclink GV500CG datasheet](https://queclink.com.br/wp-content/uploads/2025/02/GV500CG.pdf): GNSS tracking, virtual ignition, crash and driving-behaviour monitoring, geofences, BLE 5.2, scheduled reporting, OTA capability and buffered messages. Supply input is 8–32 V; the internal backup battery is a separate component.
- [Queclink User Manual TRACGV500CGUM001, version 1.00](https://easynt.com/wp-content/uploads/2024/10/queclink-gv500cg-user-setup-manual.pdf), manufacturer-authored document hosted by a distributor: sections 2.2 and 2.4 establish the power-only OBD interface. Section 3.6 describes the accelerometer. The design therefore does not claim direct ECU VIN, dashboard odometer or diagnostic-code extraction from CG.
- [Queclink FCC filing](https://fccid.io/YQD-GV500CG/User-Manual/Users-Manual-7775697) independently identifies the manufacturer and device manual. The filing mirror did not expose its PDF through the available fetch route; the accessible manufacturer-authored manual above was used for content.

## Integration evidence and limits

[Wialon's CG integration catalogue](https://wialon.com/en/gps-hardware/obd/queclink-gv500cg) describes external-supply voltage, tracker battery, derived motion/ignition states, mileage and crash correlation fields. This catalogue also contains generic CAN and other-family fields. Its presence is not proof of CG hardware capability; installed firmware, protocol and actual reports must be checked. The UI's voltage values and event examples are synthetic.

Tracker distance is treated as a calibrated estimate. A retained dashboard observation anchors the planning feed. A later manual observation, stale report or missing tracker prevents silent continued use of that calibration. Dashboard evidence and compliance expiry dates remain independent.

ECU diagnostic faults from a separately integrated source can follow the same Control Room triage workflow. The mockup labels that external source explicitly. BLE accessories require separately supported hardware, pairing and firmware validation; OTA acknowledgement is not equivalent to command dispatch.

## Local integration gaps found (read-only review)

- `app/Services/Queclink/AtTrackProtocolParser.php` handles virtual ignition, speed alarms and generic harsh behaviour. No explicit crash-normalization path was found in the reviewed cases. Towing is grouped with tamper; it needs a distinct semantic review.
- `app/Services/Fleet/Telemetry/QueclinkAdapter.php` has generic odometer fields without a CG-specific dashboard-versus-distance provenance contract. Vehicle supply voltage and tracker backup voltage need distinct normalized fields and units.
- `app/Services/Fleet/FleetDrivingMetricsService.php` contains application scoring weights. The parser's generic harsh-behaviour event requires validated subtype mapping. Repeated speed reports must not become multiple independent violations, and a fixed speed threshold must not be labelled the legal road speed limit.
- Existing Control Room ingestion is not proof of end-to-end crash or vehicle-fault routing. Required work includes event correlation, timestamps, retries, ownership, triage decisions, permitted Maintenance links, and independent release.

No production parser, telemetry adapter, scoring service or Control Room transport was changed. These findings are retained locally for a later Main handoff.
