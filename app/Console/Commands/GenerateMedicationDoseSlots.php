<?php

namespace App\Console\Commands;

use App\Models\ClientMedication;
use App\Services\Medication\DoseSlots\DoseSlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Keeps every order's dose slots generated for today and the next two New
 * Zealand days (P01 foundation C3). Idempotent: order changes regenerate
 * their own slots as they happen; this run only extends the horizon as the
 * days roll over and repairs anything missed.
 */
class GenerateMedicationDoseSlots extends Command
{
    protected $signature = 'emar:generate-dose-slots';

    protected $description = 'Generate scheduled medication dose slots for today and the next two NZ days';

    public function handle(DoseSlotGenerator $generator): int
    {
        $now = CarbonImmutable::now()->utc();
        $todayStartUtc = $now->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))
            ->startOfDay()
            ->utc()
            ->format('Y-m-d H:i:s');
        $orders = 0;

        ClientMedication::query()
            ->whereNull('superseded_by')
            ->where(fn ($query) => $query->whereNull('ceased_at')->orWhere('ceased_at', '>=', $todayStartUtc))
            ->chunkById(200, function ($chunk) use ($generator, $now, &$orders): void {
                foreach ($chunk as $order) {
                    try {
                        // Retried on deadlock (1213): MySQL rolls the whole attempt back.
                        DB::transaction(fn () => $generator->generateAhead($order, $now), 3);
                        $orders++;
                    } catch (InvalidArgumentException|LogicException $unexpectedData) {
                        // One unreadable order must not stop the others.
                        report($unexpectedData);
                    }
                }
            });

        $this->info("Dose slots generated ahead for {$orders} orders.");

        return self::SUCCESS;
    }
}
