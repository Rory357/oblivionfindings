<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertQueue;
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
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SignalProcessingService
{
    public function __construct(protected ControlRoomNotificationService $notifications)
    {
    }

    /**
     * Ingest a raw signal from an integration.
     */
    public function ingest(array $data): Signal
    {
        // Generate idempotency key if not provided
        if (empty($data['idempotency_key'])) {
            $data['idempotency_key'] = Signal::generateIdempotencyKey($data);
        }

        // Check for duplicate
        $existing = Signal::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            Log::debug('Signal deduplicated', ['idempotency_key' => $data['idempotency_key']]);
            return $existing;
        }

        // Resolve signal type
        if (!empty($data['signal_type_code'])) {
            $signalType = SignalType::findByCode($data['signal_type_code']);
            if ($signalType) {
                $data['signal_type_id'] = $signalType->id;
                $data['severity_hint'] = $data['severity_hint'] ?? $signalType->default_severity;
            }
        }

        // Record signal source activity
        if (!empty($data['signal_source_id'])) {
            $source = SignalSource::find($data['signal_source_id']);
            $source?->recordSignal();
        }

        // Create the signal
        $signal = Signal::create(array_merge($data, [
            'status' => 'pending',
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]));

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

            // Find matching rules
            $rules = SignalRule::findMatchingRules($signal);

            if ($rules->isEmpty()) {
                // No rules match - create alert with defaults
                return $this->createAlertFromSignal($signal);
            }

            // Use the highest priority (lowest number) rule
            $rule = $rules->first();

            // Check for deduplication
            if ($rule->deduplicate) {
                $existingAlert = $this->findCorrelatedAlert($signal, $rule);
                if ($existingAlert) {
                    $signal->markCorrelated($existingAlert);
                    $this->addSignalToAlert($signal, $existingAlert);
                    return $existingAlert;
                }
            }

            // Create new alert
            return $this->createAlertFromSignal($signal, $rule);
        });
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
                } catch (\Exception $e) {
                    Log::error('Failed to process signal', [
                        'signal_id' => $signal->id,
                        'error' => $e->getMessage(),
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

        // Determine severity
        $severity = $rule?->getOutputSeverity($signal)
            ?? $signal->severity_hint
            ?? $signalType?->default_severity
            ?? 'medium';

        // Determine alert type name
        $alertType = $signalType?->name
            ?? str_replace('_', ' ', ucwords($signal->signal_type_code, '_'));

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
            // Try to find auto-attach playbook
            $playbook = Playbook::findForAlert($signal->signal_type_code, $severity);
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

        $query = ControlRoomAlert::query()
            ->unresolved()
            ->where('alert_type', $signal->signalType?->name ?? str_replace('_', ' ', ucwords($signal->signal_type_code, '_')))
            ->where('triggered_at', '>=', now()->subMinutes($windowMinutes));

        // Match on same device/asset/site
        if ($signal->device_id) {
            $query->where('device_id', $signal->device_id);
        } elseif ($signal->asset_id) {
            $query->where('asset_id', $signal->asset_id);
        } elseif ($signal->site_id) {
            $query->where('site_id', $signal->site_id);
        }

        return $query->latest('triggered_at')->first();
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

        $alert->update([
            'context' => array_merge($context, [
                'correlated_signals' => $correlatedSignals,
                'last_signal_at' => $signal->occurred_at->toISOString(),
            ]),
        ]);
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
     * Ingest a signal from a fleet signal.
     */
    public function ingestFromFleetSignal(\App\Models\FleetSignal $fleetSignal): Signal
    {
        $data = [
            'signal_source_id' => null, // Will need to map fleet to signal source
            'signal_type_code' => 'fleet_' . $fleetSignal->signal_type,
            'asset_id' => $fleetSignal->asset_id,
            'external_ref' => 'fleet_signal_' . $fleetSignal->id,
            'severity_hint' => $fleetSignal->severity_hint ?? 'medium',
            'occurred_at' => $fleetSignal->occurred_at,
            'payload' => $fleetSignal->payload,
            'normalized_data' => [
                'fleet_signal_id' => $fleetSignal->id,
                'trip_id' => $fleetSignal->trip_id,
                'driver_session_id' => $fleetSignal->driver_session_id,
            ],
        ];

        return $this->ingest($data);
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
}
