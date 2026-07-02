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
        'family_violence' => ['label' => 'Family Violence Leave', 'accrual' => true],
        'parental' => ['label' => 'Parental Leave', 'accrual' => false],
        'public_holiday' => ['label' => 'Public Holiday', 'accrual' => false],
        'alternative' => ['label' => 'Alternative Holiday', 'accrual' => false],
        'toil' => ['label' => 'Time in Lieu (TOIL)', 'accrual' => false],
        'unpaid' => ['label' => 'Unpaid Leave', 'accrual' => false],
        'other' => ['label' => 'Other Leave', 'accrual' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave Engine Defaults
    |--------------------------------------------------------------------------
    | Simplified hours-based model: annual/sick/family-violence entitlements
    | accrue in monthly twelfths. The Holidays Act 2003 expresses annual leave
    | in weeks (4 weeks after 12 months) and sick/family-violence leave as
    | lump-sum day entitlements (10 days after 6 months) — see
    | docs/hr-nz-statutory-notes.md before relying on these balances for
    | statutory compliance. Alternative holidays are accrued automatically
    | when an approved timesheet is worked on a public holiday.
    */
    'leave' => [
        'full_time_hours_per_week' => env('HR_FULL_TIME_HOURS_PER_WEEK', 40),
        'accrual_types' => ['annual', 'sick', 'family_violence'],
        'default_entitlements' => [
            'annual' => env('HR_ANNUAL_LEAVE_HOURS', 152), // 4 weeks @ 38h
            'sick' => env('HR_SICK_LEAVE_HOURS', 80), // 10 days @ 8h
            'bereavement' => env('HR_BEREAVEMENT_LEAVE_HOURS', 24),
            'family_violence' => env('HR_FAMILY_VIOLENCE_LEAVE_HOURS', 80), // 10 days @ 8h
            'parental' => 0,
            'public_holiday' => 0,
            'alternative' => 0, // accrued per worked public holiday, not entitlement-based
            'unpaid' => 0,
            'toil' => 0,
            'other' => 0,
        ],
        'carryover_caps' => [
            'annual' => env('HR_ANNUAL_CARRYOVER_CAP', 80),
            'sick' => env('HR_SICK_CARRYOVER_CAP', 40),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Periods (months)
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'unsuccessful_candidate_months' => env('HR_RETENTION_CANDIDATE_MONTHS', 24),
        'anonymise_candidates_before_archive' => env('HR_ANONYMISE_CANDIDATES_ON_ARCHIVE', true),
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

    'expiry_reminder_days' => [90, 60, 30, 14, 7],

    // Days after a policy version requiring attestation is published before
    // staff who still haven't attested receive an overdue nudge.
    'policy_attestation_overdue_days' => env('HR_POLICY_ATTESTATION_OVERDUE_DAYS', 7),

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
    | Engagement Action Plans
    |--------------------------------------------------------------------------
    */
    'engagement' => [
        'action_plan_reminder_days_before' => [14, 7, 3, 1, 0],
        'action_plan_overdue_escalation_days' => [1, 3, 7],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payroll Export
    |--------------------------------------------------------------------------
    */
    'payroll' => [
        'default_format' => env('HR_PAYROLL_FORMAT', 'csv'),
        'overtime_daily_hours' => env('HR_PAYROLL_OVERTIME_DAILY_HOURS', 8),
        'default_regular_multiplier' => env('HR_PAYROLL_REGULAR_MULTIPLIER', 1.00),
        'default_overtime_multiplier' => env('HR_PAYROLL_OVERTIME_MULTIPLIER', 1.50),
        'default_public_holiday_multiplier' => env('HR_PAYROLL_PUBLIC_HOLIDAY_MULTIPLIER', 1.50),
        'default_sleepover_flat_rate' => env('HR_PAYROLL_SLEEP_OVER_FLAT_RATE', 0),
        'default_on_call_hourly_rate' => env('HR_PAYROLL_ON_CALL_HOURLY_RATE', 0),
    ],

    'candidate_retention_months' => env('HR_RETENTION_CANDIDATE_MONTHS', 24),
];
