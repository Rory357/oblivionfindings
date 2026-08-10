<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Data\CommandAuthorizationDecision;
use App\Domain\SecurityDevices\Management\Data\CommandCapabilityDefinition;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One policy decision for every Device management surface and direct path.
 *
 * Oblivion Findings is one application across many Sites. This service never
 * reads legacy partition identity. It composes current application approval,
 * canonical Site/ownership visibility, capability workspace and Device class,
 * source-domain permissions, personal-location privacy, sensitivity, and the
 * ordered Observe/Operate/Manage/Control/Admin permission lattice.
 */
final class DeviceManagementAuthorizationService
{
    /** @var array<string, bool> */
    private array $permissionCache = [];

    /** @var array<string, bool> */
    private array $explicitDenyCache = [];

    /** @var array<string, bool> */
    private array $visibilityCache = [];

    /** @var array<int, Collection<int, DeviceAssignment>> */
    private array $assignmentCache = [];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
    ) {}

    /** Reset request-snapshot memoization for long-lived workers and feature-test kernels. */
    public function resetMemoizedState(): void
    {
        $this->permissionCache = [];
        $this->explicitDenyCache = [];
        $this->visibilityCache = [];
        $this->assignmentCache = [];
    }

    public function evaluate(
        User $actor,
        Device $device,
        CommandCapabilityDefinition $capability,
        ?ManagementLevel $requiredLevel = null,
        bool $fresh = false,
    ): CommandAuthorizationDecision {
        if ($actor->approved_at === null
            || ! $this->canDo($actor, 'securityDevices.devices.view', $fresh)) {
            return $this->deny(
                $capability,
                'application_access_required',
                'Your current application access does not include Device management.',
            );
        }

        if (! $this->deviceIsVisible($actor, $device, $fresh)) {
            return $this->deny(
                $capability,
                'target_not_found',
                'This management target is not available.',
                true,
            );
        }

        if (! in_array((string) $device->domain, $capability->deviceDomains, true)) {
            return $this->deny(
                $capability,
                'workspace_boundary',
                'This management action is not available in the Device workspace.',
                true,
            );
        }

        if ($capability->deviceCategories !== []
            && ! in_array($this->normaliseCategory($device->category), $capability->deviceCategories, true)) {
            return $this->deny(
                $capability,
                'device_class_boundary',
                'This management action is not available for the Device class.',
                true,
            );
        }

        foreach ($capability->requiredPermissions as $permission) {
            if (! $this->canDo($actor, $permission, $fresh)) {
                return $this->deny(
                    $capability,
                    'sensitive_source_permission_required',
                    'This sensitive management action is not available.',
                    true,
                );
            }
        }

        if ($capability->sensitivity === 'personal_location'
            && $this->normaliseCategory($device->category) === 'personal_tracker'
            && ! $this->personalTrackingIsAuthorised($actor, $device, $fresh)) {
            return $this->deny(
                $capability,
                'personal_tracking_privacy_blocked',
                'This personal-location action is not available under the current assignment and privacy state.',
                true,
            );
        }

        $level = $requiredLevel ?? $capability->level;
        if (! $this->allowsLevel($actor, $level, $fresh)) {
            return $this->deny(
                $capability,
                'management_level_required',
                'Your role does not include the required '.ucfirst($level->value).' management level.',
            );
        }

        return new CommandAuthorizationDecision(
            allowed: true,
            code: 'allowed',
            reason: 'Authorised for the current Device, Site, workspace, ownership, and sensitivity context.',
            concealed: false,
            workspace: $capability->domain,
            sensitivity: $capability->sensitivity,
        );
    }

    public function allowsLevel(User $actor, ManagementLevel $required, bool $fresh = false): bool
    {
        if ($this->isExplicitlyDenied($actor, $required->permissionKey(), $fresh)) {
            return false;
        }

        foreach (ManagementLevel::cases() as $candidate) {
            if ($candidate->rank() >= $required->rank()
                && $this->canDo($actor, $candidate->permissionKey(), $fresh)) {
                return true;
            }
        }

        return false;
    }

    private function personalTrackingIsAuthorised(User $actor, Device $device, bool $fresh): bool
    {
        $assignments = $this->activeAssignments($device, $fresh)
            ->filter(fn (DeviceAssignment $assignment): bool => in_array($assignment->assignable_type, [
                DeviceAssignment::TARGET_CLIENT,
                DeviceAssignment::TARGET_STAFF,
            ], true));

        if ($assignments->isEmpty()) {
            return false;
        }

        return $assignments->every(function (DeviceAssignment $assignment) use ($actor): bool {
            if (! $assignment->isCollectionActive()
                || trim((string) $assignment->tracking_purpose) === ''
                || trim((string) $assignment->authority_basis) === '') {
                return false;
            }

            if ($assignment->assignable_type === DeviceAssignment::TARGET_CLIENT
                && ! $this->trackingPrivacy->assignmentAuthorisesClient(
                    $assignment,
                    (int) $assignment->assignable_id,
                )) {
                return false;
            }

            $audience = collect($assignment->access_audience ?? [])
                ->filter(fn (mixed $entry): bool => is_string($entry))
                ->values();

            return $audience->contains('authorised_client_care')
                    && $assignment->assignable_type === DeviceAssignment::TARGET_CLIENT
                || $audience->contains('control_room')
                    && $actor->canDo('controlRoom.viewAny')
                || $audience->contains('health_and_safety')
                    && $actor->canDo('hazards.view');
        });
    }

    /** @return Collection<int, DeviceAssignment> */
    private function activeAssignments(Device $device, bool $fresh): Collection
    {
        if ($fresh || ! isset($this->assignmentCache[(int) $device->id])) {
            $this->assignmentCache[(int) $device->id] = DeviceAssignment::query()
                ->with('consent.consentType')
                ->where('device_id', $device->id)
                ->active()
                ->get();
        }

        return $this->assignmentCache[(int) $device->id];
    }

    private function deviceIsVisible(User $actor, Device $device, bool $fresh): bool
    {
        $key = $actor->id.'|'.$device->id;
        if ($fresh || ! array_key_exists($key, $this->visibilityCache)) {
            $this->visibilityCache[$key] = $this->access
                ->visibleDevices($actor)
                ->whereKey($device->id)
                ->exists();
        }

        return $this->visibilityCache[$key];
    }

    private function canDo(User $actor, string $permission, bool $fresh): bool
    {
        $key = $actor->id.'|'.$permission;
        if ($fresh || ! array_key_exists($key, $this->permissionCache)) {
            $this->permissionCache[$key] = $actor->canDo($permission);
        }

        return $this->permissionCache[$key];
    }

    private function isExplicitlyDenied(User $actor, string $permission, bool $fresh): bool
    {
        $key = $actor->id.'|'.$permission;
        if ($fresh || ! array_key_exists($key, $this->explicitDenyCache)) {
            $this->explicitDenyCache[$key] = $actor->permissionOverrides()
                ->where('permissions.key', $permission)
                ->wherePivot('allowed', false)
                ->exists();
        }

        return $this->explicitDenyCache[$key];
    }

    private function normaliseCategory(mixed $category): string
    {
        $normalised = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $category), '_'));

        return match ($normalised) {
            'networking' => 'network',
            default => $normalised,
        };
    }

    private function deny(
        CommandCapabilityDefinition $capability,
        string $code,
        string $reason,
        bool $concealed = false,
    ): CommandAuthorizationDecision {
        return new CommandAuthorizationDecision(
            allowed: false,
            code: $code,
            reason: $reason,
            concealed: $concealed,
            workspace: $capability->domain,
            sensitivity: $capability->sensitivity,
        );
    }
}
