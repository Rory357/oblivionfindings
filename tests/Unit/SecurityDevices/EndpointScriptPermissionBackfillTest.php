<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScripts;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\EndpointScriptPermissionsSeeder;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\SeedAllPermissionsToAdminSeeder;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class EndpointScriptPermissionBackfillTest extends TestCase
{
    public function test_registration_and_both_admin_backfills_preserve_explicit_script_authority(): void
    {
        $previous = Model::getConnectionResolver();
        $events = Model::getEventDispatcher();
        $previousApp = Facade::getFacadeApplication();
        $previousContainer = Container::getInstance();
        $app = new Application;
        $app->instance('config', new Repository);
        $app->instance('date', new DateFactory);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        $db = new Manager($app);
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->bootEloquent();
        Model::unsetEventDispatcher();
        $schema = $db->getConnection()->getSchemaBuilder();
        try {
            $schema->create('roles', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->timestamps();
            });
            $schema->create('permissions', function (Blueprint $t): void {
                $t->id();
                $t->string('key')->unique();
                $t->string('description')->nullable();
                $t->string('group')->nullable();
                $t->string('module')->nullable();
                $t->timestamps();
            });
            $schema->create('role_permission', function (Blueprint $t): void {
                $t->unsignedBigInteger('role_id');
                $t->unsignedBigInteger('permission_id');
                $t->primary(['role_id', 'permission_id']);
            });
            $admin = Role::create(['name' => 'admin']);
            Permission::create(['key' => 'it.view']);
            Permission::create(['key' => 'healthSafety.events.close']);
            // Include a future script permission to catch accidental exact-key
            // exclusions that silently grant a new execution/output authority.
            Permission::create(['key' => 'securityDevices.endpoints.scriptFuture']);
            (new EndpointScriptPermissionsSeeder)->run();
            self::assertSame(0, $admin->permissions()->count());
            $console = new class extends Command
            {
                public function info($string, $verbosity = null): void {}
            };
            foreach ([new SeedAllPermissionsToAdminSeeder, new OperationsPermissionsSeeder, new SeedAllPermissionsToAdminSeeder] as $seeder) {
                $seeder->setCommand($console);
                $seeder->run();
                self::assertTrue($admin->permissions()->where('key', 'it.view')->exists());
                self::assertFalse($admin->permissions()->where('key', 'healthSafety.events.close')->exists());
                self::assertSame(0, $admin->permissions()->where('key', 'like', 'securityDevices.endpoints.script%')->count());
                self::assertSame(2, Permission::whereIn('key', [EndpointScripts::UPLOAD, EndpointScripts::REVIEW])->count());
                self::assertSame(3, Permission::whereIn('key', [EndpointScriptPolicy::MANAGE, EndpointScriptPolicy::RUN, EndpointScriptPolicy::UNATTENDED])->count());
            }
        } finally {
            $db->getConnection()->disconnect();
            Facade::setFacadeApplication($previousApp);
            Facade::clearResolvedInstances();
            Container::setInstance($previousContainer);
            if ($previous) {
                Model::setConnectionResolver($previous);
            } else {
                Model::unsetConnectionResolver();
            }
            if ($events) {
                Model::setEventDispatcher($events);
            } else {
                Model::unsetEventDispatcher();
            }
        }
    }
}
