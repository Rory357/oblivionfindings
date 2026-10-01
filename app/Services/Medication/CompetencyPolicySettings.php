<?php

namespace App\Services\Medication;

use App\Services\Medication\Settings\ReadsWholeNumberSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Scoped;

/**
 * Medication competency and exemption limits (eMAR P11 Staff & PINs): how
 * long an assessment stays current, the pass mark, whether every core area
 * must pass, the observed administrations an assessment needs, when renewal
 * is due, and the longest exemption.
 *
 * This is the one reader. The assessment form and its saves, the renewal
 * alert, the rostering warning, the competency register and reports, and
 * exemptions all ask it. The defaults are today's (config/medications.php)
 * and show as "Default — not yet reviewed" until someone saves or keeps them;
 * the observed minimum stays off until the organisation chooses a number.
 * Existing assessments and exemptions keep the end dates they were given.
 * Read once per request, like DoseTimingSettings.
 */
#[Scoped]
class CompetencyPolicySettings
{
    use ReadsWholeNumberSettings;

    public const VALIDITY_MONTHS = 'medications.competency.validity_months';

    public const PASS_MARK = 'medications.competency.pass_mark';

    public const RENEWAL_REMINDER_DAYS = 'medications.competency.renewal_reminder_days';

    public const LONGEST_EXEMPTION_DAYS = 'medications.competency.longest_exemption_days';

    /** 'yes' or 'no': must every core area pass, as well as the pass mark? */
    public const CORE_AREAS_MUST_PASS = 'medications.competency.core_areas_must_pass';

    /** Observed administrations an assessment must log, or off. */
    public const OBSERVED_MINIMUM = 'medications.competency.observed_minimum';

    public const OBSERVED_MINIMUM_OFF = 'off';

    /** The 12 areas an assessment covers; the pass mark counts these. */
    public const AREA_COUNT = 12;

    /** Knowledge, the five rights, safety checks, documentation, errors, allergies. */
    public const CORE_AREAS = [
        'medication_knowledge',
        'five_rights',
        'safety_checks',
        'documentation',
        'error_reporting',
        'allergy_awareness',
    ];

    public const RANGES = [
        self::VALIDITY_MONTHS => [1, 36],
        self::PASS_MARK => [1, self::AREA_COUNT],
        self::RENEWAL_REMINDER_DAYS => [1, 180],
        self::LONGEST_EXEMPTION_DAYS => [1, 90],
    ];

    /** Observed administrations: 1 to 100, when switched on. */
    public const OBSERVED_RANGE = [1, 100];

    protected const CONFIG = [
        self::VALIDITY_MONTHS => ['medications.competency.validity_months', 12],
        self::PASS_MARK => ['medications.competency.pass_mark', 10],
        self::RENEWAL_REMINDER_DAYS => ['medications.competency.renewal_reminder_days', 30],
        self::LONGEST_EXEMPTION_DAYS => ['medications.competency.longest_exemption_days', 30],
    ];

    /** Months an assessment stays current, at most (the assessor can choose earlier). */
    public function validityMonths(): int
    {
        return $this->value(self::VALIDITY_MONTHS);
    }

    /** Areas (of 12) that must be passed. */
    public function passMark(): int
    {
        return $this->value(self::PASS_MARK);
    }

    /** Days before an assessment ends that renewal is due. */
    public function renewalReminderDays(): int
    {
        return $this->value(self::RENEWAL_REMINDER_DAYS);
    }

    /** Days an exemption can last, at most. */
    public function longestExemptionDays(): int
    {
        return $this->value(self::LONGEST_EXEMPTION_DAYS);
    }

    /** Must every core area pass? Off by default: only the pass mark counts. */
    public function coreAreasMustPass(): bool
    {
        return $this->stored(self::CORE_AREAS_MUST_PASS) === 'yes';
    }

    /** Observed administrations an assessment must log, or null when off. */
    public function observedMinimum(): ?int
    {
        $stored = $this->stored(self::OBSERVED_MINIMUM);
        $stored = is_int($stored) ? (string) $stored : $stored;
        [$min, $max] = self::OBSERVED_RANGE;

        return is_string($stored) && preg_match('/^\d{1,6}$/', $stored) === 1 && (int) $stored >= $min && (int) $stored <= $max
            ? (int) $stored
            : null;
    }

    /** The latest end date an assessment made on this date can have. */
    public function latestExpiry(CarbonInterface|string $assessmentDate): CarbonImmutable
    {
        return CarbonImmutable::parse($assessmentDate)->startOfDay()->addMonthsNoOverflow($this->validityMonths());
    }

    /**
     * Do these area results pass? An area not assessed counts as not passed.
     *
     * @param  array<string, mixed>  $areas  area key => passed
     */
    public function passes(array $areas): bool
    {
        $passed = fn (string $key): bool => ! empty($areas[$key]);
        $count = collect($areas)->keys()->filter(fn (string $key): bool => $passed($key))->count();

        return $count >= $this->passMark()
            && (! $this->coreAreasMustPass() || collect(self::CORE_AREAS)->every($passed));
    }

    protected static function storedKeys(): array
    {
        return [...array_keys(self::RANGES), self::CORE_AREAS_MUST_PASS, self::OBSERVED_MINIMUM];
    }
}
