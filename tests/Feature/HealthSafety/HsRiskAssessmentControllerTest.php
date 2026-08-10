<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /health-safety/risk-assessments — the gold-standard register: hero + tab counts +
 * standardised rows, detail-over-list (?assessment=), the seven lifecycle write
 * actions (all → HsRiskAssessmentService), premium evidence attachments, and gating.
 */
class HsRiskAssessmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function hsOfficer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Manual handling — hoist transfers',
            'risk_description' => 'Two-person hoist transfers',
            'attach_type' => 'standalone',
            'attach_id' => null,
            'likelihood' => 3,
            'consequence' => 4,
            'existing_controls' => 'Ceiling hoist, training',
            'additional_controls' => 'Physio review',
            'residual_likelihood' => 2,
            'residual_consequence' => 3,
            'risk_acceptable' => true,
            'review_frequency_days' => 90,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- */
    /*  Register payload + scoping */
    /* ---------------------------------------------------------------- */

    public function test_index_renders_gold_standard_payload(): void
    {
        HsRiskAssessment::factory()->active()->create();
        HsRiskAssessment::factory()->create(); // draft

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/risk-assessments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/risk-assessments/index')
                ->has('assessments.data', 2)
                ->has('tabCounts.all')
                ->has('tabCounts.active')
                ->has('hero.total')
                ->has('hero.compliance.reviews_overdue')
                ->has('pickers.sites')
                ->where('can.manage', true)
                ->where('filters.tab', 'all')
            );
    }

    public function test_drafts_tab_scopes_to_drafts(): void
    {
        HsRiskAssessment::factory()->create();             // draft
        HsRiskAssessment::factory()->active()->create();   // active

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/risk-assessments?tab=drafts')
            ->assertInertia(fn (Assert $page) => $page->has('assessments.data', 1)
                ->where('assessments.data.0.status', 'draft'));
    }

    public function test_site_filter_scopes_rows(): void
    {
        $site = Site::factory()->create();
        HsRiskAssessment::factory()->create(['assessable_type' => Site::class, 'assessable_id' => $site->id]);
        HsRiskAssessment::factory()->create(); // standalone

        $this->actingAs($this->hsOfficer())
            ->get("/health-safety/risk-assessments?site_id={$site->id}")
            ->assertInertia(fn (Assert $page) => $page->has('assessments.data', 1));
    }

    public function test_risk_acceptable_filter_scopes_rows(): void
    {
        HsRiskAssessment::factory()->active()->create(['risk_acceptable' => false]);
        HsRiskAssessment::factory()->active()->create(['risk_acceptable' => true]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/risk-assessments?risk_acceptable=0')
            ->assertInertia(fn (Assert $page) => $page->has('assessments.data', 1)
                ->where('assessments.data.0.risk_acceptable', false));
    }

    public function test_detail_loads_when_assessment_param_present(): void
    {
        $ra = HsRiskAssessment::factory()->active()->create();

        $this->actingAs($this->hsOfficer())
            ->get("/health-safety/risk-assessments?assessment={$ra->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $ra->id)
                ->has('detail.attachments')
                ->has('detail.form'));
    }

    public function test_show_returns_json_detail(): void
    {
        $ra = HsRiskAssessment::factory()->active()->create();

        $this->actingAs($this->hsOfficer())
            ->getJson("/health-safety/risk-assessments/{$ra->id}")
            ->assertOk()
            ->assertJsonPath('detail.id', $ra->id)
            ->assertJsonPath('detail.can.manage', true);
    }

    /* ---------------------------------------------------------------- */
    /*  Lifecycle write actions */
    /* ---------------------------------------------------------------- */

    public function test_store_creates_draft_and_flashes_id(): void
    {
        $this->actingAs($this->hsOfficer())
            ->post('/health-safety/risk-assessments', $this->validPayload())
            ->assertRedirect()
            ->assertSessionHas('created_risk_assessment_id');

        $this->assertDatabaseHas('hs_risk_assessments', [
            'title' => 'Manual handling — hoist transfers',
            'status' => 'draft',
            'risk_score' => 12,
            'risk_level' => 'high',
            'residual_risk_score' => 6,
        ]);
    }

    public function test_store_for_site_sets_polymorphic_assessable(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->hsOfficer())
            ->post('/health-safety/risk-assessments', $this->validPayload([
                'attach_type' => 'site',
                'attach_id' => $site->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('hs_risk_assessments', [
            'assessable_type' => Site::class,
            'assessable_id' => $site->id,
        ]);
    }

    public function test_store_for_client_sets_polymorphic_assessable(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $this->actingAs($this->hsOfficer())
            ->post('/health-safety/risk-assessments', $this->validPayload([
                'attach_type' => 'client',
                'attach_id' => $client->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('hs_risk_assessments', [
            'assessable_type' => Client::class,
            'assessable_id' => $client->id,
        ]);
    }

    public function test_store_validates_required_title(): void
    {
        $this->actingAs($this->hsOfficer())
            ->post('/health-safety/risk-assessments', $this->validPayload(['title' => '']))
            ->assertSessionHasErrors('title');
    }

    public function test_store_validates_attach_id_required_for_non_standalone(): void
    {
        $this->actingAs($this->hsOfficer())
            ->post('/health-safety/risk-assessments', $this->validPayload(['attach_type' => 'site', 'attach_id' => null]))
            ->assertSessionHasErrors('attach_id');
    }

    public function test_store_rejects_missing_or_deleted_assessable(): void
    {
        $site = Site::factory()->create();
        $site->delete();

        $this->actingAs($this->hsOfficer())
            ->post('/health-safety/risk-assessments', $this->validPayload(['attach_type' => 'site', 'attach_id' => $site->id]))
            ->assertSessionHasErrors('attach_id');
    }

    public function test_update_edits_a_draft(): void
    {
        $ra = HsRiskAssessment::factory()->create(['title' => 'Old']);

        $this->actingAs($this->hsOfficer())
            ->put("/health-safety/risk-assessments/{$ra->id}", $this->validPayload(['title' => 'Updated title', 'likelihood' => 1, 'consequence' => 1]))
            ->assertRedirect();

        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $ra->id, 'title' => 'Updated title', 'risk_score' => 1, 'risk_level' => 'low']);
    }

    public function test_update_rejects_non_draft(): void
    {
        $ra = HsRiskAssessment::factory()->active()->create(['title' => 'Locked']);

        $this->actingAs($this->hsOfficer())
            ->put("/health-safety/risk-assessments/{$ra->id}", $this->validPayload(['title' => 'Nope']))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $ra->id, 'title' => 'Locked']);
    }

    public function test_activate_moves_draft_to_active_with_note(): void
    {
        $ra = HsRiskAssessment::factory()->create(['review_frequency_days' => 90]);

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/risk-assessments/{$ra->id}/activate", ['approver_note' => 'Approved with conditions'])
            ->assertRedirect();

        $ra->refresh();
        $this->assertEquals('active', $ra->status);
        $this->assertNotNull($ra->approved_at);
        $this->assertNotNull($ra->review_due_at);
        $this->assertEquals('Approved with conditions', $ra->approval_note);
    }

    public function test_activate_rejects_non_draft(): void
    {
        $ra = HsRiskAssessment::factory()->active()->create();

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/risk-assessments/{$ra->id}/activate")
            ->assertSessionHas('error');
    }

    public function test_mark_for_review(): void
    {
        $ra = HsRiskAssessment::factory()->active()->create();

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/risk-assessments/{$ra->id}/review")
            ->assertRedirect();

        $this->assertEquals('under_review', $ra->fresh()->status);
    }

    public function test_record_residual_sets_residual_and_note(): void
    {
        $ra = HsRiskAssessment::factory()->active()->create();

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/risk-assessments/{$ra->id}/residual", [
                'residual_likelihood' => 1,
                'residual_consequence' => 2,
                'risk_acceptable' => true,
                'review_note' => 'Controls verified',
            ])
            ->assertRedirect();

        $ra->refresh();
        $this->assertEquals(2, $ra->residual_risk_score);
        $this->assertEquals('low', $ra->residual_risk_level);
        $this->assertTrue($ra->risk_acceptable);
        $this->assertEquals('Controls verified', $ra->last_review_note);
    }

    public function test_supersede_creates_successor_draft(): void
    {
        $ra = HsRiskAssessment::factory()->active()->create();

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/risk-assessments/{$ra->id}/supersede", $this->validPayload(['title' => 'v2']))
            ->assertRedirect()
            ->assertSessionHas('created_risk_assessment_id');

        $ra->refresh();
        $this->assertEquals('superseded', $ra->status);
        $this->assertNotNull($ra->superseded_by_id);
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $ra->superseded_by_id, 'status' => 'draft', 'title' => 'v2']);
    }

    public function test_archive(): void
    {
        $ra = HsRiskAssessment::factory()->active()->create();

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/risk-assessments/{$ra->id}/archive")
            ->assertRedirect();

        $this->assertEquals('archived', $ra->fresh()->status);
    }

    /* ---------------------------------------------------------------- */
    /*  Premium evidence attachments */
    /* ---------------------------------------------------------------- */

    public function test_upload_download_and_delete_attachment(): void
    {
        Storage::fake('private');
        $ra = HsRiskAssessment::factory()->active()->create();

        // upload
        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/risk-assessments/{$ra->id}/attachments", [
                'file' => UploadedFile::fake()->create('swms.pdf', 200, 'application/pdf'),
                'kind' => 'swms',
                'notes' => 'Method statement',
            ])
            ->assertRedirect();

        $attachment = HsRiskAssessmentAttachment::where('hs_risk_assessment_id', $ra->id)->firstOrFail();
        $this->assertEquals('swms.pdf', $attachment->original_name);
        // Stored on the PRIVATE disk now — never world-readable under /storage.
        Storage::disk('private')->assertExists($attachment->path);
        $this->assertSame('private', $attachment->disk);

        // download — streamed from the private disk with the hardened CSP-sandbox
        // header from ServesPrivateAttachments (nosniff + X-Frame-Options come from
        // the edge layer, not the app).
        $this->actingAs($this->hsOfficer())
            ->get("/health-safety/risk-assessments/{$ra->id}/attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");

        // delete
        $this->actingAs($this->hsOfficer())
            ->delete("/health-safety/risk-assessments/{$ra->id}/attachments/{$attachment->id}")
            ->assertRedirect();

        Storage::disk('private')->assertMissing($attachment->path);
        $this->assertSoftDeleted('hs_risk_assessment_attachments', ['id' => $attachment->id]);
    }

    public function test_attachment_download_rejects_mismatched_assessment(): void
    {
        Storage::fake('public');
        $ra = HsRiskAssessment::factory()->create();
        $other = HsRiskAssessment::factory()->create();
        $attachment = HsRiskAssessmentAttachment::create([
            'hs_risk_assessment_id' => $other->id,
            'disk' => 'public',
            'original_name' => 'x.pdf',
            'path' => 'hs_risk_assessment_attachments/x.pdf',
        ]);

        $this->actingAs($this->hsOfficer())
            ->get("/health-safety/risk-assessments/{$ra->id}/attachments/{$attachment->id}/download")
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------- */
    /*  Permission gating */
    /* ---------------------------------------------------------------- */

    public function test_index_requires_hazards_view(): void
    {
        $this->actingAs(User::factory()->create(['approved_at' => now()]))
            ->get('/health-safety/risk-assessments')
            ->assertForbidden();
    }

    public function test_store_requires_hazards_manage(): void
    {
        $this->actingAs(User::factory()->create(['approved_at' => now()]))
            ->post('/health-safety/risk-assessments', $this->validPayload())
            ->assertForbidden();
    }
}
