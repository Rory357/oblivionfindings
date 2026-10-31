<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EndpointRestartPolicy
{
    public const MANAGE = 'securityDevices.endpoints.restartPolicyManage';

    public const SERVICE = 'securityDevices.endpoints.restartSchedule';

    public const UNATTENDED = 'securityDevices.endpoints.restartUnattended';

    public static function defaults(): array
    {
        return ['version' => 0, 'enabled' => false, 'consent_mode' => 'attended', 'minimum_warning_seconds' => 60];
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('endpoint_restart_policies') && Schema::hasColumns('endpoint_agents', ['restart_actions', 'restart_observations'])
            && Schema::hasColumns('endpoint_deliveries', ['encrypted_claim', 'claim_expires_at', 'result_hash', 'restart_readback', 'readback_sequence', 'readback_received_at', 'cancel_requested_at', 'encrypted_cancellation']);
    }

    public function available(): bool
    {
        if (! app(EndpointRemoteSupportSettings::class)->endpointEnabled() || ! config('endpoint_management.restart.release_enabled') || ! $this->schemaReady()) {
            return false;
        }
        try {
            app(EndpointCommandSigner::class)->publicKey();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Caller owns canonical Device mutex. */
    public function current(Device $device): array
    {
        if (! Schema::hasTable('endpoint_restart_policies')) {
            return self::defaults();
        }
        $row = DB::table('endpoint_restart_policies')->where('device_id', $device->id)->lockForUpdate()->first();

        return $row ? ['version' => (int) $row->version, 'enabled' => (bool) $row->enabled, 'consent_mode' => $row->consent_mode, 'minimum_warning_seconds' => (int) $row->minimum_warning_seconds] : self::defaults();
    }

    public function show(User $actor, Device $device, array $input): array
    {
        $data = Validator::make($input, ['expected_actor_id' => ['required', 'integer', 'min:1']])->validate();
        abort_if(array_diff(array_keys($input), ['expected_actor_id']) !== [], 422);

        return $this->within($actor, $device, function (User $current, Device $locked) use ($data): array {
            abort_unless((int) $data['expected_actor_id'] === (int) $current->id, 409);
            abort_unless($current->canDo(self::MANAGE) || $current->canDo(self::SERVICE), 403);

            return $this->dto($current, $locked);
        });
    }

    public function save(User $actor, Device $device, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['expected_actor_id', 'expected_version', 'enabled', 'consent_mode', 'minimum_warning_seconds', 'reason']) !== [], 422);
        $data = Validator::make($input, [
            'expected_actor_id' => ['required', 'integer:strict', 'min:1'], 'expected_version' => ['required', 'integer:strict', 'min:0'],
            'enabled' => ['required', 'boolean:strict'], 'consent_mode' => ['required', 'in:attended,unattended'],
            'minimum_warning_seconds' => ['required', 'integer:strict', 'min:60', 'max:3600'],
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
        ])->validate();
        abort_unless($this->schemaReady(), 503);

        return $this->within($actor, $device, function (User $current, Device $locked) use ($data): array {
            abort_unless($current->canDo(self::MANAGE), 403);
            abort_unless((int) $data['expected_actor_id'] === (int) $current->id, 409);
            $before = $this->current($locked);
            abort_unless($before['version'] === $data['expected_version'], 409);
            abort_unless(! $data['enabled'] || $data['consent_mode'] !== 'unattended' || $current->canDo(self::UNATTENDED), 403);
            $values = ['version' => $before['version'] + 1, 'enabled' => $data['enabled'], 'consent_mode' => $data['consent_mode'], 'minimum_warning_seconds' => $data['minimum_warning_seconds'], 'updated_by_user_id' => $current->id, 'updated_at' => now()];
            DB::table('endpoint_restart_policies')->updateOrInsert(['device_id' => $locked->id], $values + ($before['version'] === 0 ? ['created_at' => now()] : []));
            DeviceEvent::query()->create(['device_id' => $locked->id, 'event_type' => 'endpoint_restart_policy_changed', 'severity' => 'info', 'source' => 'oblivion_endpoint', 'occurred_at' => now(), 'payload' => ['actor_user_id' => $current->id, 'version' => $values['version'], 'enabled' => $values['enabled'], 'consent_mode' => $values['consent_mode'], 'reason' => $data['reason']]]);

            return $this->dto($current, $locked);
        });
    }

    private function within(User $actor, Device $device, Closure $callback): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $device, $callback): mixed {
                $current = app(ObliStaffAccess::class)->actor((int) $actor->id);
                $locked = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
                $current = app(EndpointRemoteAccess::class)->actor((int) $current->id, $locked, 'securityDevices.devices.view');

                return $callback($current, $locked);
            });
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw new HttpException(503);
            }
            throw $error;
        }
    }

    private function dto(User $actor, Device $device): array
    {
        return ['actor_id' => (int) $actor->id, 'device_id' => (int) $device->id, 'policy' => $this->current($device), 'available' => $this->available(), 'can_manage' => $actor->canDo(self::MANAGE), 'can_unattended' => $actor->canDo(self::UNATTENDED)];
    }
}
