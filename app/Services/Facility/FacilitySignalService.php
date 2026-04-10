<?php

namespace App\Services\Facility;

use App\Enums\AlertSeverity;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\SignalSource;
use App\Models\SiteInspectionSchedule;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Support\Facades\Log;

/**
 * Canonical facility signal emission service.
 *
 * Covers site/facility operational alerts:
 * - Inspection overdue / failed
 * - Device offline / fault (non-fleet Control Room devices)
 *
 * Flow: facility event → FacilitySignalService → SignalProcessingService → ControlRoomAlert
 */
class FacilitySignalService
{
    public const TYPE_INSPECTION_OVERDUE = 'inspection_overdue';
    public const TYPE_INSPECTION_FAILED = 'inspection_failed';
    public const TYPE_DEVICE_OFFLINE = 'cr_device_offline';
    public const TYPE_DEVICE_LOW_BATTERY = 'cr_device_low_battery';

    protected ?SignalSource $signalSource = null;

    public function __construct(
        protected SignalProcessingService $signalProcessor,
    ) {}

    /**
     * Emit an inspection overdue signal.
     */
    public function emitInspectionOverdue(SiteInspectionSchedule $schedule, int $daysOverdue): void
    {
        $schedule->loadMissing(['site:id,name', 'assignedTo:id,name']);

        // Only safety-critical overdue inspections (>7 days) go to CR as high.
        // 1-7 days overdue is medium.
        $severity = $daysOverdue > 7 ? AlertSeverity::HIGH : AlertSeverity::MEDIUM;

        $this->emit(
            self::TYPE_INSPECTION_OVERDUE,
            "Inspection overdue: {$schedule->title} at {$schedule->site?->name} ({$daysOverdue} days)",
            $severity,
            [
                'inspection_schedule_id' => $schedule->id,
                'inspection_type' => $schedule->inspection_type,
                'inspection_title' => $schedule->title,
                'site_id' => $schedule->site_id,
                'site_name' => $schedule->site?->name,
                'next_due_date' => $schedule->next_due_date?->toDateString(),
                'days_overdue' => $daysOverdue,
                'assigned_to_user_id' => $schedule->assigned_to_user_id,
                'assigned_to_name' => $schedule->assignedTo?->name,
                'frequency' => $schedule->frequency,
            ],
            $schedule->site_id,
        );
    }

    /**
     * Emit an inspection failed signal.
     */
    public function emitInspectionFailed(
        SiteInspectionSchedule $schedule,
        \App\Models\SiteInspectionRecord $record,
    ): void {
        $schedule->loadMissing(['site:id,name', 'assignedTo:id,name']);

        $this->emit(
            self::TYPE_INSPECTION_FAILED,
            "Inspection FAILED: {$schedule->title} at {$schedule->site?->name}",
            AlertSeverity::HIGH,
            [
                'inspection_schedule_id' => $schedule->id,
                'inspection_record_id' => $record->id,
                'inspection_type' => $schedule->inspection_type,
                'inspection_title' => $schedule->title,
                'site_id' => $schedule->site_id,
                'site_name' => $schedule->site?->name,
                'result' => $record->result,
                'findings' => $record->findings,
                'corrective_actions' => $record->corrective_actions,
                'completed_at' => $record->completed_at?->toIso8601String(),
                'completed_by_user_id' => $record->completed_by_user_id,
                'due_date' => $record->due_date?->toDateString(),
                'assigned_to_user_id' => $schedule->assigned_to_user_id,
                'assigned_to_name' => $schedule->assignedTo?->name,
                'frequency' => $schedule->frequency,
            ],
            $schedule->site_id,
        );
    }

    /**
     * Emit a Control Room device offline signal (non-fleet devices).
     *
     * Fleet devices are handled by DetectFleetOfflineDevices → FleetSignalService.
     * Integration devices are handled by IntegrationSignalNormaliser.
     * This covers CR-registered devices like bed sensors, cameras, alarm panels.
     */
    public function emitDeviceOffline(Device $device, int $minutesOffline): void
    {
        // Safety-critical device types get higher severity
        $isSafetyCritical = in_array($device->type, [
            Device::TYPE_BED_SENSOR,
            Device::TYPE_ALARM_PANEL,
            Device::TYPE_PERSONAL_TRACKER,
        ], true);

        $severity = $isSafetyCritical ? AlertSeverity::HIGH : AlertSeverity::MEDIUM;

        $this->emit(
            self::TYPE_DEVICE_OFFLINE,
            "Device offline: {$device->name} ({$device->type}) — {$minutesOffline}min",
            $severity,
            [
                'device_id' => $device->id,
                'device_uid' => $device->device_uid,
                'device_name' => $device->name,
                'device_type' => $device->type,
                'site_id' => $device->site_id,
                'location_description' => $device->location_description,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'minutes_offline' => $minutesOffline,
                'safety_critical' => $isSafetyCritical,
                'client_id' => $device->client_id,
            ],
            $device->site_id,
        );
    }

    /**
     * Core signal emission method.
     */
    protected function emit(
        string $signalType,
        string $message,
        string $severity,
        array $context,
        ?int $siteId = null,
    ): void {
        $source = $this->getSignalSource();

        $idempotencyKey = $this->buildIdempotencyKey($signalType, $context);

        $signalData = [
            'signal_source_id' => $source?->id,
            'signal_type_code' => $signalType,
            'idempotency_key' => $idempotencyKey,
            'site_id' => $siteId,
            'client_id' => $context['client_id'] ?? null,
            'severity_hint' => $severity,
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => array_merge([
                'title' => $message,
                'description' => $message,
                'source_module' => 'facility',
                'signal_type' => $signalType,
            ], $context),
        ];

        try {
            $signal = $this->signalProcessor->ingest($signalData);
            $alert = $this->signalProcessor->process($signal);

            if ($alert) {
                Log::info('FacilitySignalService: alert created', [
                    'signal_type' => $signalType,
                    'alert_id' => $alert->id,
                    'severity' => $severity,
                    'site_id' => $siteId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('FacilitySignalService: signal emission failed', [
                'signal_type' => $signalType,
                'severity' => $severity,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build idempotency key with appropriate dedup windows.
     */
    protected function buildIdempotencyKey(string $signalType, array $context): string
    {
        // Inspections dedup daily (only need one alert per day per schedule)
        // Devices dedup every 30 minutes (similar to fleet offline pattern)
        $windowMinutes = str_starts_with($signalType, 'inspection') ? 1440 : 30;
        $window = now()->format('Y-m-d') . ($windowMinutes < 1440
            ? '_' . (intdiv((int) now()->format('G'), 1) . ':' . (intdiv((int) now()->format('i'), $windowMinutes) * $windowMinutes))
            : '');

        $entityKey = match ($signalType) {
            self::TYPE_INSPECTION_OVERDUE,
            self::TYPE_INSPECTION_FAILED => $context['inspection_schedule_id'] ?? 'unknown',
            self::TYPE_DEVICE_OFFLINE,
            self::TYPE_DEVICE_LOW_BATTERY => $context['device_id'] ?? 'unknown',
            default => 'unknown',
        };

        return hash('sha256', implode('|', [
            'facility',
            $signalType,
            $entityKey,
            $window,
        ]));
    }

    protected function getSignalSource(): ?SignalSource
    {
        if ($this->signalSource) {
            return $this->signalSource;
        }

        try {
            $this->signalSource = SignalSource::firstOrCreate(
                ['slug' => 'facility'],
                [
                    'name' => 'Facility / Site Operations',
                    'vendor' => 'internal',
                    'status' => 'active',
                    'config' => [],
                    'capabilities' => ['scheduled_checks', 'event_driven'],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('FacilitySignalService: failed to resolve signal source', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->signalSource;
    }
}
