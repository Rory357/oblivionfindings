<?php

namespace App\Services\ControlRoom;

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ControlRoomAlertAccessService
{
    private const BYPASS_PERMISSIONS = ['reports.viewAny'];

    private const DRIFT_MESSAGE = 'Your access changed. The item is still listed, but you can no longer open that Control Room response.';

    public function __construct(
        private UserSiteAccessService $siteAccess,
    ) {}

    public function canList(User $user): bool
    {
        $this->loadPermissionContext($user);

        return $user->canDo('controlRoom.viewAny')
            || $user->canDo('controlRoom.alerts.view')
            || $user->canDo('controlRoom.alerts.manage');
    }

    public function applyVisibleScope(Builder $query, User $user): Builder
    {
        if (! $this->canList($user)) {
            return $query->whereRaw('1 = 0');
        }

        $this->siteAccess->applyAlertScope(
            $query,
            $user,
            self::BYPASS_PERMISSIONS,
        );

        return $this->applyControlledMedicationContentScope($query, $user);
    }

    public function applyReadableScope(Builder $query, User $user): Builder
    {
        if (! $this->canRead($user)) {
            return $query->whereRaw('1 = 0');
        }

        $this->siteAccess->applyAlertScope(
            $query,
            $user,
            self::BYPASS_PERMISSIONS,
        );

        return $this->applyControlledMedicationContentScope($query, $user);
    }

    /**
     * Apply the controlled-medication content boundary to an alert query that
     * already has its route capability and canonical Site scope enforced.
     */
    public function applyControlledMedicationContentScope(Builder $query, User $user): Builder
    {
        $this->loadPermissionContext($user);

        if ($user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            return $query;
        }

        $controlledMarker = 'control_room_alerts.context->normalized_data->controlled_drug';

        return $query->where(function (Builder $content) use ($controlledMarker): void {
            $content->where($controlledMarker, false)
                ->orWhere(function (Builder $legacyOrdinary) use ($controlledMarker): void {
                    $legacyOrdinary->whereNull($controlledMarker);
                    $this->whereDoesNotContainMedication($legacyOrdinary, 'control_room_alerts.source');
                    $this->whereDoesNotContainMedication($legacyOrdinary, 'control_room_alerts.alert_type');
                    $legacyOrdinary
                        ->whereNull('control_room_alerts.context->normalized_data->client_medication_id')
                        ->whereNull('control_room_alerts.context->normalized_data->medication_id')
                        ->where(function (Builder $sourceModule): void {
                            $sourceModule->whereNull(
                                'control_room_alerts.context->normalized_data->source_module',
                            );
                            $this->whereDoesNotContainMedication(
                                $sourceModule,
                                'control_room_alerts.context->normalized_data->source_module',
                                'or',
                            );
                        })
                        ->where(function (Builder $incidentSource): void {
                            $incidentSource->whereNull('control_room_alerts.context->incident_source_type');
                            $this->whereDoesNotContainMedication(
                                $incidentSource,
                                'control_room_alerts.context->incident_source_type',
                                'or',
                            );
                        });
                });
        });
    }

    private function whereDoesNotContainMedication(
        Builder $query,
        string $column,
        string $boolean = 'and',
    ): void {
        $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);

        $query->whereRaw(
            sprintf('LOWER(%s) NOT LIKE ?', $wrappedColumn),
            ['%medication%'],
            $boolean,
        );
    }

    /**
     * Treat unmarked historical medication alerts as controlled until their
     * canonical medication classification can be proved. Explicit false is the
     * only marker that permits ordinary medication content.
     */
    public function requiresControlledMedicationPermission(ControlRoomAlert $alert): bool
    {
        $marker = data_get($alert->context, 'normalized_data.controlled_drug');
        if ($marker === false) {
            return false;
        }
        if ($marker !== null) {
            return true;
        }

        return str_contains(strtolower((string) $alert->source), 'medication')
            || str_contains(strtolower((string) $alert->alert_type), 'medication')
            || str_contains(
                strtolower((string) data_get($alert->context, 'normalized_data.source_module')),
                'medication',
            )
            || str_contains(
                strtolower((string) data_get($alert->context, 'incident_source_type')),
                'medication',
            )
            || data_get($alert->context, 'normalized_data.client_medication_id') !== null
            || data_get($alert->context, 'normalized_data.medication_id') !== null;
    }

    public function findVisible(User $user, int $alertId): ?ControlRoomAlert
    {
        $query = ControlRoomAlert::query()->whereKey($alertId);
        $this->applyReadableScope($query, $user);

        return $query->first();
    }

    public function canView(ControlRoomAlert $alert, User $user): bool
    {
        return $this->findVisible($user, (int) $alert->id) !== null;
    }

    /**
     * Resolve readable alert IDs in one canonical Site-scoped query so other
     * modules can retain safe context without emitting inaccessible links.
     *
     * @param  iterable<ControlRoomAlert>  $alerts
     * @return Collection<int, int>
     */
    public function readableIds(iterable $alerts, User $user): Collection
    {
        $ids = collect($alerts)
            ->map(fn (ControlRoomAlert $alert): int => (int) $alert->id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = ControlRoomAlert::query()->whereKey($ids);
        $this->applyReadableScope($query, $user);

        return $query->pluck('id')->map(fn (mixed $id): int => (int) $id);
    }

    public function canManage(ControlRoomAlert $alert, User $user): bool
    {
        $this->loadPermissionContext($user);

        return $user->canDo('controlRoom.alerts.manage')
            && $this->canView($alert, $user);
    }

    /**
     * Use only for an alert returned by {@see applyVisibleScope()}.
     *
     * @return array{href: string|null, label: string, help: string|null}
     */
    public function destinationForScopedAlert(
        ControlRoomAlert $alert,
        User $user,
        mixed $returnTo = null,
    ): array {
        $this->loadPermissionContext($user);

        if (! $this->canList($user)) {
            return $this->noActionDestination($alert);
        }

        if (! $this->canRead($user)) {
            return $this->noActionDestination($alert);
        }

        return [
            'href' => $this->detailHref($alert, $returnTo),
            'label' => $user->canDo('controlRoom.alerts.manage')
                ? 'Continue Control Room response'
                : 'View alert',
            'help' => null,
        ];
    }

    /**
     * @return array{href: string|null, label: string, help: string|null}
     */
    public function destinationFor(
        ControlRoomAlert $alert,
        User $user,
        mixed $returnTo = null,
    ): array {
        if (! $this->canView($alert, $user)) {
            return $this->noActionDestination($alert);
        }

        return $this->destinationForScopedAlert($alert, $user, $returnTo);
    }

    /**
     * @return array<string, bool>
     */
    public function capabilitiesForScopedAlert(User $user): array
    {
        $this->loadPermissionContext($user);
        $canManage = $user->canDo('controlRoom.alerts.manage');

        return [
            'view' => true,
            'manage' => $canManage,
            'watch' => $canManage,
            'assign' => $user->canDo('controlRoom.alerts.assign'),
            'escalate' => $user->canDo('controlRoom.alerts.escalate'),
            'create' => $user->canDo('controlRoom.alerts.create'),
            'create_incident' => $canManage
                && $user->canDo('incidents.create'),
        ];
    }

    public function assertCanView(
        ControlRoomAlert $alert,
        User $user,
        mixed $returnTo = null,
    ): void {
        // A caller without the alert-read capability is denied at the parent
        // permission boundary. Keep that distinct from Site-scoped object
        // concealment below.
        if (! $this->canRead($user)) {
            throw new HttpException(403, 'You are not authorized to access this Control Room alert.');
        }

        if ($this->canView($alert, $user)) {
            return;
        }

        $validatedReturnTo = RecoverableTaskAuthorizationException::validatedReturnTo($returnTo);
        if ($validatedReturnTo !== null) {
            throw new RecoverableTaskAuthorizationException(
                returnTo: $validatedReturnTo,
                message: self::DRIFT_MESSAGE,
            );
        }

        // The caller may read alerts generally, but this particular object is
        // outside their canonical Site scope. Conceal it like a missing alert.
        throw new HttpException(404, 'Control Room alert not found.');
    }

    /**
     * @return array{href: null, label: string, help: string}
     */
    private function noActionDestination(ControlRoomAlert $alert): array
    {
        $alert->loadMissing('assignedTo:id,name');
        $owner = $alert->assignedTo?->name;

        return [
            'href' => null,
            'label' => 'No action for you',
            'help' => $owner
                ? "This response is owned by {$owner}. Contact a Control Room manager if you need access."
                : 'This response has no current owner. Contact a Control Room manager if you need access.',
        ];
    }

    public function canRead(User $user): bool
    {
        $this->loadPermissionContext($user);

        return $user->canDo('controlRoom.alerts.view')
            || $user->canDo('controlRoom.alerts.manage');
    }

    private function detailHref(ControlRoomAlert $alert, mixed $returnTo): string
    {
        $href = '/control-room/alerts/'.$alert->id;
        $validatedReturnTo = RecoverableTaskAuthorizationException::validatedReturnTo($returnTo);
        if ($validatedReturnTo === null) {
            return $href;
        }

        return $href.'?'.http_build_query([
            'return_to' => $validatedReturnTo,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function loadPermissionContext(User $user): void
    {
        $user->loadMissing([
            'permissionOverrides',
            'roles.permissions',
        ]);
    }
}
