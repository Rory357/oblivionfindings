<?php

namespace App\Services\Eligibility;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Read-only evidence validity for the whole duty, including overnight duties. */
final class WorkforceQualificationDutyWindow
{
    public function __construct(
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        private readonly string $timezone,
    ) {}

    public static function forShift(?Shift $shift = null): self
    {
        $startsAt = CarbonImmutable::instance($shift?->starts_at ?? now());

        return new self(
            $startsAt,
            CarbonImmutable::instance($shift?->ends_at ?? $startsAt),
            (string) (config('app.worker_timezone') ?: (config('app.timezone') ?: 'UTC')),
        );
    }

    public function lastDutyDate(): string
    {
        // Duty end is exclusive: ending at midnight does not require tomorrow's evidence.
        return ($this->endsAt->greaterThan($this->startsAt)
            ? $this->endsAt->subMicrosecond()
            : $this->endsAt)->setTimezone($this->timezone)->toDateString();
    }

    public function notYetValid(?CarbonInterface $validFrom, bool $dateOnly = false): bool
    {
        if (! $validFrom) {
            return false;
        }

        return $dateOnly
            ? $validFrom->toDateString() > $this->startsAt->setTimezone($this->timezone)->toDateString()
            : $validFrom->greaterThan($this->startsAt);
    }

    public function expired(?CarbonInterface $expiresAt, bool $dateOnly = false, int $graceDays = 0): bool
    {
        if (! $expiresAt) {
            return false;
        }

        $expiry = CarbonImmutable::instance($expiresAt)->addDays(max(0, $graceDays));

        return $dateOnly
            ? $expiry->toDateString() < $this->lastDutyDate()
            : $expiry->lessThan($this->endsAt);
    }

    public function statusFor(?HrStaffComplianceStatus $status, int $graceDays = 0): string
    {
        if (! $status) {
            return 'not_started';
        }

        if (filled($status->exemption_reason) && $status->exempted_at) {
            if ($this->notYetValid($status->exempted_at)) {
                return 'not_started';
            }

            return $this->expired($status->exempted_until, dateOnly: true) ? 'expired' : 'compliant';
        }

        if ($this->notYetValid($status->valid_from, dateOnly: true)) {
            return 'not_started';
        }

        // An explicit withdrawal/non-compliance is never softened by an expiry grace.
        if (! in_array($status->status, ['compliant', 'expiring_soon', 'expired'], true)) {
            return $status->status === 'not_started' ? 'not_started' : 'unknown';
        }

        if ($this->expired($status->expires_at, dateOnly: true)) {
            return $graceDays > 0 && ! $this->expired($status->expires_at, dateOnly: true, graceDays: $graceDays)
                ? 'expiring_soon'
                : 'expired';
        }

        return $status->status;
    }
}
