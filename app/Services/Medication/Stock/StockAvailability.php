<?php

namespace App\Services\Medication\Stock;

use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationStockLot;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\StockLotRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Current inventory reads only. Persisted balances and historic evidence are never rewritten. */
final class StockAvailability
{
    public static function today(): string
    {
        return CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
    }

    public static function quantitySql(): string
    {
        return "CASE WHEN client_medication_stocks.lots_started_at IS NULL OR EXISTS (SELECT 1 FROM client_medications AS stock_medicine WHERE stock_medicine.id = client_medication_stocks.client_medication_id AND stock_medicine.controlled_drug = 1) THEN client_medication_stocks.on_hand ELSE (SELECT COALESCE(SUM(stock_pack.quantity_remaining), 0) FROM medication_stock_lots AS stock_pack WHERE stock_pack.client_medication_stock_id = client_medication_stocks.id AND stock_pack.state = 'open' AND stock_pack.quantity_remaining > 0 AND (stock_pack.expiry_date IS NULL OR stock_pack.expiry_date >= ?)) END";
    }

    public static function usableQuantitySql(): string
    {
        return "CASE WHEN client_medication_stocks.lots_started_at IS NULL THEN client_medication_stocks.on_hand ELSE (SELECT COALESCE(SUM(stock_pack.quantity_remaining), 0) FROM medication_stock_lots AS stock_pack WHERE stock_pack.client_medication_stock_id = client_medication_stocks.id AND stock_pack.state = 'open' AND stock_pack.quantity_remaining > 0 AND (stock_pack.expiry_date IS NULL OR stock_pack.expiry_date >= ?)) END";
    }

    public function controlled(ClientMedicationStock $stock): bool
    {
        if ($stock->relationLoaded('medication') && $stock->medication && array_key_exists('controlled_drug', $stock->medication->getAttributes())) {
            return (bool) $stock->medication->controlled_drug;
        }

        return (bool) ClientMedication::withTrashed()->whereKey($stock->client_medication_id)->value('controlled_drug');
    }

    public function quantity(ClientMedicationStock $stock): ?string
    {
        if ($stock->lots_started_at === null || $this->controlled($stock)) {
            return $stock->on_hand === null ? null : Qty::normalize($stock->on_hand);
        }

        return StockLotRules::onHand($this->packs($stock)->toArray(), self::today());
    }

    public function usableQuantity(ClientMedicationStock $stock): ?string
    {
        return $stock->lots_started_at === null ? $this->quantity($stock) : StockLotRules::onHand($this->packs($stock)->toArray(), self::today());
    }

    /** @return Collection<int, MedicationStockLot> */
    public function packs(ClientMedicationStock $stock): Collection
    {
        return $stock->relationLoaded('lots') ? $stock->lots : $stock->lots()->orderBy('id')->get();
    }

    public function nextUsablePack(ClientMedicationStock $stock): ?MedicationStockLot
    {
        return $this->packs($stock)->filter(fn ($lot) => StockLotRules::usable($lot->toArray(), self::today()))
            ->sortBy(fn ($lot) => [$lot->expiry_date?->toDateString() ?? '9999-12-31', $lot->received_at?->toIso8601String() ?? '', $lot->id])
            ->first();
    }

    /** @return Collection<int, array{key: string, lot_id: int|null, date: CarbonImmutable, batch: string|null}> */
    public function expiries(ClientMedicationStock $stock, bool $expired, int $days = 30): Collection
    {
        $today = self::today();
        $end = CarbonImmutable::parse($today)->addDays(max(0, $days))->toDateString();
        if ($stock->lots_started_at === null) {
            if ($stock->expiry_date === null || $stock->on_hand === null || ! Qty::greaterThan($stock->on_hand, 0)) {
                return collect();
            }
            $date = $stock->expiry_date->toDateString();
            if ($expired ? $date >= $today : ($date < $today || $date > $end)) {
                return collect();
            }

            return collect([['key' => 'legacy:'.$stock->id.':'.$date, 'lot_id' => null,
                'date' => CarbonImmutable::instance($stock->expiry_date), 'batch' => $stock->batch_number]]);
        }

        return $this->packs($stock)->filter(fn ($lot) => $lot->state === 'open' && Qty::greaterThan($lot->quantity_remaining, 0)
            && $lot->expiry_date !== null && ($expired ? $lot->expiry_date->toDateString() < $today
                : ($lot->expiry_date->toDateString() >= $today && $lot->expiry_date->toDateString() <= $end)))
            ->map(fn ($lot) => ['key' => 'pack:'.$lot->id, 'lot_id' => (int) $lot->id,
                'date' => CarbonImmutable::instance($lot->expiry_date), 'batch' => $lot->batch_number])->values();
    }

    public static function expiryScope(Builder $query, bool $expired, int $days = 30): Builder
    {
        $today = self::today();
        $end = CarbonImmutable::parse($today)->addDays(max(0, $days))->toDateString();
        $dateFilter = fn ($q) => $expired ? $q->where('expiry_date', '<', $today) : $q->whereBetween('expiry_date', [$today, $end]);

        return $query->where(fn ($q) => $q
            ->where(fn ($legacy) => $legacy->whereNull('lots_started_at')->where('on_hand', '>', 0)->where($dateFilter))
            ->orWhere(fn ($started) => $started->whereNotNull('lots_started_at')->whereHas('lots',
                fn ($lots) => $lots->where('state', 'open')->where('quantity_remaining', '>', 0)->where($dateFilter))));
    }
}
