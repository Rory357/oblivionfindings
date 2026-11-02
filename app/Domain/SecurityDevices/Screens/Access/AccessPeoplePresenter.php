<?php

namespace App\Domain\SecurityDevices\Screens\Access;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Access\Models\AccessCard;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Access › People (pg-sdr-19b): everyone in each Site's UniFi Access and how they get in, problems
 * first: a lost card turned off, someone not matched to staff, a card not used for 60 days, a
 * leaver's last day from HR, then everyone else. Residents show their fob, never their comings and
 * goings (D19). Names for matched staff come from HR.
 */
final class AccessPeoplePresenter
{
    public const array STATUSES = ['problems', 'to_match', 'active', 'off'];

    public const int PER_PAGE = 25;

    /** A lost card or a turn-off stays on the list this long. */
    private const int RECENT_DAYS = 30;

    /** HR end dates this close show as "leaving soon". */
    private const int LEAVING_DAYS = 30;

    private const int UNUSED_DAYS = 60;

    /**
     * Every row for these consoles, problems first (not paged, not filtered).
     *
     * @param  Collection<int, AccessConsole>  $consoles
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $consoles, CarbonImmutable $now): Collection
    {
        $ids = $consoles->pluck('id')->all();
        if ($ids === []) {
            return collect();
        }
        $people = AccessPerson::query()->with(['cards' => fn ($query) => $query->orderBy('id')])->whereIn('console_id', $ids)->get();
        if ($people->isEmpty()) {
            return collect();
        }
        $byConsole = $consoles->keyBy('id');
        $policies = AccessPolicy::query()->whereIn('console_id', $ids)->get(['console_id', 'external_id', 'name'])
            ->groupBy('console_id')->map(fn (Collection $rows) => $rows->pluck('name', 'external_id'));
        $userIds = $people->pluck('user_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $users = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');
        $profiles = $userIds === [] ? collect() : HrEmployeeProfile::query()->whereIn('user_id', $userIds)
            ->orderByDesc('is_active')->orderByDesc('id')->get(['user_id', 'employee_number', 'position_title', 'end_date', 'is_active'])
            ->unique('user_id')->keyBy('user_id');
        $personIds = $people->pluck('id')->all();
        $lastIn = $this->latest($personIds, fn ($query) => $query->whereIn('kind', ['opened', 'unlocked']));
        $lastRefused = $this->latest($personIds, fn ($query) => $query->where('kind', 'denied'));
        $lastCard = $this->latest($personIds, fn ($query) => $query->where('kind', 'opened')->where('method', 'card'));
        $firstEvent = AccessEvent::query()->whereIn('console_id', $ids)->groupBy('console_id')
            ->selectRaw('console_id, MIN(occurred_at) AS first_at')->pluck('first_at', 'console_id');
        $changes = $this->recentChanges($ids, $now);
        $today = $now->setTimezone(self::zone())->startOfDay();

        return $people->map(function (AccessPerson $person) use ($byConsole, $policies, $users, $profiles, $lastIn, $lastRefused, $lastCard, $firstEvent, $changes, $now, $today): array {
            /** @var AccessConsole $console */
            $console = $byConsole->get($person->console_id);
            $site = (string) ($console->site?->name ?? 'Site');
            $resident = AccessNames::isResident($person);
            $matched = AccessNames::isMatchedStaff($person);
            $user = $person->user_id !== null ? $users->get((int) $person->user_id) : null;
            $profile = $person->user_id !== null ? $profiles->get((int) $person->user_id) : null;
            $groupNames = collect((array) ($person->policy_ids ?? []))->map(fn (mixed $id): ?string => $policies->get($person->console_id)?->get($id))->filter()->values();
            $cards = $person->cards->map(fn (AccessCard $card): array => ['id' => (int) $card->id, 'display' => self::cardNumber($card->display_id)])->values();
            $removed = $changes['cards'][$person->console_id.'|'.$person->external_id] ?? null;
            $turnedOff = $changes['off'][$person->console_id.'|'.$person->external_id] ?? null;
            $newCardSince = $removed !== null && $person->cards->contains(fn (AccessCard $card): bool => $card->created_at !== null && $card->created_at->gt($removed['at']));
            $endDate = $matched && $profile?->end_date !== null ? CarbonImmutable::parse($profile->end_date->format('Y-m-d'), self::zone()) : null;
            $coversUnused = ($first = $firstEvent->get($person->console_id)) !== null && CarbonImmutable::parse((string) $first, 'UTC')->lte($now->subDays(self::UNUSED_DAYS));
            $cardUsedAt = isset($lastCard[$person->id]) ? CarbonImmutable::parse((string) $lastCard[$person->id], 'UTC') : null;
            $unusedCard = ! $resident && $person->status === 'active' && $cards->isNotEmpty() && $coversUnused
                && ($cardUsedAt === null || $cardUsedAt->lt($now->subDays(self::UNUSED_DAYS)));

            [$rank, $badge, $note] = match (true) {
                $resident => [4, ['tone' => 'neutral', 'text' => 'Resident fob', 'at' => null], ['text' => 'Their own home, any time', 'at' => null]],
                $person->status === 'deactivated' => [5, ['tone' => 'neutral', 'text' => $turnedOff !== null && $turnedOff['leaver'] ? 'Turned off · left' : 'Turned off', 'at' => null],
                    $turnedOff !== null ? ['text' => $turnedOff['words'], 'at' => $turnedOff['at']->toIso8601String()] : ['text' => 'Turned off in UniFi', 'at' => null]],
                $removed !== null && ! $newCardSince => [0, ['tone' => 'critical', 'text' => $removed['reason'].' · card turned off {time}', 'at' => $removed['at']->toIso8601String()],
                    ['text' => $cards->isEmpty() ? 'No new card yet · a PIN or phone still works if they have one' : 'Their other card still works', 'at' => null]],
                ! $matched => [1, ['tone' => 'warning', 'text' => 'Not matched', 'at' => null], ['text' => $this->matchHint($person, $users, $profiles), 'at' => null]],
                $unusedCard => [2, ['tone' => 'warning', 'text' => 'Card not used for 60 days', 'at' => null], ['text' => 'Keep it, or turn it off?', 'at' => null]],
                $endDate !== null && $person->status === 'active' && $endDate->gte($today->startOfDay()) && $endDate->lte($today->addDays(self::LEAVING_DAYS)) => [3,
                    ['tone' => 'info', 'text' => 'Ends {date}', 'at' => $endDate->format('Y-m-d')], ['text' => 'Turns off by itself at the end of their last day', 'at' => null]],
                $person->status === 'pending' => [3, ['tone' => 'info', 'text' => 'Not active yet', 'at' => null], ['text' => 'Waiting in UniFi Access', 'at' => null]],
                default => [4, ['tone' => 'success', 'text' => 'Active', 'at' => null], null],
            };

            $in = isset($lastIn[$person->id]) ? CarbonImmutable::parse((string) $lastIn[$person->id], 'UTC') : null;
            $refused = isset($lastRefused[$person->id]) ? CarbonImmutable::parse((string) $lastRefused[$person->id], 'UTC') : null;
            $lastUsed = match (true) {
                $resident => ['text' => 'Not shown for residents', 'at' => null],
                $refused !== null && ($in === null || $refused->gt($in)) => ['text' => '{time}, refused', 'at' => $refused->toIso8601String()],
                $in !== null => ['text' => '{time}', 'at' => $in->toIso8601String()],
                default => ['text' => 'Not used yet', 'at' => null],
            };
            $name = $matched ? ($user?->name ?? AccessNames::unifiName($person)) : AccessNames::unifiName($person);
            $suggested = $person->match_state === 'suggested' && $person->user_id !== null ? $users->get((int) $person->user_id) : null;

            return [
                'id' => (int) $person->id,
                'console_id' => (int) $person->console_id,
                'site_id' => (int) $console->site_id,
                'site' => $site,
                'name' => (string) $name,
                'sub' => match (true) {
                    $resident => 'Resident · '.$site,
                    $matched => trim(($profile?->position_title ? $profile->position_title.' · ' : '').$site),
                    default => 'In UniFi · not linked to staff · '.$site,
                },
                'kind' => $resident ? 'resident' : ($matched ? 'staff' : 'unmatched'),
                'status' => (string) $person->status,
                'employee_number' => $person->employee_number,
                'user_id' => $matched ? (int) $person->user_id : null,
                'suggestion' => $suggested !== null ? [
                    'user_id' => (int) $suggested->id,
                    'name' => (string) $suggested->name,
                    'sub' => $this->staffSub($profiles->get((int) $suggested->id)),
                ] : null,
                'cards' => $cards->all(),
                'gets_in' => $resident ? 'Resident fob' : ($cards->isEmpty() ? 'No card' : ($cards->count() === 1 ? 'Card ' : 'Cards ').$cards->pluck('display')->implode(', ')),
                'doors' => $groupNames->isEmpty() ? 'None' : $groupNames->implode(', '),
                'policy_ids' => array_values(array_map('strval', (array) ($person->policy_ids ?? []))),
                'rank' => $rank,
                'badge' => $badge,
                'note' => $note,
                'last_used' => $lastUsed,
                // Only a matched staff member's door log can be opened by person (residents never, D19).
                'can_log' => $matched,
            ];
        })->sortBy(fn (array $row): string => $row['rank'].mb_strtolower($row['name']))->values();
    }

    /**
     * The People tab: the "To match" block, then the rows, filtered and paged.
     *
     * @param  Collection<int, AccessConsole>  $consoles
     * @return array<string, mixed>
     */
    public function present(Collection $consoles, ?string $status, string $search, int $page, CarbonImmutable $now): array
    {
        $rows = $this->rows($consoles, $now);
        $needle = mb_strtolower($search);
        $filtered = $rows->filter(fn (array $row): bool => ($needle === '' || str_contains(mb_strtolower($row['name'].' '.($row['employee_number'] ?? '').' '.$row['site']), $needle))
            && match ($status) {
                'problems' => $row['rank'] <= 3,
                'to_match' => $row['kind'] === 'unmatched' && $row['status'] !== 'deactivated',
                'active' => $row['status'] === 'active',
                'off' => $row['status'] === 'deactivated',
                default => true,
            })->values();
        $last = max(1, (int) ceil($filtered->count() / self::PER_PAGE));
        $page = max(1, min($page, $last));
        $slice = $filtered->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        return [
            'to_match' => $this->toMatch($rows),
            'rows' => $slice->all(),
            'meta' => [
                'page' => $page,
                'last_page' => $last,
                'total' => $filtered->count(),
                'from' => $filtered->isEmpty() ? 0 : ($page - 1) * self::PER_PAGE + 1,
                'to' => ($page - 1) * self::PER_PAGE + $slice->count(),
            ],
            'can_get_in' => $rows->whereIn('status', ['active', 'pending'])->count(),
            'turned_off' => $rows->where('status', 'deactivated')->count(),
        ];
    }

    /**
     * People in UniFi not matched to staff, for the "To match" block and pop-up 90.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, people: list<array<string, mixed>>, lines: list<string>}
     */
    public function toMatch(Collection $rows): array
    {
        $unmatched = $rows->filter(fn (array $row): bool => $row['kind'] === 'unmatched' && $row['status'] !== 'deactivated')->values();

        return [
            'count' => $unmatched->count(),
            'people' => $unmatched->take(20)->all(),
            'lines' => $unmatched->take(2)->map(fn (array $row): string => '**'.$row['name'].'** '.match (true) {
                $row['suggestion'] !== null => 'looks like '.$row['suggestion']['name'].($row['employee_number'] ? ' (employee number '.$row['employee_number'].')' : ''),
                filled($row['employee_number']) => 'has '.$row['employee_number'].', which isn’t in HR at '.$row['site'],
                default => 'has no employee number in UniFi',
            })->all(),
        ];
    }

    /**
     * Staff who work at each Site, for Give someone access (81) and Match (90). Names and employee
     * numbers come from HR.
     *
     * @param  Collection<int, AccessConsole>  $consoles
     * @return array<string, list<array{id: int, name: string, sub: string, employee_number: string|null, in_access: bool}>>
     */
    public function staffOptions(Collection $consoles): array
    {
        $out = [];
        foreach ($consoles as $console) {
            $siteId = (int) $console->site_id;
            $inAccess = AccessPerson::query()->where('console_id', $console->id)->whereNotNull('user_id')->where('match_state', 'matched')
                ->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
            $profiles = HrEmployeeProfile::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query->where('primary_site_id', $siteId)->orWhereJsonContains('secondary_site_ids', $siteId))
                ->whereNotNull('user_id')
                ->limit(500)
                ->get(['user_id', 'employee_number', 'position_title']);
            $names = User::query()->whereIn('id', $profiles->pluck('user_id')->all() ?: [0])->pluck('name', 'id');
            $out[(string) $siteId] = $profiles
                ->filter(fn (HrEmployeeProfile $profile): bool => $names->has($profile->user_id))
                ->map(fn (HrEmployeeProfile $profile): array => [
                    'id' => (int) $profile->user_id,
                    'name' => (string) $names->get($profile->user_id),
                    'sub' => $this->staffSub($profile),
                    'employee_number' => $profile->employee_number !== null ? (string) $profile->employee_number : null,
                    'in_access' => in_array((int) $profile->user_id, $inAccess, true),
                ])
                ->sortBy(fn (array $row): string => mb_strtolower($row['name']))
                ->values()
                ->all();
        }

        return $out;
    }

    /** "••4821" from the number printed on the card; never the card's token. */
    public static function cardNumber(?string $displayId): string
    {
        $digits = trim((string) $displayId);

        return $digits === '' ? 'without a number' : '••'.mb_substr($digits, -4);
    }

    private function staffSub(?HrEmployeeProfile $profile): string
    {
        return implode(' · ', array_values(array_filter([
            $profile?->position_title ? (string) $profile->position_title : null,
            $profile?->employee_number ? (string) $profile->employee_number : null,
        ]))) ?: 'Staff';
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, HrEmployeeProfile>  $profiles
     */
    private function matchHint(AccessPerson $person, Collection $users, Collection $profiles): string
    {
        if ($person->match_state === 'suggested' && $person->user_id !== null && ($user = $users->get((int) $person->user_id)) !== null) {
            $title = $profiles->get((int) $person->user_id)?->position_title;

            return 'Probably '.$user->name.($title ? ', '.$title : '');
        }

        return filled($person->employee_number) ? $person->employee_number.' isn’t in HR at this Site' : 'No employee number in UniFi';
    }

    /**
     * The latest event time per person matching a condition.
     *
     * @param  list<int>  $personIds
     * @return array<int, string>
     */
    private function latest(array $personIds, callable $where): array
    {
        if ($personIds === []) {
            return [];
        }
        $query = AccessEvent::query()->whereIn('person_id', $personIds);
        $where($query);

        return $query->groupBy('person_id')->selectRaw('person_id, MAX(occurred_at) AS last_at')->pluck('last_at', 'person_id')->all();
    }

    /**
     * Cards turned off and people turned off in the last 30 days, from the changes asked of the hub.
     *
     * @param  list<int>  $consoleIds
     * @return array{cards: array<string, array{reason: string, at: CarbonImmutable}>, off: array<string, array{words: string, leaver: bool, at: CarbonImmutable}>}
     */
    private function recentChanges(array $consoleIds, CarbonImmutable $now): array
    {
        $out = ['cards' => [], 'off' => []];
        $jobs = AccessJob::query()->with('requestedBy:id,name')
            ->whereIn('console_id', $consoleIds)
            ->whereIn('kind', ['card_remove', 'user_status'])
            ->where('state', 'succeeded')
            ->where('completed_at', '>=', $now->subDays(self::RECENT_DAYS))
            ->orderBy('completed_at')
            ->limit(500)
            ->get();
        foreach ($jobs as $job) {
            try {
                $params = (array) $job->params;
            } catch (Throwable) {
                continue;
            }
            $key = $job->console_id.'|'.($params['user_id'] ?? '');
            $at = $job->completed_at ?? $job->updated_at?->toImmutable() ?? $now;
            $reason = trim((string) $job->reason);
            if ($job->kind === 'card_remove') {
                $out['cards'][$key] = ['reason' => $reason !== '' ? ucfirst(mb_substr($reason, 0, 40)) : 'Turned off', 'at' => $at];
            } elseif (($params['status'] ?? null) === 'deactivated') {
                $leaver = str_starts_with($reason, 'Left:');
                $by = $job->requestedBy?->name;
                $out['off'][$key] = [
                    'words' => ($reason !== '' ? $reason : 'Turned off').($by ? ' · by '.$by : ($leaver ? ' · from HR' : '')),
                    'leaver' => $leaver,
                    'at' => $at,
                ];
            } else {
                unset($out['off'][$key]);
            }
        }

        return $out;
    }

    private static function zone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
