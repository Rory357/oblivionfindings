<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsAttachment;
use App\Models\ProcedureAcknowledgementReceipt;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\User;
use App\Support\HealthSafety\SafeWorkProcedureReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /health-safety/procedures — the controlled SWMS document library: gold-standard
 * register payload, param-driven detail, the full lifecycle (submit/approve/
 * request-changes/record-review/archive/restore), the version-stamped attachment
 * library (with its morph IDOR guard), and permission gating.
 */
class SafeWorkProcedureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        if ($r = Role::where('name', $role)->first()) {
            $user->roles()->attach($r);
        }

        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => Site::factory()->create()->id]);

        return $user;
    }

    private function hsOfficer(): User
    {
        return $this->userWithRole('health_safety_officer'); // procedures.{view,create,manage,approve}
    }

    /* ---------------- Register payload ---------------- */

    public function test_register_renders_gold_standard_payload(): void
    {
        SafeWorkProcedure::factory()->approved()->create(['category' => 'manual_handling']);
        SafeWorkProcedure::factory()->draft()->create();
        SafeWorkProcedure::factory()->reviewOverdue()->create(['category' => 'medication']);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/procedures')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/procedures/index')
                ->has('procedures.data', 3)
                ->has('tabCounts.all')
                ->has('hero.library.approved')
                ->has('hero.attention.review_overdue')
                ->has('hero.nz.high_risk_covered')
                ->has('sites')
                ->has('roles')
                ->has('trainingCourses')
                ->has('categories')
                ->has('can.manage')
                ->where('tab', 'all')
                ->where('detail', null)
            );
    }

    public function test_review_due_tab_scopes_to_approved_and_due(): void
    {
        SafeWorkProcedure::factory()->reviewOverdue()->create();   // counts
        SafeWorkProcedure::factory()->reviewDue()->create();       // counts
        SafeWorkProcedure::factory()->approved()->create(['review_date' => now()->addYear()]); // current — excluded
        SafeWorkProcedure::factory()->draft()->create();           // not approved — excluded

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/procedures?tab=review_due')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('procedures.data', 2)->where('tabCounts.review_due', 2));
    }

    public function test_legacy_detail_redirects_to_canonical_procedure(): void
    {
        $procedure = SafeWorkProcedure::factory()->approved()->create();
        $this->actingAs($this->hsOfficer())->get('/health-safety/procedures?procedure='.$procedure->id)->assertRedirect(SafeWorkProcedureReturn::href($procedure->id, '/health-safety/procedures'));
    }

    public function test_category_filter_scopes_results(): void
    {
        SafeWorkProcedure::factory()->create(['category' => 'fire_safety']);
        SafeWorkProcedure::factory()->create(['category' => 'medication']);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/procedures?category=fire_safety')
            ->assertInertia(fn (Assert $page) => $page->has('procedures.data', 1)->where('filters.category', 'fire_safety'));
    }

    /* ---------------- Create / edit ---------------- */

    public function test_store_creates_draft_with_initial_version(): void
    {
        $this->actingAs($this->hsOfficer())
            ->post('/health-safety/procedures', [
                'title' => 'Safe Manual Handling',
                'reference_number' => 'SWP-900',
                'category' => 'manual_handling',
                'purpose' => 'Protect workers',
                'steps' => [['step_number' => 1, 'description' => 'Assess the load', 'safety_notes' => 'Check footing']],
                'ppe_required' => ['Gloves'],
                'applicable_sites' => [], 'applicable_roles' => [],
                'review_frequency_months' => 6,
            ])
            ->assertSessionHas('created_procedure_id');

        $procedure = SafeWorkProcedure::where('reference_number', 'SWP-900')->firstOrFail();
        $this->assertSame('draft', $procedure->status);
        $this->assertSame(1, $procedure->current_version);
        $this->assertSame(1, $procedure->versions()->count());
        $this->assertCount(1, $procedure->steps);
    }

    public function test_update_bumps_version_and_unapproves_on_content_change(): void
    {
        $procedure = SafeWorkProcedure::factory()->approved()->create(['reference_number' => 'SWP-901']);

        $this->actingAs($this->hsOfficer())
            ->put("/health-safety/procedures/{$procedure->id}", [
                'title' => 'Updated title',
                'reference_number' => 'SWP-901',
                'category' => $procedure->category,
                'change_summary' => 'Clarified step 2', ...$this->guards($procedure),
            ])
            ->assertSessionHas('success');

        $procedure->refresh();
        $this->assertSame('under_review', $procedure->status); // content change un-approves
        $this->assertSame(2, $procedure->current_version);
        $this->assertSame('Clarified step 2', $procedure->versions()->latest('version_number')->first()->change_summary);
    }

    public function test_update_requires_change_summary(): void
    {
        $procedure = SafeWorkProcedure::factory()->draft()->create(['reference_number' => 'SWP-902']);

        $this->actingAs($this->hsOfficer())
            ->put("/health-safety/procedures/{$procedure->id}", [
                'title' => 'x',
                'reference_number' => 'SWP-902',
                'category' => $procedure->category,
            ])
            ->assertSessionHasErrors('change_summary');
    }

    /* ---------------- Lifecycle ---------------- */

    public function test_submit_for_review_moves_draft_to_under_review(): void
    {
        $procedure = SafeWorkProcedure::factory()->draft()->create();

        $this->actingAs($this->hsOfficer())->post("/health-safety/procedures/{$procedure->id}/submit-for-review", $this->guards($procedure))->assertSessionHas('success');

        $this->assertSame('under_review', $procedure->fresh()->status);
    }

    public function test_approve_signs_off_and_sets_review_from_cadence(): void
    {
        $procedure = SafeWorkProcedure::factory()->underReview()->create(['review_frequency_months' => 6, 'review_date' => null]);
        $officer = $this->hsOfficer();

        $this->actingAs($officer)->post("/health-safety/procedures/{$procedure->id}/approve", $this->guards($procedure))->assertSessionHas('success');

        $procedure->refresh();
        $this->assertSame('approved', $procedure->status);
        $this->assertSame($officer->id, $procedure->approved_by);
        $this->assertNotNull($procedure->approved_at);
        $this->assertNotNull($procedure->review_date); // computed from cadence
    }

    public function test_request_changes_returns_to_draft(): void
    {
        $procedure = SafeWorkProcedure::factory()->approved()->create();

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/procedures/{$procedure->id}/request-changes", ['note' => 'Needs more detail', ...$this->guards($procedure)])
            ->assertSessionHas('success');

        $this->assertSame('draft', $procedure->fresh()->status);
    }

    public function test_record_review_sets_new_date_and_snapshots(): void
    {
        $procedure = SafeWorkProcedure::factory()->reviewOverdue()->create();
        $before = $procedure->versions()->count();

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/procedures/{$procedure->id}/record-review", ['review_date' => now()->addMonths(6)->toDateString(), 'review_note' => 'Review completed for approval.', ...$this->guards($procedure)])
            ->assertSessionHas('success');

        $procedure->refresh();
        $this->assertSame('under_review', $procedure->status); // Prior publication remains available pending explicit approval
        $this->assertTrue($procedure->review_date->isFuture());
        $this->assertSame($before + 1, $procedure->versions()->count());
    }

    public function test_archive_then_restore_round_trips_status(): void
    {
        $procedure = SafeWorkProcedure::factory()->approved()->create();

        $this->actingAs($this->hsOfficer())->post("/health-safety/procedures/{$procedure->id}/archive", $this->guards($procedure))->assertSessionHas('success');
        $procedure->refresh();
        $this->assertSame('archived', $procedure->status);
        $this->assertSame('approved', $procedure->previous_status);

        $this->actingAs($this->hsOfficer())->post("/health-safety/procedures/{$procedure->id}/restore", $this->guards($procedure))->assertSessionHas('success');
        $procedure->refresh();
        $this->assertSame('draft', $procedure->status);
        $this->assertNull($procedure->published_version_id);
        $this->assertNull($procedure->previous_status);
    }

    /* ---------------- Attachment library ---------------- */

    public function test_upload_attaches_version_stamped_document(): void
    {
        Storage::fake('private');
        $procedure = SafeWorkProcedure::factory()->approved()->create(['current_version' => 3]);

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/procedures/{$procedure->id}/attachments", [
                'file' => UploadedFile::fake()->create('swms.pdf', 120, 'application/pdf'),
                'description' => 'Master SWMS', ...$this->guards($procedure),
            ])
            ->assertSessionHas('success');

        $attachment = $procedure->attachments()->firstOrFail();
        $this->assertSame('swms.pdf', $attachment->original_name);
        $this->assertSame(4, $attachment->version_at_upload);
        $this->assertSame('private', $attachment->disk);
        Storage::disk('private')->assertExists($attachment->path);
    }

    public function test_attachment_cannot_be_reached_through_another_procedure_idor(): void
    {
        Storage::fake('private');
        $a = SafeWorkProcedure::factory()->create();
        $b = SafeWorkProcedure::factory()->create();
        $attachment = HsAttachment::create([
            'attachable_type' => $a->getMorphClass(),
            'attachable_id' => $a->id,
            'uploaded_by' => $this->hsOfficer()->id,
            'original_name' => 'a.pdf',
            'path' => 'health-safety/procedures/'.$a->id.'/a.pdf',
            'disk' => 'private',
        ]);

        // Reaching A's attachment through B's id must 404 (morph type + id guard).
        $this->actingAs($this->hsOfficer())
            ->get("/health-safety/procedures/{$b->id}/attachments/{$attachment->id}/download")
            ->assertNotFound();

        $this->actingAs($this->hsOfficer())
            ->delete("/health-safety/procedures/{$b->id}/attachments/{$attachment->id}", $this->guards($b))
            ->assertNotFound();
    }

    /* ---------------- Permission gating ---------------- */

    public function test_no_permission_user_is_forbidden(): void
    {
        // board_trustee has no procedures.* (support_worker now holds procedures.view
        // for the frontline /hr/my + acknowledge flow).
        $this->actingAs($this->userWithRole('board_trustee'))
            ->get('/health-safety/procedures')
            ->assertForbidden();
    }

    public function test_view_only_user_cannot_create_or_approve(): void
    {
        $procedure = SafeWorkProcedure::factory()->underReview()->create();
        $auditor = $this->userWithRole('auditor'); // procedures.view only

        $this->actingAs($auditor)->get('/health-safety/procedures')->assertOk();
        $this->actingAs($auditor)->post('/health-safety/procedures', [
            'title' => 'x', 'reference_number' => 'SWP-950', 'category' => 'manual_handling',
        ])->assertForbidden();
        $this->actingAs($auditor)->post("/health-safety/procedures/{$procedure->id}/approve")->assertForbidden();
    }

    /* ---------------- Cross-module resolution scopes ---------------- */

    public function test_applicable_to_site_scope_includes_site_and_org_wide_approved(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $forSite = SafeWorkProcedure::factory()->approved()->create(['applicable_sites' => [$site->id]]);
        $orgWide = SafeWorkProcedure::factory()->approved()->create(['applicable_sites' => []]);
        $forOther = SafeWorkProcedure::factory()->approved()->create(['applicable_sites' => [$other->id]]);
        $draftForSite = SafeWorkProcedure::factory()->draft()->create(['applicable_sites' => [$site->id]]);

        $ids = SafeWorkProcedure::applicableToSite($site->id)->pluck('id');

        $this->assertTrue($ids->contains($forSite->id));
        $this->assertTrue($ids->contains($orgWide->id));        // empty applicable_sites = org-wide
        $this->assertFalse($ids->contains($forOther->id));      // a different site
        $this->assertFalse($ids->contains($draftForSite->id));  // not approved
    }

    public function test_applicable_to_roles_scope_includes_role_and_org_wide_approved(): void
    {
        $forRole = SafeWorkProcedure::factory()->approved()->create(['applicable_roles' => ['support_worker']]);
        $orgWide = SafeWorkProcedure::factory()->approved()->create(['applicable_roles' => []]);
        $forOther = SafeWorkProcedure::factory()->approved()->create(['applicable_roles' => ['team_lead']]);

        $ids = SafeWorkProcedure::applicableToRoles(['support_worker'])->pluck('id');

        $this->assertTrue($ids->contains($forRole->id));
        $this->assertTrue($ids->contains($orgWide->id));
        $this->assertFalse($ids->contains($forOther->id));
    }

    /* ---------------- Acknowledgement ---------------- */

    public function test_frontline_worker_can_acknowledge_a_procedure(): void
    {
        $procedure = SafeWorkProcedure::factory()->approved()->create(['current_version' => 2]);
        $worker = $this->userWithRole('support_worker'); // now holds procedures.view

        $this->actingAs($worker)
            ->post("/health-safety/procedures/{$procedure->id}/acknowledge", ['published_version_id' => $procedure->published_version_id])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('procedure_acknowledgement_receipts', [
            'safe_work_procedure_id' => $procedure->id,
            'user_id' => $worker->id,
            'version_id' => $procedure->published_version_id,
        ]);

        // Re-acknowledging updates in place, never duplicates (unique procedure+user).
        $this->actingAs($worker)->post("/health-safety/procedures/{$procedure->id}/acknowledge", ['published_version_id' => $procedure->published_version_id]);
        $this->assertSame(1, ProcedureAcknowledgementReceipt::query()
            ->where('safe_work_procedure_id', $procedure->id)->where('user_id', $worker->id)->count());

        // The detail payload reflects the current user's acknowledgement.
        $this->actingAs($worker)
            ->get("/health-safety/procedures/{$procedure->id}")
            ->assertInertia(fn (Assert $page) => $page->where('detail.acknowledged', true)->where('detail.acknowledged_count', null));
    }

    private function guards(SafeWorkProcedure $procedure): array
    {
        $procedure = $procedure->fresh();

        return ['expected_version' => $procedure->current_version, 'expected_lock_version' => $procedure->lock_version];
    }

    /* ---------------- Hazard-type mapping (#4) + export (#7) ---------------- */

    public function test_mitigating_hazard_type_scope_maps_clean_overlaps_only(): void
    {
        $manual = SafeWorkProcedure::factory()->approved()->create(['category' => 'manual_handling']);
        $fire = SafeWorkProcedure::factory()->approved()->create(['category' => 'fire_safety']);
        $other = SafeWorkProcedure::factory()->approved()->create(['category' => 'medication']);

        $this->assertTrue(SafeWorkProcedure::mitigatingHazardType('manual_handling')->pluck('id')->contains($manual->id));
        $this->assertTrue(SafeWorkProcedure::mitigatingHazardType('fire')->pluck('id')->contains($fire->id)); // fire → fire_safety
        $this->assertFalse(SafeWorkProcedure::mitigatingHazardType('manual_handling')->pluck('id')->contains($other->id));
        // Unmapped hazard types surface nothing (honest, not a guess).
        $this->assertCount(0, SafeWorkProcedure::mitigatingHazardType('security')->get());
        $this->assertCount(0, SafeWorkProcedure::mitigatingHazardType(null)->get());
    }

    public function test_export_streams_filtered_csv(): void
    {
        SafeWorkProcedure::factory()->approved()->create(['reference_number' => 'SWP-EX1', 'category' => 'fire_safety']);
        SafeWorkProcedure::factory()->approved()->create(['reference_number' => 'SWP-EX2', 'category' => 'medication']);

        $res = $this->actingAs($this->hsOfficer())->get('/health-safety/procedures/export?category=fire_safety');
        $res->assertOk();
        $body = $res->streamedContent();
        $this->assertStringContainsString('SWP-EX1', $body);
        $this->assertStringNotContainsString('SWP-EX2', $body);
        $this->assertStringContainsString('Reference,Title,Category', $body);
    }
}
