<?php

namespace App\Services\Medication\Reviews;

use App\Models\Client;
use App\Models\MedicationReview;
use App\Models\MedicationReviewItem;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Support\WorkerClock;

/** P05 source adapter into P08a's one follow-up lifecycle. */
final class MedicationReviewFollowupAdapter
{
    public function ensure(Client $client, MedicationReview $review, MedicationReviewItem $item): void
    {
        if ($item->outcome !== 'watch') {
            return;
        }
        $followup = app(MedicationFollowupService::class)->ensureForSource(
            source: 'review-watch', sourceId: $item->id, client: $client, medication: $item->medication,
            administration: null, ownerId: $review->owner_id, dueAt: WorkerClock::toUtc($item->watch_until->toDateString().' 23:59:59'),
            context: ['review_id' => $review->id, 'review_item_id' => $item->id,
                'what_to_watch' => $item->watch_text, 'watch_until' => $item->watch_until->toDateString(),
                'source_url' => '/emar/reviews?review='.$review->id],
        );
        $item->forceFill(['followup_id' => $followup->id])->save();
    }

    public function state(MedicationReviewItem $item, User $actor): ?array
    {
        if (! $item->followup_id) {
            return null;
        }
        $row = app(MedicationFollowupService::class)->visibleQuery($actor)
            ->whereKey($item->followup_id)->where('source_key', 'review-watch:'.$item->id)->where('client_id', $item->client_id)->first();

        return $row ? ['id' => $row->id, 'completed' => $row->completed_at !== null,
            'url' => '/medication-followups?open='.$row->id] : null;
    }
}
