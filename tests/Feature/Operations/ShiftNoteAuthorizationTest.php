<?php

namespace Tests\Feature\Operations;

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RoleScope;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftNoteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supportWorker;

    private User $coordinatorWithoutShiftNotes;

    private ClientNote $note;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = $this->roleWithPermissions('admin', [
            'shifts.viewAny',
            'shifts.viewAssigned',
            'shifts.manageAny',
        ], level: 100);
        $supportWorkerRole = $this->roleWithPermissions('support_worker', [
            'shifts.viewAssigned',
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
            'organization_id' => 1,
        ]);
        $this->admin->roles()->attach($adminRole);

        $this->supportWorker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'organization_id' => 1,
        ]);
        $this->supportWorker->roles()->attach($supportWorkerRole);

        $this->coordinatorWithoutShiftNotes = $this->userWithPermissions(['rostering.viewAny']);

        $client = Client::factory()->create(['organization_id' => 1]);
        $serviceContext = ServiceContext::factory()->create();
        $shift = Shift::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->supportWorker->id,
            'created_by' => $this->admin->id,
        ]);

        $this->note = ClientNote::query()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'user_id' => $this->supportWorker->id,
            'type' => 'daily_note',
            'subject' => 'Shift summary',
            'body' => 'Sensitive support note',
            'is_private' => true,
            'is_flagged' => false,
            'visibility' => 'internal',
        ]);
    }

    public function test_frontline_staff_are_redirected_from_shift_note_read_routes(): void
    {
        $this->actingAs($this->supportWorker)
            ->get(route('operations.shift_notes.index'))
            ->assertRedirect(route('my-day'));

        $this->actingAs($this->supportWorker)
            ->get(route('operations.shift_notes.export'))
            ->assertRedirect(route('my-day'));
    }

    public function test_manager_with_shift_view_any_can_load_shift_notes(): void
    {
        $this->actingAs($this->admin)
            ->get(route('operations.shift_notes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/shift-notes/Index')
                ->has('notes.data', 1));
    }

    public function test_non_frontline_user_without_shift_view_any_is_forbidden_from_read_routes(): void
    {
        $this->actingAs($this->coordinatorWithoutShiftNotes)
            ->get(route('operations.shift_notes.index'))
            ->assertForbidden();

        $this->actingAs($this->coordinatorWithoutShiftNotes)
            ->get(route('operations.shift_notes.export'))
            ->assertForbidden();
    }

    public function test_flag_and_review_routes_require_shift_view_any(): void
    {
        $this->actingAs($this->supportWorker)
            ->patch(route('operations.shift_notes.flag', $this->note), [
                'flagged_reason' => 'Needs coordinator review',
            ])
            ->assertForbidden();

        $this->actingAs($this->supportWorker)
            ->patch(route('operations.shift_notes.review', $this->note))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->from(route('operations.shift_notes.index'))
            ->patch(route('operations.shift_notes.flag', $this->note), [
                'flagged_reason' => 'Needs coordinator review',
            ])
            ->assertRedirect(route('operations.shift_notes.index'));

        $this->assertDatabaseHas('client_notes', [
            'id' => $this->note->id,
            'is_flagged' => true,
            'flagged_reason' => 'Needs coordinator review',
        ]);

        $this->actingAs($this->admin)
            ->from(route('operations.shift_notes.index'))
            ->patch(route('operations.shift_notes.review', $this->note))
            ->assertRedirect(route('operations.shift_notes.index'));

        $this->assertDatabaseHas('client_notes', [
            'id' => $this->note->id,
            'reviewed_by' => $this->admin->id,
        ]);
    }

    public function test_controller_guards_still_require_shift_view_any_if_route_permission_middleware_is_bypassed(): void
    {
        $this->withoutMiddleware([EnsurePermission::class, RoleScope::class]);

        $this->actingAs($this->supportWorker)
            ->get(route('operations.shift_notes.index'))
            ->assertForbidden();

        $this->actingAs($this->supportWorker)
            ->get(route('operations.shift_notes.export'))
            ->assertForbidden();

        $this->actingAs($this->supportWorker)
            ->patch(route('operations.shift_notes.flag', $this->note), [
                'flagged_reason' => 'Needs coordinator review',
            ])
            ->assertForbidden();

        $this->actingAs($this->supportWorker)
            ->patch(route('operations.shift_notes.review', $this->note))
            ->assertForbidden();
    }

    private function userWithPermissions(array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'organization_id' => 1,
        ]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key]
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    private function roleWithPermissions(string $roleName, array $permissionKeys, int $level = 40): Role
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            [
                'label' => ucfirst(str_replace('_', ' ', $roleName)),
                'level' => $level,
                'type' => 'system',
            ],
        );

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $role;
    }
}
