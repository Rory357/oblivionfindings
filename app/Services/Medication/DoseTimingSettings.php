<?php

namespace App\Services\Medication;

use App\Services\Medication\Settings\ReadsWholeNumberSettings;
use Illuminate\Container\Attributes\Scoped;

/**
 * Dose timing for every house (eMAR P11 Rounds & timing): when a scheduled
 * dose can be given, shows as due soon and counts as late, when a late dose
 * raises an incident, and when repeated refusals escalate.
 *
 * This is the one reader. MarScheduleService, the dose window resolver, the
 * late-dose incident and the refusal escalation all ask it; nothing else
 * reads these values. The defaults are today's (config/medications.php,
 * Stephan 29 Sep 2026: keep them until the clinical lead reviews them) and
 * show as "Default — not yet reviewed" until someone saves or keeps them.
 * Recording a dose is never blocked by these times.
 *
 * Read once per request: the container keeps one instance per request or
 * queued job (#[Scoped]) and it reads every timing value in one query. A save
 * of a timing key through AppSetting makes the request's instance re-read
 * (AppSetting's saved/deleted events call settingChanged()).
 */
#[Scoped]
class DoseTimingSettings
{
    use ReadsWholeNumberSettings;

    public const EARLY_MINUTES = 'medications.mar.window_before_minutes';

    public const LATE_MINUTES = 'medications.mar.window_after_minutes';

    public const DUE_SOON_MINUTES = 'medications.mar.due_soon_minutes';

    public const LATE_INCIDENT_MINUTES = 'medications.mar.late_incident_minutes';

    public const REFUSAL_COUNT = 'medications.refusal_escalation.count';

    public const REFUSAL_DAYS = 'medications.refusal_escalation.days';

    /** Whole numbers each value accepts, smallest and largest. */
    public const RANGES = [
        self::EARLY_MINUTES => [1, 1440],
        self::LATE_MINUTES => [1, 1440],
        self::DUE_SOON_MINUTES => [1, 1440],
        self::LATE_INCIDENT_MINUTES => [1, 1440],
        self::REFUSAL_COUNT => [1, 50],
        self::REFUSAL_DAYS => [1, 90],
    ];

    protected const CONFIG = [
        self::EARLY_MINUTES => ['medications.mar.window_before_minutes', 30],
        self::LATE_MINUTES => ['medications.mar.window_after_minutes', 60],
        self::DUE_SOON_MINUTES => ['medications.mar.due_soon_minutes', 60],
        self::LATE_INCIDENT_MINUTES => ['medications.mar.late_incident_minutes', 120],
        self::REFUSAL_COUNT => ['medications.refusal_escalation.count', 3],
        self::REFUSAL_DAYS => ['medications.refusal_escalation.days', 7],
    ];

    /** Minutes before the dose time a dose can be given from. */
    public function earlyMinutes(): int
    {
        return $this->value(self::EARLY_MINUTES);
    }

    /** Minutes after the dose time a dose counts as late. */
    public function lateMinutes(): int
    {
        return $this->value(self::LATE_MINUTES);
    }

    /** Minutes before the dose time a dose shows as due soon. */
    public function dueSoonMinutes(): int
    {
        return $this->value(self::DUE_SOON_MINUTES);
    }

    /** Minutes after the dose time a dose raises a late-dose incident. */
    public function lateIncidentMinutes(): int
    {
        return $this->value(self::LATE_INCIDENT_MINUTES);
    }

    /** Refusals or withholds of one medicine that escalate… */
    public function refusalEscalationCount(): int
    {
        return $this->value(self::REFUSAL_COUNT);
    }

    /** …within this many days. */
    public function refusalEscalationDays(): int
    {
        return $this->value(self::REFUSAL_DAYS);
    }

}
