<?php

namespace App\Domain\It\Services;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AssetAssignment;
use App\Models\Identity;
use App\Models\ItProvisioningRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** Safe labels and links only; identity tokens and device secrets are never selected. */
final class ItProvisioningCanonicalTargetService
{
    public function options(User $actor, ItProvisioningRequest $task, string $type): Collection
    {
        if (! app(ItProvisioningAccessService::class)->canManage($actor, $task)) {
            return collect();
        }
        $beneficiary = (int) $task->employeeProfile?->user_id;
        if ($beneficiary < 1) {
            return collect();
        }
        if ($type === 'identity') {
            // Linked sign-in identities have a self-service boundary. Other
            // staff record external work evidence without exposing these rows.
            if ($beneficiary !== (int) $actor->id || ! in_array($task->type, ['account', 'access'], true)) {
                return collect();
            }

            return Identity::query()->where('user_id', $beneficiary)->orderBy('id')->get(['id', 'provider', 'email'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'label' => ucfirst($row->provider).' · '.$row->email,
                    'href' => '/settings/profile', 'type' => 'identity']);
        }
        if ($task->type !== 'equipment') {
            return collect();
        }
        $recovery = in_array($task->action, ['recover', 'revoke'], true);
        if ($type === 'asset_assignment') {
            return AssetAssignment::query()->whereIn('assignee_type', ['staff', 'user', User::class])->where('assignee_id', $beneficiary)
                ->where(fn ($query) => $query->whereNull('released_at')->orWhere('id', $task->canonical_target_type === $type ? $task->canonical_target_id : 0))
                ->with('asset:id,name,asset_tag,site_id,status')->orderBy('id')->get()
                ->filter(fn ($row) => $row->asset && Gate::forUser($actor)->allows('view', $row->asset)
                    && (! $recovery || Gate::forUser($actor)->allows('manageAssignments', $row->asset)))
                ->map(fn ($row) => ['id' => (int) $row->id, 'label' => $row->asset->name.' · '.($row->asset->asset_tag ?: 'Asset assignment').($row->released_at ? ' · Released' : ''),
                    'href' => '/assets/'.$row->asset_id, 'type' => $type])->values();
        }
        if ($type === 'device_assignment') {
            if (! $actor->canDo('securityDevices.devices.view') || ($recovery && ! $actor->canDo('securityDevices.devices.assign'))) {
                return collect();
            }
            $visible = app(SecurityDevicesAccessService::class)->visibleDevices($actor)->select('id');

            return DeviceAssignment::query()->whereIn('device_id', $visible)->where('assignable_type', DeviceAssignment::TARGET_STAFF)->where('assignable_id', $beneficiary)
                ->where(fn ($query) => $query->whereNull('released_at')->orWhere('id', $task->canonical_target_type === $type ? $task->canonical_target_id : 0))
                ->with('device:id,name')->orderBy('id')->get()->map(fn ($row) => ['id' => (int) $row->id,
                    'label' => ($row->device?->name ?: 'Device').' · Staff assignment'.($row->released_at ? ' · Released' : ''),
                    'href' => '/security-devices/devices/'.$row->device_id, 'type' => $type]);
        }

        return collect();
    }

    public function find(User $actor, ItProvisioningRequest $task, ?string $type, ?int $id): ?array
    {
        return $type && $id ? $this->options($actor, $task, $type)->firstWhere('id', $id) : null;
    }
}
