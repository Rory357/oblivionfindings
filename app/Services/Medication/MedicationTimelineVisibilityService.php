<?php

namespace App\Services\Medication;

use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class MedicationTimelineVisibilityService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Keep non-medication timeline events unchanged while requiring exact
     * medication capability, canonical Client/Site ownership, and durable
     * controlled classification for every medication-linked event.
     *
     * @return Builder<*>
     */
    public function applyVisibleScope(Builder $query, User $viewer): Builder
    {
        $table = $query->getModel()->getTable();
        $administrationType = (new ClientMedicationAdministration)->getMorphClass();
        $medicationType = (new ClientMedication)->getMorphClass();
        $incidentType = (new ClientIncident)->getMorphClass();
        $directMedicationSourceTypes = [$administrationType, $medicationType];
        $canViewMedication = $viewer->canDo(MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY);
        $canViewControlled = $viewer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $siteIds = $this->siteAccess->accessibleSiteIds(
            $viewer,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );

        return $query->where(function (Builder $visible) use (
            $table,
            $directMedicationSourceTypes,
            $administrationType,
            $medicationType,
            $incidentType,
            $canViewMedication,
            $canViewControlled,
            $siteIds,
        ): void {
            $visible->where(function (Builder $ordinary) use (
                $table,
                $directMedicationSourceTypes,
                $incidentType,
            ): void {
                $ordinary->where(function (Builder $source) use (
                    $table,
                    $directMedicationSourceTypes,
                    $incidentType,
                ): void {
                    $source->whereNull($table.'.source_type')
                        ->orWhere(function (Builder $notMedicationSource) use (
                            $table,
                            $directMedicationSourceTypes,
                            $incidentType,
                        ): void {
                            $notMedicationSource
                                ->whereNotIn($table.'.source_type', $directMedicationSourceTypes)
                                ->where(function (Builder $notLinkedIncident) use ($table, $incidentType): void {
                                    $notLinkedIncident
                                        ->where($table.'.source_type', '!=', $incidentType)
                                        ->orWhereNotExists(function (QueryBuilder $error) use ($table): void {
                                            $error->selectRaw('1')
                                                ->from('medication_errors as timeline_linked_errors')
                                                ->whereColumn(
                                                    'timeline_linked_errors.client_incident_id',
                                                    $table.'.source_id',
                                                );
                                        });
                                });
                        });
                })->where(function (Builder $type) use ($table): void {
                    $type->whereNull($table.'.type')
                        ->orWhere($table.'.type', 'not like', 'medication%');
                });
            });

            if (! $canViewMedication) {
                return;
            }

            $visible->orWhere(function (Builder $medicationEvent) use (
                $table,
                $administrationType,
                $medicationType,
                $incidentType,
                $canViewControlled,
                $siteIds,
            ): void {
                $medicationEvent
                    ->whereExists(function (QueryBuilder $client) use ($table, $siteIds): void {
                        $client->selectRaw('1')
                            ->from('clients as timeline_clients')
                            ->whereColumn('timeline_clients.id', $table.'.client_id')
                            ->whereIn('timeline_clients.site_id', $siteIds)
                            ->where(function (QueryBuilder $siteIntegrity) use ($table): void {
                                $siteIntegrity->whereNull($table.'.site_id')
                                    ->orWhereColumn($table.'.site_id', 'timeline_clients.site_id');
                            });
                    })
                    ->where(function (Builder $shiftIntegrity) use ($table): void {
                        $shiftIntegrity->whereNull($table.'.shift_id')
                            ->orWhereExists(function (QueryBuilder $shift) use ($table): void {
                                $shift->selectRaw('1')
                                    ->from('shifts as timeline_shifts')
                                    ->whereColumn('timeline_shifts.id', $table.'.shift_id')
                                    ->whereColumn('timeline_shifts.client_id', $table.'.client_id');
                            });
                    })
                    ->where(function (Builder $source) use (
                        $table,
                        $administrationType,
                        $medicationType,
                        $incidentType,
                        $canViewControlled,
                    ): void {
                        $source->where(function (Builder $administration) use (
                            $table,
                            $administrationType,
                            $canViewControlled,
                        ): void {
                            $administration
                                ->where($table.'.source_type', $administrationType)
                                ->whereExists(function (QueryBuilder $record) use ($table, $canViewControlled): void {
                                    $record->selectRaw('1')
                                        ->from('client_medication_administrations as timeline_administrations')
                                        ->join(
                                            'client_medications as timeline_medications',
                                            'timeline_medications.id',
                                            '=',
                                            'timeline_administrations.client_medication_id',
                                        )
                                        ->whereColumn('timeline_administrations.id', $table.'.source_id')
                                        ->whereColumn('timeline_administrations.client_id', $table.'.client_id')
                                        ->whereColumn('timeline_medications.client_id', $table.'.client_id')
                                        ->when(! $canViewControlled, fn (QueryBuilder $medication): QueryBuilder => $medication
                                            ->where('timeline_medications.controlled_drug', false));
                                });
                        })->orWhere(function (Builder $medication) use (
                            $table,
                            $medicationType,
                            $canViewControlled,
                        ): void {
                            $medication
                                ->where($table.'.source_type', $medicationType)
                                ->whereExists(function (QueryBuilder $record) use ($table, $canViewControlled): void {
                                    $record->selectRaw('1')
                                        ->from('client_medications as timeline_medications')
                                        ->whereColumn('timeline_medications.id', $table.'.source_id')
                                        ->whereColumn('timeline_medications.client_id', $table.'.client_id')
                                        ->when(! $canViewControlled, fn (QueryBuilder $query): QueryBuilder => $query
                                            ->where('timeline_medications.controlled_drug', false));
                                });
                        })->orWhere(function (Builder $incident) use (
                            $table,
                            $incidentType,
                            $canViewControlled,
                        ): void {
                            $incident
                                ->where($table.'.source_type', $incidentType)
                                ->whereExists(function (QueryBuilder $record) use (
                                    $table,
                                    $canViewControlled,
                                ): void {
                                    $record->selectRaw('1')
                                        ->from('client_incidents as timeline_incidents')
                                        ->join(
                                            'clients as timeline_incident_clients',
                                            'timeline_incident_clients.id',
                                            '=',
                                            'timeline_incidents.client_id',
                                        )
                                        ->whereColumn('timeline_incidents.id', $table.'.source_id')
                                        ->whereColumn('timeline_incidents.client_id', $table.'.client_id')
                                        ->whereColumn(
                                            'timeline_incidents.site_id',
                                            'timeline_incident_clients.site_id',
                                        )
                                        ->whereExists(function (QueryBuilder $error): void {
                                            $error->selectRaw('1')
                                                ->from('medication_errors as timeline_incident_errors')
                                                ->join(
                                                    'client_medications as timeline_incident_medications',
                                                    'timeline_incident_medications.id',
                                                    '=',
                                                    'timeline_incident_errors.client_medication_id',
                                                )
                                                ->whereColumn(
                                                    'timeline_incident_errors.client_incident_id',
                                                    'timeline_incidents.id',
                                                )
                                                ->whereColumn(
                                                    'timeline_incident_errors.client_id',
                                                    'timeline_incidents.client_id',
                                                )
                                                ->whereColumn(
                                                    'timeline_incident_medications.client_id',
                                                    'timeline_incidents.client_id',
                                                );
                                        })
                                        ->whereNotExists(function (QueryBuilder $invalid): void {
                                            $invalid->selectRaw('1')
                                                ->from('medication_errors as timeline_invalid_errors')
                                                ->leftJoin(
                                                    'client_medications as timeline_invalid_medications',
                                                    'timeline_invalid_medications.id',
                                                    '=',
                                                    'timeline_invalid_errors.client_medication_id',
                                                )
                                                ->whereColumn(
                                                    'timeline_invalid_errors.client_incident_id',
                                                    'timeline_incidents.id',
                                                )
                                                ->where(function (QueryBuilder $malformed): void {
                                                    $malformed
                                                        ->whereNull('timeline_invalid_errors.client_id')
                                                        ->orWhereColumn(
                                                            'timeline_invalid_errors.client_id',
                                                            '!=',
                                                            'timeline_incidents.client_id',
                                                        )
                                                        ->orWhereNull('timeline_invalid_errors.client_medication_id')
                                                        ->orWhereNull('timeline_invalid_medications.id')
                                                        ->orWhereColumn(
                                                            'timeline_invalid_medications.client_id',
                                                            '!=',
                                                            'timeline_incidents.client_id',
                                                        );
                                                });
                                        })
                                        ->when(! $canViewControlled, function (QueryBuilder $ordinary): void {
                                            $ordinary->whereNotExists(function (QueryBuilder $controlled): void {
                                                $controlled->selectRaw('1')
                                                    ->from('medication_errors as timeline_controlled_errors')
                                                    ->join(
                                                        'client_medications as timeline_controlled_medications',
                                                        'timeline_controlled_medications.id',
                                                        '=',
                                                        'timeline_controlled_errors.client_medication_id',
                                                    )
                                                    ->whereColumn(
                                                        'timeline_controlled_errors.client_incident_id',
                                                        'timeline_incidents.id',
                                                    )
                                                    ->whereColumn(
                                                        'timeline_controlled_errors.client_id',
                                                        'timeline_incidents.client_id',
                                                    )
                                                    ->whereColumn(
                                                        'timeline_controlled_medications.client_id',
                                                        'timeline_incidents.client_id',
                                                    )
                                                    ->where('timeline_controlled_medications.controlled_drug', true);
                                            });
                                        });
                                });
                        });
                    });
            });
        });
    }
}
