<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrCompensationHistory;
use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Domain\Hr\Services\CompensationService;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CompensationController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        protected CompensationService $compensationService,
    ) {}

    /**
     * List salary bands with optional filtering.
     */
    public function bands(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        // "Active as of" date: which bands are in effect on the chosen date
        // (defaults to today). Drives both the active filter and the labelled
        // date picker in the toolbar.
        $asOf = $request->query('as_of') ? Carbon::parse($request->query('as_of')) : Carbon::today();
        $activeAsOf = function ($q) use ($asOf) {
            $q->where('effective_from', '<=', $asOf)
                ->where(fn ($qq) => $qq->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf));
        };

        $bands = HrSalaryBand::query()
            ->forTenant($tenantId)
            ->when($request->query('role'), fn ($q, $role) => $q->where('position_role', 'like', '%'.$this->escapeLike($role).'%'))
            ->when($request->boolean('active_only'), $activeAsOf)
            ->orderBy('position_role')
            ->orderBy('band_name')
            ->paginate(20)
            ->withQueryString();

        // Active employees grouped by role, used to plot people onto each band's
        // range and to compute true (non-page-limited) hero aggregates. Salary
        // fields are encrypted → placement is computed in PHP, not SQL.
        $employees = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->with('user:id,name')
            ->get(['id', 'user_id', 'position_role', 'annual_salary', 'hourly_rate']);

        $byRole = $employees->groupBy('position_role');

        // Per-band placements for the rows currently on the page.
        $bands->getCollection()->transform(function (HrSalaryBand $band) use ($byRole) {
            $people = $byRole->get($band->position_role, collect());

            $placements = $people
                ->map(function (HrEmployeeProfile $p) use ($band) {
                    $placed = $this->compensationService->bandPlacement($p, $band);
                    if ($placed['position'] === null) {
                        return null;
                    }

                    return [
                        'name' => $p->user?->name ?? 'Unknown',
                        'compa_ratio' => $placed['compa_ratio'],
                        'position' => $placed['position'],
                    ];
                })
                ->filter()
                ->sortByDesc('compa_ratio')
                ->values();

            $compas = $placements->pluck('compa_ratio')->filter()->values();

            $band->setAttribute('employee_count', $placements->count());
            $band->setAttribute('in_band', $placements->where('position', 'in')->count());
            $band->setAttribute('under_band', $placements->where('position', 'under')->count());
            $band->setAttribute('over_band', $placements->where('position', 'over')->count());
            $band->setAttribute('avg_compa_ratio', $compas->count() ? round($compas->avg(), 4) : null);
            $band->setAttribute('placements', $placements->all());

            return $band;
        });

        return Inertia::render('hr/compensation/bands', [
            'bands' => $bands,
            'filters' => [
                'role' => $request->query('role'),
                'active_only' => $request->boolean('active_only'),
                'as_of' => $asOf->toDateString(),
            ],
            'stats' => $this->compensationService->heroStats($tenantId, $user),
            'tabCounts' => $this->compensationService->tabCounts($tenantId),
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
                'benefits' => $user->canDo('hr.benefits.view'),
                'expenses' => $user->canDo('hr.expenses.view'),
            ],
        ]);
    }

    /**
     * Stream salary bands as a CSV (respects the same role / active filters).
     */
    public function exportBands(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        // Mirror the list's "active as of" semantics so the CSV matches the screen.
        $asOf = $request->query('as_of') ? Carbon::parse($request->query('as_of')) : Carbon::today();

        $bands = HrSalaryBand::query()
            ->forTenant($tenantId)
            ->when($request->query('role'), fn ($q, $role) => $q->where('position_role', 'like', '%'.$this->escapeLike($role).'%'))
            ->when($request->boolean('active_only'), fn ($q) => $q
                ->where('effective_from', '<=', $asOf)
                ->where(fn ($qq) => $qq->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf)))
            ->orderBy('position_role')
            ->orderBy('band_name')
            ->get();

        $filename = 'salary-bands-'.now()->format('Y-m-d').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->streamDownload(function () use ($bands) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads currency/macrons correctly
            fputcsv($out, [
                'Position role', 'Band', 'Currency',
                'Min salary', 'Mid salary', 'Max salary',
                'Min hourly', 'Max hourly',
                'Effective from', 'Effective to',
            ]);
            foreach ($bands as $band) {
                fputcsv($out, [
                    $band->position_role,
                    $band->band_name,
                    $band->currency,
                    $band->min_salary,
                    $band->mid_salary,
                    $band->max_salary,
                    $band->min_hourly,
                    $band->max_hourly,
                    $band->effective_from?->toDateString(),
                    $band->effective_to?->toDateString(),
                ]);
            }
            fclose($out);
        }, $filename, $headers);
    }

    /**
     * Create a new salary band.
     */
    public function storeBand(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        $data = $request->validate([
            'position_role' => ['required', 'string', 'max:255'],
            'band_name' => ['required', 'string', 'max:255'],
            'min_salary' => ['required', 'numeric', 'min:0'],
            'mid_salary' => ['required', 'numeric', 'min:0'],
            'max_salary' => ['required', 'numeric', 'min:0'],
            'min_hourly' => ['required', 'numeric', 'min:0'],
            'max_hourly' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);

        $this->assertBandOrdering($data);

        HrSalaryBand::create([
            'tenant_id' => $this->resolveHrTenantIdForUser($user),
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Salary band created.');
    }

    /**
     * Update an existing salary band.
     */
    public function updateBand(Request $request, HrSalaryBand $band)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $band->tenant_id);

        $data = $request->validate([
            'position_role' => ['sometimes', 'string', 'max:255'],
            'band_name' => ['sometimes', 'string', 'max:255'],
            'min_salary' => ['sometimes', 'numeric', 'min:0'],
            'mid_salary' => ['sometimes', 'numeric', 'min:0'],
            'max_salary' => ['sometimes', 'numeric', 'min:0'],
            'min_hourly' => ['sometimes', 'numeric', 'min:0'],
            'max_hourly' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);

        // Validate min ≤ mid ≤ max against the merged (existing + incoming) values,
        // since updates may patch only a subset of the range fields.
        $this->assertBandOrdering([
            'min_salary' => $data['min_salary'] ?? $band->min_salary,
            'mid_salary' => $data['mid_salary'] ?? $band->mid_salary,
            'max_salary' => $data['max_salary'] ?? $band->max_salary,
            'min_hourly' => $data['min_hourly'] ?? $band->min_hourly,
            'max_hourly' => $data['max_hourly'] ?? $band->max_hourly,
        ]);

        // Guard the effective window against the merged dates. The `after` rule
        // can't see the stored effective_from on a partial PATCH, so check here.
        $from = $data['effective_from'] ?? optional($band->effective_from)->toDateString();
        $to = $data['effective_to'] ?? optional($band->effective_to)->toDateString();
        if ($from && $to && strtotime((string) $to) <= strtotime((string) $from)) {
            throw ValidationException::withMessages([
                'effective_to' => 'Effective-to must be after effective-from.',
            ]);
        }

        $band->update($data);

        return redirect()->back()->with('success', 'Salary band updated.');
    }

    /**
     * Escape LIKE wildcards so a user-typed % or _ is matched literally.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Guard the salary-band invariant: min ≤ mid ≤ max (and min ≤ max hourly).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertBandOrdering(array $data): void
    {
        $min = (float) $data['min_salary'];
        $mid = (float) $data['mid_salary'];
        $max = (float) $data['max_salary'];

        $errors = [];
        if ($min > $mid) {
            $errors['mid_salary'] = 'Mid salary must be at least the minimum.';
        }
        if ($mid > $max) {
            $errors['max_salary'] = 'Max salary must be at least the mid salary.';
        }
        if ((float) $data['min_hourly'] > (float) $data['max_hourly']) {
            $errors['max_hourly'] = 'Max hourly rate must be at least the minimum.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Compensation history for a specific employee.
     */
    public function history(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);

        $profile->load('user:id,name');

        $history = HrCompensationHistory::where('employee_profile_id', $profile->id)
            ->with(['approver:id,name', 'creator:id,name'])
            ->orderByDesc('effective_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/compensation/history', [
            'profile' => $profile,
            'history' => $history,
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
            ],
        ]);
    }

    /**
     * Company-wide compensation change log (the History hub tab).
     */
    public function historyIndex(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $history = HrCompensationHistory::query()
            ->where('tenant_id', $tenantId)
            ->when($request->query('change_type'), fn ($q, $t) => $q->where('change_type', $t))
            ->with(['employeeProfile.user:id,name', 'approver:id,name'])
            ->orderByDesc('effective_date')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('hr/compensation/history-index', [
            'history' => $history,
            'filters' => ['change_type' => $request->query('change_type')],
            'stats' => $this->compensationService->heroStats($tenantId, $user),
            'tabCounts' => $this->compensationService->tabCounts($tenantId),
            'can' => ['manage' => $user->canDo('hr.compensation.manage')],
        ]);
    }

    /**
     * Read-only compensation settings (mileage rate + GL map) — the Settings tab.
     */
    public function settings(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        return Inertia::render('hr/compensation/settings', [
            'settings' => [
                'mileage_rate_per_km' => (float) config('finance.mileage_rate_per_km'),
                'currency' => 'NZD',
                'gl_accounts' => collect((array) config('finance.event_accounts', []))
                    ->map(fn ($a, $k) => [
                        'key' => $k,
                        'account' => is_array($a) ? ($a['debit'] ?? null) : $a,
                    ])
                    ->values()
                    ->all(),
            ],
            'stats' => $this->compensationService->heroStats($tenantId, $user),
            'tabCounts' => $this->compensationService->tabCounts($tenantId),
            'can' => ['manage' => $user->canDo('hr.compensation.manage')],
        ]);
    }

    /**
     * List compensation review cycles.
     */
    public function reviews(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $reviews = HrCompensationReview::query()
            ->where('tenant_id', $tenantId)
            ->withCount('items')
            ->with('creator:id,name')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/compensation/reviews', [
            'reviews' => $reviews,
            'filters' => [
                'status' => $request->query('status'),
            ],
            'stats' => $this->compensationService->heroStats($tenantId, $user),
            'tabCounts' => $this->compensationService->tabCounts($tenantId),
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
            ],
        ]);
    }

    /**
     * Show form to create a compensation review.
     */
    public function createReview(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $employees = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->with('user:id,name')
            ->active()
            ->get(['id', 'user_id', 'position_title', 'annual_salary', 'hourly_rate']);

        return Inertia::render('hr/compensation/review-detail', [
            'review' => null,
            'employees' => $employees,
            'reviewCycles' => [
                ['value' => 'annual', 'label' => 'Annual'],
                ['value' => 'mid_year', 'label' => 'Mid-Year'],
                ['value' => 'ad_hoc', 'label' => 'Ad Hoc'],
            ],
            'can' => [
                'manage' => true,
            ],
        ]);
    }

    /**
     * Store a new compensation review.
     */
    public function storeReview(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'review_cycle' => ['required', 'string', 'in:annual,mid_year,ad_hoc'],
            'effective_date' => ['required', 'date'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array'],
            'items.*.employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'items.*.current_salary' => ['required', 'numeric', 'min:0'],
            'items.*.proposed_salary' => ['required', 'numeric', 'min:0'],
            'items.*.change_percentage' => ['required', 'numeric'],
            'items.*.justification' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->compensationService->createCompensationReview([
            'tenant_id' => $this->resolveHrTenantIdForUser($user),
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->route('hr.compensation.reviews')->with('success', 'Compensation review created.');
    }

    /**
     * Show a single compensation review with its items.
     */
    public function showReview(Request $request, HrCompensationReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $review->tenant_id);

        $review->load([
            'items.employeeProfile.user:id,name',
            'items.approver:id,name',
            'creator:id,name',
        ]);

        $employees = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->with('user:id,name')
            ->active()
            ->get(['id', 'user_id', 'position_title', 'annual_salary', 'hourly_rate']);

        return Inertia::render('hr/compensation/review-detail', [
            'review' => $review,
            'employees' => $employees,
            'reviewCycles' => [
                ['value' => 'annual', 'label' => 'Annual'],
                ['value' => 'mid_year', 'label' => 'Mid-Year'],
                ['value' => 'ad_hoc', 'label' => 'Ad Hoc'],
            ],
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
            ],
        ]);
    }

    /**
     * Approve a compensation review (planning/in_progress → approved) so it can be applied.
     */
    public function approveReview(Request $request, HrCompensationReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        try {
            $this->compensationService->approveCompensationReview($review, $user->id);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Compensation review approved. You can now apply it to update employee salaries.');
    }

    /**
     * Apply an approved compensation review (bulk update).
     */
    public function applyReview(Request $request, HrCompensationReview $review)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        try {
            $this->compensationService->applyCompensationReview($review);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Compensation review applied successfully. Employee profiles have been updated.');
    }
}
