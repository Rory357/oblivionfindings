<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Services\GstReturnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CalculateGstReturnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $orgId = null,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public string $frequency = 'two-monthly',
        public string $basis = 'invoice',
    ) {}

    public function handle(GstReturnService $service): void
    {
        $orgIds = $this->orgId !== null
            ? collect([$this->orgId])
            : FinAccount::query()
                ->distinct()
                ->pluck('organization_id')
                ->filter();

        [$periodStart, $periodEnd] = $this->resolvePeriod();

        foreach ($orgIds as $orgId) {
            $this->prepareForOrganization($service, (int) $orgId, $periodStart, $periodEnd);
        }
    }

    private function prepareForOrganization(
        GstReturnService $service,
        int $orgId,
        string $periodStart,
        string $periodEnd,
    ): void {
        $exists = FinGstReturn::query()
            ->where('organization_id', $orgId)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->where('filing_frequency', $this->frequency)
            ->where('basis', $this->basis)
            ->exists();

        if ($exists) {
            Log::info("GST return already exists for organisation #{$orgId}, period {$periodStart} to {$periodEnd}.");

            return;
        }

        $gstReturn = $service->prepareReturn($orgId, [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'filing_frequency' => $this->frequency,
            'basis' => $this->basis,
        ]);

        Log::info("GST return prepared for organisation #{$orgId}, period {$periodStart} to {$periodEnd}.", [
            'gst_return_id' => $gstReturn->id,
            'gst_payable' => $gstReturn->gst_payable,
        ]);
    }

    /**
     * Scheduled runs prepare the previous two complete calendar months.
     *
     * @return array{0: string, 1: string}
     */
    private function resolvePeriod(): array
    {
        if ($this->periodStart && $this->periodEnd) {
            return [
                Carbon::parse($this->periodStart)->toDateString(),
                Carbon::parse($this->periodEnd)->toDateString(),
            ];
        }

        $periodEnd = now()->subMonthNoOverflow()->endOfMonth();
        $periodStart = $periodEnd->copy()->subMonthNoOverflow()->startOfMonth();

        return [
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
        ];
    }
}
