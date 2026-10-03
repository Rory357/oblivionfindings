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

    public function lockRecommendation(User $actor, Client $client, int $itemId, ?string $requestKey = null): MedicationReviewItem
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
        $sameReceipt = $requestKey !== null && $item->linked_order_version_id !== null && MedicationOrderVersion::query()->whereKey($item->linked_order_version_id)
            ->where('client_id', $client->id)->where(fn ($q) => $q->where('entry_request_key', $requestKey)->orWhere('cessation_request_key', $requestKey))->exists();
        if ($review->status !== 'completed' || ! $item->isChange() || $item->decision !== 'agreed' || ($item->linked_order_version_id !== null && ! $sameReceipt)) {
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
            && ($lockedItem->outcome === 'swap' || $lockedItem->client_medication_id === null || (int) $version->client_medication_id === (int) $lockedItem->client_medication_id), 404);
        abort_if($version->controlled_drug && (! $actor->canDo('medications.controlled.view') || ! $actor->canDo('medications.controlled.record')), 404);
        if ((int) $lockedItem->linked_order_version_id === (int) $version->id) {
            return;
        }
        if ($lockedItem->outcome === 'stop' || ($lockedItem->outcome === 'swap' && ($lockedItem->linked_stopped_order_version_id === null || (int) $lockedItem->client_medication_id === (int) $version->client_medication_id))) {
            throw ValidationException::withMessages(['review_item' => 'A swap needs the old order stopped and a separate replacement order. A stop links its cessation evidence.']);
        }
        if ($lockedItem->decision !== 'agreed' || $lockedItem->linked_order_version_id !== null) {
            throw ValidationException::withMessages(['review_item' => 'This recommendation has changed. Reload it before entering an order.']);
        }
        $lockedItem->forceFill(['linked_order_version_id' => $version->id, 'linked_replacement_order_version_id' => $lockedItem->outcome === 'swap' ? $version->id : null, 'classification_pending' => false,
            'controlled_snapshot' => (bool) $lockedItem->controlled_snapshot || (bool) $version->controlled_drug])->save();
        $review = MedicationReview::query()->whereKey($lockedItem->review_id)->where('client_id', $lockedItem->client_id)->firstOrFail();
        $review->forceFill(['revision' => $review->revision + 1])->save();
        $event = MedicationReviewEvent::query()->create(['review_id' => $review->id, 'client_id' => $review->client_id,
            'actor_id' => $actor->id, 'event' => 'order_entered',
            'details' => ['item_id' => $lockedItem->id, 'order_version_id' => $version->id, 'state' => 'Entered in Orders — check status there'], 'created_at' => now()]);
        AuditLogger::logOrFail('medications.review.order_entered', $review, ['actor_id' => $actor->id, 'event_id' => $event->id]);
    }

    /** Cessation evidence is separate from a replacement waiting to be checked. */
    public function linkStoppedVersion(User $actor, MedicationReviewItem $lockedItem, MedicationOrderVersion $version): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Link cessation inside the canonical order transaction.');
        }
        abort_unless($actor->canDo('medications.orders.manage'), 403);
        abort_unless((int) $version->client_id === (int) $lockedItem->client_id && (int) $version->client_medication_id === (int) $lockedItem->client_medication_id && $version->ceased_at !== null, 404);
        abort_if($version->controlled_drug && (! $actor->canDo('medications.controlled.view') || ! $actor->canDo('medications.controlled.record')), 404);
        if (! in_array($lockedItem->outcome, ['stop', 'swap'], true) || $lockedItem->decision !== 'agreed') {
            throw ValidationException::withMessages(['review_item' => 'This recommendation is not an agreed stop or swap.']);
        }
        if ((int) $lockedItem->linked_stopped_order_version_id === (int) $version->id) {
            return;
        }
        if ($lockedItem->linked_stopped_order_version_id !== null) {
            throw ValidationException::withMessages(['review_item' => 'Cessation evidence is already linked.']);
        }
        $lockedItem->forceFill(['linked_stopped_order_version_id' => $version->id, 'linked_order_version_id' => $lockedItem->outcome === 'stop' ? $version->id : $lockedItem->linked_order_version_id])->save();
        $review = $lockedItem->review;
        $review->forceFill(['revision' => $review->revision + 1])->save();
        $event = MedicationReviewEvent::create(['review_id' => $review->id, 'client_id' => $review->client_id, 'actor_id' => $actor->id, 'event' => 'order_stopped',
            'details' => ['item_id' => $lockedItem->id, 'stopped_order_version_id' => $version->id, 'replacement_required' => $lockedItem->outcome === 'swap'], 'created_at' => now()]);
        AuditLogger::logOrFail('medications.review.order_stopped', $review, ['actor_id' => $actor->id, 'event_id' => $event->id]);
    }
}
