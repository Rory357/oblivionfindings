<?php

namespace App\Console\Commands;

use App\Models\ClientMedication;
use App\Models\MedicationDoseSlotBackfill;
use App\Services\Medication\DoseSlots\DoseSlotBackfill;
use App\Services\Medication\DoseSlots\DoseSlotCoverage;
use App\Services\Medication\DoseSlots\DoseSlotRules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * Rebuilds dose slots and outcomes for the months before live generation
 * began (P01 foundation C5), from the orders' history and the doses recorded.
 *
 * Idempotent: a slot is only ever added, an outcome only written where a
 * slot has none — a live outcome is never rewritten. Processed one order at
 * a time in batches of days, each batch its own transaction. The run keeps a
 * cursor, so an interrupted run resumes where it stopped (same period; pass
 * --fresh to start over). A completed run makes its period readable: reads
 * before it say "Not available before {date}".
 *
 * Runs on deploy only when Main decides. --dry-run counts and writes nothing.
 */
class BackfillMedicationDoseSlots extends Command
{
    protected $signature = 'emar:backfill-dose-slots
        {--months=12 : How many months to rebuild, back from the last day}
        {--to= : The last NZ day to rebuild (Y-m-d); defaults to the day before live dose slots began}
        {--dry-run : Count what would be written; write nothing}
        {--fresh : Start a new run instead of resuming an unfinished one for the same period}
        {--chunk-days=31 : NZ days per transaction}
        {--limit-orders= : Stop after this many orders; run again to resume}
        {--order=* : Only these order ids (a repair: no run is recorded)}';

    protected $description = 'Rebuild past medication dose slots and outcomes from order history and recorded doses';

    public function handle(DoseSlotBackfill $backfill, DoseSlotCoverage $coverage): int
    {
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $now = CarbonImmutable::now()->utc();
        $today = $now->setTimezone($timezone)->toDateString();

        $liveFrom = $coverage->liveFrom();
        $to = $this->option('to') !== null
            ? (string) $this->option('to')
            : CarbonImmutable::parse($liveFrom ?? $today, 'UTC')->subDay()->toDateString();
        $months = (int) $this->option('months');
        if (! DoseSlotRules::isCalendarDate($to) || $months < 1) {
            $this->error('--to is a Y-m-d day and --months at least 1.');

            return self::INVALID;
        }
        if ($to >= $today) {
            $this->error("The backfill rebuilds past days only: --to must be before today ({$today}).");

            return self::INVALID;
        }
        $from = CarbonImmutable::parse($to, 'UTC')->addDay()->subMonthsNoOverflow($months)->toDateString();
        $chunkDays = max(1, (int) $this->option('chunk-days'));
        $limit = $this->option('limit-orders') !== null ? max(1, (int) $this->option('limit-orders')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $only = collect((array) $this->option('order'))->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->values();

        $run = $dryRun || $only->isNotEmpty() ? null : $this->backfillRun($from, $to, $now);
        $cursor = (int) ($run?->last_order_id ?? 0);

        $this->info(($dryRun ? 'Dry run — nothing is written. ' : '')."Rebuilding dose slots for {$from} to {$to} (NZ).");
        if ($run !== null && $cursor > 0) {
            $this->info("Resuming run #{$run->id} after order {$cursor}.");
        }

        $totals = DoseSlotBackfill::noCounts() + ['orders' => 0, 'skipped' => 0];
        $stopped = false;
        $lastDayEndUtc = CarbonImmutable::parse($to, $timezone)->endOfDay()->utc()->format('Y-m-d H:i:s');

        ClientMedication::withTrashed()
            ->where('id', '>', $cursor)
            ->when($only->isNotEmpty(), fn ($query) => $query->whereIn('id', $only->all()))
            // An order entered after the period owes nothing in it.
            ->where(fn ($query) => $query->whereNull('created_at')->orWhere('created_at', '<=', $lastDayEndUtc))
            ->chunkById(100, function (Collection $orders) use ($backfill, $from, $to, $now, $dryRun, $chunkDays, $limit, $run, &$totals, &$stopped): bool {
                foreach ($orders as $order) {
                    if ($limit !== null && $totals['orders'] + $totals['skipped'] >= $limit) {
                        $stopped = true;

                        return false;
                    }

                    $skipped = false;
                    try {
                        $counts = $backfill->order(
                            $order,
                            $from,
                            $to,
                            $now,
                            $dryRun,
                            $chunkDays,
                            // In the batch's own transaction, so the run's counts match what was written.
                            $run === null ? null : fn (array $batch) => MedicationDoseSlotBackfill::query()->whereKey($run->id)->incrementEach([
                                'slots_created' => $batch['slots'],
                                'outcomes_written' => $batch['outcomes'],
                                'records_without_slot' => $batch['without_slot'],
                                'days_from_records' => $batch['days_from_records'],
                            ]),
                        );
                        foreach ($counts as $key => $value) {
                            $totals[$key] += $value;
                        }
                        $totals['orders']++;
                    } catch (InvalidArgumentException|LogicException $unreadable) {
                        // One unreadable order history must not stop the others.
                        report($unreadable);
                        $this->warn("Order {$order->id} skipped: its history could not be read.");
                        $totals['skipped']++;
                        $skipped = true;
                    }

                    if ($run !== null) {
                        MedicationDoseSlotBackfill::query()->whereKey($run->id)->update(['last_order_id' => $order->id]);
                        MedicationDoseSlotBackfill::query()->whereKey($run->id)->increment($skipped ? 'orders_skipped' : 'orders_done');
                    }
                }

                return true;
            });

        if ($run !== null && ! $stopped) {
            $run->forceFill(['status' => MedicationDoseSlotBackfill::STATUS_COMPLETED, 'finished_at' => CarbonImmutable::now()->utc()])->save();
        }

        $this->table(['', $dryRun ? 'Would write' : 'Written'], [
            ['Orders processed', $totals['orders']],
            ['Orders skipped (unreadable history)', $totals['skipped']],
            ['Dose slots created', $totals['slots']],
            ['Outcomes written', $totals['outcomes']],
            ['Recorded doses without a slot', $totals['without_slot']],
            ['Days rebuilt from records', $totals['days_from_records']],
        ]);

        if ($run !== null) {
            $run->refresh();
            $this->info($stopped
                ? "Run #{$run->id} stopped after {$limit} orders; run again to resume."
                : "Run #{$run->id} completed. Dose slots are available from {$coverage->availableFrom($now)}.");
        }

        return self::SUCCESS;
    }

    /**
     * The unfinished run for this period, to resume, or a new one.
     */
    private function backfillRun(string $from, string $to, CarbonImmutable $now): MedicationDoseSlotBackfill
    {
        if (! $this->option('fresh')) {
            $unfinished = MedicationDoseSlotBackfill::query()
                ->where('status', MedicationDoseSlotBackfill::STATUS_RUNNING)
                ->whereDate('from_date', $from)
                ->whereDate('to_date', $to)
                ->latest('id')
                ->first();
            if ($unfinished !== null) {
                return $unfinished;
            }
        }

        return MedicationDoseSlotBackfill::query()->create([
            'from_date' => $from,
            'to_date' => $to,
            'status' => MedicationDoseSlotBackfill::STATUS_RUNNING,
            'started_at' => $now,
        ]);
    }
}
