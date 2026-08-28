<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\AuditLogger;
use App\Services\EnhancedMarService;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationOrderLifecycleService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\NotificationService;
use App\Services\Timeline\TimelineEmitter;
use App\Services\UserSiteAccessService;
use App\Support\EmarUrl;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ClientMedicalController extends Controller
{
    use HandlesMedicationSync;

    private function buildMedicationPayload(array $validated): array
    {
        $payload = [];

        foreach ([
            'name',
            'dosage',
            'frequency',
            'dose_times',
            'is_prn',
            'prn_reason',
            'route',
            'form',
            'pharmacy',
            'start_date',
            'end_date',
            'ceased_at',
            'ceased_reason',
            'state',
            'paused_at',
            'instructions',
            'active',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('max_per_day', $validated) || array_key_exists('max_doses_per_day', $validated)) {
            $payload['max_per_day'] = $validated['max_per_day'] ?? $validated['max_doses_per_day'];
        }

        if (array_key_exists('min_hours_between_doses', $validated)) {
            $payload['min_hours_between_doses'] = $validated['min_hours_between_doses'];
        }

        if (array_key_exists('controlled_drug', $validated) || array_key_exists('is_controlled_drug', $validated)) {
            $payload['controlled_drug'] = (bool) ($validated['controlled_drug'] ?? $validated['is_controlled_drug']);
        }

        if (array_key_exists('high_risk', $validated) || array_key_exists('is_high_risk', $validated)) {
            $payload['high_risk'] = (bool) ($validated['high_risk'] ?? $validated['is_high_risk']);
        }

        if (array_key_exists('prescriber', $validated) || array_key_exists('prescriber_name', $validated)) {
            $payload['prescriber'] = $validated['prescriber'] ?? $validated['prescriber_name'];
        }

        return $payload;
    }

    public function show(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);

        return redirect()->to(EmarUrl::medications($client));
    }

    public function updateProfile(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'medical_history' => ['nullable', 'string'],
            'disabilities' => ['nullable'],
            'disabilities.*' => ['string', 'max:255'],
            'allergies' => ['nullable'],
            'allergies.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        foreach (['disabilities', 'allergies'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $data[$field] = [];

                continue;
            }

            $data[$field] = is_array($data[$field])
                ? array_values(array_filter($data[$field], fn ($value) => filled($value)))
                : [(string) $data[$field]];
        }

        $profile = ClientMedicalProfile::firstOrNew(['client_id' => $client->id]);
        $profile->fill($data);
        $profile->client_id = $client->id;
        $profile->save();

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'medical profile', $profile, $client, [
            'title' => 'Medical profile updated',
            'url' => url("/clients/{$client->id}/medical"),
        ]);

        return back()->with('success', 'Medical profile saved successfully.');
    }

    public function storeMedication(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.orders.manage') ?? false, 403);

        try {
            return app(MedicationScopeDecisionService::class)->forClient(
                $user,
                (int) $client->id,
                now(),
                function (MedicationScopeDecision $scope) use ($request, $user) {
                    $data = $request->validate([
                        'name' => ['required', 'string', 'max:255'],
                        'dosage' => ['nullable', 'string', 'max:255'],
                        'frequency' => ['nullable', 'string', 'max:255'],
                        'dose_times' => ['nullable', 'array'],
                        'dose_times.*' => ['string', 'regex:/^\d{2}:\d{2}$/'],
                        'is_prn' => ['sometimes', 'boolean'],
                        'controlled_drug' => ['sometimes', 'boolean'],
                        'is_controlled_drug' => ['sometimes', 'boolean'],
                        'high_risk' => ['sometimes', 'boolean'],
                        'is_high_risk' => ['sometimes', 'boolean'],
                        'prn_reason' => ['nullable', 'string', 'max:255'],
                        'max_per_day' => ['nullable', 'integer', 'min:1'],
                        'max_doses_per_day' => ['nullable', 'integer', 'min:1'],
                        'min_hours_between_doses' => ['nullable', 'numeric', 'min:0'],
                        'route' => ['nullable', 'string', 'max:255'],
                        'form' => ['nullable', 'string', 'max:255'],
                        'prescriber' => ['nullable', 'string', 'max:255'],
                        'prescriber_name' => ['nullable', 'string', 'max:255'],
                        'pharmacy' => ['nullable', 'string', 'max:255'],
                        'start_date' => ['nullable', 'date'],
                        'end_date' => ['nullable', 'date', 'after_or_equal:today'],
                        'ceased_at' => ['prohibited'],
                        'ceased_reason' => ['prohibited'],
                        'state' => ['nullable', 'in:active'],
                        'paused_at' => ['prohibited'],
                        'instructions' => ['nullable', 'string'],
                        'active' => ['sometimes', 'accepted'],
                    ]);

                    // Ordinary profile creation always creates a current active order.
                    // Historical/ceased evidence can only be written by its governed lifecycle.
                    $data['state'] = 'active';
                    $data['active'] = true;
                    $data['ceased_at'] = null;
                    $data['ceased_reason'] = null;
                    $data['paused_at'] = null;

                    $client = $scope->client;
                    $medication = new ClientMedication;
                    $medication->client_id = $client->id;
                    $medication->created_by = $user->id;
                    $payload = $this->buildMedicationPayload($data);
                    if (array_key_exists('controlled_drug', $data)
                        && array_key_exists('is_controlled_drug', $data)
                        && (bool) $data['controlled_drug'] !== (bool) $data['is_controlled_drug']) {
                        throw ValidationException::withMessages([
                            'controlled_drug' => 'Choose one consistent controlled-drug classification.',
                        ]);
                    }
                    abort_if(
                        (bool) ($payload['controlled_drug'] ?? false)
                            && (! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                                || ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                        404,
                    );
                    $medication->fill($payload);
                    $medication->save();

                    app(TimelineEmitter::class)->record([
                        'source_type' => ClientMedication::class,
                        'source_id' => $medication->id,
                        'occurred_at' => now(),
                        'type' => 'medication_prescribed',
                        'actor_user_id' => $user->id,
                        'client_id' => $client->id,
                        'site_id' => $client->site_id,
                        'subject' => 'Medication added: '.$medication->name.($medication->dosage ? ' '.$medication->dosage : ''),
                        'body' => $medication->instructions,
                        'meta' => array_filter([
                            'medication_name' => $medication->name,
                            'dosage' => $medication->dosage,
                            'frequency' => $medication->frequency,
                            'route' => $medication->route,
                            'is_prn' => $medication->is_prn,
                            'controlled_drug' => $medication->controlled_drug,
                            'high_risk' => $medication->high_risk,
                        ]),
                        'visibility' => 'internal',
                        'is_pinned' => false,
                        'created_by' => $user->id,
                    ]);

                    $this->notifyMedicationEvent(
                        $user,
                        $client,
                        'medications.orders.manage',
                        (bool) $medication->controlled_drug,
                        false,
                        [
                            'action' => 'created',
                            'entity' => 'medication',
                            'entity_id' => $medication->id,
                            'event_key' => 'medication.created',
                            'title' => 'Medication added: '.$medication->name,
                            'url' => url("/clients/{$client->id}/medical"),
                        ],
                    );
                    app(MedicationScopeDecisionService::class)->recordBreakGlassUse(
                        $scope,
                        'created_medication_order',
                        'Medication '.$medication->id,
                    );

                    return back()->with('success', 'Medication added successfully.');
                },
            );
        } catch (AuthorizationException|ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to add medication. No changes were saved.');
        }
    }

    public function updateMedication(Request $request, Client $client, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.orders.manage') ?? false, 403);

        try {
            return app(MedicationScopeDecisionService::class)->forMedication(
                $user,
                $medication,
                now(),
                function (MedicationScopeDecision $scope) use ($client, $request, $user) {
                    abort_unless(
                        (int) $scope->client->id === (int) $client->id,
                        404,
                        'The requested medication action is not available.',
                    );
                    abort_if(
                        $scope->medication->controlled_drug
                            && (! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                                || ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                        404,
                    );

                    $data = $request->validate([
                        'name' => ['required', 'string', 'max:255'],
                        'dosage' => ['nullable', 'string', 'max:255'],
                        'frequency' => ['nullable', 'string', 'max:255'],
                        'dose_times' => ['nullable', 'array'],
                        'dose_times.*' => ['string', 'regex:/^\d{2}:\d{2}$/'],
                        'is_prn' => ['sometimes', 'boolean'],
                        'controlled_drug' => ['sometimes', 'boolean'],
                        'is_controlled_drug' => ['sometimes', 'boolean'],
                        'high_risk' => ['sometimes', 'boolean'],
                        'is_high_risk' => ['sometimes', 'boolean'],
                        'prn_reason' => ['nullable', 'string', 'max:255'],
                        'max_per_day' => ['nullable', 'integer', 'min:1'],
                        'max_doses_per_day' => ['nullable', 'integer', 'min:1'],
                        'min_hours_between_doses' => ['nullable', 'numeric', 'min:0'],
                        'route' => ['nullable', 'string', 'max:255'],
                        'form' => ['nullable', 'string', 'max:255'],
                        'prescriber' => ['nullable', 'string', 'max:255'],
                        'prescriber_name' => ['nullable', 'string', 'max:255'],
                        'pharmacy' => ['nullable', 'string', 'max:255'],
                        'start_date' => ['nullable', 'date'],
                        'end_date' => ['nullable', 'date'],
                        'ceased_at' => ['prohibited'],
                        'ceased_reason' => ['prohibited'],
                        'state' => ['nullable', 'in:active,paused'],
                        'paused_at' => ['nullable', 'date'],
                        'instructions' => ['nullable', 'string'],
                        'active' => ['sometimes', 'boolean'],
                    ]);

                    if (($data['active'] ?? null) === false && ($data['state'] ?? null) !== 'paused') {
                        throw ValidationException::withMessages([
                            'state' => 'Use Discontinue and record a reason to cease this medication.',
                        ]);
                    }
                    $data['active'] = ! empty($data['state']) ? $data['state'] === 'active' : true;
                    $data['state'] = $data['state'] ?? 'active';

                    $payload = $this->buildMedicationPayload($data);
                    if (array_key_exists('controlled_drug', $payload)) {
                        abort_if(
                            (bool) $payload['controlled_drug'] !== (bool) $scope->medication->controlled_drug
                                && (! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                                    || ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                            404,
                        );
                        if ((bool) $payload['controlled_drug'] !== (bool) $scope->medication->controlled_drug) {
                            throw ValidationException::withMessages([
                                'controlled_drug' => 'Controlled-drug classification cannot be changed on an existing medication order.',
                            ]);
                        }
                    }

                    $scope->medication->fill($payload);
                    $scope->medication->save();

                    $this->notifyMedicationEvent(
                        $user,
                        $scope->client,
                        'medications.orders.manage',
                        (bool) $scope->medication->controlled_drug,
                        false,
                        [
                            'action' => 'updated',
                            'entity' => 'medication',
                            'entity_id' => $scope->medication->id,
                            'event_key' => 'medication.updated',
                            'title' => 'Medication updated: '.$scope->medication->name,
                            'url' => url("/clients/{$scope->client->id}/medical"),
                        ],
                    );
                    app(MedicationScopeDecisionService::class)->recordBreakGlassUse(
                        $scope,
                        'updated_medication_order',
                        'Medication '.$scope->medication->id,
                    );

                    return back()->with('success', 'Medication updated successfully.');
                },
                submittedClientId: (int) $client->id,
            );
        } catch (AuthorizationException|ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to update medication. No changes were saved.');
        }
    }

    public function updateMedicationStock(
        Request $request,
        Client $client,
        ClientMedication $medication,
        MedicationGovernanceScopeService $governanceScope,
    ) {
        $user = $request->user();
        abort_unless(
            $user?->canDo('medications.stock.update') ?? false,
            403
        );
        $expectsJson = $request->expectsJson();
        $replayConflict = 'This client medication stock request was already used for a different actor, target, payload, or provenance.';

        try {
            [$stock, $eventClient, $eventMedication, $payload, $duplicate] = $governanceScope->forMedication(
                $user,
                (int) $medication->id,
                MedicationGovernanceScopeService::STOCK_CAPABILITY,
                function (Client $canonicalClient, ClientMedication $lockedMedication) use ($request, $user, $governanceScope, $replayConflict): array {
                    if ((bool) $lockedMedication->controlled_drug) {
                        abort_unless(
                            $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                                && $user->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
                            404,
                        );
                        throw ValidationException::withMessages([
                            'on_hand' => 'Controlled drug stock counts must be recorded through the controlled-drug balance check with a second witness.',
                        ]);
                    }

                    $data = $request->validate([
                        'on_hand' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE],
                        'unit' => ['nullable', 'string', 'max:50'],
                        'reorder_level' => ['nullable', 'integer', 'min:0'],
                        'last_counted_at' => ['nullable', 'date'],
                        'notes' => ['nullable', 'string'],
                        'reason' => ['nullable', 'string', 'max:255'],
                        ...$this->medicationOfflineSubmissionRules($request),
                    ]);
                    if (array_key_exists('on_hand', $data) && $data['on_hand'] !== null) {
                        $data['on_hand'] = MedicationStockQuantity::normalize($data['on_hand']);
                    }

                    $scope = 'client-medical-stock-update';
                    $requestFingerprint = hash('sha256', json_encode([
                        'actor_id' => (int) $user->id,
                        'client_id' => (int) $canonicalClient->id,
                        'client_medication_id' => (int) $lockedMedication->id,
                        'on_hand' => $data['on_hand'] ?? null,
                        'unit' => $data['unit'] ?? null,
                        'reorder_level' => isset($data['reorder_level']) ? (int) $data['reorder_level'] : null,
                        'last_counted_at' => $data['last_counted_at'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'reason' => $data['reason'] ?? null,
                        'captured_offline_at' => $data['captured_offline_at'] ?? null,
                        'origin_device_id' => $data['origin_device_id'] ?? null,
                        'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                    ], JSON_THROW_ON_ERROR));
                    if ($this->medicationSyncRequested($data)) {
                        $stored = $governanceScope->idempotencyResult(
                            $scope,
                            $data,
                            $requestFingerprint,
                            $replayConflict,
                            durable: true,
                        );
                        if ($stored) {
                            $stock = ClientMedicationStock::query()
                                ->where('client_medication_id', $lockedMedication->id)
                                ->first();

                            return [
                                $stock,
                                $canonicalClient,
                                $lockedMedication,
                                $this->withMedicationSync(
                                    $stored,
                                    $data,
                                    'duplicate',
                                    true,
                                    'This medication request was already processed.',
                                ),
                                true,
                            ];
                        }
                    }

                    $stock = ClientMedicationStock::query()
                        ->where('client_medication_id', $lockedMedication->id)
                        ->lockForUpdate()
                        ->first() ?? new ClientMedicationStock(['client_medication_id' => $lockedMedication->id]);
                    $beforeOnHand = $stock->exists && $stock->on_hand !== null
                        ? MedicationStockQuantity::normalize($stock->on_hand)
                        : null;
                    $stock->fill(collect($data)->except([
                        'reason',
                        'client_request_uuid',
                        'captured_offline_at',
                        'origin_device_id',
                        'queued_offline',
                    ])->all());
                    $stock->save();

                    AuditLogger::logOrFail('medications.stock.client_medical.update', $stock, array_filter([
                        'actor_id' => $user->id,
                        'client_id' => $canonicalClient->id,
                        'client_medication_id' => $lockedMedication->id,
                        'reason' => $data['reason'] ?? null,
                        'on_hand_before' => $beforeOnHand,
                        'on_hand_after' => $stock->on_hand !== null
                            ? MedicationStockQuantity::normalize($stock->on_hand)
                            : null,
                        'client_request_uuid' => $data['client_request_uuid'] ?? null,
                        'captured_offline_at' => $data['captured_offline_at'] ?? null,
                        'origin_device_id' => $data['origin_device_id'] ?? null,
                        'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                    ], fn ($value) => $value !== null && $value !== ''));

                    $payload = [
                        'success' => true,
                        'stock' => [
                            'id' => $stock->id,
                            'client_medication_id' => $lockedMedication->id,
                            'on_hand' => $stock->on_hand,
                            'unit' => $stock->unit,
                        ],
                    ];
                    if ($this->medicationSyncRequested($data)) {
                        $payload = $governanceScope->rememberIdempotencyResult(
                            $scope,
                            $data,
                            $this->withMedicationSync(
                                $payload,
                                $data,
                                $this->medicationProcessedStatus($data),
                            ),
                            $requestFingerprint,
                            $replayConflict,
                            durable: true,
                        );
                    }

                    return [$stock, $canonicalClient, $lockedMedication, $payload, false];
                },
                (int) $client->id,
            );

            if (! $duplicate) {
                $this->notifyMedicationEvent(
                    $user,
                    $eventClient,
                    'medications.stock.update',
                    (bool) $eventMedication->controlled_drug,
                    true,
                    [
                        'action' => 'updated',
                        'entity' => 'medication stock',
                        'entity_id' => $stock->id,
                        'event_key' => 'medication_stock.updated',
                        'title' => 'Medication stock updated: '.$eventMedication->name,
                        'url' => url("/clients/{$eventClient->id}/medical"),
                    ],
                );
            }

            if ($expectsJson) {
                return response()->json($payload);
            }

            return back()->with('success', $duplicate
                ? 'Medication stock update was already recorded.'
                : 'Medication stock updated successfully.');
        } catch (ValidationException $e) {
            if ($expectsJson && (($e->errors()['client_request_uuid'][0] ?? null) === $replayConflict)) {
                return response()->json(
                    $this->buildMedicationConflictPayload($request->all(), $replayConflict),
                    409,
                );
            }

            throw $e;
        } catch (AuthorizationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to update medication stock: '.$e->getMessage());
        }
    }

    public function storeAdministration(Request $request, Client $client, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless(
            $user?->canDo('medications.administer.record') ?? false,
            403
        );
        $schedule = app(MarScheduleService::class);
        $actionAt = $this->medicationConcealmentActionAt($request, $schedule, now());

        try {
            return app(MedicationScopeDecisionService::class)->forAdministration(
                $user,
                $client,
                $medication,
                $actionAt,
                null,
                null,
                null,
                function (MedicationScopeDecision $scope, ?array $data) use ($request, $user) {
                    abort_unless(is_array($data), 404);
                    $client = $scope->client;
                    $medication = $scope->medication;
                    $data['shift_id'] = $scope->shiftId();
                    $data['scope_authorized'] = true;

                    // Require a structured code whenever the outcome is not "given".
                    if (($data['status'] ?? 'given') !== 'given' && empty($data['reason_code'])) {
                        return back()->withInput()->withErrors([
                            'reason_code' => 'Please choose why the medication was not given.',
                        ]);
                    }

                    // For PRN (as-needed) medication, require an indication/reason even when "given".
                    if ($medication->is_prn && (($data['status'] ?? 'given') === 'given') && empty($data['reason'])) {
                        return back()->withInput()->with('error', 'Please provide the PRN indication (reason) for as-needed medication.');
                    }

                    // Step 8: time window logic. If a scheduled dose is administered outside the safe window,
                    // require a reason even for "given".
                    if (($data['status'] ?? 'given') === 'given' && ! empty($data['scheduled_for'])) {
                        try {
                            $scheduled = Carbon::parse($data['scheduled_for']);
                            $adminAt = ! empty($data['administered_at']) ? Carbon::parse($data['administered_at']) : now();

                            $lateAfterMinutes = 30;
                            $earlyBeforeMinutes = 60;
                            $diff = $scheduled->diffInMinutes($adminAt, false); // adminAt - scheduled

                            if (($diff < -$earlyBeforeMinutes || $diff > $lateAfterMinutes) && empty($data['reason'])) {
                                return back()->withInput()->with('error', 'Please provide a reason when administering outside the scheduled time window.');
                            }
                        } catch (\Throwable $e) {
                            // ignore parse errors
                        }
                    }

                    $medication->loadMissing('stock');

                    if (($data['queued_offline'] ?? false) && ! $medication->is_prn && ! empty($data['scheduled_for'])) {
                        $isDurableReplay = filled($data['client_request_uuid'] ?? null)
                            && ClientMedicationAdministration::withTrashed()
                                ->where('client_id', $client->id)
                                ->where('client_medication_id', $medication->id)
                                ->where('client_request_uuid', $data['client_request_uuid'])
                                ->exists();

                        if (! $isDurableReplay) {
                            $scheduledFor = Carbon::parse($data['scheduled_for']);
                            $conflictingAdministration = ClientMedicationAdministration::query()
                                ->effectiveClinicalEvidence()
                                ->where('client_id', $client->id)
                                ->where('client_medication_id', $medication->id)
                                ->whereBetween('scheduled_for', [
                                    $scheduledFor->copy()->subMinute(),
                                    $scheduledFor->copy()->addMinute(),
                                ])
                                ->latest('id')
                                ->first();

                            if ($conflictingAdministration) {
                                $payload = $this->buildMedicationConflictPayload(
                                    $data,
                                    'Medication state changed before this offline administration could sync. Supervisor review is required.',
                                );

                                if ($request->expectsJson()) {
                                    return response()->json($payload, 409);
                                }

                                return back()->withInput()->with('error', $payload['error']);
                            }
                        }
                    }

                    $result = app(EnhancedMarService::class)->recordAdministration(
                        $client,
                        $medication,
                        $data,
                        $user->id,
                        $data['shift_id'] ?? null,
                        $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                        prelockedPresenceShifts: $scope->lockedPresenceShifts,
                        prelockedPresenceEffectiveAt: $scope->lockedPresenceEffectiveAt,
                    );

                    if (! ($result['success'] ?? false)) {
                        // PRN over-limit incidents are raised inside EnhancedMarService
                        // (shared across all recording surfaces), so no handling here.
                        if ($request->expectsJson()) {
                            return response()->json(
                                $this->withMedicationSync(
                                    $result,
                                    $data,
                                    'rejected',
                                    false,
                                    $result['error'] ?? null,
                                ),
                                422,
                            );
                        }

                        $error = $result['error'] ?? 'Failed to record administration.';
                        $errorField = $result['error_field'] ?? null;

                        if (is_string($errorField) && $errorField !== '') {
                            return back()->withInput()->withErrors([$errorField => $error]);
                        }

                        return back()->withInput()->with('error', $error);
                    }

                    /** @var ClientMedicationAdministration $a */
                    $a = $result['administration'];

                    if ($result['duplicate'] ?? false) {
                        $payload = $this->withMedicationSync([
                            'success' => true,
                            'administration' => [
                                'id' => $a->id,
                                'status' => $a->status,
                                'administered_at' => $a->administered_at?->toIso8601String(),
                            ],
                            'safety_check' => $result['safety_check'] ?? null,
                        ], $data, 'duplicate', true, 'This medication request was already processed.');

                        $this->rememberMedicationSyncResponse('administration', $data, $payload);

                        if ($request->expectsJson()) {
                            return response()->json($payload);
                        }

                        return back()->with('success', 'Already saved — no changes needed.');
                    }

                    $statusLabel = ucfirst(str_replace('_', ' ', $data['status']));
                    app(TimelineEmitter::class)->record([
                        'source_type' => ClientMedicationAdministration::class,
                        'source_id' => $a->id,
                        'occurred_at' => $a->administered_at ?? now(),
                        'type' => 'medication_'.$data['status'],
                        'actor_user_id' => $user->id,
                        'client_id' => $client->id,
                        'shift_id' => $data['shift_id'] ?? null,
                        'site_id' => $client->site_id,
                        'subject' => $statusLabel.': '.$medication->name.($medication->dosage ? ' '.$medication->dosage : ''),
                        'body' => $data['notes'] ?? null,
                        'meta' => array_filter([
                            'medication_name' => $medication->name,
                            'dosage' => $medication->dosage,
                            'dose_given' => $data['dose_given'] ?? null,
                            'status' => $data['status'],
                            'reason' => $data['reason'] ?? null,
                            'reason_code' => $data['reason_code'] ?? null,
                            'witnessed_by' => $data['witnessed_by'] ?? null,
                            'witness_method' => $a->witness_method,
                            'pulse_bpm' => $data['pulse_bpm'] ?? null,
                            'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                            'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                            'client_request_uuid' => $data['client_request_uuid'] ?? null,
                            'captured_offline_at' => $data['captured_offline_at'] ?? null,
                            'origin_device_id' => $data['origin_device_id'] ?? null,
                            'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                        ]),
                        'visibility' => 'internal',
                        'is_pinned' => false,
                        'created_by' => $user->id,
                    ]);

                    // Missed/refused/late incident creation now lives in
                    // EnhancedMarService::recordAdministration so every recording
                    // surface raises the same incidents.
                    app(MedicationAlertService::class)->generateClientAlerts($client);

                    $this->notifyMedicationEvent(
                        $user,
                        $client,
                        'medications.administer.record',
                        (bool) $medication->controlled_drug,
                        true,
                        [
                            'action' => 'created',
                            'entity' => 'medication administration',
                            'entity_id' => $a->id,
                            'event_key' => 'medication_administration.created',
                            'title' => 'Medication administration recorded: '.$medication->name,
                            'url' => url("/clients/{$client->id}/medical"),
                        ],
                    );
                    app(MedicationScopeDecisionService::class)->recordBreakGlassUse(
                        $scope,
                        'recorded_dose',
                        'Administration '.$a->id,
                    );

                    $payload = $this->withMedicationSync([
                        'success' => true,
                        'administration' => [
                            'id' => $a->id,
                            'status' => $a->status,
                            'administered_at' => $a->administered_at?->toIso8601String(),
                        ],
                        'safety_check' => $result['safety_check'] ?? null,
                    ], $data, $this->medicationProcessedStatus($data));

                    $this->rememberMedicationSyncResponse('administration', $data, $payload);

                    if ($request->expectsJson()) {
                        return response()->json($payload);
                    }

                    return back()->with('success', 'Medication administration recorded.');
                },
                scopedInputResolver: function () use ($actionAt, $request, $schedule): array {
                    $data = $request->validate([
                        'status' => ['required', 'in:given,refused,missed,withheld'],
                        'reason' => ['nullable', 'string', 'max:255'],
                        'reason_code' => ['nullable', 'string', 'max:60'],
                        'dose_given' => ['nullable', 'string', 'max:255'],
                        'quantity_administered' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', 'max:10000'],
                        'scheduled_for' => ['nullable', 'date'],
                        'administered_at' => ['nullable', 'date'],
                        'shift_id' => ['nullable', 'integer'],
                        'witnessed_by' => ['nullable', 'integer'],
                        'witness_credential' => ['nullable', 'string', 'max:255'],
                        'notes' => ['nullable', 'string'],
                        'blood_glucose_level' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
                        'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
                        'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
                        'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
                        ...$this->medicationOfflineSubmissionRules($request),
                    ]);

                    $submittedAdministrationAt = $this->medicationSubmittedAdministrationAt($data);

                    return [
                        'payload' => $data,
                        'scheduled_for' => filled($data['scheduled_for'] ?? null)
                            ? $schedule->parseWorkerDateTime((string) $data['scheduled_for'])
                            : null,
                        'action_at' => $submittedAdministrationAt !== null
                            ? $schedule->parseWorkerDateTime($submittedAdministrationAt)
                            : $actionAt,
                        'shift_id' => isset($data['shift_id']) ? (int) $data['shift_id'] : null,
                    ];
                },
            );
        } catch (\Throwable $e) {
            if ($e instanceof ValidationException
                || $e instanceof AuthorizationException
                || $e instanceof HttpExceptionInterface) {
                throw $e;
            }

            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to record administration. No changes were saved.',
                ], 500);
            }

            return back()->withInput()->with('error', 'Failed to record administration. No changes were saved.');
        }
    }

    public function closeControlledDiscrepancy(Request $request, Client $client, ClientControlledDrugDiscrepancy $discrepancy)
    {
        $user = $request->user();
        abort_unless($user, 403);

        [$discrepancy, $alreadyClosed, $canonicalClient] = app(MedicationGovernanceScopeService::class)->forDiscrepancy(
            $user,
            $discrepancy,
            function (Client $canonicalClient, ?ClientMedication $medication, ClientControlledDrugDiscrepancy $lockedDiscrepancy) use ($request, $client, $user): array {
                abort_unless((int) $canonicalClient->id === (int) $client->id, 404);
                $data = $request->validate([
                    'resolution_notes' => ['nullable', 'string'],
                ]);
                if ($lockedDiscrepancy->status === 'closed') {
                    return [$lockedDiscrepancy, true, $canonicalClient];
                }

                $lockedDiscrepancy->update([
                    'status' => 'closed',
                    'resolved_at' => now(),
                    'resolved_by' => $user->id,
                    'resolution_notes' => $data['resolution_notes'] ?? null,
                ]);

                AuditLogger::logOrFail('medications.controlled.discrepancy.resolve', $lockedDiscrepancy, array_filter([
                    'actor_id' => $user->id,
                    'client_id' => $canonicalClient->id,
                    'client_medication_id' => $medication?->id,
                    'source' => 'client_medical',
                ], fn ($value) => $value !== null && $value !== ''));

                return [$lockedDiscrepancy, false, $canonicalClient];
            },
        );

        if ($alreadyClosed) {
            return back()->with('success', 'Discrepancy already closed.');
        }

        app(MedicationIncidentIntegrationService::class)->resolveControlledDiscrepancy(
            $discrepancy,
            'Controlled drug discrepancy closed from client medical record.',
            $user->id
        );

        $this->notifyMedicationEvent(
            $user,
            $canonicalClient,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            true,
            true,
            [
                'action' => 'updated',
                'entity' => 'controlled drug discrepancy',
                'entity_id' => $discrepancy->id,
                'event_key' => 'controlled_drug_discrepancy.updated',
                'title' => 'Controlled drug discrepancy closed',
                'url' => url("/clients/{$canonicalClient->id}/medical"),
            ],
        );

        return back()->with('success', 'Discrepancy closed.');
    }

    public function discontinueMedication(Request $request, Client $client, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless(
            ($user?->canDo('medications.view') ?? false)
            && ($user?->canDo('medications.orders.manage') ?? false),
            403,
        );

        try {
            $discontinued = app(MedicationOrderLifecycleService::class)->discontinue(
                $user,
                $medication,
                $request->input('reason'),
                (int) $client->id,
                requestKey: $request->input('request_key'),
            );

            if ($discontinued->wasChanged('state')) {
                try {
                    $eventClient = Client::query()->whereKey($discontinued->client_id)->firstOrFail();
                    $this->notifyMedicationEvent(
                        $user,
                        $eventClient,
                        'medications.orders.manage',
                        (bool) $discontinued->controlled_drug,
                        false,
                        [
                            'action' => 'updated',
                            'entity' => 'medication',
                            'entity_id' => $discontinued->id,
                            'event_key' => 'medication.discontinued',
                            'title' => 'Medication discontinued: '.($discontinued->name ?? 'Medication'),
                            'url' => url("/clients/{$eventClient->id}/medical"),
                        ],
                    );
                } catch (\Throwable $notificationFailure) {
                    report($notificationFailure);
                }
            }

            return back()->with('success', 'Medication discontinued successfully.');
        } catch (AuthorizationException|ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to discontinue medication. No changes were saved.');
        }
    }

    public function storeCondition(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'severity' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $c = new ClientCondition;
            $c->client_id = $client->id;
            $c->fill($data);
            $c->save();

            app(TimelineEmitter::class)->record([
                'source_type' => ClientCondition::class,
                'source_id' => $c->id,
                'occurred_at' => now(),
                'type' => 'condition_added',
                'actor_user_id' => $request->user()?->id,
                'client_id' => $client->id,
                'site_id' => $client->site_id,
                'subject' => 'Condition added: '.$c->label,
                'body' => $c->notes,
                'meta' => array_filter(['severity' => $c->severity]),
                'visibility' => 'internal',
                'is_pinned' => false,
                'created_by' => $request->user()?->id,
            ]);

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'condition', $c, $client, [
                'title' => 'Condition added: '.$c->label,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Condition added successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to add condition: '.$e->getMessage());
        }
    }

    public function updateCondition(Request $request, Client $client, ClientCondition $condition)
    {
        $this->authorize('update', $client);
        abort_unless($condition->client_id === $client->id, 404);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'severity' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $condition->fill($data);
            $condition->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'condition', $condition, $client, [
                'title' => 'Condition updated: '.$condition->label,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Condition updated successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to update condition: '.$e->getMessage());
        }
    }

    public function destroyCondition(Request $request, Client $client, ClientCondition $condition)
    {
        $this->authorize('update', $client);
        abort_unless($condition->client_id === $client->id, 404);

        try {
            $condition->delete();
            app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'condition', $condition, $client, [
                'title' => 'Condition removed: '.($condition->label ?? 'Condition'),
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Condition removed successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to remove condition: '.$e->getMessage());
        }
    }

    public function storeEmergencyContact(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'alternate_phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'preferred_method' => ['nullable', 'string', 'in:phone,text,email'],
            'availability' => ['nullable', 'string', 'max:255'],
            'is_primary_contact' => ['nullable', 'boolean'],
            'can_view_medical' => ['nullable', 'boolean'],
            'can_view_medications' => ['nullable', 'boolean'],
            'can_view_incidents' => ['nullable', 'boolean'],
            'can_receive_updates' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $e = new ClientEmergencyContact;
            $e->client_id = $client->id;
            $e->fill($data);
            $e->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'emergency contact', $e, $client, [
                'title' => 'Emergency contact added: '.$e->name,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Emergency contact added successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to add emergency contact: '.$e->getMessage());
        }
    }

    public function updateEmergencyContact(Request $request, Client $client, ClientEmergencyContact $contact)
    {
        $this->authorize('update', $client);
        abort_unless($contact->client_id === $client->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'alternate_phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'preferred_method' => ['nullable', 'string', 'in:phone,text,email'],
            'availability' => ['nullable', 'string', 'max:255'],
            'is_primary_contact' => ['nullable', 'boolean'],
            'can_view_medical' => ['nullable', 'boolean'],
            'can_view_medications' => ['nullable', 'boolean'],
            'can_view_incidents' => ['nullable', 'boolean'],
            'can_receive_updates' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $contact->fill($data);
            $contact->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'emergency contact', $contact, $client, [
                'title' => 'Emergency contact updated: '.$contact->name,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Emergency contact updated successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to update emergency contact: '.$e->getMessage());
        }
    }

    public function destroyEmergencyContact(Request $request, Client $client, ClientEmergencyContact $contact)
    {
        $this->authorize('update', $client);
        abort_unless($contact->client_id === $client->id, 404);

        try {
            $contact->delete();
            app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'emergency contact', $contact, $client, [
                'title' => 'Emergency contact removed: '.($contact->name ?? 'Contact'),
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Emergency contact removed successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to remove emergency contact: '.$e->getMessage());
        }
    }

    /** @param array<string, mixed> $payload */
    private function notifyMedicationEvent(
        User $actor,
        Client $client,
        string $eventCapability,
        bool $controlled,
        bool $controlledRecordRequired,
        array $payload,
    ): void {
        $siteId = is_numeric($client->site_id) ? (int) $client->site_id : 0;
        if ($siteId <= 0) {
            return;
        }

        $siteAccess = app(UserSiteAccessService::class);
        $notification = new AppEventNotification(array_merge($payload, [
            'kind' => 'crud',
            'client_id' => (int) $client->id,
            'actor' => ['id' => $actor->id, 'name' => $actor->name],
        ]));

        User::query()
            ->where('id', '!=', $actor->id)
            ->whereNotNull('approved_at')
            ->get()
            ->filter(function (User $recipient) use (
                $controlled,
                $controlledRecordRequired,
                $eventCapability,
                $siteAccess,
                $siteId,
            ): bool {
                if (! $recipient->canDo($eventCapability)) {
                    return false;
                }
                if ($controlled
                    && ! $recipient->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
                    return false;
                }
                if ($controlled
                    && $controlledRecordRequired
                    && ! $recipient->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)) {
                    return false;
                }

                return in_array(
                    $siteId,
                    $siteAccess->accessibleSiteIds(
                        $recipient,
                        MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
                    ),
                    true,
                );
            })
            ->each(fn (User $recipient) => $recipient->notify($notification));
    }
}
