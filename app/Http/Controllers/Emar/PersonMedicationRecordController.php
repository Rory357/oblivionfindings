<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\MedicationInteraction;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSupportChange;
use App\Models\MedicationSyringeDriver;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationConcealment;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationProfileAuditPrivacy;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationRecordSafetyPrivacy;
use App\Services\Medication\Support\MedicationSupport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        'assisted' => 'assist',
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
                    'count' => $current->count(),
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
                'view_controlled' => $actor->canDo('medications.controlled.view'),
                'view_audit' => $actor->canDo('medications.audit.view'),
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
        $order = ClientMedication::withTrashed()->where('client_id', $client->id)->findOrFail($medicationId);
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
        $orders = $this->orders($client)->keyBy('id');
        $changes = MedicationSupportChange::query()->where('client_id', $client->id)
            ->whereIn('client_medication_id', $orders->keys())
            ->with('recorder:id,name')->latest('effective_at')->latest('id')->limit(100)->get();
        $changes->each(fn ($change) => $change->setRelation('medication', $orders->get($change->client_medication_id)));
        $rows = MedicationConcealment::for($actor)->redact($changes,
            fn ($change) => (bool) $change->medication->controlled_drug,
            fn ($change) => ['concealed' => true, 'key' => 'support'.$change->id]);

        return $this->privateJson([
            'plan' => app(MedicationSupport::class)->summary($client, $actor),
            'changes' => array_map(fn ($change) => $change instanceof MedicationSupportChange ? [
                'key' => (string) $change->id, 'medicine' => $change->medication->name,
                'mode' => $change->mode, 'previous' => $change->previous_mode, 'reason' => $change->reason,
                'notes' => $change->notes, 'at' => $change->effective_at->toIso8601String(), 'by' => $change->recorder?->name,
            ] : $change, $rows['rows']),
        ]);
    }

    public function safety(Request $request, int $clientId): JsonResponse
    {
        $actor = $this->actor($request);
        $client = $this->access->client($actor, $clientId);
        $concealment = MedicationConcealment::for($actor);
        $allergies = collect(app(ClientAllergyRecordService::class)->forClient($client))->map(fn ($entry) => array_intersect_key($entry, array_flip(['allergen', 'severity', 'reaction', 'source'])))->values();
        $hideText = app(MedicationRecordSafetyPrivacy::class)->hidesUnstructuredText($actor, $client);
        $alerts = $client->medicationAlerts()->with(['createdBy:id,name', 'resolvedBy:id,name'])->orderByDesc('created_at')->get()->map(fn ($alert) => $hideText ? ['concealed' => true, 'key' => 'alert'.$alert->id] : [
            'key' => (string) $alert->id,
            'id' => $alert->id, 'type' => $alert->type, 'title' => $alert->title, 'detail' => $alert->detail,
            'enabled' => $alert->enabled, 'prompt_on_open' => $alert->prompt_on_open,
            'created_at' => $alert->created_at?->toIso8601String(), 'created_by' => $alert->createdBy?->name,
            'resolved_at' => $alert->resolved_at?->toIso8601String(), 'resolved_by' => $alert->resolvedBy?->name,
        ]);
        $orders = $this->orders($client)->filter(fn ($order) => $this->status($order) !== 'stopped');
        $names = $orders->groupBy(fn ($order) => mb_strtolower(trim($order->name)));
        $interactions = MedicationInteraction::query()->active()->with('createdBy:id,name')->get()->filter(fn ($pair) => $names->has(mb_strtolower(trim($pair->medication_a))) && $names->has(mb_strtolower(trim($pair->medication_b))));
        $pairs = $concealment->redact($interactions,
            fn ($pair) => $names[mb_strtolower(trim($pair->medication_a))]->contains('controlled_drug', true) || $names[mb_strtolower(trim($pair->medication_b))]->contains('controlled_drug', true),
            fn ($pair) => ['concealed' => true, 'key' => 'pair'.$pair->id],
        );

        return $this->privateJson([
            'allergies' => app(ClientAllergyRecordService::class)->summary($client),
            'alerts' => $alerts, 'interactions' => ['rows' => array_map(fn ($row) => $row instanceof MedicationInteraction ? [
                'key' => (string) $row->id, 'a' => $row->medication_a, 'b' => $row->medication_b,
                'severity' => $row->severity, 'description' => $row->description, 'management' => $row->management,
                'by' => $row->createdBy?->name, 'at' => $row->created_at?->toIso8601String(),
            ] : $row, $pairs['rows']), 'hidden' => $pairs['hidden']],
            'suppression' => ['suppressed' => (bool) $client->suppress_med_admin_alerts, 'reason' => $hideText ? null : $client->med_alerts_suppressed_reason],
            'can_manage' => $actor->canDo('medications.orders.manage'),
            'can_check' => $actor->canDo('medications.administer.record'),
        ]);
    }

    public function clinical(Request $request, int $clientId): JsonResponse
    {
        $actor = $this->actor($request);
        $client = $this->access->client($actor, $clientId);
        $scope = app(MedicationGovernanceScopeService::class);
        $concealment = MedicationConcealment::for($actor);
        $inr = $scope->scopeCanonicalClientMedicationRows(ClientInrRecord::query()->where('client_id', $client->id), [(int) $client->site_id])->with(['medication:id,client_id,name,controlled_drug', 'recordedBy:id,name'])->latest('tested_on')->latest('id')->get();
        $inrRows = $concealment->redact($inr, fn ($result) => (bool) $result->medication?->controlled_drug, fn ($result) => ['concealed' => true, 'key' => 'inr'.$result->id]);
        $drivers = MedicationSyringeDriver::query()->where('client_id', $client->id)->where('site_id', $client->site_id)->with(['commencedBy:id,name', 'checks.checkedBy:id,name'])->latest('commenced_at')->get()->map(function ($driver) use ($scope, $client, $concealment) {
            $contents = $scope->visibleSyringeDriverContents($client, $driver->contents ?? [], $concealment->canViewControlled());
            if ($contents === null) {
                return ['concealed' => true, 'key' => 'driver'.$driver->id];
            }

            return ['key' => (string) $driver->id, 'id' => $driver->id, 'status' => $driver->status, 'commenced_at' => $driver->commenced_at?->toIso8601String(), 'by' => $driver->commencedBy?->name, 'rate' => $driver->rate, 'rate_unit' => $driver->rate_unit, 'contents' => $contents, 'site_of_insertion' => $driver->site_of_insertion, 'notes' => $driver->notes, 'checks' => $driver->checks->sortByDesc('checked_at')->map(fn ($check) => ['id' => $check->id, 'at' => $check->checked_at?->toIso8601String(), 'by' => $check->checkedBy?->name, 'running' => $check->infusion_running, 'site_condition' => $check->site_condition, 'volume_remaining' => $check->volume_remaining, 'notes' => $check->notes])->values()];
        });
        $observations = $scope->scopeCanonicalClientMedicationRows(ClientMedicationAdministration::query()->effectiveClinicalEvidence()->where('client_id', $client->id), [(int) $client->site_id], false)->where(function ($query) {
            $query->whereNotNull('blood_glucose_level')->orWhereNotNull('pulse_bpm')->orWhereNotNull('blood_pressure_systolic')->orWhereNotNull('blood_pressure_diastolic');
        })->with(['medication:id,client_id,name,controlled_drug', 'administeredBy:id,name'])->latest('administered_at')->limit(100)->get();
        $obsRows = $concealment->redact($observations, fn ($dose) => (bool) $dose->medication?->controlled_drug, fn ($dose) => ['concealed' => true, 'key' => 'obs'.$dose->id]);
        $orders = $this->orders($client)->filter(fn ($order) => $this->status($order) !== 'stopped' && ! $concealment->hides((bool) $order->controlled_drug));

        return $this->privateJson([
            'inr' => ['rows' => array_map(fn ($row) => $row instanceof ClientInrRecord ? ['key' => (string) $row->id, 'id' => $row->id, 'medicine' => $row->medication?->name, 'medicine_id' => $row->client_medication_id, 'value' => (float) $row->inr_value, 'tested' => $row->tested_on?->toDateString(), 'target_low' => $row->target_range_low === null ? null : (float) $row->target_range_low, 'target_high' => $row->target_range_high === null ? null : (float) $row->target_range_high, 'dose' => $row->dose_mg, 'next' => $row->next_test_date?->toDateString(), 'notes' => $row->notes, 'by' => $row->recordedBy?->name, 'disabled' => $row->disabled_at !== null, 'unlinked_reason' => $row->unlinked_reason, 'instruction' => $row->instruction, 'instruction_source' => $row->instruction_source, 'disabled_reason' => $row->disabled_reason] : $row, $inrRows['rows']), 'hidden' => $inrRows['hidden']],
            'drivers' => $drivers->values(),
            'observations' => ['rows' => array_map(fn ($row) => $row instanceof ClientMedicationAdministration ? ['key' => (string) $row->id, 'medicine' => $row->medication?->name, 'at' => $row->administered_at?->toIso8601String(), 'by' => $row->administeredBy?->name, 'glucose' => $row->blood_glucose_level, 'pulse' => $row->pulse_bpm, 'systolic' => $row->blood_pressure_systolic, 'diastolic' => $row->blood_pressure_diastolic] : $row, $obsRows['rows']), 'hidden' => $obsRows['hidden']],
            'medicines' => $orders->map(fn ($order) => ['id' => $order->id, 'name' => $order->name, 'dosage' => $order->dosage ?? '', 'controlled_drug' => (bool) $order->controlled_drug, 'witness_required' => $order->requiresWitness()])->values(),
            'witnesses' => $actor->canDo('medications.orders.manage') ? $scope->controlledWitnessPicker([(int) $client->site_id], $actor->id) : [],
            'can_check' => $actor->canDo('medications.administer.record'),
            'can_manage' => $actor->canDo('medications.orders.manage'),
        ]);
    }

    public function history(Request $request, int $clientId): JsonResponse
    {
        $actor = $this->actor($request);
        $client = $this->access->client($actor, $clientId);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'view' => ['nullable', 'in:doses,corrections,changes']]);
        if (($data['view'] ?? '') === 'changes') {
            abort_unless($actor->canDo('medications.audit.view'), 403);
            $privacy = app(MedicationProfileAuditPrivacy::class);
            $query = AuditLog::query()->where('client_id', $client->id)->whereIn('auditable_type', [ClientMedication::class, ClientMedicationAdministration::class, ClientInrRecord::class, MedicationOrderVersion::class, ClientMedicalProfile::class]);
            $query->where(fn ($q) => $q->where('auditable_type', '!=', ClientMedicalProfile::class)->orWhereHasMorph('auditable', [ClientMedicalProfile::class], fn ($profile) => $profile->where('client_id', $client->id)));
            $legacy = $privacy->apply($query, $actor, $client, true)->reorder()->selectRaw("'legacy' AS source, id, created_at AS at");
            $events = MedicationEvent::query()->where('client_id', $client->id)->where('site_id', $client->site_id)->selectRaw("'event' AS source, id, occurred_at AS at");
            // Paginate both retained trails together; fetching only a page of
            // each source would silently discard older evidence.
            $page = DB::query()->fromSub($legacy->toBase()->unionAll($events->toBase()), 'changes')->orderByDesc('at')->orderByDesc('id')->orderBy('source')->paginate(25);
            $legacyLogs = AuditLog::query()->whereIn('id', $page->getCollection()->where('source', 'legacy')->pluck('id'))->with('user:id,name')->get()->keyBy('id');
            $eventLogs = MedicationEvent::query()->whereIn('id', $page->getCollection()->where('source', 'event')->pluck('id'))->get()->keyBy('id');
            $eventActors = User::query()->whereIn('id', $eventLogs->pluck('actor_id')->filter())->pluck('name', 'id');
            $page->setCollection($page->getCollection()->map(function ($ref) use ($actor, $privacy, $legacyLogs, $eventLogs, $eventActors) {
                if ($ref->source === 'event') {
                    $event = $eventLogs->get($ref->id);
                    if (! $actor->canDo('medications.controlled.view') && $event->controlled) {
                        return ['concealed' => true, 'key' => 'event'.$event->id];
                    }

                    return ['key' => 'event'.$event->id, 'action' => $event->kind, 'at' => $event->occurred_at->toIso8601String(), 'by' => $event->actor_id ? $eventActors->get($event->actor_id) : 'Automated', 'meta' => ['summary' => $event->summary, 'facts' => $event->facts, 'sequence' => $event->sequence, 'hash' => $event->hash, 'previous_hash' => $event->previous_hash]];
                }
                $log = $legacyLogs->get($ref->id);
                $record = $log->auditable;
                $controlled = $privacy->containsControlledSnapshot($log->meta) || (bool) ($record?->controlled_drug ?? false) || (bool) ($record?->medication?->controlled_drug ?? false);
                if (! $actor->canDo('medications.controlled.view') && $controlled) {
                    return ['concealed' => true, 'key' => 'audit'.$log->id];
                }
                $meta = $log->meta;
                if ($log->auditable_type === ClientMedicalProfile::class) {
                    // Medication access must not expose unrelated health notes
                    // from the older general profile audit snapshots.
                    $fields = ['allergies', 'allergy_records', 'allergies_reviewed_at', 'allergies_reviewed_by', 'allergies_review_status', 'allergies_review_method'];
                    $meta = ['fields' => array_values(array_intersect($meta['fields'] ?? [], $fields)), 'before' => array_intersect_key($meta['before'] ?? [], array_flip($fields)), 'after' => array_intersect_key($meta['after'] ?? [], array_flip($fields))];
                }

                return ['key' => 'audit'.$log->id, 'action' => $log->action, 'at' => $log->created_at?->toIso8601String(), 'by' => $log->user?->name, 'meta' => $meta];
            })->values());

            return $this->privateJson(['page' => $page]);
        }
        $scope = app(MedicationGovernanceScopeService::class);
        $query = $scope->scopeCanonicalClientMedicationRows(ClientMedicationAdministration::query()->where('client_id', $client->id), [(int) $client->site_id], false)->with(['medication:id,client_id,name,controlled_drug', 'administeredBy:id,name', 'correctionRequestedBy:id,name']);
        if (($data['view'] ?? 'doses') === 'corrections') {
            $query->where('is_correction', true);
        } else {
            $query->effectiveClinicalEvidence();
        }
        $records = $query->latest('administered_at')->latest('id')->paginate(25);
        $concealment = MedicationConcealment::for($actor);
        $records->setCollection($records->getCollection()->map(fn ($dose) => $concealment->hides((bool) $dose->medication?->controlled_drug) ? ['concealed' => true, 'key' => 'dose'.$dose->id] : ['key' => (string) $dose->id, 'id' => $dose->id, 'medicine' => $dose->medication?->name, 'status' => $dose->status, 'at' => $dose->administered_at?->toIso8601String(), 'scheduled_for' => $dose->scheduled_for?->toIso8601String(), 'by' => $dose->administeredBy?->name, 'dose' => $dose->dose_given, 'reason' => $dose->reason, 'notes' => $dose->notes, 'is_correction' => (bool) $dose->is_correction, 'correction_status' => $dose->correction_status, 'correction_reason' => $dose->correction_reason, 'requested_by' => $dose->correction_requested_by ?? $dose->administered_by]));

        return $this->privateJson(['page' => $records, 'can_correct' => $actor->canDo('medications.administer.correct'), 'actor_id' => $actor->id]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public function week(Request $request, int $clientId): JsonResponse
    {
        $this->access->client($this->actor($request), $clientId);
        $tomorrow = Carbon::now($this->schedule->workerTimezone())->addDay()->toDateString();
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$tomorrow]]);
        $to = Carbon::parse($data['date'] ?? Carbon::now($this->schedule->workerTimezone())->toDateString(), $this->schedule->workerTimezone())->startOfDay();
        $original = $request->query('date');
        $days = [];
        try {
            for ($offset = -6; $offset <= 0; $offset++) {
                $request->query->set('date', $to->copy()->addDays($offset)->toDateString());
                $days[] = app(ClientMedicationDayController::class)->show($request, $clientId)->getData(true);
            }
        } finally {
            $original === null ? $request->query->remove('date') : $request->query->set('date', $original);
        }

        return $this->privateJson(['days' => $days]);
    }

    public function dose(Request $request, int $clientId, int $administrationId): JsonResponse
    {
        $actor = $this->actor($request);
        $person = $this->access->client($actor, $clientId);
        $scope = app(MedicationGovernanceScopeService::class);
        $query = $scope->scopeCanonicalClientMedicationRows(ClientMedicationAdministration::query()->where('client_id', $person->id), [(int) $person->site_id], false);
        if (! $actor->canDo('medications.controlled.view')) {
            $scope->scopeWithoutControlledMedicationRows($query);
        }
        $dose = (clone $query)->with(['medication', 'administeredBy:id,name', 'witnessedBy:id,name'])->findOrFail($administrationId);
        $rootId = $dose->corrected_of_id ?? $dose->id;
        $chain = (clone $query)->where('client_medication_id', $dose->client_medication_id)->where(fn ($q) => $q->whereKey($rootId)->orWhere('corrected_of_id', $rootId)->orWhere('reoffer_of_id', $rootId))->with(['administeredBy:id,name', 'correctionRequestedBy:id,name', 'correctionApprovedBy:id,name'])->orderBy('id')->get();
        $map = fn ($row) => ['id' => $row->id, 'medicine' => $row->medication?->name ?? $dose->medication->name, 'status' => $row->status, 'at' => $row->administered_at?->toIso8601String(), 'scheduled_for' => $row->scheduled_for?->toIso8601String(), 'by' => $row->administeredBy?->name, 'dose' => $row->dose_given, 'reason' => $row->reason, 'notes' => $row->notes, 'witness' => $row->witnessedBy?->name, 'is_correction' => (bool) $row->is_correction, 'correction_status' => $row->correction_status, 'correction_reason' => $row->correction_reason, 'requested_by' => $row->correction_requested_by ?? $row->administered_by, 'reviewed_by' => $row->correctionApprovedBy?->name, 'reviewed_at' => $row->correction_approved_at?->toIso8601String(), 'rejection_reason' => $row->correction_rejection_reason, 'glucose' => $row->blood_glucose_level, 'pulse' => $row->pulse_bpm, 'systolic' => $row->blood_pressure_systolic, 'diastolic' => $row->blood_pressure_diastolic];

        return $this->privateJson(['dose' => $map($dose), 'chain' => $chain->map($map)->values()]);
    }

    private function privateJson(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    /** @return Collection<int, ClientMedication> the person's current (not superseded) orders */
    private function orders(Client $client): Collection
    {
        return ClientMedication::withTrashed()
            ->where('client_id', $client->id)
            ->with(['ceasedByUser:id,name', 'verifiedByUser:id,name'])
            ->get();
    }

    /** active · awaiting (waiting to be checked) · paused · stopped (ceased) */
    private function status(ClientMedication $order): string
    {
        if ($order->state === 'ceased' || $order->ceased_at !== null || $order->superseded_by !== null || $order->trashed()) {
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
        $support = [];
        $policy = app(MedicationSupport::class);
        foreach ($this->orders($client) as $order) {
            $support[(int) $order->id] = self::SUPPORT[$policy->mode($order)] ?? 'administer';
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
            $summary = app(ClientAllergyRecordService::class)->summary($client);
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'unavailable', 'count' => 0];
        }

        return ['status' => $summary['status'], 'count' => count($summary['entries']), 'reviewed' => $summary['reviewed']];
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
