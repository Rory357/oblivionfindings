<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ClientMedication;
use App\Models\MedicationAdminRule;
use App\Models\MedicationAlert;
use App\Models\MedicationOnCallRule;
use App\Models\Site;
use App\Models\User;
use App\Models\UserPushSubscription;
use App\Models\WitnessPinReminder;
use App\Notifications\WitnessPinReminderNotification;
use App\Services\AuditLogger;
use App\Services\Medication\Alerts\MedicationAlertPreviews;
use App\Services\Medication\Alerts\MedicationAlertReachGaps;
use App\Services\Medication\Alerts\MedicationAlertRecipients;
use App\Services\Medication\Alerts\OnCallResolver;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\RoundTemplateCatalogue;
use App\Services\Medication\Settings\MedicationSettingDefinition;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\Medication\Settings\MedicationSettingsStore;
use App\Services\Medication\Settings\MedicineRuleScope;
use App\Services\Medication\Settings\MedicineRuleWording;
use App\Services\Medication\Settings\QuietHoursCodec;
use App\Services\Medication\Settings\TimeCodec;
use App\Services\Medication\WitnessPinResetAuthority;
use App\Services\Medication\WitnessPinService;
use App\Services\MedicationRuleService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Medication › Settings (eMAR P11).
 *
 * Settings values (safety checks, witness PIN rules, …) are edited as a draft
 * and saved a view at a time through "Review changes"; every save and every
 * "Keep today's value" is recorded in the change history and the audit log
 * (MedicationSettingsStore).
 *
 * Facility medication administration rules (1CHART §6.1) let clinical
 * managers require a countersignature and/or a clinical observation (BSL,
 * pulse, BP) whenever a medicine's name / route / NZULM code matches — without
 * a code change. Enforcement lives in MedicationRuleService + EnhancedMarService.
 */
class MedicationSettingsController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly MedicationRuleService $ruleService,
        private readonly PeopleMutationLockService $peopleLocks,
        private readonly WitnessPinService $witnessPins,
        private readonly MedicationSettingsRegistry $settingsRegistry,
        private readonly MedicationSettingsStore $settingsStore,
        private readonly MedicineRuleWording $ruleWording,
        private readonly MedicineRuleScope $ruleScope,
        private readonly RoundTemplateCatalogue $roundTemplates,
    ) {}

    private const WITNESS_PIN_STAFF_LIMIT = 500;

    /** Permissions that make someone a possible second person (witness, co-signer, read-back, waiver). */
    private const SECOND_PERSON_PERMISSIONS = [
        'medications.controlled.witness',
        'medications.administer.record',
        'medications.orders.verify',
    ];

    /**
     * Save one Settings view's draft. Organisation settings need eMAR settings
     * management with all-Sites authority; a house setting needs authority
     * over that house. Each change names the saved value it was edited from,
     * so a change someone else saved meanwhile is never overwritten.
     */
    public function saveChanges(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor) || $this->canManageHouseAlerts($actor), 403);

        $validated = $request->validate([
            'view' => ['required', 'string', Rule::in($this->settingsRegistry->views())],
            'changes' => ['required', 'array', 'min:1', 'max:200'],
            'changes.*.group' => ['required', 'string', 'max:40'],
            'changes.*.key' => ['required', 'string', 'max:80'],
            'changes.*.site_id' => ['nullable', 'integer'],
            // Who gets an alert is one JSON value (P11 B2); each definition checks its own length.
            'changes.*.value' => ['present', 'nullable', 'string', 'max:4000'],
            'changes.*.from' => ['present', 'nullable', 'string', 'max:4000'],
            'confirm_loosening' => ['sometimes', 'boolean'],
        ]);

        $changes = $this->resolveSettings($validated['changes'], 'changes', $validated['view']);
        foreach ($changes as $index => $change) {
            if (! $change['definition']->accepts($change['value'])) {
                throw ValidationException::withMessages([
                    "changes.{$index}.value" => $change['definition']->invalidMessage(),
                ]);
            }
            // Stored as the canonical value, so "nothing changed" is exact.
            $changes[$index]['value'] = $change['definition']->normalise($change['value']);
        }
        $this->assertSettingsAuthority($actor, $changes);

        $result = DB::transaction(function () use ($actor, $changes, $validated): array {
            $lockedActor = $this->lockCurrentRuleActor($actor, $this->onlyHouseManaged($changes));
            $this->assertCurrentSettingsAuthority($lockedActor, $changes);
            // Who may be named is checked once authority over each house is
            // (another house's extras: 403, never a validation message).
            $this->assertAlertPeople($changes);
            $this->assertFollowUpConsistent($changes);
            $this->assertQuietHoursConsistent($changes);

            return $this->settingsStore->apply($lockedActor, $changes, (bool) ($validated['confirm_loosening'] ?? false));
        }, 3);

        $message = $result['saved'] === 0
            ? 'Nothing was saved — these settings already had those values.'
            : sprintf('%d %s saved. %s.', $result['saved'], $result['saved'] === 1 ? 'change' : 'changes', $result['effect']);

        return redirect()->back()->with('medication_settings_saved', $message);
    }

    /**
     * "Keep today's value": confirm defaults nobody has reviewed, one at a
     * time or from the "Review the defaults" walkthrough. Nothing about how
     * doses are recorded changes; each is recorded and audited.
     */
    public function keepDefaults(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.group' => ['required', 'string', 'max:40'],
            'items.*.key' => ['required', 'string', 'max:80'],
            'items.*.site_id' => ['nullable', 'integer'],
        ]);

        $items = $this->resolveSettings($validated['items'], 'items');
        $this->assertSettingsAuthority($actor, $items);
        // A setting that isn't configured has no value today to keep: someone
        // chooses one (P11 v5 "Not configured").
        foreach ($items as $index => $item) {
            if ($item['definition']->whenNotConfigured !== null) {
                throw ValidationException::withMessages([
                    "items.$index.key" => '“'.$item['definition']->label.'” isn’t configured — choose a value instead.',
                ]);
            }
        }

        $kept = DB::transaction(function () use ($actor, $items): int {
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $this->assertCurrentSettingsAuthority($lockedActor, $items);

            return $this->settingsStore->keep($lockedActor, $items);
        }, 3);

        $message = $kept === 1
            ? 'Kept today’s value for “'.$items[0]['definition']->label.'”. It now shows as reviewed.'
            : $kept.' values kept. They now show as reviewed, and each is in the change history.';

        return redirect()->back()->with('medication_settings_saved', $message);
    }

    /**
     * Match each requested setting to its definition: it must exist, belong to
     * the view being saved, name a house only when it is a house setting, and
     * appear once.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{definition: MedicationSettingDefinition, site_id: int|null, value: string, from: string}>
     */
    private function resolveSettings(array $rows, string $field, ?string $view = null): array
    {
        $seen = [];
        $resolved = [];
        foreach (array_values($rows) as $index => $row) {
            $definition = $this->settingsRegistry->definition((string) $row['group'], (string) $row['key']);
            $siteId = isset($row['site_id']) ? (int) $row['site_id'] : null;
            $group = $definition ? $this->settingsRegistry->group($definition->group) : null;
            if (! $definition
                || ($view !== null && $group?->view !== $view)
                || $definition->isSiteScoped() !== ($siteId !== null)) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.key" => 'This setting can’t be changed here.',
                ]);
            }
            $slot = $definition->id().'@'.($siteId ?? 'org');
            if (isset($seen[$slot])) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.key" => '“'.$definition->label.'” is listed twice.',
                ]);
            }
            $seen[$slot] = true;
            $resolved[] = [
                'definition' => $definition,
                'site_id' => $siteId,
                'value' => (string) ($row['value'] ?? ''),
                'from' => (string) ($row['from'] ?? ''),
            ];
        }

        return $resolved;
    }

    /**
     * Organisation settings need settings management with all-sites
     * authority. Without settings management, a house manager may change only
     * house settings marked for them — their houses' alert extras (P11 B2 Q3);
     * anything else is refused, never silently skipped.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null}>  $items
     */
    private function assertSettingsAuthority(User $actor, array $items): void
    {
        if (! $this->canManageSettings($actor)) {
            abort_unless($this->canManageHouseAlerts($actor) && $this->onlyHouseManaged($items), 403);
        }
        if (collect($items)->contains(fn (array $item): bool => ! $item['definition']->isSiteScoped())) {
            abort_unless($this->canManageGlobalRules($actor), 403);
        }
    }

    /** @param  list<array{definition: MedicationSettingDefinition, site_id: int|null}>  $items */
    private function onlyHouseManaged(array $items): bool
    {
        return $items !== [] && collect($items)->every(
            fn (array $item): bool => $item['definition']->houseManaged && $item['definition']->isSiteScoped(),
        );
    }

    private function canManageHouseAlerts(?User $user): bool
    {
        return (bool) $user && $user->canDo('medications.alerts.manage_house');
    }

    /**
     * People newly named on an alert must be able to get it: an approved
     * account with medication access — for a house's extras, access to that
     * house (P11 v5 pickers). Someone already on the list who has since lost
     * access can stay until they're removed.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null, value: string, from: string}>  $changes
     */
    private function assertAlertPeople(array $changes): void
    {
        foreach ($changes as $index => $change) {
            $kind = $change['definition']->codec?->kind();
            if (! in_array($kind, ['alert', 'people'], true)) {
                continue;
            }
            $ids = fn (string $value): array => $kind === 'alert'
                ? (json_decode($value, true)['people'] ?? [])
                : (json_decode($value, true) ?? []);
            $added = array_values(array_diff($ids($change['value']), $ids($change['from'])));
            if ($added === []) {
                continue;
            }
            $eligible = collect($this->alertPeople($change['site_id'] !== null ? [$change['site_id']] : null))
                ->pluck('id')
                ->all();
            if (array_diff($added, $eligible) !== []) {
                throw ValidationException::withMessages([
                    "changes.{$index}.value" => $change['site_id'] !== null
                        ? 'Choose people with medication access at this house.'
                        : 'Choose people with medication access.',
                ]);
            }
        }
    }

    /**
     * People who can be named on an alert (P11 v5 "Add a person"): approved
     * accounts with medication access, with their role and houses. Null =
     * anyone in the organisation; otherwise people with access to one of
     * these houses.
     *
     * @param  list<int>|null  $siteIds
     * @return list<array{id: int, name: string, role: string, houses: list<string>, site_ids: list<int>, all_houses: bool, controlled: bool}>
     */
    private function alertPeople(?array $siteIds): array
    {
        $allSites = Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id');

        return User::query()
            ->whereNotNull('approved_at')
            ->where(fn ($holders) => $holders
                ->whereHas('roles.permissions', fn ($p) => $p->where('key', 'medications.view'))
                ->orWhereHas('permissionOverrides', fn ($p) => $p->where('permissions.key', 'medications.view')))
            ->with(['roles:id,name,label', 'roles.permissions', 'permissionOverrides', 'hrEmployeeProfile'])
            ->orderBy('name')
            ->limit(self::WITNESS_PIN_STAFF_LIMIT)
            ->get()
            ->filter(fn (User $user): bool => $user->canDo('medications.view'))
            ->map(function (User $user) use ($allSites, $siteIds): ?array {
                $everywhere = $this->siteAccess->canBypass($user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
                $sites = $everywhere
                    ? $allSites->keys()->map(fn (mixed $id): int => (int) $id)->all()
                    : collect([$user->hrEmployeeProfile?->primary_site_id, ...($user->hrEmployeeProfile?->secondary_site_ids ?? [])])
                        ->filter()
                        ->map(fn (mixed $id): int => (int) $id)
                        ->filter(fn (int $id): bool => $allSites->has($id))
                        ->unique()
                        ->values()
                        ->all();
                if ($siteIds !== null && array_intersect($siteIds, $sites) === []) {
                    return null;
                }

                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'role' => (string) ($user->roles->first()?->label ?? $user->roles->first()?->name ?? 'Staff'),
                    'houses' => $everywhere ? [] : array_map(fn (int $id): string => (string) $allSites[$id], $sites),
                    'site_ids' => $sites,
                    'all_houses' => $everywhere,
                    'controlled' => $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Follow-up settings that only work together (P11 B2 chunk 3): a re-alert
     * needs both how often and how many times, and an escalation needs
     * someone to escalate to — whatever this save changes and what's saved.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null, value: string, from: string}>  $changes
     */
    private function assertFollowUpConsistent(array $changes): void
    {
        $changed = [];
        foreach ($changes as $index => $change) {
            if ($change['definition']->group === 'delivery') {
                $changed[$change['definition']->key] = [$change['value'], $index];
            }
        }
        if ($changed === []) {
            return;
        }
        $value = function (string $key) use ($changed): string {
            if (isset($changed[$key])) {
                return $changed[$key][0];
            }
            $definition = $this->settingsRegistry->definition('delivery', $key);

            return $definition === null
                ? MedicationSettingsRegistry::FOLLOW_UP_OFF
                : $definition->normalise(AppSetting::query()->where('key', $definition->storageKey)->value('value'));
        };
        $field = function (string ...$keys) use ($changed): string {
            foreach ($keys as $key) {
                if (isset($changed[$key])) {
                    return 'changes.'.$changed[$key][1].'.value';
                }
            }

            return 'changes.0.value';
        };
        $off = MedicationSettingsRegistry::FOLLOW_UP_OFF;
        if (($value('realert_every') === $off) !== ($value('realert_max') === $off)) {
            throw ValidationException::withMessages([
                $field('realert_every', 'realert_max') => 'Re-alerting needs both how often and how many times — or switch it off.',
            ]);
        }
        if ($value('escalate_after') !== $off && (json_decode($value('escalate_to'), true) ?: []) === []) {
            throw ValidationException::withMessages([
                $field('escalate_to', 'escalate_after') => 'Choose who it escalates to.',
            ]);
        }
    }

    /**
     * Quiet hours need both times, and different ones (P11 v5, B2 chunk 5):
     * the organisation's pair — whatever this save changes and what's saved —
     * and each house with its own hours.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null, value: string, from: string}>  $changes
     */
    private function assertQuietHoursConsistent(array $changes): void
    {
        $org = [];
        foreach ($changes as $index => $change) {
            $definition = $change['definition'];
            if ($definition->group === 'delivery' && in_array($definition->key, ['quiet_from', 'quiet_until'], true)) {
                $org[$definition->key] = [$change['value'], $index];
            }
            if ($definition->group === 'quietHouse' && $change['site_id'] !== null) {
                $q = (new QuietHoursCodec)->decode($change['value']);
                if ($q === null || $q['mode'] !== QuietHoursCodec::OWN) {
                    continue;
                }
                $house = (string) (Site::query()->whereKey($change['site_id'])->value('name') ?? 'This house');
                if ($q['from'] === '' || $q['until'] === '') {
                    throw ValidationException::withMessages([
                        "changes.{$index}.value" => $house.': choose when its quiet hours start and end.',
                    ]);
                }
                if ($q['from'] === $q['until']) {
                    throw ValidationException::withMessages([
                        "changes.{$index}.value" => $house.': quiet hours can’t start and end at the same time.',
                    ]);
                }
            }
        }
        if ($org === []) {
            return;
        }
        $value = function (string $key) use ($org): string {
            if (isset($org[$key])) {
                return $org[$key][0];
            }
            $definition = $this->settingsRegistry->definition('delivery', $key);

            return $definition === null
                ? TimeCodec::OFF
                : $definition->normalise(AppSetting::query()->where('key', $definition->storageKey)->value('value'));
        };
        $field = 'changes.'.($org['quiet_from'][1] ?? $org['quiet_until'][1]).'.value';
        $from = $value('quiet_from');
        $until = $value('quiet_until');
        if (($from === TimeCodec::OFF) !== ($until === TimeCodec::OFF)) {
            throw ValidationException::withMessages([$field => 'Choose when quiet hours start and end.']);
        }
        if ($from !== TimeCodec::OFF && $from === $until) {
            throw ValidationException::withMessages([$field => 'Quiet hours can’t start and end at the same time.']);
        }
    }

    /**
     * Re-check authority with the actor and the houses locked, inside the save.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null}>  $items
     */
    private function assertCurrentSettingsAuthority(User $lockedActor, array $items): void
    {
        $this->assertSettingsAuthority($lockedActor, $items);
        $siteIds = collect($items)->pluck('site_id')->filter()->unique()->values()->all();
        if ($siteIds === []) {
            return;
        }
        $lockedSites = $this->lockCurrentRuleSites($siteIds);
        foreach ($siteIds as $siteId) {
            $this->assertCurrentRuleSite($lockedActor, (int) $siteId, $lockedSites, false);
        }
    }

    /**
     * Reset another person's witness PIN: they must choose a new one before
     * they can witness or co-sign. Nobody sees the old or new PIN. Audited.
     */
    public function resetWitnessPin(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.witness_pin.reset'), 403);
        abort_if((int) $user->id === (int) $actor->id, 403);

        $target = $this->witnessPinStaffQuery($actor)?->whereKey($user->id)->first(['id', 'name', 'approved_at', 'role']);
        abort_unless($target !== null && $this->isSecondPerson($target), 404);
        abort_unless($this->canResetPinOf($actor, $target), 403);

        $this->witnessPins->resetByAdmin($user, $actor);

        return redirect()->back()->with('medication_settings_saved', $user->name.'’s witness PIN was reset. They must choose a new one before they can co-sign or witness.');
    }

    /**
     * Remind people to set a witness PIN (P11 PIN status): an in-app reminder,
     * plus push if they've set it up, opening their account › Witness PIN.
     * Only people with no usable PIN (not set, or reset) are reminded, by
     * someone who could reset their PIN, at most once a day each. Audited.
     */
    public function remindWitnessPins(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.witness_pin.reset'), 403);
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:'.self::WITNESS_PIN_STAFF_LIMIT],
            'user_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $ids = array_values(array_map('intval', $validated['user_ids']));
        abort_if(in_array((int) $actor->id, $ids, true), 403);

        // Anyone outside the people this person can see is not there to remind.
        $targets = $this->witnessPinStaffQuery($actor)?->whereKey($ids)->get(['id', 'name', 'approved_at', 'role']) ?? collect();
        abort_unless($targets->count() === count($ids) && $targets->every(fn (User $user): bool => $this->isSecondPerson($user)), 404);
        abort_unless($targets->every(fn (User $user): bool => $this->canResetPinOf($actor, $user)), 403);

        $result = DB::transaction(function () use ($actor, $targets, $ids): array {
            // One reminder a day each, even when two people press at once.
            User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id']);
            $statuses = $this->witnessPins->statuses($ids);
            $startOfToday = now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay()->utc();
            $remindedToday = WitnessPinReminder::query()
                ->whereIn('user_id', $ids)
                ->where('created_at', '>=', $startOfToday)
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->flip();

            $sent = [];
            $already = [];
            $hasPin = [];
            foreach ($targets as $target) {
                if (! in_array($statuses[(int) $target->id] ?? null, [WitnessPinService::STATUS_NOT_SET, WitnessPinService::STATUS_RESET], true)) {
                    $hasPin[] = $target;
                } elseif ($remindedToday->has((int) $target->id)) {
                    $already[] = $target;
                } else {
                    WitnessPinReminder::query()->create(['user_id' => $target->id, 'reminded_by' => $actor->id]);
                    $sent[] = $target;
                }
            }
            if ($sent !== []) {
                AuditLogger::logOrFail('medications.witness_pin.reminded', null, [
                    'actor_id' => (int) $actor->id,
                    'user_ids' => array_map(fn (User $user): int => (int) $user->id, $sent),
                ]);
            }

            return [$sent, $already, $hasPin];
        });
        [$sent, $already, $hasPin] = $result;
        foreach ($sent as $target) {
            $target->notify(new WitnessPinReminderNotification((string) $actor->name));
        }

        $names = fn (array $users): string => collect($users)->pluck('name')->join(', ', ' and ');
        $message = $sent === []
            ? 'No reminder was sent.'
            : (count($sent) === 1 ? 'Reminder sent to '.$sent[0]->name.'.' : 'Reminders sent to '.count($sent).' people.');
        if ($already !== []) {
            $message .= ' Already reminded today: '.$names($already).'.';
        }
        if ($hasPin !== []) {
            $message .= ' Already has a PIN: '.$names($hasPin).'.';
        }

        return redirect()->back()->with('medication_settings_saved', $message);
    }

    /**
     * Staff in the actor's approved Sites who may be chosen as a second person,
     * filtered by permission before the cap so the list isn't cut short.
     *
     * @return Collection<int, User>
     */
    private function witnessPinStaff(User $actor): Collection
    {
        return $this->witnessPinStaffQuery($actor)
            ?->lazy(200)
            ->filter(fn (User $user): bool => $this->isSecondPerson($user))
            ->take(self::WITNESS_PIN_STAFF_LIMIT)
            ->collect()
            ->values() ?? collect();
    }

    /** Null when the actor can see no Site's staff. */
    private function witnessPinStaffQuery(User $actor): ?Builder
    {
        // Permissions loaded with each person, so isSecondPerson() and the
        // reset check answer from memory — the same canDo() checks, cached.
        $query = User::query()->staff()->whereNotNull('approved_at')->orderBy('name')->orderBy('id')
            ->select(['id', 'name', 'approved_at', 'role'])
            ->with(['roles.permissions', 'permissionOverrides']);
        if (! $this->canManageGlobalRules($actor)) {
            $siteIds = $this->siteAccess->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
            if ($siteIds === []) {
                return null;
            }
            $query->where(function (Builder $scoped) use ($siteIds): void {
                foreach ($siteIds as $siteId) {
                    $scoped->orWhere(fn (Builder $site) => $this->siteAccess->applyFleetRecipientEligibility($site, (int) $siteId));
                }
            });
        }

        return $query;
    }

    /**
     * Status-only rows for the staff PIN list (never the actor), each saying
     * whether this actor may reset that person's PIN.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function witnessPinStaffRows(User $actor, bool $canResetPins): Collection
    {
        $staff = $this->witnessPinStaff($actor)
            ->reject(fn (User $user): bool => (int) $user->id === (int) $actor->id)
            ->values();
        $resettable = $canResetPins
            ? $staff->filter(fn (User $user): bool => $this->canResetPinOf($actor, $user))->pluck('id')->flip()
            : collect();

        $houses = $this->primaryHouses($staff->pluck('id')->all());
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $reminders = WitnessPinReminder::query()
            ->with('remindedBy:id,name')
            ->whereIn('id', WitnessPinReminder::query()
                ->selectRaw('MAX(id)')
                ->whereIn('user_id', $staff->pluck('id')->all())
                ->groupBy('user_id'))
            ->get()
            ->keyBy('user_id');

        $today = now($timezone)->toDateString();

        return $this->witnessPins->statusRows($staff)
            ->map(function (array $row) use ($resettable, $houses, $reminders, $timezone, $today): array {
                $reminder = $reminders->get($row['id']);
                $remindedAt = $reminder?->created_at?->copy()->timezone($timezone);

                return $row + [
                    'can_reset' => $resettable->has($row['id']),
                    'house' => $houses[$row['id']]['name'] ?? null,
                    'house_id' => $houses[$row['id']]['id'] ?? null,
                    'reminded_at' => $remindedAt?->toIso8601String(),
                    'reminded_by' => $reminder?->remindedBy?->name,
                    // One reminder per person per NZ day (Q-G).
                    'reminded_today' => $remindedAt?->toDateString() === $today,
                ];
            })
            ->values();
    }

    /**
     * Each person's own house (their HR profile's primary Site), for the PIN
     * status list's house filter and "At a house" (by id: names repeat).
     *
     * @param  list<int>  $userIds
     * @return array<int, array{id: int, name: string}>
     */
    private function primaryHouses(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $profiles = HrEmployeeProfile::query()
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('primary_site_id', 'user_id');
        $names = Site::query()->whereIn('id', $profiles->filter()->unique()->values()->all())->pluck('name', 'id');

        return $profiles
            ->filter(fn (mixed $siteId): bool => $siteId && isset($names[(int) $siteId]))
            ->mapWithKeys(fn (mixed $siteId, mixed $userId): array => [
                (int) $userId => ['id' => (int) $siteId, 'name' => $names[(int) $siteId]],
            ])
            ->all();
    }

    private function isSecondPerson(User $user): bool
    {
        return collect(self::SECOND_PERSON_PERMISSIONS)->contains(fn (string $key): bool => $user->canDo($key));
    }

    /**
     * Organisation-wide leads may reset anyone in their list. A house lead may
     * not reset someone with broader authority than theirs: another PIN
     * resetter, an eMAR settings manager or an all-Sites user.
     */
    private function canResetPinOf(User $actor, User $target): bool
    {
        return app(WitnessPinResetAuthority::class)->allows($actor, $target);
    }

    /**
     * Observation tokens must match EnhancedMarService::validateRequiredObservations().
     */
    public const OBSERVATION_OPTIONS = [
        ['value' => 'blood_glucose', 'label' => 'Blood glucose (BSL)'],
        ['value' => 'pulse', 'label' => 'Pulse rate'],
        ['value' => 'blood_pressure', 'label' => 'Blood pressure'],
    ];

    public const MATCH_TYPES = [
        ['value' => 'medicine_name', 'label' => 'Medicine name'],
        ['value' => 'route', 'label' => 'Route'],
        ['value' => 'nzulm_code', 'label' => 'NZULM code'],
        ['value' => 'controlled', 'label' => 'Controlled status'],
    ];

    public function index(Request $request)
    {
        $actor = $request->user();
        $canResetPins = (bool) $actor?->canDo('medications.witness_pin.reset');
        $canManage = $this->canManageSettings($actor);
        // P11 answer 6: auditors read Settings and its change history, never change it.
        $readOnlyAudit = ! $canManage && (bool) $actor?->canDo('medications.audit.view');
        $canView = $canManage || $readOnlyAudit;
        // P11 F1: whoever manages round templates at a house (orders.manage)
        // reaches Rounds & timing › Round templates, and only that. Q2: anyone
        // who could read templates on Meds today › Rounds (medications.view)
        // still can, read-only.
        $canTemplates = $actor !== null && $this->roundTemplates->canManageAny($actor);
        $canReadTemplates = $canTemplates || (bool) $actor?->canDo('medications.view');
        // P11 B2 Q3: house managers reach Alerts & access for their own houses' extras.
        $canHouseAlerts = $this->canManageHouseAlerts($actor);
        abort_unless($canView || $canResetPins || $canReadTemplates || $canHouseAlerts, 403);

        $canManageGlobal = $this->canManageGlobalRules($actor);
        $houseSiteIds = $canManage || $canHouseAlerts ? $this->ownHouseSiteIds($actor) : [];
        $siteIds = $canView ? $this->accessibleSiteIds($actor) : $houseSiteIds;
        $alertPayload = $this->alertPayload($actor, $canView, $canManage, $canManageGlobal, $canHouseAlerts, $siteIds, $houseSiteIds);
        // Rules that name a controlled medicine are concealed from anyone
        // without controlled-medicine access, here and in the history (EM-12).
        $canSeeControlled = $this->ruleScope->canSeeControlled($actor);
        $controlledRuleIds = $canView && ! $canSeeControlled
            ? $this->ruleScope->controlledRuleIds(MedicationAdminRule::query()->get())
            : [];
        $settings = [
            ...$this->settingsRegistry->toClient(),
            'values' => $this->settingsStore->organisationValues(),
            // null = still the default; nobody has deliberately saved or kept it.
            'reviewed' => $this->settingsStore->reviewed(),
            'site_values' => $this->settingsStore->siteValues($siteIds),
            'site_reviewed' => $this->settingsStore->siteReviewed($siteIds),
            // Organisation-wide changes, plus changes at the houses this person can see.
            'history' => $canView
                ? $this->settingsStore->history($canManageGlobal ? null : $siteIds, 500, $canSeeControlled, $controlledRuleIds)
                : [],
            'can_manage_organisation' => $canManage && $canManageGlobal,
        ];
        $witnessPin = [
            'can_reset' => $canResetPins,
            // PIN status is for settings readers and PIN resetters only, not
            // for people here just for round templates.
            'staff' => $canView || $canResetPins ? $this->witnessPinStaffRows($actor, $canResetPins) : [],
        ];

        $roundTemplates = $this->roundTemplatePayload($actor, $canTemplates, $canView || $canReadTemplates);

        // House leads can reset staff PINs and manage their houses' round
        // templates without managing medication rules: they see only those.
        if (! $canView) {
            return Inertia::render('emar/Settings', [
                'settings' => $settings,
                'witnessPin' => $witnessPin,
                ...$roundTemplates,
                ...$alertPayload,
                'settingsAccess' => false,
                'readOnlyAudit' => false,
                'rules' => [],
                'ruleOptions' => ['names' => [], 'routes' => [], 'nzulm' => []],
                // A house manager's own houses, for their alert extras.
                'sites' => $canHouseAlerts ? $this->siteChoices($houseSiteIds) : [],
                'observationOptions' => self::OBSERVATION_OPTIONS,
                'matchTypes' => self::MATCH_TYPES,
                'can' => ['manage' => false, 'manage_global' => false],
            ]);
        }

        // Everyone who opens Settings reads the rules for every house as well as
        // their own houses' rules; only all-sites authority changes them (P11 v5).
        $ruleModels = $this->visibleRulesQuery($actor, $siteIds, true)
            ->with(['site:id,name', 'creator:id,name'])
            ->orderByDesc('active')
            ->orderBy('match_type')
            ->orderBy('match_value')
            ->get();
        $latestRuleChanges = $this->settingsStore->latestRuleChanges(
            $ruleModels->map(fn (MedicationAdminRule $rule): int => (int) $rule->id)->all(),
        );
        $workerTimezone = config('app.worker_timezone', 'Pacific/Auckland');
        // Overlaps use the same person scope as the preview (MAR & medicines).
        $visibleOrders = $this->ruleScope->visibleOrders($actor, $canManageGlobal ? null : $siteIds);
        $concealed = array_flip($controlledRuleIds);
        $rules = $ruleModels->map(function (MedicationAdminRule $rule) use ($latestRuleChanges, $actor, $canManage, $workerTimezone, $visibleOrders, $ruleModels, $concealed): array {
            $change = $latestRuleChanges->get((int) $rule->id);
            if (isset($concealed[(int) $rule->id])) {
                return [
                    'id' => $rule->id,
                    'site_id' => $rule->site_id,
                    'site_name' => $rule->site?->name,
                    'match_type' => $rule->match_type,
                    'match_value' => '',
                    'requires_countersign' => false,
                    'required_observations' => [],
                    'active' => $rule->active,
                    'what' => 'Controlled-medicine rule',
                    'needs' => 'Details need controlled-medicine access',
                    'sentence' => 'Only people with controlled-medicine access can see this rule’s details.',
                    'last_changed_by' => $change?->actor?->name ?? $rule->creator?->name,
                    'last_changed_at' => ($change?->created_at ?? $rule->created_at)?->toIso8601String(),
                    'paused_note' => null,
                    'can_change' => false,
                    'concealed' => true,
                    'overlaps' => [],
                ];
            }

            return [
                'id' => $rule->id,
                'site_id' => $rule->site_id,
                'site_name' => $rule->site?->name,
                'match_type' => $rule->match_type,
                'match_value' => $rule->match_value,
                'requires_countersign' => $rule->requires_countersign,
                'required_observations' => $rule->required_observations ?? [],
                'active' => $rule->active,
                'what' => $this->ruleWording->what($rule),
                'needs' => $this->ruleWording->needs($rule),
                'sentence' => $this->ruleWording->sentence($rule),
                'last_changed_by' => $change?->actor?->name ?? $rule->creator?->name,
                'last_changed_at' => ($change?->created_at ?? $rule->created_at)?->toIso8601String(),
                'paused_note' => ! $rule->active && $change
                    ? 'Paused '.$change->created_at->copy()->timezone($workerTimezone)->format('j M Y').' by '.($change->actor?->name ?? 'someone')
                    : null,
                'can_change' => $canManage && $this->canUseRuleSiteNow($actor, $rule->site_id),
                'concealed' => false,
                'overlaps' => $rule->active ? $this->ruleScope->overlaps($visibleOrders, $rule, $ruleModels) : [],
            ];
        })->values();

        return Inertia::render('emar/Settings', [
            'settings' => $settings,
            'witnessPin' => $witnessPin,
            ...$roundTemplates,
            ...$alertPayload,
            'settingsAccess' => true,
            'readOnlyAudit' => $readOnlyAudit,
            'rules' => $rules,
            // A concealed rule's medicine never becomes a choice either.
            'ruleOptions' => $this->ruleOptions(
                $actor,
                $siteIds,
                $canManageGlobal || $readOnlyAudit,
                $ruleModels->reject(fn (MedicationAdminRule $rule): bool => isset($concealed[(int) $rule->id]))->values(),
            ),
            'sites' => Site::query()
                ->whereIn('id', $siteIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'observationOptions' => self::OBSERVATION_OPTIONS,
            'matchTypes' => self::MATCH_TYPES,
            'can' => [
                'manage' => $canManage && ($canManageGlobal || $siteIds !== []),
                'manage_global' => $canManage && $canManageGlobal,
            ],
        ]);
    }

    /**
     * Set a house's on-call contact (P11 B2 chunk 4). It saves straight away —
     * not through the page draft — and is recorded in the change history and
     * the audit log. The backup must be an employed staff member with access
     * to the house and a work phone on their staff record.
     */
    public function saveOnCall(Request $request, Site $site)
    {
        $actor = $request->user();
        abort_unless($actor && $this->canManageOnCall($actor, (int) $site->id), 403);
        $validated = $request->validate([
            'mode' => ['required', Rule::in([MedicationOnCallRule::ROSTER, MedicationOnCallRule::FIXED])],
            'team_lead' => ['required', 'boolean'],
            'backup_user_id' => ['nullable', 'integer'],
        ]);
        $roster = $validated['mode'] === MedicationOnCallRule::ROSTER;
        $pick = collect($this->onCallStaff([(int) $site->id], now())[(int) $site->id] ?? [])
            ->firstWhere('id', (int) ($validated['backup_user_id'] ?? 0));
        if ($pick === null || ! $pick['ok']) {
            throw ValidationException::withMessages([
                'backup_user_id' => $roster ? 'Choose who staff call when nobody is rostered on call.' : 'Choose the on-call person.',
            ]);
        }

        DB::transaction(function () use ($actor, $site, $validated, $roster): void {
            $lockedActor = $this->lockCurrentRuleActor($actor, true);
            abort_unless($this->canManageOnCall($lockedActor, (int) $site->id), 403);
            $resolver = app(OnCallResolver::class);
            $rule = MedicationOnCallRule::query()->with('backup')->where('site_id', $site->id)->lockForUpdate()->first();
            $before = $resolver->describe($rule);
            $rule ??= new MedicationOnCallRule(['site_id' => $site->id]);
            $rule->fill([
                'mode' => $validated['mode'],
                'team_lead' => $roster && (bool) $validated['team_lead'],
                'backup_user_id' => (int) $validated['backup_user_id'],
                'updated_by' => $lockedActor->id,
            ])->save();
            $rule->load('backup');
            $after = $resolver->describe($rule);
            if ($before === $after) {
                return;
            }
            $this->settingsStore->recordOnCallChange($lockedActor, (int) $site->id, 'On-call contact', $before, $after, false, 'medications.oncall_contact.updated');
            AuditLogger::logOrFail('medications.oncall_contact.updated', $rule, [
                'actor_id' => (int) $lockedActor->id,
                'site_id' => (int) $site->id,
                'before' => $before,
                'after' => $after,
            ]);
        }, 3);

        return redirect()->back()->with('medication_settings_saved', 'On-call contact saved for '.$site->name.'.');
    }

    /** Remove a house's on-call contact: screens there say "Not configured" again (P11 B2 chunk 4). */
    public function removeOnCall(Request $request, Site $site)
    {
        $actor = $request->user();
        abort_unless($actor && $this->canManageOnCall($actor, (int) $site->id), 403);

        DB::transaction(function () use ($actor, $site): void {
            $lockedActor = $this->lockCurrentRuleActor($actor, true);
            abort_unless($this->canManageOnCall($lockedActor, (int) $site->id), 403);
            $rule = MedicationOnCallRule::query()->with('backup')->where('site_id', $site->id)->lockForUpdate()->first();
            if ($rule === null) {
                return;
            }
            $before = app(OnCallResolver::class)->describe($rule);
            $rule->delete();
            $this->settingsStore->recordOnCallChange($lockedActor, (int) $site->id, 'On-call contact removed', $before, 'Not configured', true, 'medications.oncall_contact.removed');
            AuditLogger::logOrFail('medications.oncall_contact.removed', null, [
                'actor_id' => (int) $lockedActor->id,
                'site_id' => (int) $site->id,
                'before' => $before,
            ]);
        }, 3);

        return redirect()->back()->with('medication_settings_saved', 'On-call contact removed for '.$site->name.'. Screens show “Not configured” again.');
    }

    /**
     * Who changes a house's on-call contact (v5 canHouse): a medication
     * settings manager with access to the house, or a house manager at one
     * of their own houses (B2 Q3). Only houses that count (Q12).
     */
    private function canManageOnCall(User $actor, int $siteId): bool
    {
        return $this->alertHouseIds([$siteId]) !== [] && $this->onCallManageable($actor, [$siteId]) === [$siteId];
    }

    /**
     * Of these houses (already counted as houses), those whose on-call
     * contact this person changes — the permission checks done once.
     *
     * @param  list<int>  $houseIds
     * @return list<int>
     */
    private function onCallManageable(User $actor, array $houseIds): array
    {
        $settings = $actor->canDo('medications.settings.manage');
        $all = $settings && $this->canManageGlobalRules($actor);
        $accessible = $settings && ! $all ? $this->accessibleSiteIds($actor) : [];
        $own = $actor->canDo('medications.alerts.manage_house') ? $this->ownHouseSiteIds($actor) : [];

        return array_values(array_filter(
            $houseIds,
            fn (int $id): bool => $all || in_array($id, $accessible, true) || in_array($id, $own, true),
        ));
    }

    /**
     * On-call contacts for the houses this person sees: each house's rule,
     * its house leads, the roster for the next nights (to preview a rule),
     * and — where they can change it — the staff who can be the backup.
     *
     * @param  list<int>  $houseIds
     * @return array{houses: list<array<string, mixed>>, staff: array<int, list<array<string, mixed>>>}
     */
    private function onCallPayload(User $actor, array $houseIds): array
    {
        if ($houseIds === []) {
            return ['houses' => [], 'staff' => []];
        }
        $resolver = app(OnCallResolver::class);
        $now = now();
        $rules = MedicationOnCallRule::query()
            ->with(['backup.hrEmployeeProfile', 'updatedBy:id,name'])
            ->whereIn('site_id', $houseIds)
            ->get()
            ->keyBy('site_id');
        $leads = User::query()
            ->whereNotNull('approved_at')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'team_lead'))
            ->with('hrEmployeeProfile')
            ->orderBy('name')
            ->get();
        $person = fn (?User $user): ?array => $user === null ? null : [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'phone' => $resolver->phoneOf($user),
        ];
        $manageable = $this->onCallManageable($actor, array_map('intval', $houseIds));
        $rosters = $resolver->rosters(array_map('intval', $houseIds), $now);
        $houses = [];
        foreach (Site::query()->whereIn('id', $houseIds)->orderBy('name')->get(['id', 'name']) as $site) {
            $siteId = (int) $site->id;
            $rule = $rules->get($siteId);
            $can = in_array($siteId, $manageable, true);
            $houses[] = [
                'site_id' => $siteId,
                'name' => (string) $site->name,
                'house_leads' => $leads
                    ->filter(fn (User $lead): bool => in_array($siteId, $this->profileSiteIds($lead->hrEmployeeProfile), true))
                    ->pluck('name')
                    ->values()
                    ->all(),
                'rule' => $rule === null ? null : [
                    'mode' => (string) $rule->mode,
                    'team_lead' => (bool) $rule->team_lead,
                    'backup' => $person($rule->backup),
                    'describe' => $resolver->describe($rule),
                    'changed_by' => $rule->updatedBy?->name,
                    'changed_at' => $rule->updated_at?->toIso8601String(),
                ],
                'roster' => array_map(fn (array $night): array => [
                    'label' => $night['label'],
                    'hours' => $night['hours'],
                    'on_call' => $person($night['on_call']),
                    'team_lead' => $person($night['team_lead']),
                ], $rosters[$siteId] ?? []),
                'can_manage' => $can,
            ];
        }

        return ['houses' => $houses, 'staff' => $this->onCallStaff($manageable, $now)];
    }

    /**
     * Who can be a house's on-call backup (v5): employed staff with access to
     * the house. Without a work phone on their staff record they're listed but
     * can't be chosen; nights they're on approved leave are marked.
     *
     * @param  list<int>  $siteIds
     * @return array<int, list<array{id: int, name: string, role: string, phone: string|null, ok: bool, why: string|null, away: list<int>, leave: string|null}>>
     */
    private function onCallStaff(array $siteIds, CarbonInterface $now): array
    {
        if ($siteIds === []) {
            return [];
        }
        $resolver = app(OnCallResolver::class);
        $windows = $resolver->windows($now);
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $profiles = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->whereHas('user', fn ($u) => $u->whereNotNull('approved_at'))
            ->with(['user.roles:id,name,label'])
            ->get();
        $leave = HrLeaveRequest::query()
            ->approved()
            ->whereIn('user_id', $profiles->pluck('user_id')->all())
            ->where('starts_at', '<', end($windows)['until'])
            ->where('ends_at', '>', $windows[0]['from'])
            ->get(['user_id', 'starts_at', 'ends_at'])
            ->groupBy('user_id');
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $out = [];
        foreach ($siteIds as $siteId) {
            $rows = [];
            foreach ($profiles as $profile) {
                if (! in_array((int) $siteId, $this->profileSiteIds($profile), true) || ! $profile->user instanceof User) {
                    continue;
                }
                $user = $profile->user;
                $user->setRelation('hrEmployeeProfile', $profile);
                $phone = $resolver->phoneOf($user);
                $theirs = $leave->get($user->id, collect());
                $away = [];
                foreach ($windows as $i => $night) {
                    if ($theirs->contains(fn ($l): bool => $l->starts_at < $night['until'] && $l->ends_at > $night['from'])) {
                        $away[] = $i;
                    }
                }
                $first = $theirs->sortBy('starts_at')->first();
                $rows[] = [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'role' => (string) ($user->roles->first()?->label ?? $user->roles->first()?->name ?? 'Staff'),
                    'phone' => $phone,
                    'ok' => $phone !== null,
                    'why' => $phone === null ? 'no work phone on their staff record' : null,
                    'away' => $away,
                    'leave' => $first === null ? null : sprintf(
                        'On leave %s – %s (Leave hub)',
                        Carbon::parse($first->starts_at)->timezone($timezone)->format('D j'),
                        Carbon::parse($first->ends_at)->timezone($timezone)->format('D j M'),
                    ),
                ];
            }
            usort($rows, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $out[(int) $siteId] = $rows;
        }

        return $out;
    }

    /** @return list<int> A profile's primary and other houses. */
    private function profileSiteIds(?HrEmployeeProfile $profile): array
    {
        if (! $profile instanceof HrEmployeeProfile) {
            return [];
        }

        return collect([$profile->primary_site_id, ...($profile->secondary_site_ids ?? [])])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Alerts & access (P11 B2): whether this person sees it, whether they set
     * who gets each alert organisation-wide, the houses whose extras they
     * change, the people they can name, and the names of everyone already
     * named on an alert they can see.
     *
     * @param  list<int>  $siteIds  Houses whose settings this person reads.
     * @param  list<int>  $houseSiteIds  Their own houses (HR profile).
     * @return array{alertAccess: array<string, mixed>, alertPeople: list<array<string, mixed>>, alertNames: array<int, string>, alertReach: array<string, mixed>, alertNobodyOpen: int, alertPreviews: array<string, mixed>, alertDelivery: array{push_ready: int, people: int}, onCall: array<string, mixed>, alertHouses: list<array{id: int, name: string}>, alertReachGaps: list<array<string, mixed>>}
     */
    private function alertPayload(User $actor, bool $canView, bool $canManage, bool $canManageGlobal, bool $canHouseAlerts, array $siteIds, array $houseSiteIds): array
    {
        $view = $canView || $canHouseAlerts;
        $manageOrg = $canManage && $canManageGlobal;
        $houseIds = $canManage
            ? ($canManageGlobal ? $siteIds : $houseSiteIds)
            : ($canHouseAlerts ? $houseSiteIds : []);
        if (! $view) {
            return [
                'alertAccess' => ['view' => false, 'manage_org' => false, 'house_ids' => []],
                'alertPeople' => [],
                'alertNames' => [],
                'alertReach' => ['houses' => [], 'people' => []],
                'alertNobodyOpen' => 0,
                'alertPreviews' => [],
                'alertDelivery' => ['push_ready' => 0, 'people' => 0],
                'onCall' => ['houses' => [], 'staff' => []],
                'alertHouses' => [],
                'alertReachGaps' => [],
            ];
        }

        $named = [];
        foreach ($this->settingsStore->organisationValues()['alerts'] ?? [] as $value) {
            array_push($named, ...(json_decode($value, true)['people'] ?? []));
        }
        foreach ($this->settingsStore->siteValues($siteIds) as $groups) {
            foreach ($groups['alertExtra'] ?? [] as $value) {
                array_push($named, ...(json_decode($value, true) ?? []));
            }
        }

        $recipients = app(MedicationAlertRecipients::class);
        // Worked out once per page: the picker and the warning share it.
        $housePeople = $manageOrg ? [] : ($houseIds !== [] ? $this->alertPeople($houseIds) : []);
        $readable = array_values(array_unique([...$named, ...array_column($housePeople, 'id')]));
        $people = $manageOrg ? $this->alertPeople(null) : $housePeople;
        // Everyone who can get alerts at the houses this person sees (B2
        // chunk 5): "Staff with push set up" and "Who can't be reached".
        $visiblePeople = match (true) {
            $manageOrg => $people,
            $canManageGlobal => $this->alertPeople(null),
            $siteIds === $houseIds => $housePeople,
            default => $siteIds === [] ? [] : $this->alertPeople($siteIds),
        };
        $peopleIds = array_column($visiblePeople, 'id');
        $alertHouseIds = $this->alertHouseIds($siteIds);
        $onCall = $this->onCallPayload($actor, $alertHouseIds);

        return [
            'alertAccess' => [
                'view' => true,
                'manage_org' => $manageOrg,
                'house_ids' => array_values(array_map('intval', $houseIds)),
            ],
            // The safety net's warning (P11 B2): who each group would tell at
            // each house, from the same resolution an alert uses.
            'alertReach' => [
                'houses' => $recipients->reach($alertHouseIds, now()),
                'people' => $recipients->peopleReach($readable),
            ],
            // Open alerts nobody could be told about — never only a log row.
            'alertNobodyOpen' => MedicationAlert::query()
                ->whereNotNull('open_key')
                ->where('reached_nobody', true)
                ->when(! $canManageGlobal, fn ($query) => $query->whereIn('site_id', $siteIds))
                ->count(),
            // Organisation editors name anyone; house managers people at their houses.
            'alertPeople' => $people,
            // On-call contacts (B2 chunk 4): one per house this person sees.
            'onCall' => $onCall,
            // Quiet hours (B2 chunk 5): one row per house this person sees.
            'alertHouses' => $this->siteChoices($alertHouseIds),
            // Who can't be reached (B2 chunk 5): people with a contact gap;
            // the page works out from its draft which gaps matter.
            'alertReachGaps' => app(MedicationAlertReachGaps::class)->rows($visiblePeople, $onCall['houses'], now()),
            // Message preview (B2 chunk 2): synthetic samples through the real
            // notification, with the privacy switch on and off.
            'alertPreviews' => app(MedicationAlertPreviews::class)->all(),
            // Delivery › Push: of the people who can get alerts at the houses
            // this person sees, how many have a phone or browser allowed.
            'alertDelivery' => [
                'push_ready' => $peopleIds === [] ? 0 : UserPushSubscription::query()
                    ->whereIn('user_id', $peopleIds)
                    ->where('enabled', true)
                    ->distinct()
                    ->count('user_id'),
                'people' => count($peopleIds),
            ],
            'alertNames' => $named === [] ? [] : User::query()
                ->whereIn('id', array_values(array_unique($named)))
                ->pluck('name', 'id')
                ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
                ->all(),
        ];
    }

    /**
     * Where people live and medicines are given (P11 B2 Q12): every Site that
     * isn't head office, and head office too if it has active orders.
     *
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    private function alertHouseIds(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return Site::query()
            ->whereIn('id', $siteIds)
            ->where('is_active', true)
            ->where(fn ($sites) => $sites
                ->whereIn('type', ['house', 'residential'])
                ->orWhereNull('type')
                ->orWhere(fn ($facility) => $facility->where('type', 'facility')->whereHas('clients'))
                ->orWhereHas('clients.medications', fn ($orders) => $orders->active()))
            ->orderBy('name')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }


    /**
     * A person's own houses: their current HR profile's primary and other
     * houses, active only (where house settings can be changed).
     *
     * @return list<int>
     */
    private function ownHouseSiteIds(?User $actor): array
    {
        $profile = $actor?->hrEmployeeProfile;
        if (! $profile instanceof HrEmployeeProfile || ! $profile->is_active) {
            return [];
        }
        $ids = collect([$profile->primary_site_id, ...($profile->secondary_site_ids ?? [])])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return Site::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->where('archived', false)
            ->orderBy('name')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<array{id: int, name: string}>
     */
    private function siteChoices(array $siteIds): array
    {
        return Site::query()
            ->whereIn('id', $siteIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Site $site): array => ['id' => (int) $site->id, 'name' => (string) $site->name])
            ->all();
    }

    /**
     * Round templates (P11 Rounds & timing): the rows this person may read,
     * the houses where they may add one, and the staff who can be a default.
     *
     * @return array{roundTemplates: list<array<string, mixed>>, templateAccess: array<string, mixed>, templateStaff: list<array<string, mixed>>}
     */
    private function roundTemplatePayload(User $actor, bool $canTemplates, bool $canRead): array
    {
        $manageable = $canTemplates ? $this->roundTemplates->manageableSiteIds($actor) : [];

        return [
            'roundTemplates' => $canRead ? $this->roundTemplates->rows($actor) : [],
            'templateAccess' => [
                'read' => $canRead,
                'manage' => $canTemplates,
                'all_houses' => $canTemplates && $this->roundTemplates->hasAllHouses($actor),
                'sites' => Site::query()
                    ->whereIn('id', $manageable)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Site $site): array => ['id' => (int) $site->id, 'name' => (string) $site->name])
                    ->all(),
            ],
            'templateStaff' => $canTemplates ? $this->roundTemplates->staffPicker($actor)->all() : [],
        ];
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);

        $siteIds = $this->accessibleSiteIds($actor);
        $validated = $this->validateRule($request, $siteIds);
        $this->assertCanUseRuleSite($actor, $validated['site_id']);

        DB::transaction(function () use ($actor, $validated): void {
            $this->ruleService->lockRuleSet();
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $lockedSites = $this->lockCurrentRuleSites([$validated['site_id']]);
            $this->assertCurrentRuleSite($lockedActor, $validated['site_id'], $lockedSites, false);

            $controlled = $this->assertMayNameMedicine($lockedActor, new MedicationAdminRule($validated));
            $rule = MedicationAdminRule::create([
                ...$validated,
                'created_by' => $lockedActor->id,
            ]);
            $this->settingsStore->recordRuleChange(
                $lockedActor,
                $rule,
                'Medicine rule added',
                '—',
                $this->ruleWording->sentence($rule->load('site:id,name')),
                false,
                'medicationadminrule.create',
                $controlled,
            );
        }, 3);

        return redirect()->back()->with('medication_settings_saved', $validated['active']
            ? 'Rule added. It applies from the next dose saved.'
            : 'Rule saved as paused. It doesn’t apply until someone turns it on.');
    }

    public function update(Request $request, MedicationAdminRule $rule)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);
        $siteIds = $this->accessibleSiteIds($actor);
        $validated = $this->validateRule($request, $siteIds);

        DB::transaction(function () use ($actor, $rule, $validated): void {
            $rules = $this->ruleService->lockRuleSet();
            /** @var MedicationAdminRule|null $lockedRule */
            $lockedRule = $rules->get((int) $rule->getKey());
            abort_unless($lockedRule instanceof MedicationAdminRule, 404);
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $lockedSites = $this->lockCurrentRuleSites([
                $lockedRule->site_id,
                $validated['site_id'],
            ]);
            $this->assertCurrentRuleSite($lockedActor, $lockedRule->site_id, $lockedSites, true);
            $this->assertCurrentRuleSite($lockedActor, $validated['site_id'], $lockedSites, false);
            $wasControlled = $this->assertMayChangeRule($lockedActor, $lockedRule);
            $controlled = $this->assertMayNameMedicine($lockedActor, new MedicationAdminRule($validated)) || $wasControlled;

            $before = $this->ruleWording->sentence($lockedRule->load('site:id,name'));
            $lockedRule->update($validated);
            $after = $this->ruleWording->sentence($lockedRule->load('site:id,name'));
            if ($before !== $after || $lockedRule->wasChanged('active')) {
                $this->settingsStore->recordRuleChange(
                    $lockedActor,
                    $lockedRule,
                    'Medicine rule changed',
                    $before,
                    $after,
                    $lockedRule->wasChanged('active') && ! $lockedRule->active,
                    'medicationadminrule.update',
                    $controlled,
                );
            }
        }, 3);

        return redirect()->back()->with('medication_settings_saved', 'Rule updated. It applies from the next dose saved.');
    }

    /**
     * Pause a rule or turn it back on (P00 v5: rules are paused, never
     * deleted). Pausing loosens a check: doses no longer need it.
     */
    public function setActive(Request $request, MedicationAdminRule $rule)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        $changed = DB::transaction(function () use ($actor, $rule, $active): bool {
            $rules = $this->ruleService->lockRuleSet();
            /** @var MedicationAdminRule|null $lockedRule */
            $lockedRule = $rules->get((int) $rule->getKey());
            abort_unless($lockedRule instanceof MedicationAdminRule, 404);
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $lockedSites = $this->lockCurrentRuleSites([$lockedRule->site_id]);
            $this->assertCurrentRuleSite($lockedActor, $lockedRule->site_id, $lockedSites, true);
            $controlled = $this->assertMayChangeRule($lockedActor, $lockedRule);
            if ($lockedRule->active === $active) {
                return false;
            }

            $lockedRule->update(['active' => $active]);
            $this->settingsStore->recordRuleChange(
                $lockedActor,
                $lockedRule,
                $active ? 'Medicine rule turned back on' : 'Medicine rule paused',
                $active ? 'Paused' : 'Active',
                $this->ruleWording->sentence($lockedRule->load('site:id,name')),
                ! $active,
                'medicationadminrule.update',
                $controlled,
            );

            return true;
        }, 3);

        return redirect()->back()->with('medication_settings_saved', ! $changed
            ? ($active ? 'This rule is already on.' : 'This rule is already paused.')
            : ($active ? 'Rule turned back on. It applies from the next dose saved.' : 'Rule paused. Doses no longer need it.'));
    }

    /**
     * "Would apply now to N medicines for M people" for a rule being built
     * or viewed, and which other active rules overlap it. Same person scope
     * as MAR & medicines: people whose chart this person can open, at houses
     * they can see; controlled orders only with controlled-medicine access;
     * no count of anything hidden.
     */
    public function previewRule(Request $request): JsonResponse
    {
        $actor = $request->user();
        $readOnlyAudit = ! $this->canManageSettings($actor) && (bool) $actor?->canDo('medications.audit.view');
        abort_unless($this->canManageSettings($actor) || $readOnlyAudit, 403);

        $validated = $request->validate([
            'match_type' => ['required', 'string', Rule::in(array_column(self::MATCH_TYPES, 'value'))],
            'match_value' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer'],
            'rule_id' => ['nullable', 'integer'],
        ]);
        $global = $this->canManageGlobalRules($actor);
        $siteIds = $this->accessibleSiteIds($actor);
        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        abort_if($siteId !== null && ! $global && ! in_array($siteId, $siteIds, true), 404);

        $rule = new MedicationAdminRule([
            'site_id' => $siteId,
            'match_type' => $validated['match_type'],
            'match_value' => $validated['match_type'] === 'controlled' ? '' : trim((string) ($validated['match_value'] ?? '')),
            'active' => true,
        ]);
        $rule->id = (int) ($validated['rule_id'] ?? 0);

        $orders = $this->ruleScope->visibleOrders($actor, $global ? null : $siteIds);
        $others = $this->visibleRulesQuery($actor, $siteIds, true)->with('site:id,name')->get();
        $overlapIds = array_flip($this->ruleScope->overlaps($orders, $rule, $others));
        $concealed = $this->ruleScope->canSeeControlled($actor) ? [] : array_flip($this->ruleScope->controlledRuleIds($others));

        return response()->json([
            ...$this->ruleScope->preview($orders, $rule),
            // Controlled medicines are left out for people who can't see them.
            'limited' => ! $this->ruleScope->canSeeControlled($actor),
            'overlaps' => $others
                ->filter(fn (MedicationAdminRule $other): bool => isset($overlapIds[(int) $other->id]))
                ->map(fn (MedicationAdminRule $other): array => [
                    'id' => $other->id,
                    'sentence' => isset($concealed[(int) $other->id])
                        ? 'A controlled-medicine rule (details need controlled-medicine access).'
                        : $this->ruleWording->sentence($other),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * A rule that names a controlled medicine is hidden from anyone without
     * controlled-medicine access, so they can't change or pause it either:
     * it answers as if it weren't there. Returns whether it is controlled.
     */
    private function assertMayChangeRule(User $actor, MedicationAdminRule $rule): bool
    {
        $controlled = $this->ruleScope->isControlled($rule);
        abort_if($controlled && ! $this->ruleScope->canSeeControlled($actor), 404);

        return $controlled;
    }

    /**
     * Only someone with controlled-medicine access may write a rule that
     * names a controlled medicine. Returns whether the rule does.
     */
    private function assertMayNameMedicine(User $actor, MedicationAdminRule $rule): bool
    {
        $controlled = $this->ruleScope->isControlled($rule);
        if ($controlled && ! $this->ruleScope->canSeeControlled($actor)) {
            throw ValidationException::withMessages([
                'match_value' => 'Choose which medicines this rule applies to.',
            ]);
        }

        return $controlled;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request, array $siteIds): array
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', Rule::in($siteIds)],
            'match_type' => ['required', 'string', Rule::in(array_column(self::MATCH_TYPES, 'value'))],
            // "Controlled status" matches the order's controlled flag, so it names no medicine.
            'match_value' => ['required_unless:match_type,controlled', 'nullable', 'string', 'max:255'],
            'requires_countersign' => ['nullable', 'boolean'],
            'required_observations' => ['nullable', 'array'],
            'required_observations.*' => ['string', Rule::in(array_column(self::OBSERVATION_OPTIONS, 'value'))],
            'active' => ['nullable', 'boolean'],
        ], [
            'match_value.required_unless' => 'Choose which medicines this rule applies to.',
        ]);

        $rule = [
            'site_id' => $validated['site_id'] ?? null,
            'match_type' => $validated['match_type'],
            'match_value' => $validated['match_type'] === 'controlled' ? '' : trim((string) $validated['match_value']),
            'requires_countersign' => (bool) ($validated['requires_countersign'] ?? false),
            'required_observations' => array_values(array_unique($validated['required_observations'] ?? [])),
            'active' => (bool) ($validated['active'] ?? true),
        ];
        if (! $rule['requires_countersign'] && $rule['required_observations'] === []) {
            throw ValidationException::withMessages([
                'requires_countersign' => 'Turn on at least one: a second person or an observation.',
            ]);
        }

        return $rule;
    }

    /**
     * Rules at the given houses, plus the rules for every house when asked.
     *
     * @param  array<int, int>  $siteIds
     * @return Builder<MedicationAdminRule>
     */
    private function visibleRulesQuery(
        User $actor,
        array $siteIds,
        ?bool $includeOrganisationRules = null,
    ): Builder {
        $includeOrganisationRules ??= $this->canManageGlobalRules($actor);

        return MedicationAdminRule::query()
            ->where(function (Builder $scope) use ($includeOrganisationRules, $siteIds): void {
                $scope->whereIn('site_id', $siteIds);
                if ($includeOrganisationRules) {
                    $scope->orWhereNull('site_id');
                }
            });
    }

    /** @return array<int, int> */
    private function accessibleSiteIds(User $actor): array
    {
        return $this->siteAccess->accessibleSiteIds(
            $actor,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );
    }

    private function assertCanUseRuleSite(User $actor, ?int $siteId): void
    {
        if ($siteId === null) {
            abort_unless($this->canManageGlobalRules($actor), 403);
        }
    }

    /**
     * What a rule can match, from current orders at the houses this person can
     * see (P00 v5 builder: medicines and products are chosen, never typed).
     * Controlled orders are left out for anyone without controlled-medicine
     * access. Values already used by a visible rule stay choosable.
     *
     * @param  list<int>  $siteIds
     * @param  Collection<int, MedicationAdminRule>  $rules
     * @return array{names: list<array{name: string, routes: list<string>}>, routes: list<string>, nzulm: list<array{code: string, name: string}>}
     */
    private function ruleOptions(User $actor, array $siteIds, bool $allSites, Collection $rules): array
    {
        $orders = ClientMedication::query()
            ->active()
            ->when(! $allSites, fn (Builder $query) => $query->whereHas(
                'client',
                fn (Builder $client) => $client->whereIn('site_id', $siteIds),
            ))
            ->when(! $actor->canDo('medications.controlled.view'), fn (Builder $query) => $query->where('controlled_drug', false))
            ->get(['id', 'client_id', 'name', 'route', 'nzulm_code']);

        $names = $orders
            ->filter(fn (ClientMedication $order): bool => trim((string) $order->name) !== '')
            ->groupBy(fn (ClientMedication $order): string => trim((string) $order->name))
            ->map(fn (Collection $group, string $name): array => [
                'name' => $name,
                'routes' => $group->pluck('route')->filter()->unique()->sort()->values()->all(),
            ]);
        foreach ($rules->where('match_type', 'medicine_name') as $rule) {
            $names->put($rule->match_value, $names->get($rule->match_value) ?? ['name' => $rule->match_value, 'routes' => []]);
        }

        return [
            'names' => $names->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'routes' => $orders->pluck('route')
                ->merge($rules->where('match_type', 'route')->pluck('match_value'))
                ->map(fn (mixed $route): string => trim((string) $route))
                ->filter()
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'nzulm' => $orders
                ->filter(fn (ClientMedication $order): bool => trim((string) $order->nzulm_code) !== '')
                ->unique('nzulm_code')
                ->map(fn (ClientMedication $order): array => ['code' => trim((string) $order->nzulm_code), 'name' => (string) $order->name])
                ->merge($rules->where('match_type', 'nzulm_code')->map(fn (MedicationAdminRule $rule): array => ['code' => $rule->match_value, 'name' => '']))
                ->unique('code')
                ->sortBy('code')
                ->values()
                ->all(),
        ];
    }

    /** Can this person change rules at this house now (null = all houses)? Mirrors assertCurrentRuleSite. */
    private function canUseRuleSiteNow(User $actor, ?int $siteId): bool
    {
        if ($this->canManageGlobalRules($actor)) {
            return true;
        }
        if ($siteId === null) {
            return false;
        }
        $profile = $actor->hrEmployeeProfile;

        return $profile instanceof HrEmployeeProfile
            && collect([$profile->primary_site_id, ...($profile->secondary_site_ids ?? [])])
                ->contains(fn (mixed $assigned): bool => (int) $assigned === $siteId);
    }

    private function canManageGlobalRules(User $actor): bool
    {
        return $this->siteAccess->canBypass(
            $actor,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );
    }

    private function canManageSettings(?User $user): bool
    {
        return (bool) $user && $user->canDo('medications.settings.manage');
    }

    /** `$houseManagedOnly`: every change is a house setting house managers may make (P11 B2 Q3). */
    private function lockCurrentRuleActor(User $actor, bool $houseManagedOnly = false): User
    {
        $locks = $this->peopleLocks->lock([(int) $actor->id]);
        /** @var User|null $lockedActor */
        $lockedActor = $locks['users']->get((int) $actor->id);
        abort_unless(
            $lockedActor instanceof User
                && $lockedActor->approved_at !== null
                && ($lockedActor->canDo('medications.settings.manage')
                    || ($houseManagedOnly && $lockedActor->canDo('medications.alerts.manage_house'))),
            403,
        );
        $profile = $lockedActor->hrEmployeeProfile;
        $clinicalDate = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        abort_unless(
            $profile instanceof HrEmployeeProfile
                && $profile->is_active
                && ($profile->start_date === null || $profile->start_date->toDateString() <= $clinicalDate)
                && ($profile->end_date === null || $profile->end_date->toDateString() >= $clinicalDate),
            403,
        );

        return $lockedActor;
    }

    /** @param array<int, mixed> $siteIds @return Collection<int, Site> */
    private function lockCurrentRuleSites(array $siteIds): Collection
    {
        $ids = collect($siteIds)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->filter(fn (int $siteId): bool => $siteId > 0)
            ->unique()
            ->sort()
            ->values();

        return Site::query()
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id'])
            ->keyBy(fn (Site $site): int => (int) $site->id);
    }

    private function assertCurrentRuleSite(
        User $actor,
        ?int $siteId,
        Collection $lockedSites,
        bool $conceal,
    ): void {
        $deny = static function () use ($conceal): never {
            abort($conceal ? 404 : 403);
        };

        if ($siteId === null) {
            // Rules for every house are visible to everyone in Settings, so a
            // refused change says so rather than pretending the rule is absent.
            abort_unless($this->canManageGlobalRules($actor), 403);

            return;
        }
        if (! $lockedSites->has((int) $siteId)) {
            $deny();
        }
        if ($this->canManageGlobalRules($actor)) {
            return;
        }

        $profile = $actor->hrEmployeeProfile;
        if (! $profile instanceof HrEmployeeProfile
            || ! collect([
                $profile->primary_site_id,
                ...($profile->secondary_site_ids ?? []),
            ])->contains(fn (mixed $assignedSiteId): bool => (int) $assignedSiteId === (int) $siteId)) {
            $deny();
        }
    }
}
