<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\Client;
use App\Models\ClientMedication;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * The audit's omissions (P01 foundation C6g): every dose in the period whose
 * window has ended with nothing recorded — late today, not recorded on an
 * earlier day — from the dose-slot projection, the same doses Meds today,
 * My Day, the badge and the overdue alerts call overdue. Over the whole
 * period asked for (no 31-day clamp, no cap), for orders since ceased too;
 * never a dose waiting for the order check, self-managed, away, recorded
 * (missed included — that is a record) or owed before its order existed.
 *
 * A reader without controlled-medicine access gets no omission naming a
 * controlled medicine (EM-12). A period starting before the projection's
 * days says so: "Not available before …".
 *
 * Replaces MarOmissionService, which rebuilt each active order's schedule
 * as it is now over at most the last 31 days.
 */
final class DoseOmissions
{
    /**
     * With no period asked for, the last week, as before: on a site where
     * doses go unrecorded, 90 days of omissions would crowd the audit feed's
     * newest-800 cap (demo data: 146 in 7 days, 1,940 in 90).
     */
    public const DEFAULT_LOOKBACK_DAYS = 7;

    public function __construct(private readonly DoseSlotProjection $projection) {}

    /**
     * @param  string|null  $from  NZ day (Y-m-d); null: the default lookback
     * @param  string|null  $to  NZ day (Y-m-d); null: today
     * @return array{events: list<array<string, mixed>>, notice: string|null}
     */
    public function forPeriod(DoseSlotReaderScope $scope, ?string $from, ?string $to, CarbonImmutable $now): array
    {
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $today = $now->setTimezone($timezone)->toDateString();
        $from = $this->day($from) ?? $now->setTimezone($timezone)->subDays(self::DEFAULT_LOOKBACK_DAYS)->toDateString();
        $to = min($this->day($to) ?? $today, $today);
        if ($scope->clientIds === [] || $from > $to) {
            return ['events' => [], 'notice' => null];
        }

        $rows = $this->projection->rows($scope, $from, $to, $now)
            ->filter(fn (array $row): bool => ! $row['concealed']
                && in_array($row['state'], [DoseSlotProjection::STATE_LATE, DoseSlotProjection::STATE_NOT_RECORDED], true))
            ->values();

        $orders = ClientMedication::query()
            ->withTrashed()
            ->whereKey($rows->pluck('client_medication_id')->unique()->values()->all())
            ->get(['id', 'name', 'dosage'])
            ->keyBy('id');
        $people = Client::query()
            ->whereKey($rows->pluck('client_id')->unique()->values()->all())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Client $client): array => [$client->id => trim($client->first_name.' '.$client->last_name)]);

        $events = $rows->map(function (array $row) use ($orders, $people, $timezone): array {
            $order = $orders->get($row['client_medication_id']);
            $due = Carbon::parse($row['due_at'])->timezone($timezone);
            $name = $order?->name ?? 'Medication';
            $person = $people->get($row['client_id'], 'Unknown');

            return [
                'id' => 'omission_'.$row['client_medication_id'].'_'.$due->copy()->utc()->format('YmdHi'),
                'event_type' => 'omission',
                'timestamp' => $due->copy()->utc()->toIso8601String(),
                'description' => "{$name} dose due {$due->format('H:i')} not recorded for {$person}",
                'performed_by' => null,
                'client_id' => $row['client_id'],
                'client_name' => $person,
                'details' => [
                    'medication' => $name,
                    'dose' => $order?->dosage,
                    'scheduled_for' => $due->toIso8601String(),
                ],
            ];
        })->values()->all();

        return [
            'events' => $events,
            'notice' => $this->projection->coverage($from, $now)['notice'],
        ];
    }

    private function day(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
