<?php

namespace App\Domain\SecurityDevices\DoorClockins\Screens;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinRules;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinSetup;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinDoor;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinNotice;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Setup › Doors & access › Clock-ins from doors (drawing pg-sdr-15n) and what pop-up 92 chooses
 * from: whether it's on at the viewer's Sites, this morning in one line, the settings rows, who
 * hears about gaps, and what is kept. Doors are listed only at the viewer's own Sites.
 */
final class SetupClockinsPresenter
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DoorClockinSetup $setup,
        private readonly AccessClockinsPresenter $clockins,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $today = DoorClockinRules::today($now);
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $config = $this->setup->config();
        $counted = $this->setup->doors($viewer);
        $state = AccessClockinsPresenter::state($counted, $today);
        $notice = $this->latestNotice($siteIds);
        $canReview = DoorClockinRules::canReview($viewer);
        $rows = $state === 'off' || ! $canReview ? [] : $this->clockins->todayRows($siteIds, $now);

        return [
            'state' => $state,
            'since' => AccessClockinsPresenter::since($counted, $today),
            'starts_on' => $state === 'starting' ? (string) $counted->min(fn (DoorClockinDoor $door): string => $door->starts_on->format('Y-m-d')) : null,
            'turned_off_at' => $state === 'off' ? $config->turned_off_at?->toIso8601String() : null,
            'places' => AccessClockinsPresenter::places($counted),
            'notice' => $notice !== null ? [
                'sent_at' => $notice->sent_at->toIso8601String(),
                'by' => (string) ($notice->sender?->name ?? 'Someone who has left'),
                'by_email' => (bool) $notice->by_email,
                'starts_on' => $notice->starts_on->format('Y-m-d'),
                'told' => (int) $notice->recipients,
            ] : null,
            // "This morning" on the state card: only for people who may see the clock-ins themselves.
            'today' => $canReview && $state === 'on' ? [
                'badge' => count(array_filter($rows, fn (array $row): bool => $row['outcome'] === 'confirmed')),
                'other_way' => count(array_filter($rows, fn (array $row): bool => in_array($row['outcome'], ['now', 'other_way', 'kept'], true))),
                'gaps' => count(array_filter($rows, fn (array $row): bool => $row['waiting_for_you'])),
            ] : null,
            'settings' => [
                'before' => (int) $config->before_minutes,
                'after' => (int) $config->after_minutes,
                'badge_time' => (bool) $config->badge_time,
                'gaps' => [
                    'unconfirmed' => ['app' => (bool) $config->gap_unconfirmed_app, 'email' => (bool) $config->gap_unconfirmed_email],
                    'no_badge' => ['app' => (bool) $config->gap_no_badge_app, 'email' => (bool) $config->gap_no_badge_email],
                    'not_rostered' => ['app' => (bool) $config->gap_not_rostered_app, 'email' => (bool) $config->gap_not_rostered_email],
                ],
            ],
            'doors' => $this->doorOptions($siteIds, $counted),
            'told_by_site' => $this->toldBySite($siteIds),
            'me' => $this->me($viewer),
            'earliest_start' => DoorClockinRules::local($now)->startOfDay()->addDays(DoorClockinRules::NOTICE_DAYS)->toDateString(),
            'options' => ['before' => DoorClockinRules::BEFORE_OPTIONS, 'after' => DoorClockinRules::AFTER_OPTIONS],
            'can' => [
                'change' => DoorClockinRules::canChange($viewer),
                // The window, badge time and gap alerts apply to every Site.
                'shared' => DoorClockinRules::canChange($viewer) && DoorClockinRules::seesEverySite($viewer),
                'review' => $canReview,
            ],
        ];
    }

    /**
     * Every door at the viewer's Sites, with whether it counts and from when.
     *
     * @param  list<int>  $siteIds
     * @param  Collection<int, DoorClockinDoor>  $counted
     * @return list<array<string, mixed>>
     */
    private function doorOptions(array $siteIds, Collection $counted): array
    {
        if ($siteIds === []) {
            return [];
        }
        $byDoor = $counted->keyBy('door_id');

        return AccessDoor::query()
            ->with('console.site:id,name')
            ->whereHas('console', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->get(['id', 'console_id', 'name', 'readers'])
            ->map(function (AccessDoor $door) use ($byDoor): array {
                $row = $byDoor->get((int) $door->id);
                $readers = collect(is_array($door->readers) ? $door->readers : [])->pluck('name')->filter(fn (mixed $name): bool => is_string($name) && $name !== '')->values();

                return [
                    'id' => (int) $door->id,
                    'name' => (string) $door->name,
                    'site' => ['id' => (int) $door->console?->site_id, 'name' => (string) ($door->console?->site?->name ?? 'Site')],
                    'readers' => $readers->take(2)->all(),
                    'counts' => $row !== null,
                    'starts_on' => $row?->starts_on?->format('Y-m-d'),
                ];
            })
            ->sortBy(fn (array $door): string => mb_strtolower($door['site']['name'].' '.$door['name']))
            ->values()
            ->all();
    }

    /**
     * How many people at each Site would get the notice (matched to staff, with a card, PIN or phone).
     *
     * @param  list<int>  $siteIds
     * @return array<int, int>
     */
    private function toldBySite(array $siteIds): array
    {
        $out = [];
        foreach ($siteIds as $siteId) {
            $out[$siteId] = $this->setup->recipients([$siteId])->count();
        }

        return $out;
    }

    /** @param  list<int>  $siteIds */
    private function latestNotice(array $siteIds): ?DoorClockinNotice
    {
        if ($siteIds === []) {
            return null;
        }

        return DoorClockinNotice::query()->with('sender:id,name')->latest('sent_at')->latest('id')->limit(50)->get()
            ->first(fn (DoorClockinNotice $notice): bool => array_intersect(array_map('intval', (array) $notice->site_ids), $siteIds) !== []);
    }

    /** @return array{name: string, position: string|null} who signs the notice by default */
    private function me(User $viewer): array
    {
        $position = HrEmployeeProfile::query()->where('user_id', $viewer->id)->where('is_active', true)->value('position_title');

        return ['name' => (string) $viewer->name, 'position' => is_string($position) && trim($position) !== '' ? trim($position) : null];
    }
}
