<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Care\CareRules;
use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Care\SensorMonitoringConsent;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Shift;
use App\Models\User;
use App\Services\ConsentValidationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * S&D E6 (drawing pg-sdr-7b): a bed sensor's "Nights" view, its three header meters, last night's
 * in/out-of-bed log, minutes out of bed over the last 7 nights, and the 4-check readiness ring.
 *
 * Built strictly from readings and records already stored (`security_device_sensor_readings`,
 * `DevicePersonLink`, `CarePlan`, `ClientConsent`, `Shift`, `DeviceAlert`). A resident's name and
 * record link are included only when the viewer may open that resident's record (`Gate::allows('view', $client)`).
 */
final class SensorNightsPresenter
{
    public function __construct(
        private readonly SensorMonitoringConsent $consent,
    ) {}

    public static function isBedSensor(Device $device, ?DevicePersonLink $link): bool
    {
        if ($link?->purpose === 'falls') {
            return true;
        }
        if (data_get($device->meta, 'observed.sensor_readings.occupancy') !== null) {
            return true;
        }
        $haystack = strtolower(trim($device->name.' '.$device->model.' '.(string) $device->subcategory));

        return str_contains($haystack, 'bed sensor') || str_contains($haystack, 'bed pad');
    }

    /**
     * Header meters for drawing 7b: Right now · Last night · Alerts in 7 nights.
     *
     * @return list<array<string, mixed>>
     */
    public function meters(
        Device $device,
        ?DevicePersonLink $link,
        bool $canSeeAlerts,
        CarbonImmutable $now,
    ): array {
        $rule = (array) ($link?->rule ?? []);
        $from = (string) ($rule['from'] ?? '22:00');
        $to = (string) ($rule['to'] ?? '06:00');
        $limitMinutes = max(1, (int) ($rule['minutes'] ?? 10));

        $rows = $this->occupancyRows((int) $device->id, $now->subDays(9), $now);
        $rightNow = $this->rightNowMeter($device, $rows, $rule, $now);

        [$nightStart, $nightEnd] = $this->lastNightWindow($now, $from, $to);
        $nightSummary = $this->summariseNight($rows, $nightStart, $nightEnd, $now);

        $alertsLastNight = $canSeeAlerts
            ? DeviceAlert::query()
                ->where('device_id', $device->id)
                ->where('kind', 'care_out_of_bed')
                ->where('opened_at', '>=', $nightStart->utc())
                ->where('opened_at', '<=', $nightEnd->utc())
                ->count()
            : 0;

        $lastNightValue = null;
        $lastNightCaption = 'No bed readings recorded';
        if ($nightSummary['has_data']) {
            $count = count($nightSummary['episodes']);
            $lastNightValue = match ($count) {
                0 => 'In bed all night',
                1 => 'Out once',
                2 => 'Out twice',
                default => "Out {$count} times",
            };
            if ($count === 0) {
                $lastNightCaption = 'Night hours '.self::clockFromHm($from).' – '.self::clockFromHm($to);
            } else {
                $longest = max(array_map(fn (array $ep): int => $ep['minutes'], $nightSummary['episodes']));
                $alertNote = $canSeeAlerts
                    ? ($alertsLastNight === 0 ? 'no alert' : ($alertsLastNight === 1 ? '1 alert' : "{$alertsLastNight} alerts"))
                    : 'under '.$limitMinutes.' min rule';
                $lastNightCaption = "Longest {$longest} min · {$alertNote}";
            }
        }

        $meters = [
            $rightNow,
            [
                'key' => 'last_night',
                'label' => 'Last night',
                'value' => $lastNightValue,
                'from' => null,
                'ago' => null,
                'caption' => ['text' => $lastNightCaption, 'at' => null],
                'tone' => $alertsLastNight > 0 ? 'warning' : 'brand',
                'tab' => 'nights',
                'bar' => null,
            ],
        ];

        if ($canSeeAlerts) {
            $weekAlerts = DeviceAlert::query()
                ->where('device_id', $device->id)
                ->where('kind', 'care_out_of_bed')
                ->where('opened_at', '>=', $now->subDays(7))
                ->orderByDesc('opened_at')
                ->limit(20)
                ->get(['id', 'opened_at']);
            $count = $weekAlerts->count();
            $latest = $weekAlerts->first()?->opened_at;
            $meters[] = [
                'key' => 'alerts_7d',
                'label' => 'Alerts in 7 nights',
                'value' => (string) $count,
                'from' => null,
                'ago' => null,
                'caption' => [
                    'text' => $latest !== null
                        ? $latest->setTimezone(CareRules::ZONE)->format('D j M, g:i a')
                        : 'None in the last 7 nights',
                    'at' => null,
                ],
                'tone' => $count > 0 ? 'warning' : 'success',
                'tab' => 'alerts',
                'bar' => null,
            ];
        } else {
            $meters[] = [
                'key' => 'alerts_7d',
                'label' => 'Alerts in 7 nights',
                'value' => null,
                'from' => null,
                'ago' => null,
                'caption' => ['text' => 'Alert history not available', 'at' => null],
                'tone' => 'brand',
                'tab' => 'nights',
                'bar' => null,
            ];
        }

        return $meters;
    }

    /**
     * Full payload for the "Nights" tab (pg-sdr-7b).
     *
     * @param  array{id: int, name: string, state: string, state_label: string}|null  $gateway
     * @return array<string, mixed>
     */
    public function present(
        User $viewer,
        Device $device,
        ?DevicePersonLink $link,
        ?int $siteId,
        ?string $siteName,
        ?array $gateway,
        ?string $lastReadingAt,
        bool $canSeeAlerts,
        bool $retired,
        CarbonImmutable $now,
    ): array {
        $client = $link?->client;
        $canSeePerson = $client instanceof Client && Gate::forUser($viewer)->allows('view', $client);
        $shortName = $canSeePerson && $client instanceof Client ? DevicePersonLinks::name($client) : null;
        $fullName = $canSeePerson && $client instanceof Client ? trim($client->first_name.' '.$client->last_name) : null;
        if ($fullName === '') {
            $fullName = $shortName;
        }

        $rule = (array) ($link?->rule ?? []);
        $from = (string) ($rule['from'] ?? '22:00');
        $to = (string) ($rule['to'] ?? '06:00');
        $limitMinutes = max(1, (int) ($rule['minutes'] ?? 10));
        $fromWords = self::clockFromHm($from);
        $toWords = self::clockFromHm($to);
        $room = trim((string) $device->location_description);

        $rows = $this->occupancyRows((int) $device->id, $now->subDays(9), $now);
        [$lastNightStart, $lastNightEnd] = $this->lastNightWindow($now, $from, $to);

        return [
            'care_banner' => $this->careBanner($link, $canSeePerson, $shortName, $siteName, $fromWords, $toWords, $limitMinutes),
            'can_link' => $viewer->canDo('securityDevices.people.link') && ! $retired,
            'last_night' => $this->lastNightLog($rows, $lastNightStart, $lastNightEnd, $fromWords, $toWords, $limitMinutes, $now),
            'seven_nights' => $this->sevenNights($device, $rows, $lastNightStart, $from, $to, $canSeeAlerts, $now),
            'readiness' => $this->readiness($device, $link, $canSeePerson, $shortName, $fullName, $room, $siteId, $from, $to, $lastReadingAt, $now),
            'person_card' => $this->personCard($link, $canSeePerson, $fullName, $siteName, $room),
            'what_it_senses' => $this->whatItSenses($device, $gateway),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function careBanner(
        ?DevicePersonLink $link,
        bool $canSeePerson,
        ?string $shortName,
        ?string $siteName,
        string $fromWords,
        string $toWords,
        int $limitMinutes,
    ): ?array {
        if ($link === null || $link->purpose !== 'falls') {
            return null;
        }
        $plan = $canSeePerson ? $link->carePlan : null;
        $planWords = null;
        if ($plan instanceof CarePlan) {
            $plan->loadMissing('reviewer:id,name');
            $reviewed = $plan->reviewed_at !== null
                ? 'reviewed '.$plan->reviewed_at->setTimezone(CareRules::ZONE)->format('D j M Y')
                    .($plan->reviewer ? ' by '.$plan->reviewer->name : '')
                : null;
            $planWords = 'From '.($plan->title !== '' ? $plan->title : 'their falls plan').($reviewed !== null ? ', '.$reviewed : '');
        }
        $shiftWords = 'goes to whoever is on the night shift'.($siteName !== null ? ' at '.$siteName : '');

        return [
            'kicker' => 'Linked to '.($canSeePerson && $shortName !== null ? $shortName.'’s' : 'a resident’s').' falls plan',
            'title' => 'Alert night staff if '.($canSeePerson && $shortName !== null ? $shortName.' is' : 'they are')
                ." out of bed for over {$limitMinutes} minutes between {$fromWords} and {$toWords}",
            'sub' => $planWords !== null ? $planWords.' · '.$shiftWords : ucfirst($shiftWords),
            'night_hours' => "{$fromWords} – {$toWords}",
            'person_href' => $canSeePerson ? '/clients/'.$link->client_id : null,
        ];
    }

    /**
     * @param  Collection<int, object{value: float, at: CarbonImmutable}>  $rows
     * @return array{sub: string, items: list<array{key: string, time: string, tone: string, title: string, sub: ?string}>, empty: ?string}
     */
    private function lastNightLog(
        Collection $rows,
        CarbonImmutable $nightStart,
        CarbonImmutable $nightEnd,
        string $fromWords,
        string $toWords,
        int $limitMinutes,
        CarbonImmutable $now,
    ): array {
        $sub = $nightStart->format('D j').' – '.$nightEnd->format('D j M');
        $summary = $this->summariseNight($rows, $nightStart, $nightEnd, $now);

        if (! $summary['has_data']) {
            return [
                'sub' => $sub,
                'items' => [],
                'empty' => 'No bed occupancy readings were recorded last night.',
            ];
        }

        $items = [];
        if ($summary['first_in_bed_at'] !== null) {
            $firstLocal = $summary['first_in_bed_at']->setTimezone(CareRules::ZONE);
            $atStart = $summary['first_in_bed_at']->lte($nightStart->utc());
            $items[] = [
                'key' => 'in-bed-start',
                'time' => $atStart ? $fromWords : $firstLocal->format('g:i a'),
                'tone' => 'success',
                'title' => 'In bed',
                'sub' => $atStart ? 'Already in bed when night hours started' : "Night hours started at {$fromWords}",
            ];
        }

        foreach ($summary['episodes'] as $index => $ep) {
            /** @var CarbonImmutable $epFrom */
            $epFrom = $ep['from'];
            /** @var CarbonImmutable $epTo */
            $epTo = $ep['to'];
            $mins = $ep['minutes'];
            $over = $mins >= $limitMinutes;
            $backClock = $epTo->setTimezone(CareRules::ZONE)->format('g:i a');

            $items[] = [
                'key' => 'out-'.$index,
                'time' => $epFrom->setTimezone(CareRules::ZONE)->format('g:i a'),
                'tone' => $over ? 'warning' : 'info',
                'title' => "Out of bed for {$mins} min",
                'sub' => $ep['back']
                    ? ($over
                        ? "Back at {$backClock} · over {$limitMinutes} minutes, night staff told"
                        : "Back at {$backClock} · under {$limitMinutes} minutes, no alert")
                    : ($now->lt($nightEnd->utc()) ? 'Currently out of bed' : 'Still out of bed when night hours ended'),
            ];
        }

        if ($now->gte($nightEnd->utc())) {
            $upAfter = $rows->first(fn (object $row): bool => $row->at->gte($nightEnd->utc())
                && $row->at->lte($nightEnd->addHours(6)->utc())
                && $row->value < 0.5);
            $items[] = [
                'key' => 'night-ended',
                'time' => $toWords,
                'tone' => 'none',
                'title' => 'Night hours ended',
                'sub' => $upAfter !== null
                    ? 'Up for the day at '.$upAfter->at->setTimezone(CareRules::ZONE)->format('g:i a')
                    : null,
            ];
        }

        return [
            'sub' => $sub,
            'items' => $items,
            'empty' => null,
        ];
    }

    /**
     * @param  Collection<int, object{value: float, at: CarbonImmutable}>  $rows
     * @return array{labels: list<string>, values: list<int>, note: string}
     */
    private function sevenNights(
        Device $device,
        Collection $rows,
        CarbonImmutable $lastNightStartLocal,
        string $from,
        string $to,
        bool $canSeeAlerts,
        CarbonImmutable $now,
    ): array {
        $labels = [];
        $values = [];
        $firstStartUtc = $lastNightStartLocal->subDays(6)->utc();

        for ($offset = 6; $offset >= 0; $offset--) {
            $dayLocal = $lastNightStartLocal->subDays($offset)->startOfDay();
            [$startLocal, $endLocal] = self::windowForDay($dayLocal, $from, $to);
            $summary = $this->summariseNight($rows, $startLocal, $endLocal, $now);
            $labels[] = $startLocal->format('D');
            $values[] = $summary['total_minutes'];
        }

        $note = 'No night alerts in the last 7 nights.';
        if ($canSeeAlerts) {
            $alerts = DeviceAlert::query()
                ->with(['acknowledgedBy:id,name'])
                ->where('device_id', $device->id)
                ->where('kind', 'care_out_of_bed')
                ->where('opened_at', '>=', $firstStartUtc)
                ->orderByDesc('opened_at')
                ->limit(10)
                ->get(['id', 'opened_at', 'acknowledged_by_user_id', 'acknowledged_at']);
            $count = $alerts->count();
            if ($count > 0) {
                $latest = $alerts->first();
                $when = $latest?->opened_at?->setTimezone(CareRules::ZONE)->format('D j M, g:i a') ?? '';
                $ack = $latest?->acknowledgedBy !== null && $latest->acknowledged_at !== null
                    ? ' '.$latest->acknowledgedBy->name.' acknowledged it at '.$latest->acknowledged_at->setTimezone(CareRules::ZONE)->format('g:i a').'.'
                    : '';
                $note = $count === 1
                    ? "One alert in the last 7 nights: {$when}.{$ack}"
                    : "{$count} alerts in the last 7 nights · latest {$when}.{$ack}";
            }
        } else {
            $total = array_sum($values);
            $note = $total === 0
                ? '0 minutes out of bed recorded in the last 7 nights.'
                : "{$total} min out of bed in total across the last 7 nights.";
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'note' => $note,
        ];
    }

    /**
     * 4-check readiness ring (pg-sdr-7b):
     *   1. Linked to resident
     *   2. Falls plan in date
     *   3. Consent to monitoring sensors active
     *   4. Night shift filled on the roster tonight
     *
     * @return array<string, mixed>
     */
    private function readiness(
        Device $device,
        ?DevicePersonLink $link,
        bool $canSeePerson,
        ?string $shortName,
        ?string $fullName,
        string $room,
        ?int $siteId,
        string $from,
        string $to,
        ?string $lastReadingAt,
        CarbonImmutable $now,
    ): array {
        $linkedOk = $link !== null && $link->purpose === 'falls' && $link->client instanceof Client;
        $personWords = $canSeePerson && $fullName !== null ? $fullName : 'a resident';
        $check1 = [
            'state' => $linkedOk ? 'success' : 'warning',
            'label' => $linkedOk
                ? 'Linked to '.$personWords.($room !== '' ? ', '.$room : '')
                : 'Not linked to a resident’s falls plan',
            'note' => null,
        ];

        $plan = $link?->carePlan;
        $todayLocal = $now->setTimezone(CareRules::ZONE)->startOfDay();
        $planActive = $plan instanceof CarePlan && in_array($plan->status, ['active', 'review'], true);
        $planNotEnded = $plan instanceof CarePlan && ($plan->ends_at === null || CarbonImmutable::parse($plan->ends_at->toDateString(), CareRules::ZONE)->gte($todayLocal));
        $planReviewOk = $plan instanceof CarePlan && ($plan->next_review_at === null || CarbonImmutable::parse($plan->next_review_at->toDateString(), CareRules::ZONE)->gte($todayLocal));
        $planOk = $linkedOk && $planActive && $planNotEnded && $planReviewOk;

        $check2 = [
            'state' => $planOk ? 'success' : ($plan instanceof CarePlan ? 'warning' : 'none'),
            'label' => match (true) {
                $planOk => 'Falls plan in date',
                $plan instanceof CarePlan && ! $planReviewOk => 'Falls plan review overdue',
                $plan instanceof CarePlan => 'Falls plan not active',
                default => 'No falls plan linked',
            },
            'note' => $plan?->next_review_at !== null
                ? 'Next review '.CarbonImmutable::parse($plan->next_review_at->toDateString(), CareRules::ZONE)->format('D j M Y')
                : null,
        ];

        $consentOk = false;
        $consentNote = null;
        if ($link?->client instanceof Client) {
            $activeConsent = ConsentValidationService::getActiveConsents($link->client)
                ->first(function (ClientConsent $consent): bool {
                    $consent->loadMissing('consentType');

                    return (string) $consent->consentType?->name === SensorMonitoringConsent::TYPE;
                });
            if ($activeConsent instanceof ClientConsent) {
                $consentOk = true;
                $consentNote = $activeConsent->given_at?->setTimezone(CareRules::ZONE)->format('D j M Y');
            } else {
                $state = $this->consent->state($link->client);
                $consentOk = $state['active'];
            }
        }
        $whoConsent = $canSeePerson && $shortName !== null ? "{$shortName}’s consent" : 'Consent';
        $check3 = [
            'state' => $consentOk ? 'success' : 'warning',
            'label' => $consentOk
                ? "{$whoConsent} to the bed sensor recorded"
                : 'Consent to monitoring sensors not active',
            'note' => $consentNote,
        ];

        [$tonightStart, $tonightEnd] = $this->tonightWindow($now, $from, $to);
        $shift = $this->nightRosterShift($siteId, $tonightStart, $tonightEnd, $now);
        $check4 = [
            'state' => $shift['filled'] ? 'success' : 'warning',
            'label' => $shift['filled']
                ? 'Night shift filled on the roster tonight'
                : 'No night shift filled on the roster tonight',
            'note' => $shift['note'],
        ];

        $checks = [$check1, $check2, $check3, $check4];
        $passed = count(array_filter($checks, fn (array $item): bool => $item['state'] === 'success'));

        return [
            'passed' => $passed,
            'of' => 4,
            'label' => $passed === 4 ? 'Ready to alert night staff' : "{$passed} of 4 checks ready",
            'sub' => $lastReadingAt !== null
                ? ['text' => 'Checked {ago}', 'at' => $lastReadingAt]
                : ['text' => 'No reading received yet', 'at' => null],
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function personCard(
        ?DevicePersonLink $link,
        bool $canSeePerson,
        ?string $fullName,
        ?string $siteName,
        string $room,
    ): ?array {
        if (! $link?->client instanceof Client) {
            return null;
        }
        $client = $link->client;
        $client->loadMissing('keyWorker:id,name');
        $keyWorker = $client->keyWorker?->name;

        return [
            'name' => $canSeePerson && $fullName !== null ? $fullName : 'Resident',
            'sub' => implode(' · ', array_filter([$siteName, $room !== '' ? $room : null])) ?: null,
            'flag' => match ($link->purpose) {
                'falls' => 'Falls risk',
                'wandering' => 'Wandering risk',
                default => 'Linked sensor',
            },
            'href' => $canSeePerson ? '/clients/'.$client->id : null,
            'key_worker' => $keyWorker !== null && trim($keyWorker) !== '' ? trim($keyWorker) : null,
        ];
    }

    /**
     * @param  array{id: int, name: string, state: string, state_label: string}|null  $gateway
     * @return list<array{label: string, text: string, at: ?string}>
     */
    private function whatItSenses(Device $device, ?array $gateway): array
    {
        $stored = data_get($device->meta, 'observed.sensor_readings');
        $rssi = is_numeric(data_get($stored, 'rssi.value')) ? (float) data_get($stored, 'rssi.value') : null;
        $signalParts = [];
        if ($rssi !== null) {
            $text = rtrim(rtrim(number_format($rssi, 1, '.', ''), '0'), '.');
            $signalParts[] = (str_starts_with($text, '-') ? '−'.substr($text, 1) : $text).' dBm';
        }
        if ($gateway !== null) {
            $signalParts[] = 'through '.$gateway['name'];
        }

        return [
            ['label' => 'Senses', 'text' => 'In bed or out of bed only', 'at' => null],
            ['label' => 'Doesn’t', 'text' => 'No camera, no sound', 'at' => null],
            ['label' => 'Signal', 'text' => $signalParts !== [] ? ucfirst(implode(' · ', $signalParts)) : 'Not available', 'at' => null],
        ];
    }

    /**
     * @param  Collection<int, object{value: float, at: CarbonImmutable}>  $rows
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function rightNowMeter(Device $device, Collection $rows, array $rule, CarbonImmutable $now): array
    {
        $latestRow = $rows->last();
        $observed = data_get($device->meta, 'observed.sensor_readings.occupancy');
        $inBed = null;
        $latestAt = null;

        if ($latestRow !== null) {
            $inBed = $latestRow->value >= 0.5;
            $latestAt = $latestRow->at;
        } elseif (is_array($observed) && is_numeric($observed['value'] ?? null)) {
            $inBed = (float) $observed['value'] >= 0.5;
            $latestAt = is_string($observed['observed_at'] ?? null) ? CarbonImmutable::parse($observed['observed_at']) : null;
        }

        if ($inBed === null) {
            return [
                'key' => 'right_now',
                'label' => 'Right now',
                'value' => null,
                'from' => null,
                'ago' => null,
                'caption' => ['text' => 'No bed reading yet', 'at' => null],
                'tone' => 'brand',
                'tab' => 'nights',
                'bar' => null,
            ];
        }

        // Walk back to find when the current in-bed/out-of-bed state started.
        $since = $latestAt;
        for ($i = $rows->count() - 1; $i >= 0; $i--) {
            /** @var object{value: float, at: CarbonImmutable} $row */
            $row = $rows->values()->get($i);
            if (($row->value >= 0.5) === $inBed) {
                $since = $row->at;
            } else {
                break;
            }
        }

        $insideNight = CareRules::insideNight($rule, $now);
        $sinceClock = $since?->setTimezone(CareRules::ZONE)->format('g:i a');
        if ($inBed) {
            $caption = $sinceClock !== null ? "In bed since {$sinceClock}" : 'In bed';
        } else {
            $base = $sinceClock !== null ? "Out of bed since {$sinceClock}" : 'Out of bed';
            $caption = $insideNight ? $base : "{$base} · day time, no alerts";
        }

        return [
            'key' => 'right_now',
            'label' => 'Right now',
            'value' => $inBed ? 'In bed' : 'Up',
            'from' => null,
            'ago' => null,
            'caption' => ['text' => $caption, 'at' => null],
            'tone' => (! $inBed && $insideNight) ? 'warning' : 'brand',
            'tab' => 'nights',
            'bar' => null,
        ];
    }

    /**
     * @param  Collection<int, object{value: float, at: CarbonImmutable}>  $rows
     * @return array{
     *     has_data: bool,
     *     first_in_bed_at: CarbonImmutable|null,
     *     episodes: list<array{from: CarbonImmutable, to: CarbonImmutable, minutes: int, back: bool}>,
     *     total_minutes: int
     * }
     */
    private function summariseNight(
        Collection $rows,
        CarbonImmutable $nightStartLocal,
        CarbonImmutable $nightEndLocal,
        CarbonImmutable $now,
    ): array {
        $startUtc = $nightStartLocal->utc();
        $endUtc = $nightEndLocal->utc();
        $effectiveEndUtc = $now->lt($endUtc) ? $now->utc() : $endUtc;

        if ($effectiveEndUtc->lte($startUtc)) {
            return ['has_data' => false, 'first_in_bed_at' => null, 'episodes' => [], 'total_minutes' => 0];
        }

        $baseline = $rows->last(fn (object $row): bool => $row->at->lte($startUtc));
        $inside = $rows->filter(fn (object $row): bool => $row->at->gt($startUtc) && $row->at->lte($effectiveEndUtc))->values();

        if ($baseline === null && $inside->isEmpty()) {
            return ['has_data' => false, 'first_in_bed_at' => null, 'episodes' => [], 'total_minutes' => 0];
        }

        $inBed = $baseline !== null ? ($baseline->value >= 0.5) : null;
        $firstInBedAt = $inBed === true ? $startUtc : null;
        $outSince = null;
        $episodes = [];

        foreach ($inside as $row) {
            $rowInBed = $row->value >= 0.5;
            if ($rowInBed) {
                if ($firstInBedAt === null) {
                    $firstInBedAt = $row->at;
                }
                if ($outSince !== null) {
                    $mins = max(1, (int) round($outSince->diffInSeconds($row->at) / 60));
                    $episodes[] = ['from' => $outSince, 'to' => $row->at, 'minutes' => $mins, 'back' => true];
                    $outSince = null;
                }
                $inBed = true;
            } else {
                if ($outSince === null && ($inBed === true || $baseline === null)) {
                    $outSince = $row->at;
                }
                $inBed = false;
            }
        }

        if ($outSince !== null && $effectiveEndUtc->gt($outSince)) {
            $mins = max(1, (int) round($outSince->diffInSeconds($effectiveEndUtc) / 60));
            $episodes[] = ['from' => $outSince, 'to' => $effectiveEndUtc, 'minutes' => $mins, 'back' => false];
        }

        $totalMinutes = array_sum(array_map(fn (array $ep): int => $ep['minutes'], $episodes));

        return [
            'has_data' => true,
            'first_in_bed_at' => $firstInBedAt,
            'episodes' => $episodes,
            'total_minutes' => $totalMinutes,
        ];
    }

    /**
     * @return Collection<int, object{value: float, at: CarbonImmutable}>
     */
    private function occupancyRows(int $deviceId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Collection
    {
        $baseline = DB::table('security_device_sensor_readings')
            ->where('device_id', $deviceId)
            ->where('metric', 'occupancy')
            ->where('observed_at', '<', $fromUtc->utc()->format('Y-m-d H:i:s'))
            ->orderByDesc('observed_at')
            ->limit(1)
            ->get(['value', 'observed_at']);

        $recent = DB::table('security_device_sensor_readings')
            ->where('device_id', $deviceId)
            ->where('metric', 'occupancy')
            ->whereBetween('observed_at', [
                $fromUtc->utc()->format('Y-m-d H:i:s'),
                $toUtc->utc()->format('Y-m-d H:i:s'),
            ])
            ->orderBy('observed_at')
            ->limit(10_000)
            ->get(['value', 'observed_at']);

        return $baseline->concat($recent)->map(fn (object $row): object => (object) [
            'value' => (float) $row->value,
            'at' => CarbonImmutable::parse((string) $row->observed_at, 'UTC'),
        ])->values();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function lastNightWindow(CarbonImmutable $now, string $from, string $to): array
    {
        $local = $now->setTimezone(CareRules::ZONE);
        $startDay = $local->format('H:i') >= $from ? $local->startOfDay() : $local->subDay()->startOfDay();

        return self::windowForDay($startDay, $from, $to);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function tonightWindow(CarbonImmutable $now, string $from, string $to): array
    {
        $activeStart = CareRules::nightStartBefore(['from' => $from, 'to' => $to], $now);
        if ($activeStart !== null) {
            $startDay = $activeStart->setTimezone(CareRules::ZONE)->startOfDay();

            return self::windowForDay($startDay, $from, $to);
        }

        return self::windowForDay($now->setTimezone(CareRules::ZONE)->startOfDay(), $from, $to);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function windowForDay(CarbonImmutable $localDay, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($localDay->format('Y-m-d').' '.$from, CareRules::ZONE);
        $end = $to > $from
            ? CarbonImmutable::parse($localDay->format('Y-m-d').' '.$to, CareRules::ZONE)
            : CarbonImmutable::parse($localDay->addDay()->format('Y-m-d').' '.$to, CareRules::ZONE);

        return [$start, $end];
    }

    /**
     * @return array{filled: bool, note: string|null}
     */
    private function nightRosterShift(
        ?int $siteId,
        CarbonImmutable $tonightStartLocal,
        CarbonImmutable $tonightEndLocal,
        CarbonImmutable $now,
    ): array {
        if ($siteId === null || $siteId < 1) {
            return ['filled' => false, 'note' => null];
        }

        $startUtc = $tonightStartLocal->utc();
        $endUtc = $tonightEndLocal->utc();

        $shift = Shift::query()
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->whereNotIn('status', ['cancelled', 'declined', 'no_show'])
            ->whereNotNull('user_id')
            ->where(fn ($query) => $query
                ->where('site_id', $siteId)
                ->orWhere(fn ($derived) => $derived->whereNull('site_id')->whereHas('client', fn ($client) => $client->where('site_id', $siteId))))
            ->orderBy('starts_at')
            ->first(['id', 'starts_at', 'ends_at']);

        if ($shift !== null) {
            $shiftStart = CarbonImmutable::parse($shift->starts_at)->setTimezone(CareRules::ZONE)->format('g:i a');
            $shiftEnd = CarbonImmutable::parse($shift->ends_at)->setTimezone(CareRules::ZONE)->format('g:i a');

            return ['filled' => true, 'note' => "{$shiftStart} – {$shiftEnd}"];
        }

        if ($now->gte($startUtc) && $now->lt($endUtc)) {
            $clockedIn = HrAttendanceSession::query()
                ->where('site_id', $siteId)
                ->where('clock_in_at', '<=', $now->utc())
                ->whereNull('clock_out_at')
                ->where('status', 'open')
                ->exists();
            if ($clockedIn) {
                return ['filled' => true, 'note' => 'Staff clocked in on site'];
            }
        }

        return ['filled' => false, 'note' => null];
    }

    private static function clockFromHm(string $hm): string
    {
        return CarbonImmutable::parse('2026-01-01 '.$hm, CareRules::ZONE)->format('g:i a');
    }
}
