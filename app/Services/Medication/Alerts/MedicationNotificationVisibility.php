<?php

namespace App\Services\Medication\Alerts;

use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAlert;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Notifications\MedicationAlertNotification;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Current privacy checks precede every inbox count, page and direct lookup. */
final class MedicationNotificationVisibility
{
    private const ORDER_EVENTS = ['medication.created', 'medication.updated', 'medication.discontinued'];

    private const ADMINISTRATION_EVENTS = ['medication_administration.created', 'medication_correction_pending_approval.created'];

    private const EMERGENCY_EVENTS = ['break_glass_access.created', 'break_glass_access.ended'];

    private const CLINICAL_EVENTS = [
        ...self::ORDER_EVENTS, ...self::ADMINISTRATION_EVENTS,
        'medication_stock.updated', 'controlled_drug_discrepancy.updated',
    ];

    public function __construct(
        private readonly MedicationGovernanceScopeService $governance,
        private readonly MedicationRecordAccess $records,
        private readonly UserSiteAccessService $sites,
    ) {}

    public function apply(MorphMany $notifications, User $actor): MorphMany
    {
        // Most inboxes have no medication work. Avoid touching clinical
        // sources at all unless this user's retained notices need a gate.
        $protected = $actor->notifications()->where(function (Builder $query): void {
            $query->where('type', MedicationAlertNotification::class)
                ->orWhere('data->type', 'medication_alert')
                ->orWhereIn('data->event_key', [...self::CLINICAL_EVENTS, ...self::EMERGENCY_EVENTS]);
        })->exists();
        if (! $protected) {
            return $notifications;
        }
        $approved = $actor->isApproved();
        $siteIds = $approved && ($actor->canDo('medications.view') || $actor->canDo('medications.administer.record'))
            ? $this->sites->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS)
            : [];
        $clientIds = Client::query()->whereIn('site_id', $siteIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $readable = $actor->canDo('medications.view') ? $this->records->readableClientIds($actor, $clientIds) : [];
        // Retained administration notices keep the existing assignment-bound
        // record-only read contract; it does not widen named alert recipients.
        $eventClients = $approved ? DoseSlotReaderScope::forViewerClients($actor, $clientIds)->clientIds : [];
        $alerts = $this->visibleAlerts($actor, $readable)->select('medication_alerts.id');
        // Subject context is retained in the notice. A corrected source must
        // never lend its current access to a different person's old payload.
        foreach (['client_id', 'site_id', 'staff_user_id'] as $identity) {
            $alerts->where(fn (Builder $binding) => $binding->whereNull('notifications.data->'.$identity)
                ->orWhereColumn('medication_alerts.'.$identity, 'notifications.data->'.$identity));
        }
        $sources = $this->clinicalSources($eventClients ?? [], $actor->canDo('medications.controlled.view'));
        $grants = $this->visibleEmergencyGrants($actor)->select('client_break_glass_accesses.id');

        return $notifications->where(function (Builder $query) use ($alerts, $sources, $grants, $actor): void {
            $query->where(function (Builder $ordinary): void {
                $ordinary->where('type', '!=', MedicationAlertNotification::class)
                    ->where(fn (Builder $data) => $data->whereNull('data->type')->orWhere('data->type', '!=', 'medication_alert'))
                    ->where(fn (Builder $data) => $data->whereNull('data->event_key')->orWhereNotIn('data->event_key', [...self::CLINICAL_EVENTS, ...self::EMERGENCY_EVENTS]));
            })->orWhere(function (Builder $medication) use ($alerts, $actor): void {
                $medication->where(fn (Builder $kind) => $kind->where('type', MedicationAlertNotification::class)->orWhere('data->type', 'medication_alert'))
                    ->whereIn('data->medication_alert_id', $alerts);
                // Retained payloads remain private even if source metadata was corrected.
                if (! $actor->canDo('medications.controlled.view')) {
                    $medication->where(fn (Builder $data) => $data->whereNull('data->controlled')->orWhere('data->controlled', false));
                }
            })->orWhere(function (Builder $emergency) use ($grants): void {
                $emergency->where('type', AppEventNotification::class)->whereIn('data->event_key', self::EMERGENCY_EVENTS)->whereIn('data->access_id', $grants);
            });
            foreach ($sources as $source) {
                $query->orWhere(fn (Builder $clinical) => $clinical
                    ->where('type', AppEventNotification::class)->whereIn('data->event_key', $source['events'])->whereIn('data->entity_id', $source['query']));
            }
        });
    }

    private function visibleAlerts(User $actor, array $readable): Builder
    {
        $query = MedicationAlert::query();
        if (! $actor->isApproved()) {
            return $query->whereRaw('1 = 0');
        }
        $siteIds = $actor->canDo('medications.view')
            ? $this->governance->readerSiteIds($actor, 'medications.view') : [];
        $auditSites = $actor->canDo('medications.audit.view')
            ? $this->sites->accessibleSiteIds($actor, ['medications.audit.view']) : [];
        $query->where(function (Builder $scope) use ($actor, $siteIds, $readable, $auditSites): void {
            // Own competency renewal is nonclinical, unlike a person's record.
            $scope->where(function (Builder $own) use ($actor): void {
                $own->where('type', MedicationAlertCatalogue::RENEWALS)
                    ->where('staff_user_id', $actor->id)->whereNull('client_id')->where('controlled', false);
            })->orWhere(function (Builder $clinical) use ($siteIds, $readable): void {
                $clinical->whereIn('site_id', $siteIds)
                    ->where(fn (Builder $person) => $person->whereNull('client_id')->orWhereIn('client_id', $readable));
            })->orWhere(function (Builder $audit) use ($auditSites): void {
                // Emergency-access reviewers retain their existing audit purpose.
                $audit->whereIn('site_id', $auditSites)
                    ->where(fn (Builder $person) => $person->whereNull('client_id')
                        ->orWhereIn('client_id', Client::query()->whereIn('site_id', $auditSites)->select('id')))
                    ->where(fn (Builder $purpose) => $purpose->where('type', MedicationAlertCatalogue::BREAKGLASS)
                        ->orWhere('subject->emergency_access_review_report', true));
            });
        });
        if (! $actor->canDo('medications.controlled.view')) {
            $query->where('controlled', false);
        }

        return $query;
    }

    private function visibleEmergencyGrants(User $actor): Builder
    {
        $query = ClientBreakGlassAccess::withTrashed();
        $reviewer = $actor->canDo('medications.audit.view');
        if (! $actor->isApproved() || (! $reviewer && ! $actor->canDo('medications.breakglass'))) {
            return $query->whereRaw('1 = 0');
        }
        // Match the existing emergency history reader; an ended grant may be
        // reviewed but never becomes current administration authority here.
        $siteIds = $this->sites->accessibleSiteIds($actor, $reviewer ? ['medications.audit.view'] : []);

        return $query->whereHas('client', fn (Builder $client) => $client->whereIn('site_id', $siteIds))
            ->where(function (Builder $identity): void {
                // Access-ended notices omit client_id; when present it must
                // agree with the canonical grant, never replace its person.
                $identity->whereNull('notifications.data->client_id')
                    ->orWhereColumn('client_break_glass_accesses.client_id', 'notifications.data->client_id');
            });
    }

    /** @return array<int, array{events: array<int, string>, query: Builder}> */
    private function clinicalSources(array $clientIds, bool $controlled): array
    {
        $orders = ClientMedication::withTrashed()->select('client_medications.id')
            ->whereIn('client_medications.client_id', $clientIds)
            ->whereColumn('client_medications.client_id', 'notifications.data->client_id');
        $stock = ClientMedicationStock::query()->select('client_medication_stocks.id')
            ->join('client_medications', 'client_medications.id', '=', 'client_medication_stocks.client_medication_id')
            ->whereIn('client_medications.client_id', $clientIds)
            ->whereColumn('client_medications.client_id', 'notifications.data->client_id');
        $administrations = ClientMedicationAdministration::withTrashed()->select('client_medication_administrations.id')
            ->join('client_medications', 'client_medications.id', '=', 'client_medication_administrations.client_medication_id')
            ->whereColumn('client_medications.client_id', 'client_medication_administrations.client_id')
            ->whereIn('client_medication_administrations.client_id', $clientIds)
            ->whereColumn('client_medication_administrations.client_id', 'notifications.data->client_id');
        $discrepancies = ClientControlledDrugDiscrepancy::query()->select('client_controlled_drug_discrepancies.id')
            ->join('client_medications', 'client_medications.id', '=', 'client_controlled_drug_discrepancies.client_medication_id')
            ->whereColumn('client_medications.client_id', 'client_controlled_drug_discrepancies.client_id')
            ->whereIn('client_controlled_drug_discrepancies.client_id', $controlled ? $clientIds : [])
            ->whereColumn('client_controlled_drug_discrepancies.client_id', 'notifications.data->client_id');
        if (! $controlled) {
            foreach ([$orders, $stock, $administrations] as $source) {
                $source->where(fn (Builder $medication) => $medication->where('client_medications.controlled_drug', false)
                    ->orWhereNull('client_medications.controlled_drug'));
            }
        }

        return [
            ['events' => self::ORDER_EVENTS, 'query' => $orders],
            ['events' => ['medication_stock.updated'], 'query' => $stock],
            ['events' => self::ADMINISTRATION_EVENTS, 'query' => $administrations],
            ['events' => ['controlled_drug_discrepancy.updated'], 'query' => $discrepancies],
        ];
    }
}
