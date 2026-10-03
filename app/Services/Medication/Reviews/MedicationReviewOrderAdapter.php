<?php

namespace App\Services\Medication\Reviews;

use App\Models\Client;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationReview;
use App\Models\MedicationReviewEvent;
use App\Models\MedicationReviewItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** P04 calls this before order locks, then links the version before its final audit-chain append. */
final class MedicationReviewOrderAdapter
{
    public function __construct(private readonly MedicationRecordAccess $access) {}

    public function lockRecommendation(User $actor, Client $client, int $itemId): MedicationReviewItem
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Lock review recommendations inside the P04 source transaction.');
        }
        abort_unless($actor->canDo('medications.orders.manage'), 403);
        $this->access->assertReadable($actor, $client);
        $snapshot = MedicationReviewItem::query()->whereKey($itemId)->where('client_id', $client->id)->firstOrFail();
        $review = MedicationReview::query()->whereKey($snapshot->review_id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
        $item = MedicationReviewItem::query()->whereKey($snapshot->id)->where('review_id', $review->id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
        abort_if($item->isRestricted() && ! $actor->canDo('medications.controlled.view'), 404);
        if ($review->status !== 'completed' || ! $item->isChange() || $item->decision !== 'agreed' || $item->linked_order_version_id !== null) {
            throw ValidationException::withMessages(['review_item' => 'This recommendation is not waiting to be entered in Orders.']);
        }

        return $item;
    }

    /** Never modifies an order, sets a verified flag or bypasses P04's phone/check rules. */
    public function linkVersion(User $actor, MedicationReviewItem $lockedItem, MedicationOrderVersion $version): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Link the entered version inside the P04 source transaction.');
        }
        abort_unless($actor->canDo('medications.orders.manage'), 403);
        abort_unless((int) $version->client_id === (int) $lockedItem->client_id
            && ($lockedItem->client_medication_id === null || (int) $version->client_medication_id === (int) $lockedItem->client_medication_id), 404);
        abort_if($version->controlled_drug && (! $actor->canDo('medications.controlled.view') || ! $actor->canDo('medications.controlled.record')), 404);
        if ($lockedItem->decision !== 'agreed' || $lockedItem->linked_order_version_id !== null) {
            throw ValidationException::withMessages(['review_item' => 'This recommendation has changed. Reload it before entering an order.']);
        }
        $lockedItem->forceFill(['linked_order_version_id' => $version->id, 'classification_pending' => false,
            'controlled_snapshot' => (bool) $lockedItem->controlled_snapshot || (bool) $version->controlled_drug])->save();
        $review = MedicationReview::query()->whereKey($lockedItem->review_id)->where('client_id', $lockedItem->client_id)->firstOrFail();
        $review->forceFill(['revision' => $review->revision + 1])->save();
        $event = MedicationReviewEvent::query()->create(['review_id' => $review->id, 'client_id' => $review->client_id,
            'actor_id' => $actor->id, 'event' => 'order_entered',
            'details' => ['item_id' => $lockedItem->id, 'order_version_id' => $version->id, 'state' => 'Entered in Orders — check status there'], 'created_at' => now()]);
        AuditLogger::logOrFail('medications.review.order_entered', $review, ['actor_id' => $actor->id, 'event_id' => $event->id]);
    }
}
