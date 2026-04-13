<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Enums\AlertSeverity;
use App\Models\ControlRoom\SignalSource;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Support\Facades\Log;

/**
 * Canonical clinical signal emission service.
 *
 * Mirrors MedicationSignalService pattern: wraps SignalProcessingService
 * for clinical events that need Control Room visibility.
 *
 * Only high/critical severity clinical events are emitted.
 */
class ClinicalSignalService
{
    public const TYPE_CLINICAL_EVENT = 'clinical_event';
    public const TYPE_FALL = 'clinical_fall';
    public const TYPE_SEIZURE = 'clinical_seizure';
    public const TYPE_CHOKING = 'clinical_choking';
    public const TYPE_DETERIORATION = 'clinical_deterioration';

    protected ?SignalSource $signalSource = null;

    public function __construct(
        protected SignalProcessingService $signalProcessor,
    ) {}

    /**
     * Emit a signal for a high-severity clinical event.
     *
     * Only events with severity >= HIGH are emitted to Control Room.
     */
    public function emitForEvent(ClinicalEvent $event): void
    {
        if (! AlertSeverity::isAtLeast($event->severity, AlertSeverity::HIGH)) {
            return;
        }

        $signalType = $this->resolveSignalType($event);

        $this->emit(
            $signalType,
            $event->client_id,
            $event->severity,
            $this->buildMessage($event),
            [
                'clinical_event_id' => $event->id,
                'event_type' => $event->event_type->value,
                'site_id' => $event->site_id,
                'shift_id' => $event->shift_id,
                'occurred_at' => $event->occurred_at,
            ],
            $event->site_id,
            "clinical_event_{$event->id}",
        );
    }

    /**
     * Core emit method — mirrors MedicationSignalService::emit() exactly.
     */
    protected function emit(
        string $signalType,
        int $clientId,
        string $severity,
        string $message,
        array $context = [],
        ?int $siteId = null,
        ?string $uniqueSuffix = null,
    ): void {
        $source = $this->getSignalSource();

        $idempotencyKey = implode(':', array_filter([
            'clinical',
            $signalType,
            $clientId,
            $uniqueSuffix,
        ]));

        $signalData = [
            'signal_source_id' => $source?->id,
            'signal_type_code' => $signalType,
            'idempotency_key' => $idempotencyKey,
            'site_id' => $siteId,
            'client_id' => $clientId,
            'severity_hint' => $severity,
            'occurred_at' => $context['occurred_at'] ?? now(),
            'payload' => [],
            'normalized_data' => array_merge([
                'title' => $message,
                'description' => $message,
                'source_module' => 'clinical',
                'signal_type' => $signalType,
                'client_id' => $clientId,
            ], $context),
        ];

        try {
            $signal = $this->signalProcessor->ingest($signalData);
            $alert = $this->signalProcessor->process($signal);

            if ($alert) {
                Log::info('ClinicalSignalService: alert created', [
                    'signal_type' => $signalType,
                    'alert_id' => $alert->id,
                    'severity' => $severity,
                    'client_id' => $clientId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ClinicalSignalService: signal emission failed', [
                'signal_type' => $signalType,
                'client_id' => $clientId,
                'severity' => $severity,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveSignalType(ClinicalEvent $event): string
    {
        return match ($event->event_type->value) {
            'fall' => self::TYPE_FALL,
            'seizure' => self::TYPE_SEIZURE,
            'choking' => self::TYPE_CHOKING,
            'deterioration' => self::TYPE_DETERIORATION,
            default => self::TYPE_CLINICAL_EVENT,
        };
    }

    protected function buildMessage(ClinicalEvent $event): string
    {
        $label = $event->event_type->label();
        $clientName = $event->client?->first_name
            ? trim($event->client->first_name . ' ' . ($event->client->last_name ?? ''))
            : 'Client #' . $event->client_id;

        return "{$label} reported for {$clientName}";
    }

    protected function getSignalSource(): ?SignalSource
    {
        if ($this->signalSource === null) {
            $this->signalSource = SignalSource::firstOrCreate(
                ['slug' => 'clinical'],
                [
                    'name' => 'Health & Clinical',
                    'vendor' => 'internal',
                    'status' => 'active',
                ]
            );
        }

        return $this->signalSource;
    }
}
