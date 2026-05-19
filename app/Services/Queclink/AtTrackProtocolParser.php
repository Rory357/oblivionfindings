<?php

namespace App\Services\Queclink;

/**
 * Parser for Queclink @Track Air Interface Protocol (ASCII frames).
 *
 * References:
 *   - GV500CG @Track Air Interface Protocol v5.01 (TRACGV500CGAN005)
 *   - GL30MEUR01 @Track Air Interface Protocol v2.04
 *
 * Frame terminator is "$". Fields are comma-separated. The header determines
 * direction and frame type; the command word (e.g. GTFRI) determines payload
 * field layout. We handle the most common reports inline and fall back to a
 * generic field-array for less common ones — the raw frame is always stored
 * so unrecognised commands can still be inspected from the debug console.
 *
 * HEX frames are detected and tagged but not decoded — operators should
 * configure devices for ASCII via AT+GTSRI Protocol Format = 0.
 */
class AtTrackProtocolParser
{
    /**
     * Buffer-accumulating frame splitter. Returns complete frames from a byte
     * stream that may contain partial trailing data. The remaining buffer
     * (incomplete last frame) is returned via the $buffer reference.
     *
     * @return list<string> Complete frames (each ending with "$").
     */
    public function splitFrames(string $incoming, string &$buffer): array
    {
        $buffer .= $incoming;
        $frames = [];

        while (($idx = strpos($buffer, '$')) !== false) {
            $frame = substr($buffer, 0, $idx + 1);
            $buffer = substr($buffer, $idx + 1);

            $frame = ltrim($frame, "\r\n\0 ");
            if ($frame === '' || $frame === '$') {
                continue;
            }
            $frames[] = $frame;
        }

        return $frames;
    }

    public function parse(string $raw): AtTrackFrame
    {
        $raw = trim($raw);

        if ($raw === '' || ! str_ends_with($raw, '$')) {
            return $this->failed($raw, 'frame did not end with $');
        }

        if (! ctype_print(str_replace(["\r", "\n", '$'], '', $raw))) {
            return $this->hexFrame($raw);
        }

        $body = substr($raw, 0, -1);
        $colonPos = strpos($body, ':');
        if ($colonPos === false) {
            if (str_starts_with($body, 'AT+GT')) {
                return $this->parseAtCommand($raw, $body);
            }

            return $this->failed($raw, 'no colon delimiter found in frame body');
        }

        $header = substr($body, 0, $colonPos);
        $remainder = substr($body, $colonPos + 1);

        $frameType = match ($header) {
            '+RESP' => 'RESP',
            '+ACK' => 'ACK',
            '+SACK' => 'SACK',
            '+BUFF' => 'BUFF',
            default => 'unknown',
        };

        if ($frameType === 'unknown') {
            return $this->failed($raw, "unrecognised header '{$header}'");
        }

        $fields = explode(',', $remainder);
        if ($fields === [] || $fields[0] === '') {
            return $this->failed($raw, 'no command word after header');
        }

        $commandWord = $fields[0];

        if ($frameType === 'SACK') {
            // +SACK:GTHBD,<protocolVer>,<count>$  - rarely received inbound but supported.
            $proto = $fields[1] ?? null;
            $count = $fields[2] ?? null;

            return new AtTrackFrame(
                rawFrame: $raw,
                frameType: $frameType,
                commandWord: $commandWord,
                protocolVersion: $proto,
                imei: null,
                deviceName: null,
                countNumber: $count,
                serialNumber: null,
                fields: $fields,
            );
        }

        // RESP, ACK, BUFF share the same header layout:
        //   <cmd>,<protoVer>,<imei>,<deviceName>,...,<sendTime>,<count>$
        $proto = $fields[1] ?? null;
        $imei = $fields[2] ?? null;
        $deviceName = $fields[3] ?? null;
        $count = end($fields) ?: null;

        if (! $this->looksLikeImei($imei)) {
            return $this->failed($raw, 'invalid IMEI in field[2]');
        }

        // Tail layout for RESP/ACK/BUFF frames:
        //   ..., SerialNumber(4), SendTime(14 digits), CountNumber(4) $
        // Serial Number on ACK frames matches the serial of the original
        // command, so it's what we use to correlate command → ack. On RESP
        // frames the field exists but isn't load-bearing.
        $serial = null;
        $n = count($fields);
        if ($n >= 4) {
            $candidate = $fields[$n - 3] ?? null;
            if ($candidate !== null && preg_match('/^[0-9A-F]{4}$/i', $candidate) === 1) {
                $serial = strtoupper($candidate);
            }
        }

        $payload = $this->normalisePayload($frameType, $commandWord, $fields);

        return new AtTrackFrame(
            rawFrame: $raw,
            frameType: $frameType,
            commandWord: $commandWord,
            protocolVersion: $proto,
            imei: $imei,
            deviceName: $deviceName,
            countNumber: $count ?: null,
            serialNumber: $serial,
            fields: $fields,
            payload: $payload,
        );
    }

    /**
     * Normalise the raw field array into a payload shape compatible with
     * App\Services\Fleet\Telemetry\QueclinkAdapter::normalize().
     *
     * @return array<string, mixed>
     */
    protected function normalisePayload(string $frameType, string $commandWord, array $fields): array
    {
        $payload = [
            'imei' => $fields[2] ?? null,
            'device_name' => $fields[3] ?? null,
            'protocol_version' => $fields[1] ?? null,
            'message_id' => end($fields) ?: null,
            'frame_type' => $frameType,
            'command_word' => $commandWord,
            'event_type' => $this->eventTypeFromCommand($commandWord),
            // Battery is NOT at fields[4] for location-style commands such as
            // GTFRI on GL30 — field 4 is report_id there. Specific handlers
            // (GTBPL) set it explicitly. For GTFRI/GTSOS/etc. we scan the
            // trailing fields by position_append_mask below.
            'battery' => null,
            'battery_voltage_mv' => null,
            'power_event' => null,
            'charging_status' => null,
        ];

        if ($frameType === 'ACK') {
            return $payload;
        }

        if ($commandWord === 'GTALM') {
            return $this->normaliseConfigurationReport($payload, $fields);
        }

        if ($commandWord === 'GTBTC' || $commandWord === 'GTSTC') {
            return $this->normaliseChargingReport($payload, $fields);
        }

        // Generic Location Report layout — applies to GTFRI, GTTOW, GTSPD, GTSOS,
        // GTRTL, GTDOG, GTVGL, GTHBM, GTPNA, GTPFA, GTBPL, GTVGN, GTVGF, etc.
        // Field offsets (counted from 0 after the comma-split):
        //   0: command word
        //   1: protocol version
        //   2: IMEI
        //   3: device name
        //   4: reserved (varies — sometimes mileage, sometimes battery %)
        //   5: report id/type (2 hex chars)
        //   6: number of GNSS positions
        //   7: GNSS accuracy / HDOP
        //   8: speed km/h
        //   9: azimuth
        //   10: altitude
        //   11: longitude
        //   12: latitude
        //   13: GNSS UTC time
        //   14: MCC, 15: MNC, 16: LAC, 17: Cell ID
        //   18: position append mask
        //   19: satellites in use (optional)
        //   20: mileage
        //   ...
        //   N-2: send time, N-1: count number
        $payload['report_id_type'] = $fields[5] ?? null;
        $gnssAccuracy = $this->numOrNull($fields[7] ?? null);
        $payload['gnss_accuracy'] = $gnssAccuracy;
        $payload['hdop'] = $gnssAccuracy;
        $payload['speed'] = $this->numOrNull($fields[8] ?? null);
        $payload['motion'] = ($payload['speed'] ?? 0) > 0 ? 'moving' : 'stationary';
        $payload['course'] = $this->numOrNull($fields[9] ?? null);
        $payload['altitude'] = $this->numOrNull($fields[10] ?? null);
        $payload['lon'] = $this->numOrNull($fields[11] ?? null);
        $payload['lat'] = $this->numOrNull($fields[12] ?? null);
        $payload['gps_time'] = $this->parseTimestamp($fields[13] ?? null);
        $payload['mcc'] = $fields[14] ?? null;
        $payload['mnc'] = $fields[15] ?? null;
        $payload['lac'] = $fields[16] ?? null;
        $payload['cell_id'] = $fields[17] ?? null;

        // Special-cases by command word.
        switch ($commandWord) {
            case 'GTSOS':
                $payload['alarm'] = 'sos';
                $payload['event_type'] = 'vehicle_sos';
                $payload['sos_flag'] = true;
                break;
            case 'GTTOW':
                $payload['alarm'] = 'tamper';
                $payload['event_type'] = 'tamper';
                break;
            case 'GTBPL':
                $payload['alarm'] = 'battery_low';
                $payload['event_type'] = 'battery_low';
                $payload['battery'] = $this->numOrNull($fields[4] ?? null);
                break;
            case 'GTEPS':
                $payload['external_power'] = true;
                $payload['event_type'] = 'external_power';
                break;
            case 'GTPNA':
                $payload['alarm'] = 'power_on';
                $payload['event_type'] = 'power_on';
                $payload['power_event'] = 'power_on';
                break;
            case 'GTPFA':
                $payload['alarm'] = 'power_off';
                $payload['event_type'] = 'power_off';
                $payload['power_event'] = 'power_off';
                break;
            case 'GTVGN':
                $payload['ignition'] = true;
                $payload['event_type'] = 'ignition_on';
                break;
            case 'GTVGF':
                $payload['ignition'] = false;
                $payload['event_type'] = 'ignition_off';
                break;
            case 'GTSPD':
                $payload['alarm'] = 'speed';
                $payload['event_type'] = 'speed_alarm';
                break;
            case 'GTHBM':
                $payload['alarm'] = 'harsh_behaviour';
                $payload['event_type'] = 'harsh_behaviour';
                break;
            case 'GTGEO':
            case 'GTGIN':
                $payload['event_type'] = 'geofence_enter';
                break;
            case 'GTGOT':
                $payload['event_type'] = 'geofence_exit';
                break;
            case 'GTMAN':  // Personal-tracker man-down alarm (GL-series).
                $payload['alarm'] = 'man_down';
                $payload['event_type'] = 'man_down';
                $payload['sos_flag'] = true;
                break;
            case 'GTLBC':  // Location-by-call (GL-series panic via call).
                $payload['alarm'] = 'sos';
                $payload['event_type'] = 'vehicle_sos';
                $payload['sos_flag'] = true;
                break;
            case 'GTHBD':
                $payload['heartbeat'] = true;
                $payload['event_type'] = 'heartbeat';
                break;
            case 'GTFRI':
                $payload['event_type'] = 'location_report';
                break;
        }

        // Walk the trailing fields after the position_append_mask to recover
        // satellites, battery voltage (mV), and battery percentage.
        // Charge state is not present in GL30 location frames; it is reported
        // by GTBTC/GTSTC, so do not infer it from CSQ/movement tail fields.
        // The exact bit layout differs across Queclink families, so
        // we use an empirical scan against well-known value ranges. This is
        // safe because (a) the trailing block is bounded by send_time +
        // count_number at the end, and (b) battery percentage 0–100 and
        // voltage 2500–5500 mV almost never collide with other numeric
        // fields in this region. See GL30MEUR01 protocol §6.2 for the canon.
        $this->extractTrailingHealth($payload, $fields);

        return $payload;
    }

    /**
     * +RESP:GTBTC reports that the GL30 backup battery starts charging.
     * +RESP:GTSTC reports that charging stopped or the battery is full.
     *
     * GTBTC:
     *   <cmd>,<proto>,<imei>,<name>,<voltage>,<battery>,<mcc>,<mnc>,<lac>,<cell>,<rssi>,<ber>,<send>,<count>
     * GTSTC:
     *   <cmd>,<proto>,<imei>,<name>,<event_state>,<voltage>,<battery>,<mcc>,<mnc>,<lac>,<cell>,<rssi>,<ber>,<send>,<count>
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    protected function normaliseChargingReport(array $payload, array $fields): array
    {
        $isStarted = ($payload['command_word'] ?? null) === 'GTBTC';
        $offset = $isStarted ? 0 : 1;

        $payload['event_type'] = $isStarted ? 'charging_started' : 'charging_stopped';
        $payload['battery_voltage_mv'] = $this->intOrNull($fields[4 + $offset] ?? null);
        $payload['battery'] = $this->numOrNull($fields[5 + $offset] ?? null);
        $payload['mcc'] = $fields[6 + $offset] ?? null;
        $payload['mnc'] = $fields[7 + $offset] ?? null;
        $payload['lac'] = $fields[8 + $offset] ?? null;
        $payload['cell_id'] = $fields[9 + $offset] ?? null;
        $payload['csq_rssi'] = $this->intOrNull($fields[10 + $offset] ?? null);
        $payload['csq_ber'] = $this->intOrNull($fields[11 + $offset] ?? null);
        $payload['send_time'] = $this->parseTimestamp($fields[12 + $offset] ?? null);

        if ($isStarted) {
            $payload['charging_status'] = 'charging';
            $payload['external_power'] = true;

            return $payload;
        }

        $eventState = $this->intOrNull($fields[4] ?? null);
        $payload['charge_event_state'] = $eventState;
        $payload['charging_status'] = $eventState === 1 ? 'charge_full' : 'stopped_charging';
        $payload['external_power'] = false;

        return $payload;
    }

    /**
     * Extract battery percentage, voltage, and related health fields from
     * the trailing fields of a location-style frame.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     */
    protected function extractTrailingHealth(array &$payload, array $fields): void
    {
        $count = count($fields);
        if ($count < 22) {
            return; // not enough fields for trailing health
        }

        // The trailing block sits between fields[18] (position_append_mask)
        // and fields[N-2] (send_time). The last field is the count_number.
        $tailStart = 18;
        $tailEnd = $count - 3; // inclusive
        if ($tailEnd <= $tailStart) {
            return;
        }

        // GTBPL already sets battery from field 4 — don't clobber it.
        $skipBattery = ($payload['command_word'] ?? null) === 'GTBPL'
            && $payload['battery'] !== null;

        // Strategy: anchor on battery voltage (mV). Battery voltage is the
        // most distinctive value in the trailing block — a 4-digit integer
        // in the 2500–5500 range that doesn't collide with mileage, hour
        // meters, or satellite counts. Once we find it, battery percentage
        // is typically the immediately-following field (0–100) and charging
        // status the one after that (0–3). If no voltage is found, we make
        // no claims about battery/charging from the trailing block — the
        // command-specific handlers (e.g. GTBPL) remain authoritative.
        $voltageIdx = null;
        for ($i = $tailStart + 1; $i <= $tailEnd; $i++) {
            $raw = $fields[$i] ?? null;
            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                continue;
            }
            $num = (float) $raw;
            if ($num >= 2500 && $num <= 5500 && (int) $num == $num) {
                $voltageIdx = $i;
                $payload['battery_voltage_mv'] = (int) $num;
                break;
            }
        }

        if ($voltageIdx === null) {
            return;
        }

        // Battery percentage: field immediately after voltage.
        $batteryRaw = $fields[$voltageIdx + 1] ?? null;
        if ($batteryRaw !== null && $batteryRaw !== '' && is_numeric($batteryRaw)) {
            $num = (float) $batteryRaw;
            if ($num >= 0 && $num <= 100 && (int) $num == $num && ! $skipBattery) {
                $payload['battery'] = (float) $num;
            }
        }

        $payload['csq_ber'] = $this->intOrNull($fields[$voltageIdx + 2] ?? null);
        $movement = $this->intOrNull($fields[$voltageIdx + 3] ?? null);
        if ($movement !== null) {
            $payload['movement_status'] = $movement === 1 ? 'motion' : 'rest';
        }
    }

    /**
     * +RESP:GTALM returns configuration text from AT+GTRTO READ. The middle
     * payload is a comma-separated sequence of command sections, so keep it
     * intact for the higher-level configuration parser.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    protected function normaliseConfigurationReport(array $payload, array $fields): array
    {
        $payload['event_type'] = 'configuration_report';
        $payload['config_total_packets'] = (int) ($fields[4] ?? 1);
        $payload['config_current_packet'] = (int) ($fields[5] ?? 1);

        $tailOffset = count($fields) >= 8 ? -2 : 0;
        $configFields = $tailOffset < 0
            ? array_slice($fields, 6, $tailOffset)
            : array_slice($fields, 6);

        $payload['config_text'] = implode(',', $configFields);
        $payload['send_time'] = $this->parseTimestamp($fields[count($fields) - 2] ?? null);

        return $payload;
    }

    protected function eventTypeFromCommand(?string $cmd): ?string
    {
        if ($cmd === null) {
            return null;
        }

        return strtolower(str_replace('GT', '', $cmd));
    }

    protected function looksLikeImei(?string $value): bool
    {
        return $value !== null
            && preg_match('/^\d{14,16}$/', $value) === 1;
    }

    protected function numOrNull(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    protected function intOrNull(?string $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $num = (float) $value;

        return (int) $num == $num ? (int) $num : null;
    }

    protected function parseTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) !== 14) {
            return null;
        }
        if (! ctype_digit($value)) {
            return null;
        }

        // YYYYMMDDHHMMSS → ISO-ish; FleetTelemetryIngestService accepts Carbon-parseable strings.
        return sprintf(
            '%s-%s-%sT%s:%s:%sZ',
            substr($value, 0, 4),
            substr($value, 4, 2),
            substr($value, 6, 2),
            substr($value, 8, 2),
            substr($value, 10, 2),
            substr($value, 12, 2),
        );
    }

    protected function parseAtCommand(string $raw, string $body): AtTrackFrame
    {
        // AT+GTXXX,<password>,<arg1>,...,<serial>$
        $afterPrefix = substr($body, 5); // strip "AT+GT"
        $commaPos = strpos($afterPrefix, ',');
        $commandWord = 'GT'.($commaPos === false ? $afterPrefix : substr($afterPrefix, 0, $commaPos));
        $fields = $commaPos === false ? [] : explode(',', substr($afterPrefix, $commaPos + 1));

        return new AtTrackFrame(
            rawFrame: $raw,
            frameType: 'AT',
            commandWord: $commandWord,
            protocolVersion: null,
            imei: null,
            deviceName: null,
            countNumber: end($fields) ?: null,
            serialNumber: end($fields) ?: null,
            fields: $fields,
        );
    }

    protected function hexFrame(string $raw): AtTrackFrame
    {
        return new AtTrackFrame(
            rawFrame: $raw,
            frameType: 'unknown',
            commandWord: null,
            protocolVersion: null,
            imei: null,
            deviceName: null,
            countNumber: null,
            serialNumber: null,
            fields: [],
            parseError: 'HEX frame detected — configure device for ASCII (AT+GTSRI Protocol Format = 0)',
        );
    }

    protected function failed(string $raw, string $reason): AtTrackFrame
    {
        return new AtTrackFrame(
            rawFrame: $raw,
            frameType: 'unknown',
            commandWord: null,
            protocolVersion: null,
            imei: null,
            deviceName: null,
            countNumber: null,
            serialNumber: null,
            fields: [],
            parseError: $reason,
        );
    }
}
