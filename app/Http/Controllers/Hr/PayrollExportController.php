<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Finance\Jobs\PostPayrollJournalJob;
use App\Domain\Finance\Services\ExternalSettlementService;
use App\Domain\Hr\Models\HrPayrollExportProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Services\HrPayrollAccessService;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\PayrollExportService;
use App\Domain\Hr\Services\PayslipService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PayrollExportController extends Controller
{
    public function __construct(
        protected PayrollExportService $payrollService,
        protected HrWebhookService $webhookService,
        protected ExternalSettlementService $externalSettlements,
        protected PayslipService $payslipService,
        private readonly HrPayrollAccessService $payrollAccess,
    ) {}

    /**
     * List payroll runs.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.view'), 403);

        $visibleRuns = $this->payrollAccess->visibleRunsQuery($user);

        $runs = (clone $visibleRuns)
            ->with(['lockedBy:id,name', 'exportedBy:id,name', 'exportProfile:id,name,provider_key', 'externalSettlement'])
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('period_end')
            ->paginate(20)
            ->withQueryString();

        $runs->through(fn ($run) => [
            'id' => $run->id,
            'period_start' => optional($run->period_start)->toDateString(),
            'period_end' => optional($run->period_end)->toDateString(),
            'status' => $run->status,
            'total_hours' => (float) $run->total_hours,
            'total_gross' => (float) $run->total_gross,
            'items_count' => (int) $run->items_count,
            'created_at' => optional($run->created_at)->toDateString(),
            'locked_at' => optional($run->locked_at)->toDateTimeString(),
            'exported_at' => optional($run->exported_at)->toDateTimeString(),
            'gl_posted_at' => optional($run->gl_posted_at)->toDateTimeString(),
            'gl_error' => $run->gl_error,
            'net_paid_at' => optional($run->net_paid_at)->toDateTimeString(),
            'net_settlement' => $run->externalSettlement ? [
                'status' => $run->externalSettlement->status,
                'artifact_sha256' => $run->externalSettlement->artifact_sha256,
                'exported_at' => optional($run->externalSettlement->exported_at)->toDateTimeString(),
                'accepted_at' => optional($run->externalSettlement->accepted_at)->toDateTimeString(),
                'acceptance_reference' => $run->externalSettlement->acceptance_reference,
                'rejection_reason' => $run->externalSettlement->rejection_reason,
            ] : null,
            'export_profile' => $run->exportProfile ? [
                'id' => $run->exportProfile->id,
                'name' => $run->exportProfile->name,
                'provider_key' => $run->exportProfile->provider_key,
            ] : null,
            'validation_errors' => $run->validation_errors ?? [],
        ]);

        $profiles = HrPayrollExportProfile::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (HrPayrollExportProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->name,
                'provider_key' => $profile->provider_key,
                'description' => $profile->description,
                'delimiter' => $profile->delimiter,
                'enclosure' => $profile->enclosure,
                'line_ending' => $profile->line_ending,
                'include_headers' => (bool) $profile->include_headers,
                'is_default' => (bool) $profile->is_default,
                'mappings' => $profile->mappings ?? [],
            ])
            ->values();

        $exportFieldOptions = collect($this->payrollService->exportFieldCatalog())
            ->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
            ])
            ->values();

        // Server-side counts use the same canonical access query as the list so
        // the hero tiles stay true past page 1 without leaking hidden Sites.
        $statusCounts = (clone $visibleRuns)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('hr/payroll/index', [
            'runs' => $runs,
            'profiles' => $profiles,
            'exportFieldOptions' => $exportFieldOptions,
            'statusCounts' => [
                'total' => (int) $statusCounts->sum(),
                'draft' => (int) ($statusCounts['draft'] ?? 0),
                'locked' => (int) ($statusCounts['locked'] ?? 0),
                'exported' => (int) ($statusCounts['exported'] ?? 0),
            ],
            'filters' => [
                'status' => $request->query('status'),
            ],
            'can' => [
                'manage' => $this->payrollAccess->canManageApplicationPayroll($user),
                'export_data' => $this->payrollAccess->canManageApplicationPayroll($user),
            ],
        ]);
    }

    /**
     * Create a new payroll run for a pay period.
     */
    public function createRun(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $run = $this->payrollService->createRun(
                Carbon::parse($data['period_start']),
                Carbon::parse($data['period_end']),
                $user->id,
                $data['idempotency_key'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['period' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Payroll run created.');
    }

    /**
     * Lock a payroll run to prevent further edits.
     */
    public function lockRun(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $run = $this->payrollAccess->payrollRun($user, $run);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        // Locking creates or verifies the exact run-backed payslip set; capture
        // whether it already existed so employees are notified only once.
        $payslipsExistedBeforeLock = $run->payslips()->exists();

        try {
            $run = $this->payrollService->lockRun($run, $user->id);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['lock' => $e->getMessage()]);
        }

        if ($run->journal_id === null) {
            PostPayrollJournalJob::dispatch($run);
        }

        if (! $payslipsExistedBeforeLock) {
            $this->payslipService->notifyEmployeesPayslipAvailable(
                $run->payslips()->with('user')->get()
            );
        }

        $this->webhookService->publishApplicationEvent('payroll.run.locked', [
            'payroll_run_id' => $run->id,
            'period_start' => optional($run->period_start)->toDateString(),
            'period_end' => optional($run->period_end)->toDateString(),
            'locked_by' => $user->id,
            'status' => 'locked',
        ]);

        return redirect()->back()->with('success', 'Payroll run locked.');
    }

    /**
     * Retry the GL journal post for a locked run whose posting failed
     * (surfaced via hr_payroll_runs.gl_error). Runs the job synchronously so
     * the outcome is deterministic in every environment (a queued dispatch
     * would return "posted" before the job ran on a real queue connection).
     */
    public function retryGlPost(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $run = $this->payrollAccess->payrollRun($user, $run);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        if ($run->locked_at === null) {
            return redirect()->back()->withErrors(['gl' => 'Lock the run before posting its journal.']);
        }

        if ($run->journal_id !== null) {
            return redirect()->back()->withErrors(['gl' => 'This run already has a posted journal.']);
        }

        try {
            PostPayrollJournalJob::dispatchSync($run);
        } catch (\Throwable $e) {
            return redirect()->back()->withErrors(['gl' => "GL post failed again: {$e->getMessage()}"]);
        }

        return redirect()->back()->with('success', 'Payroll journal posted.');
    }

    public function prepareNetPay(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $run = $this->payrollAccess->payrollRun($user, $run);

        try {
            $this->externalSettlements->preparePayrollNetPay($run, $user);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return redirect()->back()->withErrors(['net_pay' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Net-pay bank file prepared. Download and submit it before recording acceptance.');
    }

    /**
     * Record immutable bank acceptance, then settle the accepted instruction.
     * If posting fails the accepted evidence remains retryable with the same key.
     */
    public function payNet(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $run = $this->payrollAccess->payrollRun($user, $run);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        $alreadySettled = $run->net_paid_at !== null;

        try {
            $settlement = $this->externalSettlements->requiredSettlement(
                $run,
                ExternalSettlementService::PAYROLL_NET_PAY,
                $user,
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()->withErrors(['net_pay' => $exception->getMessage()]);
        }
        $needsAcceptance = $settlement->status === 'exported';
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'acceptance_reference' => [$needsAcceptance ? 'required' : 'nullable', 'string', 'max:255'],
            'acceptance_evidence' => [$needsAcceptance ? 'required' : 'nullable', 'array', $needsAcceptance ? 'min:1' : 'min:0'],
        ]);

        try {
            if ($needsAcceptance) {
                $this->externalSettlements->accept(
                    $run,
                    ExternalSettlementService::PAYROLL_NET_PAY,
                    $user,
                    $validated['idempotency_key'].':accept',
                    $validated['acceptance_reference'],
                    $validated['acceptance_evidence'],
                );
            }
            $journal = $this->externalSettlements->settlePayrollNetPay(
                $run,
                $user,
                $validated['idempotency_key'].':settle',
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if (! $alreadySettled) {
            $this->webhookService->publishApplicationEvent('payroll.run.paid', [
                'payroll_run_id' => $run->id,
                'payment_journal_id' => $journal->id,
                'paid_by' => $user->id,
                'status' => 'paid',
            ]);
        }

        return redirect()->back()->with('success', 'Bank-accepted net pay settled and payslips marked paid.');
    }

    /**
     * Stream the hash-verified canonical net-pay file. The first successful
     * download advances prepared to exported; later evidence replays do not.
     */
    public function downloadNetPayFile(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $run = $this->payrollAccess->payrollRun($user, $run);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        try {
            $artifact = $this->externalSettlements->exportArtifact(
                $run,
                ExternalSettlementService::PAYROLL_NET_PAY,
                $user,
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()->withErrors(['net_pay' => $exception->getMessage()]);
        }

        return response()->streamDownload(
            static function () use ($artifact): void {
                echo $artifact['contents'];
            },
            "net-pay-run-{$run->id}.csv",
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function rejectNetPay(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $run = $this->payrollAccess->payrollRun($user, $run);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'reference' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['required', 'array', 'min:1'],
        ]);

        try {
            $this->externalSettlements->reject(
                $run,
                ExternalSettlementService::PAYROLL_NET_PAY,
                $user,
                $validated['idempotency_key'],
                $validated['reference'],
                $validated['reason'],
                $validated['evidence'],
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()->withErrors(['net_pay' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Payroll bank rejection recorded. No net-pay journal was posted.');
    }

    public function reconcileNetPay(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $run = $this->payrollAccess->payrollRun($user, $run);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'reference' => ['required', 'string', 'max:255'],
            'evidence' => ['required', 'array', 'min:1'],
            'bank_transaction_id' => ['required', 'integer'],
        ]);

        try {
            $this->externalSettlements->reconcile(
                $run,
                ExternalSettlementService::PAYROLL_NET_PAY,
                (int) $validated['bank_transaction_id'],
                $user,
                $validated['idempotency_key'],
                $validated['reference'],
                $validated['evidence'],
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()->withErrors(['net_pay' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Payroll settlement reconciled to the cleared bank transaction.');
    }

    /**
     * Export a locked payroll run as CSV.
     */
    public function export(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $run = $this->payrollAccess->payrollRun($user, $run);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        $validated = $request->validate([
            'profile_id' => ['nullable', 'integer', Rule::exists('hr_payroll_export_profiles', 'id')],
        ]);

        $profile = null;
        if (! empty($validated['profile_id'])) {
            $profile = HrPayrollExportProfile::query()->findOrFail((int) $validated['profile_id']);
        }

        try {
            $path = $this->payrollService->generateExport($run, $user->id, $profile);
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['export' => $e->getMessage()]);
        }

        $this->webhookService->publishApplicationEvent('payroll.run.exported', [
            'payroll_run_id' => $run->id,
            'period_start' => optional($run->period_start)->toDateString(),
            'period_end' => optional($run->period_end)->toDateString(),
            'exported_by' => $user->id,
            'export_profile_id' => $profile?->id,
            'export_format' => 'csv',
        ]);

        return Storage::disk('private')->download($path, basename($path), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeProfile(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        $fieldKeys = array_keys($this->payrollService->exportFieldCatalog());
        $sourceRule = Rule::in(array_merge($fieldKeys, ['static']));
        $nameRule = Rule::unique('hr_payroll_export_profiles', 'name');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', $nameRule],
            'provider_key' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'delimiter' => ['nullable', 'string', 'max:4'],
            'enclosure' => ['nullable', 'string', 'max:4'],
            'line_ending' => ['nullable', 'string', 'max:8'],
            'include_headers' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*.header' => ['required', 'string', 'max:120'],
            'mappings.*.source' => ['required', 'string', $sourceRule],
            'mappings.*.value' => ['nullable'],
        ]);

        $normalizedMappings = $this->normalizeProfileMappings($validated['mappings'], $fieldKeys);
        if ($normalizedMappings === []) {
            return redirect()->back()->withErrors([
                'mappings' => 'At least one valid export mapping is required.',
            ]);
        }

        try {
            DB::transaction(function () use ($validated, $normalizedMappings, $user) {
                $demotedProfileIds = [];
                if (! empty($validated['is_default'])) {
                    $demotedProfileIds = HrPayrollExportProfile::query()
                        ->where('is_default', true)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    HrPayrollExportProfile::query()
                        ->update(['is_default' => false]);
                }

                $profile = HrPayrollExportProfile::query()->create([
                    'name' => $validated['name'],
                    'provider_key' => $validated['provider_key'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'delimiter' => $validated['delimiter'] ?? ',',
                    'enclosure' => $validated['enclosure'] ?? '"',
                    'line_ending' => $validated['line_ending'] ?? "\n",
                    'include_headers' => (bool) ($validated['include_headers'] ?? true),
                    'is_default' => (bool) ($validated['is_default'] ?? false),
                    'mappings' => $normalizedMappings,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $this->auditDefaultProfileChange($profile, $demotedProfileIds, $user);
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()->withErrors([
                'name' => 'That profile name or default selection is already in use. Refresh and try again.',
            ]);
        }

        return redirect()->back()->with('success', 'Payroll export profile created.');
    }

    public function updateProfile(Request $request, HrPayrollExportProfile $profile)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        $fieldKeys = array_keys($this->payrollService->exportFieldCatalog());
        $sourceRule = Rule::in(array_merge($fieldKeys, ['static']));
        $nameRule = Rule::unique('hr_payroll_export_profiles', 'name')
            ->ignore($profile->id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150', $nameRule],
            'provider_key' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'delimiter' => ['nullable', 'string', 'max:4'],
            'enclosure' => ['nullable', 'string', 'max:4'],
            'line_ending' => ['nullable', 'string', 'max:8'],
            'include_headers' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'mappings' => ['sometimes', 'array', 'min:1'],
            'mappings.*.header' => ['required_with:mappings', 'string', 'max:120'],
            'mappings.*.source' => ['required_with:mappings', 'string', $sourceRule],
            'mappings.*.value' => ['nullable'],
        ]);

        $updatePayload = [
            'updated_by' => $user->id,
        ];

        foreach (['name', 'provider_key', 'description', 'delimiter', 'enclosure', 'line_ending', 'include_headers', 'is_default'] as $column) {
            if (array_key_exists($column, $validated)) {
                $updatePayload[$column] = $validated[$column];
            }
        }

        if (array_key_exists('mappings', $validated)) {
            $normalizedMappings = $this->normalizeProfileMappings($validated['mappings'], $fieldKeys);
            if ($normalizedMappings === []) {
                return redirect()->back()->withErrors([
                    'mappings' => 'At least one valid export mapping is required.',
                ]);
            }
            $updatePayload['mappings'] = $normalizedMappings;
        }

        try {
            DB::transaction(function () use ($updatePayload, $profile, $user) {
                $demotedProfileIds = [];
                if (! empty($updatePayload['is_default'])) {
                    $demotedProfileIds = HrPayrollExportProfile::query()
                        ->where('id', '!=', $profile->id)
                        ->where('is_default', true)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    HrPayrollExportProfile::query()
                        ->where('id', '!=', $profile->id)
                        ->update(['is_default' => false]);
                }

                $profile->update($updatePayload);
                $this->auditDefaultProfileChange($profile, $demotedProfileIds, $user);
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()->withErrors([
                'name' => 'That profile name or default selection is already in use. Refresh and try again.',
            ]);
        }

        return redirect()->back()->with('success', 'Payroll export profile updated.');
    }

    public function setDefaultProfile(Request $request, HrPayrollExportProfile $profile)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->payrollAccess->assertCanManageApplicationPayroll($user);

        try {
            DB::transaction(function () use ($profile, $user) {
                $demotedProfileIds = HrPayrollExportProfile::query()
                    ->where('id', '!=', $profile->id)
                    ->where('is_default', true)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                HrPayrollExportProfile::query()
                    ->update(['is_default' => false]);

                $profile->update([
                    'is_default' => true,
                    'updated_by' => $user->id,
                ]);

                $this->auditDefaultProfileChange($profile, $demotedProfileIds, $user);
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()->withErrors([
                'default' => 'The default profile changed at the same time. Refresh and try again.',
            ]);
        }

        return redirect()->back()->with('success', 'Default payroll export profile updated.');
    }

    /**
     * @param  array<int, int>  $demotedProfileIds
     */
    private function auditDefaultProfileChange(
        HrPayrollExportProfile $profile,
        array $demotedProfileIds,
        User $actor,
    ): void {
        if ($demotedProfileIds === []) {
            return;
        }

        AuditLogger::log('hr.payroll_export_profile.default_changed', $profile, [
            'actor_id' => $actor->id,
            'promoted_profile_id' => $profile->id,
            'demoted_profile_ids' => array_values($demotedProfileIds),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mappings
     * @param  array<int, string>  $fieldKeys
     * @return array<int, array{header: string, source: string, value?: mixed}>
     */
    protected function normalizeProfileMappings(array $mappings, array $fieldKeys): array
    {
        return collect($mappings)
            ->filter(fn ($mapping) => is_array($mapping))
            ->map(function (array $mapping) use ($fieldKeys) {
                $header = trim((string) ($mapping['header'] ?? ''));
                $source = trim((string) ($mapping['source'] ?? ''));

                if ($header === '' || $source === '') {
                    return null;
                }

                if ($source !== 'static' && ! in_array($source, $fieldKeys, true)) {
                    return null;
                }

                $row = [
                    'header' => $header,
                    'source' => $source,
                ];

                if ($source === 'static') {
                    $row['value'] = $mapping['value'] ?? '';
                }

                return $row;
            })
            ->filter()
            ->values()
            ->all();
    }
}
