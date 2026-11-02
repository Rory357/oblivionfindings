<?php

namespace App\Domain\SecurityDevices\Console;

use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin;
use App\Domain\SecurityDevices\Iot\Relay\Services\SensorGatewayLoginService;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * S&D Phase B (D21): make or revoke a sensor gateway's login for the Site hub's relay. IT uses this
 * until the "Add a sensor gateway" pop-up (98) lands in Phase E.
 */
final class SensorGatewayLoginCommand extends Command
{
    protected $signature = 'security-devices:sensor-login
        {site : The Site id}
        {label? : A name for the gateway, e.g. "Office UG65"}
        {--revoke= : Revoke this login (its username) instead of making one}';

    protected $description = 'Make (or revoke) a sensor gateway login for the Site hub\'s MQTT relay.';

    public function handle(SensorGatewayLoginService $logins, CoveringHubResolver $hubs): int
    {
        $site = Site::query()->find((int) $this->argument('site'));
        if (! $site instanceof Site) {
            $this->error('No Site has that id.');

            return self::FAILURE;
        }

        $revoke = $this->option('revoke');
        if (is_string($revoke) && $revoke !== '') {
            $login = SensorGatewayLogin::query()->where('site_id', $site->id)->where('username', $revoke)->first();
            if (! $login instanceof SensorGatewayLogin) {
                $this->error('That Site has no login with that username.');

                return self::FAILURE;
            }
            $logins->revoke($login);
            $this->info("Revoked {$login->username}. The Site hub refuses it from its next check-in (about a minute).");

            return self::SUCCESS;
        }

        $label = trim((string) $this->argument('label'));
        if ($label === '') {
            $this->error('Give the gateway a name, e.g. "Office UG65".');

            return self::FAILURE;
        }
        $hub = $hubs->forSite((int) $site->id);
        ['login' => $login, 'password' => $password] = $logins->create($site, $label);

        $this->info("Login made for {$label} at {$site->name}. The password is shown once; Oblivion keeps only a hash.");
        $this->table(['Setting', 'Value'], [
            ['MQTT server', $hub ? CoveringHubResolver::label($hub).' (the hub PC\'s address on the house network), port 1883' : 'No hub covers this Site yet'],
            ['Username', $login->username],
            ['Password', $password],
            ['Topic', $login->topic_prefix.'<sensor>'],
        ]);

        return self::SUCCESS;
    }
}
