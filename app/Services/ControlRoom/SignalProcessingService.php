<?php

namespace App\Services\ControlRoom;

use App\Enums\AlertSeverity;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\MaintenanceWindow;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\FleetOuting;
use App\Models\FleetResidentTransport;
use App\Models\FleetSignal;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\ShiftSignal;
use App\Services\AuditLogger;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\ShiftSignalService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SignalProcessingService
{
    private const TRANSACTION_ATTEMPTS = 3;

    private const INCIDENT_CORRELATION_CAPABILITY = 'incident_correlation';

    private const TRUSTED_INCIDENT_SOURCE_SLUGS = ['medication'];

    public function __construct(
        protected ControlRoomNotificationService $notifications,
        protected ?ShiftSignalService $shiftSignals = null,
        protected ?IncidentJourneyService $journeys = null,
    ) {
        $this->shiftSignals ??= app(ShiftSignalService::class);
        $this->journeys ??= app(IncidentJourneyService::class);
    }

    /**
     * Ingest a raw signal from an integration.
     *
     * Validates required fields and normalises severity before creating.
     * Idempotent — returns existing signal if idempotency key matches.
     */
    public function ingest(array $data): Signal
    {
        // Validate: signal_type_code is required for meaningful classification
        if (empty($data['signal_type_code'])) {
            Log::warning('Signal ingested without signal_type_code', [
                'signal_source_id' => $data['signal_source_id'] ?? null,
                'severity_hint' => $data['severity_hint'] ?? null,
            ]);
            $data['signal_type_code'] = 'unknown';
        }

        // Normalise severity_hint to canonical values
        if (isset($data['severity_hint'])) {
            $data['severity_hint'] = AlertSeverity::normalise($data['severity_hint']);
        }

        // Generate idempotency key if not provided
        if (empty($data['idempotency_key'])) {
            $data['idempotency_key'] = Signal::generateIdempotencyKey($data);
        }

        // Check for duplicate (idempotent)
        $existing = Signal::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            Log::debug('Signal deduplicated', ['idempotency_key' => $data['idempotency_key']]);

            return $existing;
        }

        // Resolve signal type
        if (! empty($data['signal_type_code']) && $data['signal_type_code'] !== 'unknown') {
            $signalType = SignalType::findByCode($data['signal_type_code']);
            if ($signalType) {
                $data['signal_type_id'] = $signalType->id;
                $data['severity_hint'] = $data['severity_hint'] ?? $signalType->default_severity;
            }
        }

        // Record signal source activity
        if (! empty($data['signal_source_id'])) {
            $source = SignalSource::find($data['signal_source_id']);
            $source?->recordSignal();
        }

        // Ensure occurred_at is never null
        $data['occurred_at'] = $data['occurred_at'] ?? now();

        // Create the signal (race-condition safe: unique constraint on idempotency_key)
        try {
            $signal = Signal::create(array_merge($data, [
                'status' => 'pending',
            ]));
        } catch (QueryException $e) {
            // Handle race condition: another process created the signal between check and create
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                $signal = Signal::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
                Log::debug('Signal deduplicated (race condition)', ['idempotency_key' => $data['idempotency_key']]);

                return $signal;
            }

            throw $e;
        }

        Log::info('Signal ingested', [
            'signal_id' => $signal->id,
            'type' => $signal->signal_type_code,
            'source_id' => $signal->signal_source_id,
        ]);

        return $signal;
    }

    /**
     * Process a pending signal and potentially create an alert.
     */
    public function process(Signal $signal): ?ControlRoomAlert
    {
        if ($signal->status !== 'pending') {
            return $signal->alert;
        }

        return DB::transaction(function () use ($signal) {
            // Check if in maintenance window
            if ($this->isInMaintenanceWindow($signal)) {
                $signal->markSuppressed('In maintenance window');

                return null;
            }

            $incident = $this->trustedIncidentForSignal($signal);
            if ($incident !== null) {
                $existingAlert = $this->exactAlertForIncident($incident);
                if ($existingAlert !== null) {
                    $signal->markCorrelated($existingAlert);
                    $this->addSignalToAlert($signal, $existingAlert);

                    $existingAlert = $this->journeys
                        ->attachAlertToIncident($incident, $existingAlert->fresh())
                        ->alert;

                    if ($existingAlert === null) {
                        throw new \RuntimeException('The canonical incident journey lost its operational alert.');
                    }

                    return $existingAlert;
                }
            }

            // Find matching rules
            $rules = SignalRule::findMatchingRules($signal);

            if ($rules->isEmpty()) {
                // No rules match - create alert with defaults
                return $this->createAlertForSignal($signal, null, $incident);
            }

            // Use the highest priority (lowest number) rule
            $rule = $rules->first();

            // Check for deduplication
            if ($rule->deduplicate && $incident === null) {
                $existingAlert = $this->findCorrelatedAlert($signal, $rule);
                if ($existingAlert) {
                    $signal->markCorrelated($existingAlert);
                    $this->addSignalToAlert($signal, $existingAlert);

                    return $existingAlert;
                }
            }

            // Create new alert
            return $this->createAlertForSignal($signal, $rule, $incident);
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function createAlertForSignal(
        Signal $signal,
        ?SignalRule $rule,
        ?ClientIncident $incident,
    ): ControlRoomAlert {
        $alert = $this->createAlertFromSignal($signal, $rule);

        if ($incident !== null) {
            $alert = $this->journeys
                ->attachAlertToIncident($incident, $alert)
                ->alert;

            if ($alert === null) {
                throw new \RuntimeException('The canonical incident journey did not return its operational alert.');
            }
        }

        return $alert;
    }

    /**
     * Process all pending signals.
     */
    public function processAllPending(int $limit = 100): int
    {
        $processed = 0;

        Signal::pending()
            ->orderBy('occurred_at')
            ->limit($limit)
            ->each(function ($signal) use (&$processed) {
                try {
                    $this->process($signal);
                    $processed++;
                } catch (\Throwable $e) {
                    Log::error('Failed to process signal', [
                        'signal_id' => $signal->id,
                        'signal_type' => $signal->signal_type_code,
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $signal->markFailed($e->getMessage());
                }
            });

        return $processed;
    }

    /**
     * Create an alert from a signal.
     */
    protected function createAlertFromSignal(Signal $signal, ?SignalRule $rule = null): ControlRoomAlert
    {
        $signalType = $signal->signalType;

        // Determine severity — normalised through canonical AlertSeverity
        $severity = AlertSeverity::normalise(
            $rule?->getOutputSeverity($signal)
                ?? $signal->severity_hint
                ?? $signalType?->default_severity
        );

        // Determine alert type name
        $alertType = $this->resolveAlertType($signal, $rule);

        // Find appropriate queue
        $queue = null;
        if ($rule?->output_tier) {
            $queue = TriageQueue::active()->byTier($rule->output_tier)->first();
        }
        $queue ??= TriageQueue::findForAlert($severity, $signal->signalSource?->slug ?? 'unknown', $signal->signal_type_code);

        // Create the alert
        $alert = ControlRoomAlert::create([
            'source' => $signal->signalSource?->slug ?? 'unknown',
            'alert_type' => $alertType,
            'severity' => $severity,
            'status' => 'open',
            'asset_id' => $signal->asset_id,
            'device_id' => $signal->device_id,
            'site_id' => $signal->site_id,
            'client_id' => $signal->client_id,
            'queue_id' => $queue?->id,
            'escalation_level' => $rule?->getOutputEscalationLevel() ?? 0,
            'triggered_at' => $signal->occurred_at,
            'context' => [
                'signal_id' => $signal->id,
                'signal_type_code' => $signal->signal_type_code,
                'signal_payload' => $signal->payload,
                'rule_id' => $rule?->id,
                'normalized_data' => $signal->normalized_data,
            ],
        ]);

        // Mark signal as processed
        $signal->markProcessed($alert);

        if ($queue) {
            AlertQueue::create([
                'alert_id' => $alert->id,
                'queue_id' => $queue->id,
                'entered_at' => now(),
            ]);
        }

        // Attach SLA
        $this->attachSla($alert);

        // Attach playbook if rule specifies one
        if ($rule?->playbook_id) {
            $this->attachPlaybook($alert, $rule->playbook);
        } else {
            // Try to find auto-attach playbook matching the resolved alert type name
            $playbook = Playbook::findForAlert($alertType, $severity);
            if ($playbook) {
                $this->attachPlaybook($alert, $playbook);
            }
        }

        // Update device if applicable
        if ($signal->device_id) {
            $signal->device?->recordSignal();
        }

        // Increment shift counters
        $currentShift = Shift::getCurrent();
        $currentShift?->incrementCreated();

        // Audit log
        AuditLogger::log('controlRoom.alert.created', $alert, [
            'source' => 'signal_processing',
            'signal_id' => $signal->id,
        ]);

        Log::info('Alert created from signal', [
            'alert_id' => $alert->id,
            'signal_id' => $signal->id,
            'severity' => $severity,
        ]);

        $this->notifications->notifyAlert($alert, $rule, $queue);

        // Run post-creation automation (auto-assign, auto-start playbook)
        app(AlertAutomationService::class)->onAlertCreated($alert);

        return $alert;
    }

    /**
     * Check if signal should be suppressed due to maintenance window.
     */
    protected function isInMaintenanceWindow(Signal $signal): bool
    {
        return MaintenanceWindow::query()
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->where(function ($q) use ($signal) {
                $q->whereNull('signal_source_id')
                    ->orWhere('signal_source_id', $signal->signal_source_id);
            })
            ->where(function ($q) use ($signal) {
                $q->whereNull('site_id')
                    ->orWhere('site_id', $signal->site_id);
            })
            ->where(function ($q) use ($signal) {
                $q->whereNull('asset_id')
                    ->orWhere('asset_id', $signal->asset_id);
            })
            ->exists();
    }

    /**
     * Find an existing alert that this signal should correlate with.
     */
    protected function findCorrelatedAlert(Signal $signal, SignalRule $rule): ?ControlRoomAlert
    {
        $windowMinutes = $rule->dedup_window_minutes ?? 30;
        $normalizedData = $signal->normalized_data ?? [];

        $query = ControlRoomAlert::query()
            ->unresolved()
            ->where('triggered_at', '>=', now()->subMinutes($windowMinutes));

        $query->whereIn('alert_type', $this->correlationAlertTypes($signal, $rule));

        if (! empty($normalizedData['shift_id'])) {
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(context, '$.normalized_data.shift_id')) = ?",
                [(string) $normalizedData['shift_id']]
            );
        } elseif (! empty($normalizedData['coverage_window_key'])) {
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(context, '$.normalized_data.coverage_window_key')) = ?",
                [(string) $normalizedData['coverage_window_key']]
            );

            if ($signal->site_id) {
                $query->where('site_id', $signal->site_id);
            }
        } elseif ($signal->device_id) {
            $query->where('device_id', $signal->device_id);
        } elseif ($signal->asset_id) {
            $query->where('asset_id', $signal->asset_id);
        } elseif ($signal->site_id) {
            $query->where('site_id', $signal->site_id);
        }

        return $query->latest('triggered_at')->lockForUpdate()->first();
    }

    private function trustedIncidentForSignal(Signal $signal): ?ClientIncident
    {
        $incidentId = data_get($signal->normalized_data, 'incident_id');
        if ($incidentId === null || $incidentId === '') {
            return null;
        }

        if (! $this->hasCanonicalPositiveIntegerIdentity($incidentId)) {
            throw new \DomainException('Incident signal correlation requires a valid incident.');
        }

        $source = $signal->signalSource;
        $capabilities = $source?->capabilities ?? [];
        $isTrustedSource = $source !== null
            && $source->status === 'active'
            && $source->vendor === 'internal'
            && (
                in_array($source->slug, self::TRUSTED_INCIDENT_SOURCE_SLUGS, true)
                || in_array(self::INCIDENT_CORRELATION_CAPABILITY, $capabilities, true)
            );

        if (! $isTrustedSource) {
            throw new \DomainException('Incident signal correlation requires a trusted source.');
        }

        $incident = ClientIncident::query()
            ->whereKey((int) $incidentId)
            ->first();

        if ($incident === null
            || $signal->client_id === null
            || (int) $incident->client_id !== (int) $signal->client_id
        ) {
            throw new \DomainException('Incident signal correlation does not match the signal client.');
        }

        return $incident;
    }

    private function hasCanonicalPositiveIntegerIdentity(mixed $identity): bool
    {
        if (is_int($identity)) {
            return $identity > 0;
        }

        return is_string($identity)
            && preg_match('/^[1-9][0-9]*$/D', $identity) === 1
            && (string) (int) $identity === $identity;
    }

    private function exactAlertForIncident(ClientIncident $incident): ?ControlRoomAlert
    {
        if ($incident->control_room_alert_id !== null) {
            $direct = ControlRoomAlert::query()
                ->whereKey($incident->control_room_alert_id)
                ->lockForUpdate()
                ->first();

            if ($direct !== null) {
                return $direct;
            }
        }

        $claims = ControlRoomAlert::query()
            ->where(function ($query) use ($incident) {
                $query->whereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(context, '$.incident_id')) = ?",
                    [(string) $incident->id]
                )->orWhereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(context, '$.normalized_data.incident_id')) = ?",
                    [(string) $incident->id]
                );
            })
            ->lockForUpdate()
            ->get();

        if ($claims->count() > 1) {
            throw new \DomainException(
                'Incident signal correlation is ambiguous: multiple alerts claim the same incident.',
            );
        }

        $alert = $claims->first();

        return $alert;
    }

    /**
     * Add a correlated signal to an existing alert.
     */
    protected function addSignalToAlert(Signal $signal, ControlRoomAlert $alert): void
    {
        $context = $alert->context ?? [];
        $correlatedSignals = $context['correlated_signals'] ?? [];
        $correlatedSignals[] = [
            'signal_id' => $signal->id,
            'occurred_at' => $signal->occurred_at->toISOString(),
            'severity_hint' => $signal->severity_hint,
        ];

        $newAlertType = $this->resolveAlertType($signal);
        $isTransition = $this->isShiftStateTransition($alert->alert_type, $newAlertType);
        $updatedContext = array_merge($context, [
            'signal_id' => $signal->id,
            'signal_type_code' => $signal->signal_type_code,
            'signal_payload' => $signal->payload,
            'normalized_data' => $signal->normalized_data,
            'correlated_signals' => $correlatedSignals,
            'last_signal_at' => $signal->occurred_at->toISOString(),
        ]);

        if ($isTransition) {
            $transitions = $updatedContext['state_transitions'] ?? [];
            $transitions[] = [
                'from_alert_type' => $alert->alert_type,
                'to_alert_type' => $newAlertType,
                'from_signal_type_code' => $context['signal_type_code'] ?? null,
                'to_signal_type_code' => $signal->signal_type_code,
                'transitioned_at' => now()->toISOString(),
                'reason' => 'Shift start anomaly moved from no-show risk to confirmed late start.',
            ];
            $updatedContext['state_transitions'] = $transitions;
        }

        $attributes = [
            'context' => $updatedContext,
        ];

        if ($isTransition) {
            $attributes['alert_type'] = $newAlertType;
            $attributes['severity'] = $signal->severity_hint ?? $alert->severity;
        } else {
            $highestSeverity = $this->higherSeverity($alert->severity, $signal->severity_hint);
            if ($highestSeverity !== $alert->severity) {
                $attributes['severity'] = $highestSeverity;
            }
        }

        $alert->update($attributes);

        if ($isTransition) {
            AuditLogger::log('controlRoom.alert.transition', $alert, [
                'source' => 'shift_signal_pipeline',
                'signal_id' => $signal->id,
                'from_alert_type' => $alert->getOriginal('alert_type'),
                'to_alert_type' => $newAlertType,
            ]);
        }
    }

    /**
     * Attach an SLA to an alert.
     */
    protected function attachSla(ControlRoomAlert $alert): void
    {
        $slaDefinition = SlaDefinition::findForAlert(
            $alert->alert_type,
            $alert->severity,
            $alert->source
        );

        if ($slaDefinition) {
            AlertSla::createFromDefinition($alert, $slaDefinition);
        }
    }

    /**
     * Attach a playbook to an alert.
     */
    protected function attachPlaybook(ControlRoomAlert $alert, Playbook $playbook): void
    {
        $run = PlaybookRun::create([
            'playbook_id' => $playbook->id,
            'alert_id' => $alert->id,
            'status' => 'pending',
            'total_steps' => $playbook->steps()->count(),
        ]);

        $alert->update(['playbook_run_id' => $run->id]);
    }

    /**
     * Ingest a signal from a fleet signal, enriching with operational context.
     */
    public function ingestFromFleetSignal(FleetSignal $fleetSignal): Signal
    {
        // Eager-load relationships for context enrichment
        $fleetSignal->loadMissing([
            'asset:id,name,asset_tag,registration_number,home_site_id',
            'asset.homeSite:id,name',
            'trip:id,asset_id,driver_session_id,started_at,ended_at,distance_km,start_address,end_address',
            'trip.driverSession:id,user_id',
            'trip.driverSession.user:id,name',
            'driverSession:id,user_id',
            'driverSession.user:id,name',
            'geofence:id,name,asset_id',
        ]);

        // Build fleet context for the alert
        $fleetContext = $this->buildFleetContext($fleetSignal);

        // Map fleet source to control room signal source
        $fleetSource = SignalSource::where('slug', 'queclink_fleet')->first();

        $signalTypeCode = 'fleet_'.str_replace('.', '_', $fleetSignal->signal_type);

        $data = [
            'signal_source_id' => $fleetSource?->id,
            'signal_type_code' => $signalTypeCode,
            'asset_id' => $fleetSignal->asset_id,
            'site_id' => $fleetSignal->asset?->home_site_id,
            'external_ref' => 'fleet_signal_'.$fleetSignal->id,
            'severity_hint' => $fleetSignal->severity_hint ?? 'medium',
            'occurred_at' => $fleetSignal->occurred_at,
            'payload' => array_merge($fleetSignal->payload ?? [], [
                'fleet_context' => $fleetContext,
            ]),
            'normalized_data' => [
                'fleet_signal_id' => $fleetSignal->id,
                'trip_id' => $fleetSignal->trip_id,
                'driver_session_id' => $fleetSignal->driver_session_id,
                'fleet_context' => $fleetContext,
            ],
        ];

        return $this->ingest($data);
    }

    /**
     * Ingest a signal from a shift signal, enriching with staffing and care context.
     */
    public function ingestFromShiftSignal(ShiftSignal $shiftSignal): Signal
    {
        $shiftSignal->loadMissing([
            'site:id,name',
            'client:id,first_name,last_name,site_id',
            'staff:id,name',
            'shift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,actual_starts_at,actual_ends_at,status,shift_type,location',
            'shift.client:id,first_name,last_name,site_id',
            'shift.site:id,name',
            'shift.staff:id,name',
            'shift.serviceContext:id,name,type',
            'shift.attendanceSessions:id,shift_id,user_id,clock_in_at,clock_out_at,status',
            'shift.medicationAdministrations:id,shift_id,client_medication_id,scheduled_for,status,administered_at,notes',
            'shift.medicationAdministrations.medication:id,name',
            'shift.incidents:id,shift_id,client_id,type,severity,status,occurred_at,title',
            'shift.residentTransports:id,shift_id,resident_id,booking_id,status,pickup_location,dropoff_location',
            'shift.residentTransports.resident:id,first_name,last_name',
            'shift.residentTransports.booking:id,status,purpose,starts_at,ends_at',
        ]);

        $shiftContext = $this->buildShiftContext($shiftSignal);
        $source = SignalSource::query()->firstOrCreate(
            ['slug' => 'shift_operations'],
            [
                'name' => 'Shift Operations',
                'vendor' => 'internal',
                'status' => 'active',
            ],
        );

        $data = [
            'signal_source_id' => $source?->id,
            'signal_type_code' => $shiftSignal->signal_type,
            'site_id' => $shiftSignal->site_id ?: $shiftSignal->shift?->site_id ?: $shiftSignal->shift?->client?->site_id,
            'client_id' => $shiftSignal->client_id ?: $shiftSignal->shift?->client_id,
            'external_ref' => 'shift_signal_'.$shiftSignal->id,
            'severity_hint' => $shiftSignal->severity_hint ?? 'medium',
            'occurred_at' => $shiftSignal->occurred_at,
            'payload' => array_merge($shiftSignal->payload ?? [], [
                'shift_context' => $shiftContext,
            ]),
            'normalized_data' => [
                'shift_signal_id' => $shiftSignal->id,
                'shift_id' => $shiftSignal->shift_id,
                'coverage_window_key' => $shiftSignal->payload['coverage_window_key']
                    ?? $shiftSignal->payload['window_key']
                    ?? null,
                'staff_user_id' => $shiftSignal->user_id ?: $shiftSignal->shift?->user_id,
                'site_id' => $shiftSignal->site_id ?: $shiftSignal->shift?->site_id ?: $shiftSignal->shift?->client?->site_id,
            ],
        ];

        return $this->ingest($data);
    }

    public function resolveShiftAlertsByShift(
        int $shiftId,
        array $signalTypes,
        string $reason,
        string $resolutionSource,
        array $metadata = [],
    ): int {
        $alerts = ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'shift_operations')
            ->whereIn('alert_type', collect($signalTypes)->map(
                fn (string $signalType) => $this->shiftSignals->alertTypeForSignalType($signalType)
            )->all())
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(context, '$.normalized_data.shift_id')) = ?",
                [(string) $shiftId]
            )
            ->get();

        foreach ($alerts as $alert) {
            $this->resolveAlert($alert, $reason, $resolutionSource, $metadata);
        }

        return $alerts->count();
    }

    public function resolveShiftCoverageAlert(
        string $coverageWindowKey,
        string $reason,
        string $resolutionSource,
        array $metadata = [],
    ): int {
        $alerts = ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'shift_operations')
            ->where('alert_type', $this->shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_UNCOVERED))
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(context, '$.normalized_data.coverage_window_key')) = ?",
                [$coverageWindowKey]
            )
            ->get();

        foreach ($alerts as $alert) {
            $this->resolveAlert($alert, $reason, $resolutionSource, $metadata);
        }

        return $alerts->count();
    }

    /**
     * Build enriched fleet context for control room alerts.
     */
    protected function buildFleetContext(FleetSignal $signal): array
    {
        $context = [];

        // Vehicle info (non-PII operational identifiers)
        if ($signal->asset) {
            $context['vehicle'] = [
                'id' => $signal->asset->id,
                'name' => $signal->asset->name,
                'asset_tag' => $signal->asset->asset_tag,
                'registration' => $signal->asset->registration_number,
                'home_site' => $signal->asset->homeSite?->name,
            ];
        }

        // Driver reference (ID only — name available via drill-down)
        $driver = $signal->driverSession?->user ?? $signal->trip?->driverSession?->user;
        if ($driver) {
            $context['driver'] = [
                'id' => $driver->id,
            ];
        }

        // Geofence info (non-PII)
        if ($signal->geofence) {
            $context['geofence'] = [
                'id' => $signal->geofence->id,
                'name' => $signal->geofence->name,
            ];
        }

        // Trip info (IDs and metrics only — addresses removed)
        if ($signal->trip) {
            $context['trip'] = [
                'id' => $signal->trip->id,
                'started_at' => optional($signal->trip->started_at)->toISOString(),
                'ended_at' => optional($signal->trip->ended_at)->toISOString(),
                'distance_km' => $signal->trip->distance_km,
            ];
        }

        // Linked booking and outing (reduced to IDs, status, and non-PII labels)
        if ($signal->asset_id) {
            $activeBooking = FleetVehicleBooking::query()
                ->where('asset_id', $signal->asset_id)
                ->where('status', 'checked_out')
                ->first();

            if ($activeBooking) {
                $context['booking'] = [
                    'id' => $activeBooking->id,
                    'purpose' => $activeBooking->purpose,
                    'booked_by_user_id' => $activeBooking->user_id,
                ];

                $outing = FleetOuting::query()
                    ->where('booking_id', $activeBooking->id)
                    ->where('status', 'active')
                    ->withCount('residents')
                    ->first();

                if ($outing) {
                    $context['outing'] = [
                        'id' => $outing->id,
                        'title' => $outing->title,
                    ];
                    $context['affected_resident_count'] = $outing->residents_count;
                }
            }

            // Active transport (count + IDs only — names removed)
            $activeTransport = FleetResidentTransport::query()
                ->where('asset_id', $signal->asset_id)
                ->where('status', 'in_progress')
                ->first();

            if ($activeTransport && ! isset($context['affected_resident_count'])) {
                $context['affected_resident_count'] = $activeTransport->resident_id ? 1 : 0;
            }
        }

        // Vehicle state (last known position — already consent-gated)
        if ($signal->asset_id) {
            $state = FleetVehicleStateSnapshot::query()
                ->where('asset_id', $signal->asset_id)
                ->first();

            if ($state && $state->latitude && ! $state->consent_blocked) {
                $context['location'] = [
                    'lat' => (float) $state->latitude,
                    'lng' => (float) $state->longitude,
                    'speed_kph' => $state->speed_kph,
                    'last_seen_at' => optional($state->last_seen_at)->toISOString(),
                ];
            }
        }

        return $context;
    }

    /**
     * Build enriched shift context for control room alerts.
     */
    protected function buildShiftContext(ShiftSignal $signal): array
    {
        $context = [];
        $shift = $signal->shift;
        $occurredAt = $signal->occurred_at ?? now();

        if ($shift) {
            $attendanceSessions = $shift->attendanceSessions ?? collect();
            $clockIn = $attendanceSessions
                ->whereNotNull('clock_in_at')
                ->sortBy('clock_in_at')
                ->first();
            $clockOut = $attendanceSessions
                ->whereNotNull('clock_out_at')
                ->sortByDesc('clock_out_at')
                ->first();

            $context['shift'] = [
                'id' => $shift->id,
                'status' => $shift->status,
                'shift_type' => $shift->shift_type,
                'location' => $shift->location,
                'planned_start' => $shift->starts_at?->toISOString(),
                'planned_end' => $shift->ends_at?->toISOString(),
                'actual_start' => ($shift->actual_starts_at ?? $clockIn?->clock_in_at)?->toISOString(),
                'actual_end' => ($shift->actual_ends_at ?? $clockOut?->clock_out_at)?->toISOString(),
            ];

            // Staff reference (ID only — name available via drill-down)
            if ($shift->staff) {
                $context['staff'] = [
                    'id' => $shift->staff->id,
                ];
            }

            // Client reference (ID only — name available via drill-down)
            if ($shift->client) {
                $context['client'] = [
                    'id' => $shift->client->id,
                ];
            }

            // Site info (non-PII operational label)
            if ($shift->site ?? $shift->client?->site) {
                $site = $shift->site ?? $shift->client?->site;
                $context['site'] = [
                    'id' => $site?->id,
                    'name' => $site?->name,
                ];
            }

            // Service context (non-PII operational label)
            if ($shift->serviceContext) {
                $context['service_context'] = [
                    'id' => $shift->serviceContext->id,
                    'name' => $shift->serviceContext->name,
                    'type' => $shift->serviceContext->type,
                ];
            }

            $context['attendance'] = [
                'open_session_count' => $attendanceSessions->where('status', 'open')->count(),
                'latest_clock_in_at' => $clockIn?->clock_in_at?->toISOString(),
                'latest_clock_out_at' => $clockOut?->clock_out_at?->toISOString(),
            ];

            // Medications: count and flag only — no medication names or schedule detail
            $medicationsDueSoon = $shift->medicationAdministrations
                ->filter(function (ClientMedicationAdministration $administration) use ($occurredAt) {
                    if (! $administration->scheduled_for || $administration->administered_at) {
                        return false;
                    }

                    if ($administration->status !== 'pending') {
                        return false;
                    }

                    return $administration->scheduled_for->between(
                        Carbon::parse($occurredAt)->copy()->subMinutes(30),
                        Carbon::parse($occurredAt)->copy()->addHour(),
                        true
                    );
                });

            $context['medications_due_soon'] = [
                'count' => $medicationsDueSoon->count(),
                'has_due' => $medicationsDueSoon->isNotEmpty(),
            ];

            // Incidents: count and severity summary only — no titles or narrative
            $activeIncidents = $shift->incidents
                ->filter(fn ($incident) => ! in_array($incident->status, ['closed'], true));

            $context['active_incidents'] = [
                'count' => $activeIncidents->count(),
                'has_active' => $activeIncidents->isNotEmpty(),
                'highest_severity' => $activeIncidents->sortByDesc('severity')->first()?->severity,
            ];

            // Transport: count and status summary — no resident names or location detail
            $activeTransports = $shift->residentTransports
                ->filter(fn ($transport) => ! in_array($transport->status, ['completed', 'cancelled', 'returned'], true));

            $context['transport'] = [
                'count' => $activeTransports->count(),
                'has_active' => $activeTransports->isNotEmpty(),
            ];
        }

        if (! empty($signal->payload['coverage_status']) && is_array($signal->payload['coverage_status'])) {
            $coverageStatus = $signal->payload['coverage_status'];
            $context['coverage_window'] = [
                'site_id' => $coverageStatus['site_id'] ?? $signal->site_id,
                'site_name' => $coverageStatus['site_name'] ?? null,
                'rule_id' => $coverageStatus['rule_id'] ?? null,
                'rule_name' => $coverageStatus['rule_name'] ?? null,
                'coverage_type' => $coverageStatus['coverage_type'] ?? null,
                'service_context_id' => $coverageStatus['service_context_id'] ?? null,
                'service_context_name' => $coverageStatus['service_context_name'] ?? null,
                'window_label' => $coverageStatus['window_label'] ?? null,
                'starts_at' => $coverageStatus['starts_at'] ?? null,
                'ends_at' => $coverageStatus['ends_at'] ?? null,
                'required_staff' => $coverageStatus['required_staff'] ?? null,
                'assigned_staff' => $coverageStatus['assigned_staff'] ?? null,
                'planned_staff' => $coverageStatus['planned_staff'] ?? null,
                'missing_staff' => $coverageStatus['missing_staff'] ?? null,
                'deficit' => $coverageStatus['deficit'] ?? $coverageStatus['unfilled_after_open_shifts'] ?? null,
                'unfilled_after_open_shifts' => $coverageStatus['unfilled_after_open_shifts'] ?? null,
                'role_shortages' => $coverageStatus['role_shortages'] ?? [],
                'recommended_fill_action' => $coverageStatus['recommended_fill_action'] ?? null,
                'open_shift_ids' => $coverageStatus['open_shift_ids'] ?? [],
                'coverage_window_key' => $coverageStatus['coverage_window_key'] ?? $signal->payload['coverage_window_key'] ?? null,
            ];
        }

        if (! empty($signal->payload['reason'])) {
            $context['reason'] = $signal->payload['reason'];
        }

        return $context;
    }

    /**
     * Ingest a signal from a device.
     */
    public function ingestFromDevice(Device $device, string $signalTypeCode, array $payload = [], ?string $severityHint = null): Signal
    {
        return $this->ingest([
            'signal_source_id' => $device->signal_source_id,
            'signal_type_code' => $signalTypeCode,
            'device_id' => $device->id,
            'asset_id' => $device->asset_id,
            'site_id' => $device->site_id,
            'client_id' => $device->client_id,
            'severity_hint' => $severityHint,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }

    protected function resolveAlertType(Signal $signal, ?SignalRule $rule = null): string
    {
        $derivedRuleAlertType = null;
        if ($rule && is_string($rule->name) && str_ends_with(strtolower($rule->name), ' rule')) {
            $derivedRuleAlertType = preg_replace('/\s+rule$/i', '', $rule->name);
        }

        return $rule?->alert_type
            ?? $derivedRuleAlertType
            ?? $signal->signalType?->name
            ?? str_replace('_', ' ', ucwords($signal->signal_type_code, '_'));
    }

    protected function higherSeverity(?string $current, ?string $candidate): string
    {
        return AlertSeverity::higher(
            AlertSeverity::normalise($current),
            AlertSeverity::normalise($candidate),
        );
    }

    protected function resolveAlert(
        ControlRoomAlert $alert,
        string $reason,
        string $resolutionSource,
        array $metadata = [],
    ): void {
        if (in_array($alert->status, ['resolved', 'closed'], true)) {
            return;
        }

        $resolvedAt = now();
        $context = $alert->context ?? [];
        $resolution = array_merge([
            'resolved_at' => $resolvedAt->toISOString(),
            'reason' => $reason,
            'source' => $resolutionSource,
        ], $metadata);
        $history = $context['resolution_history'] ?? [];
        $history[] = $resolution;

        $alert->update([
            'status' => 'resolved',
            'resolved_at' => $resolvedAt,
            'resolved_by_user_id' => null,
            'notes' => $reason,
            'context' => array_merge($context, [
                'resolution' => $resolution,
                'resolution_history' => $history,
            ]),
        ]);

        $alert->sla?->recordResolution();

        if (
            $alert->source === 'shift_operations'
            && $alert->alert_type === $this->shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_UNCOVERED)
        ) {
            Log::info('coverage.alert.resolved', [
                'alert_id' => $alert->id,
                'coverage_window_key' => $metadata['coverage_window_key'] ?? data_get($alert->context, 'normalized_data.coverage_window_key'),
                'source' => $resolutionSource,
                'actor_user_id' => $metadata['actor_user_id'] ?? null,
                'shift_id' => $metadata['shift_id'] ?? null,
                'series_id' => $metadata['series_id'] ?? null,
                'action' => $metadata['action'] ?? null,
            ]);
        }

        AuditLogger::log('controlRoom.alert.resolve', $alert, [
            'source' => 'shift_signal_pipeline',
            'resolution_source' => $resolutionSource,
        ]);
    }

    protected function correlationAlertTypes(Signal $signal, ?SignalRule $rule = null): array
    {
        if ($this->isShiftStartSignal((string) $signal->signal_type_code)) {
            return [
                $this->shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_NO_SHOW),
                $this->shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_LATE_START),
            ];
        }

        return [$this->resolveAlertType($signal, $rule)];
    }

    protected function isShiftStartSignal(string $signalTypeCode): bool
    {
        return in_array($signalTypeCode, ShiftSignalService::START_ANOMALY_TYPES, true);
    }

    protected function isShiftStateTransition(string $fromAlertType, string $toAlertType): bool
    {
        return $fromAlertType === $this->shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_NO_SHOW)
            && $toAlertType === $this->shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_LATE_START);
    }
}
