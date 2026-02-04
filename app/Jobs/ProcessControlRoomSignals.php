<?php

namespace App\Jobs;

use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessControlRoomSignals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $limit = 100)
    {
    }

    public function handle(): void
    {
        try {
            $processed = app(SignalProcessingService::class)->processAllPending($this->limit);
            Log::info('Control Room signals processed', ['count' => $processed]);
        } catch (\Throwable $e) {
            Log::error('Failed processing Control Room signals', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
