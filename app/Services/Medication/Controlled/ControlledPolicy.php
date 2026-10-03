<?php

namespace App\Services\Medication\Controlled;

use App\Models\AppSetting;
use App\Models\ClientMedication;
use App\Models\MedicationSiteSetting;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * P07's single reader for controlled-dose witness policy and count timing.
 * Explicit order requirements always win. Register counts and movements have
 * their own mandatory witness check; disabling dose witnessing never waives it.
 * This service does not grant an override or decide clinical medicine classes.
 */
class ControlledPolicy
{
    public const WITNESS_REQUIRED = 'medications.controlled.witness_required';

    public const HOUSE_WITNESS = 'medications.controlled.house_witness';

    public const COUNT_CADENCE = 'medications.controlled.count_cadence';

    public const COUNT_OVERDUE_MINUTES = 'medications.controlled.count_overdue_minutes';

    public const ONSITE_DESTRUCTION = 'medications.controlled.onsite_destruction';

    public const CADENCES = ['shift', 'day', 'week'];

    public const COUNT_DUE_BEFORE_MINUTES = 30;

    public const DEFAULT_OVERDUE_MINUTES = 60;

    /** The canonical dose requirement. An override is separately authorised. */
    public function witnessRequired(ClientMedication $medication): bool
    {
        if ((bool) $medication->witness_required) {
            return true;
        }
        if (! (bool) $medication->controlled_drug) {
            return false;
        }

        return $this->doseWitnessRequiredAt($this->siteId($medication));
    }

    /**
     * The house follows the organisation until it deliberately chooses Always
     * or Not required (P11 v5 ControlledDrugs). Missing values retain the
     * approved default: witnessing on, with each house following it.
     */
    public function doseWitnessRequiredAt(?int $siteId): bool
    {
        $organisation = $this->organisationValue(self::WITNESS_REQUIRED);
        $organisationRequired = $organisation !== 'off';
        if ($siteId === null) {
            return $organisationRequired;
        }

        return match ($this->houseValue($siteId, self::HOUSE_WITNESS)) {
            'on' => true,
            'off' => false,
            default => $organisationRequired,
        };
    }

    /**
     * One organisation cadence, applied to the site's actual roster. Until a
     * valid cadence is deliberately stored, no count is due or overdue.
     */
    public function cadence(?int $siteId = null): ?string
    {
        $value = $this->organisationValue(self::COUNT_CADENCE);

        return is_string($value) && in_array($value, self::CADENCES, true) ? $value : null;
    }

    /** On-site denaturing starts off and requires explicit organisation policy. */
    public function onsiteAllowed(?int $siteId = null): bool
    {
        return $this->organisationValue(self::ONSITE_DESTRUCTION) === 'on';
    }

    public function overdueMinutes(): int
    {
        $value = $this->organisationValue(self::COUNT_OVERDUE_MINUTES);
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match('/^\d{1,4}$/', $value) === 1
            && (int) $value >= 1 && (int) $value <= 1440
            ? (int) $value : self::DEFAULT_OVERDUE_MINUTES;
    }

    /**
     * $lastCount is the latest completed, witnessed count, never a draft.
     * Times returned include offsets and can be shown with shared NZ helpers.
     * The caller owns person/site access and controlled-record concealment.
     *
     * @return array{status: string, cadence: string|null, change_at: string|null, due_at: string|null, overdue_at: string|null, next_change_at: string|null, last_count_at: string|null}
     */
    public function countStatus(ClientMedication $medication, DateTimeInterface $now, ?DateTimeInterface $lastCount = null): array
    {
        if (! (bool) $medication->controlled_drug) {
            return $this->emptyStatus('not_applicable', null, $lastCount);
        }
        $siteId = $this->siteId($medication);
        $cadence = $this->cadence($siteId);
        if ($cadence === null) {
            return $this->emptyStatus('not_configured', null, $lastCount);
        }
        if ($siteId === null || $cadence === 'week') {
            // The weekly weekday/anchor was never approved. Do not invent it.
            return $this->emptyStatus('schedule_unavailable', $cadence, $lastCount);
        }

        $clock = CarbonImmutable::instance($now)->setTimezone($this->timezone());
        $from = $clock->startOfDay()->subDays(8);
        $until = $clock->startOfDay()->addDays(8);
        $changes = $this->rosterChanges($siteId, $from, $until);

        return $this->countStatusForChanges($cadence, $now, $lastCount, $changes, $this->overdueMinutes(), $this->timezone(), $medication->created_at);
    }

    /**
     * Pure evaluator, also usable by batched payload adapters after loading a
     * site's roster once. Duplicate staff starts are one house shift change.
     * A count in the 30-minute due window covers that upcoming change. Daily
     * uses the earliest actual morning start on each NZ date, never a fixed
     * invented hour. Weekly remains unavailable without a reviewed anchor.
     *
     * @param  iterable<DateTimeInterface>  $changes
     * @return array{status: string, cadence: string|null, change_at: string|null, due_at: string|null, overdue_at: string|null, next_change_at: string|null, last_count_at: string|null}
     */
    public function countStatusForChanges(string $cadence, DateTimeInterface $now, ?DateTimeInterface $lastCount, iterable $changes, int $overdueMinutes = self::DEFAULT_OVERDUE_MINUTES, string $timezone = 'Pacific/Auckland', ?DateTimeInterface $notBefore = null): array
    {
        if (! in_array($cadence, self::CADENCES, true)) {
            return $this->emptyStatus('not_configured', null, $lastCount);
        }
        if ($cadence === 'week') {
            return $this->emptyStatus('schedule_unavailable', $cadence, $lastCount);
        }
        $clock = CarbonImmutable::instance($now)->setTimezone($timezone);
        $last = $lastCount === null ? null : CarbonImmutable::instance($lastCount)->setTimezone($timezone);
        if ($last?->greaterThan($clock)) {
            // Future-dated evidence cannot satisfy today's count.
            $last = null;
        }
        $boundaries = [];
        foreach ($changes as $change) {
            $at = CarbonImmutable::instance($change)->setTimezone($timezone);
            $boundaries[$at->getTimestamp()] = $at;
        }
        ksort($boundaries);
        if ($cadence === 'day') {
            $morningByDate = [];
            foreach ($boundaries as $at) {
                if ($at->hour < 12) {
                    $morningByDate[$at->toDateString()] ??= $at;
                }
            }
            $boundaries = $morningByDate;
        }
        if ($notBefore !== null) {
            // Select the house's cadence before removing changes from before
            // this order existed; a later staff start is not a new daily count.
            $boundaries = array_filter($boundaries, fn (CarbonImmutable $at): bool => $at->greaterThanOrEqualTo($notBefore));
        }
        if ($boundaries === []) {
            return $this->emptyStatus('schedule_unavailable', $cadence, $lastCount);
        }

        $current = null;
        $next = null;
        foreach ($boundaries as $at) {
            if ($at->subRealMinutes(self::COUNT_DUE_BEFORE_MINUTES)->lessThanOrEqualTo($clock)) {
                $current = $at;
            } else {
                $next = $at;
                break;
            }
        }
        $change = $current ?? $next;
        $due = $change->subRealMinutes(self::COUNT_DUE_BEFORE_MINUTES);
        $overdue = $change->addRealMinutes(max(1, $overdueMinutes));
        $status = $current === null ? 'upcoming' : 'due';
        if ($current !== null && $last !== null && $last->greaterThanOrEqualTo($due)) {
            $status = 'complete';
        } elseif ($current !== null && $clock->greaterThanOrEqualTo($overdue)) {
            $status = 'overdue';
        }

        return [
            'status' => $status,
            'cadence' => $cadence,
            'change_at' => $change->toIso8601String(),
            'due_at' => $due->toIso8601String(),
            'overdue_at' => $overdue->toIso8601String(),
            'next_change_at' => $next?->toIso8601String(),
            'last_count_at' => $lastCount === null ? null : CarbonImmutable::instance($lastCount)->toIso8601String(),
        ];
    }

    protected function siteId(ClientMedication $medication): ?int
    {
        $siteId = $medication->client?->site_id;

        return $siteId === null ? null : (int) $siteId;
    }

    protected function organisationValue(string $key): mixed
    {
        return AppSetting::query()->where('key', $key)->value('value');
    }

    protected function houseValue(int $siteId, string $key): mixed
    {
        return MedicationSiteSetting::query()->where('site_id', $siteId)->where('key', $key)->value('value');
    }

    protected function timezone(): string
    {
        return config('app.worker_timezone', 'Pacific/Auckland');
    }

    /** @return list<CarbonImmutable> */
    protected function rosterChanges(int $siteId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return Shift::query()
            ->where(function ($site) use ($siteId): void {
                $site->where('site_id', $siteId)
                    ->orWhere(function ($derived) use ($siteId): void {
                        $derived->whereNull('site_id')
                            ->whereHas('client', fn ($clients) => $clients->where('site_id', $siteId));
                    });
            })
            ->whereIn('status', ['scheduled', 'in_progress', 'completed'])
            ->where(fn ($query) => $query->where('is_on_call', false)->orWhereNull('is_on_call'))
            ->whereBetween('starts_at', [$from->utc(), $until->utc()])
            ->orderBy('starts_at')
            ->get(['starts_at'])
            ->map(fn (Shift $shift): CarbonImmutable => CarbonImmutable::instance($shift->starts_at))
            ->all();
    }

    /** @return array{status: string, cadence: string|null, change_at: null, due_at: null, overdue_at: null, next_change_at: null, last_count_at: string|null} */
    private function emptyStatus(string $status, ?string $cadence, ?DateTimeInterface $lastCount): array
    {
        return [
            'status' => $status,
            'cadence' => $cadence,
            'change_at' => null,
            'due_at' => null,
            'overdue_at' => null,
            'next_change_at' => null,
            'last_count_at' => $lastCount === null ? null : CarbonImmutable::instance($lastCount)->toIso8601String(),
        ];
    }
}
