<?php

namespace App\Console\Commands;

use App\Models\ShiftOpenPosition;
use App\Models\TimelineEvent;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class ExpireStaleOpenPositions extends Command
{
    protected $signature = 'shifts:expire-positions';

    protected $description = 'Cancel expired job board positions and remind managers about stale pending claims';

    public function handle(): int
    {
        $expired = $this->expireOpenPositions();
        $nudged = $this->nudgeStaleClaims();

        $this->info("Cancelled {$expired} expired open position(s). Nudged managers for {$nudged} stale claim(s).");

        return self::SUCCESS;
    }

    private function expireOpenPositions(): int
    {
        $expired = 0;

        ShiftOpenPosition::query()
            ->with(['shift.client:id,first_name,last_name,site_id'])
            ->where('status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($positions) use (&$expired) {
                foreach ($positions as $position) {
                    $updated = ShiftOpenPosition::query()
                        ->whereKey($position->id)
                        ->where('status', 'open')
                        ->update(['status' => 'cancelled']);

                    if ($updated === 0) {
                        continue;
                    }

                    $expired++;
                    $this->recordTimeline(
                        $position,
                        'shift_open_position_expired',
                        'Open position expired',
                        'The job board position passed its expiry time and was closed automatically.',
                    );
                }
            });

        return $expired;
    }

    private function nudgeStaleClaims(): int
    {
        $nudged = 0;
        $threshold = now()->subHours(48);

        ShiftOpenPosition::query()
            ->with([
                'shift.client:id,first_name,last_name,site_id',
                'claimer:id,name',
                'replacementRequest:id,shift_id,requested_by,current_staff_id,replacement_user_id,status',
            ])
            ->where('status', 'claimed')
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', $threshold)
            ->orderBy('id')
            ->chunkById(100, function ($positions) use (&$nudged) {
                foreach ($positions as $position) {
                    if ($this->recentNudgeExists($position)) {
                        continue;
                    }

                    app(NotificationService::class)->notifyCrud(null, 'claim_pending_approval', 'shift replacement', $position, $position->shift?->client, [
                        'event_key' => 'shift_replacements.claim_pending_approval',
                        'title' => 'Replacement claim awaiting approval',
                        'body' => $position->claimer?->name
                            ? $position->claimer->name.' claimed a replacement shift more than 48 hours ago.'
                            : 'A replacement shift claim has been waiting for approval for more than 48 hours.',
                        'url' => url('/operations/job-board?status=claimed'),
                        'include_managers' => true,
                        'include_assigned_workers' => false,
                        'include_entity_user' => false,
                    ]);

                    $this->recordTimeline(
                        $position,
                        'shift_replacement_claim_pending_nudged',
                        'Replacement claim reminder sent',
                        'Managers were reminded that this job board claim is still awaiting approval.',
                    );

                    $nudged++;
                }
            });

        return $nudged;
    }

    private function recentNudgeExists(ShiftOpenPosition $position): bool
    {
        return TimelineEvent::query()
            ->where('source_type', ShiftOpenPosition::class)
            ->where('source_id', $position->id)
            ->where('type', 'shift_replacement_claim_pending_nudged')
            ->where('occurred_at', '>=', now()->subDay())
            ->exists();
    }

    private function recordTimeline(ShiftOpenPosition $position, string $type, string $subject, string $body): void
    {
        // timeline_events has a unique key on (type, source_type, source_id), so a
        // position can only ever carry ONE event of a given type. The 24h window in
        // recentNudgeExists() lets a stale claim be reminded again the next day —
        // so this must REFRESH the existing event, not insert a duplicate. A plain
        // create() here threw a unique-constraint violation that aborted the whole
        // shifts:expire-positions run.
        TimelineEvent::updateOrCreate(
            [
                'type' => $type,
                'source_type' => ShiftOpenPosition::class,
                'source_id' => $position->id,
            ],
            [
                'occurred_at' => now(),
                'actor_user_id' => null,
                'client_id' => $position->shift?->client_id,
                'shift_id' => $position->shift_id,
                'site_id' => $position->shift?->client?->site_id,
                'subject' => $subject,
                'body' => $body,
                'meta' => [
                    'shift_open_position_id' => $position->id,
                    'status' => $position->fresh()?->status ?? $position->status,
                    'claimed_by' => $position->claimed_by,
                ],
                'visibility' => 'internal',
                'created_by' => null,
            ],
        );
    }
}
