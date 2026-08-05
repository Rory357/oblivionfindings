<?php

namespace App\Domain\SecurityDevices\AccessControl\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class AccessControlLifecycleService
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** @param array<string, mixed> $data */
    public function createSchedule(User $actor, array $data): AccessControlSchedule
    {
        $this->assertCanManage($actor);
        $siteId = (int) $data['site_id'];
        $this->access->assertCanViewSite($actor, $siteId);

        return DB::transaction(function () use ($actor, $data, $siteId): AccessControlSchedule {
            $schedule = AccessControlSchedule::query()->create([
                'site_id' => $siteId,
                'name' => trim((string) $data['name']),
                'timezone' => (string) config('app.worker_timezone', 'Pacific/Auckland'),
                'days' => array_values($data['days']),
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'is_active' => true,
                'created_by_user_id' => $actor->getKey(),
            ]);

            AuditLogger::logOrFail('access_control.schedule.created', $schedule, [
                'actor_id' => (int) $actor->getKey(),
                'site_id' => $siteId,
            ]);

            return $schedule;
        });
    }

    /** @param array<string, mixed> $data */
    public function issueCredential(User $actor, array $data): AccessControlCredential
    {
        $this->assertCanManage($actor);
        $siteId = (int) $data['site_id'];
        $this->access->assertCanViewSite($actor, $siteId);
        $schedule = AccessControlSchedule::query()
            ->whereKey((int) $data['access_schedule_id'])
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->first();
        abort_unless($schedule, 404);

        $this->assertHolderAtSite($actor, (string) $data['holder_type'], (int) $data['holder_id'], $siteId);
        $devices = $this->authorisedAccessDevices($actor, $data['device_ids'], $siteId);

        return DB::transaction(function () use ($actor, $data, $devices, $schedule, $siteId): AccessControlCredential {
            $credential = AccessControlCredential::query()->create([
                'site_id' => $siteId,
                'access_schedule_id' => $schedule->getKey(),
                'label' => trim((string) $data['label']),
                'holder_type' => $data['holder_type'],
                'holder_id' => (int) $data['holder_id'],
                'reference_key' => trim((string) $data['reference_key']),
                'status' => 'active',
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'created_by_user_id' => $actor->getKey(),
            ]);
            $credential->devices()->sync($devices->modelKeys());

            AuditLogger::logOrFail('access_control.credential.issued', $credential, [
                'actor_id' => (int) $actor->getKey(),
                'site_id' => $siteId,
                'device_ids' => $devices->modelKeys(),
            ]);

            return $credential->load(['schedule', 'devices']);
        });
    }

    public function revokeCredential(User $actor, AccessControlCredential $credential, string $reason): AccessControlCredential
    {
        $this->assertCanManage($actor);
        $this->access->assertCanViewSite($actor, (int) $credential->site_id);

        return DB::transaction(function () use ($actor, $credential, $reason): AccessControlCredential {
            $locked = AccessControlCredential::query()->whereKey($credential->getKey())->lockForUpdate()->firstOrFail();
            $this->access->assertCanViewSite($actor, (int) $locked->site_id);

            if ($locked->status === 'revoked') {
                return $locked;
            }

            $locked->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->getKey(),
                'revocation_reason' => trim($reason),
            ])->save();

            AuditLogger::logOrFail('access_control.credential.revoked', $locked, [
                'actor_id' => (int) $actor->getKey(),
                'site_id' => (int) $locked->site_id,
            ]);

            return $locked;
        });
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->canDo('securityDevices.accessControl.manage'), 403);
    }

    private function assertHolderAtSite(User $actor, string $holderType, int $holderId, int $siteId): void
    {
        if ($holderType === AccessControlCredential::HOLDER_CLIENT) {
            $client = $this->access->assignableClient($actor, $holderId);
            abort_unless($client instanceof Client && (int) $client->site_id === $siteId, 404);

            return;
        }

        if ($holderType === AccessControlCredential::HOLDER_STAFF) {
            $staff = $this->access->assignableStaffMember($actor, $holderId);
            $profile = $staff?->hrEmployeeProfile()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
                ->atSite($siteId)
                ->first();
            abort_unless($profile instanceof HrEmployeeProfile, 404);

            return;
        }

        abort(404);
    }

    /** @param array<int, mixed> $deviceIds */
    private function authorisedAccessDevices(User $actor, array $deviceIds, int $siteId): EloquentCollection
    {
        $ids = collect($deviceIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $devices = $this->access->visibleDevices($actor)
            ->whereKey($ids)
            ->where('domain', 'security')
            ->where('category', 'access_control')
            ->with(['assignments' => fn ($query) => $query->active()->where('assigned_at', '<=', now())])
            ->get();

        abort_unless($devices->count() === $ids->count(), 404);
        foreach ($devices as $device) {
            abort_unless($this->effectiveSiteId($device) === $siteId, 404);
        }

        return $devices;
    }

    private function effectiveSiteId(Device $device): ?int
    {
        $assignment = $device->assignments->first();
        if (! $assignment instanceof DeviceAssignment) {
            return null;
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_SITE) {
            return (int) $assignment->assignable_id;
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_ROOM) {
            $siteId = SiteRoom::query()->whereKey($assignment->assignable_id)->value('site_id');

            return is_numeric($siteId) ? (int) $siteId : null;
        }

        return null;
    }
}
