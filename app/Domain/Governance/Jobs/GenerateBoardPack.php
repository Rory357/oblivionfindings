<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Services\BoardPackBuilderService;
use App\Domain\Governance\Services\DashboardAggregatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateBoardPack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    public function __construct(
        public int $meetingId
    ) {}

    public function handle(
        BoardPackBuilderService $builder,
        DashboardAggregatorService $aggregator
    ): void {
        $meeting = GovernanceMeeting::findOrFail($this->meetingId);
        
        // Capture dashboard snapshot
        $snapshot = $aggregator->captureSnapshot('month');
        
        // Build the pack
        $pack = $builder->build($meeting, $snapshot);
        
        // Log success
        \Log::info('Board pack generated', [
            'pack_id' => $pack->id,
            'meeting_id' => $meeting->id,
            'file_size' => $pack->file_size,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('Board pack generation failed', [
            'meeting_id' => $this->meetingId,
            'error' => $exception->getMessage(),
        ]);
    }
}
