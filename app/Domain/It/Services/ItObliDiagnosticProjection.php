<?php

namespace App\Domain\It\Services;

/** Pure allowlist projection; reported absence never proves uninstallation or a stopped service. */
final class ItObliDiagnosticProjection
{
    public const MISSING_FIELDS = ['windows_version', 'architecture', 'memory_total_bytes', 'memory_available_bytes', 'uptime_seconds', 'cpu_percent', 'disks'];

    private const MAX = 9007199254740991;

    public static function project(array $inventory): array
    {
        $system = ['windows_version' => 'unknown', 'architecture' => 'unknown', 'memory_total_bytes' => null, 'memory_available_bytes' => null, 'uptime_seconds' => null, 'cpu_percent' => null, 'disks' => []];
        $os = $inventory['os_version'] ?? null;
        if (is_string($os) && strlen($os) <= 1200 && preg_match('/(?:^| )([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,8}(?:\.[0-9]{1,8})?)$/D', $os, $match)) {
            $system['windows_version'] = $match[1];
        }
        if (in_array($inventory['architecture'] ?? null, ['amd64', 'arm64'], true)) {
            $system['architecture'] = $inventory['architecture'];
        }
        $total = self::number($inventory['memory_total_bytes'] ?? null, 1);
        $available = self::number($inventory['memory_available_bytes'] ?? null);
        $system['memory_total_bytes'] = $total;
        $system['memory_available_bytes'] = $total !== null && $available !== null && $available <= $total ? $available : null;
        $system['uptime_seconds'] = self::number($inventory['uptime_seconds'] ?? null, 0, 3155760000);
        $cpu = $inventory['cpu_percent'] ?? null;
        if ((is_int($cpu) || is_float($cpu)) && is_finite((float) $cpu) && $cpu >= 0 && $cpu <= 100) {
            $system['cpu_percent'] = round((float) $cpu, 1);
        }
        $errors = is_array($inventory['probe_errors'] ?? null) ? $inventory['probe_errors'] : [];
        $diskIncomplete = ! is_array($inventory['disks'] ?? null) || in_array('disks', $errors, true);
        $seen = [];
        if (is_array($inventory['disks'] ?? null) && count($inventory['disks']) > 64) {
            $diskIncomplete = true;
        }
        foreach (array_slice(is_array($inventory['disks'] ?? null) ? $inventory['disks'] : [], 0, 64) as $disk) {
            if (! is_array($disk)) {
                $diskIncomplete = true;

                continue;
            }
            $name = $disk['name'] ?? null;
            $size = self::number($disk['total_bytes'] ?? null, 1);
            $free = self::number($disk['free_bytes'] ?? null);
            if (! is_string($name) || ! preg_match('/\A[A-Z]:\z/', $name) || $size === null || $free === null || $free > $size || isset($seen[$name])) {
                $diskIncomplete = true;

                continue;
            }
            $seen[$name] = true;
            if (count($system['disks']) >= 8) {
                $diskIncomplete = true;

                continue;
            }
            $system['disks'][] = ['name' => $name, 'total_bytes' => $size, 'free_bytes' => $free];
        }
        usort($system['disks'], fn ($a, $b) => strcmp($a['name'], $b['name']));
        $missing = [];
        foreach (self::MISSING_FIELDS as $field) {
            if (($field === 'disks' && $diskIncomplete) || ($field !== 'disks' && ($system[$field] === null || $system[$field] === 'unknown'))) {
                $missing[] = $field;
            }
        }
        $softwareComplete = ($inventory['software_complete'] ?? null) === true && ! in_array('software', $errors, true) && is_array($inventory['software'] ?? null);
        if (is_array($inventory['software'] ?? null) && count($inventory['software']) > 5000) {
            $softwareComplete = false;
        }
        $products = ['microsoft edge' => 'microsoft_edge', 'google chrome' => 'google_chrome', 'microsoft teams' => 'microsoft_teams', 'teams machine-wide installer' => 'teams_installer'];
        $software = [];
        $ambiguous = [];
        foreach (array_slice(is_array($inventory['software'] ?? null) ? $inventory['software'] : [], 0, 5000) as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                $softwareComplete = false;

                continue;
            }
            $name = $products[strtolower($item['name'])] ?? null;
            if ($name === null) {
                continue;
            }
            $version = $item['version'] ?? null;
            if (! is_string($version) || ! preg_match('/\A[0-9]{1,9}(\.[0-9]{1,9}){1,5}\z/', $version)) {
                $softwareComplete = false;

                continue;
            }
            if (isset($software[$name]) && $software[$name]['version'] !== $version) {
                $ambiguous[$name] = true;
                $softwareComplete = false;
            }
            $software[$name] = ['name' => $name, 'version' => $version];
        }
        foreach ($ambiguous as $name => $_) {
            unset($software[$name]);
        }
        ksort($software);
        $servicesComplete = ($inventory['services_complete'] ?? null) === true && ! in_array('services', $errors, true) && is_array($inventory['services'] ?? null);
        if (is_array($inventory['services'] ?? null) && count($inventory['services']) > 1000) {
            $servicesComplete = false;
        }
        $known = ['spooler' => 'print_spooler', 'wuauserv' => 'windows_update', 'bits' => 'background_transfer', 'windefend' => 'windows_defender', 'mpssvc' => 'windows_firewall'];
        $services = [];
        foreach (array_slice(is_array($inventory['services'] ?? null) ? $inventory['services'] : [], 0, 1000) as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                $servicesComplete = false;

                continue;
            }
            $name = $known[strtolower($item['name'])] ?? null;
            if ($name === null) {
                continue;
            }
            $state = in_array($item['state'] ?? null, ['running', 'stopped'], true) ? $item['state'] : 'unknown';
            if (isset($services[$name]) && $services[$name]['state'] !== $state) {
                $state = 'unknown';
                $servicesComplete = false;
            }
            $services[$name] = ['name' => $name, 'state' => $state];
        }
        ksort($services);

        return ['snapshot' => ['system' => $system, 'software' => array_values($software), 'services' => array_values($services)], 'completeness' => ['system' => ['complete' => $missing === [], 'missing_fields' => $missing], 'software' => ['complete' => $softwareComplete], 'services' => ['complete' => $servicesComplete]]];
    }

    private static function number(mixed $value, int $minimum = 0, int $maximum = self::MAX): ?int
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum ? $value : null;
    }
}
