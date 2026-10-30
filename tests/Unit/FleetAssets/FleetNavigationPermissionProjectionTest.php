<?php

namespace Tests\Unit\FleetAssets;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FleetNavigationPermissionProjectionTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The projection reads config('chat.shared_experience'). Without an application boot
        // there is no config store, so give the bare container one with shared Chat off.
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['chat' => ['shared_experience' => false]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /** @return array<string, array{list<string>, bool}> */
    public static function permissionCases(): array
    {
        return [
            'report only' => [['fleet.reports.view'], true],
            'report and assigned assets' => [['fleet.reports.view', 'assets.viewAssigned'], true],
            'report and asset manager' => [['fleet.reports.view', 'assets.viewAny'], true],
            'unrelated global reports' => [['reports.viewAny'], false],
            'fleet view is projected separately' => [['fleet.viewAny'], false],
            'no permission' => [[], false],
        ];
    }

    #[DataProvider('permissionCases')]
    public function test_projection_reads_existing_authority_without_granting_operations(array $keys, bool $expected): void
    {
        $user = $this->userWithLoadedPermissions($keys);
        $permissions = $this->project($user);

        self::assertSame($expected, $permissions['fleet']['reportsView']);
        self::assertSame(in_array('fleet.viewAny', $keys, true), $permissions['fleet']['viewAny']);
        self::assertSame($user->canDo('assets.viewAssigned'), $permissions['assets']['viewAssigned']);
        self::assertSame($user->canDo('assets.viewAny'), $permissions['assets']['viewAny']);
    }

    public function test_an_explicit_deny_still_wins_over_a_role_report_permission(): void
    {
        $user = $this->userWithLoadedPermissions(['fleet.reports.view']);
        $deny = new Permission(['key' => 'fleet.reports.view']);
        $deny->setRelation('pivot', new Pivot(['allowed' => false]));
        $user->setRelation('permissionOverrides', new Collection([$deny]));

        self::assertFalse($user->canDo('fleet.reports.view'));
        self::assertFalse($this->project($user)['fleet']['reportsView']);
    }

    private function userWithLoadedPermissions(array $keys): User
    {
        $role = new Role(['name' => 'navigation-test']);
        $role->setRelation('permissions', new Collection(array_map(fn (string $key) => new Permission(['key' => $key]), $keys)));

        return (new User)
            ->setRelation('roles', new Collection([$role]))
            ->setRelation('permissionOverrides', new Collection);
    }

    private function project(User $user): array
    {
        // Exercise the real projection and User::canDo with loaded relations;
        // no application boot, database, grants or operational records required.
        return (new class(new BoardPackAccessService) extends HandleInertiaRequests
        {
            public function project(User $user): array
            {
                return $this->buildUserPermissions($user);
            }
        })->project($user);
    }
}
