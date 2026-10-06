<?php

namespace App\Services\Medication\Support;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use App\Models\MedicationSelfAdminAssessment;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SupportReviewSources
{
    public function order(ClientMedication $order): void
    {
        if ($order->wasRecentlyCreated || array_intersect(array_keys($order->getChanges()), ClientMedication::verificationSensitiveFields()) !== []) {
            $this->forClient((int) $order->client_id, function ($a) use ($order) {
                app(SupportReviewDelivery::class)->enqueue($a, 'order', 'support-order:'.$order->id.':'.hash('sha256', json_encode([$order->getChanges(), $order->updated_at?->toIso8601String()])), 'A new or changed order — reassess support. New medicines stay Administer until set.');
            });
        }
    }

    public function error(MedicationError $error): void
    {
        if (! $error->client_id) {
            return;
        }
        if ($error->client_medication_id && ! ClientMedication::withTrashed()->whereKey($error->client_medication_id)->where('client_id', $error->client_id)->exists()) {
            return;
        }
        $this->forClient((int) $error->client_id, fn ($a) => app(SupportReviewDelivery::class)->enqueue($a, 'error', 'support-error:'.$error->id, 'A medication error or incident — reassess support.'));
    }

    public function administration(ClientMedicationAdministration $record): void
    {
        if (! in_array($record->status, ['refused', 'missed'], true)) {
            return;
        }
        $this->forClient((int) $record->client_id, function ($a) {
            $now = CarbonImmutable::now('UTC');
            $today = $now->setTimezone('Pacific/Auckland')->toDateString();
            $counts = app(DoseSlotProjection::class)->stateCounts(DoseSlotReaderScope::internal([(int) $a->client_id]), $now->setTimezone('Pacific/Auckland')->subDays(6)->toDateString(), $today, $now);
            if (($counts['refused'] ?? 0) + ($counts['missed'] ?? 0) + ($counts['not_recorded'] ?? 0) >= 3) {
                app(SupportReviewDelivery::class)->enqueue($a, 'refusals', 'support-refusals:'.$a->id.':'.$today, 'Refusals or missed doses (3 in 7 days) — reassess support.');
            }
        });
    }

    public function sweep(): int
    {
        $count = 0;
        MedicationSelfAdminAssessment::query()->where('status', 'completed')->whereDate('reassessment_date', '<', now('Pacific/Auckland')->toDateString())
            ->whereNotIn('id', MedicationSelfAdminAssessment::withTrashed()->whereNotNull('supersedes_id')->select('supersedes_id'))
            ->chunkById(100, function ($assessments) use (&$count) {
                foreach ($assessments as $a) {
                    $this->forClient((int) $a->client_id, function ($current) use ($a, &$count) {
                        if ($current->id !== $a->id) {
                            return;
                        }
                        app(SupportReviewDelivery::class)->enqueue($current, 'review_date', 'support-review-date:'.$a->id.':'.$a->reassessment_date->toDateString(), 'The support plan review date passed — support stays as it is until reassessed.');
                        $count++;
                    });
                }
            });

        return $count;
    }

    public function reconciliation(int $clientId, int $reconciliationId): void
    {
        $this->forClient($clientId, fn ($a) => app(SupportReviewDelivery::class)->enqueue($a, 'order', 'support-reconciliation:'.$reconciliationId, 'A medication reconciliation was applied — reassess support.'));
    }

    private function forClient(int $clientId, \Closure $callback): void
    {
        // Reuse the source transaction. No additional aggregate/head locks in a model observer.
        // Synchronous receipt failure rolls back canonical dose/order/error/reconciliation writes.
        $write = function () use ($clientId, $callback) {
            $assessment = app(MedicationSupport::class)->current($clientId);
            if ($assessment) {
                $callback($assessment);
            }
        };
        if (DB::transactionLevel() > 0) {
            $write();
        } else {
            DB::transaction($write, 3);
        }
    }
}
