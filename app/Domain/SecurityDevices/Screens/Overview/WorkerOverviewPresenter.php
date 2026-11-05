<?php

namespace App\Domain\SecurityDevices\Screens\Overview;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Alerts\StaffOnShift;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\Alerts\AlertsPresenter;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Devices\Pages\AlarmPagePresenter;
use App\Domain\SecurityDevices\Screens\Devices\Pages\SensorPagePresenter;
use App\Domain\SecurityDevices\Screens\Sites\AlertWords;
use App\Domain\SecurityDevices\Screens\Sites\SiteStream;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The support worker's Overview (S&D rebuild drawing pg-sdr-1c): one house, safety first, then its
 * doors, alarm, panic buttons and sensors, and what the person was told today. Same URL as the IT
 * Overview; OverviewPresenter::showsWorkerView() decides who gets it.
 *
 * The house comes from the person's HR profile (primary and secondary Sites), the same as the rest
 * of S&D: the one they're on shift at today, else their primary Site; any other Site is 404.
 *
 * Nothing here widens what the person may see:
 * - alerts are the house's own (safety, readings, batteries and care), shown in full only while the
 *   person is on shift there (the people DeviceAlertEngine tells), otherwise only the ones they were
 *   told about;
 * - the alarm's armed state and zones show with securityDevices.events.view, as on the alarm page;
 *   without it, a person sees only armed or disarmed (no times, names, zones or history) and only at a
 *   Site they work at: their HR profile's Sites or today's rostered shift (Stephan, 2 Nov: "Show at
 *   their own Site");
 * - devices linked to a person (a resident's bed sensor or tracker) are left out, and so are cameras,
 *   network gear and computers.
 *
 * Left out because nothing backs them yet: today's resident outing and tracker ("With you today"),
 * when a panic button was last tested, Report a broken device, Ask Obli, and the Activity tab (18c).
 */
final class WorkerOverviewPresenter
{
    /**
     * Safety alert kinds the staff on shift at a house are told about (DeviceAlertEngine::SAFETY_EVENTS,
     * plus the care and scald kinds as the engine names them). An allow-list, so an IT alert (offline,
     * failing, hub, sensor broker) never reaches this view.
     */
    private const array SAFETY_KINDS = ['panic', 'alarm', 'leak', 'forced', 'tamper', 'care_out_of_bed', 'care_door_night', 'scald_risk'];

    /** The other house alerts: batteries, and readings ("reading_*", matched by prefix). */
    private const array HOUSE_KINDS = [...self::SAFETY_KINDS, 'battery'];

    /** Alarm subcategories that are panic buttons rather than the panel or a zone. */
    private const array PANIC = ['panic_button', 'duress'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly AlarmPagePresenter $alarms,
        private readonly StaffOnShift $staff,
    ) {}

    /**
     * @param  array{site?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, array $input): array
    {
        $now = CarbonImmutable::now('UTC');
        $requested = is_scalar($input['site'] ?? null) && ctype_digit((string) $input['site']) ? (int) $input['site'] : null;
        if ($requested !== null) {
            $this->access->assertCanViewSite($viewer, $requested);
        }
        $sites = $this->access->accessibleSites($viewer)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);
        $siteIds = $sites->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $shift = $this->shiftToday($viewer, $siteIds, $now);
        $siteId = $requested ?? $shift['site_id'] ?? $this->primarySite($viewer, $siteIds) ?? ($siteIds[0] ?? null);
        $site = $siteId === null ? null : $sites->first(fn (Site $option): bool => (int) $option->id === $siteId);

        $base = [
            'view' => 'worker',
            'tab' => 'overview',
            'site' => $site === null ? null : ['id' => (int) $site->id, 'name' => (string) $site->name],
            'sites' => $sites->map(fn (Site $option): array => ['value' => (string) $option->id, 'label' => (string) $option->name])->all(),
            'shift' => $shift !== null && $site !== null && $shift['site_id'] === (int) $site->id
                ? ['starts_at' => $shift['starts_at'], 'ends_at' => $shift['ends_at']]
                : null,
            'on_shift' => false,
            'meters' => [],
            'safety' => [],
            'kit' => ['brand' => null, 'reported_at' => null, 'items' => []],
            'around' => [],
            'told' => [],
            'can' => [
                'open_devices' => $viewer->canDo('securityDevices.devices.view'),
                'open_alerts' => AlertsPresenter::canOpen($viewer),
            ],
        ];
        if ($site === null) {
            return $base;
        }

        $siteId = (int) $site->id;
        $onShift = $this->staff->onShift($siteId, $now)->contains(fn (User $person): bool => (int) $person->id === (int) $viewer->id);
        $toldIds = $this->toldAlertIds($viewer, $now->subDays(14));
        $personLinkedDeviceIds = DevicePersonLink::query()
            ->whereNull('unlinked_at')
            ->pluck('device_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();
        $entries = $this->directory->entries($viewer, $siteId)
            ->reject(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Retired
                || $personLinkedDeviceIds->has($entry->id())
                || self::linkedToPerson($entry->device))
            ->sortBy(fn (DeviceEntry $entry): string => mb_strtolower($entry->name()))
            ->values();

        $safety = $this->safety($viewer, $siteId, $onShift, $toldIds);
        $kit = $this->kit($viewer, $entries, $this->worksAt($viewer, $siteId, $shift));
        $around = $this->around($entries, $base['can']['open_devices']);

        return [
            ...$base,
            'on_shift' => $onShift,
            'meters' => $this->meters($kit, $safety, $onShift),
            'safety' => $safety,
            'kit' => $kit,
            'around' => $around,
            'told' => $this->toldToday($viewer, $siteId, $now),
        ];
    }

    /**
     * The viewer's shift that is on now or still to come today (NZ day), at one of their Sites.
     *
     * @param  list<int>  $siteIds
     * @return array{site_id: int, starts_at: string, ends_at: string}|null
     */
    private function shiftToday(User $viewer, array $siteIds, CarbonImmutable $now): ?array
    {
        if ($siteIds === []) {
            return null;
        }
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $endOfDay = $now->setTimezone($zone)->endOfDay()->utc();
        $shift = Shift::query()
            ->where('user_id', $viewer->id)
            ->whereNotIn('status', ['cancelled', 'declined', 'no_show'])
            ->where('ends_at', '>=', $now)
            ->where('starts_at', '<=', $endOfDay)
            ->where(fn ($query) => $query
                ->whereIn('site_id', $siteIds)
                ->orWhere(fn ($derived) => $derived->whereNull('site_id')->whereHas('client', fn ($client) => $client->whereIn('site_id', $siteIds))))
            ->with('client:id,site_id')
            ->orderBy('starts_at')
            ->first(['id', 'user_id', 'site_id', 'client_id', 'starts_at', 'ends_at']);
        if ($shift === null) {
            return null;
        }
        $siteId = $shift->site_id !== null ? (int) $shift->site_id : (int) ($shift->client?->site_id ?? 0);
        if (! in_array($siteId, $siteIds, true) || $shift->starts_at === null || $shift->ends_at === null) {
            return null;
        }

        return [
            'site_id' => $siteId,
            'starts_at' => CarbonImmutable::parse($shift->starts_at)->utc()->toIso8601String(),
            'ends_at' => CarbonImmutable::parse($shift->ends_at)->utc()->toIso8601String(),
        ];
    }

    /**
     * A Site the person works at: their active HR profile's primary or secondary Site, or the Site of
     * today's rostered shift.
     *
     * @param  array{site_id: int, starts_at: string, ends_at: string}|null  $shift
     */
    private function worksAt(User $viewer, int $siteId, ?array $shift): bool
    {
        if ($shift !== null && $shift['site_id'] === $siteId) {
            return true;
        }
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $viewer->id)
            ->where('is_active', true)
            ->first(['id', 'primary_site_id', 'secondary_site_ids']);
        if ($profile === null) {
            return false;
        }
        $ids = array_map('intval', array_filter([$profile->primary_site_id, ...((array) ($profile->secondary_site_ids ?? []))], 'is_numeric'));

        return in_array($siteId, $ids, true);
    }

    /** @param  list<int>  $siteIds */
    private function primarySite(User $viewer, array $siteIds): ?int
    {
        $primary = HrEmployeeProfile::query()
            ->where('user_id', $viewer->id)
            ->where('is_active', true)
            ->value('primary_site_id');

        return is_numeric($primary) && in_array((int) $primary, $siteIds, true) ? (int) $primary : null;
    }

    private static function linkedToPerson(Device $device): bool
    {
        return $device->assignments->contains(fn (DeviceAssignment $assignment): bool => in_array(
            $assignment->assignable_type,
            [DeviceAssignment::TARGET_CLIENT, DeviceAssignment::TARGET_STAFF],
            true,
        ));
    }

    /**
     * Device alerts this person was told about (their own notifications), by alert id.
     *
     * @return list<int>
     */
    private function toldAlertIds(User $viewer, CarbonImmutable $since): array
    {
        return $this->toldQuery($viewer)
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(500)
            ->get(['id', 'data'])
            ->map(fn (DatabaseNotification $notification): int => (int) data_get($notification->data, 'context.alert_id', 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<DatabaseNotification> */
    private function toldQuery(User $viewer)
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $viewer->getMorphClass())
            ->where('notifiable_id', $viewer->getKey())
            ->where('type', SecurityDeviceAlertNotification::class);
    }

    /**
     * The house's open alerts, worst first: all of them while the person is on shift there, else
     * only the ones they were told about.
     *
     * @param  list<int>  $toldIds
     * @return list<array<string, mixed>>
     */
    private function safety(User $viewer, int $siteId, bool $onShift, array $toldIds): array
    {
        if (! $onShift && $toldIds === []) {
            return [];
        }
        $alerts = DeviceAlert::query()
            ->active()
            ->where('site_id', $siteId)
            ->where(fn ($query) => $query->whereIn('kind', self::HOUSE_KINDS)->orWhere('kind', 'like', 'reading\_%'))
            ->when(! $onShift, fn ($query) => $query->whereIn('id', $toldIds))
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")
            ->orderByDesc('opened_at')
            ->limit(20)
            ->get(['id', 'device_id', 'site_id', 'kind', 'severity', 'title', 'opened_at']);
        if ($alerts->isEmpty()) {
            return [];
        }
        $carePeople = SiteStream::carePeople($viewer, $alerts);
        $opened = DeviceAlertEvent::query()
            ->whereIn('alert_id', $alerts->pluck('id')->all())
            ->where('kind', 'opened')
            ->orderBy('id')
            ->get(['alert_id', 'text', 'detail'])
            ->keyBy('alert_id');

        return $alerts->map(function (DeviceAlert $alert) use ($carePeople, $opened): array {
            $words = new AlertWords($alert, $carePeople[(int) $alert->id] ?? false);
            $event = $opened->get($alert->id);
            $what = (string) ($words->text($event?->text) ?? '');
            $detail = (string) ($words->text($event?->detail) ?? '');
            $safety = in_array($alert->kind, self::SAFETY_KINDS, true);

            return [
                'id' => (int) $alert->id,
                'title' => $words->title(),
                'tone' => $alert->severity === 'critical' ? 'critical' : 'warning',
                'safety' => $safety,
                'at' => $alert->opened_at->toIso8601String(),
                'sub' => trim(implode('. ', array_filter([$what, $detail], fn (string $part): bool => $part !== ''))),
                'then' => match (true) {
                    str_starts_with($alert->kind, 'reading_') => 'It closes by itself when the reading is back in the usual range.',
                    $alert->kind === 'battery' => 'It closes by itself once a new battery reports.',
                    default => 'It stays open until someone who looks after alerts marks it done.',
                },
            ];
        })->values()->all();
    }

    /**
     * Doors and alarm (the house's safety kit): each alarm panel with its armed state when the person
     * may see it, the zones it reports, panic buttons and doors.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{brand: ?string, reported_at: ?string, items: list<array<string, mixed>>}
     */
    private function kit(User $viewer, Collection $entries, bool $worksHere): array
    {
        $canSeeEvents = $viewer->canDo('securityDevices.events.view');
        $brand = null;
        $reportedAt = null;
        $panels = [];
        $zones = [];
        $panic = [];
        $doors = [];
        foreach ($entries as $entry) {
            $sub = strtolower((string) $entry->device->subcategory);
            if ($entry->kind === DeviceKind::AlarmPanel && in_array($sub, self::PANIC, true)) {
                $panic[] = $this->panicItem($entry);

                continue;
            }
            if ($entry->kind === DeviceKind::Door) {
                $doors[] = $this->deviceItem($entry, 'door', $entry->device->location_description ? (string) $entry->device->location_description : $entry->model());

                continue;
            }
            if ($entry->kind !== DeviceKind::AlarmPanel) {
                continue;
            }
            $item = $this->deviceItem($entry, 'alarm', $entry->model());
            // The alarm page's own reading (armed state, areas, zones), only where it shows them too.
            $device = $canSeeEvents || $worksHere ? Device::query()->find($entry->id()) : null;
            if ($device instanceof Device && ! $canSeeEvents) {
                // Their own house without events.view: armed or disarmed, read-only, and nothing more.
                $state = $this->alarms->armedStateOnly($device);
                $item['flag'] = ['tone' => $state['tone'], 'text' => $state['label']];
                $item['panel'] = ['state' => $state['state'], 'label' => $state['label'], 'tone' => $state['tone'], 'since' => null, 'by' => null];
                $panels[] = $item;

                continue;
            }
            if ($device instanceof Device) {
                $page = $this->alarms->present($viewer, $device, ['tab' => 'overview']);
                $brand ??= $page['control']['brand'] ?? null;
                $last = $page['overview']['last_report'] ?? null;
                if (is_string($last) && ($reportedAt === null || $last > $reportedAt)) {
                    $reportedAt = $last;
                }
                $panel = $page['panel'];
                if (($panel['state'] ?? 'hidden') !== 'hidden') {
                    $armedArea = collect($page['areas'])->first(fn (array $area): bool => $area['armed'] === true);
                    $item['flag'] = ['tone' => (string) $panel['tone'], 'text' => (string) $panel['label']];
                    $item['panel'] = [
                        'state' => (string) $panel['state'],
                        'label' => (string) $panel['label'],
                        'tone' => (string) $panel['tone'],
                        'since' => $panel['since'],
                        'by' => $armedArea['by'] ?? null,
                    ];
                    $item['facts'] = array_values(array_filter([
                        $panel['since'] !== null ? ['tone' => 'info', 'text' => 'Since {time}'.(($armedArea['by'] ?? null) ? ', by '.$armedArea['by'] : ''), 'at' => $panel['since']] : null,
                        $page['control']['view_only'] ? ['tone' => 'brand', 'text' => 'Armed and disarmed at the keypad'.($brand ? ' or in the '.$brand.' app' : ''), 'at' => null] : null,
                    ]));
                }
                foreach (array_slice($page['overview']['zones'] ?? [], 0, 6) as $zone) {
                    $leftOut = $zone['state'] === 'open' && in_array($panel['state'] ?? null, ['armed', 'partly'], true);
                    $zones[] = [
                        'key' => 'zone-'.$entry->id().'-'.$zone['id'],
                        'kind' => 'zone',
                        'id' => $entry->id(),
                        'name' => (string) $zone['name'],
                        'sub' => 'Alarm zone',
                        'flag' => ['tone' => (string) $zone['tone'], 'text' => (string) $zone['label']],
                        'facts' => array_values(array_filter([
                            $leftOut ? ['tone' => 'warning', 'text' => 'Left out when the alarm armed', 'at' => null] : null,
                            $zone['changed_at'] !== null ? ['tone' => $zone['tone'] === 'success' ? 'success' : 'info', 'text' => 'Last changed {time}', 'at' => $zone['changed_at']] : null,
                        ])),
                        'panel' => null,
                    ];
                }
            }
            $panels[] = $item;
        }

        return [
            'brand' => $brand,
            'reported_at' => $reportedAt,
            'items' => [...$panels, ...$zones, ...$panic, ...$doors],
        ];
    }

    /** @return array<string, mixed> */
    private function deviceItem(DeviceEntry $entry, string $kind, string $sub): array
    {
        $seen = $entry->lastSeenAt();

        return [
            'key' => $kind.'-'.$entry->id(),
            'kind' => $kind,
            'id' => $entry->id(),
            'name' => $entry->name(),
            'sub' => $sub,
            'flag' => ['tone' => $entry->state->tone(), 'text' => $entry->state->label()],
            'facts' => array_values(array_filter([
                $entry->batteryLow() ? ['tone' => 'warning', 'text' => 'Battery '.$entry->battery().'%: IT is told', 'at' => null] : null,
                $seen !== null ? ['tone' => 'info', 'text' => 'Last heard from {time}', 'at' => $seen->toIso8601String()] : null,
            ])),
            'panel' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function panicItem(DeviceEntry $entry): array
    {
        $ready = $entry->state === DeviceState::Online && ! $entry->batteryLow();
        $battery = $entry->battery();

        return [
            'key' => 'panic-'.$entry->id(),
            'kind' => 'panic',
            'id' => $entry->id(),
            'name' => $entry->name(),
            'sub' => $entry->device->location_description ? (string) $entry->device->location_description : $entry->model(),
            'flag' => $ready ? ['tone' => 'success', 'text' => 'Ready'] : ['tone' => $entry->state->tone() === 'success' ? 'warning' : $entry->state->tone(), 'text' => $entry->state === DeviceState::Online ? 'Battery low' : $entry->state->label()],
            'facts' => array_values(array_filter([
                $battery !== null ? ['tone' => $entry->batteryLow() ? 'warning' : 'success', 'text' => 'Battery '.$battery.'%', 'at' => null] : null,
            ])),
            'panel' => null,
            'ready' => $ready,
        ];
    }

    /**
     * Around the house: the sensors that aren't linked to a person, by the place they're in.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function around(Collection $entries, bool $canOpen): array
    {
        $sensors = $entries->filter(fn (DeviceEntry $entry): bool => $entry->kind === DeviceKind::Sensor);
        $groups = $sensors->groupBy(function (DeviceEntry $entry): string {
            $place = trim((string) $entry->device->location_description);

            return $place !== '' ? 'place:'.mb_strtolower($place) : 'device:'.$entry->id();
        });

        return $groups->map(function (Collection $group, string $key) use ($canOpen): array {
            /** @var DeviceEntry $first */
            $first = $group->first();
            $place = str_starts_with($key, 'place:') ? trim((string) $first->device->location_description) : null;
            $facts = [];
            $worst = 'success';
            foreach ($group as $entry) {
                /** @var DeviceEntry $entry */
                if ($entry->state === DeviceState::Offline) {
                    $worst = 'critical';
                    $facts[] = ['tone' => 'critical', 'text' => $entry->name().' isn’t answering', 'at' => null];

                    continue;
                }
                if (in_array($entry->state, [DeviceState::CantCheck, DeviceState::NotWatched], true)) {
                    $worst = $worst === 'success' ? 'neutral' : $worst;
                    $facts[] = ['tone' => 'none', 'text' => $entry->name().': '.lcfirst($entry->state->label()), 'at' => null];

                    continue;
                }
                [$words, $tone] = self::readingWords($entry->readings);
                if ($words !== null) {
                    $facts[] = ['tone' => $tone, 'text' => ($place !== null ? $entry->name().': ' : '').$words, 'at' => null];
                }
                if ($tone !== 'success' || $entry->state === DeviceState::Problem) {
                    $worst = $worst === 'critical' ? 'critical' : 'warning';
                }
                if ($entry->batteryLow()) {
                    $worst = $worst === 'critical' ? 'critical' : 'warning';
                    $facts[] = ['tone' => 'warning', 'text' => $entry->name().' battery low: IT is told', 'at' => null];
                }
            }

            return [
                'key' => $key,
                'name' => $place ?? $first->name(),
                'sub' => $place !== null
                    ? implode(', ', $group->take(3)->map(fn (DeviceEntry $entry): string => $entry->name())->all()).($group->count() > 3 ? ' and '.($group->count() - 3).' more' : '')
                    : $first->model(),
                'flag' => match ($worst) {
                    'critical' => ['tone' => 'critical', 'text' => 'Not answering'],
                    'warning' => ['tone' => 'warning', 'text' => 'Needs a look'],
                    'neutral' => ['tone' => 'neutral', 'text' => 'Not checked'],
                    default => ['tone' => 'success', 'text' => 'All fine'],
                },
                'facts' => array_slice($facts, 0, 3),
                'href' => $canOpen && $group->count() === 1 ? '/security-devices/devices/'.$first->id() : null,
            ];
        })->values()->all();
    }

    /**
     * "3.2 °C · 54%" from a sensor's latest readings, with the worst state among them.
     *
     * @param  array<string, mixed>  $readings
     * @return array{0: ?string, 1: string}
     */
    private static function readingWords(array $readings): array
    {
        $words = [];
        $tone = 'success';
        foreach (array_keys(SensorPagePresenter::METRICS) as $key) {
            $reading = $readings[$key] ?? null;
            if (! is_array($reading) || ! is_numeric($reading['value'] ?? null)) {
                continue;
            }
            $value = (float) $reading['value'];
            $number = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
            $number = str_starts_with($number, '-') ? '−'.substr($number, 1) : $number;
            $unit = SensorPagePresenter::METRICS[$key][1];
            $words[] = match ($unit) {
                '%' => $number.'%',
                'people' => SensorPagePresenter::METRICS[$key][0].' '.$number,
                default => $number.' '.$unit,
            };
            if (in_array($reading['state'] ?? null, ['warning', 'critical'], true)) {
                $tone = $tone === 'critical' || $reading['state'] === 'critical' ? 'critical' : 'warning';
            }
            if (count($words) === 2) {
                break;
            }
        }

        return [$words === [] ? null : implode(' · ', $words), $tone];
    }

    /**
     * The header numbers. Each one opens where its number lives.
     *
     * @param  array{brand: ?string, reported_at: ?string, items: list<array<string, mixed>>}  $kit
     * @param  list<array<string, mixed>>  $safety
     * @return list<array<string, mixed>>
     */
    private function meters(array $kit, array $safety, bool $onShift): array
    {
        $meters = [];
        $panel = collect($kit['items'])->first(fn (array $item): bool => $item['kind'] === 'alarm');
        if ($panel !== null) {
            $openZone = collect($kit['items'])->first(fn (array $item): bool => $item['kind'] === 'zone' && collect($item['facts'])->contains('text', 'Left out when the alarm armed'));
            $meters[] = $panel['panel'] !== null
                ? [
                    'key' => 'alarm', 'label' => 'Alarm', 'value' => $panel['panel']['label'],
                    'caption' => ['text' => ($panel['panel']['since'] !== null ? 'Since {time}' : $panel['name']).($openZone !== null ? ' · '.lcfirst($openZone['name']).' left out' : ''), 'at' => $panel['panel']['since']],
                    'tone' => $panel['panel']['tone'] === 'critical' ? 'critical' : ($openZone !== null ? 'warning' : null),
                    'device_id' => $panel['id'],
                ]
                : [
                    'key' => 'alarm', 'label' => 'Alarm', 'value' => $panel['flag']['text'],
                    'caption' => ['text' => $panel['name'].' · armed state not shown to you', 'at' => null],
                    'tone' => $panel['flag']['tone'] === 'critical' ? 'critical' : null,
                    'device_id' => $panel['id'],
                ];
        }

        $critical = collect($safety)->contains('tone', 'critical');
        $meters[] = [
            'key' => 'safety', 'label' => 'Safety',
            'value' => $safety === [] ? 'None open' : (count($safety) === 1 ? '1 alert' : count($safety).' alerts'),
            'caption' => ['text' => $safety === [] ? ($onShift ? 'Nothing needs you right now' : 'Nothing told to you') : (string) $safety[0]['title'], 'at' => null],
            'tone' => $safety === [] ? ($onShift ? 'success' : null) : ($critical ? 'critical' : 'warning'),
            'device_id' => null,
        ];

        $panic = collect($kit['items'])->filter(fn (array $item): bool => $item['kind'] === 'panic')->values();
        if ($panic->isNotEmpty()) {
            $ready = $panic->filter(fn (array $item): bool => (bool) $item['ready']);
            $notReady = $panic->first(fn (array $item): bool => ! $item['ready']);
            $meters[] = [
                'key' => 'panic', 'label' => 'Panic buttons',
                'value' => $ready->count() === $panic->count() ? $ready->count().' ready' : $ready->count().' of '.$panic->count().' ready',
                'caption' => ['text' => $notReady === null ? ($panic->count() === 1 ? 'Answering, battery fine' : 'All answering, batteries fine') : $notReady['name'].': '.lcfirst($notReady['flag']['text']), 'at' => null],
                'tone' => $notReady === null ? 'success' : 'warning',
                'device_id' => null,
            ];
        }

        return $meters;
    }

    /**
     * What this person was told today (NZ day) about this house, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function toldToday(User $viewer, int $siteId, CarbonImmutable $now): array
    {
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $notifications = $this->toldQuery($viewer)
            ->where('created_at', '>=', $now->setTimezone($zone)->startOfDay()->utc())
            ->latest()
            ->limit(40)
            ->get(['id', 'data', 'created_at']);
        if ($notifications->isEmpty()) {
            return [];
        }
        $alertIds = $notifications->map(fn (DatabaseNotification $notification): int => (int) data_get($notification->data, 'context.alert_id', 0))->filter()->unique()->values()->all();
        $here = DeviceAlert::query()
            ->whereIn('id', $alertIds === [] ? [0] : $alertIds)
            ->where('site_id', $siteId)
            ->get(['id', 'device_id', 'site_id', 'kind', 'title', 'opened_at'])
            ->keyBy(fn (DeviceAlert $alert): int => (int) $alert->id);
        $carePeople = SiteStream::carePeople($viewer, $here->values());

        return $notifications
            ->filter(fn (DatabaseNotification $notification): bool => $here->has((int) data_get($notification->data, 'context.alert_id', 0)))
            ->take(8)
            ->map(function (DatabaseNotification $notification) use ($here, $carePeople): array {
                $alertId = (int) data_get($notification->data, 'context.alert_id', 0);
                /** @var DeviceAlert $alert */
                $alert = $here->get($alertId);
                $words = new AlertWords($alert, $carePeople[$alertId] ?? false);

                return [
                    'id' => (string) $notification->id,
                    'at' => $notification->created_at?->toIso8601String(),
                    'tone' => match ((string) data_get($notification->data, 'severity')) {
                        'critical' => 'critical',
                        'warning' => 'warning',
                        default => 'info',
                    },
                    'title' => $words->text((string) data_get($notification->data, 'title', 'An alert')) ?? 'An alert',
                    'sub' => (string) ($words->text(trim((string) data_get($notification->data, 'body', ''))) ?? ''),
                ];
            })
            ->values()
            ->all();
    }
}
