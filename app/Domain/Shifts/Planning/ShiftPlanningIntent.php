<?php

namespace App\Domain\Shifts\Planning;

use App\Models\Shift;
use App\Services\ShiftStateGuardService;
use App\Support\ShiftTaskSupport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Ordered submitted control projection; derived Site/context are separate result metadata. */
final class ShiftPlanningIntent
{
    public static function normalize(array $data, ?Shift $original = null): array
    {
        $data = self::cleanStrings($data);
        $userId = self::id(array_key_exists('user_id', $data) ? $data['user_id'] : $original?->user_id);
        if (array_key_exists('status', $data) || $original === null) {
            $status = app(ShiftStateGuardService::class)->normalizePlanningStatus($data['status'] ?? null, $userId !== null);
        } elseif (array_key_exists('user_id', $data)) {
            $status = $userId === null ? 'draft' : ($original->status === 'draft' ? 'scheduled' : $original->status);
        } else {
            $status = $original->status;
        }
        $type = $data['shift_type'] ?? 'standard';

        return [
            'client_id' => (int) $data['client_id'],
            'service_context_id' => self::id($data['service_context_id'] ?? null),
            'user_id' => $userId,
            'starts_at' => self::instant($data['starts_at']),
            'ends_at' => self::instant($data['ends_at']),
            'location' => array_key_exists('location', $data) ? $data['location'] : $original?->location,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $original?->notes,
            'status' => $status,
            'shift_type' => $type,
            'is_sleepover' => $type === 'sleepover' || (bool) ($data['is_sleepover'] ?? false),
            'is_on_call' => $type === 'on_call' || (bool) ($data['is_on_call'] ?? false),
            'is_lone_worker' => (bool) ($data['is_lone_worker'] ?? false),
            'expected_break_minutes' => isset($data['expected_break_minutes']) && $data['expected_break_minutes'] !== '' ? (int) $data['expected_break_minutes'] : null,
            'coverage_roles' => array_values(array_key_exists('coverage_roles', $data) ? ($data['coverage_roles'] ?? []) : ($original?->coverage_roles ?? [])),
            'required_licence_class' => array_key_exists('required_licence_class', $data) ? $data['required_licence_class'] : $original?->required_licence_class,
            'required_licence_endorsements' => array_values(array_key_exists('required_licence_endorsements', $data) ? ($data['required_licence_endorsements'] ?? []) : ($original?->required_licence_endorsements ?? [])),
            'tasks' => array_key_exists('tasks', $data) ? ShiftTaskSupport::normalizeInputs($data['tasks'] ?? [])->map(fn (array $task): array => [
                'id' => $original === null ? null : self::id($task['id']), 'label' => $task['label'], 'scheduled_time' => $task['scheduled_time'],
            ])->values()->all() : ($original === null ? [] : null),
        ];
    }

    /** Same recursive Unicode trim/empty-null transforms as the HTTP middleware. */
    public static function cleanStrings(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::cleanStrings($value);
            } elseif (is_string($value)) {
                $value = Str::trim($value);
                $data[$key] = $value === '' ? null : $value;
            }
        }

        return $data;
    }

    public static function source(Shift $shift): array
    {
        return ['shift_id' => (int) $shift->id, 'client_id' => self::id($shift->client_id),
            'site_id' => self::id($shift->site_id), 'user_id' => self::id($shift->user_id),
            'service_context_id' => self::id($shift->service_context_id), 'shift_series_id' => self::id($shift->shift_series_id),
            'status' => $shift->status];
    }

    public static function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    public static function instant(mixed $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.000\Z');
    }

    private static function id(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
