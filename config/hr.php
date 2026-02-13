<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fatigue Rules
    |--------------------------------------------------------------------------
    */
    'fatigue' => [
        'max_hours_per_day' => env('HR_MAX_HOURS_PER_DAY', 12),
        'max_hours_per_week' => env('HR_MAX_HOURS_PER_WEEK', 50),
        'warning_threshold_weekly' => env('HR_WARNING_HOURS_WEEKLY', 40),
        'min_rest_between_shifts_hours' => env('HR_MIN_REST_HOURS', 10),
        'max_consecutive_days' => env('HR_MAX_CONSECUTIVE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave Types
    |--------------------------------------------------------------------------
    */
    'leave_types' => [
        'annual' => ['label' => 'Annual Leave', 'accrual' => true],
        'sick' => ['label' => 'Sick Leave', 'accrual' => true],
        'bereavement' => ['label' => 'Bereavement Leave', 'accrual' => false],
        'parental' => ['label' => 'Parental Leave', 'accrual' => false],
        'lieu' => ['label' => 'Time in Lieu', 'accrual' => false],
        'other' => ['label' => 'Other Leave', 'accrual' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Periods (months)
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'unsuccessful_candidate_months' => env('HR_RETENTION_CANDIDATE_MONTHS', 24),
        'employee_records_years' => env('HR_RETENTION_EMPLOYEE_YEARS', 7),
        'vetting_records_years' => env('HR_RETENTION_VETTING_YEARS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Police Vetting
    |--------------------------------------------------------------------------
    */
    'vetting' => [
        'default_validity_months' => env('HR_VETTING_VALIDITY_MONTHS', 36),
        'reminder_days_before_expiry' => env('HR_VETTING_REMINDER_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Employee Number Format
    |--------------------------------------------------------------------------
    */
    'employee_number_prefix' => env('HR_EMPLOYEE_NUMBER_PREFIX', 'EMP'),

    /*
    |--------------------------------------------------------------------------
    | Wellbeing Indicator Thresholds
    |--------------------------------------------------------------------------
    */
    'wellbeing' => [
        'monitor' => [
            'overtime_hours_weekly' => 10,
            'consecutive_days' => 5,
            'sick_leave_days_90d' => 6,
        ],
        'concern' => [
            'overtime_hours_weekly' => 20,
            'consecutive_days' => 7,
            'sick_leave_days_90d' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payroll Export
    |--------------------------------------------------------------------------
    */
    'payroll' => [
        'default_format' => env('HR_PAYROLL_FORMAT', 'csv'),
    ],
];
