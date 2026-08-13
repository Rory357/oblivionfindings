<?php

namespace App\Jobs;

use App\Domain\Privacy\Retention\RetentionExecutionService;
use App\Models\DataRetentionPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnforceDataRetentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(RetentionExecutionService $service): void
    {
        DataRetentionPolicy::query()
            ->where('active', true)
            ->where('execution_state', 'approved')
            ->orderBy('id')
            ->each(function (DataRetentionPolicy $policy) use ($service): void {
                try {
                    $service->execute($policy, 'scheduled');
                } catch (\Throwable $exception) {
                    Log::error('Governed data retention execution failed.', [
                        'policy_id' => $policy->id,
                        'failure_category' => class_basename($exception),
                    ]);
                }
            });
    }
}
