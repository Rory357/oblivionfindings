# Supported Security & Devices Hardware and Protocols

This document records the exact protocol specifications, supported capabilities, and honest verification levels for hardware integrated into Oblivion Findings' Security & Devices workspace.

## Support levels

- **Tested on real hardware** — Verified end-to-end against physical units connected to Oblivion Findings.
- **Built from vendor docs** — Implemented directly from official vendor protocol specifications and verified against automated protocol/unit suites.
- **Planned** — Scheduled for upcoming phases; not yet active in production.

---

## Tracking — Queclink `@Track` Air Interface Protocol

### Models & verification status

| Series / Model | Category | Transport | Support level |
| --- | --- | --- | --- |
| **Queclink GL30M / GL30MEU / GL300** (`gl30m`) | Personal safety & care tracker | Direct TCP `@Track` ASCII frames (`+RESP:`, `+BUFF:`, `+ACK:`, `AT+GT...$`) | **Tested on real hardware** (`Care tracker 867963069916998`) |
| **Queclink GV500CG** (`gv500cg`) | OBD-II vehicle telematics tracker | Direct TCP `@Track` ASCII frames (`+RESP:`, `+BUFF:`, `+ACK:`, `AT+GT...$`) | **Built from vendor docs** |

### Official vendor protocol specifications cited

- **Queclink Wireless Solutions** — *GL30MEU @Track Air Interface Protocol Specification*, Document `GL30MEUR01`, Revision `2.04` (and base GL300 series reference `TRACGL300AN001`):
  - `§3.2.1.2` `AT+GTSRI` — Backend server registration (`SRI`: primary/backup host & port, heartbeat interval, SACK enable).
  - `§3.2.2.1` `AT+GTCFG` — Global configuration (`CFG`: continuous send interval, low-battery threshold, SOS report mode, GNSS & Wi-Fi reporting).
  - `§3.2.2.3` `AT+GTDOG` — Protocol watchdog (`DOG`: scheduled reboot mode, interval in days, reboot time `HHMM`, report before reboot).
  - `§3.2.2.5` `AT+GTNMD` — Non-movement detection (`NMD`: rest vs movement send interval).
  - `§3.2.8` `AT+GTRTO` — Real-time operation sub-commands:
    - `1` (`GPS`) — Request immediate position fix (`+RESP:GTRTL`).
    - `2` (`READ`) — Read active configuration snapshot (`+RESP:GTALM` across all sections or a 3-letter section code).
    - `3` (`REBOOT`) — Reboot tracker (`+ACK:GTRTO`, requires operator confirmation).
    - `A` (`INF`) — Request live device status, battery, and cellular signal report (`+RESP:GTINF`).
- **Queclink Wireless Solutions** — *GV500CG @Track Air Interface Protocol Specification*, Document `TRACGV500CGAN005`, Revision `5.01`:
  - `§3.2.5` `AT+GTDOG` — Scheduled watchdog reboot configuration.
  - `§3.4.1` `AT+GTFRI` — Fixed report information (`Send Interval` in seconds, ignition/period reporting).
  - `§3.5.1` `AT+GTRTO` — Real-time operation sub-commands (`1` GPS, `2` READ, `3` REBOOT, `A` INF).
- Vendor documentation portal: <https://www.queclink.com/> (Queclink `@Track` Air Interface Protocol manuals distributed via Queclink technical documentation).

### Security & privacy controls on the Queclink Unit Console

1. **Command password redaction & encryption at rest:** Outbound `AT+GT...$` commands encrypt raw payloads via `QueclinkPendingCommand::$raw_command_encrypted` and redact the device password (`[REDACTED]`) in every console message and reply view.
2. **Resident & staff location privacy:** Last position coordinates and raw `+RESP:` / `+BUFF:` GNSS fields are gated by `PersonalTrackingPrivacyService` and `assets.telemetry.view`. Unassigned trackers or client assignments without active location consent never expose coordinates.
3. **Confirmation on state-changing commands:** Read-only queries (`Request current location`, `Read configuration`, `Request device status`) run immediately for authorised operators; state-changing or disruptive commands (`Reboot unit`, `Update reporting interval`, `Update watchdog`) require explicit confirmation and a recorded reason in the audit log.
