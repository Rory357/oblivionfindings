<?php

namespace App\Services\Medication;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
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
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Medication rows on the general audit log (/audit-logs, Settings › Audit
 * log and its CSV, HR's audit view) — EA-020. The eMAR audit (Reports ›
 * Audit, MedicationReportAccess) is the home of medication audit; here a
 * medication row is kept only when:
 *
 * - the reader holds medications.view and medications.audit.view;
 * - the person is at a house the reader may read medication records for
 *   (eMAR Site bypass only) and passes the per-person rule
 *   (ClientPolicy::viewMedications);
 * - controlled-register rows, controlled-medicine rows and aggregate
 *   records only for medications.controlled.view holders (the same split as
 *   MedicationProfileAuditPrivacy).
 *
 * Medication rows with no person (settings, staff authority) need an
 * organisation-wide medication auditor (an eMAR Site-bypass key).
 */
final class MedicationGeneralAuditPrivacy
{
    /** Action prefixes of the medication audit families (ModuleReportController's list). */
    private const ACTION_PATTERNS = ['medication%', 'meds.%', 'emar.%', 'clientmedication%', 'clientcontrolleddrug%', 'controlled_drug%', 'cd.%', 'cd\_%'];

    private const TYPE_PATTERNS = ['%Medication%', '%ControlledDrug%'];

    private const CONTROLLED_ACTION_PATTERNS = ['%controlled%', 'cd.%', 'cd\_%', 'clientcontrolleddrug%'];

    /** Records whose medicine is their client_medication_id. */
    private const LINKED = [
        ClientMedicationAdministration::class => 'client_medication_administrations',
        ClientInrRecord::class => 'client_inr_records',
        MedicationDestruction::class => 'medication_destructions',
        MedicationError::class => 'medication_errors',
        MedicationOrderVersion::class => 'medication_order_versions',
        MedicationPharmacyOrder::class => 'medication_pharmacy_orders',
        MedicationPrescriberOrder::class => 'medication_prescriber_orders',
    ];

    /** Only controlled readers see these (MedicationProfileAuditPrivacy::AGGREGATES and CONTROLLED). */
    private const CONTROLLED_ONLY_TYPES = [
        ClientControlledDrugEntry::class,
        ClientControlledDrugDiscrepancy::class,
        MedicationReview::class,
        MedicationSelfAdminAssessment::class,
        MedicationSyringeDriver::class,
    ];

    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $records,
    ) {}

    public function apply(Builder $query, ?User $viewer): Builder
    {
        $table = $query->getModel()->getTable();
        $auditor = $viewer !== null
            && $viewer->canDo(MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY)
            && $viewer->canDo('medications.audit.view');
        $controlled = $auditor && $viewer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $readable = [];
        $organisationWide = false;
        if ($auditor) {
            $siteIds = $this->scope->readerSiteIds($viewer, 'medications.audit.view');
            $readable = $this->records->readableClientIds(
                $viewer,
                Client::query()->whereIn('site_id', $siteIds === [] ? [0] : $siteIds)->pluck('id'),
            );
            $organisationWide = collect(MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS)
                ->contains(fn (string $permission): bool => $viewer->canDo($permission));
        }

        return $query->where(function (Builder $rows) use ($table, $readable, $controlled, $organisationWide): void {
            $rows->where(fn (Builder $ordinary) => $this->nonMedication($ordinary, $table));
            if ($readable !== []) {
                $rows->orWhere(function (Builder $medication) use ($table, $readable, $controlled): void {
                    $medication->whereIn($table.'.client_id', $readable);
                    if (! $controlled) {
                        $this->withoutControlled($medication, $table);
                    }
                });
            }
            if ($organisationWide) {
                $rows->orWhere(function (Builder $unowned) use ($table, $controlled): void {
                    $unowned->whereNull($table.'.client_id');
                    if (! $controlled) {
                        $this->withoutControlled($unowned, $table);
                    }
                });
            }
        });
    }

    /** Rows that are not medication audit at all (the zip export keeps only these). */
    public function nonMedication(Builder $query, string $table = 'audit_logs'): Builder
    {
        $observation = (new ClinicalObservation)->getMorphClass();

        return $query
            ->where(function (Builder $typed) use ($table): void {
                $typed->whereNull($table.'.auditable_type')->orWhere(function (Builder $type) use ($table): void {
                    foreach (self::TYPE_PATTERNS as $pattern) {
                        $type->where($table.'.auditable_type', 'not like', $pattern);
                    }
                });
            })
            ->where(function (Builder $named) use ($table): void {
                $named->whereNull($table.'.action')->orWhere(function (Builder $action) use ($table): void {
                    foreach (self::ACTION_PATTERNS as $pattern) {
                        $action->where($table.'.action', 'not like', $pattern);
                    }
                });
            })
            // Dose-vitals copies made by eMAR recording are medication evidence.
            ->whereNotExists(fn (QueryBuilder $copies) => $copies
                ->selectRaw('1')
                ->from('clinical_observations')
                ->whereColumn('clinical_observations.id', $table.'.auditable_id')
                ->where($table.'.auditable_type', $observation)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(clinical_observations.data, '$.source')) = ?", ['emar_administration']));
    }

    private function withoutControlled(Builder $query, string $table): void
    {
        $query
            ->where(fn (Builder $typed) => $typed->whereNull($table.'.auditable_type')->orWhereNotIn($table.'.auditable_type', self::CONTROLLED_ONLY_TYPES))
            ->where(function (Builder $named) use ($table): void {
                foreach (self::CONTROLLED_ACTION_PATTERNS as $pattern) {
                    $named->where($table.'.action', 'not like', $pattern);
                }
            })
            // Snapshots keep the controlled classification they were made with.
            ->where(fn (Builder $meta) => $meta->whereNull($table.'.meta')
                ->orWhereRaw('CAST('.$table.'.meta AS CHAR) NOT REGEXP ?', ['"(controlled_drug|is_controlled_drug|is_controlled)": ?(true|1|"1")']))
            ->whereNotExists(fn (QueryBuilder $orders) => $orders
                ->selectRaw('1')
                ->from('client_medications')
                ->whereColumn('client_medications.id', $table.'.auditable_id')
                ->where($table.'.auditable_type', ClientMedication::class)
                ->where('client_medications.controlled_drug', true));

        foreach (self::LINKED as $type => $linkedTable) {
            $query->whereNotExists(fn (QueryBuilder $linked) => $linked
                ->selectRaw('1')
                ->from($linkedTable)
                ->join('client_medications', 'client_medications.id', '=', $linkedTable.'.client_medication_id')
                ->whereColumn($linkedTable.'.id', $table.'.auditable_id')
                ->where($table.'.auditable_type', $type)
                ->where('client_medications.controlled_drug', true));
        }
    }
}
