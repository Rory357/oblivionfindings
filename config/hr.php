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
    | Leave Policies (NZ Holidays Act 2003)
    |--------------------------------------------------------------------------
    */
    'leave_policies' => [
        'annual' => [
            'entitlement_weeks' => 4,
            'min_months_for_entitlement' => 12,
            'casual_rate' => 0.08, // 8% holiday pay for casuals
        ],
        'sick' => [
            'days_per_year' => 10,
            'min_months_for_entitlement' => 6,
            'max_carry_over_days' => 20,
        ],
        'bereavement' => [
            'close_relative_days' => 3,
            'other_days' => 1,
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Time Tracking
    |--------------------------------------------------------------------------
    */
    'time_tracking' => [
        'default_break_minutes' => env('HR_DEFAULT_BREAK_MINUTES', 30),
        'auto_clock_out_hours' => env('HR_AUTO_CLOCK_OUT_HOURS', 12),
        'timesheet_period' => env('HR_TIMESHEET_PERIOD', 'fortnightly'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compensation
    |--------------------------------------------------------------------------
    */
    'compensation' => [
        'currency' => env('HR_CURRENCY', 'NZD'),
        'review_cycle_months' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Benefits
    |--------------------------------------------------------------------------
    */
    'benefits' => [
        'default_kiwisaver_rate' => 3.00,
        'employer_kiwisaver_rate' => 3.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Training
    |--------------------------------------------------------------------------
    */
    'training' => [
        'completion_auto_updates_compliance' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    */
    'assets' => [
        'auto_generate_tag_prefix' => env('HR_ASSET_TAG_PREFIX', 'AST'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Surveys
    |--------------------------------------------------------------------------
    */
    'surveys' => [
        'enps_reminder_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Expenses
    |--------------------------------------------------------------------------
    */
    'expenses' => [
        'claim_number_prefix' => env('HR_EXPENSE_PREFIX', 'EXP'),
        'max_receipt_size_kb' => 10240,
    ],
];
