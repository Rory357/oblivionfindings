<?php

return [
    // The completed person record and MAR/medicine/as-needed hubs are the default.
    // An explicit "legacy" override retains the earlier pages for rollout recovery.
    'person_record' => env('EMAR_PERSON_RECORD', 'p02'),

    // Pack writers and the controlled ledger share the stock workflow. Existing
    // stock needs an explicit counted opening; enabling never invents a balance.
    // The environment override remains available for rollout recovery.
    'stock_lots_enabled' => env('MEDICATION_STOCK_LOTS_ENABLED', true),

    // Dose timing defaults. Medication › Settings › Rounds & timing saves the
    // organisation's own values; DoseTimingSettings reads them, falling back
    // to these until someone saves one.
    'mar' => [
        // How early a scheduled dose can be administered (minutes before scheduled time)
        'window_before_minutes' => 30,

        // How late a scheduled dose can be administered before it is flagged as late (minutes after scheduled time)
        'window_after_minutes' => 60,

        // When to mark a scheduled dose as "due soon" (minutes before scheduled time)
        'due_soon_minutes' => 60,

        // A dose recorded this many minutes after its scheduled time raises a late-dose incident
        'late_incident_minutes' => 120,
    ],

    // This many refusals or withholds of the same medicine within this many
    // days escalate to a manager and the GP.
    'refusal_escalation' => [
        'count' => 3,
        'days' => 7,
    ],

    // Medication competency and exemption defaults (Settings › Staff & PINs;
    // CompetencyPolicySettings reads the saved values).
    'competency' => [
        // An assessment stays current for at most this many months
        'validity_months' => 12,
        // Areas (of 12) that must be passed
        'pass_mark' => 10,
        // Renewal is due this many days before an assessment ends
        'renewal_reminder_days' => 30,
        // An exemption lasts at most this many days
        'longest_exemption_days' => 30,
    ],

    'witness_pin' => [
        // Ask for the login password before someone sets a witness PIN while
        // they have no usable one (not set yet, reset by a lead, or needing
        // renewal), so a colleague at an unlocked session can't choose it for
        // them. On by default (Stephan, 30 Sep 2026: approved with PIN-1).
        'login_check_to_set' => (bool) env('MEDICATION_WITNESS_PIN_LOGIN_CHECK', true),

        // Eligible non-controlled doses can request confirmation in the colleague's
        // own session. Pending, disputed and expired states remain explicit.
        'forgotten_fallback_enabled' => (bool) env('MEDICATION_FORGOTTEN_PIN_ENABLED', true),

        // Server-managed PIN pepper; never reuse the login/session APP_KEY.
        // Existing pin_v1 hashes remain valid until the owner sets a new PIN.
        'pepper' => env('MEDICATION_WITNESS_PIN_PEPPER'),
    ],

    // Away (P01 C7): which records say a person is away, so a dose due then
    // reads "Away · reason" and is never chased (DoseAwaySources).
    'away' => [
        // Only actual, approved departure/return evidence creates Away.
        // Planned or legacy date-only leave never suppresses a due dose.
        'from_leave' => (bool) env('MEDICATION_AWAY_FROM_LEAVE', true),
    ],
];
