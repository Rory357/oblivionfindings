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

    public function test_search_and_filters_never_find_a_locked_concern_by_what_it_says(): void
    {
        // Readiness audit P1 (6 Nov 2026): searching a name, a word or a category must not reveal that a need-to-know
        // concern exists. Its reference still matches, as it is on the row anyway.
        $viewer = $this->userWith(['safeguarding.viewAny']);
        $locked = SafeguardingConcern::factory()->create(['is_sensitive' => true, 'subject_name' => 'Hidden Kauri',
            'description' => 'Private words about Hidden Kauri.', 'abuse_category' => 'financial', 'status' => 'reported']);
        SafeguardingConcern::factory()->create(['is_sensitive' => false, 'subject_name' => 'Open Totara',
            'description' => 'Open words.', 'abuse_category' => 'financial', 'status' => 'reported']);

        foreach (['q=Hidden', 'q=Private+words', 'category=financial'] as $query) {
            $this->actingAs($viewer)->get('/safeguarding?tab=all&'.$query)->assertOk()->assertInertia(fn ($page) => $page
                ->where('rows.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($locked->id))
                ->where('tabCounts.all', str_starts_with($query, 'category') ? 1 : 0));
        }
        $this->actingAs($viewer)->get('/safeguarding?tab=all&q='.$locked->reference_number)->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.data.0.id', $locked->id)->where('rows.data.0.restricted', true));

        $cleared = $this->userWith(['safeguarding.viewAny', 'safeguarding.viewSensitive']);
        $this->actingAs($cleared)->get('/safeguarding?tab=all&q=Hidden')->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.data.0.id', $locked->id));
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
