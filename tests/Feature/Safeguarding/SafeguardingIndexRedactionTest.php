<?php

namespace Tests\Feature\Safeguarding;

use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safeguarding redesign — Step 3 (need-to-know on the list).
 *
 * A sensitive concern is shown as "Restricted" (subject identity redacted) to a
 * viewer who has `viewAny` but lacks `viewSensitive` and is neither the assignee
 * nor the reporter — and is fully visible to a cleared viewer, the assignee, or
 * the reporter.
 */
class SafeguardingIndexRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /** A user with ONLY the given permission overrides (no role → no inherited viewSensitive). */
    private function userWith(array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    public function test_sensitive_concern_is_restricted_for_uncleared_viewer(): void
    {
        $viewer = $this->userWith(['safeguarding.viewAny']);
        $reporter = User::factory()->create(['approved_at' => now()]);

        SafeguardingConcern::factory()->create([
            'is_sensitive' => true,
            'subject_name' => 'Jane Doe',
            'reported_by_user_id' => $reporter->id,
            'status' => 'reported',
        ]);

        $this->actingAs($viewer)
            ->get('/safeguarding?tab=triage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data.0.restricted', true)
                ->where('rows.data.0.subject', null)
            );
    }

    public function test_sensitive_concern_is_visible_to_cleared_viewer(): void
    {
        $viewer = $this->userWith(['safeguarding.viewAny', 'safeguarding.viewSensitive']);

        SafeguardingConcern::factory()->create([
            'is_sensitive' => true,
            'subject_name' => 'Jane Doe',
            'status' => 'reported',
        ]);

        $this->actingAs($viewer)
            ->get('/safeguarding?tab=triage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data.0.restricted', false)
                ->where('rows.data.0.subject.name', 'Jane Doe')
            );
    }

    public function test_sensitive_concern_is_visible_to_assignee(): void
    {
        $viewer = $this->userWith(['safeguarding.viewAny']);

        SafeguardingConcern::factory()
            ->assignedTo($viewer)
            ->create([
                'is_sensitive' => true,
                'subject_name' => 'Jane Doe',
                'status' => 'reported',
            ]);

        $this->actingAs($viewer)
            ->get('/safeguarding?tab=triage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data.0.restricted', false)
                ->where('rows.data.0.subject.name', 'Jane Doe')
            );
    }

    public function test_non_sensitive_concern_is_never_restricted(): void
    {
        $viewer = $this->userWith(['safeguarding.viewAny']);

        SafeguardingConcern::factory()->create([
            'is_sensitive' => false,
            'subject_name' => 'Open Subject',
            'status' => 'reported',
        ]);

        $this->actingAs($viewer)
            ->get('/safeguarding?tab=triage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data.0.restricted', false)
            );
    }
}
