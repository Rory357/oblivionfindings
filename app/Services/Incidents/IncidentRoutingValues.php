<?php

namespace App\Services\Incidents;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Closed organisational routing intent, not a source-access or ownership grant. */
final class IncidentRoutingValues
{
    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }

    public static function integer(mixed $value, string $field, bool $zero = false): int
    {
        if ((! is_int($value) && ! (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)))
            || strlen((string) $value) > 10 || (int) $value > 2147483647 || (int) $value < ($zero ? 0 : 1)) {
            self::error($field, 'Use a valid whole-number identity or revision.');
        }
        return (int) $value;
    }

    public static function keys(array $value, array $keys, string $field): void
    {
        if (array_diff(array_keys($value), $keys)) {
            self::error($field, 'Only the routing command fields may be submitted.');
        }
    }

    public static function instant(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            self::error($field, 'Use a real date and time with its UTC offset.');
        }
        if (preg_match('/([+-])([0-9]{2}):([0-9]{2})$/', $value, $offset) && ((int) $offset[2] > 14 || (int) $offset[3] > 59 || ((int) $offset[2] === 14 && (int) $offset[3] !== 0))) { self::error($field, 'Use a real UTC offset.'); }
        try {
            $at = CarbonImmutable::parse($value);
            if ($at->year < 1000) { self::error($field, 'Use a representable real date.'); }
            if ($at->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                self::error($field, 'Use a real date and time.');
            }
            return $at->utc()->format('Y-m-d\TH:i:s.u\Z');
        } catch (\Throwable) {
            self::error($field, 'Use a real date and time.');
        }
    }

    public static function configuration(array $input): array
    {
        self::keys($input, ['schema_version', 'routes', 'triage'], 'configuration');
        if (($input['schema_version'] ?? null) !== 1 || ! is_array($input['routes'] ?? null) || ! array_is_list($input['routes']) || count($input['routes']) > 500) {
            self::error('configuration', 'Use the supported bounded routing configuration.');
        }
        $routes = [];
        foreach ($input['routes'] as $index => $route) {
            $field = 'configuration.routes.'.$index;
            if (! is_array($route)) {
                self::error($field, 'Use a routing rule.');
            }
            self::keys($route, ['site_id', 'scope', 'lead_user_id', 'effective_from', 'effective_until', 'cover_user_id', 'cover_from', 'cover_until'], $field);
            if (! in_array($route['scope'] ?? null, ['ordinary', 'restricted'], true)) {
                self::error($field.'.scope', 'Choose ordinary or restricted routing.');
            }
            $row = [
                'site_id' => self::integer($route['site_id'] ?? null, $field.'.site_id'), 'scope' => $route['scope'],
                'lead_user_id' => self::integer($route['lead_user_id'] ?? null, $field.'.lead_user_id'),
                'effective_from' => self::instant($route['effective_from'] ?? null, $field.'.effective_from'),
                'effective_until' => self::instant($route['effective_until'] ?? null, $field.'.effective_until'),
                'cover_user_id' => isset($route['cover_user_id']) ? self::integer($route['cover_user_id'], $field.'.cover_user_id') : null,
                'cover_from' => self::instant($route['cover_from'] ?? null, $field.'.cover_from'),
                'cover_until' => self::instant($route['cover_until'] ?? null, $field.'.cover_until'),
            ];
            if ($row['effective_from'] === null || ($row['effective_until'] !== null && $row['effective_until'] <= $row['effective_from'])) {
                self::error($field.'.effective_until', 'Provide a start and an end after the start when an end is needed.');
            }
            if ($row['cover_user_id'] !== null || $row['cover_from'] !== null || $row['cover_until'] !== null) {
                if ($row['cover_user_id'] === null || $row['cover_from'] === null || $row['cover_until'] === null
                    || $row['cover_until'] <= $row['cover_from'] || $row['cover_from'] < $row['effective_from']
                    || ($row['effective_until'] !== null && $row['cover_until'] > $row['effective_until']) || $row['cover_user_id'] === $row['lead_user_id']) {
                    self::error($field.'.cover_user_id', 'Cover needs a different named person and a complete interval within the rule.');
                }
            }
            foreach ($routes as $prior) {
                if ($prior['site_id'] === $row['site_id'] && $prior['scope'] === $row['scope']
                    && ($prior['effective_until'] === null || $row['effective_from'] < $prior['effective_until'])
                    && ($row['effective_until'] === null || $prior['effective_from'] < $row['effective_until'])) {
                    self::error($field.'.effective_from', 'Routing intervals for the same Site and scope must not overlap.');
                }
            }
            $routes[] = $row;
        }
        usort($routes, fn ($a, $b) => [$a['site_id'], $a['scope'], $a['effective_from']] <=> [$b['site_id'], $b['scope'], $b['effective_from']]);
        $triage = $input['triage'] ?? null;
        if ($triage !== null) {
            if (! is_array($triage)) {
                self::error('configuration.triage', 'Choose a real monitored triage destination.');
            }
            self::keys($triage, ['user_id', 'escalation_user_id', 'escalation_after_minutes'], 'configuration.triage');
            $triage = [
                'user_id' => self::integer($triage['user_id'] ?? null, 'configuration.triage.user_id'),
                'escalation_user_id' => self::integer($triage['escalation_user_id'] ?? null, 'configuration.triage.escalation_user_id'),
                'escalation_after_minutes' => self::integer($triage['escalation_after_minutes'] ?? null, 'configuration.triage.escalation_after_minutes'),
            ];
        }
        return ['schema_version' => 1, 'routes' => $routes, 'triage' => $triage];
    }

    public static function command(array $input): array
    {
        self::keys($input, ['configuration', 'expected_revision', 'request_uuid', 'reason'], 'configuration');
        if (! is_array($input['configuration'] ?? null) || ! is_string($input['request_uuid'] ?? null) || ! Str::isUuid($input['request_uuid'])) {
            self::error('request_uuid', 'Use a valid routing command identity and configuration.');
        }
        if (! is_string($input['reason'] ?? null) || trim($input['reason']) === '' || mb_strlen($input['reason']) > 4000) {
            self::error('reason', 'Record why this routing configuration is being reviewed.');
        }
        return [
            'configuration' => self::configuration($input['configuration']),
            'triage_supplied' => array_key_exists('triage', $input['configuration']),
            'expected_revision' => self::integer($input['expected_revision'] ?? null, 'expected_revision', true),
            'request_uuid' => strtolower($input['request_uuid']), 'reason' => trim($input['reason']),
        ];
    }

    public static function error(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
