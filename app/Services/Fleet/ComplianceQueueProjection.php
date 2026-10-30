<?php

namespace App\Services\Fleet;

use App\Models\FleetVehicleComplianceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Read-only requirement rows from the same versions and assessment as Vehicle Profile. */
class ComplianceQueueProjection
{
    public function __construct(private readonly VehicleReadinessService $readiness) {}

    public function rows(Collection $vehicles): Collection
    {
        $assessments = $this->readiness->projections($vehicles);
        $records = FleetVehicleComplianceRecord::query()->whereIn('asset_id', $vehicles->pluck('id'))
            ->with('currentVersion.recordedBy:id,name')->get()->groupBy('asset_id');
        $today = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $soon = CarbonImmutable::parse($today)->addDays(30)->toDateString();

        return $vehicles->flatMap(function ($vehicle) use ($assessments, $records, $today, $soon) {
            $assessment = $assessments[(int) $vehicle->id];
            $site = $vehicle->homeSite ?? $vehicle->site;
            $base = [
                'vehicle' => [
                    'id' => $vehicle->id, 'name' => $vehicle->name, 'asset_tag' => $vehicle->asset_tag,
                    'registration_number' => $vehicle->registration_number,
                    'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
                    'responsible' => $vehicle->fleetResponsible?->name,
                ],
                'assessed_at' => $assessment->assessedAt->toIso8601String(),
                'odometer_km' => $assessment->odometerKm,
            ];
            $byKind = ($records->get($vehicle->id) ?? collect())->keyBy('kind');
            $rows = collect(['wof', 'registration', 'cof', 'ruc'])->map(function ($kind) use ($vehicle, $base, $assessment, $byKind, $today, $soon) {
                $record = $byKind->get($kind);
                $version = $record?->currentVersion;
                // A pointer to another record must never expose that record's evidence.
                if ($version && (int) $version->record_id !== (int) $record->id) {
                    $version = null;
                }
                $reasons = collect($assessment->reasons)->filter(fn ($reason) => $reason->kind === $kind)->values();
                $codes = $reasons->pluck('code');
                $expiry = $version?->expires_on?->toDateString();
                $state = match (true) {
                    ! $version => 'not_recorded',
                    $codes->contains("compliance.{$kind}.failed") => 'failed',
                    $codes->contains("compliance.{$kind}.expired"), $codes->contains('compliance.ruc.coverage_exhausted') => 'expired',
                    $reasons->isNotEmpty() => 'needs_assessment',
                    $version->applicability === 'not_applicable' => 'not_applicable',
                    $expiry && $expiry >= $today && $expiry <= $soon => 'due_soon',
                    default => 'current',
                };
                $action = match (true) {
                    $codes->contains('compliance.ruc.odometer_missing') => 'mileage',
                    $state === 'failed' => 'maintenance',
                    in_array($state, ['current', 'not_applicable'], true) => 'source',
                    default => 'evidence',
                };

                return [...$base,
                    'id' => $vehicle->id.'-'.$kind, 'kind' => $kind, 'label' => VehicleComplianceService::LABELS[$kind],
                    'state' => $state, 'action' => $action, 'record_id' => $record?->id,
                    'applicability' => $version?->applicability ?? 'unknown',
                    'basis' => $version?->applicability_basis,
                    'reason' => $reasons->pluck('message')->implode(' ') ?: ($version?->applicability === 'not_applicable'
                        ? $version->applicability_basis : 'Current recorded evidence. Trip eligibility is checked for the selected use.'),
                    'version' => $version?->version, 'version_id' => $version?->id,
                    'reference' => $version?->evidence_reference ?? $version?->source_reference,
                    'recorded_by' => $version?->recordedBy?->name,
                    'recorded_at' => $version?->created_at?->toIso8601String(),
                    'expires_on' => $expiry, 'effective_on' => $version?->effective_on?->toDateString(),
                    'ruc_start_km' => $version?->ruc_start_km, 'ruc_end_km' => $version?->ruc_end_km,
                ];
            });
            // Insurance is context from the vehicle owner, never an inferred coverage decision.
            $insurance = $vehicle->insurance_expires_at?->toDateString();
            $rows->push([...$base, 'id' => $vehicle->id.'-insurance', 'kind' => 'insurance', 'label' => 'Insurance context',
                'state' => ! $insurance ? 'not_recorded' : ($insurance < $today ? 'expired' : ($insurance <= $soon ? 'due_soon' : 'recorded')),
                'action' => 'documents', 'applicability' => 'unknown', 'basis' => null,
                'reason' => 'Vehicle policy details and documents remain authoritative. A recorded date does not confirm coverage.',
                'reference' => null, 'version' => null, 'version_id' => null, 'record_id' => null,
                'recorded_by' => null, 'recorded_at' => null, 'expires_on' => $insurance, 'effective_on' => null,
                'ruc_start_km' => null, 'ruc_end_km' => null,
            ]);
            $restrictions = collect($assessment->reasons)->filter(fn ($reason) => $reason->kind === null);
            if ($restrictions->isNotEmpty()) {
                $rows->push([...$base, 'id' => $vehicle->id.'-restriction', 'kind' => 'restriction', 'label' => 'Vehicle restrictions',
                    'state' => 'restricted', 'action' => 'maintenance', 'applicability' => 'unknown', 'basis' => null,
                    'reason' => $restrictions->pluck('message')->implode(' '),
                    'reference' => null, 'version' => null, 'version_id' => null, 'record_id' => null,
                    'recorded_by' => null, 'recorded_at' => null, 'expires_on' => null, 'effective_on' => null,
                    'ruc_start_km' => null, 'ruc_end_km' => null,
                ]);
            }

            return $rows;
        })->values();
    }
}
