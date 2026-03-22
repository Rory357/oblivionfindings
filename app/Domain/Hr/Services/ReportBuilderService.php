<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrSavedReport;
use App\Domain\Hr\Models\HrTimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ReportBuilderService
{
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
            'label' => 'Time Tracking',
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

    /**
     * Get available report sources for the builder UI.
     */
    public function getAvailableSources(): array
    {
        return self::REPORT_SOURCES;
    }

    /**
     * Execute a saved report and return rows of data.
     */
    public function executeReport(HrSavedReport $report): array
    {
        $query = $this->buildQuery(
            $report->report_type,
            $report->fields,
            $report->filters,
            $report->group_by,
            $report->sort_by,
            $report->sort_direction ?? 'asc',
        );

        $report->update(['last_run_at' => now()]);

        return $query->get()->toArray();
    }

    /**
     * Build an Eloquent query for the given report parameters.
     */
    public function buildQuery(
        string $reportType,
        array $fields,
        ?array $filters,
        ?string $groupBy,
        ?string $sortBy,
        string $sortDirection = 'asc',
    ): Builder {
        $query = match ($reportType) {
            'employee' => $this->buildEmployeeQuery($fields),
            'leave' => $this->buildLeaveQuery($fields),
            'compliance' => $this->buildComplianceQuery($fields),
            'time' => $this->buildTimeQuery($fields),
            'training' => $this->buildTrainingQuery($fields),
            default => throw new \InvalidArgumentException("Unknown report type: {$reportType}"),
        };

        // Apply filters
        if ($filters) {
            foreach ($filters as $filter) {
                $field = $filter['field'] ?? null;
                $operator = $filter['operator'] ?? '=';
                $value = $filter['value'] ?? null;

                if (! $field || $value === null) {
                    continue;
                }

                match ($operator) {
                    'contains' => $query->where($field, 'like', "%{$value}%"),
                    'starts_with' => $query->where($field, 'like', "{$value}%"),
                    'ends_with' => $query->where($field, 'like', "%{$value}"),
                    'is_null' => $query->whereNull($field),
                    'is_not_null' => $query->whereNotNull($field),
                    default => $query->where($field, $operator, $value),
                };
            }
        }

        // Apply grouping
        if ($groupBy) {
            $query->groupBy($groupBy);
        }

        // Apply sorting
        if ($sortBy) {
            $query->orderBy($sortBy, $sortDirection);
        }

        return $query;
    }

    /**
     * Export data rows to CSV string.
     */
    public function exportToCsv(array $data, array $fields): string
    {
        $output = fopen('php://temp', 'r+');

        // Header row — use human-readable labels
        $headers = array_map(fn ($f) => ucwords(str_replace('_', ' ', $f)), $fields);
        fputcsv($output, $headers);

        // Data rows
        foreach ($data as $row) {
            $line = [];
            foreach ($fields as $field) {
                $value = $row[$field] ?? '';
                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                }
                $line[] = $value;
            }
            fputcsv($output, $line);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export data to an Excel-compatible file (CSV with .xlsx extension).
     * Returns the file path to the generated file.
     */
    public function exportToExcel(array $data, array $fields, string $reportName): string
    {
        $csv = $this->exportToCsv($data, $fields);

        $filename = str_replace(' ', '_', strtolower($reportName)) . '_' . now()->format('Y-m-d_His') . '.xlsx';
        $path = storage_path("app/private/reports/{$filename}");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $csv);

        return $path;
    }

    /* ------------------------------------------------------------------ */
    /*  Private query builders per report type                             */
    /* ------------------------------------------------------------------ */

    private function buildEmployeeQuery(array $fields): Builder
    {
        $fieldMap = [
            'employee_number' => 'hr_employee_profiles.employee_number',
            'name' => 'users.name',
            'email' => 'users.email',
            'position_title' => 'hr_employee_profiles.position_title',
            'department' => 'hr_employee_profiles.department',
            'employment_type' => 'hr_employee_profiles.employment_type',
            'start_date' => 'hr_employee_profiles.start_date',
            'end_date' => 'hr_employee_profiles.end_date',
            'is_active' => 'hr_employee_profiles.is_active',
            'primary_site' => 'sites.name as primary_site',
            'hours_per_week' => 'hr_employee_profiles.hours_per_week',
            'pay_frequency' => 'hr_employee_profiles.pay_frequency',
            'gender' => 'hr_employee_profiles.gender',
            'ethnicity' => 'hr_employee_profiles.ethnicity',
            'is_first_aider' => 'hr_employee_profiles.is_first_aider',
            'is_fire_warden' => 'hr_employee_profiles.is_fire_warden',
        ];

        $selects = [];
        foreach ($fields as $field) {
            if (isset($fieldMap[$field])) {
                $selects[] = $fieldMap[$field];
            }
        }

        $query = HrEmployeeProfile::query()
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->leftJoin('sites', 'sites.id', '=', 'hr_employee_profiles.primary_site_id');

        if (! empty($selects)) {
            $query->selectRaw(implode(', ', $selects));
        }

        return $query;
    }

    private function buildLeaveQuery(array $fields): Builder
    {
        $fieldMap = [
            'employee_name' => 'users.name as employee_name',
            'leave_type' => 'hr_leave_requests.leave_type',
            'start_date' => 'hr_leave_requests.starts_at as start_date',
            'end_date' => 'hr_leave_requests.ends_at as end_date',
            'hours_requested' => 'hr_leave_requests.hours_requested',
            'status' => 'hr_leave_requests.status',
            'reason' => 'hr_leave_requests.reason',
            'submitted_at' => 'hr_leave_requests.submitted_at',
            'reviewed_by' => 'reviewers.name as reviewed_by',
        ];

        $selects = [];
        foreach ($fields as $field) {
            if (isset($fieldMap[$field])) {
                $selects[] = $fieldMap[$field];
            }
        }

        $query = HrLeaveRequest::query()
            ->join('users', 'users.id', '=', 'hr_leave_requests.user_id')
            ->leftJoin('users as reviewers', 'reviewers.id', '=', 'hr_leave_requests.reviewed_by');

        if (! empty($selects)) {
            $query->selectRaw(implode(', ', $selects));
        }

        return $query;
    }

    private function buildComplianceQuery(array $fields): Builder
    {
        $fieldMap = [
            'employee_name' => 'users.name as employee_name',
            'requirement_name' => 'hr_compliance_requirements.name as requirement_name',
            'category' => 'hr_compliance_requirements.category',
            'status' => 'hr_compliance_matrix.status',
            'completed_at' => 'hr_compliance_matrix.completed_at',
            'expires_at' => 'hr_compliance_matrix.expires_at',
            'is_hard_stop' => 'hr_compliance_requirements.is_hard_stop',
        ];

        $selects = [];
        foreach ($fields as $field) {
            if (isset($fieldMap[$field])) {
                $selects[] = $fieldMap[$field];
            }
        }

        $query = HrComplianceRequirement::query()
            ->join('hr_compliance_matrix', 'hr_compliance_matrix.requirement_id', '=', 'hr_compliance_requirements.id')
            ->join('users', 'users.id', '=', 'hr_compliance_matrix.user_id');

        if (! empty($selects)) {
            $query->selectRaw(implode(', ', $selects));
        }

        return $query;
    }

    private function buildTimeQuery(array $fields): Builder
    {
        $fieldMap = [
            'employee_name' => 'users.name as employee_name',
            'entry_date' => 'hr_time_entries.entry_date',
            'clock_in' => 'hr_time_entries.clock_in',
            'clock_out' => 'hr_time_entries.clock_out',
            'total_hours' => 'hr_time_entries.total_hours',
            'break_minutes' => 'hr_time_entries.break_minutes',
            'status' => 'hr_time_entries.status',
            'entry_type' => 'hr_time_entries.entry_type',
        ];

        $selects = [];
        foreach ($fields as $field) {
            if (isset($fieldMap[$field])) {
                $selects[] = $fieldMap[$field];
            }
        }

        $query = HrTimeEntry::query()
            ->join('users', 'users.id', '=', 'hr_time_entries.user_id');

        if (! empty($selects)) {
            $query->selectRaw(implode(', ', $selects));
        }

        return $query;
    }

    private function buildTrainingQuery(array $fields): Builder
    {
        $fieldMap = [
            'employee_name' => 'users.name as employee_name',
            'course_title' => 'hr_courses.title as course_title',
            'category' => 'hr_courses.category',
            'status' => 'hr_course_enrollments.status',
            'enrolled_at' => 'hr_course_enrollments.created_at as enrolled_at',
            'completed_at' => 'hr_course_enrollments.completed_at',
            'score' => 'hr_course_enrollments.score',
        ];

        $selects = [];
        foreach ($fields as $field) {
            if (isset($fieldMap[$field])) {
                $selects[] = $fieldMap[$field];
            }
        }

        $query = HrCourseEnrollment::query()
            ->join('users', 'users.id', '=', 'hr_course_enrollments.user_id')
            ->join('hr_courses', 'hr_courses.id', '=', 'hr_course_enrollments.course_id');

        if (! empty($selects)) {
            $query->selectRaw(implode(', ', $selects));
        }

        return $query;
    }
}
