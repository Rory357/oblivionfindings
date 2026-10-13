<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointRemoteSession;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class EndpointRemotePolicy
{
    public static function defaults(): array
    {
        return ['version' => 0, 'enabled' => false, 'consent_mode' => 'attended', 'allow_control' => false, 'max_minutes' => 30];
    }

    /** Caller owns the Device mutex before taking this lock. */
    public function current(Device $device): array
    {
        $row = DB::table('endpoint_remote_policies')->where('device_id', $device->id)->lockForUpdate()->first();
        if (! $row) {
            return self::defaults();
        }

        return ['version' => (int) $row->version, 'enabled' => (bool) $row->enabled, 'consent_mode' => $row->consent_mode,
            'allow_control' => (bool) $row->allow_control, 'max_minutes' => (int) $row->max_minutes];
    }

    public function show(User $actor, Device $device): array
    {
        return DB::transaction(function () use ($actor, $device): array {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            app(EndpointRemoteAccess::class)->actor($actor->id, $device, 'securityDevices.remote.managePolicy');

            return $this->current($device);
        });
    }

    public function save(User $actor, Device $device, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['expected_actor_id', 'expected_version', 'enabled', 'consent_mode', 'allow_control', 'max_minutes', 'reason']) !== [], 422);
        $data = Validator::make($input, [
            'expected_actor_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:0'],
            'enabled' => ['required', 'boolean:strict'], 'consent_mode' => ['required', 'in:attended,unattended'],
            'allow_control' => ['required', 'boolean:strict'], 'max_minutes' => ['required', 'integer:strict', 'between:5,120'],
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
        ])->validate();

        return DB::transaction(function () use ($actor, $device, $data): array {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $actor = app(EndpointRemoteAccess::class)->actor($actor->id, $device, 'securityDevices.remote.managePolicy');
            abort_unless((int) $data['expected_actor_id'] === (int) $actor->id, 409);
            $before = $this->current($device);
            abort_unless($before['version'] === (int) $data['expected_version'], 409);
            $values = array_intersect_key($data, self::defaults());
            $values['version'] = $before['version'] + 1;
            DB::table('endpoint_remote_policies')->updateOrInsert(['device_id' => $device->id],
                $values + ['updated_by_user_id' => $actor->id, 'updated_at' => now()] + ($before['version'] === 0 ? ['created_at' => now()] : []));
            foreach (EndpointRemoteSession::query()->where('device_id', $device->id)->whereNull('ended_at')->lockForUpdate()->get() as $session) {
                app(EndpointRemoteSessions::class)->end($session, 'stopped', 'policy_changed');
            }
            app(EndpointRemoteSessions::class)->event($device->id, 'remote_policy_changed', [
                'actor_user_id' => $actor->id, 'version' => $values['version'],
                'enabled' => $values['enabled'], 'consent_mode' => $values['consent_mode'],
                'allow_control' => $values['allow_control'], 'reason' => $data['reason'],
            ]);

            return $this->current($device);
        });
    }
}
