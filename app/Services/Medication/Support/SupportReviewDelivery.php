<?php

namespace App\Services\Medication\Support;

use App\Models\Client;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSupportTriggerOutbox;
use Illuminate\Support\Facades\DB;

/** Transactional source receipt -> canonical P08a identity, with scheduler retries. */
class SupportReviewDelivery
{
    public function enqueue(MedicationSelfAdminAssessment $assessment, string $kind, string $sourceKey, string $reason): void
    {
        // Called from the original source transaction, before its final P09 append.
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Persist medication support triggers in the clinical source transaction.');
        }
        $row = MedicationSupportTriggerOutbox::firstOrCreate(['source_key' => $sourceKey], [
            'client_id' => $assessment->client_id, 'assessment_id' => $assessment->id, 'kind' => $kind, 'reason' => $reason, 'occurred_at' => now('UTC')]);
        // A retried source keeps its first captured assessment/time even after reassessment.
        // Delivery recognizes that the newer assessment already covers this older event.
        abort_unless((int) $row->client_id === (int) $assessment->client_id && $row->kind === $kind, 404);
    }

    public function deliver(int $id): bool
    {
        $snapshot = MedicationSupportTriggerOutbox::findOrFail($id);
        try {
            return DB::transaction(function () use ($snapshot) {
                $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->firstOrFail();
                $assessment = MedicationSelfAdminAssessment::withTrashed()->where('client_id', $client->id)->whereKey($snapshot->assessment_id)->lockForUpdate()->firstOrFail();
                $current = app(MedicationSupport::class)->current((int) $client->id, true);
                $receipt = MedicationSupportTriggerOutbox::query()->whereKey($snapshot->id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
                if ($receipt->delivered_at) {
                    return true;
                }
                // A newer assessment already covers the event captured under its predecessor.
                if ($current?->id === $assessment->id) {
                    app(SupportFollowupAdapter::class)->request($assessment, $receipt->kind, $receipt->source_key, $receipt->reason, $receipt->occurred_at);
                }
                $receipt->update(['attempts' => $receipt->attempts + 1, 'last_attempt_at' => now('UTC'), 'delivered_at' => now('UTC'), 'last_error' => null]);

                return true;
            }, 3);
        } catch (\Throwable $error) {
            // Failure does not rewrite the source's successful commit. The durable receipt retries.
            MedicationSupportTriggerOutbox::query()->whereKey($id)->whereNull('delivered_at')->update([
                'attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => now('UTC'), 'last_error' => 'Reassessment work could not be delivered; retry pending.']);
            report($error);

            return false;
        }
    }

    public function sweep(int $limit = 100): int
    {
        $delivered = 0;
        $ids = MedicationSupportTriggerOutbox::query()->whereNull('delivered_at')
            ->where(fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', now('UTC')->subMinute()))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            if ($this->deliver((int) $id)) {
                $delivered++;
            }
        }

        return $delivered;
    }
}
