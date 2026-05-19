# Queclink GL30MEU Field Reference

This reference documents the command fields currently exposed by the Oblivion Queclink hub. The source of truth is the extracted GL30MEUR01 @Track Air Interface Protocol v2.04 text at `storage/app/queclink-protocol-gl30m.txt`, plus the implementation in `app/Services/Queclink/CommandBuilder.php` and the readback mapper in `app/Services/Queclink/ConfigurationSnapshotService.php`.

The hub always queues outbound writes through `queclink_pending_commands`. Readback uses `AT+GTRTO=gl30,2,<SECTION>,...$`; device responses arrive as `+RESP:GTALM` and are parsed into `configuration.summary`.

## Protocol Corrections

The original remote-config plan listed a few command words that are event reports or aliases, not writable GL30 v2.04 configuration commands:

- `GTSOS` is a panic/SOS event report. SOS behaviour is configured through `GTCFG` fields `function_button_mode` and `sos_report_mode`, and phone-number calling/SMS allow-listing is configured through `GTWLT`.
- `GTMAN` is a man-down/movement event report. The writable movement/non-movement section in GL30 v2.04 is `GTNMD`.
- `GTTOW` is not a GL30 v2.04 writable config command in the extracted protocol text.
- The Wi-Fi command word is `GTWFI`, not `GTWIF`.
- Bluetooth settings are written through `GTBTS`. There is no separate writable `GTBT` command in GL30 v2.04; `gl30Bt()` is an alias to `gl30Bts()` only.

## Read Sections

| UI section | GTRTO section code | Snapshot key | Notes |
| --- | --- | --- | --- |
| Identity metrics | `BSI` | `battery` | Read-only battery, voltage, charging state. |
| Server | `SRI` | `server` | Main/backup host and connection behaviour. |
| Tracking | `CFG` | `global` | Global cadence, GNSS, SOS mode, LED, battery-low settings. |
| SIM PIN | `PIN` | `pin` | SIM auto-unlock fields. |
| Watchdog | `DOG` | `dog` | Timed reboot if the unit stops communicating. |
| Time | `TMA` | `time` | Local-time offset and optional UTC sync value. |
| Non-movement | `NMD` | `non_movement` | Stillness and safe-check detection. |
| Power | `PDS` | `power` | Sleep/profile preserve mask. |
| Geofences | `GEO` | `geofences` | Repeated rows keyed by slot. |
| Bluetooth | `BTS` | `bluetooth` | BLE name/discoverable/advertising settings. |
| BLE accessories | `BID` | `beacons` | Beacon accessory scan settings and MAC list. |
| Wi-Fi | `WFI` | `wifi` | Wi-Fi positioning scan settings and entries. |
| Phone allow-list | `WLT` | `allowlist` | Phone numbers allowed to call/SMS. |
| Firmware update | `UPC` | `firmware_update` | OTA configuration download settings. |
| Firmware version | `FVR` | `firmware_version` | OTA configuration metadata; mostly read-only in the hub. |

## Writable Commands

### `GTSRI` - Server Registration

Builder: `CommandBuilder::gl30ServerRegistration()`

Snapshot key: `summary.server`

Fields exposed by the hub:

| Field | Meaning |
| --- | --- |
| `report_mode` | TCP/UDP report mode. The hub defaults to TCP long connection. |
| `manual_netreg` | Manual network-registration mode. |
| `buffer_mode` | Offline buffer behaviour. |
| `main_host`, `main_port` | Primary Oblivion listener endpoint. |
| `backup_host`, `backup_port` | Backup endpoint. |
| `sms_gateway` | SMS gateway field; usually blank. |
| `heartbeat_interval_minutes` | Heartbeat cadence. |
| `sack_enable` | Server ACK handling. |
| `sms_ack_enable` | SMS ACK handling. |
| `psm_network_hold_time_seconds` | Network hold time after PSM wake. |
| `protocol_format` | ASCII protocol format. The hub validates this as `0`. |

### `GTCFG` - Global GL30 Configuration

Builder: `CommandBuilder::gl30GlobalConfiguration()`

Snapshot key: `summary.global`

Fields exposed by the hub:

| Field | Meaning |
| --- | --- |
| `device_name` | Device display/name field sent to the unit. |
| `gnss_timeout_seconds` | GNSS acquisition timeout. |
| `event_mask` | Event mask in hex. |
| `report_item_mask` | Report-item mask in hex. |
| `mode_selection` | Continuous vs power-saving mode. |
| `continuous_send_interval_seconds` | Periodic report interval. Values 1-4 are rejected. |
| `start_mode` | Wake-start mode for power-saving schedules. |
| `specified_time_of_day` | HHMM wake time. |
| `wakeup_interval_hours` | Wake interval in hours. |
| `gnss_enable` | GNSS on/off. |
| `agps_mode` | AGPS on/off. |
| `gsm_report` | GSM report mask. |
| `battery_low_percentage` | Battery-low threshold. |
| `function_button_mode` | Function/SOS button behaviour. |
| `sos_report_mode` | SOS report mode. |
| `wifi_report` | Wi-Fi fallback/report behaviour. |
| `led_on` | LED behaviour. |
| `charge_standby_mode` | Charge standby behaviour. |

### `GTPIN` - SIM PIN

Builder: `CommandBuilder::gl30Pin()`

Snapshot key: `summary.pin`

Fields:

- `auto_unlock_pin`
- `pin`

### `GTDOG` - Watchdog Reboot

Builder: `CommandBuilder::gl30Dog()`

Snapshot key: `summary.dog`

Fields:

- `mode`
- `reboot_interval`
- `reboot_time`
- `report_before_reboot`
- `unit`
- `send_failure_timeout`

### `GTTMA` - Time Adjustment

Builder: `CommandBuilder::gl30Tma()`

Snapshot key: `summary.time`

Fields:

- `sign`
- `hour_offset`
- `minute_offset`
- `daylight_saving`
- `utc_time`

### `GTNMD` - Non-Movement

Builder: `CommandBuilder::gl30Nmd()`

Snapshot key: `summary.non_movement`

Fields:

- `sensor_enable`
- `mode`
- `non_movement_duration`
- `movement_duration`
- `movement_threshold`
- `rest_send_interval`
- `report_mode`
- `safe_check`
- `location_ignore`

### `GTPDS` - Power Saving

Builder: `CommandBuilder::gl30Pds()`

Snapshot key: `summary.power`

Fields:

- `mode`
- `mask`

### `GTGEO` - Circular Geofence

Builder: `CommandBuilder::gl30Geo()`

Snapshot key: `summary.geofences`

Fields:

- `slot`
- `mode`
- `longitude`
- `latitude`
- `radius`

### `GTBTS` - Bluetooth Settings

Builder: `CommandBuilder::gl30Bts()`

Snapshot key: `summary.bluetooth`

Fields:

- `mode`
- `bluetooth_name`
- `discoverable_mode`
- `discoverable_time`
- `advertising_interval`
- `advertising_data_type`

### `GTBID` - BLE Accessory Scan

Builder: `CommandBuilder::gl30Bid()`

Snapshot key: `summary.beacons`

Fields:

- `enable`
- `beacon_id_model`
- `append_mask`
- `scan_interval`
- `beacon_accessory_model`
- `mac_list`

### `GTWFI` - Wi-Fi Positioning

Builder: `CommandBuilder::gl30Wifi()`

Snapshot key: `summary.wifi`

Fields:

- `mode`
- `scan_interval`
- `send_interval`
- `lost_times`
- `alarm_scan_interval`
- `start_index`
- `end_index`
- `entries`

### `GTWLT` - Phone Allow-List

Builder: `CommandBuilder::gl30Wlt()`

Snapshot key: `summary.allowlist`

Fields:

- `number_filter`
- `phone_number_start`
- `phone_number_end`
- `phone_numbers`

### `GTUPC` - OTA Configuration Download

Builder: `CommandBuilder::gl30Upc()`

Snapshot key: `summary.firmware_update`

Fields:

- `max_download_retry`
- `download_timeout_minutes`
- `download_protocol`
- `report_enable`
- `update_interval_hours`
- `download_url`
- `mode`
- `extended_status_report`
- `identifier_number`

### `GTFVR` - Firmware/Configuration Version Metadata

Builder: `CommandBuilder::gl30Fvr()`

Snapshot key: `summary.firmware_version`

Fields:

- `configuration_name`
- `configuration_version`
- `digital_signature`
- `generation_time` (readback only)

`GTFVR` is mainly read back and recorded from OTA configuration files. The hub keeps the builder because the protocol defines the command shape, but normal operators should use `GTRTO` readback or firmware-update tooling rather than hand-editing this section.
