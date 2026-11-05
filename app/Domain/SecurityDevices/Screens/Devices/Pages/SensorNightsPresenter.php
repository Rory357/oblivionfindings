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
 * `DevicePersonLink`, `CarePlan`, `ClientConsent`, `Shift`, `DeviceAlert`).
 *
 * Privacy: in/out of bed is about one resident, so the page shows any of it only when `mayShow()`
 * says so: the viewer may open that resident's record AND their consent to monitoring sensors is
 * active. Inside, resident details stay hidden from anyone who can't open the record.
 *
 * Truth: a state is claimed only from a reading. Time the state isn't known (before the first
 * reading, or while the sensor was offline) is never counted as in bed. "Night staff told" is said
 * only when a stored out-of-bed alert matches that time out of bed.
 *
 * @phpstan-type OccupancyRow object{value: float, at: CarbonImmutable}
 * @phpstan-type OfflineSpell array{from: CarbonImmutable, to: CarbonImmutable, open: bool}
 * @phpstan-type NightHistory array{rows: Collection<int, object{value: float, at: CarbonImmutable}>, offline: list<array{from: CarbonImmutable, to: CarbonImmutable, open: bool}>, alerts: Collection<int, DeviceAlert>}
 * @phpstan-type Episode array{from: CarbonImmutable, to: CarbonImmutable, minutes: int, back: bool, end: string, from_start: bool, alert: DeviceAlert|null}
 */
final class SensorNightsPresenter
{
    /** Days of readings loaded: 7 nights plus the one that may be in progress, plus a margin. */
    private const int HISTORY_DAYS = 9;

    /** The newest readings kept when a chatty sensor sends more than this in HISTORY_DAYS. */
    private const int ROW_LIMIT = 10_000;

    /**
     * A reading older than this before the night starts doesn't tell the state at its start: a
     * sensor that has sent nothing for a whole day may have stopped, so nothing is claimed.
     */
    private const int STATE_KEPT_HOURS = 24;

    /** Roster shifts that count as filled: scheduled or started (never cancelled, draft or no-show). */
    private const array ROSTERED_STATES = ['scheduled', 'in_progress'];

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
     * Whether the viewer may see this bed sensor's nights at all (the Nights tab and the bed meters):
     * only when they may open the linked resident's record and the resident's consent to monitoring
     * sensors is active. An unlinked bed sensor shows the ordinary sensor page.
     */
    public function mayShow(User $viewer, ?DevicePersonLink $link): bool
    {
        $client = $link?->client;
        if (! $client instanceof Client || ! Gate::forUser($viewer)->allows('view', $client)) {
            return false;
        }

        return $this->consent->state($client)['active'];
    }

    /**
     * Everything stored that the meters and the Nights tab read, loaded once per page: bed readings,
     * offline spells, and (only for viewers who may see alerts) the out-of-bed alerts with who was told.
     *
     * @return NightHistory
     */
    public function history(Device $device, bool $canSeeAlerts, CarbonImmutable $now): array
    {
        $fromUtc = $now->utc()->subDays(self::HISTORY_DAYS);
        $toUtc = $now->utc();

        return [
            'rows' => $this->occupancyRows((int) $device->id, $fromUtc, $toUtc),
            'offline' => $this->offlineSpells((int) $device->id, $fromUtc, $toUtc),
            'alerts' => $canSeeAlerts ? $this->outOfBedAlerts((int) $device->id, $fromUtc) : collect(),
        ];
    }

    /**
     * Header meters for drawing 7b: Right now · Last night · Alerts in 7 nights.
     *
     * @param  NightHistory  $history
     * @return list<array<string, mixed>>
     */
    public function meters(
        Device $device,
        ?DevicePersonLink $link,
        array $history,
        bool $canSeeAlerts,
        CarbonImmutable $now,
    ): array {
        $rule = (array) ($link?->rule ?? []);
        $from = (string) ($rule['from'] ?? '22:00');
        $to = (string) ($rule['to'] ?? '06:00');
        $limitMinutes = max(1, (int) ($rule['minutes'] ?? 10));

        $rightNow = $this->rightNowMeter($device, $history, $rule, $now);

        [$nightStart, $nightEnd] = $this->lastNightWindow($now, $from, $to);
        $nightSummary = $this->summariseNight($history, $nightStart, $nightEnd, $now);
        $alertsLastNight = count(array_filter($nightSummary['episodes'], fn (array $ep): bool => $ep['alert'] !== null));

        $lastNightValue = null;
        $lastNightCaption = 'No bed readings recorded';
        if ($nightSummary['has_data']) {
            $count = count($nightSummary['episodes']);
            $lastNightValue = match ($count) {
                0 => $nightSummary['whole_night'] ? 'In bed all night' : 'Not seen out of bed',
                1 => 'Out once',
                2 => 'Out twice',
                default => "Out {$count} times",
            };
            if ($count === 0) {
                $lastNightCaption = $nightSummary['whole_night']
                    ? 'Night hours '.self::clockFromHm($from).' – '.self::clockFromHm($to)
                    : 'No readings for part of the night';
            } else {
                $longest = max(array_map(fn (array $ep): int => $ep['minutes'], $nightSummary['episodes']));
                $parts = ["Longest {$longest} min"];
                $parts[] = $canSeeAlerts
                    ? match ($alertsLastNight) {
                        0 => 'no alert',
                        1 => '1 alert',
                        default => "{$alertsLastNight} alerts",
                    }
                    : match (true) {
                        $longest > $limitMinutes => "over the {$limitMinutes} min rule",
                        $longest === $limitMinutes => "at the {$limitMinutes} min rule",
                        default => "under the {$limitMinutes} min rule",
                    };
                if (! $nightSummary['whole_night']) {
                    $parts[] = 'gaps in readings';
                }
                $lastNightCaption = implode(' · ', $parts);
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
            $sevenDaysAgo = $now->utc()->subDays(7);
            $weekAlerts = $history['alerts']
                ->filter(fn (DeviceAlert $alert): bool => $alert->opened_at !== null && $alert->opened_at->gte($sevenDaysAgo))
                ->sortByDesc(fn (DeviceAlert $alert): int => $alert->opened_at?->getTimestamp() ?? 0)
                ->values();
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
     * Full payload for the "Nights" tab (pg-sdr-7b). The caller shows it only when `mayShow()`;
     * resident details are still hidden here from anyone who can't open the record.
     *
     * @param  NightHistory  $history
     * @param  array{id: int, name: string, state: string, state_label: string}|null  $gateway
     * @return array<string, mixed>
     */
    public function present(
        User $viewer,
        Device $device,
        ?DevicePersonLink $link,
        array $history,
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
        // The room says where the resident sleeps: only for viewers who may open their record.
        $room = $canSeePerson ? trim((string) $device->location_description) : '';

        [$lastNightStart, $lastNightEnd] = $this->lastNightWindow($now, $from, $to);

        return [
            'care_banner' => $this->careBanner($link, $canSeePerson, $shortName, $siteName, $fromWords, $toWords, $limitMinutes),
            'can_link' => $viewer->canDo('securityDevices.people.link') && ! $retired,
            'last_night' => $this->lastNightLog($history, $lastNightStart, $lastNightEnd, $fromWords, $toWords, $limitMinutes, $canSeeAlerts, $now),
            'seven_nights' => $this->sevenNights($history, $lastNightStart, $from, $to, $canSeeAlerts, $now),
            'readiness' => $this->readiness($link, $canSeePerson, $shortName, $fullName, $room, $siteId, $from, $to, $lastReadingAt, $now),
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
     * @param  NightHistory  $history
     * @return array{sub: string, items: list<array{key: string, time: string, tone: string, title: string, sub: ?string}>, empty: ?string}
     */
    private function lastNightLog(
        array $history,
        CarbonImmutable $nightStart,
        CarbonImmutable $nightEnd,
        string $fromWords,
        string $toWords,
        int $limitMinutes,
        bool $canSeeAlerts,
        CarbonImmutable $now,
    ): array {
        $sub = $nightStart->format('D j').' – '.$nightEnd->format('D j M');
        $summary = $this->summariseNight($history, $nightStart, $nightEnd, $now);

        if (! $summary['has_data']) {
            return [
                'sub' => $sub,
                'items' => [],
                'empty' => 'No bed readings were recorded last night.',
            ];
        }

        $startUtc = $nightStart->utc();
        $endUtc = $nightEnd->utc();
        $clock = fn (CarbonImmutable $at): string => $at->lte($startUtc) ? $fromWords : $at->setTimezone(CareRules::ZONE)->format('g:i a');

        /** @var list<array{sort: int, item: array{key: string, time: string, tone: string, title: string, sub: ?string}}> $items */
        $items = [];
        $backTimes = array_map(fn (array $ep): int => $ep['to']->getTimestamp(), array_filter($summary['episodes'], fn (array $ep): bool => $ep['back']));
        if ($summary['start_state'] === true) {
            $items[] = ['sort' => $startUtc->getTimestamp(), 'item' => [
                'key' => 'in-bed-start',
                'time' => $fromWords,
                'tone' => 'success',
                'title' => 'In bed',
                'sub' => 'Already in bed when night hours started',
            ]];
        } elseif ($summary['first_in_bed_at'] !== null && ! in_array($summary['first_in_bed_at']->getTimestamp(), $backTimes, true)) {
            $items[] = ['sort' => $summary['first_in_bed_at']->getTimestamp(), 'item' => [
                'key' => 'in-bed-start',
                'time' => $clock($summary['first_in_bed_at']),
                'tone' => 'success',
                'title' => 'In bed',
                'sub' => "Night hours started at {$fromWords}",
            ]];
        }

        foreach ($summary['episodes'] as $index => $ep) {
            $mins = $ep['minutes'];
            $over = $mins >= $limitMinutes;
            $endClock = $ep['to']->setTimezone(CareRules::ZONE)->format('g:i a');

            $first = array_values(array_filter([
                $ep['from_start'] ? 'Already out of bed when night hours started' : null,
                match ($ep['end']) {
                    'back' => "Back at {$endClock}",
                    'offline' => "Sensor went offline at {$endClock}",
                    'now' => 'Currently out of bed',
                    default => 'Still out of bed when night hours ended',
                },
            ]));
            $rest = array_values(array_filter([
                in_array($ep['end'], ['back', 'night_end'], true) || ($ep['end'] === 'now' && $over)
                    ? match (true) {
                        $mins > $limitMinutes => "over {$limitMinutes} minutes",
                        $mins === $limitMinutes => "at the {$limitMinutes}-minute limit",
                        default => "under {$limitMinutes} minutes",
                    }
                    : null,
                // Who was told comes only from a stored alert that matches this time out of bed.
                $canSeeAlerts ? ($ep['alert'] !== null ? self::toldWords($ep['alert']) : 'no alert') : null,
            ]));

            $items[] = ['sort' => $ep['from']->getTimestamp(), 'item' => [
                'key' => 'out-'.$index,
                'time' => $clock($ep['from']),
                'tone' => $over || $ep['alert'] !== null ? 'warning' : 'info',
                'title' => $ep['end'] === 'offline' ? "Out of bed for at least {$mins} min" : "Out of bed for {$mins} min",
                'sub' => implode(' · ', [...$first, ...($rest !== [] ? [implode(', ', $rest)] : [])]),
            ]];
        }

        $effectiveEnd = $now->lt($endUtc) ? $now->utc() : $endUtc;
        foreach ($summary['gaps'] as $index => $gap) {
            $items[] = ['sort' => $gap['from']->getTimestamp(), 'item' => [
                'key' => 'offline-'.$index,
                'time' => $clock($gap['from']),
                'tone' => 'none',
                'title' => 'Sensor offline',
                'sub' => $gap['to']->lt($effectiveEnd)
                    ? 'No readings until '.$gap['to']->setTimezone(CareRules::ZONE)->format('g:i a')
                    : ($now->lt($endUtc) ? 'No readings since then' : 'No readings for the rest of the night'),
            ]];
        }

        usort($items, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $log = array_map(fn (array $row): array => $row['item'], $items);

        if ($now->gte($endUtc)) {
            $upAfter = $history['rows']->first(fn (object $row): bool => $row->at->gte($endUtc)
                && $row->at->lte($nightEnd->addHours(6)->utc())
                && $row->value < 0.5);
            $log[] = [
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
            'items' => $log,
            'empty' => null,
        ];
    }

    /**
     * Minutes out of bed for each of the last 7 nights. A night with no readings is null
     * ("No readings" on screen), never 0.
     *
     * @param  NightHistory  $history
     * @return array{labels: list<string>, values: list<int|null>, note: string}
     */
    private function sevenNights(
        array $history,
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
            $summary = $this->summariseNight($history, $startLocal, $endLocal, $now);
            $labels[] = $startLocal->format('D');
            $values[] = $summary['total_minutes'];
        }

        $known = array_values(array_filter($values, fn (?int $value): bool => $value !== null));
        $missing = count($values) - count($known);
        $missingWords = match (true) {
            $missing === 0 => '',
            $missing === count($values) => ' No bed readings in the last 7 nights.',
            $missing === 1 => ' No readings on 1 of the 7 nights.',
            default => " No readings on {$missing} of the 7 nights.",
        };

        if ($canSeeAlerts) {
            $alerts = $history['alerts']
                ->filter(fn (DeviceAlert $alert): bool => $alert->opened_at !== null && $alert->opened_at->gte($firstStartUtc))
                ->sortByDesc(fn (DeviceAlert $alert): int => $alert->opened_at?->getTimestamp() ?? 0)
                ->values();
            $count = $alerts->count();
            $note = 'No night alerts in the last 7 nights.';
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
            $note .= $missingWords;
        } elseif ($known === []) {
            $note = 'No bed readings in the last 7 nights.';
        } else {
            $total = array_sum($known);
            $note = ($total === 0
                ? 'No time out of bed recorded at night in the last 7 nights.'
                : "{$total} min out of bed in total across the last 7 nights.").$missingWords;
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
     *   4. Night shift filled on the roster tonight (the whole night, not just part of it)
     *
     * Plan review dates, the consent date and the room are shown only to viewers who may open the
     * resident's record.
     *
     * @return array<string, mixed>
     */
    private function readiness(
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
                ? 'Linked to '.$personWords.($canSeePerson && $room !== '' ? ', '.$room : '')
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
            'note' => $canSeePerson && $plan?->next_review_at !== null
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
                $consentNote = $canSeePerson
                    ? $activeConsent->given_at?->setTimezone(CareRules::ZONE)->format('D j M Y')
                    : null;
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
            'label' => match (true) {
                $shift['filled'] => 'Night shift filled on the roster tonight',
                $shift['partial'] => 'Night shift on the roster doesn’t cover the whole night',
                default => 'No night shift filled on the roster tonight',
            },
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
     * The "Linked to" card. Key worker, the falls-risk flag and the room are the resident's care
     * details: only for viewers who may open their record.
     *
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
        $keyWorker = null;
        if ($canSeePerson) {
            $client->loadMissing('keyWorker:id,name');
            $keyWorker = $client->keyWorker?->name;
        }

        return [
            'name' => $canSeePerson && $fullName !== null ? $fullName : 'Resident',
            'sub' => implode(' · ', array_filter([$siteName, $canSeePerson && $room !== '' ? $room : null])) ?: null,
            'flag' => $canSeePerson
                ? match ($link->purpose) {
                    'falls' => 'Falls risk',
                    'wandering' => 'Wandering risk',
                    default => 'Linked sensor',
                }
                : null,
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
     * @param  NightHistory  $history
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function rightNowMeter(Device $device, array $history, array $rule, CarbonImmutable $now): array
    {
        /** @var list<object{value: float, at: CarbonImmutable}> $list */
        $list = $history['rows']->values()->all();
        $latestRow = $list === [] ? null : $list[array_key_last($list)];
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

        // Offline now, with nothing heard since it went quiet: don't claim in or out of bed.
        $quietNow = null;
        foreach ($history['offline'] as $spell) {
            if ($spell['open'] && ($latestAt === null || $latestAt->lte($spell['from']))) {
                $quietNow = $spell;
            }
        }

        if ($inBed === null || $quietNow !== null) {
            return [
                'key' => 'right_now',
                'label' => 'Right now',
                'value' => null,
                'from' => null,
                'ago' => null,
                'caption' => $quietNow !== null
                    ? ['text' => 'Sensor offline since {time}', 'at' => $quietNow['from']->toIso8601String()]
                    : ['text' => 'No bed reading yet', 'at' => null],
                'tone' => 'brand',
                'tab' => 'nights',
                'bar' => null,
            ];
        }

        // Walk back to find when the current in-bed/out-of-bed state started, never past a time the
        // sensor was offline (the state before it went quiet may not have lasted).
        $floor = null;
        foreach ($history['offline'] as $spell) {
            if (! $spell['open'] && $latestAt !== null && $spell['to']->lte($latestAt) && ($floor === null || $spell['to']->gt($floor))) {
                $floor = $spell['to'];
            }
        }
        $since = $latestAt;
        for ($i = count($list) - 1; $i >= 0; $i--) {
            $row = $list[$i];
            if ($floor !== null && $row->at->lt($floor)) {
                break;
            }
            if (($row->value >= 0.5) !== $inBed) {
                break;
            }
            $since = $row->at;
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
     * One night window, from readings only:
     * - the state at the window start is the last reading before it (within STATE_KEPT_HOURS, and
     *   not while the sensor was offline); out of bed then opens a time out of bed at the window start;
     * - while offline, the state is unknown until the next reading, and a time out of bed that was
     *   open is cut at the moment it went quiet (never "back in bed");
     * - `whole_night` is true only when the state was known for the whole window;
     * - no known state and no readings at all: `has_data` false and `total_minutes` null.
     *
     * @param  NightHistory  $history
     * @return array{
     *     has_data: bool,
     *     whole_night: bool,
     *     start_state: bool|null,
     *     first_in_bed_at: CarbonImmutable|null,
     *     episodes: list<Episode>,
     *     gaps: list<array{from: CarbonImmutable, to: CarbonImmutable}>,
     *     total_minutes: int|null
     * }
     */
    private function summariseNight(
        array $history,
        CarbonImmutable $nightStartLocal,
        CarbonImmutable $nightEndLocal,
        CarbonImmutable $now,
    ): array {
        $rows = $history['rows'];
        $startUtc = $nightStartLocal->utc();
        $endUtc = $nightEndLocal->utc();
        $effectiveEndUtc = $now->lt($endUtc) ? $now->utc() : $endUtc;
        $empty = [
            'has_data' => false, 'whole_night' => false, 'start_state' => null, 'first_in_bed_at' => null,
            'episodes' => [], 'gaps' => [], 'total_minutes' => null,
        ];

        if ($effectiveEndUtc->lte($startUtc)) {
            return $empty;
        }

        // Offline spells, clipped to the window.
        $gaps = [];
        $offlineAtStart = false;
        foreach ($history['offline'] as $spell) {
            $gapFrom = $spell['from']->max($startUtc);
            $gapTo = $spell['to']->min($effectiveEndUtc);
            if ($gapTo->gt($gapFrom)) {
                $gaps[] = ['from' => $gapFrom, 'to' => $gapTo];
                if ($spell['from']->lte($startUtc)) {
                    $offlineAtStart = true;
                }
            }
        }

        $keptFrom = $startUtc->subHours(self::STATE_KEPT_HOURS);
        $baseline = $rows->last(fn (object $row): bool => $row->at->lte($startUtc) && $row->at->gte($keptFrom));
        $inside = $rows->filter(fn (object $row): bool => $row->at->gt($startUtc) && $row->at->lte($effectiveEndUtc))->values();

        // Went quiet after that reading (even if back before the night started): nothing heard since.
        foreach ($baseline !== null ? $history['offline'] : [] as $spell) {
            if ($spell['from']->gt($baseline->at) && $spell['from']->lte($startUtc)) {
                $offlineAtStart = true;
            }
        }

        $state = $baseline !== null && ! $offlineAtStart ? ($baseline->value >= 0.5) : null;
        if ($state === null && $inside->isEmpty()) {
            return [...$empty, 'gaps' => $gaps];
        }

        // Readings and the moments the sensor went quiet, in time order.
        $events = [];
        foreach ($inside as $row) {
            $events[] = ['at' => $row->at, 'quiet' => false, 'in' => $row->value >= 0.5, 'until' => null];
        }
        foreach ($gaps as $gap) {
            if ($gap['from']->gt($startUtc)) {
                $events[] = ['at' => $gap['from'], 'quiet' => true, 'in' => null, 'until' => $gap['to']];
            }
        }
        usort($events, fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['quiet'] ? 0 : 1] <=> [$b['at']->getTimestamp(), $b['quiet'] ? 0 : 1]);

        $startState = $state;
        $firstInBedAt = $state === true ? $startUtc : null;
        $outSince = $state === false ? $startUtc : null;
        $outFromStart = $state === false;
        $cursor = $startUtc;
        $unknownSeconds = 0;
        /** @var list<array{from: CarbonImmutable, to: CarbonImmutable, end: string, from_start: bool, match_until: CarbonImmutable}> $spells */
        $spells = [];

        foreach ($events as $event) {
            if ($state === null) {
                $unknownSeconds += max(0, $event['at']->getTimestamp() - $cursor->getTimestamp());
            }
            $cursor = $event['at'];

            if ($event['quiet']) {
                if ($outSince !== null) {
                    $spells[] = ['from' => $outSince, 'to' => $event['at'], 'end' => 'offline', 'from_start' => $outFromStart, 'match_until' => $event['until']];
                    $outSince = null;
                    $outFromStart = false;
                }
                $state = null;

                continue;
            }

            if ($event['in']) {
                $firstInBedAt ??= $event['at'];
                if ($outSince !== null) {
                    $spells[] = ['from' => $outSince, 'to' => $event['at'], 'end' => 'back', 'from_start' => $outFromStart, 'match_until' => $event['at']];
                    $outSince = null;
                    $outFromStart = false;
                }
                $state = true;
            } else {
                if ($outSince === null) {
                    $outSince = $event['at'];
                    $outFromStart = false;
                }
                $state = false;
            }
        }

        if ($state === null) {
            $unknownSeconds += max(0, $effectiveEndUtc->getTimestamp() - $cursor->getTimestamp());
        }
        if ($outSince !== null && $effectiveEndUtc->gt($outSince)) {
            $spells[] = [
                'from' => $outSince,
                'to' => $effectiveEndUtc,
                'end' => $now->lt($endUtc) ? 'now' : 'night_end',
                'from_start' => $outFromStart,
                'match_until' => $effectiveEndUtc,
            ];
        }

        // Match each time out of bed to a stored out-of-bed alert opened within it.
        $usedAlerts = [];
        $episodes = [];
        foreach ($spells as $spell) {
            $alert = $history['alerts']->first(fn (DeviceAlert $candidate): bool => ! isset($usedAlerts[(int) $candidate->id])
                && $candidate->opened_at !== null
                && $candidate->opened_at->gte($spell['from'])
                && $candidate->opened_at->lte($spell['match_until']));
            if ($alert instanceof DeviceAlert) {
                $usedAlerts[(int) $alert->id] = true;
            }
            $seconds = max(0, $spell['to']->getTimestamp() - $spell['from']->getTimestamp());
            $episodes[] = [
                'from' => $spell['from'],
                'to' => $spell['to'],
                'minutes' => max(1, (int) round($seconds / 60)),
                'back' => $spell['end'] === 'back',
                'end' => $spell['end'],
                'from_start' => $spell['from_start'],
                'alert' => $alert instanceof DeviceAlert ? $alert : null,
            ];
        }

        return [
            'has_data' => true,
            'whole_night' => $unknownSeconds === 0,
            'start_state' => $startState,
            'first_in_bed_at' => $firstInBedAt,
            'episodes' => $episodes,
            'gaps' => $gaps,
            'total_minutes' => array_sum(array_map(fn (array $ep): int => $ep['minutes'], $episodes)),
        ];
    }

    /** "Mere Tane told" from the alert's own timeline, or "alert opened" when nobody was on shift to tell. */
    private static function toldWords(DeviceAlert $alert): string
    {
        $told = $alert->relationLoaded('events')
            ? $alert->events->first(fn (object $event): bool => $event->kind === 'told')
            : null;
        $text = $told !== null ? trim((string) $told->text) : '';

        return $text !== '' ? $text : 'alert opened';
    }

    /**
     * Bed readings in the window, newest kept first when a chatty sensor sends more than the limit,
     * returned oldest first, with the last reading before the window (the state it started in).
     *
     * @return Collection<int, object{value: float, at: CarbonImmutable}>
     */
    private function occupancyRows(int $deviceId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Collection
    {
        $recent = DB::table('security_device_sensor_readings')
            ->where('device_id', $deviceId)
            ->where('metric', 'occupancy')
            ->whereBetween('observed_at', [
                $fromUtc->utc()->format('Y-m-d H:i:s'),
                $toUtc->utc()->format('Y-m-d H:i:s'),
            ])
            ->orderByDesc('observed_at')
            ->limit(self::ROW_LIMIT)
            ->get(['value', 'observed_at'])
            ->reverse()
            ->values();

        // Cut off at the limit: the reading before the window no longer tells the state before the
        // oldest row kept, so leave it out rather than claim a state for the missing stretch.
        $baseline = $recent->count() >= self::ROW_LIMIT
            ? collect()
            : DB::table('security_device_sensor_readings')
                ->where('device_id', $deviceId)
                ->where('metric', 'occupancy')
                ->where('observed_at', '<', $fromUtc->utc()->format('Y-m-d H:i:s'))
                ->orderByDesc('observed_at')
                ->limit(1)
                ->get(['value', 'observed_at']);

        return $baseline->concat($recent)->map(fn (object $row): object => (object) [
            'value' => (float) $row->value,
            'at' => CarbonImmutable::parse((string) $row->observed_at, 'UTC'),
        ])->values();
    }

    /**
     * When this sensor was offline (its own "offline" alerts) in the window; an open one runs to now.
     *
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable, open: bool}>
     */
    private function offlineSpells(int $deviceId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        return DeviceAlert::query()
            ->where('device_id', $deviceId)
            ->where('kind', 'offline')
            ->where('opened_at', '<=', $toUtc)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $fromUtc))
            ->orderBy('opened_at')
            ->limit(200)
            ->get(['id', 'opened_at', 'closed_at'])
            ->filter(fn (DeviceAlert $alert): bool => $alert->opened_at !== null)
            ->map(fn (DeviceAlert $alert): array => [
                'from' => $alert->opened_at->utc(),
                'to' => ($alert->closed_at ?? $toUtc)->utc(),
                'open' => $alert->closed_at === null,
            ])
            ->values()
            ->all();
    }

    /**
     * This sensor's out-of-bed alerts in the window, oldest first, with who was told.
     *
     * @return Collection<int, DeviceAlert>
     */
    private function outOfBedAlerts(int $deviceId, CarbonImmutable $fromUtc): Collection
    {
        return DeviceAlert::query()
            ->with([
                'acknowledgedBy:id,name',
                'events' => fn ($query) => $query->where('kind', 'told'),
            ])
            ->where('device_id', $deviceId)
            ->where('kind', 'care_out_of_bed')
            ->where('opened_at', '>=', $fromUtc)
            ->orderByDesc('opened_at')
            ->limit(200)
            ->get(['id', 'device_id', 'opened_at', 'acknowledged_by_user_id', 'acknowledged_at'])
            ->sortBy(fn (DeviceAlert $alert): int => $alert->opened_at?->getTimestamp() ?? 0)
            ->values();
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
     * Filled only when scheduled or started roster shifts at the Site together cover the whole
     * night window (what's left of it, once the night has started). Someone clocked in is noted,
     * but isn't the roster.
     *
     * @return array{filled: bool, partial: bool, note: string|null}
     */
    private function nightRosterShift(
        ?int $siteId,
        CarbonImmutable $tonightStartLocal,
        CarbonImmutable $tonightEndLocal,
        CarbonImmutable $now,
    ): array {
        if ($siteId === null || $siteId < 1) {
            return ['filled' => false, 'partial' => false, 'note' => null];
        }

        $startUtc = $tonightStartLocal->utc();
        $endUtc = $tonightEndLocal->utc();
        $coverFrom = $now->utc()->gt($startUtc) ? $now->utc() : $startUtc;
        $clock = fn (CarbonImmutable $at): string => $at->setTimezone(CareRules::ZONE)->format('g:i a');

        $shifts = Shift::query()
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $coverFrom)
            ->whereIn('status', self::ROSTERED_STATES)
            ->whereNotNull('user_id')
            ->where(fn ($query) => $query
                ->where('site_id', $siteId)
                ->orWhere(fn ($derived) => $derived->whereNull('site_id')->whereHas('client', fn ($client) => $client->where('site_id', $siteId))))
            ->orderBy('starts_at')
            ->limit(50)
            ->get(['id', 'starts_at', 'ends_at']);

        // Walk the shifts in start order; any gap before the window ends means not filled.
        $reached = $coverFrom;
        $gapUntil = $endUtc;
        $firstStart = null;
        foreach ($shifts as $shift) {
            $shiftStart = CarbonImmutable::parse($shift->starts_at)->utc();
            $shiftEnd = CarbonImmutable::parse($shift->ends_at)->utc();
            if ($shiftStart->gt($reached)) {
                $gapUntil = $shiftStart;

                break;
            }
            $firstStart ??= $shiftStart;
            if ($shiftEnd->gt($reached)) {
                $reached = $shiftEnd;
            }
            if ($reached->gte($endUtc)) {
                break;
            }
        }

        if ($firstStart !== null && $reached->gte($endUtc)) {
            return ['filled' => true, 'partial' => false, 'note' => $clock($firstStart).' – '.$clock($reached)];
        }

        $clockedIn = $now->gte($startUtc) && $now->lt($endUtc) && HrAttendanceSession::query()
            ->where('site_id', $siteId)
            ->where('clock_in_at', '<=', $now->utc())
            ->whereNull('clock_out_at')
            ->where('status', 'open')
            ->exists();
        $clockedInWords = $clockedIn ? 'Someone is clocked in on site now' : null;

        if ($shifts->isEmpty()) {
            return ['filled' => false, 'partial' => false, 'note' => $clockedInWords];
        }

        $gapWords = 'Not covered from '.$clock($reached).' to '.$clock($gapUntil);

        return ['filled' => false, 'partial' => true, 'note' => $clockedInWords !== null ? $gapWords.' · '.$clockedInWords : $gapWords];
    }

    private static function clockFromHm(string $hm): string
    {
        return CarbonImmutable::parse('2026-01-01 '.$hm, CareRules::ZONE)->format('g:i a');
    }
}
