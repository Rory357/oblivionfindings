<?php

namespace App\Services\Medication\Recording;

use Illuminate\Validation\Rule;

/**
 * eMAR P01 — the one recording contract ("Record a dose: one pop-up
 * everywhere"). The approved option lists and the request fields every
 * recording path accepts on top of its own (Meds today, as-needed doses and
 * the mobile API), so they validate and store one shape.
 *
 * Every field is optional for now: the old dialogs keep posting until the
 * chunk that retires each one. The dialog itself (C2) always sends them.
 */
final class RecordingContract
{
    /** Outside the dose window (approved D4 / P01 Q3). */
    public const LATE_REASONS = [
        'out_or_asleep' => 'Person was out or asleep at the time',
        'waiting_second_person' => 'Waiting for a second person',
        'supporting_someone_else' => 'Staff were supporting someone else',
        'other' => 'Other',
    ];

    /** Why less than ordered was given (approved, P00 v4/v5). */
    public const AMOUNT_REASONS = [
        'part_taken' => 'Only part taken',
        'dropped_or_spilled' => 'Dropped or spilled',
        'vomited' => 'Vomited soon after',
        'other' => 'Other',
    ];

    /** Withheld reasons, as approved in plain words (NotGivenReason values; Q4). */
    public const WITHHELD_REASONS = [
        'doctors_instruction' => 'Doctor’s instruction',
        'fasting' => 'Fasting',
        'vomit_or_nausea' => 'Vomit or nausea',
        'medication_unavailable' => 'Medication unavailable',
        'withheld' => 'Safety concern — not safe to give',
        'other' => 'Other (say what happened)',
    ];

    /** Where the person is (stored as withheld with these codes; reads as Away). */
    public const AWAY_REASONS = [
        'absent' => 'Out (day programme, appointment or with family)',
        'social_leave' => 'Social leave',
        'hospitalised' => 'In hospital',
        'transferred' => 'Transferred',
    ];

    public const AMOUNT_AS_ORDERED = 'as_ordered';

    public const AMOUNT_LESS = 'less';

    public const AMOUNT_MORE = 'more';

    public const AMOUNT_MODES = [self::AMOUNT_AS_ORDERED, self::AMOUNT_LESS, self::AMOUNT_MORE];

    /** "How serious does it seem?" — the medication error severities a worker may choose. */
    public const MORE_SEVERITIES = ['minor', 'moderate', 'major', 'critical'];

    /** What the second person on a dose was for. */
    public const SECOND_WITNESS = 'witness';

    public const SECOND_RULE = 'rule';

    public const SECOND_COSIGNER = 'cosigner';

    public const SECOND_AMOUNT = 'amount';

    public const SECOND_VERIFIED = 'verified';

    public const SECOND_NOT_VERIFIED = 'not_verified';

    /** Nobody eligible on the roster could confirm it (Stephan Q2). */
    public const SECOND_NOT_CONFIRMED = 'not_confirmed';

    /** Why a P01 record waits for a house lead's review (C6 lists them). */
    public const REVIEW_SECOND_PERSON_NOT_CONFIRMED = 'second_person_not_confirmed';

    public const REVIEW_PARTIAL_DOSE_NOT_CONFIRMED = 'partial_dose_not_confirmed';

    public const REVIEW_KEYS = [
        self::REVIEW_SECOND_PERSON_NOT_CONFIRMED,
        self::REVIEW_PARTIAL_DOSE_NOT_CONFIRMED,
    ];

    /** The follow-up a refusal creates until the P08a re-offer rule exists. */
    public const REFUSAL_FOLLOW_UP_ACTION = 'Offer again, or record why not';

    /**
     * Validation rules shared by every recording path, on top of the path's
     * own fields.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'late_reason' => ['nullable', 'string', Rule::in(array_keys(self::LATE_REASONS))],
            'amount_mode' => ['nullable', 'string', Rule::in(self::AMOUNT_MODES)],
            'amount_reason' => ['nullable', 'string', Rule::in(array_keys(self::AMOUNT_REASONS))],
            'quantity_given' => ['nullable', 'numeric', 'min:0.01', 'max:10000', 'decimal:0,2'],
            'more_severity' => ['nullable', 'string', Rule::in(self::MORE_SEVERITIES)],
            'more_immediate_action' => ['nullable', 'string', 'max:2000'],
            'second_person_pin_forgotten' => ['nullable', 'boolean'],
            'second_person_unavailable' => ['nullable', 'boolean'],
            'reoffer_of_id' => ['nullable', 'integer', 'min:1'],
            'follow_up_due_at' => ['nullable', 'date'],
            'effect_check_due_at' => ['nullable', 'date'],
        ];
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return array_keys(self::rules());
    }
}
