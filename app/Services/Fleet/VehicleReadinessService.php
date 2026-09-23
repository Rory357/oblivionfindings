<?php

namespace App\Services\Fleet;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\User;
use App\Services\Fleet\Data\VehicleReadinessAssessment;
use App\Services\Fleet\Data\VehicleReadinessContext;
use App\Services\Fleet\Data\VehicleReadinessReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one readiness assessment behind the vehicle header, lists, calendar and
 * every use decision (booking confirmation, approval, checkout and
 * maintenance release). Recorded evidence only: no legal applicability is
 * inferred from fuel or vehicle type, and unknown stays unknown.
 */
class VehicleReadinessService
{
    public function __construct(
        private readonly VehicleOdometerService $odometer,
        private readonly MaintenanceRestrictionService $maintenance,
    ) {}

    public function assess(Asset $asset, ?VehicleReadinessContext $context = null, bool $lock = false): VehicleReadinessAssessment
    {
        $context ??= new VehicleReadinessContext;
        $assetId = (int) $asset->id;

        return $this->evaluate(
            $asset,
            $context,
            $this->currentVersions([$assetId], $lock)[$assetId] ?? [],
            $this->odometer->currentObserved($assetId, $lock),
            $this->maintenance->blockers($assetId, $lock, $context->releaseRestrictionIds, $context->resolvedCheckRunIds),
            $this->odometer->latestTrackerEstimate($assetId),
            $lock,
        );
    }

    /**
     * Header-style projections for list pages in a fixed number of queries.
     * Use decisions never rely on these: they call assess() under their locks.
     *
     * @param  iterable<Asset>  $assets
     * @return array<int, VehicleReadinessAssessment>
     */
    public function projections(iterable $assets): array
    {
        $assets = collect($assets)->keyBy(fn (Asset $asset): int => (int) $asset->id);
        if ($assets->isEmpty()) {
            return [];
        }
        $ids = $assets->keys()->all();
        $versions = $this->currentVersions($ids);
        $observations = $this->odometer->currentObservedMany($ids);
        $blockers = $this->maintenance->blockersMany($ids);
        $context = new VehicleReadinessContext;

        return $assets->map(fn (Asset $asset, int $id): VehicleReadinessAssessment => $this->evaluate(
            $asset, $context, $versions[$id] ?? [], $observations[$id] ?? null,
            $blockers[$id] ?? ['restriction_ids' => [], 'check_run_ids' => []], null, false,
        ))->all();
    }

    public function assertCanProceed(VehicleReadinessAssessment $assessment, string $field = 'asset_id'): void
    {
        $blocking = $assessment->blockingReasons();
        if ($blocking !== []) {
            throw ValidationException::withMessages([$field => $blocking[0]->message]);
        }
    }

    /**
     * Current compliance versions keyed by Asset and kind. Lock order is
     * records, then versions, matching VehicleComplianceService::record.
     *
     * @param  list<int>  $assetIds
     * @return array<int, array<string, array{record: object, version: ?object}>>
     */
    private function currentVersions(array $assetIds, bool $lock = false): array
    {
        $records = DB::table('fleet_vehicle_compliance_records')->whereIn('asset_id', $assetIds)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $versionIds = $records->pluck('current_version_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $versions = $versionIds === [] ? collect() : DB::table('fleet_vehicle_compliance_versions')
            ->whereIn('id', $versionIds)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()->keyBy('id');

        $current = [];
        foreach ($records as $record) {
            $version = $versions->get((int) $record->current_version_id);
            $current[(int) $record->asset_id][$record->kind] = [
                'record' => $record,
                'version' => $version && (int) $version->record_id === (int) $record->id ? $version : null,
            ];
        }

        return $current;
    }

    /**
     * @param  array<string, array{record: object, version: ?object}>  $versions
     * @param  array{restriction_ids: list<int>, check_run_ids: list<int>}  $blockers
     * @param  array<string,mixed>|null  $trackerEstimate
     */
    private function evaluate(
        Asset $asset,
        VehicleReadinessContext $context,
        array $versions,
        ?FleetVehicleOdometerObservation $observation,
        array $blockers,
        ?array $trackerEstimate,
        bool $lock,
    ): VehicleReadinessAssessment {
        $now = CarbonImmutable::now();
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $today = $now->setTimezone($zone)->toDateString();
        $useStartsOn = CarbonImmutable::instance($context->startsAt ?? $now)->setTimezone($zone)->toDateString();
        $useEndsOn = max($today, CarbonImmutable::instance($context->endsAt ?? $now)->setTimezone($zone)->toDateString());
        $reasons = [];
        $versionIds = [];
        $versionInputs = [];

        if ($asset->status !== 'active') {
            $reasons[] = new VehicleReadinessReason('asset.not_active', 'Vehicle is not active.');
        }

        foreach (VehicleComplianceService::KINDS as $kind) {
            $label = VehicleComplianceService::LABELS[$kind];
            $record = $versions[$kind]['record'] ?? null;
            $version = $versions[$kind]['version'] ?? null;
            if (! $record || ! $version) {
                $reasons[] = new VehicleReadinessReason("compliance.{$kind}.missing", "{$label}: assess applicability.", 'vehicle', $kind);

                continue;
            }
            $recordId = (int) $record->id;
            $versionId = (int) $version->id;
            $versionIds[$kind] = $versionId;
            $versionInputs[$kind] = [
                'record_id' => $recordId, 'version_id' => $versionId,
                'applicability' => $version->applicability, 'basis' => $version->applicability_basis,
                'outcome' => $version->outcome, 'evidence_reference' => $version->evidence_reference,
                'document_id' => $version->asset_document_id, 'document_trust' => $version->document_trust,
                'effective_on' => $version->effective_on, 'expires_on' => $version->expires_on,
                'ruc_start_km' => $version->ruc_start_km, 'ruc_end_km' => $version->ruc_end_km,
            ];
            $reason = fn (string $code, string $message): VehicleReadinessReason => new VehicleReadinessReason(
                "compliance.{$kind}.{$code}", "{$label}: {$message}", 'vehicle', $kind, $recordId, $versionId,
            );

            if ($version->applicability === 'not_applicable') {
                if (trim((string) $version->applicability_basis) === '') {
                    $reasons[] = $reason('basis_missing', 'record the not-applicable basis.');
                }

                continue;
            }
            if ($version->applicability !== 'applicable') {
                $reasons[] = $reason('applicability_unknown', 'assess applicability.');

                continue;
            }
            if ($version->outcome === 'failed') {
                $reasons[] = $reason('failed', 'failed evidence requires review.');

                continue;
            }
            if (! in_array($version->outcome, ['recorded', 'passed'], true)) {
                $reasons[] = $reason('unresolved', 'assessment is unresolved.');

                continue;
            }
            if (trim((string) $version->evidence_reference) === '') {
                $reasons[] = $reason('evidence_missing', 'evidence is missing.');
            }
            if ($version->effective_on && $version->effective_on > $useStartsOn) {
                $reasons[] = $reason('not_effective', 'recorded evidence is not in effect until '.self::date($version->effective_on).'.');
            }
            if ($kind !== 'ruc' && ! $version->expires_on) {
                $reasons[] = $reason('expiry_missing', 'record the next due / expiry date.');
            } elseif ($version->expires_on && $version->expires_on < $today) {
                $reasons[] = $reason('expired', 'recorded evidence has expired.');
            } elseif ($version->expires_on && $version->expires_on < $useEndsOn) {
                $reasons[] = $reason('expired', 'recorded evidence expires on '.self::date($version->expires_on).', before this use ends.');
            }
        }

        $observedKm = $observation ? (float) $observation->value_km : null;
        $ruc = $versions['ruc']['version'] ?? null;
        if ($ruc && $ruc->applicability === 'applicable' && in_array($ruc->outcome, ['recorded', 'passed'], true)) {
            $candidate = $context->candidateOdometerKm;
            [$coverageKm, $source] = $candidate !== null && ($observedKm === null || $candidate > $observedKm)
                ? [$candidate, 'checkout reading']
                : [$observedKm, 'recorded odometer'];
            $low = is_numeric($ruc->ruc_start_km) ? (float) $ruc->ruc_start_km : null;
            $high = is_numeric($ruc->ruc_end_km) ? (float) $ruc->ruc_end_km : null;
            $rucReason = fn (string $code, string $message): VehicleReadinessReason => new VehicleReadinessReason(
                "compliance.ruc.{$code}", "RUC: {$message}", 'vehicle', 'ruc', $observation?->id, (int) $ruc->id,
            );
            if ($low === null || $high === null || $low < 0 || $high <= $low) {
                $reasons[] = $rucReason('range_invalid', 'record a valid licence range.');
            } elseif ($coverageKm === null) {
                $reasons[] = $rucReason('odometer_missing', 'record an odometer reading to check coverage.');
            } elseif ($coverageKm < $low) {
                $reasons[] = $rucReason('coverage_not_started', "{$source} ".self::km($coverageKm).' is below the recorded licence start '.self::km($low).'.');
            } elseif ($coverageKm > $high) {
                // Retains the approved strict upper-bound comparison; no statutory threshold is implied.
                $reasons[] = $rucReason('coverage_exhausted', "{$source} ".self::km($coverageKm).' exceeds the recorded licence end '.self::km($high).'. Review coverage before vehicle use.');
            }
        }
        if ($context->purpose === 'checkout' && $context->candidateOdometerKm === null) {
            $reasons[] = new VehicleReadinessReason('odometer.checkout_missing', 'Record the odometer reading at checkout.', 'use');
        }

        $releasing = $context->purpose === 'maintenance_release';
        foreach ($blockers['restriction_ids'] as $id) {
            $reasons[] = new VehicleReadinessReason('maintenance.active_restriction', 'A maintenance hold is active until an authorised release.', 'vehicle', null, $id, null, ! $releasing);
        }
        foreach ($blockers['check_run_ids'] as $id) {
            $reasons[] = new VehicleReadinessReason('maintenance.unresolved_check', 'A vehicle check needs assessment or repair.', 'vehicle', null, $id, null, ! $releasing);
        }

        if (in_array($context->purpose, ['booking_request', 'booking_confirmation', 'booking_approval', 'checkout'], true)) {
            array_push($reasons, ...$this->driverReasons($asset, $context, $useStartsOn, $useEndsOn, $lock));
        }
        if ($context->purpose === 'checkout' && $context->endsAt && ! $context->endsAt->greaterThan($now)) {
            $reasons[] = new VehicleReadinessReason('booking.period_expired', 'This booking has ended and can\'t be checked out.', 'booking');
        }
        if ($context->isUseDecision() && $context->startsAt && $context->endsAt) {
            array_push($reasons, ...$this->bookingReasons($asset, $context, $now, $lock));
        }

        $blocking = array_values(array_filter($reasons, fn (VehicleReadinessReason $reason): bool => $reason->blocksDecision));
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
            'reasons' => array_map(fn (VehicleReadinessReason $reason): array => $reason->toArray(), $reasons),
        ];

        return new VehicleReadinessAssessment(
            $blocking === [] ? 'ready' : 'blocked', $blocking === [], $reasons, $now, $versionIds,
            $observation?->id, $observedKm, $trackerEstimate, $blockers['restriction_ids'],
            $blockers['check_run_ids'], MaintenanceFingerprint::of($payload),
        );
    }

    /** @return list<VehicleReadinessReason> */
    private function driverReasons(Asset $asset, VehicleReadinessContext $context, string $useStartsOn, string $useEndsOn, bool $lock): array
    {
        if (! $context->driverUserId) {
            return [new VehicleReadinessReason('driver.missing', 'Choose a current eligible driver.', 'driver')];
        }
        $reasons = [];
        $driver = User::query()->staff()->whereKey($context->driverUserId)->whereNotNull('approved_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $siteId = $this->assetSiteId($asset);
        $profile = $driver && $siteId
            ? HrEmployeeProfile::query()->where('user_id', $context->driverUserId)->where('is_active', true)
                ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $useStartsOn))
                ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $useEndsOn))
                ->where(fn ($sites) => $sites->where('primary_site_id', $siteId)->orWhereJsonContains('secondary_site_ids', $siteId))
                ->when($lock, fn ($query) => $query->lockForUpdate())->first()
            : null;
        if (! $driver || ! $profile) {
            $reasons[] = new VehicleReadinessReason('driver.not_current_at_site', 'The driver isn\'t current staff at this vehicle\'s site for the whole booking.', 'driver');
        }
        $eligibility = HrDriverEligibility::query()->where('user_id', $context->driverUserId)->where('status', 'eligible')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $eligibility || ! $eligibility->licence_expires_at || $eligibility->licence_expires_at->toDateString() < $useEndsOn) {
            $reasons[] = new VehicleReadinessReason('driver.ineligible', 'The driver\'s licence eligibility isn\'t current through the end of this booking.', 'driver');
        }

        return $reasons;
    }

    /** @return list<VehicleReadinessReason> */
    private function bookingReasons(Asset $asset, VehicleReadinessContext $context, CarbonImmutable $now, bool $lock): array
    {
        $reasons = [];
        // Custody blocks checkout outright. For later use it blocks only when
        // the other booking's period overlaps; an overdue return is treated as
        // still in progress, because the vehicle has not come back.
        $custody = FleetVehicleBooking::query()->where('asset_id', $asset->id)->where('status', 'checked_out')
            ->when($context->bookingId, fn ($query) => $query->whereKeyNot($context->bookingId))
            ->when($context->purpose !== 'checkout', fn ($query) => $query
                ->where('starts_at', '<', $context->endsAt)
                ->when($context->startsAt->greaterThan($now), fn ($overlap) => $overlap->where('ends_at', '>', $context->startsAt)))
            ->orderBy('id');
        if (($lock ? $custody->lockForUpdate() : $custody)->pluck('id')->isNotEmpty()) {
            $reasons[] = new VehicleReadinessReason('booking.checked_out_custody', 'The vehicle is checked out under another booking at this time.', 'booking');
        }
        // A request competes with other requests and confirmed bookings. A
        // decision (approval, confirmation, checkout) competes only with
        // confirmed bookings: a waiting request never blocks deciding this one.
        $competing = $context->purpose === 'booking_request' ? ['pending', 'approved'] : ['approved'];
        $conflicts = FleetVehicleBooking::query()->where('asset_id', $asset->id)->whereIn('status', $competing)
            ->when($context->bookingId, fn ($query) => $query->whereKeyNot($context->bookingId))
            ->where('starts_at', '<', $context->endsAt)->where('ends_at', '>', $context->startsAt)->orderBy('id');
        if (($lock ? $conflicts->lockForUpdate() : $conflicts)->pluck('id')->isNotEmpty()) {
            $reasons[] = new VehicleReadinessReason('booking.conflict', 'The vehicle has another booking or unavailable period at this time.', 'booking');
        }

        return $reasons;
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

    private static function km(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.').' km';
    }

    private static function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('j M Y');
    }
}
