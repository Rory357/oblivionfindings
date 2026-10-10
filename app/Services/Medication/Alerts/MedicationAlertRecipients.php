<?php

namespace App\Services\Medication\Alerts;

use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Who is told about one medication alert (eMAR P11 B2): the groups switched
 * on in Medication Settings › Alerts, the people named for every house and
 * the house's own extras — never more than today's gate allows.
 *
 * Every recipient must hold an approved account with medication access at
 * the alert's house; an alert about a controlled medicine reaches only people
 * with controlled-medicine access (EM-12). People a group would have told but
 * who lack that access are reported, so the alert log can say so.
 *
 * Groups (P11 v5 RECIPIENT_GROUPS):
 *  - Everyone rostered on a covering shift: assigned to a shift at the house
 *    that covers the alert time — scheduled or in progress, published when
 *    publishing is on (B2 Q10: the rostered window, not clocked in).
 *  - House lead: the team_lead role at the house (B2 Q2; today's routing).
 *  - On-call person: the house's on-call contact at the alert time
 *    (OnCallResolver, B2 chunk 4) — nobody where it isn't configured.
 *  - Clinical lead / Provider manager: that role, with access to the house.
 *  - People who update stock here: `medications.stock.update` at the house.
 *  - The staff member: the person the alert is about.
 *
 * Safety net (P11 B2, Main 2 Oct — not in v5): when the chosen groups, named
 * people and house extras would tell nobody at the house, the alert goes to
 * the people who manage medication settings with access to that house,
 * through the same gate. If even they can't be told, nobody is — and the
 * alert log and Settings say so. The safety net never counts as loosening.
 */
class MedicationAlertRecipients
{
    public const FALLBACK = 'fallback';

    public const FALLBACK_PERMISSION = 'medications.settings.manage';

    /**
     * Loaded with every person, so canDo() and the house lookup answer from
     * memory (User::canDo uses loaded relations). The same checks, cached.
     */
    private const PRELOAD = ['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile'];

    /** @var array<int, list<int>> */
    private array $sites = [];

    /** @var array<string, Collection<int, User>> */
    private array $groupCache = [];

    /** @var array<int, array<string, bool>> */
    private array $permissions = [];

    /** @var array<int, Client|null> */
    private array $clients = [];

    /** @var array<int, array<int, bool>> */
    private array $personAccess = [];

    public function __construct(
        private readonly MedicationAlertSettings $settings,
        private readonly UserSiteAccessService $siteAccess,
        private readonly OnCallResolver $onCall,
    ) {}

    /**
     * @return array{told: list<array{user: User, reason: string}>, not_told_controlled: list<int>, fallback: bool, nobody_reason: string|null}
     */
    public function resolve(string $alert, MedicationAlertSubject $subject, CarbonInterface $at): array
    {
        $setting = $this->settings->forAlert($alert);
        if ($setting === null) {
            return ['told' => [], 'not_told_controlled' => [], 'fallback' => false, 'nobody_reason' => null];
        }

        /** @var array<int, array{user: User, reason: string}> $candidates */
        $candidates = [];
        $add = function (Collection $users, string $reason) use (&$candidates): void {
            foreach ($users as $user) {
                $candidates[(int) $user->id] ??= ['user' => $user, 'reason' => $reason];
            }
        };
        foreach ($setting['groups'] as $group) {
            $add($this->members($group, $subject, $at), $group);
        }
        $add($this->approved($setting['people']), 'named');
        $add($this->approved($this->settings->extras($alert, $subject->siteId)), 'extra');

        [$told, $notToldControlled, $notToldPerson] = $this->gated($candidates, $subject);
        $fallback = false;
        if ($told === []) {
            $managers = [];
            foreach ($this->withPermission(self::FALLBACK_PERMISSION) as $user) {
                $managers[(int) $user->id] = ['user' => $user, 'reason' => self::FALLBACK];
            }
            [$told, $managersWithoutControlled, $managersWithoutPerson] = $this->gated($managers, $subject);
            $fallback = $told !== [];
            $notToldControlled = array_values(array_unique([...$notToldControlled, ...$managersWithoutControlled]));
            $notToldPerson = array_values(array_unique([...$notToldPerson, ...$managersWithoutPerson]));
        }

        return [
            'told' => $told,
            'not_told_controlled' => $notToldControlled,
            // EA-015: in a group, but can't open this person's record.
            'not_told_person' => $notToldPerson,
            'fallback' => $fallback,
            'nobody_reason' => $told === []
                ? 'Nobody in its groups, and no medication settings manager with access to this house'
                    .($subject->controlled ? ' and controlled-medicine access' : '')
                    .($subject->clientId !== null ? ' who can open this person’s record' : '').'.'
                : null,
        ];
    }

    /**
     * How many people each group would tell at each house now, and the safety
     * net behind them — the same resolution raise() uses, so the "Goes to"
     * warning and what happens can't disagree. `controlled` counts only
     * people with controlled-medicine access. Everyone rostered is counted
     * against the roster at this moment.
     *
     * @param  list<int>  $siteIds
     * @return array<int, array{groups: array<string, array{all: int, controlled: int}>, fallback: array{all: int, controlled: int}}>
     */
    public function reach(array $siteIds, CarbonInterface $at): array
    {
        $out = [];
        foreach ($siteIds as $siteId) {
            $count = function (Collection $users, string $reason) use ($siteId): array {
                $all = 0;
                $controlled = 0;
                foreach ($users as $user) {
                    $base = new MedicationAlertSubject(key: '', siteId: (int) $siteId, title: '', message: '', shortMessage: '');
                    if ($this->gate($user, $base, $reason) === 'yes') {
                        $all++;
                        if ($this->can($user, MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
                            $controlled++;
                        }
                    }
                }

                return ['all' => $all, 'controlled' => $controlled];
            };
            $groups = [];
            foreach (MedicationAlertCatalogue::GROUPS_BUILT as $group) {
                if ($group === MedicationAlertCatalogue::STAFF_MEMBER) {
                    continue;
                }
                $subject = new MedicationAlertSubject(key: '', siteId: (int) $siteId, title: '', message: '', shortMessage: '');
                $groups[$group] = $count($this->members($group, $subject, $at), $group);
            }
            $out[(int) $siteId] = [
                'groups' => $groups,
                'fallback' => $count($this->withPermission(self::FALLBACK_PERMISSION), self::FALLBACK),
            ];
        }

        return $out;
    }

    /**
     * Whether each person could get an alert, and where: for the "Goes to"
     * warning about named people and house extras.
     *
     * @param  list<int>  $ids
     * @return array<int, array{ok: bool, site_ids: list<int>, controlled: bool}>
     */
    public function peopleReach(array $ids): array
    {
        return $this->approved(array_values(array_unique($ids)))
            ->mapWithKeys(fn (User $user): array => [(int) $user->id => [
                'ok' => $this->can($user, 'medications.view'),
                'site_ids' => $this->sitesOf($user),
                'controlled' => $this->can($user, MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
            ]])
            ->all();
    }

    /**
     * Who an escalation adds (B2 chunk 3): the chosen groups at the alert's
     * house, through the same gate as the first message, leaving out anyone
     * already told.
     *
     * @param  list<string>  $groups
     * @param  list<int>  $alreadyTold
     * @return list<array{user: User, reason: string}>
     */
    public function escalationTargets(array $groups, MedicationAlertSubject $subject, CarbonInterface $at, array $alreadyTold): array
    {
        $candidates = [];
        foreach ($groups as $group) {
            foreach ($this->members($group, $subject, $at) as $user) {
                if (! in_array((int) $user->id, $alreadyTold, true)) {
                    $candidates[(int) $user->id] ??= ['user' => $user, 'reason' => $group];
                }
            }
        }

        return $this->gated($candidates, $subject)[0];
    }

    /**
     * Those told so far who can still get the alert (a re-alert): approved,
     * and still through the gate for why they were first told.
     *
     * @param  array<int, string>  $reasonsById  user id => why they were first told
     * @return list<array{user: User, reason: string}>
     */
    public function stillAllowed(array $reasonsById, MedicationAlertSubject $subject): array
    {
        $candidates = [];
        foreach ($this->approved(array_map('intval', array_keys($reasonsById))) as $user) {
            $candidates[(int) $user->id] = ['user' => $user, 'reason' => $reasonsById[(int) $user->id]];
        }

        return $this->gated($candidates, $subject)[0];
    }

    /**
     * @param  array<int, array{user: User, reason: string}>  $candidates
     * @return array{0: list<array{user: User, reason: string}>, 1: list<int>}
     */
    private function gated(array $candidates, MedicationAlertSubject $subject): array
    {
        $told = [];
        $notToldControlled = [];
        $notToldPerson = [];
        foreach ($candidates as $candidate) {
            $gate = $this->gate($candidate['user'], $subject, $candidate['reason']);
            if ($gate === 'yes') {
                $told[] = $candidate;
            } elseif ($gate === 'controlled') {
                $notToldControlled[] = (int) $candidate['user']->id;
            } elseif ($gate === 'person') {
                $notToldPerson[] = (int) $candidate['user']->id;
            }
        }

        return [$told, $notToldControlled, $notToldPerson];
    }

    /**
     * Medication access at the alert's house, and controlled-medicine access
     * for a controlled alert. "controlled" means only that was missing. The
     * staff member is told about their own renewal wherever they work — it
     * names nobody else and no medicine (as today).
     *
     * @return 'yes'|'no'|'controlled'
     */
    private function gate(User $user, MedicationAlertSubject $subject, string $reason): string
    {
        if ($user->approved_at === null) {
            return 'no';
        }
        if ($reason === MedicationAlertCatalogue::EA_REVIEWERS || ($subject->context['emergency_access_review_report'] ?? false)) {
            return $this->can($user, 'medications.audit.view') && $subject->siteId !== null
                && in_array($subject->siteId, $this->siteAccess->accessibleSiteIds($user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS), true) ? 'yes' : 'no';
        }
        if ($reason === MedicationAlertCatalogue::STAFF_MEMBER) {
            return 'yes';
        }
        if (! $this->can($user, 'medications.view')
            || $subject->siteId === null
            || ! in_array($subject->siteId, $this->sitesOf($user), true)) {
            return 'no';
        }
        if ($subject->controlled && ! $this->can($user, MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            return 'controlled';
        }
        // EA-015: an alert about a person goes only to people who can open
        // that person's record — the same gate as the bell and the record.
        if ($subject->clientId !== null && ! $this->canOpenPerson($user, $subject->clientId)) {
            return 'person';
        }

        return 'yes';
    }

    /** ClientPolicy::viewMedications, asked once per person and client while resolving. */
    private function canOpenPerson(User $user, int $clientId): bool
    {
        if (! array_key_exists($clientId, $this->clients)) {
            $this->clients[$clientId] = Client::query()->find($clientId);
        }
        $client = $this->clients[$clientId];

        return $this->personAccess[(int) $user->id][$clientId] ??= $client !== null
            && Gate::forUser($user)->allows('viewMedications', $client);
    }

    /** canDo(), asked once per person and permission while resolving. */
    private function can(User $user, string $permission): bool
    {
        return $this->permissions[(int) $user->id][$permission] ??= $user->canDo($permission);
    }

    /** @return Collection<int, User> */
    private function members(string $group, MedicationAlertSubject $subject, CarbonInterface $at): Collection
    {
        return match ($group) {
            MedicationAlertCatalogue::ROSTERED => $this->rostered($subject->siteId, $at),
            MedicationAlertCatalogue::HOUSE_LEAD => $this->withRole('team_lead'),
            MedicationAlertCatalogue::ON_CALL => $this->onCall($subject->siteId, $at),
            MedicationAlertCatalogue::CLINICAL_LEAD => $this->withRole('clinical_lead'),
            MedicationAlertCatalogue::PROVIDER_MANAGER => $this->withRole('provider_manager'),
            MedicationAlertCatalogue::STOCK_STAFF => $this->withPermission('medications.stock.update'),
            MedicationAlertCatalogue::EA_REVIEWERS => $this->withPermission('medications.audit.view'),
            MedicationAlertCatalogue::STAFF_MEMBER => $subject->staffUserId === null
                ? collect()
                : $this->approved([$subject->staffUserId]),
            default => collect(),
        };
    }

    /** @return Collection<int, User> People assigned to a shift at the house that covers this moment. */
    private function rostered(?int $siteId, CarbonInterface $at): Collection
    {
        if ($siteId === null) {
            return collect();
        }
        $userIds = Shift::query()
            ->visibleToFrontline()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereNotNull('user_id')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->where(fn ($house) => $house
                ->where('site_id', $siteId)
                ->orWhere(fn ($viaClient) => $viaClient
                    ->whereNull('site_id')
                    ->whereHas('client', fn ($client) => $client->where('site_id', $siteId))))
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $this->approved($userIds);
    }

    /** @return Collection<int, User> The house's on-call contact at that moment (B2 chunk 4), if anyone. */
    private function onCall(?int $siteId, CarbonInterface $at): Collection
    {
        if ($siteId === null) {
            return collect();
        }
        $user = $this->onCall->at($siteId, $at)['user'];

        return $user === null ? collect() : $this->approved([(int) $user->id]);
    }

    /** @return Collection<int, User> */
    private function withRole(string $role): Collection
    {
        return $this->groupCache['role:'.$role] ??= User::query()
            ->whereNotNull('approved_at')
            ->whereHas('roles', fn ($roles) => $roles->where('name', $role))
            ->with(self::PRELOAD)
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, User> Holders by role or personal grant; canDo() decides, so denials hold. */
    private function withPermission(string $permission): Collection
    {
        return $this->groupCache['permission:'.$permission] ??= User::query()
            ->whereNotNull('approved_at')
            ->where(fn ($holders) => $holders
                ->whereHas('roles.permissions', fn ($p) => $p->where('key', $permission))
                ->orWhereHas('permissionOverrides', fn ($p) => $p->where('permissions.key', $permission)))
            ->with(self::PRELOAD)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->can($user, $permission))
            ->values();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function approved(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->whereNotNull('approved_at')->with(self::PRELOAD)->orderBy('id')->get();
    }

    /** @return list<int> */
    private function sitesOf(User $user): array
    {
        return $this->sites[(int) $user->id] ??= array_map('intval', $this->siteAccess->accessibleSiteIds(
            $user,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        ));
    }
}
