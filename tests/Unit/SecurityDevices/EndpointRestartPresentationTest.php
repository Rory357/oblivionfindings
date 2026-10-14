<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartPolicy;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class EndpointRestartPresentationTest extends TestCase
{
    public function test_real_policy_and_restart_projections_keep_pending_cancellation_and_boot_confirmation_distinct(): void
    {
        $previous = Container::getInstance();
        $facade = Facade::getFacadeApplication();
        $resolver = Model::getConnectionResolver();
        try {
            $app = new Application;
            Container::setInstance($app);
            Facade::setFacadeApplication($app);
            Facade::clearResolvedInstances();
            $app->instance('config', new Repository(['security_devices' => require dirname(__DIR__, 3).'/config/security_devices.php', 'endpoint_management' => ['enabled' => false, 'restart' => ['release_enabled' => false]]]));
            $db = new Manager($app);
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
            $db->bootEloquent();
            $app->instance('db', $db->getDatabaseManager());
            $schema = $db->getConnection()->getSchemaBuilder();
            $app->instance('db.schema', $schema);
            $schema->create('endpoint_restart_policies', function (Blueprint $t) {
                $t->id();
                $t->integer('device_id');
                $t->integer('version');
                $t->boolean('enabled');
                $t->string('consent_mode');
                $t->integer('minimum_warning_seconds');
            });
            $schema->create('endpoint_deliveries', function (Blueprint $t) {
                $t->id();
                $t->integer('device_command_attempt_id');
                $t->timestamp('cancel_requested_at')->nullable();
                $t->timestamp('completed_at')->nullable();
            });
            $db->getConnection()->table('endpoint_restart_policies')->insert(['device_id' => 7, 'version' => 1, 'enabled' => true, 'consent_mode' => 'attended', 'minimum_warning_seconds' => 60]);
            $db->getConnection()->table('endpoint_deliveries')->insert(['id' => 1, 'device_command_attempt_id' => 1]);
            $viewer = (new class extends User
            {
                public function canDo(string $permissionKey): bool
                {
                    return in_array($permissionKey, [EndpointRestartPolicy::MANAGE, EndpointRestartPolicy::SERVICE], true);
                }
            })->forceFill(['id' => 9]);
            $device = (new Device)->forceFill(['id' => 7]);
            $policy = new EndpointRestartPolicy;
            $dto = (new ReflectionMethod($policy, 'dto'))->invoke($policy, $viewer, $device);
            self::assertSame(['actor_id', 'device_id', 'policy', 'available', 'can_manage', 'can_unattended'], array_keys($dto));
            self::assertFalse($dto['available']);
            self::assertTrue($dto['can_manage']);
            self::assertFalse($dto['can_unattended']);
            $capability = (new CommandCapabilityRegistry)->definition('endpoint.computer.restart');
            self::assertTrue($capability->requiresApproval);
            self::assertTrue($capability->requiresStepUp);
            self::assertTrue($capability->requiresMfa);
            self::assertFalse($capability->allowsBreakGlass);
            self::assertSame(86405, $capability->expiresAfterSeconds);
            self::assertSame(['execute_at', 'warning_seconds'], array_keys($capability->parameters));
            $service = (new CommandCapabilityRegistry)->definition('endpoint.service.manage');
            self::assertSame(300, $service->expiresAfterSeconds);
            self::assertTrue($service->requiresStepUp);
            $commands = (new ReflectionClass(EndpointRestartCommands::class))->newInstanceWithoutConstructor();
            $request = new DeviceCommandRequest(['capability' => 'endpoint.computer.restart', 'device_id' => 7, 'requested_by_user_id' => 9, 'status' => 'dispatching', 'safe_parameter_summary' => ['execute_at' => now()->utc()->addHours(2)->format('Y-m-d\TH:i:s\Z'), 'warning_seconds' => 60], 'expected_state' => ['consent_mode' => 'attended'], 'expires_at' => now()->addHours(2)->addSeconds(5)]);
            $request->forceFill(['id' => 12]);
            $attempt = (new DeviceCommandAttempt)->forceFill(['id' => 1]);
            $states = [];
            $states['scheduled'] = $commands->projection($request, $viewer, $attempt);
            self::assertTrue($states['scheduled']['canCancel']);
            $db->getConnection()->table('endpoint_deliveries')->where('id', 1)->update(['cancel_requested_at' => now()->format('Y-m-d H:i:s')]);
            $states['cancel_requested'] = $commands->projection($request, $viewer, $attempt);
            self::assertSame('cancel_requested', $states['cancel_requested']['state']);
            self::assertFalse($states['cancel_requested']['canCancel']);
            foreach (['reconciling' => 'awaiting_boot', 'uncertain' => 'uncertain', 'reconciled' => 'confirmed', 'cancelled' => 'cancelled', 'expired' => 'expired', 'failed' => 'failed'] as $status => $state) {
                $request->status = $status;
                $states[$state] = $commands->projection($request, $viewer, $attempt);
                self::assertSame($state, $states[$state]['state']);
                self::assertFalse($states[$state]['canCancel']);
            }
            if ($export = getenv('OBLI_RESTART_UI_EXPORT')) {
                file_put_contents($export, json_encode(['policy' => $dto, 'capability' => $capability, 'projections' => $states], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }
        } finally {
            $resolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($resolver);
            Container::setInstance($previous);
            Facade::setFacadeApplication($facade);
            Facade::clearResolvedInstances();
        }
    }
}
