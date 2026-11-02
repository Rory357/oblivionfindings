<?php

namespace App\Domain\SecurityDevices\Screens\Access;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessVisitor;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pop-up 87, the fire roll call: who may be at a Site now, each saying where that came from. Staff
 * rostered on now (with their clock-in when there is one), people who badged in today, and visitors
 * with a pass for today. Leaving isn't recorded (doors open from inside), so someone listed may
 * have gone. Only staff matched at that Site are named; anyone else who badged in is listed without
 * a name. Residents aren't listed from their fobs (D19).
 */
final class AccessRollCall
{
    /** @return array<string, mixed> */
    public function present(Site $site, CarbonImmutable $now): array
    {
        $console = AccessConsole::query()->where('site_id', $site->id)->first();
        $dayStart = AccessDoorsPresenter::dayStart($now);
        $rostered = $this->rostered((int) $site->id, $now);
        $clockedIn = HrAttendanceSession::query()
            ->where('site_id', $site->id)
            ->whereNull('clock_out_at')
            ->where('status', 'open')
            ->where('clock_in_at', '<=', $now)
            ->get(['user_id', 'clock_in_at'])
            ->keyBy('user_id');
        $badges = $console instanceof AccessConsole ? $this->badgedToday($console, $dayStart, $now) : collect();
        $badgeFor = $badges->filter(fn (array $badge): bool => $badge['user_id'] !== null)->keyBy('user_id');

        $staff = $rostered->map(function (Shift $shift) use ($clockedIn, $badgeFor): array {
            $userId = (int) $shift->user_id;
            $parts = ['Rostered {from} – {until}'];
            $times = ['from' => $shift->starts_at?->toIso8601String(), 'until' => $shift->ends_at?->toIso8601String()];
            if (($session = $clockedIn->get($userId)) !== null) {
                $parts[] = 'clocked in {clock}';
                $times['clock'] = CarbonImmutable::parse($session->clock_in_at)->toIso8601String();
            }
            if (($badge = $badgeFor->get($userId)) !== null) {
                $parts[] = 'badged in {badge}';
                $times['badge'] = $badge['at'];
            }

            return ['key' => 'staff-'.$userId, 'name' => (string) ($shift->staff?->name ?? 'Staff member'), 'hint' => ['text' => implode(' · ', $parts), 'times' => $times]];
        })->unique('key')->values();

        // Clocked in here but not rostered now.
        $rosteredIds = $rostered->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $clockedOnly = $clockedIn->reject(fn ($session): bool => in_array((int) $session->user_id, $rosteredIds, true));
        $clockedNames = User::query()->whereIn('id', $clockedOnly->keys()->all() ?: [0])->pluck('name', 'id');
        foreach ($clockedOnly as $userId => $session) {
            $staff->push(['key' => 'staff-'.$userId, 'name' => (string) ($clockedNames->get($userId) ?? 'Staff member'), 'hint' => [
                'text' => 'Clocked in {clock} · not rostered now',
                'times' => ['clock' => CarbonImmutable::parse($session->clock_in_at)->toIso8601String()],
            ]]);
        }
        $onShiftIds = array_merge($rosteredIds, $clockedOnly->keys()->map(fn (mixed $id): int => (int) $id)->all());

        $cameIn = $badges->reject(fn (array $badge): bool => $badge['user_id'] !== null && in_array($badge['user_id'], $onShiftIds, true))
            ->map(fn (array $badge): array => [
                'key' => 'badge-'.$badge['key'],
                'name' => $badge['name'] ?? 'Someone not matched to staff',
                'hint' => ['text' => 'Badged in {badge} at '.$badge['door'].' · may have left', 'times' => ['badge' => $badge['at']]],
            ])->values();

        $visitors = $console instanceof AccessConsole ? AccessVisitor::query()
            ->where('console_id', $console->id)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderBy('name')
            ->get()
            ->map(fn (AccessVisitor $visitor): array => [
                'key' => 'visitor-'.$visitor->id,
                'name' => (string) $visitor->name,
                'hint' => ['text' => 'Visitor pass {from} – {until} · coming in isn’t recorded for passes', 'times' => [
                    'from' => $visitor->starts_at?->toIso8601String(), 'until' => $visitor->ends_at?->toIso8601String(),
                ]],
            ])->values() : collect();

        $doors = $console instanceof AccessConsole ? AccessDoor::query()->where('console_id', $console->id)->get(['lock_state']) : collect();

        return [
            'site' => ['id' => (int) $site->id, 'name' => (string) $site->name],
            'emergency' => $console instanceof AccessConsole && in_array($console->emergency_mode, ['lockdown', 'evacuation'], true) ? $console->emergency_mode : 'normal',
            'doors' => $doors->count(),
            'unlocked' => $doors->where('lock_state', 'unlocked')->count(),
            'sections' => [
                ['key' => 'staff', 'title' => 'Staff on shift', 'icon' => 'id-card', 'people' => $staff->all()],
                ['key' => 'came_in', 'title' => 'Came in today by badge, not on shift', 'icon' => 'door-open', 'people' => $cameIn->all()],
                ['key' => 'visitors', 'title' => 'Visitors with a pass now', 'icon' => 'hard-hat', 'people' => $visitors->all()],
            ],
            'as_of' => $now->toIso8601String(),
        ];
    }

    /** @return Collection<int, Shift> */
    private function rostered(int $siteId, CarbonImmutable $now): Collection
    {
        return Shift::query()
            ->with('staff:id,name')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->whereNotIn('status', ['cancelled', 'declined', 'no_show'])
            ->whereNotNull('user_id')
            ->where(fn ($query) => $query
                ->where('site_id', $siteId)
                ->orWhere(fn ($derived) => $derived->whereNull('site_id')->whereHas('client', fn ($client) => $client->where('site_id', $siteId))))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Each person's first way in today (by card, PIN, phone or remote unlock). Residents are left out.
     *
     * @return Collection<int, array{key: string, user_id: int|null, name: string|null, door: string, at: string}>
     */
    private function badgedToday(AccessConsole $console, CarbonImmutable $dayStart, CarbonImmutable $now): Collection
    {
        return AccessEvent::query()
            ->with(['door:id,name', 'person:id,kind,client_id,match_state,user_id', 'person.user:id,name'])
            ->where('console_id', $console->id)
            ->whereIn('kind', ['opened', 'unlocked'])
            ->whereNotNull('person_id')
            ->where(fn ($query) => $query->whereNull('method')->orWhere('method', '!=', 'button'))
            ->where('occurred_at', '>=', $dayStart)
            ->orderBy('occurred_at')
            ->limit(5000)
            ->get()
            ->reject(fn (AccessEvent $event): bool => AccessNames::isResident($event->person))
            ->unique('person_id')
            ->map(function (AccessEvent $event) use ($now): array {
                $who = AccessNames::who($event, $event->person, $now);

                return [
                    'key' => (string) $event->person_id,
                    'user_id' => $who['named'] ? (int) $event->person?->user_id : null,
                    'name' => $who['named'] ? $who['text'] : null,
                    'door' => (string) ($event->door?->name ?? 'a door'),
                    'at' => $event->occurred_at->toIso8601String(),
                ];
            })
            ->values();
    }
}
