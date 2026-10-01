<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientMedication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Order changes → schedule history → slots ahead, in the same transaction as
 * the change (P01 foundation C3). Runs from ClientMedication's model events:
 * create, verify, reject, edit, pause, resume, stop.
 */
final class DoseSlotOrderSync
{
    public function __construct(
        private readonly DoseScheduleHistory $history,
        private readonly DoseSlotGenerator $generator,
    ) {}

    public function created(ClientMedication $order): void
    {
        DB::transaction(function () use ($order): void {
            $this->history->created($order);
            $this->generateAhead($order, CarbonImmutable::now()->utc());
        });
    }

    public function updated(ClientMedication $order): void
    {
        if (array_intersect_key($order->getChanges(), array_flip([
            ...ClientMedication::verificationSensitiveFields(),
            ...DoseScheduleHistory::SLOT_RELEVANT_FIELDS,
        ])) === []) {
            return;
        }

        $now = CarbonImmutable::now()->utc();
        DB::transaction(function () use ($order, $now): void {
            $this->history->updated($order, $now);
            $this->generateAhead($order, $now);
        });
    }

    private function generateAhead(ClientMedication $order, CarbonImmutable $now): void
    {
        try {
            $this->generator->generateAhead($order, $now);
        } catch (InvalidArgumentException|LogicException $unexpectedData) {
            // An unreadable schedule must never block saving the order; the
            // hourly run retries. Database errors still propagate.
            report($unexpectedData);
        }
    }
}
