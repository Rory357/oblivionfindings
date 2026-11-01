<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Devices page (drawings pg-sdr-2, 2a, 2b, 2c and 2d): tabs by kind, problems first, and each
 * device's reading and last 24 hours where the data has them. Where a reading doesn't exist it
 * says so ("Not available"); nothing here invents a number.
 */
final class DevicesIndexPresenter
{
    public const string LAYOUT_PREFERENCE = 'security-devices.devices.view';

    public const array TABS = ['all', 'network', 'cameras', 'alarms_doors', 'sensors', 'trackers', 'computers', 'other', 'upkeep'];

    public const int PER_PAGE = 25;

    /** Every prop present() returns, so the controller can hand each one over lazily. */
    public const array PROPS = ['tab', 'layout', 'upkeep', 'filters', 'tabs', 'meters', 'sites', 'statuses', 'can', 'cards', 'cameras', 'list', 'batteries', 'updates'];

    /** Cards drawn per group before "Show all"; the rest stay one click away in the list. */
    public const int CARD_LIMIT = 200;

    private const array STATUS_FILTERS = ['offline', 'problem', 'cant_check', 'online', 'not_watched'];

    public function __construct(
        private readonly DeviceDirectory $directory,
        private readonly DeviceLast24Hours $last24Hours,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /**
     * @param  array{tab?: mixed, layout?: mixed, upkeep?: mixed, site?: mixed, status?: mixed, q?: mixed, page?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, array $input, string $layout): array
    {
        $tab = in_array($input['tab'] ?? null, self::TABS, true) ? $input['tab'] : 'all';
        $upkeep = ($input['upkeep'] ?? null) === 'updates' ? 'updates' : 'batteries';
        $status = in_array($input['status'] ?? null, self::STATUS_FILTERS, true) ? $input['status'] : 'all';
        $search = Str::limit(trim((string) ($input['q'] ?? '')), 120, '');
        $siteId = $this->siteFilter($viewer, $input['site'] ?? null);
        $pageNumber = max(1, (int) ($input['page'] ?? 1));
        $now = CarbonImmutable::now();

        $scoped = $this->directory->entries($viewer, $siteId)
            ->sortBy(fn (DeviceEntry $entry): string => $entry->sortKey())
            ->values();
        $filtered = $scoped
            ->filter(fn (DeviceEntry $entry): bool => $status === 'all' || $entry->state->value === $status)
            ->filter(fn (DeviceEntry $entry): bool => $this->matches($entry, $search))
            ->values();
        $inTab = $tab === 'all' || $tab === 'upkeep'
            ? $filtered
            : $filtered->filter(fn (DeviceEntry $entry): bool => $entry->tab() === $tab)->values();

        $props = [
            'tab' => $tab,
            'layout' => $layout,
            'upkeep' => $upkeep,
            'filters' => [
                'site' => $siteId === null ? 'all' : (string) $siteId,
                'status' => $status,
                'q' => $search,
            ],
            'tabs' => $this->tabs($filtered),
            'meters' => $this->meters($scoped),
            'sites' => $this->siteOptions($viewer),
            'statuses' => [
                ['value' => 'all', 'label' => 'Any status'],
                ...array_map(fn (string $value): array => [
                    'value' => $value,
                    'label' => DeviceState::from($value)->label(),
                ], self::STATUS_FILTERS),
            ],
            'can' => $this->can($viewer),
            'cards' => null,
            'cameras' => null,
            'list' => null,
            'batteries' => null,
            'updates' => null,
        ];

        if ($tab === 'upkeep') {
            if ($upkeep === 'updates') {
                $props['updates'] = $this->updates($viewer, $scoped);
            } else {
                $props['batteries'] = $this->batteries($inTab, $pageNumber);
            }
        } elseif ($layout === 'list') {
            $props['list'] = $this->rows($inTab, $pageNumber, $scoped, $now);
        } elseif ($tab === 'cameras') {
            $props['cameras'] = $this->cameraWall($inTab);
        } else {
            $props['cards'] = $this->cards($inTab, $scoped, $now);
        }

        return $props;
    }

    private function siteFilter(User $viewer, mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === 'all') {
            return null;
        }
        $siteId = (int) $raw;
        abort_unless($siteId > 0 && in_array($siteId, $this->access->accessibleSiteIds($viewer), true), 404);

        return $siteId;
    }

    private function matches(DeviceEntry $entry, string $search): bool
    {
        if ($search === '') {
            return true;
        }
        $needle = mb_strtolower($search);
        $haystack = mb_strtolower(implode(' ', array_filter([
            $entry->name(),
            $entry->model(),
            $entry->device->ip_address,
            $entry->siteName,
            $entry->device->location_description,
            $entry->kind->label(),
        ])));

        return str_contains($haystack, $needle);
    }

    /**
     * @param  Collection<int, DeviceEntry>  $entries
     * @return list<array{key: string, count: ?int}>
     */
    private function tabs(Collection $entries): array
    {
        $counts = $entries->countBy(fn (DeviceEntry $entry): string => $entry->tab());

        return array_map(fn (string $tab): array => [
            'key' => $tab,
            'count' => match ($tab) {
                'all' => $entries->count(),
                'upkeep' => null,
                default => (int) ($counts[$tab] ?? 0),
            },
        ], self::TABS);
    }

    /**
     * Header numbers, for the chosen Site (not the status filter): each is a link to its view.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, array{count: int, caption: array{text: string, at: ?string}}>
     */
    private function meters(Collection $entries): array
    {
        $online = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online);
        $offline = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Offline);
        $cantCheck = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::CantCheck);
        $lowBatteries = $entries->filter(fn (DeviceEntry $entry): bool => $entry->batteryLow())
            ->sortBy(fn (DeviceEntry $entry): int => (int) $entry->battery())
            ->values();

        $firstOffline = $offline->first();
        $offlineCaption = match (true) {
            $firstOffline === null => ['text' => 'Nothing offline', 'at' => null],
            $firstOffline->offlineSince !== null => [
                'text' => $firstOffline->name().', since {time}'.($offline->count() > 1 ? ' and '.($offline->count() - 1).' more' : ''),
                'at' => $firstOffline->offlineSince->toIso8601String(),
            ],
            default => [
                'text' => $firstOffline->name().($offline->count() > 1 ? ' and '.($offline->count() - 1).' more' : ''),
                'at' => null,
            ],
        };

        $behind = $cantCheck->pluck('behind')->filter()->unique()->values();
        $cantCheckCaption = match (true) {
            $cantCheck->isEmpty() => 'Everything can be checked',
            $behind->count() === 1 => 'Behind '.$behind->first().' · grey, not red',
            default => 'Behind a router or hub that’s down · grey, not red',
        };

        $batteryNames = $lowBatteries->take(2)->map(fn (DeviceEntry $entry): string => $entry->name())->values();
        $batteryCaption = match (true) {
            $lowBatteries->isEmpty() => 'No battery at '.DeviceEntry::LOW_BATTERY.'% or below',
            $lowBatteries->count() <= 2 => $batteryNames->join(' and '),
            default => $batteryNames->join(', ').' and '.($lowBatteries->count() - 2).' more',
        };

        return [
            'online' => ['count' => $online->count(), 'caption' => ['text' => 'Watched and answering', 'at' => null]],
            'offline' => ['count' => $offline->count(), 'caption' => $offlineCaption],
            'cant_check' => ['count' => $cantCheck->count(), 'caption' => ['text' => $cantCheckCaption, 'at' => null]],
            'batteries_low' => ['count' => $lowBatteries->count(), 'caption' => ['text' => $batteryCaption, 'at' => null]],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function siteOptions(User $viewer): array
    {
        $ids = $this->access->accessibleSiteIds($viewer);
        if ($ids === []) {
            return [['value' => 'all', 'label' => 'All Sites']];
        }

        return [
            ['value' => 'all', 'label' => 'All Sites'],
            ...Site::query()
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Site $site): array => ['value' => (string) $site->id, 'label' => (string) $site->name])
                ->all(),
        ];
    }

    /** @return array<string, bool> */
    private function can(User $viewer): array
    {
        return [
            'add' => $viewer->canDo('securityDevices.devices.create'),
            'assign' => $viewer->canDo('securityDevices.devices.assign'),
            'scan' => $viewer->canDo('securityDevices.integrations.manage'),
            'connect' => $viewer->canDo('securityDevices.integrations.manage'),
            'edit' => $viewer->canDo('securityDevices.devices.update'),
            'move' => $viewer->canDo('securityDevices.devices.assign'),
            'retire' => $viewer->canDo('securityDevices.devices.delete'),
            'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
        ];
    }

    /**
     * Cards: problems first, in two groups, with the not-watched devices as one quiet line.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  Collection<int, DeviceEntry>  $scoped
     * @return array<string, mixed>
     */
    private function cards(Collection $entries, Collection $scoped, CarbonImmutable $now): array
    {
        $attention = $entries->filter(fn (DeviceEntry $entry): bool => $entry->needsAttention())->values();
        $normal = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online && ! $entry->batteryLow())->values();
        $notWatched = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::NotWatched)->values();

        $shown = $attention->take(self::CARD_LIMIT)->concat($normal->take(self::CARD_LIMIT));
        $last24 = $this->last24Hours->forEntries($shown, $now);
        $behindCounts = $scoped->pluck('behind')->filter()->countBy();

        $attentionParts = array_filter([
            $this->plural($attention->where('state', DeviceState::Offline)->count(), 'offline', 'offline'),
            $this->plural($attention->where('state', DeviceState::CantCheck)->count(), 'can’t be checked', 'can’t be checked'),
            $this->plural($attention->where('state', DeviceState::Problem)->count(), 'needs attention', 'need attention'),
            $this->plural($attention->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::Online && $entry->batteryLow())->count(), 'battery low', 'batteries low'),
        ]);

        return [
            'groups' => [
                [
                    'key' => 'attention',
                    'title' => 'Needs attention',
                    'count' => $attention->count(),
                    'sub' => implode(' · ', $attentionParts),
                    'truncated' => $attention->count() > self::CARD_LIMIT,
                    'items' => $attention->take(self::CARD_LIMIT)
                        ->map(fn (DeviceEntry $entry): array => $this->card($entry, $last24[$entry->id()] ?? null, $behindCounts))
                        ->values()->all(),
                ],
                [
                    'key' => 'normal',
                    'title' => 'Working normally',
                    'count' => $normal->count(),
                    'sub' => '',
                    'truncated' => $normal->count() > self::CARD_LIMIT,
                    'items' => $normal->take(self::CARD_LIMIT)
                        ->map(fn (DeviceEntry $entry): array => $this->card($entry, $last24[$entry->id()] ?? null, $behindCounts))
                        ->values()->all(),
                ],
            ],
            'not_watched' => [
                'count' => $notWatched->count(),
                'names' => $notWatched->take(3)->map(fn (DeviceEntry $entry): string => $entry->name().' ('.$entry->model().')')->values()->all(),
            ],
        ];
    }

    private function plural(int $count, string $one, string $many): ?string
    {
        return $count === 0 ? null : $count.' '.($count === 1 ? $one : $many);
    }

    /**
     * @param  array{hours: ?string, spark: ?list<?float>, unit: ?string, latency_ms: ?int}|null  $last24
     * @param  Collection<string, int>  $behindCounts
     * @return array<string, mixed>
     */
    public function card(DeviceEntry $entry, ?array $last24, Collection $behindCounts): array
    {
        $readingAt = $this->readingAt($entry);

        return [
            ...$this->identity($entry),
            'badge' => $this->badge($entry),
            'note' => $this->note($entry, $last24, (int) ($behindCounts[$entry->name()] ?? 0)),
            'reading' => $this->reading($entry, $last24),
            'graph' => $this->graph($entry, $last24),
            'seen' => match (true) {
                $readingAt !== null => ['label' => 'Reading', 'at' => $readingAt],
                $entry->state === DeviceState::Online => ['label' => 'Seen', 'at' => $entry->lastSeenAt()?->toIso8601String()],
                default => ['label' => 'Last seen', 'at' => $entry->lastSeenAt()?->toIso8601String()],
            },
        ];
    }

    /** @return array<string, mixed> */
    private function identity(DeviceEntry $entry): array
    {
        return [
            'id' => $entry->id(),
            'name' => $entry->name(),
            'sub' => $entry->subline(),
            'kind' => $entry->kind->value,
            'kind_label' => $entry->kind->label(),
            'state' => $entry->state->value,
            'site' => $entry->siteName,
            'site_id' => $entry->siteId,
            'address' => $entry->device->ip_address,
            'model' => $entry->model(),
            'battery' => $entry->battery(),
            'watching' => $entry->state === DeviceState::NotWatched
                ? 'Nothing'
                : Str::ucfirst(implode(', ', $entry->watching)),
            'href' => '/security-devices/devices/'.$entry->id(),
        ];
    }

    /** @return array{label: string, tone: string} */
    private function badge(DeviceEntry $entry): array
    {
        if ($entry->state === DeviceState::Online && $entry->batteryLow()) {
            return ['label' => 'Battery '.$entry->battery().'%', 'tone' => 'warning'];
        }

        return ['label' => $entry->state->label(), 'tone' => $entry->state->tone()];
    }

    /**
     * One plain line. "{time}" and "{ago}" are filled in on the page, in NZ time.
     *
     * @param  array{hours: ?string, spark: ?list<?float>, unit: ?string, latency_ms: ?int}|null  $last24
     * @return array{text: string, at: ?string}
     */
    private function note(DeviceEntry $entry, ?array $last24, int $behindCount): array
    {
        $seen = $entry->lastSeenAt();
        $behindText = $behindCount > 0
            ? ' · '.$behindCount.' '.($behindCount === 1 ? 'device' : 'devices').' behind it can’t be checked'
            : '';

        return match ($entry->state) {
            DeviceState::Offline => match (true) {
                $entry->offlineSince !== null => ['text' => 'Since {time}'.$behindText, 'at' => $entry->offlineSince->toIso8601String()],
                $seen !== null => ['text' => 'Last seen {time}'.$behindText, 'at' => $seen->toIso8601String()],
                default => ['text' => 'No answer yet'.$behindText, 'at' => null],
            },
            DeviceState::CantCheck => ['text' => match (true) {
                $entry->behind !== null && $entry->behindHub => $entry->behind.' isn’t answering',
                $entry->behind !== null => $entry->behind.' is offline',
                default => 'Something in front of it is down',
            }, 'at' => null],
            DeviceState::Problem => [
                'text' => $entry->failingCheck !== null ? 'Check not passing: '.$entry->failingCheck : 'It reported a problem',
                'at' => null,
            ],
            DeviceState::NotWatched => ['text' => 'Not watched · no alerts for it', 'at' => null],
            default => match (true) {
                $entry->batteryLow() && $entry->device->battery_updated_at !== null => [
                    'text' => 'Battery read {ago}',
                    'at' => $entry->device->battery_updated_at->toIso8601String(),
                ],
                $entry->lastCheckedAt !== null => [
                    'text' => 'Checked {ago}'.(($last24['latency_ms'] ?? null) !== null ? ' · replies in '.$this->ms((int) $last24['latency_ms']) : ''),
                    'at' => $entry->lastCheckedAt->toIso8601String(),
                ],
                $seen !== null => ['text' => 'Reported {ago}', 'at' => $seen->toIso8601String()],
                default => ['text' => 'Reports through a connected service', 'at' => null],
            },
        };
    }

    private function ms(int $value): string
    {
        return $value < 1 ? 'under 1 ms' : $value.' ms';
    }

    /**
     * The reading that matters, or null for "Not available". `from` asks the page for a duration.
     *
     * @param  array{hours: ?string, spark: ?list<?float>, unit: ?string, latency_ms: ?int}|null  $last24
     * @return array{value: ?string, sub: ?string, from: ?string}|null
     */
    private function reading(DeviceEntry $entry, ?array $last24): ?array
    {
        if ($entry->state === DeviceState::Offline) {
            return $entry->offlineSince !== null
                ? ['value' => null, 'sub' => 'offline', 'from' => $entry->offlineSince->toIso8601String()]
                : null;
        }

        $readings = $this->sensorReadings($entry);
        if ($readings !== []) {
            $first = array_shift($readings);

            return [
                'value' => $first,
                'sub' => $readings === [] ? null : implode(' · ', $readings),
                'from' => null,
            ];
        }

        if ($entry->battery() !== null) {
            return ['value' => $entry->battery().'%', 'sub' => 'battery', 'from' => null];
        }

        if ($entry->state === DeviceState::Online && ($last24['latency_ms'] ?? null) !== null) {
            return ['value' => $this->ms((int) $last24['latency_ms']), 'sub' => 'reply time at the last check', 'from' => null];
        }

        return null;
    }

    /** @return list<string> e.g. ["21.4 °C", "48% humidity", "CO₂ 620 ppm"] */
    private function sensorReadings(DeviceEntry $entry): array
    {
        $order = ['temperature', 'humidity', 'co2', 'tvoc', 'pm25', 'pressure', 'illuminance', 'people_count'];
        $words = [];
        foreach ($order as $key) {
            $reading = $entry->readings[$key] ?? null;
            if (! is_array($reading) || ! is_numeric($reading['value'] ?? null)) {
                continue;
            }
            $value = (float) $reading['value'];
            $number = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
            $words[] = match ($key) {
                'temperature' => $number.' °C',
                'humidity' => $number.'% humidity',
                'co2' => 'CO₂ '.$number.' ppm',
                'tvoc' => 'TVOC '.$number.' ppb',
                'pm25' => 'PM2.5 '.$number,
                'pressure' => $number.' hPa',
                'illuminance' => $number.' lx',
                default => $number.' people',
            };
        }

        return $words;
    }

    private function readingAt(DeviceEntry $entry): ?string
    {
        $latest = collect($entry->readings)
            ->map(fn (mixed $reading): ?string => is_array($reading) && is_string($reading['observed_at'] ?? null) ? $reading['observed_at'] : null)
            ->filter()
            ->max();

        if (! is_string($latest)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($latest)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{hours: ?string, spark: ?list<?float>, unit: ?string, latency_ms: ?int}|null  $last24
     * @return array{label: string, note: ?array{text: string, at: ?string}, hours: ?string, spark: ?list<?float>, unit: ?string}
     */
    private function graph(DeviceEntry $entry, ?array $last24): array
    {
        $spark = $last24['spark'] ?? null;
        $hours = $last24['hours'] ?? null;

        if ($spark !== null) {
            $values = array_values(array_filter($spark, fn (?float $value): bool => $value !== null));
            $unit = $this->unitWord($last24['unit'] ?? null);

            return [
                'label' => 'Readings, last 24 hours',
                'note' => ['text' => $this->number(min($values)).'–'.$this->number(max($values)).($unit !== '' ? ' '.$unit : ''), 'at' => null],
                'hours' => null,
                'spark' => $spark,
                'unit' => $unit,
            ];
        }

        $label = match (true) {
            $entry->state === DeviceState::CantCheck => 'Checked, last 24 hours',
            in_array($entry->kind, [DeviceKind::CareTracker, DeviceKind::VehicleTracker, DeviceKind::AlarmPanel, DeviceKind::Sensor], true) => 'Reports, last 24 hours',
            default => 'Online, last 24 hours',
        };

        if ($hours === null) {
            return ['label' => $label, 'note' => null, 'hours' => null, 'spark' => null, 'unit' => null];
        }

        $answered = substr_count($hours, 'o');
        $note = match (true) {
            $answered === 24 => ['text' => 'All 24 h', 'at' => null],
            $entry->state === DeviceState::Offline && str_ends_with($hours, 'c') && $entry->offlineSince !== null => ['text' => 'Down since {time}', 'at' => $entry->offlineSince->toIso8601String()],
            $entry->state === DeviceState::CantCheck && str_ends_with($hours, 'n') => ['text' => 'Not checked for '.(24 - strlen(rtrim($hours, 'n'))).' h', 'at' => null],
            default => ['text' => $answered.' of 24 h', 'at' => null],
        };

        return ['label' => $label, 'note' => $note, 'hours' => $hours, 'spark' => null, 'unit' => null];
    }

    private function unitWord(?string $unit): string
    {
        return match ($unit) {
            null, '' => '',
            'C', 'celsius', '°C' => '°C',
            default => $unit,
        };
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /**
     * List rows, problems first then by name, 25 to a page.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @param  Collection<int, DeviceEntry>  $scoped
     * @return array<string, mixed>
     */
    private function rows(Collection $entries, int $page, Collection $scoped, CarbonImmutable $now): array
    {
        $total = $entries->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);
        $slice = $entries->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();
        $last24 = $this->last24Hours->forEntries($slice, $now);
        $behindCounts = $scoped->pluck('behind')->filter()->countBy();

        return [
            'rows' => $slice->map(fn (DeviceEntry $entry): array => [
                ...$this->identity($entry),
                'badge' => $this->badge($entry),
                'note' => $this->note($entry, $last24[$entry->id()] ?? null, (int) ($behindCounts[$entry->name()] ?? 0)),
                'seen_at' => $entry->lastSeenAt()?->toIso8601String(),
            ])->all(),
            'meta' => $this->meta($total, $page, $lastPage),
        ];
    }

    /** @return array{total: int, page: int, last_page: int, from: int, to: int} */
    private function meta(int $total, int $page, int $lastPage): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
            'to' => min($total, $page * self::PER_PAGE),
        ];
    }

    /**
     * The camera wall. Oblivion doesn't keep camera stills yet, so every tile says so plainly
     * and opens the camera's page; live viewing asks for a reason there.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function cameraWall(Collection $entries): array
    {
        $cantCheck = $entries->filter(fn (DeviceEntry $entry): bool => $entry->state === DeviceState::CantCheck);

        return [
            'stills' => false,
            'items' => $entries->map(fn (DeviceEntry $entry): array => [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'site' => $entry->siteName,
                'state' => $entry->state->value,
                'line' => $this->cameraLine($entry),
                'line_at' => $entry->state === DeviceState::Offline ? $entry->offlineSince?->toIso8601String() : null,
                'href' => '/security-devices/devices/'.$entry->id(),
            ])->all(),
            'cant_check' => $cantCheck
                ->groupBy(fn (DeviceEntry $entry): string => (string) ($entry->siteName ?? 'No Site'))
                ->map(fn (Collection $group, string $site): array => [
                    'site' => $site,
                    'text' => ($group->count() === 1 ? 'Its camera can’t be checked: ' : 'Its '.$group->count().' cameras can’t be checked: ')
                        .(($behind = $group->pluck('behind')->filter()->first()) !== null
                            ? $behind.($group->first()->behindHub ? ' isn’t answering.' : ' is offline.')
                            : 'something in front of them is down.'),
                ])
                ->values()
                ->all(),
        ];
    }

    private function cameraLine(DeviceEntry $entry): string
    {
        return match ($entry->state) {
            DeviceState::Offline => $entry->offlineSince !== null ? 'offline since {time}' : 'offline',
            DeviceState::CantCheck => 'can’t check',
            DeviceState::Problem => 'needs attention',
            DeviceState::NotWatched => 'not watched',
            default => 'online',
        };
    }

    /**
     * Batteries, lowest first. A forecast needs a battery's history, which isn't kept yet.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function batteries(Collection $entries, int $page): array
    {
        $withBattery = $entries
            ->filter(fn (DeviceEntry $entry): bool => $entry->battery() !== null)
            ->sortBy(fn (DeviceEntry $entry): string => str_pad((string) $entry->battery(), 3, '0', STR_PAD_LEFT).'|'.mb_strtolower($entry->name()))
            ->values();
        $total = $withBattery->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);

        return [
            'forecast' => false,
            'rows' => $withBattery->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->map(function (DeviceEntry $entry): array {
                $battery = (int) $entry->battery();
                $charged = in_array($entry->kind, [DeviceKind::CareTracker, DeviceKind::VehicleTracker], true);

                return [
                    'id' => $entry->id(),
                    'name' => $entry->name(),
                    'model' => $entry->model(),
                    'kind' => $entry->kind->value,
                    'where' => implode(' · ', array_filter([
                        $entry->person ?? $entry->siteName,
                        $entry->person === null ? $entry->device->location_description : null,
                    ])) ?: null,
                    'battery' => $battery,
                    'tone' => match (true) {
                        $entry->state === DeviceState::CantCheck => 'neutral',
                        $battery <= 15 => 'critical',
                        $battery <= 25 => 'warning',
                        $charged => 'info',
                        default => 'success',
                    },
                    'cant_check' => $entry->state === DeviceState::CantCheck,
                    'behind' => $entry->behind,
                    'charged' => $charged,
                    'read_at' => $entry->device->battery_updated_at?->toIso8601String(),
                    'href' => '/security-devices/devices/'.$entry->id(),
                ];
            })->values()->all(),
            'meta' => $this->meta($total, $page, $lastPage),
        ];
    }

    /**
     * Updates. No source tells Oblivion yet which devices have newer software, so the table
     * says so; software changes the devices reported in the last 30 days are real events.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function updates(User $viewer, Collection $entries): array
    {
        $names = $entries->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->name()]);
        $recent = [];
        if ($names->isNotEmpty()) {
            $query = DeviceEvent::query()
                ->whereIn('device_events.device_id', $names->keys()->all())
                ->where('device_events.event_type', 'firmware_updated')
                ->where('device_events.occurred_at', '>=', now()->subDays(30));
            $this->access->applyTemporalEventCustodyScope($query, $viewer);
            $recent = $query
                ->latest('device_events.occurred_at')
                ->limit(10)
                ->get(['device_events.id', 'device_events.device_id', 'device_events.payload', 'device_events.occurred_at'])
                ->map(function (DeviceEvent $event) use ($names): array {
                    $from = data_get($event->payload, 'from_version') ?? data_get($event->payload, 'previous_version');
                    $to = data_get($event->payload, 'to_version') ?? data_get($event->payload, 'version');

                    return [
                        'id' => (int) $event->id,
                        'device_id' => (int) $event->device_id,
                        'name' => (string) ($names[$event->device_id] ?? 'Device'),
                        'versions' => is_scalar($from) && is_scalar($to) ? "{$from} → {$to}" : (is_scalar($to) ? 'Now on '.$to : null),
                        'at' => $event->occurred_at instanceof CarbonInterface ? $event->occurred_at->toIso8601String() : null,
                    ];
                })
                ->all();
        }

        return [
            'source' => false,
            'recent' => $recent,
        ];
    }
}
