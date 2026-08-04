<?php

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('team')) {
            $team = $this->input('team');
            if (is_string($team) || $team === null) {
                $this->merge(['team' => HrEmployeeProfile::normalizeTeam($team)]);
            }
        }

        $viewer = $this->user();
        $profile = $this->route('profile');
        if (! $viewer || ! $profile instanceof HrEmployeeProfile || ! $viewer->canDo('hr.employees.manage')) {
            return;
        }

        $siteAccess = app(UserSiteAccessService::class);
        $visibleUser = User::query()->whereKey($profile->user_id);
        $siteAccess->applyHistoricalStaffSiteScope($visibleUser, $viewer);
        $accessibleSiteIds = $siteAccess->accessibleSiteIds($viewer);
        $assignedSiteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn ($siteId) => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values();

        abort_unless(
            $visibleUser->exists()
                && $assignedSiteIds->isNotEmpty()
                && $assignedSiteIds->diff($accessibleSiteIds)->isEmpty(),
            404,
        );
    }

    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.employees.manage') ?? false;
    }

    public function rules(): array
    {
        $canManageFinancial = $this->user()?->canDo('hr.employees.viewFinancial') ?? false;
        $siteAccess = app(UserSiteAccessService::class);
        $accessibleSiteIds = $siteAccess->accessibleSiteIds($this->user());
        $managerQuery = User::query();
        $siteAccess->applyStaffScope($managerQuery, $this->user());
        $accessibleManagerIds = $managerQuery->pluck('users.id')->all();
        $accessibleDepartmentIds = HrDepartment::query()
            ->where('is_active', true)
            ->where(function ($query) use ($accessibleSiteIds): void {
                $query->whereDoesntHave('sites');
                if ($accessibleSiteIds !== []) {
                    $query->orWhereHas('sites', fn ($siteQuery) => $siteQuery
                        ->whereIn('sites.id', $accessibleSiteIds));
                }
            })
            ->pluck('id')
            ->all();

        return [
            'employee_number' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:50'],
            'ethnicity' => ['nullable', 'string', 'max:100'],
            'work_rights_status' => ['nullable', 'string', Rule::in(['citizen', 'permanent_resident', 'resident_visa', 'work_visa', 'student_visa', 'other'])],
            'visa_type' => ['nullable', 'string', 'max:100'],
            'visa_expires_at' => ['nullable', 'date'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:50'],
            'home_address' => ['nullable', 'string', 'max:1000'],
            'work_email' => ['nullable', 'email', 'max:255'],
            'work_phone' => ['nullable', 'string', 'max:50'],
            'position_title' => ['sometimes', 'required', 'string', 'max:255'],
            'position_role' => ['nullable', 'string', 'max:100'],
            'team' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['sometimes', 'required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'contract_type' => ['nullable', 'string', Rule::in(['permanent', 'fixed_term', 'casual', 'contractor'])],
            'hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'hourly_rate' => [Rule::prohibitedIf(! $canManageFinancial), 'nullable', 'numeric', 'min:0'],
            'annual_salary' => [Rule::prohibitedIf(! $canManageFinancial), 'nullable', 'numeric', 'min:0'],
            'pay_frequency' => [Rule::prohibitedIf(! $canManageFinancial), 'nullable', 'string', Rule::in(['weekly', 'fortnightly', 'monthly'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'probation_end_date' => ['nullable', 'date'],
            'termination_reason' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'primary_site_id' => [
                'nullable',
                'integer',
                Rule::exists('sites', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleSiteIds),
                ),
            ],
            'secondary_site_ids' => ['nullable', 'array'],
            'secondary_site_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('sites', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleSiteIds),
                ),
            ],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('hr_departments', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleDepartmentIds),
                ),
            ],
            'manager_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleManagerIds),
                ),
            ],
            'emergency_contacts' => ['nullable', 'array'],
            'emergency_contacts.*.name' => ['required_with:emergency_contacts', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'bank_account' => [Rule::prohibitedIf(! $canManageFinancial), 'nullable', 'string', 'max:255'],
            'ird_number' => [Rule::prohibitedIf(! $canManageFinancial), 'nullable', 'string', 'max:20'],
            'tax_code' => [Rule::prohibitedIf(! $canManageFinancial), 'nullable', 'string', 'max:10'],
            'kiwisaver_rate' => [Rule::prohibitedIf(! $canManageFinancial), 'nullable', 'numeric', 'min:0', 'max:10'],
            'can_drive_clients' => ['sometimes', 'boolean'],
            'is_first_aider' => ['sometimes', 'boolean'],
            'is_fire_warden' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'restricted_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
