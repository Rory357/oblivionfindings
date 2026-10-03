<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationAlert;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\MarScheduleService;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\DoseSlots\DoseSlotCoverage;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationScopeDecisionService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One person's medication day for the client profile's MAR tab (eMAR P02-1b):
 * the scheduled medicines × the day's dose times, each dose with the state
 * and words Meds today uses (the same orders, rows and dose-slot projection
 * through MedsBoardPayloadService), the as-needed medicines, and what the
 * recording seam needs to open today's recorder for an exact dose.
 *
 * Behind the per-person gate (MedicationRecordAccess: 403 / 404). Controlled
 * medicines are left out for a reader without controlled-medicine access and
 * counted. Days before the projection's coverage get its notice, not a grid
 * read from today's orders; the day after today shows upcoming doses only.
 */
class ClientMedicationDayController extends Controller
{
    public function __construct(
        private readonly MedicationRecordAccess $access,
        private readonly MedsBoardPayloadService $board,
        private readonly MarScheduleService $schedule,
    ) {}

    public function show(Request $request, int $client): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $person = $this->access->client($actor, $client);

        $timezone = $this->schedule->workerTimezone();
        $now = Carbon::now($timezone);
        $today = $now->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$tomorrow],
        ]);
        $date = $data['date'] ?? $today;
        $day = Carbon::parse($date, $timezone)->startOfDay();

        $coverage = app(DoseSlotCoverage::class)->forPeriod($date, CarbonImmutable::now());
        $includeControlled = $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $clientIds = [(int) $person->id];

        $canRecordAny = $actor->canDo('medications.administer.record');
        $hasAuthority = $canRecordAny && in_array(
            (int) $person->id,
            app(MedicationScopeDecisionService::class)->clientIdsWithCurrentAuthority($actor, $clientIds, $now->copy()),
            true,
        );

        $medicines = [];
        $times = [];
        $hidden = ['total' => 0, 'overdue' => 0];
        if ($coverage['complete']) {
            $bySlot = $this->board->slotIndex($this->board->administrationsForDay($clientIds, $day, $includeControlled));
            $rows = $this->board->scheduleForDate($clientIds, $day, $now, $bySlot, $includeControlled, $hidden);
            foreach ($rows as $row) {
                $id = (int) $row['medication_id'];
                $medicines[$id] ??= [
                    'id' => $id,
                    'name' => $row['medication_name'],
                    'dose' => $row['dose'],
                    'route' => $row['route'],
                    'is_controlled' => $row['is_controlled'],
                    'requires_witness' => $row['requires_witness'],
                    'cells' => [],
                ];
                $medicines[$id]['cells'][$row['time']][] = $row;
                $times[$row['time']] = true;
            }
        }
        ksort($times);
        $medicines = collect($medicines)->sortBy(fn (array $m) => mb_strtolower($m['name']))->values()->all();

        return response()->json([
            'date' => $date,
            'today' => $today,
            'tomorrow' => $tomorrow,
            'now' => $now->toIso8601String(),
            'timezone' => $timezone,
            'coverage' => $coverage,
            'times' => array_keys($times),
            'medicines' => $medicines,
            'hidden_controlled' => $hidden,
            'prn' => $this->asNeeded($clientIds, $day, $now, $date === $today, $includeControlled),
            'allergies' => $this->allergies($person),
            'chart_alerts' => ClientMedicationAlert::query()
                ->where('client_id', $person->id)
                ->where('enabled', true)
                ->whereNull('resolved_at')
                ->orderBy('created_at')
                ->get(['id', 'type', 'title'])
                ->map(fn (ClientMedicationAlert $alert) => [
                    'id' => $alert->id,
                    'type' => $alert->type,
                    'title' => $alert->title,
                ])
                ->values(),
            'can' => [
                // Recording follows the recorder's own rules; the page only
                // decides which cells to offer and says why when it can't.
                'record' => $hasAuthority,
                'record_reason' => match (true) {
                    ! $canRecordAny => 'no_permission',
                    ! $hasAuthority => 'no_shift',
                    default => null,
                },
                'record_controlled' => $actor->canDo('medications.controlled.record'),
                'report' => $actor->canDo('medications.reports.export') || $actor->canDo('reports.viewAny'),
            ],
            // What today's recorder (the seam) needs; only for someone who can record here.
            'recorder' => $hasAuthority ? [
                'client' => $this->board->clientsPayload($clientIds)[0] ?? null,
                'witnesses' => $this->board->witnesses($actor, $clientIds),
                'not_given_reasons' => $this->board->notGivenReasons(),
                'signed_as' => $this->board->boardUser($actor),
            ] : null,
        ]);
    }

    /**
     * The as-needed medicines: today, their limit state (last 24 hours, as
     * Meds today shows it); any other day, how many were given that day.
     *
     * @param  array<int, int>  $clientIds
     * @return array{rows: array<int, array<string, mixed>>, hidden: int}
     */
    private function asNeeded(array $clientIds, Carbon $day, Carbon $now, bool $isToday, bool $includeControlled): array
    {
        $all = collect($this->board->prnMedications($clientIds, $now, true));
        $hidden = $includeControlled ? 0 : $all->where('is_controlled', true)->count();
        $rows = $includeControlled ? $all : $all->where('is_controlled', false);

        $from = $day->copy()->utc();
        $to = $day->copy()->endOfDay()->utc();
        $given = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->whereIn('client_medication_id', $rows->pluck('id')->all())
            ->where('status', 'given')
            ->whereBetween('administered_at', [$from, $to])
            ->get(['client_medication_id', 'administered_at'])
            ->groupBy('client_medication_id');

        $timezone = $this->schedule->workerTimezone();

        return [
            'rows' => $rows->map(function (array $row) use ($given, $isToday, $timezone) {
                $onDay = $given->get($row['id'], collect());
                $last = $onDay->max('administered_at');

                return $row + [
                    'given_on_day' => $onDay->count(),
                    'last_given_on_day' => $last ? Carbon::parse($last)->timezone($timezone)->format('H:i') : null,
                    'is_today' => $isToday,
                ];
            })->values()->all(),
            'hidden' => $hidden,
        ];
    }

    /**
     * Both recorded allergy lists (EM-07). Empty is "nothing recorded" —
     * never "no known allergies"; a failed read says so.
     *
     * @return array{status: string, entries: array<int, array{allergen: string, severity: ?string, reaction: ?string, source: string}>}
     */
    private function allergies(Client $person): array
    {
        try {
            $entries = collect(app(ClientAllergyRecordService::class)->forClient($person))
                ->map(fn (array $entry) => [
                    'allergen' => $entry['allergen'],
                    'severity' => $entry['severity'],
                    'reaction' => $entry['reaction'],
                    'source' => $entry['source'],
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'unavailable', 'entries' => []];
        }

        return ['status' => $entries === [] ? 'none' : 'recorded', 'entries' => $entries];
    }
}
