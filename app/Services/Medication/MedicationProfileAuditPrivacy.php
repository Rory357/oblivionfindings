<?php

namespace App\Services\Medication;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientInrRecord;
use App\Models\MedicationDestruction;
use App\Models\MedicationError;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationReview;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSyringeDriver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Medication evidence on the broader client-profile audit follows the medication person gate. */
final class MedicationProfileAuditPrivacy
{
    private const LINKED = [ClientMedicationAdministration::class, ClientInrRecord::class, MedicationDestruction::class, MedicationError::class, MedicationOrderVersion::class, MedicationPharmacyOrder::class, MedicationPrescriberOrder::class];
    private const AGGREGATES = [MedicationReview::class, MedicationSelfAdminAssessment::class, MedicationSyringeDriver::class];
    private const CONTROLLED = [ClientControlledDrugEntry::class, ClientControlledDrugDiscrepancy::class];

    public function __construct(private readonly MedicationGovernanceScopeService $scope) {}

    public function apply(Builder $query, User $actor, Client $client, bool $redactControlled = false): Builder
    {
        $types = [ClientMedication::class, ...self::LINKED, ...self::AGGREGATES, ...self::CONTROLLED];
        $readable = $actor->canDo('medications.view') && Gate::forUser($actor)->allows('viewMedications', $client);
        $controlled = $redactControlled || $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $allowed = $controlled ? $types : [ClientMedication::class, ...self::LINKED];
        $query->where(function (Builder $rows) use ($types, $allowed, $readable, $controlled, $client) {
            $rows->where(function (Builder $other) use ($types) {
                $other->whereNotIn('auditable_type', $types);
                // Unknown medication aggregates cannot bypass canonical checks.
                foreach (['App\\Models\\Medication', 'App\\Models\\ClientMedication', 'App\\Models\\ClientControlledDrug'] as $prefix) {
                    $other->whereRaw('LEFT(auditable_type, ?) != ?', [strlen($prefix), $prefix]);
                }
            });
            if ($readable) {
                $rows->orWhereHasMorph('auditable', $allowed, function (Builder $record, string $type) use ($controlled, $client) {
                    $record->where('client_id', $client->id);
                    if (in_array($type, self::LINKED, true)) {
                        $this->scope->scopeCanonicalClientMedicationRows($record, [(int) $client->site_id], $type !== ClientMedicationAdministration::class);
                        if (! $controlled) $this->scope->scopeWithoutControlledMedicationRows($record);
                    } elseif ($type === ClientMedication::class && ! $controlled) {
                        $record->where('controlled_drug', false);
                    }
                });
            }
        });

        // Historical dose-vitals copies remain stored. Their medication-linked
        // audit entries need the same canonical owner and CD read permission.
        return $query->where(function (Builder $rows) use ($readable, $controlled, $client) {
            $rows->where('auditable_type', '!=', ClinicalObservation::class)
                ->orWhereHasMorph('auditable', [ClinicalObservation::class], function (Builder $record) use ($readable, $controlled, $client) {
                    $record->where('client_id', $client->id)->where(function (Builder $observations) use ($readable, $controlled, $client) {
                        $observations->whereNull('data->source')->orWhere('data->source', '!=', 'emar_administration');
                        if ($readable) {
                            $observations->orWhere(function (Builder $copies) use ($controlled, $client) {
                                $copies->where('data->source', 'emar_administration')->whereExists(function ($orders) use ($controlled, $client) {
                                    $orders->selectRaw('1')->from('client_medications')->where('client_id', $client->id)->whereRaw("client_medications.id = JSON_UNQUOTE(JSON_EXTRACT(clinical_observations.data, '$.client_medication_id'))");
                                    if (! $controlled) $orders->where('controlled_drug', false);
                                });
                            });
                        }
                    });
                });
        });
    }

    /** Old audit snapshots retain their original controlled classification. */
    public function containsControlledSnapshot(mixed $value): bool
    {
        if (! is_array($value)) return false;
        foreach ($value as $key => $item) {
            if (in_array($key, ['controlled_drug', 'is_controlled_drug', 'is_controlled'], true) && in_array($item, [true, 1, '1'], true)) return true;
            if ($this->containsControlledSnapshot($item)) return true;
        }
        return false;
    }
}
