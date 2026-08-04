<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsComplianceHero;
use App\Http\Controllers\Hr\Concerns\ProvidesComplianceWizardData;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ComplianceMatrixController extends Controller
{
    use BuildsComplianceHero;
    use ProvidesComplianceWizardData;

    public function __construct(
        private readonly PeopleMutationLockService $mutationLocks,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — matrix grid view */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);

        $requirements = HrComplianceRequirement::query()
            ->with('matrixEntries')
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (HrComplianceRequirement $requirement) => [
                'id' => $requirement->id,
                'name' => $requirement->name,
                'type' => $requirement->check_type,
                'description' => $requirement->description,
                'renewal_period_months' => $requirement->validity_months,
                'is_mandatory' => (bool) $requirement->hard_stop,
                'is_active' => (bool) $requirement->is_active,

                // Keep native fields available for newer screens.
                'code' => $requirement->code,
                'category' => $requirement->category,
                'check_type' => $requirement->check_type,
                'validity_months' => $requirement->validity_months,
                'renewal_reminder_days' => $requirement->renewal_reminder_days,
                'hard_stop' => (bool) $requirement->hard_stop,
            ])
            ->values();

        $matrixRecords = HrComplianceMatrix::query()
            ->with('requirement:id,code,name,category')
            ->orderBy('role')
            ->get();

        // Distinct roles and site types used in matrix
        $roles = $matrixRecords->pluck('role')->unique()->sort()->values();
        $siteTypes = $matrixRecords->pluck('site_type')
            ->merge(Site::query()->active()->notArchived()->whereNotNull('type')->pluck('type'))
            ->filter(fn ($type) => filled($type) && mb_strtolower(trim((string) $type)) !== 'all')
            ->unique()
            ->sort()
            ->values();
        $matrixEntries = $matrixRecords
            ->map(fn (HrComplianceMatrix $entry) => [
                'id' => $entry->id,
                'requirement_id' => $entry->requirement_id,
                'role' => $entry->role,
                'site_type' => $entry->site_type,
                'is_mandatory' => (bool) $entry->is_mandatory,
            ])
            ->values();

        return Inertia::render('hr/compliance/matrix', [
            'hero' => $this->complianceHero($user),
            'requirements' => $requirements,
            'matrixEntries' => $matrixEntries,
            'roles' => $roles,
            'siteTypes' => $siteTypes,
            'wizard' => $this->complianceWizardData($user),
            'can' => [
                'manage' => $user->canDo('hr.compliance.manage'),
                // Real perms for the shared hub header's cross-domain create actions.
                'vetting_manage' => $user->canDo('hr.vetting.manage'),
                'driver_manage' => $user->canDo('hr.driver.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Requirement */
    /* ------------------------------------------------------------------ */

    public function storeRequirement(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $this->normalizeLegacyRequirementPayload($request, false);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('hr_compliance_requirements', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:100'],
            'check_type' => ['required', 'string', Rule::in(['training_course', 'credential', 'background_check', 'policy_attestation', 'driver_licence', 'manual'])],
            'reference_id' => ['nullable', 'integer'],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'renewal_reminder_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'hard_stop' => ['required', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['nullable', 'array', 'max:100'],
            'roles.*' => ['string', 'distinct', 'max:100', Rule::exists('roles', 'name')],
            'site_types' => ['nullable', 'array', 'max:100'],
            'site_types.*' => ['string', 'distinct', 'max:100', Rule::in($this->activeSiteTypeChoices())],
        ]);

        DB::transaction(function () use ($user, $validated): void {
            $lockedActor = $this->lockManagingActor($user);
            $requirement = HrComplianceRequirement::query()->create([
                ...collect($validated)->except(['roles', 'site_types'])->all(),
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => $lockedActor->id,
            ]);
            $this->syncAssignment($validated, $requirement);
        }, 3);

        return redirect()->back()->with('success', 'Compliance requirement created.');
    }

    /**
     * Optional Assignment step from the Requirement wizard: create matrix rows for
     * each role × site-type the requirement now applies to. Additive — never
     * deletes existing rows the user didn't touch.
     */
    private function syncAssignment(array $validated, HrComplianceRequirement $requirement): int
    {
        $roles = array_values(array_filter((array) ($validated['roles'] ?? [])));
        sort($roles);
        if (empty($roles)) {
            return 0;
        }

        $siteTypes = array_values(array_filter((array) ($validated['site_types'] ?? [])));
        if (empty($siteTypes)) {
            $siteTypes = ['all'];
        } else {
            $siteTypes = array_map($this->normaliseSiteType(...), $siteTypes);
            sort($siteTypes);
        }

        $count = 0;
        foreach ($roles as $role) {
            foreach ($siteTypes as $siteType) {
                HrComplianceMatrix::updateOrCreate(
                    [
                        'requirement_id' => $requirement->id,
                        'role' => $role,
                        'site_type' => $siteType,
                    ],
                    [
                        'is_mandatory' => (bool) ($validated['is_mandatory'] ?? $requirement->hard_stop),
                    ],
                );
                $count++;
            }
        }

        return $count;
    }

    /* ------------------------------------------------------------------ */
    /*  Update Requirement */
    /* ------------------------------------------------------------------ */

    public function updateRequirement(Request $request, string $requirement)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $requirement = $this->currentRequirement($requirement);
        $this->normalizeLegacyRequirementPayload($request, true);

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('hr_compliance_requirements', 'code')->ignore($requirement->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'check_type' => ['sometimes', 'required', 'string', Rule::in(['training_course', 'credential', 'background_check', 'policy_attestation', 'driver_licence', 'manual'])],
            'reference_id' => ['nullable', 'integer'],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'renewal_reminder_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'hard_stop' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['nullable', 'array', 'max:100'],
            'roles.*' => ['string', 'distinct', 'max:100', Rule::exists('roles', 'name')],
            'site_types' => ['nullable', 'array', 'max:100'],
            'site_types.*' => ['string', 'distinct', 'max:100', Rule::in($this->activeSiteTypeChoices())],
        ]);

        DB::transaction(function () use ($user, $requirement, $validated): void {
            $lockedActor = $this->lockManagingActor($user);
            $lockedRequirement = HrComplianceRequirement::query()
                ->whereKey($requirement->id)
                ->lockForUpdate()
                ->first();
            abort_unless($lockedRequirement, 404);
            $lockedRequirement->update([
                ...collect($validated)->except(['roles', 'site_types'])->all(),
                'updated_by' => $lockedActor->id,
            ]);
            $this->syncAssignment($validated, $lockedRequirement);
        }, 3);

        return redirect()->back()->with('success', 'Compliance requirement updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy Requirement */
    /* ------------------------------------------------------------------ */

    public function destroyRequirement(Request $request, string $requirement)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $requirement = $this->currentRequirement($requirement);

        DB::transaction(function () use ($user, $requirement): void {
            $lockedActor = $this->lockManagingActor($user);
            $lockedRequirement = HrComplianceRequirement::query()
                ->whereKey($requirement->id)
                ->lockForUpdate()
                ->first();
            abort_unless($lockedRequirement, 404);

            // Soft deactivate rather than hard delete to preserve the audit trail.
            $lockedRequirement->update([
                'is_active' => false,
                'updated_by' => $lockedActor->id,
            ]);
            HrComplianceMatrix::query()
                ->where('requirement_id', $lockedRequirement->id)
                ->delete();
        }, 3);

        return redirect()->back()->with('success', 'Compliance requirement deactivated.');
    }

    public function concealInvalidRequirement(): never
    {
        abort(404);
    }

    /* ------------------------------------------------------------------ */
    /*  Update Matrix — assign/unassign requirement to role/site_type */
    /* ------------------------------------------------------------------ */

    public function updateMatrix(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);

        $validated = $request->validate([
            'requirement_id' => [
                'required',
                'integer',
                Rule::exists('hr_compliance_requirements', 'id')->where('is_active', true),
            ],
            'role' => ['required', 'string', 'max:100', Rule::exists('roles', 'name')],
            'site_type' => ['nullable', 'string', 'max:100', Rule::in($this->activeSiteTypeChoices())],
            'is_mandatory' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'action' => ['required', 'string', Rule::in(['assign', 'unassign'])],
        ]);

        $siteType = $this->normaliseSiteType($validated['site_type'] ?? null);
        DB::transaction(function () use ($user, $validated, $siteType): void {
            $this->lockManagingActor($user);
            $requirement = HrComplianceRequirement::query()
                ->whereKey($validated['requirement_id'])
                ->lockForUpdate()
                ->first();
            abort_unless($requirement && $requirement->is_active, 404);

            if ($validated['action'] === 'assign') {
                HrComplianceMatrix::updateOrCreate(
                    [
                        'requirement_id' => $requirement->id,
                        'role' => $validated['role'],
                        'site_type' => $siteType,
                    ],
                    [
                        'is_mandatory' => $validated['is_mandatory'],
                        'notes' => $validated['notes'] ?? null,
                    ]
                );

                return;
            }

            $entry = HrComplianceMatrix::query()
                ->where('requirement_id', $requirement->id)
                ->where('role', $validated['role'])
                ->where('site_type', $siteType)
                ->delete();
        }, 3);

        $message = $validated['action'] === 'assign'
            ? 'Matrix entry assigned.'
            : 'Matrix entry removed.';

        return redirect()->back()->with('success', $message);
    }

    /** Assign one or more application-wide requirements across role/Site-type combinations. */
    public function assign(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $validated = $request->validate([
            'requirement_ids' => ['required', 'array', 'min:1', 'max:100'],
            'requirement_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('hr_compliance_requirements', 'id')->where('is_active', true),
            ],
            'roles' => ['required', 'array', 'min:1', 'max:100'],
            'roles.*' => ['string', 'distinct', 'max:100', Rule::exists('roles', 'name')],
            'site_types' => ['nullable', 'array', 'max:100'],
            'site_types.*' => ['string', 'distinct', 'max:100', Rule::in($this->activeSiteTypeChoices())],
            'is_mandatory' => ['sometimes', 'boolean'],
        ]);

        $count = DB::transaction(function () use ($user, $validated): int {
            $this->lockManagingActor($user);
            $requirements = HrComplianceRequirement::query()
                ->whereIn('id', $validated['requirement_ids'])
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_unless($requirements->count() === count($validated['requirement_ids']), 404);

            $count = 0;
            foreach ($requirements as $requirement) {
                $count += $this->syncAssignment([
                    ...$validated,
                    'is_mandatory' => $validated['is_mandatory'] ?? true,
                ], $requirement);
            }

            return $count;
        }, 3);

        return redirect()->back()->with('success', "Assigned across {$count} role/Site combinations.");
    }

    private function normalizeLegacyRequirementPayload(Request $request, bool $isUpdate): void
    {
        $payload = $request->all();
        $legacyType = isset($payload['type']) ? trim((string) $payload['type']) : '';

        if (! isset($payload['check_type']) && $legacyType !== '') {
            $payload['check_type'] = $this->mapLegacyTypeToCheckType($legacyType);
        }

        if (! isset($payload['category']) && $legacyType !== '') {
            $payload['category'] = $legacyType;
        }

        if (! isset($payload['validity_months']) && array_key_exists('renewal_period_months', $payload)) {
            $payload['validity_months'] = $payload['renewal_period_months'];
        }

        if (! array_key_exists('hard_stop', $payload) && array_key_exists('is_mandatory', $payload)) {
            $payload['hard_stop'] = (bool) $payload['is_mandatory'];
        }

        if ((! isset($payload['code']) || trim((string) $payload['code']) === '') && isset($payload['name'])) {
            $payload['code'] = Str::upper(Str::slug((string) $payload['name'], '_'));
        }
        if (isset($payload['code'])) {
            $payload['code'] = Str::upper(trim((string) $payload['code']));
        }

        if (! $isUpdate && (! isset($payload['category']) || trim((string) $payload['category']) === '')) {
            $payload['category'] = 'general';
        }

        if (! $isUpdate && (! isset($payload['check_type']) || trim((string) $payload['check_type']) === '')) {
            $payload['check_type'] = 'manual';
        }

        $request->replace($payload);
    }

    private function normaliseSiteType(mixed $siteType): string
    {
        $siteType = is_string($siteType) ? trim($siteType) : '';

        return $siteType === '' ? 'all' : $siteType;
    }

    /** @return array<int, string> */
    private function activeSiteTypeChoices(): array
    {
        return Site::query()
            ->active()
            ->notArchived()
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->map(fn ($type): string => trim((string) $type))
            ->filter(fn (string $type): bool => $type !== '' && mb_strtolower($type) !== 'all')
            ->prepend('all')
            ->unique()
            ->values()
            ->all();
    }

    private function currentRequirement(string $routeRequirementId): HrComplianceRequirement
    {
        $requirement = HrComplianceRequirement::query()
            ->whereKey($this->boundedRouteId($routeRequirementId))
            ->first();
        abort_unless($requirement, 404);

        return $requirement;
    }

    private function lockManagingActor(User $actor): User
    {
        $locked = $this->mutationLocks->lock([$actor->id]);
        $lockedActor = $locked['users']->get($actor->id);
        abort_unless(
            $lockedActor instanceof User && $lockedActor->canDo('hr.compliance.manage'),
            403,
        );

        return $lockedActor;
    }

    private function boundedRouteId(string $value): int
    {
        $normalized = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        abort_unless(
            ctype_digit($value)
                && $normalized !== ''
                && (strlen($normalized) < strlen($maximum)
                    || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) <= 0)),
            404,
        );

        return (int) $normalized;
    }

    private function mapLegacyTypeToCheckType(string $legacyType): string
    {
        return match ($legacyType) {
            'training' => 'training_course',
            'check' => 'background_check',
            'document' => 'policy_attestation',
            'certification', 'license' => 'credential',
            default => 'manual',
        };
    }
}
