<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AssetDocument;
use App\Models\FleetVehicleComplianceRecord;
use App\Models\FleetVehicleComplianceVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Registration, WoF, CoF and RUC evidence. Each save is a new retained
 * version; applicability is recorded, never inferred from vehicle type.
 */
class VehicleComplianceService
{
    public const KINDS = ['registration', 'wof', 'cof', 'ruc'];

    public const LABELS = ['registration' => 'Registration', 'wof' => 'WoF', 'cof' => 'CoF', 'ruc' => 'RUC'];

    public const APPLICABILITY = ['unknown', 'applicable', 'not_applicable'];

    public const OUTCOMES = ['needs_assessment', 'recorded', 'passed', 'failed'];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** @param array<string,mixed> $data */
    public function record(User $actor, int $assetId, string $kind, array $data, string $requestKey, mixed $expectedCurrentVersionId = null): FleetVehicleComplianceVersion
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        return DB::transaction(function () use ($actor, $assetId, $kind, $data, $requestKey, $expectedCurrentVersionId): FleetVehicleComplianceVersion {
            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($currentActor->canDo('fleet.manage'), 403);
            $asset = $this->access->assignableVehicle($currentActor, $assetId, true) ?? abort(404);
            if (trim($requestKey) === '' || mb_strlen($requestKey) > 100) {
                throw ValidationException::withMessages(['request_key' => 'Provide an idempotency key of at most 100 characters.']);
            }
            if ($expectedCurrentVersionId !== null
                && (! filter_var($expectedCurrentVersionId, FILTER_VALIDATE_INT) || (int) $expectedCurrentVersionId < 1)) {
                throw ValidationException::withMessages(['expected_current_version_id' => 'Expected current version must be a positive integer.']);
            }
            $expectedCurrentVersionId = $expectedCurrentVersionId === null ? null : (int) $expectedCurrentVersionId;
            abort_if(($data['source_type'] ?? null) === 'legacy_asset_field', 422, 'Legacy source provenance is reserved for migration.');
            $this->validate($kind, $data);
            $content = collect($data)->only([
                'applicability', 'applicability_basis', 'source_type', 'source_id', 'source_reference',
                'outcome', 'evidence_reference', 'asset_document_id', 'effective_on', 'expires_on',
                'ruc_start_km', 'ruc_end_km', 'observed_at', 'reason',
            ])->map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value)->all();
            $fingerprint = MaintenanceFingerprint::of(['actor_id' => (int) $currentActor->id, 'asset_id' => (int) $asset->id, 'kind' => $kind, 'data' => $content]);
            $record = FleetVehicleComplianceRecord::query()->firstOrCreate(['asset_id' => $asset->id, 'kind' => $kind]);
            $record = FleetVehicleComplianceRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $prior = FleetVehicleComplianceVersion::query()->where('record_id', $record->id)->where('request_key', $requestKey)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_fingerprint, $fingerprint), 409, 'This request was already used for different evidence.');

                return $prior;
            }
            $currentVersionId = $record->current_version_id ? (int) $record->current_version_id : null;
            abort_unless($currentVersionId === $expectedCurrentVersionId, 409, self::LABELS[$kind].' evidence changed while you were editing. Reload before saving another version.');

            $documentId = isset($content['asset_document_id']) ? (int) $content['asset_document_id'] : null;
            if ($documentId && ! AssetDocument::query()->whereKey($documentId)->where('asset_id', $asset->id)->exists()) {
                throw ValidationException::withMessages(['asset_document_id' => 'Choose a document held for this vehicle.']);
            }
            $current = $record->current_version_id
                ? FleetVehicleComplianceVersion::query()->whereKey($record->current_version_id)
                    ->where('record_id', $record->id)->lockForUpdate()->first()
                : null;
            abort_unless(! $record->current_version_id || $current, 409, 'Current compliance evidence provenance is invalid.');
            $version = FleetVehicleComplianceVersion::query()->create([
                ...$content,
                'record_id' => $record->id,
                'version' => ($current?->version ?? 0) + 1,
                'supersedes_version_id' => $current?->id,
                'recorded_by_user_id' => $currentActor->id,
                'document_trust' => $documentId ? 'legacy_unverified' : null,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
                'content_sha256' => MaintenanceFingerprint::of($content),
                'created_at' => now(),
            ]);
            $record->update(['current_version_id' => $version->id]);

            // The legacy Asset date remains a compatibility projection of the
            // last recorded expiry; it never makes an assessment acceptable.
            $legacyField = ['registration' => 'registration_expires_at', 'wof' => 'wof_expires_at', 'cof' => 'cof_expires_at'][$kind] ?? null;
            if ($legacyField && in_array($version->outcome, ['recorded', 'passed'], true) && $version->expires_on) {
                $asset->forceFill([$legacyField => $version->expires_on])->save();
            }

            return $version;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    private function validate(string $kind, array $data): void
    {
        Validator::make($data, [
            'applicability' => ['required', 'in:'.implode(',', self::APPLICABILITY)],
            'applicability_basis' => ['nullable', 'string', 'max:5000'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'outcome' => ['required', 'in:'.implode(',', self::OUTCOMES)],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'asset_document_id' => ['nullable', 'integer', 'min:1'],
            'effective_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'ruc_start_km' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'ruc_end_km' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'observed_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'applicability_basis' => 'basis', 'evidence_reference' => 'evidence reference',
            'effective_on' => 'effective date', 'expires_on' => 'next due / expiry date',
            'ruc_start_km' => 'licence start', 'ruc_end_km' => 'licence end',
        ])->validate();
        $label = self::LABELS[$kind];
        $applicability = $data['applicability'];
        $outcome = $data['outcome'];
        if ($applicability === 'unknown' && $outcome !== 'needs_assessment') {
            throw ValidationException::withMessages(['outcome' => "Decide whether {$label} applies before recording an outcome."]);
        }
        if ($applicability === 'not_applicable') {
            if (trim((string) ($data['applicability_basis'] ?? '')) === '') {
                throw ValidationException::withMessages(['applicability_basis' => "Record why {$label} does not apply to this vehicle."]);
            }
            if (in_array($outcome, ['passed', 'failed'], true)) {
                throw ValidationException::withMessages(['outcome' => 'A not-applicable decision can\'t pass or fail.']);
            }
        }
        if (! empty($data['effective_on']) && ! empty($data['expires_on'])
            && CarbonImmutable::parse($data['effective_on'])->greaterThan(CarbonImmutable::parse($data['expires_on']))) {
            throw ValidationException::withMessages(['expires_on' => 'The next due / expiry date can\'t be before the effective date.']);
        }
        if ($applicability !== 'applicable' || ! in_array($outcome, ['recorded', 'passed', 'failed'], true)) {
            return;
        }
        if (trim((string) ($data['evidence_reference'] ?? '')) === '') {
            throw ValidationException::withMessages(['evidence_reference' => 'Record the certificate, licence or receipt reference.']);
        }
        if ($kind === 'ruc' && in_array($outcome, ['recorded', 'passed'], true)) {
            $low = $data['ruc_start_km'] ?? null;
            $high = $data['ruc_end_km'] ?? null;
            if (! is_numeric($low) || ! is_numeric($high) || (float) $high <= (float) $low) {
                throw ValidationException::withMessages(['ruc_end_km' => 'The licence end must be higher than its start.']);
            }
        } elseif ($kind !== 'ruc' && in_array($outcome, ['recorded', 'passed'], true) && empty($data['expires_on'])) {
            throw ValidationException::withMessages(['expires_on' => 'Record the next due / expiry date.']);
        }
    }
}
