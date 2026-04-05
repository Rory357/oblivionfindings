<?php

namespace App\Http\Controllers;

use App\Models\AnonymizationLog;
use App\Models\Client;
use App\Models\DataRetentionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DataDeletionLogController extends Controller
{
    /**
     * Display a listing of deletion logs.
     */
    public function index(Request $request): Response
    {
        $query = AnonymizationLog::query()
            ->with('anonymizedBy');

        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('reason', 'like', "%{$request->q}%")
                    ->orWhere('model_type', 'like', "%{$request->q}%");
            });
        }

        if ($request->filled('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        $query->orderByDesc('anonymized_at');

        $logs = $query->get()->map(function ($log) {
            return [
                'id' => $log->id,
                'model_type' => class_basename($log->model_type),
                'model_id' => $log->model_id,
                'reason' => $log->reason,
                'fields_anonymized' => $log->fields_anonymized,
                'deleted_at' => $log->anonymized_at?->toIso8601String(),
                'deleted_by_name' => $log->anonymizedBy?->name,
                'policy_name' => $log->reason,
            ];
        });

        return Inertia::render('privacy/deletion-logs', [
            'logs' => $logs,
            'filters' => $request->only(['q', 'model_type']),
        ]);
    }

    /**
     * Execute data deletion based on retention policies.
     */
    public function execute(Request $request): RedirectResponse
    {
        $request->validate([
            'policy_id' => 'required|exists:data_retention_policies,id',
            'confirm' => 'required|accepted',
        ]);

        $policy = DataRetentionPolicy::findOrFail($request->policy_id);

        if (! $policy->active) {
            return back()->with('error', 'This retention policy is not active.');
        }

        $modelClass = $policy->model_type;

        if (! class_exists($modelClass)) {
            return back()->with('error', 'The model class for this policy does not exist.');
        }

        $retentionYears = $policy->retention_period_years;

        if (! $retentionYears) {
            return back()->with('error', 'This policy has no retention period defined (indefinite retention).');
        }

        $cutoffDate = now()->subYears($retentionYears);

        // Build query for records past their retention period
        $query = $modelClass::query()
            ->where('created_at', '<', $cutoffDate);

        // Apply retention conditions if specified
        if ($policy->retention_conditions) {
            foreach ($policy->retention_conditions as $field => $value) {
                $query->where($field, $value);
            }
        }

        // Include soft-deleted records if policy applies to them
        if ($policy->applies_to_soft_deleted && method_exists($modelClass, 'withTrashed')) {
            $query->withTrashed();
        }

        // Exclude records under legal hold
        if ($policy->legal_hold_exemption) {
            $query->whereNotExists(function ($subQ) use ($modelClass) {
                $subQ->select(DB::raw(1))
                    ->from('legal_holds')
                    ->where('holdable_type', $modelClass)
                    ->whereColumn('holdable_id', (new $modelClass)->getTable() . '.id')
                    ->where('status', 'active');
            });
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            return back()->with('info', 'No records found matching the retention policy criteria.');
        }

        $deletedCount = 0;
        $anonymizedCount = 0;
        $usesSoftDeletes = method_exists($modelClass, 'trashed');

        DB::transaction(function () use ($records, $modelClass, $policy, $usesSoftDeletes, &$deletedCount, &$anonymizedCount) {
            foreach ($records as $record) {
                $fieldsAnonymized = [];
                $methods = [];

                if ($usesSoftDeletes && ! $record->trashed()) {
                    // Soft-delete the record
                    $record->delete();
                    $fieldsAnonymized[] = 'soft_deleted';
                    $methods['soft_delete'] = true;
                    $deletedCount++;
                }

                // Anonymize personal data fields regardless of soft-delete status
                $personalFields = $this->getPersonalDataFields($modelClass);
                $updatedFields = [];

                foreach ($personalFields as $field => $strategy) {
                    if (! isset($record->{$field}) || $record->{$field} === null) {
                        continue;
                    }

                    switch ($strategy) {
                        case 'redact':
                            $updatedFields[$field] = 'REDACTED';
                            $fieldsAnonymized[] = $field;
                            $methods[$field] = 'redacted';
                            break;
                        case 'clear':
                            $updatedFields[$field] = null;
                            $fieldsAnonymized[] = $field;
                            $methods[$field] = 'cleared';
                            break;
                    }
                }

                if (! empty($updatedFields)) {
                    $record->forceFill($updatedFields)->saveQuietly();
                    $anonymizedCount++;
                }

                // Log the anonymization action
                AnonymizationLog::create([
                    'model_type' => $modelClass,
                    'model_id' => $record->id,
                    'reason' => 'retention_period_expired - Policy: ' . $policy->policy_name,
                    'fields_anonymized' => $fieldsAnonymized,
                    'anonymization_methods' => $methods,
                    'anonymized_at' => now(),
                    'anonymized_by_user_id' => auth()->id(),
                    'reversible' => false,
                ]);
            }
        });

        // Update policy last-applied timestamp
        $policy->update([
            'last_applied_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        $summary = [];
        if ($deletedCount > 0) {
            $summary[] = "{$deletedCount} record(s) soft-deleted";
        }
        if ($anonymizedCount > 0) {
            $summary[] = "{$anonymizedCount} record(s) anonymized";
        }

        return back()->with('success', 'Data deletion executed successfully. ' . implode(', ', $summary) . '.');
    }

    /**
     * Get the personal data fields and their anonymization strategy for a given model.
     *
     * Returns an array of field => strategy where strategy is 'redact' or 'clear'.
     */
    private function getPersonalDataFields(string $modelClass): array
    {
        $map = [
            Client::class => [
                'first_name' => 'redact',
                'last_name' => 'redact',
                'preferred_name' => 'clear',
                'email' => 'clear',
                'phone' => 'clear',
                'nhi_number' => 'clear',
                'address_line_1' => 'clear',
                'address_line_2' => 'clear',
                'suburb' => 'clear',
                'city' => 'clear',
                'postcode' => 'clear',
                'life_story' => 'clear',
                'interests_hobbies' => 'clear',
                'strengths_abilities' => 'clear',
                'profile_photo_path' => 'clear',
            ],
            \App\Models\ClientNote::class => [
                'subject' => 'redact',
                'body' => 'redact',
            ],
            \App\Models\ClientDocument::class => [
                'title' => 'redact',
                'description' => 'clear',
            ],
        ];

        return $map[$modelClass] ?? [];
    }
}
