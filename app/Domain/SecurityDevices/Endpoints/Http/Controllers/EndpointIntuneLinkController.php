<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Models\IntuneDeviceLink;
use App\Domain\SecurityDevices\Endpoints\Presenters\EndpointDevicePosturePresenter;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EndpointIntuneLinkController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function store(Request $request, Device $device): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'managed_device_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
        ]);
        abort_unless(Schema::hasTable('endpoint_intune_links'), 503);

        $managedDeviceId = strtolower((string) $data['managed_device_id']);
        $reason = isset($data['reason']) && trim((string) $data['reason']) !== ''
            ? trim((string) $data['reason'])
            : null;

        $this->withinAuthorizedDevice($request->user(), $device, function (User $actor, Device $locked) use ($managedDeviceId, $reason): void {
            $duplicate = IntuneDeviceLink::query()
                ->where('managed_device_id', $managedDeviceId)
                ->where('device_id', '!=', $locked->id)
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'managed_device_id' => 'This Microsoft Intune device ID is already linked to another computer.',
                ]);
            }

            $existing = IntuneDeviceLink::query()
                ->where('device_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing && strtolower($existing->managed_device_id) === $managedDeviceId) {
                $existing->linked_by_user_id = $actor->id;
                $existing->save();

                return;
            }

            $previousId = $existing?->managed_device_id;
            $link = $existing ?? new IntuneDeviceLink(['device_id' => $locked->id]);
            $link->fill([
                'managed_device_id' => $managedDeviceId,
                'snapshot' => null,
                'synced_at' => null,
                'last_attempt_at' => null,
                'sync_status' => 'pending',
                'linked_by_user_id' => $actor->id,
            ]);
            $link->save();

            DeviceEvent::query()->create([
                'device_id' => $locked->id,
                'event_type' => 'endpoint_intune_linked',
                'severity' => 'info',
                'source' => 'oblivion_endpoint',
                'occurred_at' => now(),
                'payload' => array_filter([
                    'actor_user_id' => (int) $actor->id,
                    'managed_device_id' => $managedDeviceId,
                    'previous_managed_device_id' => $previousId,
                    'reason' => $reason,
                ], static fn (mixed $value): bool => $value !== null),
            ]);
        });

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'status' => 'linked',
                'managed_device_id' => $managedDeviceId,
            ]);
        }

        return back()->with('success', 'Microsoft Intune device link saved.');
    }

    public function destroy(Request $request, Device $device): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
        ]);
        abort_unless(Schema::hasTable('endpoint_intune_links'), 503);

        $reason = trim((string) $data['reason']);
        abort_if(mb_strlen($reason) < 10, 422, 'Provide a reason of at least 10 characters.');

        $this->withinAuthorizedDevice($request->user(), $device, function (User $actor, Device $locked) use ($reason): void {
            $existing = IntuneDeviceLink::query()
                ->where('device_id', $locked->id)
                ->lockForUpdate()
                ->first();
            abort_if($existing === null, 404);

            $previousId = $existing->managed_device_id;
            $existing->delete();

            DeviceEvent::query()->create([
                'device_id' => $locked->id,
                'event_type' => 'endpoint_intune_unlinked',
                'severity' => 'info',
                'source' => 'oblivion_endpoint',
                'occurred_at' => now(),
                'payload' => [
                    'actor_user_id' => (int) $actor->id,
                    'managed_device_id' => $previousId,
                    'reason' => $reason,
                ],
            ]);
        });

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => 'unlinked']);
        }

        return back()->with('success', 'Microsoft Intune device link removed.');
    }

    private function withinAuthorizedDevice(User $actor, Device $device, Closure $callback): void
    {
        try {
            DB::transaction(function () use ($actor, $device, $callback): void {
                $locked = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
                $current = CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $locked): User {
                    $resolved = $reads->query(User::query())->findOrFail($actor->id);
                    $resolved->load([
                        'permissionOverrides' => fn ($query) => $reads->query($query->getQuery()),
                        'roles' => fn ($query) => $reads->query($query->getQuery()),
                        'roles.permissions' => fn ($query) => $reads->query($query->getQuery()),
                    ]);
                    abort_unless(
                        $resolved->approved_at !== null
                        && $resolved->canDo('securityDevices.viewAny')
                        && $resolved->canDo('securityDevices.devices.view')
                        && $resolved->canDo('securityDevices.devices.update')
                        && ($resolved->canDo('securityDevices.integrations.manage') || $resolved->canDo('securityDevices.commands.admin')),
                        403,
                    );
                    $deviceAccess = $this->access->forCurrentEvidence($reads, (int) $locked->id);
                    $deviceAccess->assertCanViewDevice($resolved, $locked);
                    $deviceAccess->assertCanManageActiveAssignment($resolved, $locked, true);

                    return $resolved;
                });

                abort_unless(
                    EndpointDevicePosturePresenter::isComputer($locked),
                    422,
                    'Only IT endpoints and servers can be linked to Microsoft Intune.',
                );

                $callback($current, $locked);
            });
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw new HttpException(503);
            }

            throw $error;
        }
    }
}
