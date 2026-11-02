<?php

namespace App\Domain\SecurityDevices\Alarms\SiaDc09;

/**
 * Clean-room mapper for Ademco Contact ID (ANSI/SIA DC-05 `ADM-CID`) and SIA-DCS (ANSI/SIA DC-03) payloads.
 *
 * Maps alarm panel event frames onto canonical `DeviceEvent` types, severities,
 * and zone states consumed by `DeviceProfilePresenter::alarmPanelTypeProfile()`.
 *
 * Official specifications:
 *   - ANSI/SIA DC-05-1999.09 (Ademco Contact ID Protocol for Digital Alarm Communications)
 *   - ANSI/SIA DC-03-1990.01 (SIA Digital Communication Standard)
 */
final class ContactIdEventMapper
{
    /**
     * @return array{
     *     ok: bool,
     *     event_type: string,
     *     severity: string,
     *     summary: string,
     *     partition: string|null,
     *     zone: string|null,
     *     zone_state: string|null,
     *     cid_qualifier?: string,
     *     cid_code?: string,
     *     sia_code?: string
     * }
     */
    public function map(string $token, string $data): array
    {
        if ($token === 'NULL') {
            return [
                'ok' => true,
                'event_type' => 'heartbeat',
                'severity' => 'info',
                'summary' => 'SIA DC-09 supervision heartbeat received.',
                'partition' => null,
                'zone' => null,
                'zone_state' => null,
            ];
        }

        if ($token === 'ADM-CID') {
            return $this->mapContactId($data);
        }

        if ($token === 'SIA-DCS') {
            return $this->mapSiaDcs($data);
        }

        return [
            'ok' => false,
            'event_type' => 'unknown',
            'severity' => 'info',
            'summary' => 'Unsupported SIA token.',
            'partition' => null,
            'zone' => null,
            'zone_state' => null,
        ];
    }

    /**
     * Parse ADM-CID data block such as `#1234|1130 01 004` or `#1234|18 1 130 01 004` or `123418113001004`.
     *
     * @return array{
     *     ok: bool,
     *     event_type: string,
     *     severity: string,
     *     summary: string,
     *     partition: string|null,
     *     zone: string|null,
     *     zone_state: string|null,
     *     cid_qualifier?: string,
     *     cid_code?: string
     * }
     */
    private function mapContactId(string $data): array
    {
        $afterPipe = str_contains($data, '|') ? explode('|', $data, 2)[1] : $data;
        $compact = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper($afterPipe)) ?? '';

        // Strip optional leading account + message-type `18` when panel embeds full 15-char Contact ID string
        if (strlen($compact) >= 13 && substr($compact, -11, 2) === '18') {
            $compact = substr($compact, -9);
        } elseif (strlen($compact) === 11 && str_starts_with($compact, '18')) {
            $compact = substr($compact, 2);
        }

        if (strlen($compact) !== 9 || ! in_array($compact[0], ['1', '3', '6'], true) || ! ctype_digit(substr($compact, 1, 3))) {
            return [
                'ok' => false,
                'event_type' => 'unknown',
                'severity' => 'info',
                'summary' => 'Malformed Ademco Contact ID payload.',
                'partition' => null,
                'zone' => null,
                'zone_state' => null,
            ];
        }

        $qualifier = $compact[0]; // 1 = Event / Opening, 3 = Restore / Closing, 6 = Status
        $codeNum = (int) substr($compact, 1, 3);
        $codeStr = substr($compact, 1, 3);
        $partition = substr($compact, 4, 2);
        $zoneOrUser = substr($compact, 6, 3);
        $isRestore = $qualifier === '3';

        $zone = $zoneOrUser !== '000' ? $zoneOrUser : null;
        $partitionClean = $partition !== '00' ? $partition : '01';

        // 100–109: Medical / Personal Emergency
        if ($codeNum >= 100 && $codeNum <= 109) {
            return [
                'ok' => true,
                'event_type' => $isRestore ? 'zone_restored' : 'panic',
                'severity' => $isRestore ? 'info' : 'critical',
                'summary' => $isRestore ? 'Medical emergency restored.' : 'Medical or personal emergency alarm triggered.',
                'partition' => $partitionClean,
                'zone' => $zone,
                'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'triggered') : null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 110–118: Fire / Smoke Alarm
        if ($codeNum >= 110 && $codeNum <= 118) {
            return [
                'ok' => true,
                'event_type' => $isRestore ? 'zone_restored' : 'alarm_triggered',
                'severity' => $isRestore ? 'info' : 'critical',
                'summary' => $isRestore ? 'Fire alarm zone restored.' : 'Fire or smoke alarm triggered.',
                'partition' => $partitionClean,
                'zone' => $zone,
                'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'triggered') : null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 120–129: Panic / Duress / Hold-up
        if ($codeNum >= 120 && $codeNum <= 129) {
            $eventType = $isRestore ? 'zone_restored' : ($codeNum === 121 ? 'duress' : 'panic');

            return [
                'ok' => true,
                'event_type' => $eventType,
                'severity' => $isRestore ? 'info' : 'critical',
                'summary' => $isRestore
                    ? 'Panic or duress zone restored.'
                    : ($codeNum === 121 ? 'Silent duress alarm triggered.' : 'Panic alarm triggered.'),
                'partition' => $partitionClean,
                'zone' => $zone,
                'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'triggered') : null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 137, 144, 145, 383: Tamper
        if (in_array($codeNum, [137, 144, 145, 383], true)) {
            return [
                'ok' => true,
                'event_type' => $isRestore ? 'tamper_restored' : 'tamper',
                'severity' => $isRestore ? 'info' : 'warning',
                'summary' => $isRestore ? 'Tamper restored.' : 'Alarm panel or sensor tamper detected.',
                'partition' => $partitionClean,
                'zone' => $zone,
                'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'tamper') : null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 130–139: Burglary / Intrusion
        if ($codeNum >= 130 && $codeNum <= 139) {
            return [
                'ok' => true,
                'event_type' => $isRestore ? 'zone_restored' : 'alarm_triggered',
                'severity' => $isRestore ? 'info' : 'critical',
                'summary' => $isRestore
                    ? sprintf('Intrusion zone %s restored to normal.', $zone ?? $partitionClean)
                    : sprintf('Intrusion alarm triggered on zone %s.', $zone ?? $partitionClean),
                'partition' => $partitionClean,
                'zone' => $zone,
                'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'triggered') : null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 150–169: 24-Hour Environmental (water leak, gas, temperature)
        if ($codeNum >= 150 && $codeNum <= 169) {
            return [
                'ok' => true,
                'event_type' => $isRestore ? 'zone_restored' : 'alarm_triggered',
                'severity' => $isRestore ? 'info' : 'warning',
                'summary' => $isRestore ? 'Environmental alarm zone restored.' : 'Environmental hazard sensor triggered.',
                'partition' => $partitionClean,
                'zone' => $zone,
                'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'triggered') : null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 300–399: System & Power Troubles (301 AC Loss, 302 Low Battery, 384 RF Low Battery)
        if ($codeNum >= 300 && $codeNum <= 399) {
            $summary = match ($codeNum) {
                301 => $isRestore ? 'Mains AC power restored.' : 'Mains AC power lost on alarm panel.',
                302, 384 => $isRestore ? 'Battery trouble restored.' : 'Low backup or wireless zone battery reported.',
                default => $isRestore ? 'System trouble restored.' : 'Alarm panel trouble reported.',
            };

            return [
                'ok' => true,
                'event_type' => $isRestore ? 'fault_restored' : 'alarm_fault',
                'severity' => $isRestore ? 'info' : 'warning',
                'summary' => $summary,
                'partition' => $partitionClean,
                'zone' => $zone,
                'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'fault') : null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 400–409, 441, 442, 456: Open / Close (ANSI/SIA DC-05: Q=1 is Opening/Disarm, Q=3 is Closing/Arm!)
        if (($codeNum >= 400 && $codeNum <= 409) || in_array($codeNum, [441, 442, 456], true)) {
            $isArmed = $isRestore;
            $armMode = in_array($codeNum, [441, 442, 456], true) ? 'stay/partial' : 'away';

            return [
                'ok' => true,
                'event_type' => $isArmed ? 'alarm_armed' : 'alarm_disarmed',
                'severity' => 'info',
                'summary' => $isArmed
                    ? sprintf('Area %s armed (%s).', $partitionClean, $armMode)
                    : sprintf('Area %s disarmed.', $partitionClean),
                'partition' => $partitionClean,
                'zone' => null,
                'zone_state' => null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 570–579: Zone Bypass
        if ($codeNum >= 570 && $codeNum <= 579) {
            return [
                'ok' => true,
                'event_type' => $isRestore ? 'zone_unbypassed' : 'zone_bypassed',
                'severity' => $isRestore ? 'info' : 'warning',
                'summary' => $isRestore
                    ? sprintf('Zone %s unbypassed.', $zone ?? 'unknown')
                    : sprintf('Zone %s bypassed.', $zone ?? 'unknown'),
                'partition' => $partitionClean,
                'zone' => $zone,
                'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'bypassed') : null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        // 601–608: Periodic / Manual Test
        if ($codeNum >= 601 && $codeNum <= 608) {
            return [
                'ok' => true,
                'event_type' => 'periodic_test',
                'severity' => 'info',
                'summary' => 'Alarm communicator test report received.',
                'partition' => $partitionClean,
                'zone' => null,
                'zone_state' => null,
                'cid_qualifier' => $qualifier,
                'cid_code' => $codeStr,
            ];
        }

        return [
            'ok' => true,
            'event_type' => $isRestore ? 'alarm_restored' : 'alarm_event',
            'severity' => 'info',
            'summary' => sprintf('Contact ID event %s%s received.', $qualifier, $codeStr),
            'partition' => $partitionClean,
            'zone' => $zone,
            'zone_state' => $zone !== null ? ($isRestore ? 'normal' : 'open') : null,
            'cid_qualifier' => $qualifier,
            'cid_code' => $codeStr,
        ];
    }

    /**
     * Parse SIA-DCS block such as `#1234|Nri01/BA003`.
     *
     * @return array{
     *     ok: bool,
     *     event_type: string,
     *     severity: string,
     *     summary: string,
     *     partition: string|null,
     *     zone: string|null,
     *     zone_state: string|null,
     *     sia_code?: string
     * }
     */
    private function mapSiaDcs(string $data): array
    {
        $afterPipe = str_contains($data, '|') ? explode('|', $data, 2)[1] : $data;
        $partition = '01';
        if (preg_match('/ri(\d{1,3})/i', $afterPipe, $pm) === 1) {
            $partition = str_pad($pm[1], 2, '0', STR_PAD_LEFT);
        }

        // Phase A, item A3: a block reads `N` (new event), then optional `ti<hh:mm>`, `id<user>` and
        // `ri<area>` parts, each optionally followed by `/`, then the two-letter event code and its zone
        // or user number, e.g. `Nri1/CL501`. The earlier pattern read the `ri` marker itself as the event
        // code, so a real closing (`Nri1/CL501`) was stored as an unknown event instead of "armed".
        $block = strtoupper(trim($afterPipe));
        $matched = preg_match('/\AN?(?:TI\d{2}:\d{2}\/?)?(?:ID\d*\/?)?(?:RI\d*\/?)?([A-Z]{2})(\d{0,4})/', $block, $em) === 1
            || preg_match('/(?:\/|\A|N)([A-Z]{2})(\d{0,4})\b/', $block, $em) === 1;

        if (! $matched) {
            return [
                'ok' => false,
                'event_type' => 'unknown',
                'severity' => 'info',
                'summary' => 'Malformed SIA-DCS event block.',
                'partition' => null,
                'zone' => null,
                'zone_state' => null,
            ];
        }

        $code = $em[1];
        $zoneRaw = $em[2] !== '' && (int) $em[2] > 0 ? str_pad($em[2], 3, '0', STR_PAD_LEFT) : null;

        return match ($code) {
            'BA', 'GA', 'WA' => [
                'ok' => true,
                'event_type' => 'alarm_triggered',
                'severity' => 'critical',
                'summary' => sprintf('SIA %s alarm triggered%s.', $code, $zoneRaw ? " on zone {$zoneRaw}" : ''),
                'partition' => $partition,
                'zone' => $zoneRaw,
                'zone_state' => $zoneRaw !== null ? 'triggered' : null,
                'sia_code' => $code,
            ],
            'PA', 'HA' => [
                'ok' => true,
                'event_type' => 'panic',
                'severity' => 'critical',
                'summary' => 'Panic or hold-up alarm triggered.',
                'partition' => $partition,
                'zone' => $zoneRaw,
                'zone_state' => $zoneRaw !== null ? 'triggered' : null,
                'sia_code' => $code,
            ],
            'FA' => [
                'ok' => true,
                'event_type' => 'alarm_triggered',
                'severity' => 'critical',
                'summary' => 'Fire alarm triggered.',
                'partition' => $partition,
                'zone' => $zoneRaw,
                'zone_state' => $zoneRaw !== null ? 'triggered' : null,
                'sia_code' => $code,
            ],
            'TA' => [
                'ok' => true,
                'event_type' => 'tamper',
                'severity' => 'warning',
                'summary' => 'Tamper alarm reported.',
                'partition' => $partition,
                'zone' => $zoneRaw,
                'zone_state' => $zoneRaw !== null ? 'tamper' : null,
                'sia_code' => $code,
            ],
            'BR', 'FR', 'PR', 'TR', 'WR' => [
                'ok' => true,
                'event_type' => 'zone_restored',
                'severity' => 'info',
                'summary' => sprintf('Zone %s restored to normal.', $zoneRaw ?? $partition),
                'partition' => $partition,
                'zone' => $zoneRaw,
                'zone_state' => $zoneRaw !== null ? 'normal' : null,
                'sia_code' => $code,
            ],
            'CL', 'CA', 'CG', 'NL' => [
                'ok' => true,
                'event_type' => 'alarm_armed',
                'severity' => 'info',
                'summary' => sprintf('Area %s armed.', $partition),
                'partition' => $partition,
                'zone' => null,
                'zone_state' => null,
                'sia_code' => $code,
            ],
            'OP', 'OA', 'OG' => [
                'ok' => true,
                'event_type' => 'alarm_disarmed',
                'severity' => 'info',
                'summary' => sprintf('Area %s disarmed.', $partition),
                'partition' => $partition,
                'zone' => null,
                'zone_state' => null,
                'sia_code' => $code,
            ],
            'BB' => [
                'ok' => true,
                'event_type' => 'zone_bypassed',
                'severity' => 'warning',
                'summary' => sprintf('Zone %s bypassed.', $zoneRaw ?? 'unknown'),
                'partition' => $partition,
                'zone' => $zoneRaw,
                'zone_state' => $zoneRaw !== null ? 'bypassed' : null,
                'sia_code' => $code,
            ],
            'BU' => [
                'ok' => true,
                'event_type' => 'zone_unbypassed',
                'severity' => 'info',
                'summary' => sprintf('Zone %s unbypassed.', $zoneRaw ?? 'unknown'),
                'partition' => $partition,
                'zone' => $zoneRaw,
                'zone_state' => $zoneRaw !== null ? 'normal' : null,
                'sia_code' => $code,
            ],
            'AT', 'YT' => [
                'ok' => true,
                'event_type' => 'alarm_fault',
                'severity' => 'warning',
                'summary' => $code === 'AT' ? 'Mains AC trouble reported.' : 'Low battery trouble reported.',
                'partition' => $partition,
                'zone' => null,
                'zone_state' => null,
                'sia_code' => $code,
            ],
            'RP', 'RX' => [
                'ok' => true,
                'event_type' => 'periodic_test',
                'severity' => 'info',
                'summary' => 'Periodic communicator test received.',
                'partition' => $partition,
                'zone' => null,
                'zone_state' => null,
                'sia_code' => $code,
            ],
            default => [
                'ok' => true,
                'event_type' => 'alarm_event',
                'severity' => 'info',
                'summary' => sprintf('SIA-DCS event %s received.', $code),
                'partition' => $partition,
                'zone' => $zoneRaw,
                'zone_state' => null,
                'sia_code' => $code,
            ],
        };
    }
}
