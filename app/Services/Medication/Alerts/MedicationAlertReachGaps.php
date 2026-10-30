<?php

namespace App\Services\Medication\Alerts;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserPushSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Who can't be reached (P11 v5 Delivery, B2 chunk 5): the contact gaps that
 * stop an alert or an on-call call getting through — no work email, push
 * not set up, no work phone, and an on-call backup on approved leave — for
 * people who work at a house (everyone else can't be told about one).
 *
 * It returns facts about each person with a gap; the page works out from
 * its draft which gaps matter (an alert they'd get by email or push, or
 * being needed on call), as v5 does. Leave says only "On leave" and the
 * dates — never the type of leave — and only for people needed on call
 * (a backup, or rostered on call or as the team lead in the nights shown),
 * where it is the reason a night shows nobody.
 */
class MedicationAlertReachGaps
{
    /** A shift in the next fortnight counts them in "Everyone rostered on a covering shift". */
    private const ROSTER_DAYS = 14;

    public function __construct(private readonly OnCallResolver $onCall) {}

    /**
     * @param  list<array{id: int, name: string, role: string, houses: list<string>, site_ids: list<int>, all_houses: bool, controlled: bool}>  $people  People who can get alerts.
     * @param  list<array<string, mixed>>  $onCallHouses  The on-call contacts payload: each house's rule and roster.
     * @param  array<int, User>  $loaded  Those people already loaded with roles, grants and HR profile.
     * @return list<array{id: int, name: string, role: string, houses: string, site_ids: list<int>, controlled: bool, groups: list<string>, work_email: bool, push: bool, phone: bool, leave: string|null, backup_for: list<string>, on_call: bool}>
     */
    public function rows(array $people, array $onCallHouses, CarbonInterface $now, array $loaded = []): array
    {
        if ($people === []) {
            return [];
        }
        $ids = array_column($people, 'id');
        $missing = array_values(array_diff($ids, array_keys($loaded)));
        $users = collect($loaded)->only($ids);
        if ($missing !== []) {
            $users = $users->union(User::query()
                ->whereIn('id', $missing)
                ->with(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile'])
                ->get()
                ->keyBy('id'));
        }
        $push = UserPushSubscription::query()
            ->whereIn('user_id', $ids)
            ->where('enabled', true)
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $rostered = Shift::query()
            ->visibleToFrontline()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereIn('user_id', $ids)
            ->where('ends_at', '>', $now)
            ->where('starts_at', '<', Carbon::instance($now)->addDays(self::ROSTER_DAYS))
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // Who the on-call contacts can give: rostered on call or as the team
        // lead on shift (where the house follows them), and each backup.
        $onCall = [];
        $backupFor = [];
        foreach ($onCallHouses as $house) {
            $rule = $house['rule'] ?? null;
            if ($rule === null) {
                continue;
            }
            if (($rule['mode'] ?? null) === 'roster') {
                foreach ($house['roster'] ?? [] as $night) {
                    if (isset($night['on_call']['id'])) {
                        $onCall[(int) $night['on_call']['id']] = true;
                    }
                    if (($rule['team_lead'] ?? false) && isset($night['team_lead']['id'])) {
                        $onCall[(int) $night['team_lead']['id']] = true;
                    }
                }
            }
            if (isset($rule['backup']['id'])) {
                $onCall[(int) $rule['backup']['id']] = true;
                $backupFor[(int) $rule['backup']['id']][] = (string) $house['name'];
            }
        }
        $leave = $this->leave(array_keys($onCall), $now);

        $rows = [];
        foreach ($people as $person) {
            $user = $users->get($person['id']);
            // Someone with no house can't be told about anything at one.
            if (! $user instanceof User || (! $person['all_houses'] && $person['site_ids'] === [])) {
                continue;
            }
            $id = (int) $user->id;
            $row = [
                'id' => $id,
                'name' => (string) $person['name'],
                'role' => (string) $person['role'],
                'houses' => $person['all_houses'] ? 'All houses' : (implode(', ', $person['houses']) ?: 'No house'),
                'site_ids' => $person['site_ids'],
                'controlled' => (bool) $person['controlled'],
                'groups' => $this->groupsOf($user, in_array($id, $rostered, true), isset($onCall[$id])),
                'work_email' => $user->medicationAlertWorkEmail() !== null,
                'push' => in_array($id, $push, true),
                'phone' => $this->onCall->phoneOf($user) !== null,
                'leave' => $leave[$id] ?? null,
                'backup_for' => $backupFor[$id] ?? [],
                'on_call' => isset($onCall[$id]),
            ];
            $gap = ! $row['work_email'] || ! $row['push'] || ! $row['phone'] || $row['leave'] !== null;
            if ($gap) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The alert groups this person can be in at their houses.
     *
     * @return list<string>
     */
    private function groupsOf(User $user, bool $rostered, bool $onCall): array
    {
        $role = fn (string $name): bool => $user->roles->contains('name', $name);

        return array_values(array_filter([
            $rostered ? MedicationAlertCatalogue::ROSTERED : null,
            $role('team_lead') ? MedicationAlertCatalogue::HOUSE_LEAD : null,
            $onCall ? MedicationAlertCatalogue::ON_CALL : null,
            $role('clinical_lead') ? MedicationAlertCatalogue::CLINICAL_LEAD : null,
            $role('provider_manager') ? MedicationAlertCatalogue::PROVIDER_MANAGER : null,
            $user->canDo('medications.stock.update') ? MedicationAlertCatalogue::STOCK_STAFF : null,
            // Anyone can be the person an alert is about (their own renewal).
            MedicationAlertCatalogue::STAFF_MEMBER,
        ]));
    }

    /**
     * Approved leave in the on-call nights shown (tonight and the next two),
     * as "On leave Thu 1 – Mon 5 Oct (Leave hub)". Never the type of leave.
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function leave(array $userIds, CarbonInterface $now): array
    {
        if ($userIds === []) {
            return [];
        }
        $windows = $this->onCall->windows($now);
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');

        return HrLeaveRequest::query()
            ->approved()
            ->whereIn('user_id', $userIds)
            ->where('starts_at', '<', end($windows)['until'])
            ->where('ends_at', '>', $windows[0]['from'])
            ->orderBy('starts_at')
            ->get(['user_id', 'starts_at', 'ends_at'])
            ->groupBy('user_id')
            ->map(function ($requests) use ($timezone): string {
                $first = $requests->first();

                return sprintf(
                    'On leave %s – %s (Leave hub)',
                    Carbon::parse($first->starts_at)->timezone($timezone)->format('D j'),
                    Carbon::parse($first->ends_at)->timezone($timezone)->format('D j M'),
                );
            })
            ->mapWithKeys(fn (string $text, mixed $id): array => [(int) $id => $text])
            ->all();
    }
}
