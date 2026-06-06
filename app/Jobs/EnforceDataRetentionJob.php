<?php

namespace App\Jobs;

use App\Domain\Finance\Jobs\PruneFinanceAuditExportsJob;
use App\Models\DataRetentionPolicy;
use App\Models\LegalHold;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnforceDataRetentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        app(PruneFinanceAuditExportsJob::class)->handle();

        $policies = DataRetentionPolicy::where('active', true)->get();

        foreach ($policies as $policy) {
            try {
                $this->enforcePolicy($policy);
            } catch (\Throwable $e) {
                Log::error("Data retention enforcement failed for policy {$policy->id}: ".$e->getMessage(), [
                    'policy_id' => $policy->id,
                    'model_type' => $policy->model_type,
                    'exception' => $e,
                ]);
            }
        }
    }

    private function enforcePolicy(DataRetentionPolicy $policy): void
    {
        $modelClass = $policy->model_type;

        if (! class_exists($modelClass)) {
            Log::warning("Data retention policy {$policy->id} references non-existent model: {$modelClass}");

            return;
        }

        $model = new $modelClass;
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass));
        $createdAtColumn = $model->getTable().'.'.$model->getCreatedAtColumn();

        // --- Phase 1: Soft-delete records past retention_period_years ---
        if ($policy->retention_period_years && $usesSoftDeletes) {
            $cutoff = now()->subYears($policy->retention_period_years);

            $query = $modelClass::where($createdAtColumn, '<', $cutoff)
                ->whereNull($model->getTable().'.deleted_at');

            $this->applyExemptions($query, $policy, $modelClass);

            $recordsToSoftDelete = $query->get();

            foreach ($recordsToSoftDelete as $record) {
                DB::transaction(function () use ($record, $policy) {
                    $record->delete(); // soft-delete

                    AuditLogger::log('data_retention.soft_deleted', $record, [
                        'policy_id' => $policy->id,
                        'policy_name' => $policy->policy_name,
                        'retention_period_years' => $policy->retention_period_years,
                    ]);
                });
            }

            if ($recordsToSoftDelete->isNotEmpty()) {
                Log::info("Data retention: soft-deleted {$recordsToSoftDelete->count()} {$modelClass} records for policy {$policy->id}");
            }
        }

        // --- Phase 2: Anonymize/hard-delete records past hard_delete_after_years ---
        if ($policy->hard_delete_after_years) {
            $hardCutoff = now()->subYears($policy->hard_delete_after_years);

            $query = $modelClass::query();

            // Include soft-deleted records for hard deletion
            if ($usesSoftDeletes) {
                $query->withTrashed();
            }

            $query->where($createdAtColumn, '<', $hardCutoff);

            $this->applyExemptions($query, $policy, $modelClass);

            $recordsToAnonymize = $query->get();

            foreach ($recordsToAnonymize as $record) {
                DB::transaction(function () use ($record, $policy) {
                    $this->anonymizeRecord($record);

                    AuditLogger::log('data_retention.anonymized', $record, [
                        'policy_id' => $policy->id,
                        'policy_name' => $policy->policy_name,
                        'hard_delete_after_years' => $policy->hard_delete_after_years,
                    ]);
                });
            }

            if ($recordsToAnonymize->isNotEmpty()) {
                Log::info("Data retention: anonymized {$recordsToAnonymize->count()} {$modelClass} records for policy {$policy->id}");
            }
        }

        // --- Phase 3: Archive records past archive_after_years (soft-delete if not already) ---
        if ($policy->archive_after_years && $usesSoftDeletes) {
            $archiveCutoff = now()->subYears($policy->archive_after_years);

            $query = $modelClass::where($createdAtColumn, '<', $archiveCutoff)
                ->whereNull($model->getTable().'.deleted_at');

            $this->applyExemptions($query, $policy, $modelClass);

            $recordsToArchive = $query->get();

            foreach ($recordsToArchive as $record) {
                DB::transaction(function () use ($record, $policy) {
                    $record->delete(); // soft-delete as archive

                    AuditLogger::log('data_retention.archived', $record, [
                        'policy_id' => $policy->id,
                        'policy_name' => $policy->policy_name,
                        'archive_after_years' => $policy->archive_after_years,
                    ]);
                });
            }

            if ($recordsToArchive->isNotEmpty()) {
                Log::info("Data retention: archived {$recordsToArchive->count()} {$modelClass} records for policy {$policy->id}");
            }
        }

        // Update last_applied_at timestamp
        $policy->update(['last_applied_at' => now()]);
    }

    /**
     * Apply legal hold and active case exemptions to the query.
     */
    private function applyExemptions($query, DataRetentionPolicy $policy, string $modelClass): void
    {
        $model = new $modelClass;

        // Exclude records with active legal holds
        if ($policy->legal_hold_exemption) {
            if (method_exists($model, 'legalHolds')) {
                $query->whereDoesntHave('legalHolds', function ($q) {
                    $q->where('status', 'active');
                });
            } else {
                // Use a morph-based subquery against the legal_holds table
                $heldIds = LegalHold::where('holdable_type', $modelClass)
                    ->where('status', 'active')
                    ->pluck('holdable_id');

                if ($heldIds->isNotEmpty()) {
                    $query->whereNotIn($model->getTable().'.'.$model->getKeyName(), $heldIds);
                }
            }
        }

        // Exclude records with active case exemptions (e.g., records tied to open client cases)
        if ($policy->active_case_exemption) {
            if (method_exists($model, 'client')) {
                $query->whereDoesntHave('client', function ($q) {
                    $q->where('status', 'active');
                });
            }
        }
    }

    /**
     * Anonymize a record by clearing personal/sensitive fields.
     */
    private function anonymizeRecord($record): void
    {
        $fillable = $record->getFillable();
        $anonymizedData = [];

        // Fields that are commonly personal and should be anonymized
        $personalFieldPatterns = [
            'name', 'email', 'phone', 'address', 'nhi', 'dob', 'date_of_birth',
            'first_name', 'last_name', 'middle_name', 'preferred_name',
            'mobile', 'home_phone', 'work_phone', 'emergency_contact',
            'next_of_kin', 'notes', 'description', 'comment', 'body',
            'ip_address', 'user_agent', 'signed_document_path',
        ];

        foreach ($fillable as $field) {
            foreach ($personalFieldPatterns as $pattern) {
                if (str_contains(strtolower($field), $pattern)) {
                    $anonymizedData[$field] = str_contains(strtolower($field), 'nhi') || str_contains($pattern, 'date') || str_contains($pattern, 'dob')
                        ? null
                        : '[REDACTED]';
                    break;
                }
            }
        }

        if (! empty($anonymizedData)) {
            $record->forceFill($anonymizedData);
            $record->save();
        }
    }
}
