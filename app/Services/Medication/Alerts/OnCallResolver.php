<?php

namespace App\Services\Medication\Alerts;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\MedicationOnCallRule;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Who a house's on-call contact is (eMAR P11 B2 chunk 4; Stephan, 29 Sep:
 * an employed staff member, following the roster).
 *
 * Following the roster: whoever is on an on-call shift at the house, then —
 * if chosen — someone with the Team lead role on shift there (Q8), then the
 * backup person. "Always the same person": the backup. A backup on approved
 * leave gives nobody ("Nobody — <name> is on leave"). Worked out at the
 * moment it's needed, from the roster and staff records; only the rule and
 * the backup's account are stored.
 *
 * The work phone is used first; a personal cellphone is used only when
 * that person explicitly consented in their own account (P11 Q10).
 */
class OnCallResolver
{
    /** After hours, for "who staff will see": 5:00 pm to 7:00 am (v5). */
    public const NIGHT_FROM = '17:00';

    public const NIGHT_UNTIL = '07:00';

    public const NIGHT_HOURS = '5:00 pm – 7:00 am';

    /** @var array<int, MedicationOnCallRule|null> */
    private array $rules = [];

    /** The house's rule, or null when it isn't configured. */
    public function rule(int $siteId): ?MedicationOnCallRule
    {
        if (! array_key_exists($siteId, $this->rules)) {
            $this->rules[$siteId] = MedicationOnCallRule::query()->with('backup.hrEmployeeProfile')->where('site_id', $siteId)->first();
        }

        return $this->rules[$siteId];
    }

    /**
     * The on-call contact at this moment (alerts and escalations).
     *
     * @return array{user: User|null, how: string, warning: string|null, configured: bool}
     */
    public function at(int $siteId, CarbonInterface $at): array
    {
        $from = Carbon::instance($at);

        return $this->resolve($this->rule($siteId), $siteId, $from, $from->copy()->addSecond());
    }

    /**
     * Who staff will see after hours, night by night from today (NZ).
     *
     * @return list<array{label: string, hours: string, user: User|null, how: string, warning: string|null}>
     */
    public function nights(int $siteId, CarbonInterface $now, int $count = 3, ?MedicationOnCallRule $rule = null): array
    {
        $rule ??= $this->rule($siteId);

        return array_map(function (array $night) use ($rule, $siteId): array {
            $resolved = $this->resolve($rule, $siteId, $night['from'], $night['until']);

            return [
                'label' => $night['label'],
                'hours' => $night['hours'],
                'user' => $resolved['user'],
                'how' => $resolved['how'],
                'warning' => $resolved['warning'],
            ];
        }, $this->windows($now, $count));
    }

    /**
     * The roster for the next nights at the house — who is on an on-call shift
     * and which team lead is on shift — for previewing a rule before it's saved.
     *
     * @return list<array{label: string, hours: string, from: Carbon, until: Carbon, on_call: User|null, team_lead: User|null}>
     */
    public function roster(int $siteId, CarbonInterface $now, int $count = 3): array
    {
        return array_map(fn (array $night): array => [
            ...$night,
            'on_call' => $this->onCallAt($siteId, $night['from'], $night['until']),
            'team_lead' => $this->leadAt($siteId, $night['from'], $night['until']),
        ], $this->windows($now, $count));
    }

    /**
     * The roster for several houses at once (Settings): one query for every
     * shift in the next nights at those houses, then the same choice as
     * roster() — the earliest on-call shift, the earliest team lead on shift.
     *
     * @param  list<int>  $siteIds
     * @return array<int, list<array{label: string, hours: string, from: Carbon, until: Carbon, on_call: User|null, team_lead: User|null}>>
     */
    public function rosters(array $siteIds, CarbonInterface $now, int $count = 3): array
    {
        if ($siteIds === []) {
            return [];
        }
        $windows = $this->windows($now, $count);
        $shifts = Shift::query()
            ->visibleToFrontline()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereNotNull('user_id')
            ->where('starts_at', '<', end($windows)['until'])
            ->where('ends_at', '>', $windows[0]['from'])
            ->where(fn ($house) => $house
                ->whereIn('site_id', $siteIds)
                ->orWhere(fn ($viaClient) => $viaClient
                    ->whereNull('site_id')
                    ->whereHas('client', fn ($client) => $client->whereIn('site_id', $siteIds))))
            ->whereHas('staff', fn ($u) => $u->whereNotNull('approved_at'))
            ->with(['staff.roles:id,name', 'staff.hrEmployeeProfile', 'client:id,site_id'])
            ->orderBy('starts_at')
            ->get();
        $out = [];
        foreach ($siteIds as $siteId) {
            $here = $shifts->filter(fn (Shift $shift): bool => (int) ($shift->site_id ?? $shift->client?->site_id) === (int) $siteId);
            $out[(int) $siteId] = array_map(function (array $night) use ($here): array {
                $during = $here->filter(fn (Shift $shift): bool => $shift->starts_at < $night['until'] && $shift->ends_at > $night['from']);

                return [
                    ...$night,
                    'on_call' => $during->first(fn (Shift $shift): bool => (bool) $shift->is_on_call || $shift->shift_type === 'on_call')?->staff,
                    'team_lead' => $during->first(fn (Shift $shift): bool => (bool) $shift->staff?->roles->contains('name', 'team_lead'))?->staff,
                ];
            }, $windows);
        }

        return $out;
    }

    /**
     * Tonight and the next nights, 5:00 pm to 7:00 am NZ.
     *
     * @return list<array{label: string, hours: string, from: Carbon, until: Carbon}>
     */
    public function windows(CarbonInterface $now, int $count = 3): array
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $today = Carbon::instance($now)->setTimezone($timezone)->startOfDay();
        [$h, $m] = array_map('intval', explode(':', self::NIGHT_FROM));
        [$uh, $um] = array_map('intval', explode(':', self::NIGHT_UNTIL));
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $day = $today->copy()->addDays($i);
            $out[] = [
                'label' => $i === 0 ? 'Tonight' : $day->format('D j M'),
                'hours' => self::NIGHT_HOURS,
                'from' => $day->copy()->setTime($h, $m)->utc(),
                'until' => $day->copy()->addDay()->setTime($uh, $um)->utc(),
            ];
        }

        return $out;
    }

    /** Does the roster have an on-call shift at this house in the next few nights? */
    public function rosterHasOnCall(int $siteId, CarbonInterface $now, int $days = 3): bool
    {
        $from = Carbon::instance($now);

        return $this->shiftsAt($siteId, $from, $from->copy()->addDays($days))
            ->where(fn ($q) => $q->where('is_on_call', true)->orWhere('shift_type', 'on_call'))
            ->exists();
    }

    /** "Follows the roster, then the team lead on shift · backup Hana Kereama" / "Always Hana Kereama". */
    public function describe(?MedicationOnCallRule $rule): string
    {
        if ($rule === null) {
            return 'Not configured';
        }
        $name = $rule->backup?->name ?? 'nobody chosen';

        return $rule->mode === MedicationOnCallRule::FIXED
            ? 'Always '.$name
            : 'Follows the roster'.($rule->team_lead ? ', then the team lead on shift' : '').' · backup '.$name;
    }

    /** Work phone first; personal cellphone only with the account owner’s consent. */
    public function phoneOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $profile = $user->relationLoaded('hrEmployeeProfile') ? $user->getRelation('hrEmployeeProfile') : $user->hrEmployeeProfile()->first();
        if (! $profile instanceof HrEmployeeProfile || ! $profile->is_active) {
            return null;
        }
        $phone = trim((string) $profile->work_phone);

        if ($phone !== '') {
            return $phone;
        }
        $personal = trim((string) $user->cellphone);

        return $user->on_call_cellphone_consented_at !== null && $personal !== '' ? $personal : null;
    }

    /** Approved leave covering any of this window. */
    public function onLeave(int $userId, CarbonInterface $from, CarbonInterface $until): bool
    {
        return HrLeaveRequest::query()
            ->approved()
            ->where('user_id', $userId)
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from)
            ->exists();
    }

    /** @return array{user: User|null, how: string, warning: string|null, configured: bool} */
    private function resolve(?MedicationOnCallRule $rule, int $siteId, Carbon $from, Carbon $until): array
    {
        if ($rule === null) {
            return ['user' => null, 'how' => 'Not configured', 'warning' => null, 'configured' => false];
        }
        if ($rule->mode === MedicationOnCallRule::ROSTER) {
            $onCall = $this->onCallAt($siteId, $from, $until);
            if ($onCall !== null) {
                return ['user' => $onCall, 'how' => 'On an on-call shift', 'warning' => null, 'configured' => true];
            }
            $lead = $rule->team_lead ? $this->leadAt($siteId, $from, $until) : null;
            if ($lead !== null) {
                return ['user' => $lead, 'how' => 'Team lead on shift', 'warning' => null, 'configured' => true];
            }
        }
        $backup = $rule->backup;
        if (! $backup instanceof User || $backup->approved_at === null) {
            return ['user' => null, 'how' => 'Nobody chosen', 'warning' => 'Nobody — no backup person', 'configured' => true];
        }
        if ($this->onLeave((int) $backup->id, $from, $until)) {
            return ['user' => null, 'how' => 'Backup on leave', 'warning' => 'Nobody — '.$backup->name.' is on leave', 'configured' => true];
        }

        return [
            'user' => $backup,
            'how' => $rule->mode === MedicationOnCallRule::ROSTER ? 'Backup — nobody rostered' : 'Always this person',
            'warning' => null,
            'configured' => true,
        ];
    }

    /** Someone on an on-call shift at the house during the window. */
    private function onCallAt(int $siteId, Carbon $from, Carbon $until): ?User
    {
        $shift = $this->shiftsAt($siteId, $from, $until)
            ->where(fn ($q) => $q->where('is_on_call', true)->orWhere('shift_type', 'on_call'))
            ->whereHas('staff', fn ($u) => $u->whereNotNull('approved_at'))
            ->orderBy('starts_at')
            ->with('staff.hrEmployeeProfile')
            ->first();

        return $shift?->staff;
    }

    /** Someone with the Team lead role on shift at the house during the window (Q8). */
    private function leadAt(int $siteId, Carbon $from, Carbon $until): ?User
    {
        $shift = $this->shiftsAt($siteId, $from, $until)
            ->whereHas('staff', fn ($u) => $u->whereNotNull('approved_at')->whereHas('roles', fn ($r) => $r->where('name', 'team_lead')))
            ->orderBy('starts_at')
            ->with('staff.hrEmployeeProfile')
            ->first();

        return $shift?->staff;
    }

    /** Shifts at the house (directly, or through a client there) overlapping the window, rostered and published. */
    private function shiftsAt(int $siteId, Carbon $from, Carbon $until)
    {
        return Shift::query()
            ->visibleToFrontline()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereNotNull('user_id')
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from)
            ->where(fn ($house) => $house
                ->where('site_id', $siteId)
                ->orWhere(fn ($viaClient) => $viaClient
                    ->whereNull('site_id')
                    ->whereHas('client', fn ($client) => $client->where('site_id', $siteId))));
    }
}
