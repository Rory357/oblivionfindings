<?php

namespace App\Services\Fleet;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleComplianceRecord;
use App\Models\User;
use App\Services\Fleet\Data\VehicleReadinessAssessment;
use App\Services\Fleet\Data\VehicleReadinessContext;
use App\Services\Fleet\Data\VehicleReadinessReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleReadinessService
{
    public function __construct(
        private readonly VehicleOdometerService $odometer,
        private readonly MaintenanceRestrictionService $maintenance,
    ) {}

    public function assess(Asset $asset, ?VehicleReadinessContext $context = null, bool $lock = false): VehicleReadinessAssessment
    {
        $context ??= new VehicleReadinessContext;
        $now = CarbonImmutable::now();
        $workerTimezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $coverageStartsOn = CarbonImmutable::instance($context->startsAt ?? $now)->setTimezone($workerTimezone)->toDateString();
        $coverageEndsOn = CarbonImmutable::instance($context->endsAt ?? $now)->setTimezone($workerTimezone)->toDateString();
        $reasons = [];
        $versions = [];
        $versionInputs = [];
        if ($asset->status !== 'active') $reasons[] = new VehicleReadinessReason('asset.not_active', 'Vehicle is not active.');

        $recordsQuery = FleetVehicleComplianceRecord::query()->where('asset_id', $asset->id)->orderBy('id');
        $records = ($lock ? $recordsQuery->lockForUpdate() : $recordsQuery)->get()->keyBy('kind');
        $current = [];
        foreach (VehicleComplianceService::KINDS as $kind) {
            $record = $records->get($kind);
            $version = $record?->current_version_id
                ? DB::table('fleet_vehicle_compliance_versions')->where('id', $record->current_version_id)
                    ->where('record_id', $record->id)->when($lock, fn ($q) => $q->lockForUpdate())->first()
                : null;
            if (! $version) {
                $reasons[] = new VehicleReadinessReason("compliance.{$kind}.missing", ucfirst($kind).' needs assessment.', 'vehicle', $kind);
                continue;
            }
            $current[$kind] = $version; $versions[$kind] = (int) $version->id;
            $versionInputs[$kind] = [
                'record_id' => (int) $record->id, 'version_id' => (int) $version->id,
                'applicability' => $version->applicability, 'basis' => $version->applicability_basis,
                'outcome' => $version->outcome, 'evidence_reference' => $version->evidence_reference,
                'document_id' => $version->asset_document_id, 'document_trust' => $version->document_trust,
                'effective_on' => $version->effective_on, 'expires_on' => $version->expires_on,
                'ruc_start_km' => $version->ruc_start_km, 'ruc_end_km' => $version->ruc_end_km,
            ];
            if ($version->applicability === 'not_applicable') {
                if (trim((string) $version->applicability_basis) === '') $reasons[] = new VehicleReadinessReason("compliance.{$kind}.basis_missing", ucfirst($kind).' Not applicable basis is missing.', 'vehicle', $kind, (int) $record->id, (int) $version->id);
                continue;
            }
            if ($version->applicability !== 'applicable') {
                $reasons[] = new VehicleReadinessReason("compliance.{$kind}.applicability_unknown", ucfirst($kind).' applicability needs assessment.', 'vehicle', $kind, (int) $record->id, (int) $version->id);
                continue;
            }
            if (! in_array($version->outcome, ['recorded', 'passed'], true)) {
                $reasons[] = new VehicleReadinessReason("compliance.{$kind}.unresolved", ucfirst($kind).' assessment is unresolved.', 'vehicle', $kind, (int) $record->id, (int) $version->id);
                continue;
            }
            if (trim((string) $version->evidence_reference) === '') {
                $reasons[] = new VehicleReadinessReason("compliance.{$kind}.evidence_missing", ucfirst($kind).' evidence is missing.', 'vehicle', $kind, (int) $record->id, (int) $version->id);
            }
            if ($version->effective_on && $version->effective_on > $coverageStartsOn) {
                $reasons[] = new VehicleReadinessReason("compliance.{$kind}.not_effective", ucfirst($kind).' evidence is not effective for this use.', 'vehicle', $kind, (int) $record->id, (int) $version->id);
            }
            if ($kind !== 'ruc') {
                if (! $version->expires_on) $reasons[] = new VehicleReadinessReason("compliance.{$kind}.expiry_missing", ucfirst($kind).' expiry is missing.', 'vehicle', $kind, (int) $record->id, (int) $version->id);
                elseif ($version->expires_on < $coverageEndsOn) $reasons[] = new VehicleReadinessReason("compliance.{$kind}.expired", ucfirst($kind).' evidence does not cover this use.', 'vehicle', $kind, (int) $record->id, (int) $version->id);
            } elseif ($version->expires_on && $version->expires_on < $coverageEndsOn) {
                $reasons[] = new VehicleReadinessReason('compliance.ruc.expired', 'RUC evidence does not cover this use.', 'vehicle', 'ruc', (int) $record->id, (int) $version->id);
            }
        }

        $observation = $this->odometer->currentObserved((int) $asset->id, $lock);
        $odometerKm = $observation ? (float) $observation->value_km : null;
        $ruc = $current['ruc'] ?? null;
        if ($ruc && $ruc->applicability === 'applicable' && in_array($ruc->outcome, ['recorded', 'passed'], true)) {
            $coverageOdometerKm = $context->candidateOdometerKm === null
                ? $odometerKm
                : ($odometerKm === null ? $context->candidateOdometerKm : max($odometerKm, $context->candidateOdometerKm));
            if (! is_numeric($ruc->ruc_start_km) || ! is_numeric($ruc->ruc_end_km) || (float) $ruc->ruc_start_km < 0 || (float) $ruc->ruc_end_km <= (float) $ruc->ruc_start_km) {
                $reasons[] = new VehicleReadinessReason('compliance.ruc.range_invalid', 'RUC licence range is invalid.', 'vehicle', 'ruc', null, (int) $ruc->id);
            } elseif ($coverageOdometerKm === null) {
                $reasons[] = new VehicleReadinessReason('compliance.ruc.odometer_missing', 'Record an observed odometer to check RUC coverage.', 'vehicle', 'ruc', null, (int) $ruc->id);
            } elseif ($coverageOdometerKm < (float) $ruc->ruc_start_km) {
                $reasons[] = new VehicleReadinessReason('compliance.ruc.coverage_not_started', 'Observed odometer is below the recorded RUC licence start.', 'vehicle', 'ruc', $observation?->id, (int) $ruc->id);
            } elseif ($coverageOdometerKm > (float) $ruc->ruc_end_km) {
                $reasons[] = new VehicleReadinessReason('compliance.ruc.coverage_exhausted', 'Observed odometer exceeds the recorded RUC licence end.', 'vehicle', 'ruc', $observation?->id, (int) $ruc->id);
            }
        }
        if ($context->purpose === 'checkout' && $context->candidateOdometerKm === null) {
            $reasons[] = new VehicleReadinessReason('odometer.checkout_missing', 'Checkout requires a current observed odometer.', 'use');
        }

        $blockers = $this->maintenance->blockers((int) $asset->id, $lock, $context->releaseRestrictionIds, $context->resolvedCheckRunIds);
        foreach ($blockers['restriction_ids'] as $id) $reasons[] = new VehicleReadinessReason('maintenance.active_restriction', 'Vehicle remains restricted by maintenance.', 'vehicle', null, $id, null, $context->purpose !== 'maintenance_release');
        foreach ($blockers['check_run_ids'] as $id) $reasons[] = new VehicleReadinessReason('maintenance.unresolved_check', 'A vehicle check needs assessment or repair.', 'vehicle', null, $id, null, $context->purpose !== 'maintenance_release');

        $driverRequired = in_array($context->purpose, ['booking_request', 'booking_confirmation', 'booking_approval', 'checkout'], true);
        if ($driverRequired && ! $context->driverUserId) {
            $reasons[] = new VehicleReadinessReason('driver.missing', 'A current eligible driver is required for this use.', 'driver');
        }
        if ($driverRequired && $context->driverUserId) {
            $driver = User::query()->staff()->whereKey($context->driverUserId)->whereNotNull('approved_at')
                ->when($lock, fn ($q) => $q->lockForUpdate())->first();
            $assetSiteId = $this->assetSiteId($asset);
            $profile = $driver && $assetSiteId
                ? HrEmployeeProfile::query()->where('user_id', $context->driverUserId)->where('is_active', true)
                    ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $coverageStartsOn))
                    ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $coverageEndsOn))
                    ->where(fn ($sites) => $sites->where('primary_site_id', $assetSiteId)
                        ->orWhereJsonContains('secondary_site_ids', $assetSiteId))
                    ->when($lock, fn ($q) => $q->lockForUpdate())->first()
                : null;
            if (! $driver || ! $profile) {
                $reasons[] = new VehicleReadinessReason('driver.not_current_at_site', 'Driver must be current approved staff at the vehicle Site through this use.', 'driver');
            }
            $eligibility = HrDriverEligibility::query()->where('user_id', $context->driverUserId)->where('status', 'eligible')
                ->when($lock, fn ($q) => $q->lockForUpdate())->first();
            $throughDate = CarbonImmutable::instance($context->endsAt ?? $now)->setTimezone($workerTimezone)->toDateString();
            if (! $eligibility || ! $eligibility->licence_expires_at
                || $eligibility->licence_expires_at->toDateString() < $throughDate) {
                $reasons[] = new VehicleReadinessReason('driver.ineligible', 'Driver eligibility is not current through this use.', 'driver');
            }
        }
        if ($context->purpose === 'checkout' && $context->endsAt && ! $context->endsAt->greaterThan($now)) {
            $reasons[] = new VehicleReadinessReason('booking.period_expired', 'This booking has ended and cannot be checked out.', 'booking');
        }
        if ($context->isUseDecision() && $context->startsAt && $context->endsAt) {
            $custody = FleetVehicleBooking::query()->where('asset_id', $asset->id)->where('status', 'checked_out')
                ->when($context->bookingId, fn ($q) => $q->where('id', '!=', $context->bookingId))->orderBy('id');
            $custodyIds = ($lock ? $custody->lockForUpdate() : $custody)->pluck('id');
            if ($custodyIds->isNotEmpty()) {
                $reasons[] = new VehicleReadinessReason('booking.checked_out_custody', 'Vehicle is currently checked out under another booking.', 'booking');
            }
            $conflicts = FleetVehicleBooking::query()->where('asset_id', $asset->id)
                ->whereIn('status', ['pending', 'approved'])->when($context->bookingId, fn ($q) => $q->where('id', '!=', $context->bookingId))
                ->where('starts_at', '<', $context->endsAt)->where('ends_at', '>', $context->startsAt)->orderBy('id');
            $ids = ($lock ? $conflicts->lockForUpdate() : $conflicts)->pluck('id');
            if ($ids->isNotEmpty()) $reasons[] = new VehicleReadinessReason('booking.conflict', 'Vehicle has a conflicting booking or unavailable period.', 'booking');
        }

        $blocking = array_values(array_filter($reasons, fn (VehicleReadinessReason $r) => $r->blocksDecision));
        $payload = [
            'asset_id' => (int) $asset->id, 'asset_status' => $asset->status,
            'context' => [
                'purpose' => $context->purpose, 'driver_user_id' => $context->driverUserId,
                'starts_at' => $context->startsAt?->toIso8601String(), 'ends_at' => $context->endsAt?->toIso8601String(),
                'booking_id' => $context->bookingId, 'candidate_odometer_km' => $context->candidateOdometerKm,
                'release_restriction_ids' => $context->releaseRestrictionIds,
                'resolved_check_run_ids' => $context->resolvedCheckRunIds,
            ],
            'versions' => $versionInputs,
            'odometer' => $observation ? [
                'id' => (int) $observation->id, 'value_km' => (string) $observation->value_km,
                'observed_at' => $observation->observed_at?->toIso8601String(), 'source_kind' => $observation->source_kind,
            ] : null,
            'restriction_ids' => $blockers['restriction_ids'], 'check_run_ids' => $blockers['check_run_ids'],
            'reasons' => array_map(fn ($r) => $r->toArray(), $reasons),
        ];
        return new VehicleReadinessAssessment($blocking === [] ? 'ready' : 'blocked', $blocking === [], $reasons, $now,
            $versions, $observation?->id, $odometerKm, $this->odometer->latestTrackerEstimate((int) $asset->id),
            $blockers['restriction_ids'], $blockers['check_run_ids'], MaintenanceFingerprint::of($payload));
    }

    public function assertCanProceed(VehicleReadinessAssessment $assessment, string $field = 'asset_id'): void
    {
        if (! $assessment->canProceed) throw ValidationException::withMessages([$field => $assessment->reasons[0]->message]);
    }

    private function assetSiteId(Asset $asset): ?int
    {
        if ($asset->site_id || $asset->home_site_id) {
            return (int) ($asset->site_id ?: $asset->home_site_id);
        }

        return $asset->client_id
            ? (int) (DB::table('clients')->where('id', $asset->client_id)->value('site_id') ?: 0) ?: null
            : null;
    }
}
