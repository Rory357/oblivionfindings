<?php

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\EmployeeRoleAssignmentService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }

        if ($this->has('team')) {
            $team = $this->input('team');
            if (is_string($team) || $team === null) {
                $this->merge(['team' => HrEmployeeProfile::normalizeTeam($team)]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.employees.manage') ?? false;
    }

    public function rules(): array
    {
        $siteAccess = app(UserSiteAccessService::class);
        $accessibleSiteIds = $siteAccess->accessibleSiteIds(
            $this->user(),
            UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS,
        );
        $managerQuery = User::query();
        $siteAccess->applyHrEmployeeStaffScope($managerQuery, $this->user());
        $accessibleManagerIds = $managerQuery->pluck('users.id')->all();
        $assignableRoleNames = $this->user()
            ? app(EmployeeRoleAssignmentService::class)->assignableRoleNames($this->user())
            : [];
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
            'name' => ['required', 'string', 'max:255'],
            // NOT unique: an existing account (e.g. a candidate-created user) is
            // linked/updated by EmployeeIntakeService rather than rejected. The
            // controller gates silent overwrite behind `link_existing`.
            'email' => ['required', 'email', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in($assignableRoleNames)],
            'position_id' => ['nullable', 'integer', 'exists:hr_positions,id'],
            'position_title' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('hr_departments', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleDepartmentIds),
                ),
            ],
            'team' => ['nullable', 'string', 'max:255'],
            'primary_site_id' => [
                'required',
                'integer',
                Rule::exists('sites', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleSiteIds),
                ),
            ],
            'secondary_site_ids' => ['nullable', 'array'],
            'secondary_site_ids.*' => [
                'integer',
                'distinct',
                Rule::notIn([(int) $this->input('primary_site_id')]),
                Rule::exists('sites', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleSiteIds),
                ),
            ],
            'manager_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->whereIn('id', $accessibleManagerIds),
                ),
            ],
            'start_date' => ['nullable', 'date'],
            'work_phone' => ['nullable', 'string', 'max:50'],

            // Step 3 — right to work / visa (all nullable so quick-add still works).
            'work_rights_status' => ['nullable', 'string', 'max:50'],
            'visa_type' => ['nullable', 'string', 'max:100'],
            'visa_expires_at' => ['nullable', 'date'],

            // Step 4 — emergency contacts (JSON array of {name, relationship, phone}).
            'emergency_contacts' => ['nullable', 'array'],
            'emergency_contacts.*.name' => ['nullable', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contacts.*.phone' => ['nullable', 'string', 'max:50'],

            // Intake toggles.
            'start_onboarding' => ['nullable', 'boolean'],
            'send_invite' => ['nullable', 'boolean'],
            'link_existing' => ['nullable', 'boolean'],
        ];
    }
}
