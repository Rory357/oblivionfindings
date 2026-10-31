<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SafeguardingReportingPageTest extends TestCase
{
    use RefreshDatabase;

    private function worker(Site $site, bool $canReport = false): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => now()->subYear(),
        ]);
        if ($canReport) {
            $permission = Permission::firstOrCreate(['key' => 'safeguarding.create'], [
                'description' => 'Report a safeguarding concern', 'group' => 'test', 'module' => 'Test',
            ]);
            $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }

        return $user;
    }

    public function test_reporting_permission_opens_only_scoped_intake_choices_and_retains_register_denial(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $other = Site::factory()->create(['is_active' => true]);
        $reporter = $this->worker($site, true);
        $colleague = $this->worker($site);
        $foreign = $this->worker($other);
        $localClient = Client::factory()->create(['site_id' => $site->id]);
        Client::factory()->create(['site_id' => $other->id]);

        $this->actingAs($reporter)->get('/safeguarding/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('safeguarding/create')
                ->has('clients', 1)->where('clients.0.id', $localClient->id)
                ->has('sites', 1)->where('sites.0.id', $site->id)
                ->where('staff', fn ($staff) => collect($staff)->pluck('id')->sort()->values()->all()
                    === collect([$reporter->id, $colleague->id])->sort()->values()->all())
                ->where('returnUrl', '/my-day')->missing('rows')->missing('hero')->missing('detail'));
        $this->get('/safeguarding?raise=1')->assertForbidden();
        $this->actingAs($foreign)->get('/safeguarding/create')->assertForbidden();
    }

    public function test_reporter_can_submit_return_to_intake_and_open_their_own_record(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $reporter = $this->worker($site, true);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $this->actingAs($reporter)->from('/safeguarding/create')->post('/safeguarding', [
            'site_id' => $site->id, 'subject_type' => 'client', 'subject_id' => $client->id,
            'concern_type' => 'neglect', 'description' => 'Synthetic reporting access regression.',
            'severity' => 'low', 'is_sensitive' => true,
        ])->assertRedirect('/safeguarding/create')->assertSessionHas('created_concern_id');
        $concern = SafeguardingConcern::query()->sole();
        $this->assertSame($reporter->id, $concern->reported_by_user_id);
        $this->assertSame($site->id, $concern->site_id);
        $this->assertTrue($concern->is_sensitive);
        $this->get('/safeguarding/'.$concern->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('returnUrl', '/my-day'));
        $this->get('/safeguarding')->assertForbidden();
    }

    public function test_failed_submission_returns_to_reporting_with_errors_without_creating_a_record(): void
    {
        $reporter = $this->worker(Site::factory()->create(), true);
        $this->actingAs($reporter)->from('/safeguarding/create')->post('/safeguarding', [
            'concern_type' => 'neglect', 'severity' => 'low', 'description' => '',
        ])->assertRedirect('/safeguarding/create')->assertSessionHasErrors('description');
        $this->assertDatabaseCount('safeguarding_concerns', 0);
        $this->get('/safeguarding/create')->assertOk();
    }

    public function test_returns_preserve_permitted_register_filters_and_reject_untrusted_destinations(): void
    {
        $reporter = $this->worker(Site::factory()->create(), true);
        $permission = Permission::firstOrCreate(['key' => 'incidents.viewAny'], ['description' => 'View incidents', 'group' => 'test', 'module' => 'Test']);
        $reporter->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $this->actingAs($reporter)->get('/safeguarding/create?'.http_build_query([
            'return_to' => '/incidents?tab=near_misses&q=two%20words&page=2&return_to=https://outside.test&report=1',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('returnUrl', '/incidents?tab=near_misses&q=two%20words&page=2'));
        foreach (['https://outside.test', '//outside.test', '/\\outside.test', '/safeguarding?raise=1', '/incidents/1', '/incidents?source[]=x'] as $target) {
            $this->get('/safeguarding/create?'.http_build_query(['return_to' => $target]))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('returnUrl', '/incidents'));
        }
    }
}
