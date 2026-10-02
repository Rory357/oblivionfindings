<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A person's medication events on their profile calendar (P01 C6i): the
 * profile's first month and the calendar's own fetches share this.
 *
 * Recorded doses come from their records (given, refused, withheld, Missed
 * (recorded)) over the whole range. The other scheduled doses come from the
 * dose-slot projection through ScheduledDoseStates — the same orders, due
 * times and states as Meds today — for today and three NZ days either side,
 * never before the day the projection's coverage starts: Waiting for the
 * order check, Due, Due now, Overdue (window ended, today) or Not recorded
 * (an earlier day). Nothing is owed before an order was entered.
 *
 * Controlled medicines are left out for a reader without controlled-medicine
 * access (EM-12).
 */
final class ClientCalendarDoses
{
    /** Unrecorded doses are shown for today and this many NZ days either side. */
    public const DAYS_EITHER_SIDE = 3;

    /** @var array<string, string> */
    private const LABELS = [
        'given' => 'Given',
        'refused' => 'Refused',
        'withheld' => 'Withheld',
        'missed' => 'Missed (recorded)',
        'pending_check' => 'Waiting for the order check',
        'self_managed' => 'Self-managed',
        'upcoming' => 'Due',
        'due' => 'Due now',
        'overdue' => 'Overdue',
        'not_recorded' => 'Not recorded',
    ];

    /** @var array<string, string> FullCalendar event colours (the calendar's existing palette). */
    private const COLOURS = [
        'given' => '#10b981',
        'refused' => '#f97316',
        'withheld' => '#eab308',
        'missed' => '#ef4444',
        'pending_check' => '#64748b',
        'self_managed' => '#64748b',
        'upcoming' => '#ec4899',
        'due' => '#ec4899',
        'overdue' => '#ef4444',
        'not_recorded' => '#ef4444',
    ];

    public function __construct(
        private readonly ScheduledDoseStates $states,
        private readonly DoseSlotCoverage $coverage,
        private readonly MarScheduleService $schedule,
        private readonly MedicationGovernanceScopeService $governance,
    ) {}

    /**
     * The medication events from $start up to $end.
     *
     * @return list<array<string, mixed>>
     */
    public function events(Client $client, Carbon $start, Carbon $end, bool $includeControlled, ?Carbon $now = null): array
    {
        $timezone = $this->schedule->workerTimezone();
        $now = ($now ?? Carbon::now())->copy()->utc();
        $startUtc = $start->copy()->utc();
        $endUtc = $end->copy()->utc();

        $records = $this->records($client, $startUtc, $endUtc, $includeControlled);
        $recordedSlots = $records
            ->mapWithKeys(fn (ClientMedicationAdministration $record): array => [
                $this->schedule->slotKey((int) $record->client_id, (int) $record->client_medication_id, $this->rawUtc($record, 'scheduled_for')) => true,
            ]);

        $events = $records
            ->map(fn (ClientMedicationAdministration $record): array => $this->recordEvent($record, $timezone))
            ->values()
            ->all();

        foreach ($this->unrecordedDoses($client, $startUtc, $endUtc, $includeControlled, $now) as [$order, $dose]) {
            if (! $recordedSlots->has($this->schedule->slotKey((int) $order->client_id, (int) $order->id, $dose['due_at']))) {
                $events[] = $this->doseEvent($order, $dose, $timezone);
            }
        }

        return $events;
    }

    /**
     * A scheduled dose's calendar status: the shared list status
     * (ScheduledDoseStates::listStatus), with an earlier day's unrecorded
     * dose as not_recorded rather than overdue.
     *
     * @param  array{state: string, outcome: string|null, due_soon: bool}  $dose
     */
    public static function statusFor(array $dose): string
    {
        if ($dose['outcome'] === null && $dose['state'] === DoseSlotProjection::STATE_SELF_MANAGED) {
            return 'self_managed';
        }
        $status = ScheduledDoseStates::listStatus($dose);

        return $status === 'overdue' && $dose['state'] === DoseSlotProjection::STATE_NOT_RECORDED
            ? 'not_recorded'
            : $status;
    }

    /**
     * Records with an outcome, of scheduled doses in the range.
     *
     * @return Collection<int, ClientMedicationAdministration>
     */
    private function records(Client $client, Carbon $startUtc, Carbon $endUtc, bool $includeControlled): Collection
    {
        $records = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('client_id', $client->id)
            ->whereBetween('scheduled_for', [$startUtc, $endUtc])
            ->whereIn('status', ScheduledDoseStates::RECORDED_STATUSES)
            ->with('medication:id,client_id,name,dosage,route,form,controlled_drug');
        $this->governance->scopeCanonicalClientMedicationRows(
            $records,
            is_numeric($client->site_id) ? [(int) $client->site_id] : [],
            allowNullMedication: false,
        );
        if (! $includeControlled) {
            $this->governance->scopeWithoutControlledMedicationRows($records);
        }

        return $records->orderBy('id')->get();
    }

    /**
     * The scheduled doses in the range on the days the calendar shows them
     * unrecorded, with their orders.
     *
     * @return list<array{0: ClientMedication, 1: array<string, mixed>}>
     */
    private function unrecordedDoses(Client $client, Carbon $startUtc, Carbon $endUtc, bool $includeControlled, Carbon $now): array
    {
        $timezone = $this->schedule->workerTimezone();
        $today = CarbonImmutable::instance($now)->setTimezone($timezone)->startOfDay();
        $from = max(
            $startUtc->copy()->timezone($timezone)->toDateString(),
            $today->subDays(self::DAYS_EITHER_SIDE)->toDateString(),
            $this->coverage->availableFrom(CarbonImmutable::instance($now)),
        );
        $to = min(
            $endUtc->copy()->subSecond()->timezone($timezone)->toDateString(),
            $today->addDays(self::DAYS_EITHER_SIDE)->toDateString(),
        );
        if ($from > $to) {
            return [];
        }

        $orders = ScheduledDoseStates::listedOrders([(int) $client->id])
            ->when(! $includeControlled, fn ($query) => $query->where('controlled_drug', false))
            ->get()
            ->keyBy('id');
        $doses = $this->states->dosesBetween(
            $orders,
            Carbon::parse($from, $timezone),
            Carbon::parse($to, $timezone),
            $now,
        );

        $listed = [];
        foreach ($doses as $orderId => $orderDoses) {
            foreach ($orderDoses as $dose) {
                $dueUtc = $dose['due_at']->copy()->utc();
                if ($dueUtc->greaterThanOrEqualTo($startUtc) && $dueUtc->lessThan($endUtc)) {
                    $listed[] = [$orders->get($orderId), $dose];
                }
            }
        }

        return $listed;
    }

    /** @return array<string, mixed> */
    private function recordEvent(ClientMedicationAdministration $record, string $timezone): array
    {
        $name = $record->medication?->name ?? 'Medication';
        $status = (string) $record->status;

        return [
            'id' => 'med-'.$record->id,
            'title' => $name.' — '.self::label($status),
            'start' => $this->rawUtc($record, 'scheduled_for')->timezone($timezone)->toIso8601String(),
            'end' => null,
            'allDay' => false,
            'backgroundColor' => self::colour($status),
            'borderColor' => 'transparent',
            'extendedProps' => [
                'type' => 'medication',
                'status' => $status,
                'medication_name' => $name,
                'dosage' => $record->medication?->dosage,
                'route' => $record->medication?->route,
                'notes' => $record->notes,
                'administered_at' => $record->getRawOriginal('administered_at') !== null
                    ? $this->rawUtc($record, 'administered_at')->timezone($timezone)->toIso8601String()
                    : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dose
     * @return array<string, mixed>
     */
    private function doseEvent(ClientMedication $order, array $dose, string $timezone): array
    {
        $status = self::statusFor($dose);

        return [
            'id' => 'medsched-'.$order->id.'-'.$dose['due_at']->copy()->utc()->format('YmdHi'),
            'title' => $order->name.' — '.self::label($status),
            'start' => $dose['due_at']->copy()->timezone($timezone)->toIso8601String(),
            'end' => null,
            'allDay' => false,
            'backgroundColor' => self::colour($status),
            'borderColor' => 'transparent',
            'extendedProps' => [
                'type' => 'medication',
                'status' => $status,
                'medication_name' => $order->name,
                'dosage' => $order->dosage,
                'route' => $order->route,
            ],
        ];
    }

    private static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    private static function colour(string $status): string
    {
        return self::COLOURS[$status] ?? self::COLOURS['upcoming'];
    }

    /** A UTC column read back from its raw value (the accessor can shift it). */
    private function rawUtc(ClientMedicationAdministration $record, string $column): Carbon
    {
        return Carbon::parse((string) $record->getRawOriginal($column), 'UTC');
    }
}
