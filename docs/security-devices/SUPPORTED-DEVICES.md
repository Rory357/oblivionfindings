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

---

## Network Discovery & Device Identification (Phase 3)

### Protocols & verification status

| Protocol / Source | Transport & Port | Purpose | Support level |
| --- | --- | --- | --- |
| **ICMP Echo + ARP / Neighbour Table** | ICMP + OS ARP (`arp -a`, `/proc/net/arp`, `ip neigh`) | Verifies genuine echo replies (rejecting Windows `Destination host unreachable` exit-code-0 false positives) and resolves Layer-2 MAC addresses on local subnets | **Tested on real hardware** (`172.16.0.0/24` LAN + Ubiquiti `UX7`) |
| **IEEE OUI & MA-M MAC Vendor Lookup** | Offline binary-searched dataset (`46,453` prefixes) | Resolves hardware manufacturers from 24-bit OUI and 28-bit MA-M prefixes while skipping locally-administered randomised MACs | **Tested on real hardware** |
| **Async TCP + HTTP Banners + TLS Certificates** | Bounded non-blocking TCP + HTTP `HEAD`/`GET` + TLS peer certificate Subject/SAN | Identifies management interfaces, RTSP cameras (`554`), JetDirect/IPP printers (`9100`/`631`), NAS appliances (`5000`/`5001`), and Windows/SSH endpoints | **Tested on real hardware** |
| **mDNS & DNS-SD** | Multicast UDP `224.0.0.251:5353` | Discovers `.local` hostnames and advertised services (`_rtsp._tcp`, `_onvif._tcp`, `_ipp._tcp`, `_pdl-datastream._tcp`, `_airplay._tcp`, `_googlecast._tcp`, `_smb._tcp`, `_ssh._tcp`) | **Tested on real hardware** |
| **SSDP & UPnP Device Description** | Multicast UDP `239.255.255.250:1900` + SSRF-guarded XML `GET` | Extracts UPnP `friendlyName`, `manufacturer`, `modelName`, and `deviceType` strictly within the approved scope CIDR | **Tested on real hardware** |
| **ONVIF WS-Discovery** | Multicast UDP `239.255.255.250:3702` SOAP `Probe` / `ProbeMatches` | Identifies `NetworkVideoTransmitter` IP cameras and NVRs (`onvif://www.onvif.org/hardware/...`, `name/...`) without credentials | **Built from vendor docs** |
| **SNMP v2c & SNMPv3 USM System MIB** | Pure-PHP ASN.1 BER over UDP `161` | Queries `sysDescr.0` (`1.3.6.1.2.1.1.1.0`), `sysObjectID.0` (`1.3.6.1.2.1.1.2.0`), and `sysName.0` (`1.3.6.1.2.1.1.5.0`) via read-only v2c or leased SNMPv3 `authPriv` | **Built from vendor docs** (verified against live UDP BER responder) |

### Official specifications cited

- **IETF RFC 6762 & RFC 6763** — *Multicast DNS* and *DNS-Based Service Discovery* (`224.0.0.251:5353`).
- **Open Connectivity Foundation / UPnP Forum** — *UPnP Device Architecture 2.0* (`M-SEARCH` `ssdp:all` on `239.255.255.250:1900` and `root/device` XML schema).
- **ONVIF** — *ONVIF Core Specification* & *WS-Discovery* (`http://www.onvif.org/ver10/network/wsdl`, `dn:NetworkVideoTransmitter` SOAP `Probe` on `239.255.255.250:3702`).
- **IETF RFC 3416, RFC 3418, RFC 3414, RFC 3826, & RFC 7860** — *SNMPv2c Protocol Operations*, *Management Information Base (MIB-II `system` group)*, and *SNMPv3 User-based Security Model (HMAC-SHA-2 / CFB128-AES-128)*.
- **IEEE Registration Authority** — Public *MA-L (OUI)* (`oui.csv`) and *MA-M* (`mam.csv`) registry assignments (<https://standards-oui.ieee.org/>).
