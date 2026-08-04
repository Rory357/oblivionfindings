<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HsRiskAssessmentSiteAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_register_detail_filters_and_pickers_follow_effective_site_provenance(): void
    {
        $localSite = Site::factory()->create(['name' => 'Local Site']);
        $otherSite = Site::factory()->create(['name' => 'Other Site']);
        $viewer = $this->siteBoundUser($localSite, ['hazards.view']);
        $localClient = Client::factory()->create(['site_id' => $localSite->id]);
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $localEvent = HsEvent::factory()->create(['site_id' => $localSite->id]);
        $otherEvent = HsEvent::factory()->create(['site_id' => $otherSite->id]);

        $siteRisk = HsRiskAssessment::factory()->forSite($localSite->id)->create();
        $clientRisk = HsRiskAssessment::factory()->forClient($localClient->id)->create();
        $eventRisk = HsRiskAssessment::factory()->create(['hs_event_id' => $localEvent->id]);
        $foreignRisk = HsRiskAssessment::factory()->forClient($otherClient->id)->create();
        $standalone = HsRiskAssessment::factory()->create();
        $mismatched = HsRiskAssessment::factory()->create([
            'hs_event_id' => $otherEvent->id,
            'assessable_type' => Site::class,
            'assessable_id' => $localSite->id,
        ]);

        $this->actingAs($viewer)
            ->get('/health-safety/risk-assessments?assessment='.$clientRisk->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments.data', 3)
                ->where('detail.id', $clientRisk->id)
                ->has('pickers.sites', 1)
                ->where('pickers.sites.0.id', $localSite->id)
                ->has('pickers.clients', 1)
                ->where('pickers.clients.0.id', $localClient->id)
                ->has('pickers.events', 1)
                ->where('pickers.events.0.id', $localEvent->id));

        $this->actingAs($viewer)
            ->get('/health-safety/risk-assessments?site_id='.$localSite->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('assessments.data', 3));

        $this->actingAs($viewer)
            ->get('/health-safety/risk-assessments?client_id='.$otherClient->id)
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get('/health-safety/risk-assessments?hs_event_id='.$otherEvent->id)
            ->assertForbidden();

        foreach ([$foreignRisk, $standalone, $mismatched] as $hidden) {
            $this->actingAs($viewer)
                ->get('/health-safety/risk-assessments/'.$hidden->id)
                ->assertForbidden();
        }

        $this->assertNotSame($eventRisk->id, $siteRisk->id);
    }

    public function test_create_and_update_accept_only_contexts_within_site_access(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->siteBoundUser($localSite, ['hazards.view', 'hazards.manage']);
        $localClient = Client::factory()->create(['site_id' => $localSite->id]);
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $localEvent = HsEvent::factory()->create(['site_id' => $localSite->id]);
        $otherEvent = HsEvent::factory()->create(['site_id' => $otherSite->id]);

        foreach ([
            ['site', $localSite->id],
            ['client', $localClient->id],
            ['event', $localEvent->id],
        ] as [$type, $id]) {
            $this->actingAs($manager)
                ->post('/health-safety/risk-assessments', $this->validPayload($type, $id, ['title' => "Allowed {$type}"]))
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors();
        }

        foreach ([
            ['site', $otherSite->id],
            ['client', $otherClient->id],
            ['event', $otherEvent->id],
        ] as [$type, $id]) {
            $this->actingAs($manager)
                ->post('/health-safety/risk-assessments', $this->validPayload($type, $id, ['title' => "Denied {$type}"]))
                ->assertSessionHasErrors('attach_id');
        }

        $this->actingAs($manager)
            ->post('/health-safety/risk-assessments', $this->validPayload('standalone'))
            ->assertSessionHasErrors('attach_type');

        $draft = HsRiskAssessment::factory()->forSite($localSite->id)->create(['title' => 'Original']);
        $this->actingAs($manager)
            ->put('/health-safety/risk-assessments/'.$draft->id, $this->validPayload('client', $otherClient->id))
            ->assertSessionHasErrors('attach_id');
        $this->assertSame('Original', $draft->fresh()->title);
    }

    public function test_every_lifecycle_action_enforces_the_assessment_site(): void
    {
        Storage::fake('private');
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->siteBoundUser($localSite, ['hazards.view', 'hazards.manage']);

        $draft = HsRiskAssessment::factory()->forSite($localSite->id)->create();
        $this->actingAs($manager)
            ->put('/health-safety/risk-assessments/'.$draft->id, $this->validPayload('site', $localSite->id, ['title' => 'Updated']))
            ->assertRedirect();
        $this->actingAs($manager)->post('/health-safety/risk-assessments/'.$draft->id.'/activate')->assertRedirect();

        $review = HsRiskAssessment::factory()->active()->forSite($localSite->id)->create();
        $this->actingAs($manager)->post('/health-safety/risk-assessments/'.$review->id.'/review')->assertRedirect();
        $this->actingAs($manager)->post('/health-safety/risk-assessments/'.$review->id.'/residual', [
            'residual_likelihood' => 1,
            'residual_consequence' => 2,
        ])->assertRedirect();
        $this->actingAs($manager)
            ->post('/health-safety/risk-assessments/'.$review->id.'/supersede', $this->validPayload('site', $localSite->id))
            ->assertRedirect();

        $archive = HsRiskAssessment::factory()->active()->forSite($localSite->id)->create();
        $this->actingAs($manager)->post('/health-safety/risk-assessments/'.$archive->id.'/archive')->assertRedirect();

        $upload = HsRiskAssessment::factory()->active()->forSite($localSite->id)->create();
        $this->actingAs($manager)->post('/health-safety/risk-assessments/'.$upload->id.'/attachments', [
            'file' => UploadedFile::fake()->create('local.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $foreignDraft = HsRiskAssessment::factory()->forSite($otherSite->id)->create();
        $foreignActive = HsRiskAssessment::factory()->active()->forSite($otherSite->id)->create();
        $denied = [
            ['put', "/health-safety/risk-assessments/{$foreignDraft->id}", $this->validPayload('site', $localSite->id)],
            ['post', "/health-safety/risk-assessments/{$foreignDraft->id}/activate", []],
            ['post', "/health-safety/risk-assessments/{$foreignActive->id}/review", []],
            ['post', "/health-safety/risk-assessments/{$foreignActive->id}/residual", ['residual_likelihood' => 1, 'residual_consequence' => 1]],
            ['post', "/health-safety/risk-assessments/{$foreignActive->id}/supersede", $this->validPayload('site', $localSite->id)],
            ['post', "/health-safety/risk-assessments/{$foreignActive->id}/archive", []],
        ];

        foreach ($denied as [$method, $uri, $payload]) {
            $this->actingAs($manager)->{$method}($uri, $payload)->assertForbidden();
        }

        $this->actingAs($manager)->post('/health-safety/risk-assessments/'.$foreignActive->id.'/attachments', [
            'file' => UploadedFile::fake()->create('foreign.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_attachment_download_and_delete_require_parent_site_and_parent_child_match(): void
    {
        Storage::fake('private');
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->siteBoundUser($localSite, ['hazards.view', 'hazards.manage']);
        $local = HsRiskAssessment::factory()->forSite($localSite->id)->create();
        $foreign = HsRiskAssessment::factory()->forSite($otherSite->id)->create();
        Storage::disk('private')->put('risk/local.pdf', 'local');
        Storage::disk('private')->put('risk/foreign.pdf', 'foreign');
        $localAttachment = $this->attachment($local, 'risk/local.pdf');
        $foreignAttachment = $this->attachment($foreign, 'risk/foreign.pdf');

        $this->actingAs($manager)
            ->get("/health-safety/risk-assessments/{$local->id}/attachments/{$localAttachment->id}/download")
            ->assertOk();
        $this->actingAs($manager)
            ->get("/health-safety/risk-assessments/{$foreign->id}/attachments/{$foreignAttachment->id}/download")
            ->assertForbidden();
        $this->actingAs($manager)
            ->get("/health-safety/risk-assessments/{$local->id}/attachments/{$foreignAttachment->id}/download")
            ->assertNotFound();
        $this->actingAs($manager)
            ->delete("/health-safety/risk-assessments/{$foreign->id}/attachments/{$foreignAttachment->id}")
            ->assertForbidden();
        $this->actingAs($manager)
            ->delete("/health-safety/risk-assessments/{$local->id}/attachments/{$localAttachment->id}")
            ->assertRedirect();
    }

    public function test_only_application_wide_hs_access_can_see_and_manage_truly_standalone_assessments(): void
    {
        $site = Site::factory()->create();
        $standalone = HsRiskAssessment::factory()->create();
        $siteViewer = $this->siteBoundUser($site, ['hazards.view']);
        $globalManager = $this->userWithPermissions([
            'hazards.view',
            'hazards.manage',
            'healthSafety.viewAllSites',
        ]);

        $this->actingAs($siteViewer)
            ->get('/health-safety/risk-assessments?assessment='.$standalone->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments.data', 0)
                ->where('detail', null));

        $this->actingAs($globalManager)
            ->get('/health-safety/risk-assessments?assessment='.$standalone->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments.data', 1)
                ->where('detail.id', $standalone->id));

        $this->actingAs($globalManager)
            ->put('/health-safety/risk-assessments/'.$standalone->id, $this->validPayload('standalone', null, ['title' => 'Global update']))
            ->assertRedirect();
        $this->assertSame('Global update', $standalone->fresh()->title);
    }

    private function validPayload(string $attachType, ?int $attachId = null, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Site-scoped risk assessment',
            'risk_description' => 'Canonical Site access proof',
            'attach_type' => $attachType,
            'attach_id' => $attachId,
            'likelihood' => 3,
            'consequence' => 3,
            'residual_likelihood' => 1,
            'residual_consequence' => 2,
            'risk_acceptable' => true,
            'review_frequency_days' => 90,
        ], $overrides);
    }

    private function attachment(HsRiskAssessment $assessment, string $path): HsRiskAssessmentAttachment
    {
        return HsRiskAssessmentAttachment::query()->create([
            'hs_risk_assessment_id' => $assessment->id,
            'disk' => 'private',
            'original_name' => basename($path),
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => 5,
        ]);
    }

    /** @param  array<int, string>  $permissionKeys */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = $this->userWithPermissions($permissionKeys);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    /** @param  array<int, string>  $permissionKeys */
    private function userWithPermissions(array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync($permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));

        return $user;
    }
}
