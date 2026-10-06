<?php

namespace App\Services\Medication\Stock;

use App\Models\ClientMedicationStock;
use App\Models\FleetMedicationTransitLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Server-only custody identity; never constructed from administration HTTP data. */
final class TransitStockContext
{
    private function __construct(public readonly FleetMedicationTransitLog $log, public readonly array $packLines) {}

    public static function fromLog(FleetMedicationTransitLog $log, ?string $quantityUnit, array $packLines = []): self
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Transit stock requires the governing custody transaction.');
        }
        $stock = ClientMedicationStock::where('client_medication_id', $log->medication_id)->lockForUpdate()->firstOrFail();
        if ($stock->lots_started_at === null || trim((string) $quantityUnit) !== $stock->unit) {
            throw ValidationException::withMessages(['quantity_unit' => 'Enter the actual removed quantity in the recorded stock unit. No unit conversion is assumed.']);
        }

        return new self($log, $packLines);
    }
}
