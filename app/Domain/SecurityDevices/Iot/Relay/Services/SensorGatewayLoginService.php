<?php

namespace App\Domain\SecurityDevices\Iot\Relay\Services;

use App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * S&D Phase B (D21): makes and revokes sensor gateway logins. The password exists only in the
 * value this returns; Oblivion keeps a bcrypt hash, and so does the Site hub.
 */
final class SensorGatewayLoginService
{
    /** @return array{login: SensorGatewayLogin, password: string} */
    public function create(Site $site, string $label, ?Device $gateway = null, ?User $actor = null): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            throw new InvalidArgumentException('Give the gateway a name of up to 120 characters.');
        }
        $username = 'gw-'.strtolower(Str::random(12));
        $password = Str::password(28, symbols: false);

        $login = SensorGatewayLogin::query()->create([
            'site_id' => $site->id,
            'device_id' => $gateway?->id,
            'label' => $label,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]),
            'topic_prefix' => "oblivion/{$site->id}/{$username}/",
            'created_by_user_id' => $actor?->id,
        ]);
        AuditLogger::logOrFail('security_devices.sensor_login.created', $login, [
            'site_id' => (int) $site->id,
            'username' => $username,
            'actor_id' => $actor?->id,
        ], systemActor: $actor === null);

        return ['login' => $login, 'password' => $password];
    }

    public function revoke(SensorGatewayLogin $login, ?User $actor = null): void
    {
        if ($login->revoked_at !== null) {
            return;
        }
        $login->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor?->id])->save();
        AuditLogger::logOrFail('security_devices.sensor_login.revoked', $login, [
            'site_id' => (int) $login->site_id,
            'username' => $login->username,
            'actor_id' => $actor?->id,
        ], systemActor: $actor === null);
    }
}
