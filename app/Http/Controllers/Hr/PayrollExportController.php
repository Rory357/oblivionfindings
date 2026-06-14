<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Finance\Jobs\PostPayrollJournalJob;
use App\Domain\Finance\Services\PayrollJournalService;
use App\Domain\Hr\Models\HrPayrollExportProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\PayrollExportService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PayrollExportController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        protected PayrollExportService $payrollService,
        protected HrWebhookService $webhookService,
        protected PayrollJournalService $payrollJournalService,
    ) {}

    /**
     * List payroll runs.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $runs = HrPayrollRun::query()
            ->with(['lockedBy:id,name', 'exportedBy:id,name', 'exportProfile:id,name,provider_key'])
            ->withCount('items')
            ->where('tenant_id', $tenantId)
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
            'net_paid_at' => optional($run->net_paid_at)->toDateTimeString(),
            'export_profile' => $run->exportProfile ? [
                'id' => $run->exportProfile->id,
                'name' => $run->exportProfile->name,
                'provider_key' => $run->exportProfile->provider_key,
            ] : null,
            'validation_errors' => $run->validation_errors ?? [],
        ]);

        $profiles = HrPayrollExportProfile::query()
            ->forTenant($tenantId)
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

        return Inertia::render('hr/payroll/index', [
            'runs' => $runs,
            'profiles' => $profiles,
            'exportFieldOptions' => $exportFieldOptions,
            'filters' => [
                'status' => $request->query('status'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.payroll.export'),
                'export_data' => $user->canDo('hr.payroll.export'),
            ],
        ]);
    }

    /**
     * Create a new payroll run for a pay period.
     */
    public function createRun(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $run = $this->payrollService->createRun(
                $tenantId,
                Carbon::parse($data['period_start']),
                Carbon::parse($data['period_end']),
                $user->id,
            );
            if (! empty($data['notes'])) {
                $run->update(['notes' => $data['notes']]);
            }
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
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        if ($run->tenant_id !== $tenantId) {
            abort(404);
        }

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

        $this->webhookService->publish($run->tenant_id, 'payroll.run.locked', [
            'payroll_run_id' => $run->id,
            'period_start' => optional($run->period_start)->toDateString(),
            'period_end' => optional($run->period_end)->toDateString(),
            'locked_by' => $user->id,
            'status' => 'locked',
        ]);

        return redirect()->back()->with('success', 'Payroll run locked.');
    }

    /**
     * Pay employee net pay for a GL-posted run: post the DR Accrued Wages /
     * CR bank journal and mark payslips paid.
     */
    public function payNet(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        if ($run->tenant_id !== $tenantId) {
            abort(404);
        }

        if ($run->journal_id === null) {
            return redirect()->back()->with('error', 'Post the payroll run to the GL before paying net pay.');
        }

        if ($run->net_paid_at !== null) {
            return redirect()->back()->with('error', 'Net pay for this run has already been paid.');
        }

        try {
            $journal = $this->payrollJournalService->postNetPayPayment($run);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $this->webhookService->publish($run->tenant_id, 'payroll.run.paid', [
            'payroll_run_id' => $run->id,
            'payment_journal_id' => $journal->id,
            'paid_by' => $user->id,
            'status' => 'paid',
        ]);

        return redirect()->back()->with('success', 'Net pay disbursed and payslips marked paid.');
    }

    /**
     * Export a locked payroll run as CSV.
     */
    public function export(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        if ($run->tenant_id !== $tenantId) {
            abort(404);
        }

        $validated = $request->validate([
            'profile_id' => ['nullable', 'integer', Rule::exists('hr_payroll_export_profiles', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)
            )],
        ]);

        $profile = null;
        if (! empty($validated['profile_id'])) {
            $profile = HrPayrollExportProfile::query()->findOrFail((int) $validated['profile_id']);
            $this->assertHrTenantAccess($tenantId, $profile->tenant_id);
        }

        try {
            $path = $this->payrollService->generateExport($run, $user->id, $profile);
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['export' => $e->getMessage()]);
        }

        $this->webhookService->publish($run->tenant_id, 'payroll.run.exported', [
            'payroll_run_id' => $run->id,
            'period_start' => optional($run->period_start)->toDateString(),
            'period_end' => optional($run->period_end)->toDateString(),
            'exported_by' => $user->id,
            'storage_path' => $path,
        ]);

        return Storage::disk('private')->download($path, basename($path), [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function storeProfile(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $fieldKeys = array_keys($this->payrollService->exportFieldCatalog());
        $sourceRule = Rule::in(array_merge($fieldKeys, ['static']));
        $nameRule = Rule::unique('hr_payroll_export_profiles', 'name')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

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

        DB::transaction(function () use ($validated, $normalizedMappings, $tenantId, $user) {
            if (! empty($validated['is_default'])) {
                HrPayrollExportProfile::query()
                    ->where('tenant_id', $tenantId)
                    ->update(['is_default' => false]);
            }

            HrPayrollExportProfile::query()->create([
                'tenant_id' => $tenantId,
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
        });

        return redirect()->back()->with('success', 'Payroll export profile created.');
    }

    public function updateProfile(Request $request, HrPayrollExportProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $profile->tenant_id);

        $fieldKeys = array_keys($this->payrollService->exportFieldCatalog());
        $sourceRule = Rule::in(array_merge($fieldKeys, ['static']));
        $nameRule = Rule::unique('hr_payroll_export_profiles', 'name')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId))
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

        DB::transaction(function () use ($updatePayload, $tenantId, $profile) {
            if (! empty($updatePayload['is_default'])) {
                HrPayrollExportProfile::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', '!=', $profile->id)
                    ->update(['is_default' => false]);
            }

            $profile->update($updatePayload);
        });

        return redirect()->back()->with('success', 'Payroll export profile updated.');
    }

    public function setDefaultProfile(Request $request, HrPayrollExportProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $profile->tenant_id);

        DB::transaction(function () use ($tenantId, $profile, $user) {
            HrPayrollExportProfile::query()
                ->where('tenant_id', $tenantId)
                ->update(['is_default' => false]);

            $profile->update([
                'is_default' => true,
                'updated_by' => $user->id,
            ]);
        });

        return redirect()->back()->with('success', 'Default payroll export profile updated.');
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
