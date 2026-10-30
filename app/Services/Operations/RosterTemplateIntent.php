<?php

namespace App\Services\Operations;

use App\Models\RosterTemplate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RosterTemplateIntent
{
    public static function normalize(array $data, ?RosterTemplate $existing = null): array
    {
        return ['name' => Str::trim($data['name']),
            'description' => self::text($data['description'] ?? null),
            'template_type' => $data['template_type'] ?? ($existing ? $existing->template_type : 'weekly'),
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : ($existing ? $existing->is_active : true),
            'template_shifts' => array_map(self::row(...), array_values($data['template_shifts']))];
    }

    public static function row(array $row): array
    {
        if (empty($row['client_id'])) {
            throw ValidationException::withMessages(['template_shifts' => 'Each template shift must be linked to a client.']);
        }
        if (($row['start_time'] ?? null) === ($row['end_time'] ?? null)) {
            throw ValidationException::withMessages(['template_shifts' => 'Template shift start and end times cannot be the same.']);
        }
        $type = $row['shift_type'] ?? 'standard';

        return ['client_id' => (int) $row['client_id'], 'user_id' => self::id($row['user_id'] ?? null),
            'service_context_id' => self::id($row['service_context_id'] ?? null),
            'day_of_week' => (int) $row['day_of_week'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time'],
            'shift_type' => $type, 'is_sleepover' => $type === 'sleepover' || (bool) ($row['is_sleepover'] ?? false),
            'is_on_call' => $type === 'on_call' || (bool) ($row['is_on_call'] ?? false),
            'is_lone_worker' => (bool) ($row['is_lone_worker'] ?? false),
            'expected_break_minutes' => filled($row['expected_break_minutes'] ?? null) ? (int) $row['expected_break_minutes'] : null,
            'required_skills' => array_values(array_filter(array_map(fn ($skill) => Str::trim($skill), $row['required_skills'] ?? []))),
            'location' => self::text($row['location'] ?? null, true), 'notes' => self::text($row['notes'] ?? null, true)];
    }

    public static function hash(string $action, ?int $templateId, ?array $expected, ?array $values): string
    {
        return RosterTemplateSource::hash(['action' => $action, 'template_id' => $templateId,
            'expected_source' => $expected, 'values' => $values]);
    }

    private static function text(?string $value, bool $falsey = false): ?string
    {
        $value = $value === null ? null : Str::trim($value);

        return $value === '' || ($falsey && ! $value) ? null : $value;
    }

    private static function id(mixed $value): ?int
    {
        return $value ? (int) $value : null;
    }
}
