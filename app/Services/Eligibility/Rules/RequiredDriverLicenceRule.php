<?php

namespace App\Services\Eligibility\Rules;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class RequiredDriverLicenceRule implements EligibilityRuleInterface
{
    public function evaluate(Shift $shift, User $user): array
    {
        $requiredClass = trim((string) $shift->required_licence_class);
        $requiredEndorsements = collect($shift->required_licence_endorsements ?? [])
            ->map(fn ($endorsement) => strtoupper(trim((string) $endorsement)))
            ->filter()
            ->unique()
            ->values();

        if ($requiredClass === '' && $requiredEndorsements->isEmpty()) {
            return self::pass();
        }

        $tenantId = (int) ($shift->organization_id ?: $user->organization_id ?: 1);
        $eligibility = $user->relationLoaded('hrDriverEligibility')
            ? $user->hrDriverEligibility
            : HrDriverEligibility::query()->where('user_id', $user->id)->first();

        if (! $eligibility || (int) $eligibility->tenant_id !== $tenantId) {
            return self::block('No current driver eligibility record exists for this organisation.');
        }

        if ($eligibility->status !== 'eligible' || ! $eligibility->can_drive_clients) {
            return self::block('This worker is not currently approved for driving shifts.');
        }

        if (! $eligibility->licence_expires_at) {
            return self::block('The driving licence expiry date is not recorded.');
        }

        $dutyWindow = $this->plannedDutyWindow($shift);
        if ($dutyWindow === null) {
            return self::block('The planned shift duty window is invalid, so driver licence coverage cannot be verified.');
        }

        $licenceExpiresOn = $eligibility->licence_expires_at->toDateString();
        if ($licenceExpiresOn < $dutyWindow['starts_on']) {
            return self::block("The driving licence expires before this shift ({$eligibility->licence_expires_at->format('j M Y')}).");
        }

        if ($licenceExpiresOn < $dutyWindow['last_duty_on']) {
            return self::block("The driving licence does not remain valid for this entire shift (expires {$eligibility->licence_expires_at->format('j M Y')}).");
        }

        $heldClass = preg_replace('/^class\s+/i', '', trim((string) $eligibility->licence_class));

        if ($requiredClass !== '' && strtoupper($heldClass) !== strtoupper($requiredClass)) {
            return self::block("This shift requires a Class {$requiredClass} driving licence.");
        }

        $heldEndorsements = collect($eligibility->licence_endorsements ?? [])
            ->map(fn ($endorsement) => strtoupper(trim((string) $endorsement)))
            ->filter()
            ->unique();
        $missingEndorsements = $requiredEndorsements->diff($heldEndorsements)->values();

        if ($missingEndorsements->isNotEmpty()) {
            $labels = $missingEndorsements->map(fn (string $endorsement) => "{$endorsement} endorsement")->implode(', ');

            return self::block("This shift requires the missing {$labels}.");
        }

        return self::pass();
    }

    /** @return array{starts_on: string, last_duty_on: string}|null */
    private function plannedDutyWindow(Shift $shift): ?array
    {
        $startsAt = $this->resolveInstant($shift->starts_at);
        $endsAt = $this->resolveInstant($shift->ends_at);

        if (! $startsAt || ! $endsAt || $endsAt->lessThanOrEqualTo($startsAt)) {
            return null;
        }

        $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));

        return [
            'starts_on' => $startsAt->copy()->setTimezone($timezone)->toDateString(),
            'last_duty_on' => $endsAt->copy()->subMicrosecond()->setTimezone($timezone)->toDateString(),
        ];
    }

    private function resolveInstant(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function block(string $message): array
    {
        return [
            'rule' => 'required_driver_licence',
            'passed' => false,
            'severity' => 'block',
            'overrideable' => false,
            'message' => $message,
        ];
    }

    private static function pass(): array
    {
        return [
            'rule' => 'required_driver_licence',
            'passed' => true,
            'severity' => 'block',
            'overrideable' => false,
            'message' => null,
        ];
    }
}
