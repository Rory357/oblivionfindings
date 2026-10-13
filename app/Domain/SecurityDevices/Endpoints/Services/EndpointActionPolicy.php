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

final class EndpointActionPolicy
{
    public const MANAGE = 'securityDevices.endpoints.manageActionPolicy';

    public const SERVICE = 'securityDevices.endpoints.serviceManage';

    public const UNATTENDED = 'securityDevices.endpoints.serviceUnattended';

    public static function defaults(): array
    {
        return ['version' => 0, 'enabled' => false, 'consent_mode' => 'attended'];
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('endpoint_action_policies') && Schema::hasColumns('endpoint_agents', ['service_actions', 'service_action_observations'])
            && Schema::hasColumns('endpoint_deliveries', ['encrypted_claim', 'claim_expires_at', 'result_hash', 'service_readback', 'readback_sequence', 'readback_received_at']);
    }

    public function available(): bool
    {
        if (! config('endpoint_management.enabled') || ! config('endpoint_management.actions.release_enabled') || ! $this->schemaReady()) {
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
        if (! Schema::hasTable('endpoint_action_policies')) {
            return self::defaults();
        }
        $row = DB::table('endpoint_action_policies')->where('device_id', $device->id)->lockForUpdate()->first();

        return $row ? ['version' => (int) $row->version, 'enabled' => (bool) $row->enabled, 'consent_mode' => $row->consent_mode] : self::defaults();
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
        abort_if(array_diff(array_keys($input), ['expected_actor_id', 'expected_version', 'expected_catalogue_digest', 'enabled', 'consent_mode', 'reason']) !== [], 422);
        $data = Validator::make($input, [
            'expected_actor_id' => ['required', 'integer:strict', 'min:1'], 'expected_version' => ['required', 'integer:strict', 'min:0'],
            'expected_catalogue_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'enabled' => ['required', 'boolean:strict'], 'consent_mode' => ['required', 'in:attended,unattended'],
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
        ])->validate();
        abort_unless($this->schemaReady(), 503);

        return $this->within($actor, $device, function (User $current, Device $locked) use ($data): array {
            abort_unless($current->canDo(self::MANAGE), 403);
            abort_unless((int) $data['expected_actor_id'] === (int) $current->id, 409);
            $before = $this->current($locked);
            abort_unless($before['version'] === $data['expected_version'] && hash_equals(app(EndpointServiceCatalogue::class)->digest(), $data['expected_catalogue_digest']), 409);
            abort_unless(! $data['enabled'] || $data['consent_mode'] !== 'unattended' || $current->canDo(self::UNATTENDED), 403);
            $values = ['version' => $before['version'] + 1, 'enabled' => $data['enabled'], 'consent_mode' => $data['consent_mode'], 'updated_by_user_id' => $current->id, 'updated_at' => now()];
            DB::table('endpoint_action_policies')->updateOrInsert(['device_id' => $locked->id], $values + ($before['version'] === 0 ? ['created_at' => now()] : []));
            DeviceEvent::query()->create(['device_id' => $locked->id, 'event_type' => 'endpoint_action_policy_changed', 'severity' => 'info', 'source' => 'oblivion_endpoint', 'occurred_at' => now(), 'payload' => ['actor_user_id' => $current->id, 'version' => $values['version'], 'enabled' => $values['enabled'], 'consent_mode' => $values['consent_mode'], 'reason' => $data['reason']]]);

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
        $catalogue = app(EndpointServiceCatalogue::class);

        return ['actor_id' => (int) $actor->id, 'device_id' => (int) $device->id, 'policy' => $this->current($device), 'catalogue_digest' => $catalogue->digest(), 'catalogue' => $catalogue->all(), 'available' => $this->available(), 'can_unattended' => $actor->canDo(self::UNATTENDED)];
    }
}
