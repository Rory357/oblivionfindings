<?php

namespace Tests\Feature\SecurityDevices;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntegrationSpokeReadAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public static function spokes(): array
    {
        return [
            'Milesight' => ['/security-devices/integrations/milesight', 'security-devices/integrations/milesight'],
            'Queclink' => ['/security-devices/integrations/queclink', 'security-devices/integrations/queclink-hub'],
        ];
    }

    #[DataProvider('spokes')]
    public function test_management_reads_bind_current_actor_and_real_nested_shared_permissions(string $route, string $component): void
    {
        $manager = User::factory()->create(['approved_at' => now()]);
        $manager->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->actingAs($manager)->get($route)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component($component)
                ->where('actor_id', $manager->id)
                ->where('auth.user.id', $manager->id)
                ->where('can.manage', true)
                ->where('auth.can.securityDevices.viewAny', true)
                ->where('auth.can.securityDevices.integrationsManage', true)
                ->where('as_of', fn ($value) => is_string($value) && str_ends_with($value, 'Z'))
            );
    }

    #[DataProvider('spokes')]
    public function test_module_read_permission_does_not_grant_provider_management(string $route, string $component): void
    {
        $reader = User::factory()->create(['approved_at' => now()]);
        $view = Permission::query()->where('key', 'securityDevices.viewAny')->firstOrFail();
        $reader->permissionOverrides()->attach($view->id, ['allowed' => true]);

        $this->actingAs($reader)->get($route)->assertForbidden();
    }
}
