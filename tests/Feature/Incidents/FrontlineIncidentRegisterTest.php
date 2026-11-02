<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A support worker's Incidents list (board G4-4, approved by Stephan 1 Nov 2026): her four status words, frontline
 * boxes, and only the incidents for the people she supports (D-5: her own reports about no particular person show on
 * My Day only). Nothing here widens what she can open.
 */
class FrontlineIncidentRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now(), 'email_verified_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'email_verified_at' => now()]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->site = Site::factory()->create(['name' => 'Kauri House']);
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->client->supportWorkers()->attach($this->staff->id);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->staff->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function incident(array $attributes): ClientIncident
    {
        return ClientIncident::factory()->create(array_replace([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->staff->id,
            'status' => 'submitted',
            'occurred_at' => now()->subDay(),
            'is_hr_confidential' => false,
        ], $attributes));
    }

    public function test_her_boxes_and_views_use_her_four_words(): void
    {
        $other = User::factory()->create();
        $ownDraft = $this->incident(['status' => 'draft']);
        $othersDraft = $this->incident(['status' => 'draft', 'reported_by' => $other->id]);
        $sent = $this->incident(['status' => 'submitted']);
        $followed = $this->incident(['status' => 'reviewed']);
        $this->incident(['status' => 'closed', 'closed_at' => now()->subDays(2)]);
        // Her own report for someone she doesn't support: My Day only (D-5); her access opens only her people.
        $noPerson = $this->incident(['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id]);

        $this->actingAs($this->staff)->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('frontline.own_drafts', 1)
            ->where('frontline.drafts', 2)
            ->where('frontline.sent', 1)
            ->where('frontline.followed_up', 1)
            ->where('frontline.closed_recent', 1)
            ->where('sites.0.name', 'Kauri House')
            ->has('rows.data', 5));

        $ids = fn (string $tab) => collect($this->actingAs($this->staff)->get('/incidents?tab='.$tab)
            ->assertOk()->viewData('page')['props']['rows']['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$sent->id], $ids('sent'));
        $this->assertSame([$followed->id], $ids('followed_up'));
        $this->assertSame(collect([$ownDraft->id, $othersDraft->id])->sort()->values()->all(), $ids('drafts'));
        $this->assertNotContains($noPerson->id, $ids('all'));

        $rows = collect($this->actingAs($this->staff)->get('/incidents?tab=drafts')->viewData('page')['props']['rows']['data'])->keyBy('id');
        $this->assertTrue($rows[$ownDraft->id]['reported_by_me']);
        $this->assertFalse($rows[$othersDraft->id]['reported_by_me']);
    }

    public function test_managers_keep_the_register_boxes(): void
    {
        $this->incident(['status' => 'submitted']);

        $this->actingAs($this->admin)->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('frontline', null)
            ->has('hero.attention.worksafe'));
    }
}
