<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PruneDataRetentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $policies = DB::table('data_retention_policies')
            ->where('active', true)
            ->get();

        foreach ($policies as $policy) {
            $this->processPolicy($policy);
        }
    }

    protected function processPolicy($policy): void
    {
        if (!$policy->retention_period_years && !$policy->hard_delete_after_years) {
            return;
        }

        $modelClass = $policy->model_type;
        if (!class_exists($modelClass)) {
            Log::warning("Data retention policy references non-existent model: {$modelClass}");
            return;
        }

        // Calculate cutoff dates
        $softDeleteCutoff = $policy->retention_period_years 
            ? Carbon::now()->subYears($policy->retention_period_years) 
            : null;
            
        $hardDeleteCutoff = $policy->hard_delete_after_years 
            ? Carbon::now()->subYears($policy->hard_delete_after_years) 
            : null;

        // 1. Handle Soft Deletes (Retention Period)
        if ($softDeleteCutoff) {
            $recordsToSoftDelete = $modelClass::where('created_at', '<', $softDeleteCutoff)
                ->whereNull('deleted_at') // Assuming SoftDeletes trait
                ->get();

            foreach ($recordsToSoftDelete as $record) {
                if (!$this->isUnderLegalHold($record)) {
                    $record->delete(); // Soft delete
                    Log::info("Retention: Soft deleted {$modelClass} ID {$record->id}");
                }
            }
        }

        // 2. Handle Hard Deletes (Permanent Removal)
        if ($hardDeleteCutoff) {
            // Look at already soft-deleted records (withTrashed)
            $recordsToForceDelete = $modelClass::withTrashed()
                ->where('created_at', '<', $hardDeleteCutoff)
                ->get();

            foreach ($recordsToForceDelete as $record) {
                if (!$this->isUnderLegalHold($record)) {
                    $record->forceDelete();
                    Log::info("Retention: Force deleted {$modelClass} ID {$record->id}");
                }
            }
        }
    }

    protected function isUnderLegalHold($record): bool
    {
        return DB::table('legal_holds')
            ->where('holdable_type', get_class($record))
            ->where('holdable_id', $record->id)
            ->where('status', 'active')
            ->exists();
    }
}