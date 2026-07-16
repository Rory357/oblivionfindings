<?php

namespace App\Services\ControlRoom;

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
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

        return $this->siteAccess->applyAlertScope(
            $query,
            $user,
            self::BYPASS_PERMISSIONS,
        );
    }

    public function applyReadableScope(Builder $query, User $user): Builder
    {
        if (! $this->canRead($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $this->siteAccess->applyAlertScope(
            $query,
            $user,
            self::BYPASS_PERMISSIONS,
        );
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

        throw new HttpException(403, 'You are not authorized to access this Control Room alert.');
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
