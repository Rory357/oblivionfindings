<?php

namespace App\Services\Operations;

use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use Illuminate\Support\Collection;

final class RosterTemplateSource
{
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    public static function expected(RosterTemplate $template, Collection $rows): array
    {
        return ['template_id' => (int) $template->id, 'source_revision' => self::revision($template, $rows)];
    }

    public static function revision(RosterTemplate $template, Collection $rows): string
    {
        return self::hash(['template_id' => (int) $template->id, 'created_by' => self::id($template->created_by),
            'deleted_at' => $template->deleted_at?->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'values' => self::values($template, $rows, false),
            'child_ids' => $rows->sortBy('id')->pluck('id')->map(fn ($id) => (int) $id)->values()->all()]);
    }

    public static function values(RosterTemplate $template, Collection $rows, bool $minutes = true): array
    {
        return ['name' => $template->name, 'description' => $template->description,
            'template_type' => $template->template_type, 'is_active' => self::flag($template, 'is_active'),
            'template_shifts' => $rows->sortBy('id')->map(fn ($row) => self::row($row, $minutes))->values()->all()];
    }

    public static function row(RosterTemplateShift $row, bool $minutes = true): array
    {
        return ['client_id' => self::id($row->client_id), 'user_id' => self::id($row->user_id),
            'service_context_id' => self::id($row->service_context_id), 'day_of_week' => (int) $row->day_of_week,
            'start_time' => $minutes ? substr((string) $row->start_time, 0, 5) : (string) $row->start_time,
            'end_time' => $minutes ? substr((string) $row->end_time, 0, 5) : (string) $row->end_time,
            'shift_type' => $row->shift_type, 'is_sleepover' => self::flag($row, 'is_sleepover'),
            'is_on_call' => self::flag($row, 'is_on_call'), 'is_lone_worker' => self::flag($row, 'is_lone_worker'),
            'expected_break_minutes' => $row->expected_break_minutes,
            'required_skills' => $row->required_skills, 'location' => $row->location, 'notes' => $row->notes];
    }

    private static function flag($model, string $key): ?bool
    {
        return $model->getRawOriginal($key) === null ? null : (bool) $model->getAttribute($key);
    }

    private static function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
