<?php

namespace App\Services\Medication;

use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\User;
use App\Services\GuidedRoundService;
use App\Services\Medication\Settings\MedicationSettingsStore;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Round templates as Medication › Settings › Rounds & timing shows them
 * (eMAR P11): which templates a person may read, which they may change,
 * how a template reads in words, and today's round from each.
 *
 * Reading follows the rules Meds today › Rounds used: templates at the
 * houses the person can see, plus organisation-wide templates for people
 * with all-houses access. Changing needs medications.orders.manage at the
 * template's house (all-houses access for an organisation-wide template);
 * the write endpoints enforce the same through MedicationGovernanceScopeService.
 */
class RoundTemplateCatalogue
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly MedicationSettingsStore $settingsStore,
        private readonly GuidedRoundService $rounds,
    ) {}

    /** Can this person manage round templates at any house? */
    public function canManageAny(User $user): bool
    {
        return $this->manageableSiteIds($user) !== [];
    }

    /** @return list<int> Houses where this person can add or change templates. */
    public function manageableSiteIds(User $user): array
    {
        if (! $user->canDo('medications.orders.manage')) {
            return [];
        }

        return array_values($this->siteAccess->accessibleSiteIds($user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS));
    }

    public function hasAllHouses(User $user): bool
    {
        return $user->canDo('clinical.accessAllSites') || $user->canDo('sites.viewAll');
    }

    /** Templates this person may read, at the houses they can see. */
    public function visibleQuery(User $user): Builder
    {
        $readerSiteIds = $this->siteAccess->accessibleSiteIds($user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
        $allHouses = $this->hasAllHouses($user);

        return MedicationRoundTemplate::query()->where(function (Builder $templates) use ($readerSiteIds, $allHouses): void {
            $templates
                ->whereRaw('1 = 0')
                ->orWhere(function (Builder $siteBound) use ($readerSiteIds): void {
                    $siteBound
                        ->whereIn('medication_round_templates.site_id', $readerSiteIds)
                        ->where(function (Builder $context): void {
                            $context
                                ->whereNull('medication_round_templates.service_context_id')
                                ->orWhereHas('serviceContext', fn (Builder $serviceContext) => $serviceContext
                                    ->where('service_contexts.is_active', true)
                                    ->where(fn (Builder $contextSite) => $contextSite
                                        ->whereNull('service_contexts.site_id')
                                        ->orWhereColumn('service_contexts.site_id', 'medication_round_templates.site_id')));
                        });
                })
                ->orWhere(function (Builder $contextBound) use ($readerSiteIds): void {
                    $contextBound
                        ->whereNull('medication_round_templates.site_id')
                        ->whereNotNull('medication_round_templates.service_context_id')
                        ->whereHas('serviceContext', fn (Builder $serviceContext) => $serviceContext
                            ->where('service_contexts.is_active', true)
                            ->whereIn('service_contexts.site_id', $readerSiteIds));
                });

            if ($allHouses) {
                $templates->orWhere(function (Builder $organisationWide): void {
                    $organisationWide
                        ->whereNull('medication_round_templates.site_id')
                        ->where(fn (Builder $context) => $context
                            ->whereNull('medication_round_templates.service_context_id')
                            ->orWhereHas('serviceContext', fn (Builder $serviceContext) => $serviceContext
                                ->where('service_contexts.is_active', true)
                                ->whereNull('service_contexts.site_id')));
                });
            }
        });
    }

    /** The house a template belongs to: its own, or its service context's. */
    public function houseId(MedicationRoundTemplate $template): ?int
    {
        $siteId = (int) ($template->site_id ?? $template->serviceContext?->site_id ?? 0);

        return $siteId > 0 ? $siteId : null;
    }

    /** @param  list<int>  $manageableSiteIds */
    public function canChange(User $user, MedicationRoundTemplate $template, array $manageableSiteIds): bool
    {
        if ($template->isRetired() || $manageableSiteIds === []) {
            return false;
        }
        $houseId = $this->houseId($template);

        return $houseId === null ? $this->hasAllHouses($user) : in_array($houseId, $manageableSiteIds, true);
    }

    /**
     * The rows the Round templates list shows, with today's round from each.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(User $user): array
    {
        return $this->rowsWithStaff($user, $this->staffPicker($user));
    }

    /**
     * One governed staff read supplies both default names and editing choices.
     * The result belongs to this call; later calls read current staff again.
     *
     * @return array{rows: list<array<string, mixed>>, staff: Collection<int, array{id: int, name: string, site_ids: list<int>}>}
     */
    public function rowsAndStaff(User $user): array
    {
        $staff = $this->staffPicker($user);

        return ['rows' => $this->rowsWithStaff($user, $staff), 'staff' => $staff];
    }

    /** @param Collection<int, array{id: int, name: string, site_ids: list<int>}> $staff */
    private function rowsWithStaff(User $user, Collection $staff): array
    {
        $templates = $this->visibleQuery($user)
            ->with(['site:id,name', 'serviceContext:id,site_id', 'serviceContext.site:id,name', 'retiredBy:id,name'])
            ->orderBy('scheduled_time')
            ->orderBy('name')
            ->get();
        $manageable = $this->manageableSiteIds($user);
        $latest = $this->settingsStore->latestTemplateChanges($templates->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $today = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $todaysRounds = MedicationRound::query()
            ->whereIn('round_template_id', $templates->pluck('id')->all())
            ->forDate($today)
            ->get()
            ->keyBy('round_template_id');
        $canCount = $user->canDo('medications.view');
        $includeControlled = $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        // A default staff member is named only when they work at a house this
        // person can see (the same governed list the template picker offers).
        $staff = $staff->keyBy(fn (array $person): int => (int) $person['id']);

        return $templates->map(function (MedicationRoundTemplate $template) use ($user, $manageable, $latest, $todaysRounds, $canCount, $includeControlled, $timezone, $staff): array {
            $round = $todaysRounds->get($template->id);
            $assignee = $template->default_assigned_to !== null ? $staff->get((int) $template->default_assigned_to) : null;
            $cells = $round !== null && $canCount ? collect($this->rounds->cells($round, $includeControlled)) : null;
            $change = $latest->get((int) $template->id);
            $house = $template->site ?? $template->serviceContext?->site;

            return [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'scheduled_time' => substr((string) $template->scheduled_time, 0, 5),
                'window_minutes' => (int) ($template->window_minutes ?? 60),
                'days_of_week' => array_values(array_map('intval', $template->days_of_week ?? [])),
                'status' => $template->isRetired() ? 'retired' : ($template->active ? 'active' : 'paused'),
                'site_id' => $this->houseId($template),
                'site_name' => $house?->name,
                'default_assigned_to' => $assignee === null ? null : (int) $assignee['id'],
                'default_staff' => $assignee['name'] ?? null,
                'today' => $cells === null ? null : [
                    'doses' => $cells->count(),
                    'people' => $cells->pluck('resident_id')->filter()->unique()->count(),
                ],
                'last_changed_by' => $change?->actor?->name ?? ($template->isRetired() ? $template->retiredBy?->name : null),
                'last_changed_at' => ($change?->created_at ?? $template->retired_at ?? $template->updated_at)?->copy()->timezone($timezone)->toIso8601String(),
                'can_change' => $this->canChange($user, $template, $manageable),
            ];
        })->values()->all();
    }

    /**
     * Staff who can be a template's default, with the houses they work at,
     * within the houses this person can see.
     *
     * @return Collection<int, array{id: int, name: string, site_ids: list<int>}>
     */
    public function staffPicker(User $user): Collection
    {
        return app(MedicationGovernanceScopeService::class)
            ->prescriptionWitnessStaffPicker($this->siteAccess->accessibleSiteIds($user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS))
            ->map(fn (array $person): array => [
                'id' => (int) $person['id'],
                'name' => (string) $person['name'],
                'site_ids' => array_values(array_map('intval', $person['site_ids'] ?? [])),
            ])
            ->values();
    }

    /** How a template reads in the change history: "8:00 am ±60 · Every day · everyone rostered · Active". */
    public function describe(MedicationRoundTemplate $template, ?string $staffName = null): string
    {
        $status = $template->isRetired() ? 'Retired' : ($template->active ? 'Active' : 'Paused');

        return implode(' · ', [
            self::clock((string) $template->scheduled_time).' ±'.(int) ($template->window_minutes ?? 60),
            self::daysText($template->days_of_week ?? []),
            $staffName ?? $template->defaultAssignedTo?->name ?? 'everyone rostered',
            $status,
        ]);
    }

    /** "08:00" → "8:00 am". */
    public static function clock(string $time): string
    {
        [$hour, $minute] = array_map('intval', array_pad(explode(':', $time), 2, '0'));

        return (($hour + 11) % 12 + 1).':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).' '.($hour < 12 ? 'am' : 'pm');
    }

    /** @param  array<int, int|string>  $days  ISO weekdays, 1 = Monday. */
    public static function daysText(array $days): string
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);
        if ($days === [] || count($days) === 7) {
            return 'Every day';
        }
        if ($days === [1, 2, 3, 4, 5]) {
            return 'Monday to Friday';
        }
        if ($days === [6, 7]) {
            return 'Saturday and Sunday';
        }
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return implode(', ', array_map(fn (int $day): string => $names[$day] ?? (string) $day, $days));
    }
}
