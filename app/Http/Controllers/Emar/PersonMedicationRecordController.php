<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSyringeDriver;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationConcealment;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The person medication record (eMAR P02, approved v1 28a5a2ddf), shaped like
 * the Fleet vehicle profile: the page carries the summary (who, the header
 * meters), each section loads its own records from the JSON views here.
 *
 * Every entry passes the one per-person gate (MedicationRecordAccess). A
 * reader without controlled-medicine access sees controlled medicines as
 * redacted, counted rows inside the record (MedicationConcealment).
 *
 * Served at /emar/mar?client_id=… while config('medications.person_record')
 * is "p02"; until the Chart lands (P02-4) today's MAR page stays the default.
 */
class PersonMedicationRecordController extends Controller
{
    /** Today's three stored support values, in P00's four words (D6 is P03's). */
    private const SUPPORT = [
        'staff_given' => 'administer',
        'prompted' => 'prompt',
        'self_managed' => 'independent',
    ];

    public function __construct(
        private readonly MedicationRecordAccess $access,
        private readonly MarScheduleService $schedule,
    ) {}

    public function show(Request $request, int $clientId): Response|SymfonyResponse
    {
        $actor = $this->actor($request);
        try {
            $client = $this->access->client($actor, $clientId)->load(['site:id,name', 'serviceContext:id,name']);
        } catch (HttpException $e) {
            // The record's own boundary pages, with the gate's status: no
            // access says nothing about the person; not found looks the same
            // whether the person is missing or at another house.
            return Inertia::render('emar/record/show', [
                'unavailable' => $e->getStatusCode() === 403 ? 'no_access' : 'not_found',
            ])->toResponse($request)->setStatusCode($e->getStatusCode());
        }
        $concealment = MedicationConcealment::for($actor);
        $orders = $this->orders($client);
        $current = $orders->filter(fn (ClientMedication $order) => $this->status($order) !== 'stopped');
        $hidden = $current->filter(fn (ClientMedication $order) => $concealment->hides((bool) $order->controlled_drug));
        $visible = $current->reject(fn (ClientMedication $order) => $concealment->hides((bool) $order->controlled_drug));

        return Inertia::render('emar/record/show', [
            'person' => $this->person($client),
            'meters' => [
                'medicines' => [
                    'count' => $visible->count(),
                    'hidden' => $hidden->count(),
                    'as_needed' => $visible->where('is_prn', true)->count(),
                    'to_check' => $visible->filter(fn (ClientMedication $order) => $this->status($order) === 'awaiting')->count(),
                ],
                'allergies' => $this->allergySummary($client),
                'inr' => $this->latestInr($client, $concealment),
                'driver' => $this->runningDriver($client, $concealment),
            ],
            'can' => [
                // Orders are added, changed and stopped in Orders & reviews (P04), never here.
                'manage_orders' => $actor->canDo('medications.orders.manage'),
            ],
            'as_at' => Carbon::now($this->schedule->workerTimezone())->toIso8601String(),
        ]);
    }

    /** Current or stopped medicines (?status=stopped). */
    public function medicines(Request $request, int $clientId): JsonResponse
    {
        $actor = $this->actor($request);
        $client = $this->access->client($actor, $clientId);
        $stopped = $request->query('status') === 'stopped';
        $support = $this->supportByMedicine($client);
        $orders = $this->orders($client)
            ->filter(fn (ClientMedication $order) => ($this->status($order) === 'stopped') === $stopped)
            ->sortBy(fn (ClientMedication $order) => mb_strtolower($order->name))
            ->values();

        $rows = MedicationConcealment::for($actor)->redact(
            $orders,
            fn (ClientMedication $order) => (bool) $order->controlled_drug,
            fn (ClientMedication $order) => ['concealed' => true, 'key' => 'c'.$order->id],
        );

        return $this->privateJson([
            'rows' => array_map(
                fn ($row) => $row instanceof ClientMedication ? $this->medicineRow($row, $support) : $row,
                $rows['rows'],
            ),
            'hidden' => $rows['hidden'],
        ]);
    }

    /** One medicine's details: the order, how it's given, recent doses. */
    public function medicine(Request $request, int $clientId, int $medicationId): JsonResponse
    {
        $actor = $this->actor($request);
        $client = $this->access->client($actor, $clientId);
        $order = ClientMedication::query()->current()->where('client_id', $client->id)->findOrFail($medicationId);
        // Inside the record a controlled medicine is listed, but its details need access.
        abort_if(MedicationConcealment::for($actor)->hides((bool) $order->controlled_drug), 404);
        $order->load(['verifiedByUser:id,name', 'ceasedByUser:id,name']);
        $timezone = $this->schedule->workerTimezone();

        $doses = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('client_id', $client->id)
            ->where('client_medication_id', $order->id)
            ->where('administered_at', '>=', Carbon::now($timezone)->subDays(7)->startOfDay()->utc())
            ->with('administeredBy:id,name')
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (ClientMedicationAdministration $dose) => [
                'id' => $dose->id,
                'status' => $dose->status,
                'at' => $dose->administered_at?->copy()->timezone($timezone)->toIso8601String(),
                'by' => $dose->administeredBy?->name,
            ])
            ->values();

        return $this->privateJson([
            'medicine' => $this->medicineRow($order, $this->supportByMedicine($client)) + [
                'instructions' => $order->instructions,
                'indication' => $order->indication,
                'prescriber' => $order->prescriber,
                'review' => $order->review_date?->toDateString(),
            ],
            'recent_doses' => $doses,
        ]);
    }

    /** The self-administration assessment and each current medicine's support. */
    public function support(Request $request, int $clientId): JsonResponse
    {
        $actor = $this->actor($request);
        $client = $this->access->client($actor, $clientId);
        $assessment = $this->assessment($client);
        $support = $this->supportByMedicine($client, $assessment);
        $orders = $this->orders($client)
            ->filter(fn (ClientMedication $order) => $this->status($order) !== 'stopped')
            ->sortBy(fn (ClientMedication $order) => mb_strtolower($order->name))
            ->values();
        $rows = MedicationConcealment::for($actor)->redact(
            $orders,
            fn (ClientMedication $order) => (bool) $order->controlled_drug,
            fn (ClientMedication $order) => ['concealed' => true, 'key' => 'c'.$order->id],
        );

        return $this->privateJson([
            'assessment' => $assessment ? [
                'outcome' => $assessment->outcome_label ?? $assessment->outcome,
                'assessed' => $assessment->assessment_date?->toDateString(),
                'by' => $assessment->assessor?->name,
                'reassess' => $assessment->reassessment_date?->toDateString(),
                'agreement' => $assessment->agreement_signed_at
                    ? ['signed' => $assessment->agreement_signed_at->toDateString(), 'by' => $assessment->agreementSigner?->name]
                    : null,
                'storage' => $assessment->safe_storage_notes ?: $assessment->storage_location,
            ] : null,
            'rows' => array_map(
                fn ($row) => $row instanceof ClientMedication ? $this->medicineRow($row, $support) : $row,
                $rows['rows'],
            ),
            'hidden' => $rows['hidden'],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function privateJson(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    /** @return Collection<int, ClientMedication> the person's current (not superseded) orders */
    private function orders(Client $client): Collection
    {
        return ClientMedication::query()
            ->current()
            ->where('client_id', $client->id)
            ->with(['ceasedByUser:id,name', 'verifiedByUser:id,name'])
            ->get();
    }

    /** active · awaiting (waiting to be checked) · paused · stopped (ceased) */
    private function status(ClientMedication $order): string
    {
        if ($order->state === 'ceased' || $order->ceased_at !== null) {
            return 'stopped';
        }
        if ($order->state === 'paused' || ! $order->active) {
            return 'paused';
        }

        return $order->isVerifiedForAdministration() ? 'active' : 'awaiting';
    }

    /**
     * @param  array<int, string>  $support  support word by order id
     * @return array<string, mixed>
     */
    private function medicineRow(ClientMedication $order, array $support): array
    {
        $timezone = $this->schedule->workerTimezone();

        return [
            'key' => (string) $order->id,
            'id' => $order->id,
            'name' => $order->name,
            'strength' => $order->dosage,
            'amount' => $this->amount($order),
            'route' => $order->route,
            'form' => $order->form,
            'kind' => $order->is_prn ? 'prn' : 'scheduled',
            'when' => $order->is_prn ? 'As needed' : $this->when($order),
            'support' => $support[(int) $order->id] ?? 'administer',
            'status' => $this->status($order),
            'controlled' => (bool) $order->controlled_drug,
            'witness' => $order->requiresWitness(),
            'high_risk' => (bool) $order->high_risk,
            'started' => $order->start_date?->toDateString(),
            'verified' => $order->verified_at ? [
                'at' => $order->verified_at->copy()->timezone($timezone)->toIso8601String(),
                'by' => $order->verifiedByUser?->name,
            ] : null,
            'stopped' => $order->ceased_at ? [
                'at' => $order->ceased_at->copy()->timezone($timezone)->toIso8601String(),
                'by' => $order->ceasedByUser?->name,
                'reason' => $order->ceased_reason,
            ] : null,
        ];
    }

    /** "1 tablet" — the order's decimal amount without trailing zeros, else its dosage text. */
    private function amount(ClientMedication $order): ?string
    {
        $amount = $order->dose_amount;
        if ($amount === null || $amount === '') {
            return $order->dosage;
        }
        $number = rtrim(rtrim(number_format((float) $amount, 4, '.', ''), '0'), '.');

        return trim($number.' '.($order->dose_unit ?? ''));
    }

    private function when(ClientMedication $order): string
    {
        $times = collect($order->dose_times ?? [])
            ->filter(fn ($time) => is_string($time) && preg_match('/^\d{1,2}:\d{2}/', $time))
            ->sort()
            ->map(fn (string $time) => Carbon::createFromFormat('H:i', substr($time, 0, 5))->format('g:i a'))
            ->values();

        return $times->isNotEmpty() ? $times->implode(' · ') : (string) ($order->frequency ?? '—');
    }

    private function assessment(Client $client): ?MedicationSelfAdminAssessment
    {
        $assessments = MedicationSelfAdminAssessment::query()
            ->where('client_id', $client->id)
            ->with(['assessor:id,name', 'agreementSigner:id,name'])
            ->latest('assessment_date')
            ->latest('id')
            ->get();
        // A reassessment supersedes the one before it.
        $superseded = $assessments->pluck('supersedes_id')->filter()->all();

        return $assessments->first(fn (MedicationSelfAdminAssessment $a) => ! in_array($a->id, $superseded, true));
    }

    /** @return array<int, string> support word by order id, from the assessment */
    private function supportByMedicine(Client $client, ?MedicationSelfAdminAssessment $assessment = null): array
    {
        $assessment ??= $this->assessment($client);
        $support = [];
        foreach ($assessment?->med_scope ?? [] as $item) {
            if (is_array($item) && is_numeric($item['med_id'] ?? null)) {
                $support[(int) $item['med_id']] = self::SUPPORT[$item['scope'] ?? ''] ?? 'administer';
            }
        }

        return $support;
    }

    /** @return array<string, mixed> */
    private function person(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => trim($client->first_name.' '.$client->last_name),
            'preferred' => $client->preferred_name ?: $client->first_name,
            'initials' => mb_strtoupper(mb_substr((string) $client->first_name, 0, 1).mb_substr((string) $client->last_name, 0, 1)),
            'age' => $client->date_of_birth?->age,
            'nhi' => $client->nhi_number,
            'status' => $client->status,
            'house' => $client->site?->name,
            'service' => $client->serviceContext?->name,
        ];
    }

    /** @return array{status: string, count: int} */
    private function allergySummary(Client $client): array
    {
        try {
            $count = count(app(ClientAllergyRecordService::class)->forClient($client));
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'unavailable', 'count' => 0];
        }

        return ['status' => $count > 0 ? 'recorded' : 'none', 'count' => $count];
    }

    /** @return array<string, mixed>|null the latest INR result in use */
    private function latestInr(Client $client, MedicationConcealment $concealment): ?array
    {
        $inr = ClientInrRecord::query()
            ->where('client_id', $client->id)
            ->whereNull('disabled_at')
            ->where(function ($query) use ($client, $concealment) {
                $query->whereNull('client_medication_id')->orWhereHas('medication', function ($orders) use ($client, $concealment) {
                    $orders->where('client_id', $client->id);
                    if (! $concealment->canViewControlled()) {
                        $orders->where('controlled_drug', false);
                    }
                });
            })
            ->latest('tested_on')
            ->latest('id')
            ->first();

        return $inr ? [
            'value' => (float) $inr->inr_value,
            'tested' => $inr->tested_on?->toDateString(),
            'target' => $inr->target_range_low !== null && $inr->target_range_high !== null
                ? [(float) $inr->target_range_low, (float) $inr->target_range_high]
                : null,
            'next' => $inr->next_test_date?->toDateString(),
        ] : null;
    }

    /** @return array<string, mixed>|null a running syringe driver; hidden whole when it holds a controlled medicine the reader can't see */
    private function runningDriver(Client $client, MedicationConcealment $concealment): ?array
    {
        $driver = MedicationSyringeDriver::query()
            ->where('client_id', $client->id)
            ->where('site_id', $client->site_id)
            ->where('status', 'running')
            ->whereNull('completed_at')
            ->latest('commenced_at')
            ->first();
        if (! $driver) {
            return null;
        }
        if (app(MedicationGovernanceScopeService::class)->visibleSyringeDriverContents($client, $driver->contents ?? [], $concealment->canViewControlled()) === null) {
            return ['concealed' => true];
        }
        $lastCheck = $driver->checks()->latest('checked_at')->value('checked_at');

        return [
            'concealed' => false,
            'last_check' => $lastCheck ? Carbon::parse($lastCheck)->timezone($this->schedule->workerTimezone())->toIso8601String() : null,
        ];
    }
}
