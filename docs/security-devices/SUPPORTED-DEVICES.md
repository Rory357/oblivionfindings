# Supported Security & Devices Hardware and Protocols

> **Corrected 30 Oct 2026 (Phase A, `docs/security-devices/GOAL.md`).** Earlier versions of this page said
> "Tested on real hardware" for UniFi, Queclink and several discovery protocols with no recorded walk.
> Those marks now read "Not proven on hardware". Report-only alarm brands (Ajax, Arrowhead, Bosch, DSC,
> ICT Protege, Inner Range Integriti, Paradox) are **view only**: Oblivion can't arm, disarm or unlock
> them. UniFi PoE power-cycle and restart are not built. The alarm receiver (SIA DC-09) is being fixed in
> Phase A; until then it rejects real panels. The live list is Setup › Supported devices; walks are
> recorded in `docs/security-devices/HARDWARE-WALKS.md`.

This document records the exact protocol specifications, supported capabilities, and honest verification levels for hardware integrated into Oblivion Findings' Security & Devices workspace.

## Support levels

- **Tested on real hardware** — Walked end-to-end on physical units, with the walk recorded in `HARDWARE-WALKS.md`. Nothing has this level yet.
- **Built from vendor docs** — Implemented directly from official vendor protocol specifications and verified against automated protocol/unit suites.
- **Planned** — Scheduled for upcoming phases; not yet active in production.

---

## Tracking — Queclink `@Track` Air Interface Protocol

### Models & verification status

| Series / Model | Category | Transport | Support level |
| --- | --- | --- | --- |
| **Queclink GL30M / GL30MEU / GL300** (`gl30m`) | Personal safety & care tracker | Direct TCP `@Track` ASCII frames (`+RESP:`, `+BUFF:`, `+ACK:`, `AT+GT...$`) | **Not proven on hardware** (claimed before 30 Oct with no recorded walk) (`Care tracker 867963069916998`) |
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
| **ICMP Echo + ARP / Neighbour Table** | ICMP + OS ARP (`arp -a`, `/proc/net/arp`, `ip neigh`) | Verifies genuine echo replies (rejecting Windows `Destination host unreachable` exit-code-0 false positives) and resolves Layer-2 MAC addresses on local subnets | **Not proven on hardware** (claimed before 30 Oct with no recorded walk) (`172.16.0.0/24` LAN + Ubiquiti `UX7`) |
| **IEEE OUI & MA-M MAC Vendor Lookup** | Offline binary-searched dataset (`46,453` prefixes) | Resolves hardware manufacturers from 24-bit OUI and 28-bit MA-M prefixes while skipping locally-administered randomised MACs | **Not proven on hardware** (claimed before 30 Oct with no recorded walk) |
| **Async TCP + HTTP Banners + TLS Certificates** | Bounded non-blocking TCP + HTTP `HEAD`/`GET` + TLS peer certificate Subject/SAN | Identifies management interfaces, RTSP cameras (`554`), JetDirect/IPP printers (`9100`/`631`), NAS appliances (`5000`/`5001`), and Windows/SSH endpoints | **Not proven on hardware** (claimed before 30 Oct with no recorded walk) |
| **mDNS & DNS-SD** | Multicast UDP `224.0.0.251:5353` | Discovers `.local` hostnames and advertised services (`_rtsp._tcp`, `_onvif._tcp`, `_ipp._tcp`, `_pdl-datastream._tcp`, `_airplay._tcp`, `_googlecast._tcp`, `_smb._tcp`, `_ssh._tcp`) | **Not proven on hardware** (claimed before 30 Oct with no recorded walk) |
| **SSDP & UPnP Device Description** | Multicast UDP `239.255.255.250:1900` + SSRF-guarded XML `GET` | Extracts UPnP `friendlyName`, `manufacturer`, `modelName`, and `deviceType` strictly within the approved scope CIDR | **Not proven on hardware** (claimed before 30 Oct with no recorded walk) |
| **ONVIF WS-Discovery** | Multicast UDP `239.255.255.250:3702` SOAP `Probe` / `ProbeMatches` | Identifies `NetworkVideoTransmitter` IP cameras and NVRs (`onvif://www.onvif.org/hardware/...`, `name/...`) without credentials | **Built from vendor docs** |
| **SNMP v2c & SNMPv3 USM System MIB** | Pure-PHP ASN.1 BER over UDP `161` | Queries `sysDescr.0` (`1.3.6.1.2.1.1.1.0`), `sysObjectID.0` (`1.3.6.1.2.1.1.2.0`), and `sysName.0` (`1.3.6.1.2.1.1.5.0`) via read-only v2c or leased SNMPv3 `authPriv` | **Built from vendor docs** (verified against live UDP BER responder) |

### Official specifications cited

- **IETF RFC 6762 & RFC 6763** — *Multicast DNS* and *DNS-Based Service Discovery* (`224.0.0.251:5353`).
- **Open Connectivity Foundation / UPnP Forum** — *UPnP Device Architecture 2.0* (`M-SEARCH` `ssdp:all` on `239.255.255.250:1900` and `root/device` XML schema).
- **ONVIF** — *ONVIF Core Specification* & *WS-Discovery* (`http://www.onvif.org/ver10/network/wsdl`, `dn:NetworkVideoTransmitter` SOAP `Probe` on `239.255.255.250:3702`).
- **IETF RFC 3416, RFC 3418, RFC 3414, RFC 3826, & RFC 7860** — *SNMPv2c Protocol Operations*, *Management Information Base (MIB-II `system` group)*, and *SNMPv3 User-based Security Model (HMAC-SHA-2 / CFB128-AES-128)*.
- **IEEE Registration Authority** — Public *MA-L (OUI)* (`oui.csv`) and *MA-M* (`mam.csv`) registry assignments (<https://standards-oui.ieee.org/>).

---

## Automatic Site Topology & Camera ONVIF Controls (Phase 4)

### Protocols & verification status

| Protocol / Source | Transport & MIB / Endpoint | Purpose | Support level |
| --- | --- | --- | --- |
| **IEEE 802.1AB LLDP (`LLDP-MIB`)** | SNMPv2c / SNMPv3 `GetNextRequest-PDU` (`1.0.8802.1.1.2.1.3.7.1` & `1.0.8802.1.1.2.1.4.1.1`) | Discovers Layer-2 chassis MACs, port labels, and system names between gateways, switches, access points, and VoIP/camera endpoints | **Built from vendor docs** |
| **Cisco Discovery Protocol (`CISCO-CDP-MIB`)** | SNMPv2c / SNMPv3 `GetNextRequest-PDU` (`1.3.6.1.4.1.9.9.23.1.2.1.1`) | Discovers Cisco & CDP-speaking switch/router/AP neighbours, remote IPv4s, platforms, and uplink port names | **Built from vendor docs** |
| **SNMP Bridge Forwarding & ARP Tables (`IF-MIB`, `BRIDGE-MIB`, `Q-BRIDGE-MIB`, `IP-MIB`)** | SNMPv2c / SNMPv3 `GetNextRequest-PDU` (`1.3.6.1.2.1.31.1.1.1.1`, `1.3.6.1.2.1.17.4.3.1`, `1.3.6.1.2.1.17.7.1.2.2.1`, `1.3.6.1.2.1.4.22.1.2`) | Maps learned endpoint MAC addresses to switch bridge ports and resolves subnet ARP tables | **Built from vendor docs** |
| **Ubiquiti UniFi Local Network API** | HTTPS `443` (`/proxy/network/api/s/{site}/stat/device` & `stat/sta` on UniFi OS, or `8443` `/api/s/{site}/...`) | Queries gateway, switch, and access-point uplink hierarchy (`uplink.uplink_mac`, `uplink_remote_port`) and wireless client AP associations (`ap_mac`, `essid`) via Site hub | **Built from vendor docs** (`UX7` LAN gateway topology verified on `172.16.0.0/24`) |
| **ONVIF Media Snapshot & PTZ Service** | HTTP/HTTPS SOAP 1.2 + WS-UsernameToken Digest (`GetProfiles`, `GetSnapshotUri`, `ContinuousMove`, `Stop`, `GotoHomePosition`) | Captures SSRF-guarded live JPEG/PNG/WebP snapshots (`<= 2 MB`, same-IP verification) and operates pan/tilt/zoom cameras with full audit logging | **Built from vendor docs** |

### Official specifications cited

- **IEEE Std 802.1AB-2016** — *Station and Media Access Control Connectivity Discovery (LLDP)*, MIB root `iso(1).std(0).iso8802(8802).ieee802dot1(1).ieee802dot1mibs(1).lldpMIB(2)` (<https://standards.ieee.org/ieee/802.1AB/6047/>).
- **Cisco Systems** — *CISCO-CDP-MIB* (`1.3.6.1.4.1.9.9.23.1.2.1.1` `cdpCacheEntry`: `cdpCacheAddress`, `cdpCacheDeviceId`, `cdpCacheDevicePort`, `cdpCachePlatform`) (<https://snmp.cloudapps.cisco.com/Support/SNMP/do/BrowseOID.do?local=en&translate=Translate&objectInput=1.3.6.1.4.1.9.9.23>).
- **IETF RFC 2863 (`IF-MIB`), RFC 4188 (`BRIDGE-MIB`), RFC 4363 (`Q-BRIDGE-MIB`), & RFC 4293 (`IP-MIB`)** — Standard interface naming (`ifName`), transparent bridge forwarding database (`dot1dTpFdbEntry`), VLAN-aware forwarding database (`dot1qTpFdbEntry`), and IPv4 address-translation tables (<https://datatracker.ietf.org/doc/html/rfc4188>, <https://datatracker.ietf.org/doc/html/rfc4363>, <https://datatracker.ietf.org/doc/html/rfc2863>, <https://datatracker.ietf.org/doc/html/rfc4293>).
- **Ubiquiti Inc.** — *UniFi Network Integration & Local Controller API* (<https://developer.ui.com/>).
- **ONVIF** — *ONVIF Core Specification*, *ONVIF Media Service Specification* (`ver10/media/wsdl`: `GetProfiles`, `GetSnapshotUri`), and *ONVIF PTZ Service Specification* (`ver20/ptz/wsdl`: `ContinuousMove`, `Stop`, `GotoHomePosition`) (<https://www.onvif.org/profiles/specifications/>, <https://www.onvif.org/specs/core/ONVIF-Core-Specification.pdf>, <https://www.onvif.org/specs/srv/streaming/ONVIF-Streaming-Spec.pdf>, <https://www.onvif.org/specs/srv/ptz/ONVIF-PTZ-Service-Spec.pdf>).


---

## Brand & Protocol Driver Catalogue (Phase 5)

Every integration in Security & Devices implements the Home-Assistant-style `DeviceDriver` contract (`DeviceDriverRegistry` + `/security-devices/supported-devices`), declaring its supported models, exact capabilities, confirmation-locked actions, known limitations, honest verification badge (`Tested on real hardware` / `Built from vendor docs` / `Planned`), and official vendor or standards specifications.

### 1. Security cameras — ONVIF Profile S & T and UniFi Protect Local API

| Brand / Driver | Key | Transport & Auth | Capabilities | Support level |
| --- | --- | --- | --- | --- |
| **Standard ONVIF & TP-Link Tapo** | `camera-onvif-standard` | ONVIF Profile S & T (`devicemgmt`, `Media1`, `Media2` H.265/HEVC, `PTZ`), WS-UsernameToken + IETF RFC 7616 HTTP Digest (`SHA-256` / `MD5`), ports `80`/`443`/`8000`/`2020` (Tapo) | Live stream URIs (credentials stripped), snapshot (`<= 2 MB`), PTZ (`ContinuousMove`, `Stop`, `GotoHomePosition`) | **Built from vendor docs** (`172.16.0.78` Tapo C120 LAN discovery verified; ONVIF port `2020` & RTSP `554` open once Camera Account is enabled in the Tapo app) |
| **Hikvision** | `camera.hikvision_onvif` | ONVIF Profile S & T (`Media1`, `Media2`, `PTZ`) + Hikvision NVR channel discovery via Site hub | Live stream URIs, snapshot, PTZ | **Built from vendor docs** |
| **Dahua** | `camera.dahua_onvif` | ONVIF Profile S & T (`Media1`, `Media2`, `PTZ`) + RFC 7616 HTTP Digest | Live stream URIs, snapshot, PTZ | **Built from vendor docs** |
| **Uniview (UNV)** | `camera.uniview_onvif` | ONVIF Profile S & T (`Media1`, `Media2`, `PTZ`) + WS-UsernameToken / HTTP Digest | Live stream URIs, snapshot, PTZ | **Built from vendor docs** |
| **Axis Communications** | `camera.axis_onvif` | ONVIF Profile S & T (`Media1`, `Media2`, `PTZ`) + Axis ONVIF account auth | Live stream URIs, snapshot, PTZ | **Built from vendor docs** |
| **Hanwha Vision (Wisenet)** | `camera.hanwha_onvif` | ONVIF Profile S & T (`Media1`, `Media2`, `PTZ`) + HTTP Digest (`SHA-256`) | Live stream URIs, snapshot, PTZ | **Built from vendor docs** |
| **Ubiquiti UniFi Protect** | `camera.unifi_protect` | Official UniFi OS Protect Integration API v1 (`https://<nvr>/proxy/protect/integration/v1/*`, `X-API-KEY`) via Site hub (`UniFiProtectLocalClient`) | Camera inventory, high-resolution JPEG snapshot, sanitised `rtsps://` stream URIs (query tokens stripped before persistence) | **Built from vendor docs** |

**Official camera specifications cited:**
- **ONVIF** — *Profile S Specification*, *Profile T Specification*, *Media Service (`ver10`)*, *Media2 Service (`ver20`)*, and *PTZ Service (`ver20`)* (<https://www.onvif.org/profiles/profile-s/>, <https://www.onvif.org/profiles/profile-t/>, <https://www.onvif.org/specs/srv/media/ONVIF-Media2-Service-Spec.pdf>).
- **IETF RFC 7616** — *HTTP Digest Access Authentication* (`SHA-256` & `MD5`, `qop=auth`) (<https://datatracker.ietf.org/doc/html/rfc7616>).
- **TP-Link** — *How to view Tapo camera on PC/NAS/NVR through RTSP/ONVIF (`:2020/onvif/device_service`, `:554/stream1`)* (<https://www.tp-link.com/us/support/faq/2680/>).
- **Hikvision**, **Dahua**, **Uniview**, **Axis Communications**, & **Hanwha Vision** — Official ONVIF integration and developer portals (<https://tpp.hikvision.com/>, <https://www.dahuasecurity.com/support/downloadCenter/softwares>, <https://global.uniview.com/>, <https://developer.axis.com/vapix/onvif/>, <https://www.hanwhavision.com/>).
- **Ubiquiti Inc.** — *UniFi Protect Official Integration API (`integration/v1`)* (<https://developer.ui.com/>, UniFi Protect Console -> Settings -> Control Plane -> Integrations).

---

### 2. Intruder & perimeter alarms — ANSI/SIA DC-09, NZ local REST APIs, and honest `Planned` roadmap

| Brand / Driver | Key | Transport | Capabilities & Governance | Support level |
| --- | --- | --- | --- | --- |
| **ANSI/SIA DC-09 & Ademco Contact ID (DC-05) IP Receiver** | `alarm.sia_dc09_contact_id` | TCP/UDP IP receiver (`SiaDc09FrameDecoder` CRC-16/ARC `0x8005`, optional AES-128/256-CBC decryption, `ADM-CID`, `SIA-DCS`, `NULL` heartbeat, `ACK` reply; `php artisan security-devices:sia-dc09-listen`) | Real-time arm (`Q=3` `400`–`409`) / disarm (`Q=1` `400`–`409`), burglary (`130`–`139`), panic/duress (`120`–`125`), fire (`110`–`118`), tamper (`137`/`383`), AC/battery trouble (`301`/`302`); intended for Arrowhead Elite/ESL-2, ICT Protege, Bosch Solution, Paradox, DSC Neo, Texecom, & Honeywell IP communicators | **Built from vendor docs** |
| **Gallagher Command Centre** | `alarm.gallagher_command_centre` | Local HTTPS REST API (`GGL-API-KEY` + mutual TLS client cert, `/api/alarm_zones/{id}/arm` & `/disarm`) | Alarm zone status, arm (`alarm.arm` — confirmation + approval), disarm (`alarm.disarm` — MFA + type-device-name confirmation + approval + audit log) | **Built from vendor docs** |
| **Inner Range Inception** | `alarm.innerrange_inception` | Local HTTPS REST API v1 (`/api/v1/authentication/login`, `/api/v1/control/area/{id}/activity` `ArmArea` / `DisarmArea`) | Area arm/disarm state, arm (`alarm.arm`), disarm (`alarm.disarm` — MFA + type-device-name confirmation + approval + audit log) | **Built from vendor docs** |
| **Arrowhead Alarm Products (AAP) Elite S / ESL-2** | `alarm.arrowhead_elite` | Native IP module control requires AAP installer/integrator protocol documentation (events supported today via SIA DC-09 / Contact ID IP reporting) | Native arm/disarm planned; SIA DC-09 reports, view only | **Planned** |
| **ICT Protege GX / WX** | `alarm.ict_protege` | SOAP/REST SDK gated behind ICT Technology Partner programme & licensing (events supported today via SIA DC-09) | Native area/door control planned; SIA DC-09 reports, view only | **Planned** |
| **Inner Range Integriti** | `alarm.innerrange_integriti` | Licensed Integriti XML/REST Integration Server port (events supported today via SIA DC-09) | Native Integriti XML/REST integration planned | **Planned** |
| **Bosch Solution 2000 / 3000 / 6000** | `alarm.bosch_solution` | Proprietary RSC+ / Bosch installer SDK under NDA (events supported today via SIA DC-09 / Contact ID over B426-M) | Native RSC+ control planned; SIA DC-09 reports, view only | **Planned** |
| **Ajax Systems Hub 2 / Hub Hybrid** | `alarm.ajax_hub` | Direct control requires Ajax Enterprise API agreement; SIA DC-09 reports only (view only) | Native Enterprise API planned; SIA DC-09 reports, view only | **Planned** |
| **Paradox EVO / Magellan / Spectra (IP150+)** | `alarm.paradox_ip` | Proprietary encrypted bus protocol without public third-party API (events supported today via SIA DC-09 / IPR512 compatible reporting) | Native IP150+ integration planned; SIA DC-09 reports, view only | **Planned** |
| **DSC PowerSeries Neo (TL280E)** | `alarm.dsc_powerseries_neo` | Johnson Controls integrator programme required for ITV2 encrypted bus (events supported today via SIA DC-09) | Native ITV2 integration planned; SIA DC-09 reports, view only | **Planned** |

**Official alarm specifications cited:**
- **Security Industry Association (SIA)** — *ANSI/SIA DC-09 Internet Protocol Event Reporting*, *ANSI/SIA DC-05 (Ademco Contact ID)*, and *ANSI/SIA DC-03 (SIA-DCS)* (<https://www.securityindustry.org/industry-standards/>).
- **Gallagher Security (Hamilton, NZ)** — *Gallagher Command Centre REST API Documentation* (<https://gallaghersecurity.github.io/>).
- **Inner Range** — *Inception REST API Specification* (<https://www.innerrange.com/>).
- **Arrowhead Alarm Products (Auckland, NZ)**, **Integrated Control Technology (Auckland, NZ)**, **Bosch Security**, **Ajax Systems**, **Paradox Security**, & **DSC (Johnson Controls)** — Official product & SIA DC-09 communicator documentation (<https://www.aap.co.nz/>, <https://ict.co/>, <https://www.boschsecurity.com/>, <https://support.ajax.systems/en/sia-protocol/>, <https://www.paradox.com/>, <https://www.dsc.com/>).

---

### 3. IoT & environmental sensors — OASIS MQTT v3.1.1 and Clean-Room Milesight LoRaWAN

| Brand / Driver | Key | Transport & Codec | Capabilities | Support level |
| --- | --- | --- | --- | --- |
| **OASIS MQTT v3.1.1 Environmental & Care Sensors** | `sensor.mqtt_generic` | Pure-PHP OASIS MQTT v3.1.1 TCP/TLS client (`MqttV311Client`, `CONNECT`/`SUBSCRIBE`/`PUBLISH`/`PINGREQ` + `security-devices:mqtt-listen`), JSON sensor payload intake (`MqttSensorIntakeService`) | Temperature, humidity, CO2, battery %, door/window contact alerts, leak alerts, panic/call button alerts, PIR occupancy | **Built from vendor docs** |
| **Milesight LoRaWAN Gateway (UG56 / UG65 / UG67) & Sensors** | `sensor.milesight_lorawan_gateway` | Authenticated HTTPS Webhook (`POST /webhooks/milesight-gateway/{site}` with the Site’s secret as a Bearer token, an `X-Oblivion-Webhook-Token` header or a Basic-auth password; Milesight gateways don’t sign requests, and the earlier `X-Milesight-Signature` check was removed in Phase A) + MQTT uplink JSON + **clean-room** binary TLV payload decoder (`MilesightLorawanPayloadDecoder`, zero GPL code) | Decodes AM103/AM307/AM319 (`temp`, `humidity`, `co2`, `tvoc`, `pm2_5`, `pressure`), EM300-TH/SLD/MCS (`leak`, `door`), WS101 (`button`), WS202 (`pir`), WS301 (`magnet`, `tamper`), VS121 (`people_count_all`) | **Built from vendor docs** |
| **Milesight Development Platform Cloud** | `sensor.milesight_cloud` | OAuth2 + Webhook API via `/security-devices/integrations/milesight` | Cloud application & sensor sync, webhook telemetry | **Built from vendor docs** |

**Official IoT specifications cited & clean-room licence compliance:**
- **OASIS Standard** — *MQTT Version 3.1.1* (<https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html>).
- **Milesight IoT** — *UG56/UG65/UG67 HTTP(S)/MQTT Uplink Integration Guide* (<https://support.milesight-iot.com/kb/guide-1065.html>) and *Milesight LoRaWAN Payload Codec / TLV Format Guide* (<https://support.milesight-iot.com/kb/guide-1282.html>, <https://support.milesight-iot.com/kb/guide-1262.html>). `MilesightLorawanPayloadDecoder` is written 100% clean-room from published binary channel/type tables with zero GPL-licensed code copied from `Milesight-IoT/SensorDecoders`.

---

### 4. Network configuration backup & diff over SSH

| Brand / Driver | Key | Read-Only SSH Command | Secret Redaction & Normalization | Support level |
| --- | --- | --- | --- | --- |
| **MikroTik RouterOS v6 / v7** | `network.mikrotik_routeros` | `/export terse show-sensitive=no` over SSH-2 exec channel (`phpseclib3`) | Strips volatile `# month/day/year by RouterOS` header lines; redacts any residual RouterOS `password=`, `secret=`, ` passphrase=`, `private-key=`, `auth-key=` fields (`NetworkConfigurationRedactor`, up to `20,000` lines) | **Built from vendor docs** |
| **Cisco IOS / IOS-XE** | `network.cisco_ios` | `show running-config` | Strips `! Last configuration change`, `NVRAM config last updated`, and `Current configuration : N bytes` volatile lines; redacts Type 5/7/8/9 secrets, SNMP communities, TACACS/RADIUS keys, and WPA PSKs | **Built from vendor docs** |
| **HPE Aruba Networking (AOS-CX & ArubaOS-Switch)** | `network.aruba_aos` | `show running-config` | Strips volatile timestamp banners; redacts `ciphertext`, SNMP communities, RADIUS/TACACS keys, and user hashes | **Built from vendor docs** |
| **Fortinet FortiGate (FortiOS)** | `network.fortinet_fortios` | `show full-configuration` (configurable to `show`) | Strips volatile `#conf_file_ver=` headers; redacts `set password ENC ...`, `set psksecret ENC ...`, `set private-key`, and SNMP/RADIUS secrets | **Built from vendor docs** |
| **Ubiquiti UniFi Network** | `network.ubiquiti_unifi` | HTTPS Local Controller & Cloud Site Manager API | Device inventory, port state, topology, and configuration snapshot tracking | **Not proven on hardware** (claimed before 30 Oct with no recorded walk) (`UX7` gateway on `172.16.0.0/24`) |

All SSH configuration backups run through the audited `network.config.backup` command adapter (`NetworkSshConfigBackupCommandAdapter`) with operator impact confirmation, leased SSH credentials (`GovernedCredentialLeaseBroker`), SSRF/scope checks (`EgressPolicy`), SHA-256 drift detection (`ConfigurationSnapshotService::captureFromCommand()`), and side-by-side redacted line diffs (`ConfigurationDiffDialog`).

**Official network CLI specifications cited:**
- **MikroTik** — *RouterOS Configuration Export Manual* (<https://help.mikrotik.com/docs/spaces/ROS/pages/328155/Configuration+Management>).
- **Cisco Systems** — *Cisco IOS Configuration Fundamentals Command Reference (`show running-config`)* (<https://www.cisco.com/c/en/us/td/docs/ios-xml/ios/fundamentals/command/cf_command_ref.html>).
- **HPE Aruba Networking** — *AOS-CX Fundamentals Guide* (<https://www.arubanetworks.com/techdocs/AOS-CX/>).
- **Fortinet** — *FortiOS CLI Reference* (<https://docs.fortinet.com/product/fortigate/>).

---

### 5. Site-Hub Syslog & NetFlow / IPFIX / sFlow Telemetry

| Driver | Key | Hub UDP Receiver & Central Pipeline | Overlapping Private LAN Safety | Support level |
| --- | --- | --- | --- | --- |
| **Site-Hub Syslog Receiver** | `telemetry.hub_syslog` | `HubTelemetryReceiver::spoolSyslogDatagram()` (RFC 5424 & RFC 3164, `<= 8192` bytes) -> encrypted collector spool -> `CollectorIngestService` (`syslog_event`) -> `InboundTelemetryScopeResolver::resolveForCollector()` -> `EventEnvelopeHandler` | Resolves sender IP strictly within the authenticated collector's authorised `site_id` + active `DiscoveryScope` CIDRs, preventing cross-Site collisions when two houses both use `192.168.1.0/24` | **Built from vendor docs** |
| **Site-Hub NetFlow / IPFIX / sFlow Receiver** | `telemetry.hub_netflow` | `HubTelemetryReceiver::spoolFlowDatagram()` (NetFlow v5, NetFlow v9, IPFIX RFC 7011, sFlow v5, `<= 65507` bytes) -> encrypted collector spool -> `CollectorIngestService` (`flow_datagram`) -> `FlowIntakeService` -> `EventEnvelopeHandler` (`flow.bytes`, `flow.packets`, `flow.count`) | Exporter sequence state (`monitoring_flow_exporter_states`) and device attribution are partitioned by `(site_id, exporter_hash)` via the authenticated collector's Site scope | **Built from vendor docs** |

**Official telemetry specifications cited:**
- **IETF RFC 5424 & RFC 3164** — *The Syslog Protocol* (<https://datatracker.ietf.org/doc/html/rfc5424>, <https://datatracker.ietf.org/doc/html/rfc3164>).
- **IETF RFC 3954 & RFC 7011** — *Cisco Systems NetFlow Services Export Version 9* and *IP Flow Information Export (IPFIX)* (<https://datatracker.ietf.org/doc/html/rfc3954>, <https://datatracker.ietf.org/doc/html/rfc7011>).
- **sFlow.org** — *sFlow Version 5 Specification* (<https://sflow.org/sflow_version_5.txt>).

---

### 6. Windows Computers, Obli Tray Helper, & Microsoft Intune

| Brand / Driver | Key | Transport & Enrolment | Capabilities & Safety Boundaries | Support level |
| --- | --- | --- | --- | --- |
| **Microsoft Windows (Computer Service & Obli Tray Helper)** | `computer.oblivion_windows_obli` | `Obli.Setup.exe` one-time setup code (`ofc1_`) or installation profile file -> Ed25519-signed HTTPS heartbeats (`oblivion-endpoint`) + local Obli tray IPC (`Obli.exe`) + WebRTC attended screen share | Disk, memory, BitLocker, and update posture; audited Windows service restarts, scheduled computer restarts, and approved software actions; attended screen help gated by an admin toggle in `/settings/obli` plus on-screen **Allow / Decline** consent and a persistent **Stop** banner. Arbitrary script execution remains disabled (`EndpointScriptPolicy::available() === false`). | **Built from vendor docs** |
| **Microsoft Intune (Read-Only Discovery & Compliance)** | `computer.microsoft_intune_graph` | Microsoft Entra OAuth 2.0 client credentials (`directory_id`, `client_id`, `client_secret` via `IntegrationSecretManager`) -> Microsoft Graph v1.0 `GET /v1.0/deviceManagement/managedDevices` | Discovers Intune-enrolled Windows 10/11 PCs and Windows Servers from `/security-devices/integrations/intune`, bulk-imports selected computers into `Devices` assigned to an active Site, and refreshes compliance, OS version, and encryption posture every 30 minutes (`endpoints:intune-sync`). No remote wipe, policy editing, or script push. | **Built from vendor docs** |

**Official Microsoft specifications cited:**
- **Microsoft Graph REST API v1.0** — *List managedDevices* (<https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list?view=graph-rest-1.0>).
- **Microsoft Entra Identity Platform** — *OAuth 2.0 client credentials flow* (<https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow>).
- **Microsoft Win32 & Graphics** — *Windows Services* (<https://learn.microsoft.com/en-us/windows/win32/services/services>) and *Desktop Duplication API* (<https://learn.microsoft.com/en-us/windows/win32/direct3ddxgi/desktop-dup-api>).
