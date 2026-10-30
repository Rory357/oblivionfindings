<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use Carbon\CarbonInterface;

/** Opaque source fingerprint; never permission, eligibility or commit evidence. */
final class RosterSuggestionSource
{
    public static function single(RosterSuggestionRun $run, RosterSuggestion $suggestion, Shift $shift): array
    {
        return ['run_id' => (int) $run->id, 'site_id' => (int) $run->site_id,
            'suggestion_id' => (int) $suggestion->id, 'shift_id' => (int) $suggestion->shift_id,
            'candidate_user_id' => self::id($suggestion->candidate_user_id), 'status' => $suggestion->status,
            'source_revision' => self::revision($run, $suggestion, $shift)];
    }

    public static function revision(RosterSuggestionRun $run, RosterSuggestion $suggestion, Shift $shift): string
    {
        return self::hash(['run_id' => (int) $run->id, 'site_id' => (int) $run->site_id,
            'suggestion_id' => (int) $suggestion->id, 'shift_id' => (int) $suggestion->shift_id,
            'candidate_user_id' => self::id($suggestion->candidate_user_id), 'status' => $suggestion->status,
            'shift_client_id' => self::id($shift->client_id), 'shift_site_id' => self::id($shift->site_id),
            'service_context_id' => self::id($shift->service_context_id), 'respite_booking_id' => self::id($shift->respite_booking_id),
            'shift_type' => $shift->shift_type, 'assigned_user_id' => self::id($shift->user_id), 'shift_status' => $shift->status,
            'starts_at' => self::instant($shift->starts_at), 'ends_at' => self::instant($shift->ends_at)]);
    }

    public static function instant(?CarbonInterface $value): ?string
    {
        return $value?->copy()->utc()->format('Y-m-d\TH:i:s.000\Z');
    }

    public static function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    public static function suggestion(RosterSuggestion $row): array
    {
        return ['id' => (int) $row->id, 'status' => $row->status,
            'accepted_by' => self::id($row->accepted_by), 'accepted_at' => self::instant($row->accepted_at),
            'dismissed_by' => self::id($row->dismissed_by), 'dismissed_at' => self::instant($row->dismissed_at),
            'applied_by' => self::id($row->applied_by), 'applied_at' => self::instant($row->applied_at)];
    }

    public static function assignment(RosterSuggestion $row): array
    {
        $shift = $row->getRelation('shift');

        return ['suggestion_id' => (int) $row->id, 'shift_id' => (int) $shift->id,
            'user_id' => self::id($shift->user_id), 'status' => $shift->status,
            'starts_at' => self::instant($shift->starts_at), 'ends_at' => self::instant($shift->ends_at)];
    }
}
