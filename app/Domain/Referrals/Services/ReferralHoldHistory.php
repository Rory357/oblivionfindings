<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ReferralHoldHistory
{
    /** Current uninterrupted hold, independently of progress stage; missing history remains unknown. */
    public static function starts(Builder $query, CarbonImmutable $cutoff): array
    {
        $held = (clone $query)->whereNotIn('stage', ['closed', 'converted'])->whereNotNull('hold_reason');
        $records = (clone $held)->select(['id', 'stage', 'hold_reason', 'stage_changed_at'])->get()->keyBy('id');
        $states = [];
        foreach (ReferralEvent::query()->whereIn('referral_id', (clone $held)->select('id'))
            ->whereIn('kind', ['hold.recorded', 'hold.released', 'referral.closed', 'referral.reopened'])
            ->where('occurred_at', '<=', $cutoff)->orderBy('occurred_at')->orderBy('id')->cursor() as $event) {
            $previous = $states[$event->referral_id] ?? null;
            if ($event->kind !== 'hold.recorded') {
                $states[$event->referral_id] = ['start' => null, 'reason' => null];

                continue;
            }
            $payload = $event->payload;
            $start = array_key_exists('previous_hold_reason', $payload) && $payload['previous_hold_reason'] === null
                ? $event->occurred_at
                : (($previous && $previous['reason'] === ($payload['previous_hold_reason'] ?? null)) ? $previous['start'] : null);
            $states[$event->referral_id] = ['start' => $start, 'reason' => $payload['hold_reason'] ?? null];
        }
        $result = [];
        foreach ($records as $record) {
            $state = $states[$record->id] ?? null;
            $result[$record->id] = $state ? ($state['reason'] === $record->hold_reason ? $state['start'] : null)
                : ($record->stage === 'on_hold' ? $record->stage_changed_at : null);
        }

        return $result;
    }
}
