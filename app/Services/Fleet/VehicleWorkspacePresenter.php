<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetDocumentSet;
use App\Models\FleetChecklistRun;
use App\Models\FleetServiceCompletion;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleComplianceRecord;
use App\Models\FleetVehicleComplianceVersion;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\FleetVehicleReminder;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\Fleet\Data\VehicleReadinessAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Scoped read model for the vehicle workspace (Overview and Service &
 * compliance). Every list is bounded; nothing here writes.
 */
class VehicleWorkspacePresenter
{
    private const READINGS = 50;

    private const HISTORY = 50;

    public function __construct(
        private readonly VehicleReadinessService $readiness,
        private readonly VehicleOdometerService $odometer,
        private readonly VehicleStaffDirectory $staff,
        private readonly FleetCatalogueService $catalogue,
        private readonly MaintenanceAccessService $maintenance,
        private readonly VehicleObligationReminderService $obligationReminders,
        private readonly VehicleMileageFeedService $mileageFeed,
    ) {}

    /** @return array<string,mixed> */
    public function present(User $viewer, Asset $asset, bool $canViewTechnology): array
    {
        $asset->loadMissing(['site:id,name', 'homeSite:id,name', 'primaryDriver:id,name', 'fleetResponsible:id,name', 'profilePhoto']);
        $assessment = $this->readiness->assess($asset);
        $can = $this->permissions($viewer, $asset, $canViewTechnology);
        // Files follow the asset register's own access rule (AssetPolicy::view).
        $documents = $can['view_documents'] ? $this->documents($asset) : [];
        // Service and RUC planning may use the calibrated tracker distance; readiness never does.
        // Tracker figures are separately permissioned, so other viewers plan from recorded readings.
        $currentReading = $assessment->odometerObservationId
            ? FleetVehicleOdometerObservation::query()->find($assessment->odometerObservationId) : null;
        $planning = $this->mileageFeed->planning($asset, $currentReading, $canViewTechnology ? $assessment->trackerEstimate : null);
        $schedules = $this->schedules($asset, $assessment, $documents, $planning['planning_km']);

        return [
            'vehicle' => $this->vehicle($asset),
            'readiness' => $assessment->toArray($canViewTechnology),
            'compliance' => $this->compliance($asset, $documents),
            'odometer' => $this->odometerData($asset, $assessment, $canViewTechnology, $documents),
            'schedules' => $schedules,
            'service_history' => $this->serviceHistory($asset),
            'reminders' => $this->reminders($asset),
            'obligation_reminders' => $this->obligationReminders->forVehicle($viewer, $asset, $planning['planning_km']),
            'mileage_feed' => $this->mileageFeed->present($viewer, $asset, $currentReading, $assessment->trackerEstimate, $canViewTechnology),
            'documents' => array_values(array_filter($documents, fn (array $set): bool => $set['source'] === null)),
            'linked_documents' => $this->linkedDocuments($asset, $documents),
            'work' => $this->work($viewer, $asset),
            'checks' => $this->checks($asset),
            'catalogues' => $this->catalogue->options(),
            'people' => $can['manage'] || $can['manage_schedules'] || $can['manage_documents']
                ? $this->staff->candidates($asset, null, array_filter([$asset->fleet_responsible_user_id]))->all()
                : [],
            'as_of' => now()->toIso8601String(),
            'can' => $can,
        ];
    }

    /** @return array<string,bool> */
    private function permissions(User $viewer, Asset $asset, bool $canViewTechnology): array
    {
        $manage = $viewer->canDo('fleet.manage');

        return [
            'manage' => $manage,
            'view_documents' => Gate::forUser($viewer)->allows('view', $asset),
            'manage_documents' => Gate::forUser($viewer)->allows('manageDocuments', $asset),
            'manage_schedules' => $manage || $viewer->canDo('fleet.maintenance.manage'),
            'add_catalogue' => $this->catalogue->canAdd($viewer),
            'view_finance' => $viewer->canDo('finance.assets.view'),
            'inspect' => $manage || $viewer->canDo('fleet.maintenance.manage'),
            'report_maintenance' => $this->maintenance->canReport($viewer)
                && in_array((int) $asset->site_id, $this->maintenance->approvedSiteIds($viewer), true),
            'view_maintenance' => $this->maintenance->canRead($viewer),
            'schedule_service' => $this->maintenance->canManage($viewer)
                && in_array((int) $asset->site_id, $this->maintenance->approvedSiteIds($viewer), true),
            'book' => $viewer->canDo('fleet.viewAny') || $viewer->canDo('assets.viewAny'),
            'view_vehicle_technology' => $canViewTechnology,
        ];
    }

    /** @return array<string,mixed> */
    private function vehicle(Asset $asset): array
    {
        $photo = $asset->profilePhoto;

        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'asset_tag' => $asset->asset_tag,
            'registration_number' => $asset->registration_number,
            'status' => $asset->status,
            'site' => $asset->site ? ['id' => $asset->site->id, 'name' => $asset->site->name] : null,
            'home_site' => $asset->homeSite ? ['id' => $asset->homeSite->id, 'name' => $asset->homeSite->name] : null,
            'manufacturer' => $asset->manufacturer,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'fuel_type' => $asset->fuel_type,
            'seating_capacity' => $asset->seating_capacity,
            'body_type' => $asset->body_type,
            'use_purpose' => $asset->use_purpose,
            'ownership_arrangement' => $asset->ownership_arrangement,
            'responsible' => $asset->fleetResponsible ? ['id' => $asset->fleetResponsible->id, 'name' => $asset->fleetResponsible->name] : null,
            'primary_driver' => $asset->primaryDriver ? ['id' => $asset->primaryDriver->id, 'name' => $asset->primaryDriver->name] : null,
            'insurance_provider' => $asset->insurance_provider,
            'insurance_policy_reference' => $asset->insurance_policy_reference,
            'insurance_expires_at' => $asset->insurance_expires_at?->toDateString(),
            'warranty_reference' => $asset->warranty_reference,
            'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'inspection_due_at' => $asset->inspection_due_at?->toDateString(),
            'profile_version' => (int) ($asset->vehicle_profile_version ?? 1),
            'photo_url' => $photo && $photo->state === AssetDocument::STATE_AVAILABLE
                ? route('fleet-assets.vehicles.documents.file', ['asset' => $asset->id, 'document' => $photo->id, 'inline' => 1])
                : null,
            'accessibility' => [
                'has_wheelchair_ramp' => (bool) $asset->has_wheelchair_ramp,
                'has_hoist' => (bool) $asset->has_hoist,
                'has_child_seat_anchors' => (bool) $asset->has_child_seat_anchors,
                'has_medical_storage' => (bool) $asset->has_medical_storage,
                'accessibility_notes' => $asset->accessibility_notes,
            ],
            'history' => $this->history($asset),
        ];
    }

    /** Recent changes to the vehicle record, from its audit trail. @return list<array<string,mixed>> */
    private function history(Asset $asset): array
    {
        $labels = [
            'fleet.vehicle.update' => 'Vehicle details updated',
            'fleet.vehicle.photo.update' => 'Profile photo updated',
            'fleet.vehicle.photo.remove' => 'Profile photo removed',
        ];

        return DB::table('audit_logs as log')->leftJoin('users', 'users.id', '=', 'log.user_id')
            ->where('log.auditable_type', $asset->getMorphClass())->where('log.auditable_id', $asset->id)
            ->whereIn('log.action', array_keys($labels))->orderByDesc('log.id')->limit(10)
            ->get(['log.id', 'log.action', 'log.meta', 'log.created_at', 'users.name as actor'])
            ->map(function (object $row) use ($labels): array {
                $meta = json_decode((string) $row->meta, true) ?: [];

                return [
                    'id' => (int) $row->id,
                    'label' => $labels[$row->action] ?? 'Vehicle record changed',
                    'reason' => $meta['reason'] ?? null,
                    'actor' => $row->actor,
                    'occurred_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
                ];
            })->values()->all();
    }

    /**
     * @param  list<array<string,mixed>>  $documents
     * @return list<array<string,mixed>>
     */
    private function compliance(Asset $asset, array $documents): array
    {
        $records = FleetVehicleComplianceRecord::query()->where('asset_id', $asset->id)->get()->keyBy('kind');
        $versions = FleetVehicleComplianceVersion::query()->whereIn('record_id', $records->pluck('id'))
            ->with('recordedBy:id,name')->orderByDesc('version')->get()->groupBy('record_id');
        $filesByVersion = collect($documents)->filter(fn (array $set): bool => ($set['source']['type'] ?? null) === 'compliance_version')
            ->groupBy(fn (array $set): int => (int) $set['source']['id']);

        return collect(VehicleComplianceService::KINDS)->map(function (string $kind) use ($records, $versions, $filesByVersion): array {
            $record = $records->get($kind);
            $history = $record ? ($versions->get($record->id) ?? collect()) : collect();
            $current = $history->firstWhere('id', $record?->current_version_id);

            return [
                'kind' => $kind,
                'label' => VehicleComplianceService::LABELS[$kind],
                'record_id' => $record?->id,
                'current' => $current ? $this->version($current, $filesByVersion->get($current->id, collect())) : null,
                'history' => $history->take(10)->map(fn ($version) => $this->version($version, $filesByVersion->get($version->id, collect())))->values()->all(),
                'history_count' => $history->count(),
            ];
        })->values()->all();
    }

    /** @return array<string,mixed> */
    private function version(FleetVehicleComplianceVersion $version, Collection $sets): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'applicability' => $version->applicability,
            'applicability_basis' => $version->applicability_basis,
            'outcome' => $version->outcome,
            'evidence_reference' => $version->evidence_reference,
            'source_reference' => $version->source_reference,
            'effective_on' => $version->effective_on?->toDateString(),
            'expires_on' => $version->expires_on?->toDateString(),
            'ruc_start_km' => $version->ruc_start_km === null ? null : (float) $version->ruc_start_km,
            'ruc_end_km' => $version->ruc_end_km === null ? null : (float) $version->ruc_end_km,
            'legacy' => $version->source_type === 'legacy_asset_field',
            'reason' => $version->reason,
            'recorded_by' => $version->recordedBy?->name,
            'created_at' => $version->created_at?->toIso8601String(),
            'files' => $sets->flatMap(fn (array $set): array => $set['files'])->values()->all(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $documents
     * @return array<string,mixed>
     */
    private function odometerData(Asset $asset, VehicleReadinessAssessment $assessment, bool $canViewTechnology, array $documents): array
    {
        $readings = FleetVehicleOdometerObservation::query()->where('asset_id', $asset->id)
            ->with('recordedBy:id,name')->orderByDesc('observed_at')->orderByDesc('id')->limit(self::READINGS)->get();
        $correctedIds = FleetVehicleOdometerObservation::query()->where('asset_id', $asset->id)
            ->whereNotNull('corrects_observation_id')->pluck('corrects_observation_id')->map(fn ($id): int => (int) $id)->all();
        $filesByReading = collect($documents)->filter(fn (array $set): bool => ($set['source']['type'] ?? null) === 'odometer_observation')
            ->groupBy(fn (array $set): int => (int) $set['source']['id']);

        return [
            'current_id' => $assessment->odometerObservationId,
            'current_km' => $assessment->odometerKm,
            'tracker_estimate' => $canViewTechnology ? $assessment->trackerEstimate : null,
            'total' => FleetVehicleOdometerObservation::query()->where('asset_id', $asset->id)->count(),
            'readings' => $readings->map(fn (FleetVehicleOdometerObservation $reading): array => [
                'id' => $reading->id,
                'value_km' => (float) $reading->value_km,
                'observed_at' => $reading->observed_at?->toIso8601String(),
                'source_kind' => $reading->source_kind,
                'source_reference' => $reading->source_reference,
                'recorded_by' => $reading->recordedBy?->name,
                'corrects_observation_id' => $reading->corrects_observation_id,
                'correction_reason' => $reading->correction_reason,
                'notes' => $reading->notes,
                'is_current' => (int) $reading->id === $assessment->odometerObservationId,
                'is_corrected' => in_array((int) $reading->id, $correctedIds, true),
                'files' => $filesByReading->get($reading->id, collect())->flatMap(fn (array $set): array => $set['files'])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return list<array<string,mixed>> */
    /** @param  list<array<string,mixed>>  $documents */
    private function schedules(Asset $asset, VehicleReadinessAssessment $assessment, array $documents = [], ?float $planningKm = null): array
    {
        $planningKm ??= $assessment->odometerKm;
        $today = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        // Supporting files kept with each schedule (current revisions only).
        $files = [];
        foreach ($documents as $set) {
            if (($set['source']['type'] ?? null) !== 'service_schedule') {
                continue;
            }
            foreach ($set['files'] as $file) {
                if ($file['current']) {
                    $files[(int) $set['source']['id']][] = ['id' => $file['id'], 'name' => $file['name'], 'url' => $file['url']];
                }
            }
        }

        return FleetServiceSchedule::query()->where('asset_id', $asset->id)->with('owner:id,name')
            ->orderByDesc('is_active')->orderByRaw('next_due_at IS NULL')->orderBy('next_due_at')->orderBy('id')->get()
            ->map(function (FleetServiceSchedule $schedule) use ($planningKm, $today, $files): array {
                $dueDate = $schedule->next_due_at?->toDateString();
                $dueKm = $schedule->next_due_km === null ? null : (float) $schedule->next_due_km;
                $kmOverdue = $dueKm !== null && $planningKm !== null && $planningKm >= $dueKm;

                return [
                    'id' => $schedule->id,
                    'name' => $schedule->name,
                    'interval_months' => $schedule->interval_months,
                    'interval_days' => $schedule->interval_days,
                    'interval_km' => $schedule->interval_km,
                    'next_due_at' => $dueDate,
                    'next_due_km' => $dueKm,
                    'last_completed_at' => $schedule->last_completed_at?->toDateString(),
                    'last_completed_km' => $schedule->last_completed_km === null ? null : (float) $schedule->last_completed_km,
                    'owner' => $schedule->owner ? ['id' => $schedule->owner->id, 'name' => $schedule->owner->name] : null,
                    'reminder_days_before' => $schedule->reminder_days_before,
                    'reminder_km_before' => $schedule->reminder_km_before,
                    'is_active' => (bool) $schedule->is_active,
                    'lock_version' => (int) ($schedule->lock_version ?? 1),
                    'overdue' => (bool) $schedule->is_active && (($dueDate !== null && $dueDate < $today) || $kmOverdue),
                    'km_remaining' => $dueKm !== null && $planningKm !== null ? round($dueKm - $planningKm, 1) : null,
                    'files' => $files[$schedule->id] ?? [],
                ];
            })->values()->all();
    }

    /** Recorded services plus completed or cancelled Maintenance work, newest first. @return list<array<string,mixed>> */
    private function serviceHistory(Asset $asset): array
    {
        $completions = FleetServiceCompletion::query()->where('asset_id', $asset->id)
            ->with(['schedule:id,name', 'workOrder:id,reference_number,title', 'recordedBy:id,name'])
            ->orderByDesc('completed_on')->orderByDesc('id')->limit(self::HISTORY)->get()
            ->map(fn (FleetServiceCompletion $completion): array => [
                'key' => 'completion-'.$completion->id,
                'kind' => 'service',
                'id' => $completion->id,
                'title' => $completion->schedule?->name ?? 'Service',
                'date' => $completion->completed_on?->toDateString(),
                'odometer_km' => $completion->odometer_km === null ? null : (float) $completion->odometer_km,
                'status' => 'completed',
                'provider' => $completion->provider,
                'evidence_reference' => $completion->evidence_reference,
                'notes' => $completion->notes,
                'reference' => $completion->workOrder?->reference_number,
                'work_order_id' => $completion->work_order_id,
                'recorded_by' => $completion->recordedBy?->name,
            ]);
        $work = FleetWorkOrder::query()->where('asset_id', $asset->id)->whereIn('status', ['completed', 'cancelled'])
            ->orderByDesc(DB::raw('COALESCE(completed_at, updated_at)'))->limit(self::HISTORY)->get();
        $workIds = $work->pluck('id')->all();
        $providers = [];
        $cancelReasons = [];
        if ($workIds !== []) {
            DB::table('fleet_maintenance_actions')->whereIn('work_order_id', $workIds)
                ->whereIn('action_type', ['plan_provider', 'cancel'])->orderBy('id')
                ->get(['work_order_id', 'action_type', 'payload_json'])
                ->each(function (object $action) use (&$providers, &$cancelReasons): void {
                    $payload = json_decode((string) $action->payload_json, true) ?: [];
                    if ($action->action_type === 'plan_provider' && ! empty($payload['provider_name'])) {
                        $providers[(int) $action->work_order_id] = (string) $payload['provider_name'];
                    }
                    if ($action->action_type === 'cancel' && ! empty($payload['reason'])) {
                        $cancelReasons[(int) $action->work_order_id] = (string) $payload['reason'];
                    }
                });
        }
        $workFiles = $workIds === [] ? collect() : DB::table('fleet_maintenance_attachments')->whereIn('work_order_id', $workIds)
            ->groupBy('work_order_id')->selectRaw('work_order_id, COUNT(*) as files')->pluck('files', 'work_order_id');
        $awaiting = $workIds === [] ? [] : DB::table('fleet_maintenance_restrictions')->whereIn('work_order_id', $workIds)
            ->where('state', 'active')->pluck('work_order_id')->map(fn (mixed $id): int => (int) $id)->all();
        $completionFiles = $completions->isEmpty() ? collect() : DB::table('asset_documents')->where('asset_id', $asset->id)
            ->where('source_type', 'service_completion')->whereIn('source_id', $completions->pluck('id'))->whereNull('archived_at')
            ->groupBy('source_id')->selectRaw('source_id, COUNT(*) as files')->pluck('files', 'source_id');
        $completions = $completions->map(fn (array $row): array => $row + ['files' => (int) ($completionFiles[$row['id']] ?? 0), 'awaiting_release' => false]);
        $work = $work->map(fn (FleetWorkOrder $order): array => [
                'key' => 'work-'.$order->id,
                'kind' => 'work',
                'id' => $order->id,
                'title' => $order->title ?: 'Maintenance work',
                'date' => ($order->completed_at ?? $order->updated_at)?->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
                'odometer_km' => null,
                'status' => $order->status,
                'provider' => $providers[$order->id] ?? null,
                'evidence_reference' => null,
                'notes' => $order->status === 'cancelled'
                    ? ($cancelReasons[$order->id] ?? $order->completion_notes)
                    : $order->completion_notes,
                'reference' => $order->reference_number,
                'work_order_id' => $order->id,
                'recorded_by' => null,
                'files' => (int) ($workFiles[$order->id] ?? 0),
                'awaiting_release' => $order->status === 'completed' && in_array((int) $order->id, $awaiting, true),
            ]);

        return $completions->concat($work)->sortByDesc(fn (array $row): string => ($row['date'] ?? '').'#'.$row['key'])
            ->take(self::HISTORY)->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function reminders(Asset $asset): array
    {
        $reminders = FleetVehicleReminder::query()->where('asset_id', $asset->id)
            ->with(['owner:id,name', 'backup:id,name', 'events' => fn ($events) => $events->with('actor:id,name')->orderByDesc('id')->limit(10)])
            ->orderByRaw("FIELD(state, 'scheduled', 'acknowledged', 'paused', 'completed')")->orderBy('due_at')->limit(100)->get();
        $labels = $this->sourceLabels($asset, $reminders);

        return $reminders->map(fn (FleetVehicleReminder $reminder): array => [
            'id' => $reminder->id,
            'title' => $reminder->title,
            'action_text' => $reminder->action_text,
            'source' => ['type' => $reminder->source_type, 'id' => $reminder->source_id,
                'label' => $labels[$reminder->source_type.':'.$reminder->source_id] ?? 'Vehicle'],
            'due_at' => $reminder->due_at?->toIso8601String(),
            'repeat_months' => $reminder->repeat_months,
            'owner' => $reminder->owner ? ['id' => $reminder->owner->id, 'name' => $reminder->owner->name] : null,
            'backup' => $reminder->backup ? ['id' => $reminder->backup->id, 'name' => $reminder->backup->name] : null,
            'state' => $reminder->state,
            'lock_version' => $reminder->lock_version,
            'events' => $reminder->events->map(fn ($event): array => [
                'id' => $event->id, 'action' => $event->action, 'note' => $event->note,
                'actor' => $event->actor?->name, 'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();
    }

    /** @return array<string,string> */
    private function sourceLabels(Asset $asset, Collection $reminders): array
    {
        $labels = [];
        foreach ($reminders->groupBy('source_type') as $type => $group) {
            $ids = $group->pluck('source_id')->filter()->unique()->values();
            $rows = match ($type) {
                'document_set' => AssetDocumentSet::query()->whereIn('id', $ids)->where('asset_id', $asset->id)->pluck('category', 'id'),
                'service_schedule' => FleetServiceSchedule::query()->whereIn('id', $ids)->where('asset_id', $asset->id)->pluck('name', 'id'),
                'compliance_record' => FleetVehicleComplianceRecord::query()->whereIn('id', $ids)->where('asset_id', $asset->id)
                    ->pluck('kind', 'id')->map(fn (string $kind): string => VehicleComplianceService::LABELS[$kind] ?? $kind),
                'work_order' => FleetWorkOrder::query()->whereIn('id', $ids)->where('asset_id', $asset->id)
                    ->get(['id', 'reference_number', 'title'])->mapWithKeys(fn ($order): array => [$order->id => trim(($order->reference_number ?? '').' · '.$order->title, ' ·')]),
                default => collect(),
            };
            foreach ($rows as $id => $label) {
                $labels[$type.':'.$id] = (string) $label;
            }
        }

        return $labels;
    }

    /** Document sets with their files, newest first. Includes source-owned evidence. @return list<array<string,mixed>> */
    /**
     * Files kept with another record of this vehicle, so the document library
     * can follow each one to its source. Booking and Finance files keep their
     * own access rules and are listed only where those records are shown.
     *
     * @param  list<array<string,mixed>>  $documents
     * @return list<array<string,mixed>>
     */
    private function linkedDocuments(Asset $asset, array $documents): array
    {
        $sets = collect($documents)->filter(fn (array $set): bool => in_array($set['source']['type'] ?? null,
            ['compliance_version', 'odometer_observation', 'service_completion', 'service_schedule', 'unavailable_period', 'checklist_run'], true));
        $labels = [];
        foreach ($sets->groupBy(fn (array $set): string => $set['source']['type']) as $type => $group) {
            $ids = $group->map(fn (array $set): int => (int) $set['source']['id'])->unique()->values()->all();
            $rows = match ($type) {
                'compliance_version' => FleetVehicleComplianceVersion::query()->whereIn('id', $ids)
                    ->whereHas('record', fn ($record) => $record->where('asset_id', $asset->id))->with('record:id,kind')->get()
                    ->mapWithKeys(fn (FleetVehicleComplianceVersion $version): array => [$version->id => (VehicleComplianceService::LABELS[$version->record?->kind] ?? 'Compliance').' · version '.$version->version]),
                'odometer_observation' => FleetVehicleOdometerObservation::query()->whereIn('id', $ids)->where('asset_id', $asset->id)
                    ->pluck('value_km', 'id')->map(fn (mixed $km): string => 'Reading · '.number_format((float) $km).' km'),
                'service_completion' => FleetServiceCompletion::query()->whereIn('id', $ids)->where('asset_id', $asset->id)->with('schedule:id,name')->get()
                    ->mapWithKeys(fn (FleetServiceCompletion $completion): array => [$completion->id => ($completion->schedule?->name ?? 'Service').' · completed '.$completion->completed_on?->format('j M Y')]),
                'service_schedule' => FleetServiceSchedule::query()->whereIn('id', $ids)->where('asset_id', $asset->id)->pluck('name', 'id')
                    ->map(fn (string $name): string => 'Service schedule · '.$name),
                'unavailable_period' => FleetVehicleUnavailablePeriod::query()->whereIn('id', $ids)->where('asset_id', $asset->id)->get(['id', 'starts_at'])
                    ->mapWithKeys(fn (FleetVehicleUnavailablePeriod $period): array => [$period->id => 'Unavailable from '.$period->starts_at?->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('j M Y')]),
                'checklist_run' => FleetChecklistRun::query()->whereIn('id', $ids)->where('asset_id', $asset->id)->with('template:id,name')->get()
                    ->mapWithKeys(fn (FleetChecklistRun $run): array => [$run->id => ($run->template?->name ?? 'Vehicle check').' · '.$run->submitted_at?->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('j M Y')]),
                default => collect(),
            };
            foreach ($rows as $id => $label) {
                $labels[$type.':'.$id] = (string) $label;
            }
        }

        // A source that no longer belongs to this vehicle is left out rather than guessed.
        return $sets->filter(fn (array $set): bool => isset($labels[$set['source']['type'].':'.$set['source']['id']]))
            ->map(fn (array $set): array => array_replace($set, ['source' => $set['source'] + ['label' => $labels[$set['source']['type'].':'.$set['source']['id']]]]))
            ->values()->all();
    }

    private function documents(Asset $asset): array
    {
        $sets = AssetDocumentSet::query()->where('asset_id', $asset->id)->with(['files' => fn ($files) => $files->with('uploadedBy:id,name')->orderByDesc('revision')->orderBy('id')])
            ->orderByDesc('id')->limit(200)->get();
        $renewals = FleetVehicleReminder::query()->where('asset_id', $asset->id)->where('source_type', 'document_set')
            ->whereIn('source_id', $sets->pluck('id'))->whereIn('state', ['scheduled', 'acknowledged', 'paused'])
            ->with('owner:id,name')->get()->keyBy('source_id');

        return $sets->map(function (AssetDocumentSet $set) use ($asset, $renewals): array {
            $renewal = $renewals->get($set->id);

            return [
                'id' => $set->id,
                'category' => $set->category,
                'reference' => $set->reference,
                'document_date' => $set->document_date?->toDateString(),
                'expires_on' => $set->expires_on?->toDateString(),
                'current_revision' => (int) $set->current_revision,
                'lock_version' => (int) $set->lock_version,
                'legacy' => (bool) $set->legacy_backfill,
                'source' => $set->source_type ? ['type' => $set->source_type, 'id' => (int) $set->source_id] : null,
                'renewal' => $renewal ? [
                    'id' => $renewal->id, 'due_at' => $renewal->due_at?->toIso8601String(), 'state' => $renewal->state,
                    'owner' => $renewal->owner?->name, 'owner_user_id' => $renewal->owner_user_id,
                    'backup_user_id' => $renewal->backup_user_id, 'lock_version' => $renewal->lock_version,
                ] : null,
                'files' => $set->files->map(fn (AssetDocument $file): array => [
                    'id' => $file->id,
                    'name' => $file->original_name ?: $file->title,
                    'size_bytes' => $file->size_bytes === null ? null : (int) $file->size_bytes,
                    'mime' => $file->detected_mime ?: $file->mime_type,
                    'state' => $file->state,
                    'revision' => (int) ($file->revision ?? 1),
                    'current' => (int) ($file->revision ?? 1) === (int) $set->current_revision && $file->archived_at === null,
                    'archived_at' => $file->archived_at?->toIso8601String(),
                    'archive_reason' => $file->archive_reason,
                    'uploaded_at' => $file->created_at?->toIso8601String(),
                    'uploaded_by' => $file->uploadedBy?->name,
                    'url' => $file->isOpenable()
                        ? route('fleet-assets.vehicles.documents.file', ['asset' => $asset->id, 'document' => $file->id])
                        : null,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array<string,mixed> */
    private function work(User $viewer, Asset $asset): array
    {
        if (! $this->maintenance->canRead($viewer)) {
            return ['can_view' => false, 'open_count' => null, 'open' => [], 'active_restrictions' => 0];
        }
        $open = FleetWorkOrder::query()->where('asset_id', $asset->id)->whereNotIn('status', ['completed', 'cancelled'])
            ->with('assignedTo:id,name')
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id')->limit(50)
            ->get(['id', 'reference_number', 'title', 'status', 'priority', 'due_at', 'assigned_to_user_id', 'next_action']);
        $sources = $this->workSources($open->pluck('id')->all());
        // The latest provider step on each open work order (planned, confirmed, ...).
        $provider = DB::table('fleet_maintenance_actions')->whereIn('work_order_id', $open->pluck('id'))
            ->whereIn('action_type', ['plan_provider', 'record_provider_confirmation', 'record_provider_cancellation', 'record_provider_completion'])
            ->orderBy('id')->get(['work_order_id', 'action_type'])
            ->mapWithKeys(fn (object $action): array => [(int) $action->work_order_id => $action->action_type])->all();
        $held = DB::table('fleet_maintenance_restrictions')->whereIn('work_order_id', $open->pluck('id'))
            ->where('state', 'active')->pluck('work_order_id')->map(fn (mixed $id): int => (int) $id)->all();

        return [
            'can_view' => true,
            'open_count' => FleetWorkOrder::query()->where('asset_id', $asset->id)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'open' => $open->map(fn (FleetWorkOrder $order): array => [
                'id' => $order->id, 'reference' => $order->reference_number, 'title' => $order->title,
                'status' => $order->status, 'priority' => $order->priority, 'due_at' => $order->due_at?->toIso8601String(),
                'owner' => $order->assignedTo?->name,
                'next_action' => $order->next_action,
                'source' => $sources[$order->id] ?? ['label' => 'Maintenance record', 'failed_check' => false],
                'restricted' => in_array((int) $order->id, $held, true),
                'provider_state' => match ($provider[$order->id] ?? null) {
                    'plan_provider' => 'planned', 'record_provider_confirmation' => 'confirmed',
                    'record_provider_completion' => 'completed', 'record_provider_cancellation' => 'cancelled',
                    default => null,
                },
            ])->values()->all(),
            'active_restrictions' => DB::table('fleet_maintenance_restrictions')->where('asset_id', $asset->id)->where('state', 'active')->count(),
            'awaiting_release' => DB::table('fleet_maintenance_restrictions as restriction')
                ->join('fleet_work_orders as work', 'work.id', '=', 'restriction.work_order_id')
                ->where('restriction.asset_id', $asset->id)->where('restriction.state', 'active')
                ->where('work.status', 'completed')->exists(),
            'total' => FleetWorkOrder::query()->where('asset_id', $asset->id)->count(),
        ];
    }

    /**
     * Where each work order came from: its first report's check, or a manual report.
     *
     * @param  list<int>  $workIds
     * @return array<int,array{label:string,failed_check:bool}>
     */
    private function workSources(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }
        $reports = DB::table('fleet_maintenance_reports as report')
            ->leftJoin('fleet_checklist_runs as run', function ($join): void {
                $join->on('run.id', '=', 'report.source_id')->where('report.source_type', 'fleet_checklist_run');
            })
            ->whereIn('report.work_order_id', $workIds)->whereNull('report.duplicate_of_report_id')
            ->orderBy('report.id')
            ->get(['report.work_order_id', 'report.source_type', 'report.source_id', 'run.outcome']);
        // PKG-02B: work created from a Control Room response shows its reference.
        $responses = DB::table('control_room_alerts')
            ->whereIn('id', $reports->where('source_type', 'control_room_alert')->pluck('source_id')->filter()->unique()->values()->all())
            ->pluck('reference_number', 'id');
        $sources = [];
        foreach ($reports as $report) {
            $id = (int) $report->work_order_id;
            if (isset($sources[$id])) {
                continue;
            }
            $sources[$id] = match ($report->source_type) {
                'fleet_checklist_run' => ['label' => 'CHK-'.$report->source_id, 'failed_check' => $report->outcome !== null
                    && ! in_array($report->outcome, ['passed', FleetChecklistRun::OUTCOME_NO_ISSUE], true)],
                'control_room_alert' => ['label' => (string) ($responses[$report->source_id] ?? 'Control Room response'), 'failed_check' => false],
                default => ['label' => 'Manual report', 'failed_check' => false],
            };
        }

        return $sources;
    }

    /**
     * The latest submitted check and, separately, the latest daily check: a
     * daily check is a recorded observation, so it never stands in for the
     * vehicle's check result.
     *
     * @return array<string,mixed>
     */
    private function checks(Asset $asset): array
    {
        if (! Schema::hasTable('fleet_checklist_runs')) {
            return ['latest' => null, 'latest_daily' => null, 'next_due_at' => $asset->inspection_due_at?->toDateString()];
        }
        return [
            'latest' => $this->latestCheck($asset, false),
            'latest_daily' => $this->latestCheck($asset, true),
            'next_due_at' => $asset->inspection_due_at?->toDateString(),
        ];
    }

    /** @return array{id:int, outcome:?string, template:?string, submitted_at:string}|null */
    private function latestCheck(Asset $asset, bool $daily): ?array
    {
        $run = DB::table('fleet_checklist_runs as run')->leftJoin('fleet_checklist_templates as template', 'template.id', '=', 'run.template_id')
            ->where('run.asset_id', $asset->id)->whereNotNull('run.submitted_at')
            ->when($daily,
                fn ($query) => $query->where('run.check_kind', FleetChecklistRun::KIND_DAILY),
                fn ($query) => $query->where(fn ($kind) => $kind->whereNull('run.check_kind')->orWhere('run.check_kind', '!=', FleetChecklistRun::KIND_DAILY)))
            ->orderByDesc('run.submitted_at')->orderByDesc('run.id')
            ->first(['run.id', 'run.outcome', 'run.submitted_at', 'template.name as template_name']);

        return $run ? [
            'id' => (int) $run->id, 'outcome' => $run->outcome, 'template' => $run->template_name,
            'submitted_at' => CarbonImmutable::parse($run->submitted_at, 'UTC')->toIso8601String(),
        ] : null;
    }
}
