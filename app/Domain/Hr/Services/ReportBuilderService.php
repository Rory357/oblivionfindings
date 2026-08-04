<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrSavedReport;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ReportBuilderService
{
    use SanitizesCsvOutput;

    public const MAX_FIELDS = 50;

    public const MAX_FILTERS = 25;

    public const FILTER_OPERATORS = [
        '=', '!=', '>', '>=', '<', '<=',
        'contains', 'starts_with', 'ends_with', 'is_null', 'is_not_null',
    ];

    /**
     * Available report data sources with their queryable fields.
     */
    public const REPORT_SOURCES = [
        'employee' => [
            'label' => 'Employee Data',
            'fields' => [
                'employee_number', 'name', 'email', 'position_title', 'department',
                'employment_type', 'start_date', 'end_date', 'is_active',
                'primary_site', 'hours_per_week', 'pay_frequency',
                'gender', 'ethnicity', 'is_first_aider', 'is_fire_warden',
            ],
        ],
        'leave' => [
            'label' => 'Leave Data',
            'fields' => [
                'employee_name', 'leave_type', 'start_date', 'end_date',
                'hours_requested', 'status', 'reason', 'submitted_at', 'reviewed_by',
            ],
        ],
        'compliance' => [
            'label' => 'Compliance Data',
            'fields' => [
                'employee_name', 'requirement_name', 'category', 'status',
                'completed_at', 'expires_at', 'is_hard_stop',
            ],
        ],
        'time' => [
            'label' => 'Timekeeping Data',
            'fields' => [
                'employee_name', 'entry_date', 'clock_in', 'clock_out',
                'total_hours', 'break_minutes', 'status', 'entry_type',
            ],
        ],
        'training' => [
            'label' => 'Training Data',
            'fields' => [
                'employee_name', 'course_title', 'category', 'status',
                'enrolled_at', 'completed_at', 'score',
            ],
        ],
    ];

    /** @var array<string, array<int, string>> */
    private const SOURCE_PERMISSIONS = [
        'employee' => ['hr.employees.viewAny'],
        'leave' => ['hr.leave.viewAny'],
        'compliance' => ['hr.compliance.view'],
        'time' => ['timesheets.viewAny', 'hr.time.viewAny'],
        'training' => ['hr.training.view', 'training.viewAny'],
    ];

    /** @var array<string, array<string, array<int, string>>> */
    private const FIELD_PERMISSIONS = [
        'employee' => [
            'hours_per_week' => ['hr.employees.viewFinancial'],
            'pay_frequency' => ['hr.employees.viewFinancial'],
            'gender' => ['hr.employees.viewRestricted'],
            'ethnicity' => ['hr.employees.viewRestricted'],
        ],
        'leave' => [
            'reason' => ['hr.leave.manage'],
        ],
    ];

    /**
     * Every selectable alias maps to a controlled select expression and a
     * controlled column for filters/sorting. Request data is never used as a
     * SQL identifier.
     *
     * @var array<string, array<string, array{select: string, column: string}>>
     */
    private const FIELD_MAPS = [
        'employee' => [
            'employee_number' => ['select' => 'hr_employee_profiles.employee_number as employee_number', 'column' => 'hr_employee_profiles.employee_number'],
            'name' => ['select' => 'users.name as name', 'column' => 'users.name'],
            'email' => ['select' => 'users.email as email', 'column' => 'users.email'],
            'position_title' => ['select' => 'hr_employee_profiles.position_title as position_title', 'column' => 'hr_employee_profiles.position_title'],
            'department' => ['select' => 'hr_employee_profiles.department as department', 'column' => 'hr_employee_profiles.department'],
            'employment_type' => ['select' => 'hr_employee_profiles.employment_type as employment_type', 'column' => 'hr_employee_profiles.employment_type'],
            'start_date' => ['select' => 'hr_employee_profiles.start_date as start_date', 'column' => 'hr_employee_profiles.start_date'],
            'end_date' => ['select' => 'hr_employee_profiles.end_date as end_date', 'column' => 'hr_employee_profiles.end_date'],
            'is_active' => ['select' => 'hr_employee_profiles.is_active as is_active', 'column' => 'hr_employee_profiles.is_active'],
            'primary_site' => ['select' => 'sites.name as primary_site', 'column' => 'sites.name'],
            'hours_per_week' => ['select' => 'hr_employee_profiles.hours_per_week as hours_per_week', 'column' => 'hr_employee_profiles.hours_per_week'],
            'pay_frequency' => ['select' => 'hr_employee_profiles.pay_frequency as pay_frequency', 'column' => 'hr_employee_profiles.pay_frequency'],
            'gender' => ['select' => 'hr_employee_profiles.gender as gender', 'column' => 'hr_employee_profiles.gender'],
            'ethnicity' => ['select' => 'hr_employee_profiles.ethnicity as ethnicity', 'column' => 'hr_employee_profiles.ethnicity'],
            'is_first_aider' => ['select' => 'hr_employee_profiles.is_first_aider as is_first_aider', 'column' => 'hr_employee_profiles.is_first_aider'],
            'is_fire_warden' => ['select' => 'hr_employee_profiles.is_fire_warden as is_fire_warden', 'column' => 'hr_employee_profiles.is_fire_warden'],
        ],
        'leave' => [
            'employee_name' => ['select' => 'users.name as employee_name', 'column' => 'users.name'],
            'leave_type' => ['select' => 'hr_leave_requests.leave_type as leave_type', 'column' => 'hr_leave_requests.leave_type'],
            'start_date' => ['select' => 'hr_leave_requests.starts_at as start_date', 'column' => 'hr_leave_requests.starts_at'],
            'end_date' => ['select' => 'hr_leave_requests.ends_at as end_date', 'column' => 'hr_leave_requests.ends_at'],
            'hours_requested' => ['select' => 'hr_leave_requests.hours_requested as hours_requested', 'column' => 'hr_leave_requests.hours_requested'],
            'status' => ['select' => 'hr_leave_requests.status as status', 'column' => 'hr_leave_requests.status'],
            'reason' => ['select' => 'hr_leave_requests.reason as reason', 'column' => 'hr_leave_requests.reason'],
            'submitted_at' => ['select' => 'hr_leave_requests.submitted_at as submitted_at', 'column' => 'hr_leave_requests.submitted_at'],
            'reviewed_by' => ['select' => 'reviewers.name as reviewed_by', 'column' => 'reviewers.name'],
        ],
        'compliance' => [
            'employee_name' => ['select' => 'users.name as employee_name', 'column' => 'users.name'],
            'requirement_name' => ['select' => 'hr_compliance_requirements.name as requirement_name', 'column' => 'hr_compliance_requirements.name'],
            'category' => ['select' => 'hr_compliance_requirements.category as category', 'column' => 'hr_compliance_requirements.category'],
            'status' => ['select' => 'hr_compliance_matrix.status as status', 'column' => 'hr_compliance_matrix.status'],
            'completed_at' => ['select' => 'hr_compliance_matrix.completed_at as completed_at', 'column' => 'hr_compliance_matrix.completed_at'],
            'expires_at' => ['select' => 'hr_compliance_matrix.expires_at as expires_at', 'column' => 'hr_compliance_matrix.expires_at'],
            'is_hard_stop' => ['select' => 'hr_compliance_requirements.is_hard_stop as is_hard_stop', 'column' => 'hr_compliance_requirements.is_hard_stop'],
        ],
        'time' => [
            'employee_name' => ['select' => 'users.name as employee_name', 'column' => 'users.name'],
            'entry_date' => ['select' => 'hr_time_entries.entry_date as entry_date', 'column' => 'hr_time_entries.entry_date'],
            'clock_in' => ['select' => 'hr_time_entries.clock_in as clock_in', 'column' => 'hr_time_entries.clock_in'],
            'clock_out' => ['select' => 'hr_time_entries.clock_out as clock_out', 'column' => 'hr_time_entries.clock_out'],
            'total_hours' => ['select' => 'hr_time_entries.total_hours as total_hours', 'column' => 'hr_time_entries.total_hours'],
            'break_minutes' => ['select' => 'hr_time_entries.break_minutes as break_minutes', 'column' => 'hr_time_entries.break_minutes'],
            'status' => ['select' => 'hr_time_entries.status as status', 'column' => 'hr_time_entries.status'],
            'entry_type' => ['select' => 'hr_time_entries.entry_type as entry_type', 'column' => 'hr_time_entries.entry_type'],
        ],
        'training' => [
            'employee_name' => ['select' => 'users.name as employee_name', 'column' => 'users.name'],
            'course_title' => ['select' => 'hr_courses.title as course_title', 'column' => 'hr_courses.title'],
            'category' => ['select' => 'hr_courses.category as category', 'column' => 'hr_courses.category'],
            'status' => ['select' => 'hr_course_enrollments.status as status', 'column' => 'hr_course_enrollments.status'],
            'enrolled_at' => ['select' => 'hr_course_enrollments.created_at as enrolled_at', 'column' => 'hr_course_enrollments.created_at'],
            'completed_at' => ['select' => 'hr_course_enrollments.completed_at as completed_at', 'column' => 'hr_course_enrollments.completed_at'],
            'score' => ['select' => 'hr_course_enrollments.score as score', 'column' => 'hr_course_enrollments.score'],
        ],
    ];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Return only sources and fields the explicit viewer may query.
     *
     * @return array<string, array{label: string, fields: array<int, string>}>
     */
    public function getAvailableSources(User $viewer): array
    {
        $sources = [];

        foreach (self::REPORT_SOURCES as $source => $definition) {
            if (! $this->canAccessSource($viewer, $source)) {
                continue;
            }

            $sources[$source] = [
                'label' => $definition['label'],
                'fields' => array_values(array_filter(
                    $definition['fields'],
                    fn (string $field): bool => $this->canAccessField($viewer, $source, $field),
                )),
            ];
        }

        return $sources;
    }

    /**
     * Validate a definition from either a request or a stored report. Stored
     * JSON receives the same permission and shape checks on every execution.
     *
     * @param  array<int, mixed>  $fields
     * @param  array<int, mixed>|null  $filters
     */
    public function assertDefinitionAllowed(
        User $viewer,
        string $reportType,
        array $fields,
        ?array $filters,
        ?string $groupBy,
        ?string $sortBy,
    ): void {
        $errors = [];
        $availableSources = $this->getAvailableSources($viewer);
        $allowedFields = $availableSources[$reportType]['fields'] ?? null;

        if ($allowedFields === null) {
            $errors['report_type'][] = 'You do not have access to this report source.';
        }

        if ($fields === [] || count($fields) > self::MAX_FIELDS) {
            $errors['fields'][] = 'Choose between 1 and '.self::MAX_FIELDS.' report fields.';
        }

        $normalizedFields = collect($fields)
            ->filter(fn (mixed $field): bool => is_string($field))
            ->values();
        if ($normalizedFields->count() !== count($fields) || $normalizedFields->unique()->count() !== count($fields)) {
            $errors['fields'][] = 'Report fields must be distinct field names.';
        }

        if ($allowedFields !== null) {
            foreach ($fields as $field) {
                if (! is_string($field) || ! in_array($field, $allowedFields, true)) {
                    $errors['fields'][] = 'One or more report fields are unavailable.';
                    break;
                }
            }
        }

        if ($groupBy !== null && $groupBy !== '') {
            $errors['group_by'][] = 'Grouped reports are not available in this builder.';
        }

        if ($sortBy !== null && $sortBy !== '' && ($allowedFields === null || ! in_array($sortBy, $allowedFields, true))) {
            $errors['sort_by'][] = 'The selected sort field is unavailable.';
        }

        if ($filters !== null && count($filters) > self::MAX_FILTERS) {
            $errors['filters'][] = 'Use no more than '.self::MAX_FILTERS.' report filters.';
        }

        foreach ($filters ?? [] as $index => $filter) {
            if (! is_array($filter)) {
                $errors["filters.{$index}"][] = 'Each report filter must be an object.';

                continue;
            }

            $field = $filter['field'] ?? null;
            $operator = $filter['operator'] ?? null;
            $value = $filter['value'] ?? null;

            if (! is_string($field) || $allowedFields === null || ! in_array($field, $allowedFields, true)) {
                $errors["filters.{$index}.field"][] = 'The selected filter field is unavailable.';
            }

            if (! is_string($operator) || ! in_array($operator, self::FILTER_OPERATORS, true)) {
                $errors["filters.{$index}.operator"][] = 'The selected filter operator is invalid.';
            }

            if (! in_array($operator, ['is_null', 'is_not_null'], true)) {
                if (! is_scalar($value) || mb_strlen((string) $value) > 255) {
                    $errors["filters.{$index}.value"][] = 'A filter value of at most 255 characters is required.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Execute a creator-authorized saved report for the current viewer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function executeReport(HrSavedReport $report, User $viewer): array
    {
        $query = $this->buildQuery(
            $viewer,
            $report->report_type,
            (array) $report->fields,
            $report->filters === null ? null : (array) $report->filters,
            $report->group_by,
            $report->sort_by,
            $report->sort_direction ?? 'asc',
        );

        $report->forceFill(['last_run_at' => now()])->save();

        return $query->get()->toArray();
    }

    /**
     * Build an Eloquent query whose identifiers come only from FIELD_MAPS and
     * whose staff rows are constrained by canonical historical Site access.
     *
     * @param  array<int, mixed>  $fields
     * @param  array<int, mixed>|null  $filters
     */
    public function buildQuery(
        User $viewer,
        string $reportType,
        array $fields,
        ?array $filters,
        ?string $groupBy,
        ?string $sortBy,
        string $sortDirection = 'asc',
    ): Builder {
        $this->assertDefinitionAllowed($viewer, $reportType, $fields, $filters, $groupBy, $sortBy);

        /** @var array<string, array{select: string, column: string}> $fieldMap */
        $fieldMap = self::FIELD_MAPS[$reportType];
        $selects = array_map(
            fn (string $field): string => $fieldMap[$field]['select'],
            $fields,
        );

        $query = match ($reportType) {
            'employee' => $this->buildEmployeeQuery($viewer),
            'leave' => $this->buildLeaveQuery($viewer),
            'compliance' => $this->buildComplianceQuery($viewer),
            'time' => $this->buildTimeQuery($viewer),
            'training' => $this->buildTrainingQuery($viewer),
            default => throw new \InvalidArgumentException("Unknown report type: {$reportType}"),
        };
        $query->selectRaw(implode(', ', $selects));

        foreach ($filters ?? [] as $filter) {
            $column = $fieldMap[$filter['field']]['column'];
            $operator = $filter['operator'];
            $value = $filter['value'] ?? null;

            match ($operator) {
                'contains' => $query->where($column, 'like', "%{$value}%"),
                'starts_with' => $query->where($column, 'like', "{$value}%"),
                'ends_with' => $query->where($column, 'like', "%{$value}"),
                'is_null' => $query->whereNull($column),
                'is_not_null' => $query->whereNotNull($column),
                default => $query->where($column, $operator, $value),
            };
        }

        if ($sortBy !== null && $sortBy !== '') {
            $query->orderBy($fieldMap[$sortBy]['column'], $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query;
    }

    /**
     * Export data rows to a CSV string.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  array<int, string>  $fields
     */
    public function exportToCsv(array $data, array $fields): string
    {
        $output = fopen('php://temp', 'r+');

        $headers = array_map(fn (string $field): string => ucwords(str_replace('_', ' ', $field)), $fields);
        $this->putCsv($output, $headers);

        foreach ($data as $row) {
            $line = [];
            foreach ($fields as $field) {
                $value = $row[$field] ?? '';
                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                }
                $line[] = $value;
            }
            $this->putCsv($output, $line);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    private function buildEmployeeQuery(User $viewer): Builder
    {
        $query = HrEmployeeProfile::withTrashed()
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->leftJoin('sites', 'sites.id', '=', 'hr_employee_profiles.primary_site_id');

        return $this->siteAccess->applyHistoricalStaffProfileScope($query, $viewer);
    }

    private function buildLeaveQuery(User $viewer): Builder
    {
        return HrLeaveRequest::query()
            ->join('users', 'users.id', '=', 'hr_leave_requests.user_id')
            ->leftJoin('users as reviewers', 'reviewers.id', '=', 'hr_leave_requests.reviewed_by')
            ->whereIn('hr_leave_requests.user_id', $this->visibleHistoricalUserIds($viewer));
    }

    private function buildComplianceQuery(User $viewer): Builder
    {
        return HrComplianceRequirement::query()
            ->join('hr_compliance_matrix', 'hr_compliance_matrix.requirement_id', '=', 'hr_compliance_requirements.id')
            ->join('users', 'users.id', '=', 'hr_compliance_matrix.user_id')
            ->whereIn('hr_compliance_matrix.user_id', $this->visibleHistoricalUserIds($viewer));
    }

    private function buildTimeQuery(User $viewer): Builder
    {
        return HrTimeEntry::query()
            ->join('users', 'users.id', '=', 'hr_time_entries.user_id')
            ->whereIn('hr_time_entries.user_id', $this->visibleHistoricalUserIds($viewer));
    }

    private function buildTrainingQuery(User $viewer): Builder
    {
        return HrCourseEnrollment::query()
            ->join('users', 'users.id', '=', 'hr_course_enrollments.user_id')
            ->join('hr_courses', 'hr_courses.id', '=', 'hr_course_enrollments.course_id')
            ->whereIn('hr_course_enrollments.user_id', $this->visibleHistoricalUserIds($viewer));
    }

    /** @return Builder<User> */
    private function visibleHistoricalUserIds(User $viewer): Builder
    {
        return $this->siteAccess->applyHistoricalStaffSiteScope(
            User::query()->select('users.id'),
            $viewer,
        );
    }

    private function canAccessSource(User $viewer, string $source): bool
    {
        foreach (self::SOURCE_PERMISSIONS[$source] ?? [] as $permission) {
            if ($viewer->canDo($permission)) {
                return true;
            }
        }

        return false;
    }

    private function canAccessField(User $viewer, string $source, string $field): bool
    {
        $permissions = self::FIELD_PERMISSIONS[$source][$field] ?? [];
        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($viewer->canDo($permission)) {
                return true;
            }
        }

        return false;
    }
}
