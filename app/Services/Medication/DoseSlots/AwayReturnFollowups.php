<?php

namespace App\Services\Medication\DoseSlots;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Models\Client;
use App\Models\ClientLeaveRequest;
use App\Models\MedicationFollowup;
use App\Models\RespiteStay;
use App\Services\Medication\Followups\MedicationFollowupService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * eMAR audit Lane A8 (EA-021, EA-040; Stephan's D6 as decided by Main,
 * 14 Oct): a forgotten return has an owner. Leave or respite with no
 * recorded return, once the cap has passed after its planned end, gives the
 * house lead ONE "Record the return" follow-up for that period. An open
 * hospital stay, once the cap has passed after admission, asks the lead to
 * "Record the discharge, or confirm they're still in hospital"; confirming
 * asks again after another cap. Recording the return or discharge (or
 * withdrawing the record) closes it. Run by the overdue job every 15 minutes.
 */
final class AwayReturnFollowups
{
    public const TYPE = 'away_return';

    public const STILL_IN_HOSPITAL = 'still_in_hospital';

    public const RETURN_LABEL = 'Record the return';

    public const HOSPITAL_LABEL = 'Record the discharge, or confirm they’re still in hospital';

    public function __construct(private readonly MedicationFollowupService $followups) {}

    /** @return array{raised: int, closed: int} */
    public function sweep(CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $cap = DoseAwaySources::noReturnCapHours();
        $raised = 0;
        if (DoseAwaySources::returnFollowupOn()) {
            foreach ($this->openPeriods() as $period) {
                $due = $period['base']->addHours($cap);
                if ($due->greaterThan($now)) {
                    continue;
                }
                $raised += DB::transaction(function () use ($period, $cap, $due): int {
                    $client = Client::query()->whereKey($period['client_id'])->lockForUpdate()->first();
                    if (! $client instanceof Client || ! $this->stillOpen($period['source'], $period['id'])) {
                        return 0;
                    }
                    $hospital = $period['source'] === DoseAwaySources::HOSPITAL;
                    $row = $this->followups->ensure(
                        $period['key'], self::TYPE, $client, null, null, null, $due,
                        [
                            'away_source' => $period['source'],
                            'away_id' => $period['id'],
                            'since' => $period['from']->toIso8601String(),
                            'cap_from' => $period['base']->toIso8601String(),
                            'cap_hours' => $cap,
                            'label' => $hospital ? self::HOSPITAL_LABEL : self::RETURN_LABEL,
                            'source_url' => $period['source'] === DoseAwaySources::RESPITE
                                ? '/respite?tab=stays'
                                : '/clients/'.$client->id,
                        ],
                    );

                    return $row->wasRecentlyCreated ? 1 : 0;
                }, 3);
            }
        }

        $closed = 0;
        MedicationFollowup::query()->where('type', self::TYPE)->whereNull('completed_at')
            ->orderBy('id')->get(['id', 'source_key', 'context'])
            ->each(function (MedicationFollowup $row) use (&$closed): void {
                $source = (string) ($row->context['away_source'] ?? '');
                $id = (int) ($row->context['away_id'] ?? 0);
                if ($this->stillOpen($source, $id)) {
                    return;
                }
                DB::transaction(fn () => $this->followups->completeFromSource(
                    $row->source_key, null, $this->returned($source, $id) ? 'returned' : 'no_longer_away',
                ), 3);
                $closed++;
            });

        return ['raised' => $raised, 'closed' => $closed];
    }

    public static function sourceKey(string $source, int $id, int $cycle = 1): string
    {
        return 'away-return:'.$source.':'.$id.($cycle > 1 ? ':'.$cycle : '');
    }

    /** The admission is still open (no valid discharge), so it can be confirmed as continuing. */
    public function hospitalStillOpen(int $admissionId): bool
    {
        return $this->stillOpen(DoseAwaySources::HOSPITAL, $admissionId);
    }

    /**
     * Periods with no recorded return, each with the instant the cap counts
     * from (its planned end, or for hospital the admission or the lead's last
     * "still in hospital") and the follow-up's source key.
     *
     * @return list<array{source: string, id: int, client_id: int, from: CarbonImmutable, base: CarbonImmutable, key: string}>
     */
    private function openPeriods(): array
    {
        $periods = [];
        $this->hospital()->orderBy('clinical_events.id')
            ->get(['clinical_events.id', 'clinical_events.client_id', 'clinical_events.hospital_admitted_at'])
            ->each(function (ClinicalEvent $event) use (&$periods): void {
                $from = CarbonImmutable::parse((string) $event->getRawOriginal('hospital_admitted_at'), 'UTC');
                $cycle = $this->hospitalCycle((int) $event->id, $from);
                if ($cycle !== null) {
                    $periods[] = ['source' => DoseAwaySources::HOSPITAL, 'id' => (int) $event->id, 'client_id' => (int) $event->client_id,
                        'from' => $from, 'base' => $cycle['base'], 'key' => self::sourceKey(DoseAwaySources::HOSPITAL, (int) $event->id, $cycle['n'])];
                }
            });
        $this->respite()->orderBy('respite_stays.id')
            ->get(['respite_stays.id', 'respite_stays.client_id', 'respite_stays.actual_start', 'rb.end_at as planned_end'])
            ->each(function (RespiteStay $stay) use (&$periods): void {
                $from = CarbonImmutable::parse((string) $stay->getRawOriginal('actual_start'), 'UTC');
                $planned = $stay->getRawOriginal('planned_end') === null ? $from : CarbonImmutable::parse((string) $stay->getRawOriginal('planned_end'), 'UTC');
                $periods[] = ['source' => DoseAwaySources::RESPITE, 'id' => (int) $stay->id, 'client_id' => (int) $stay->client_id,
                    'from' => $from, 'base' => $planned->greaterThan($from) ? $planned : $from,
                    'key' => self::sourceKey(DoseAwaySources::RESPITE, (int) $stay->id)];
            });
        if (DoseAwaySources::leaveCounts()) {
            $this->leave()->orderBy('id')->get(['id', 'client_id', 'departed_at', 'ends_on'])
                ->each(function (ClientLeaveRequest $leave) use (&$periods): void {
                    $from = CarbonImmutable::parse((string) $leave->getRawOriginal('departed_at'), 'UTC');
                    $endsOn = $leave->getRawOriginal('ends_on');
                    $planned = $endsOn === null ? $from : DoseAwaySources::nzEndOfDay(substr((string) $endsOn, 0, 10));
                    $periods[] = ['source' => DoseAwaySources::LEAVE, 'id' => (int) $leave->id, 'client_id' => (int) $leave->client_id,
                        'from' => $from, 'base' => $planned->greaterThan($from) ? $planned : $from,
                        'key' => self::sourceKey(DoseAwaySources::LEAVE, (int) $leave->id)];
                });
        }

        return $periods;
    }

    /**
     * Which "record the discharge" follow-up an open admission is on: the
     * first counts from admission; after the lead confirms "still in
     * hospital", the next counts from that confirmation. Null while one is open.
     *
     * @return array{n: int, base: CarbonImmutable}|null
     */
    private function hospitalCycle(int $admissionId, CarbonImmutable $admittedAt): ?array
    {
        $rows = MedicationFollowup::query()->where('type', self::TYPE)
            ->where(fn ($keys) => $keys->where('source_key', self::sourceKey(DoseAwaySources::HOSPITAL, $admissionId))
                ->orWhere('source_key', 'like', self::sourceKey(DoseAwaySources::HOSPITAL, $admissionId).':%'))
            ->orderByDesc('id')->get(['id', 'completed_at', 'context']);
        if ($rows->contains(fn (MedicationFollowup $row): bool => $row->completed_at === null)) {
            return null;
        }
        $last = $rows->first();
        $confirmed = $last !== null && ($last->context['outcome'] ?? null) === self::STILL_IN_HOSPITAL && filled($last->context['confirmed_at'] ?? null)
            ? CarbonImmutable::parse((string) $last->context['confirmed_at'])->utc()
            : null;

        return ['n' => $rows->count() + 1, 'base' => $confirmed ?? $admittedAt];
    }

    /** The period still has no recorded return (and still counts as Away evidence). */
    private function stillOpen(string $source, int $id): bool
    {
        return match ($source) {
            DoseAwaySources::HOSPITAL => $this->hospital()->where('clinical_events.id', $id)->exists(),
            DoseAwaySources::RESPITE => $this->respite()->where('respite_stays.id', $id)->exists(),
            DoseAwaySources::LEAVE => DoseAwaySources::leaveCounts() && $this->leave()->whereKey($id)->exists(),
            default => false,
        };
    }

    /** A return or discharge was recorded (rather than the record being withdrawn). */
    private function returned(string $source, int $id): bool
    {
        return match ($source) {
            DoseAwaySources::HOSPITAL => ClinicalEvent::query()->where('hospital_admission_id', $id)
                ->where('event_type', 'hospital_discharge')->whereNotNull('hospital_discharged_at')->exists(),
            DoseAwaySources::RESPITE => RespiteStay::query()->whereKey($id)->where('status', 'discharged')->exists(),
            DoseAwaySources::LEAVE => ClientLeaveRequest::query()->whereKey($id)->whereNotNull('returned_at')->exists(),
            default => false,
        };
    }

    /** Canonical admissions with no valid linked discharge (as DoseAwaySources reads them). */
    private function hospital(): Builder
    {
        return ClinicalEvent::query()
            ->join('clients as hc', 'hc.id', '=', 'clinical_events.client_id')
            ->whereNull('hc.deleted_at')
            ->where('clinical_events.event_type', 'hospital_admission')
            ->whereNotNull('clinical_events.hospital_admitted_at')
            ->whereColumn('clinical_events.hospital_admitted_at', 'clinical_events.occurred_at')
            ->whereNotExists(fn ($discharge) => $discharge->selectRaw('1')->from('clinical_events as d')
                ->whereColumn('d.hospital_admission_id', 'clinical_events.id')
                ->whereColumn('d.client_id', 'clinical_events.client_id')
                ->whereNull('d.deleted_at')
                ->where('d.event_type', 'hospital_discharge')
                ->whereNotNull('d.hospital_discharged_at')
                ->whereColumn('d.hospital_discharged_at', 'd.occurred_at')
                ->whereColumn('d.hospital_discharged_at', '>=', 'clinical_events.hospital_admitted_at'));
    }

    /** Checked-in stays at another house, not discharged. */
    private function respite(): Builder
    {
        return RespiteStay::query()
            ->join('respite_bookings as rb', 'rb.id', '=', 'respite_stays.booking_id')
            ->join('clients as rc', 'rc.id', '=', 'respite_stays.client_id')
            ->whereNull('rb.deleted_at')
            ->whereNull('rc.deleted_at')
            ->whereIn('respite_stays.status', array_values(array_diff(DoseAwaySources::STAY_STATUSES, ['discharged'])))
            ->whereNotNull('respite_stays.actual_start')
            ->whereNotNull('rb.location_id')
            ->whereNotNull('rc.site_id')
            ->whereColumn('rb.location_id', '<>', 'rc.site_id');
    }

    /** Approved leave after its actual departure, with no actual return. */
    private function leave(): Builder
    {
        return ClientLeaveRequest::query()
            ->whereIn('status', DoseAwaySources::LEAVE_STATUSES)
            ->whereNotNull('approved_at')->whereNotNull('approved_by')->whereNull('withdrawn_at')
            ->whereNotNull('departed_at')->whereNull('returned_at')
            ->whereHas('client');
    }
}
