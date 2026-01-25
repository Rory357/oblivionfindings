<?php

namespace App\Observers;

use App\Models\Shift;
use App\Models\TimelineEvent;

class ShiftObserver
{
    public function created(Shift $shift): void
    {
        $this->upsertTimelineEvent($shift);
    }

    public function updated(Shift $shift): void
    {
        $this->upsertTimelineEvent($shift);
    }

    public function deleted(Shift $shift): void
    {
        TimelineEvent::query()
            ->where('type', 'shift')
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->delete();
    }

    protected function upsertTimelineEvent(Shift $shift): void
    {
        $shift->loadMissing('client');

        $subject = 'Shift';
        if ($shift->client) {
            $subject = 'Shift with ' . trim(($shift->client->first_name ?? '') . ' ' . ($shift->client->last_name ?? ''));
        }

        TimelineEvent::query()->updateOrCreate(
            [
                'type' => 'shift',
                'source_type' => Shift::class,
                'source_id' => $shift->id,
            ],
            [
                'occurred_at' => $shift->starts_at,
                'source_type' => Shift::class,
                'source_id' => $shift->id,
                'actor_user_id' => $shift->user_id,
                'client_id' => $shift->client_id,
                'site_id' => $shift->client?->site_id,
                'subject' => $subject,
                'body' => $shift->notes,
                'meta' => array_filter([
                    'shift_id' => $shift->id,
                    'starts_at' => $shift->starts_at?->toISOString(),
                    'ends_at' => $shift->ends_at?->toISOString(),
                    'actual_starts_at' => $shift->actual_starts_at?->toISOString(),
                    'actual_ends_at' => $shift->actual_ends_at?->toISOString(),
                    'location' => $shift->location,
                    'status' => $shift->status,
                ], fn($v) => $v !== null && $v !== ''),
                'visibility' => 'internal',
                'created_by' => $shift->created_by,
            ]
        );
    }
}
