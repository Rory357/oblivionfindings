<?php

namespace App\Http\Controllers;

use App\Domain\Privacy\Retention\RetentionContractException;
use App\Domain\Privacy\Retention\RetentionExecutionService;
use App\Models\AnonymizationLog;
use App\Models\DataRetentionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataDeletionLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request);

        $query = AnonymizationLog::query()->with('anonymizedBy');

        if ($request->filled('q')) {
            $query->where(function ($q) use ($request): void {
                $q->where('reason', 'like', "%{$request->q}%")
                    ->orWhere('model_type', 'like', "%{$request->q}%");
            });
        }

        if ($request->filled('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        $logs = $query->orderByDesc('anonymized_at')->get()->map(fn ($log): array => [
            'id' => $log->id,
            'model_type' => class_basename($log->model_type),
            'model_id' => $log->model_id,
            'reason' => $log->reason,
            'fields_anonymized' => $log->fields_anonymized,
            'deleted_at' => $log->anonymized_at?->toIso8601String(),
            'deleted_by_name' => $log->anonymizedBy?->name,
            'policy_name' => $log->reason,
        ]);

        return Inertia::render('privacy/deletion-logs', [
            'logs' => $logs,
            'filters' => $request->only(['q', 'model_type']),
        ]);
    }

    public function execute(Request $request, RetentionExecutionService $service): RedirectResponse
    {
        $this->authorizePermission($request);
        $validated = $request->validate([
            'policy_id' => ['required', 'integer', 'exists:data_retention_policies,id'],
            'confirm' => ['required', 'accepted'],
        ]);

        $policy = DataRetentionPolicy::query()->findOrFail($validated['policy_id']);

        try {
            $outcome = $service->execute($policy, 'manual', $request->user());
        } catch (RetentionContractException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($outcome['status'] === 'already_running') {
            return back()->with('info', 'This approved retention run is already in progress.');
        }

        if ($outcome['status'] === 'already_completed') {
            return back()->with('info', 'This approved retention run has already completed.');
        }

        if (in_array($outcome['status'], ['blocked', 'failed'], true)) {
            return back()->with(
                'error',
                $outcome['failure_message'] ?? 'This retention run did not complete. Review the governed execution log before retrying.',
            );
        }

        $result = $outcome['result'];
        $processed = (int) ($result['anonymized'] ?? 0)
            + (int) ($result['soft_deleted'] ?? 0)
            + (int) ($result['archived'] ?? 0);

        return back()->with(
            $processed > 0 ? 'success' : 'info',
            $processed > 0
                ? "Retention execution completed with {$processed} governed outcome(s)."
                : 'No eligible records remained after legal holds and exemptions were applied.',
        );
    }

    private function authorizePermission(Request $request): void
    {
        abort_unless($request->user()?->canDo('privacy.manageRetention'), 403);
    }
}
