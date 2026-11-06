<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Policies\SafeguardingConcernPolicy;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Readiness audit, Stephan's 6 Nov 2026 choices (by pop-up via MASTER):
 * - P2 "Block them": the staff member a concern names as the alleged perpetrator can't open it, find it or lead it.
 * - P3 "Their report + status only": someone who sees a concern only as its reporter gets their report and its status.
 * - P6 "Remove it": "View reports" no longer opens safeguarding at every Site; safeguarding.viewAllSites does.
 * Each rule is proved both ways: the block, and that the people who should see it still do.
 */
final class SafeguardingNamedPersonReporterAndSiteTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $otherSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('public');
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->otherSite = Site::factory()->create(['is_active' => true]);
    }

    private function staff(array $keys, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function concern(array $facts = []): SafeguardingConcern
    {
        return SafeguardingConcern::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'investigating',
            'is_sensitive' => false,
            'description' => 'What the reporter wrote',
            ...$facts,
        ]);
    }

    private function namingAsPerpetrator(User $user, array $facts = []): SafeguardingConcern
    {
        return $this->concern(['alleged_perpetrator_type' => User::class, 'alleged_perpetrator_id' => $user->id, ...$facts]);
    }

    // ---- P2 ----------------------------------------------------------------------------------------------------

    public function test_the_alleged_perpetrator_cannot_open_the_concern_and_gets_a_plain_message(): void
    {
        $manager = $this->staff(['safeguarding.viewAny', 'safeguarding.update', 'safeguarding.viewSensitive']);
        $concern = $this->namingAsPerpetrator($manager);

        $this->actingAs($manager)->get("/safeguarding/{$concern->id}")
            ->assertForbidden()
            ->assertSee(SafeguardingConcernPolicy::NAMED_PERSON_MESSAGE);
        $this->assertFalse($manager->can('view', $concern));
        $this->assertFalse($manager->can('update', $concern));
        $this->assertFalse($manager->can('investigate', $concern));

        // A colleague with the same access still opens it.
        $colleague = $this->staff(['safeguarding.viewAny', 'safeguarding.update', 'safeguarding.viewSensitive']);
        $this->actingAs($colleague)->get("/safeguarding/{$concern->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.description', 'What the reporter wrote'));
    }

    public function test_the_alleged_perpetrator_never_finds_it_in_the_register_or_its_counts(): void
    {
        $manager = $this->staff(['safeguarding.viewAny']);
        $this->namingAsPerpetrator($manager);
        $other = $this->concern();

        $this->actingAs($manager)->get('/safeguarding')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)->where('rows.data.0.id', $other->id)->where('tabCounts.all', 1)
            ->where('hero.openWork.open.value', 1));

        $colleague = $this->staff(['safeguarding.viewAny']);
        $this->actingAs($colleague)->get('/safeguarding')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 2));
    }

    public function test_the_alleged_perpetrator_cannot_be_made_its_lead_or_offered_as_one(): void
    {
        $lead = $this->staff(['safeguarding.viewAny', 'safeguarding.update']);
        $named = $this->staff(['safeguarding.viewAny', 'safeguarding.update']);
        $concern = $this->namingAsPerpetrator($named);

        $this->actingAs($lead)->from("/safeguarding/{$concern->id}")
            ->post("/safeguarding/{$concern->id}/assign", ['assigned_to_user_id' => $named->id])
            ->assertSessionHasErrors('assigned_to_user_id');
        $this->assertNull($concern->fresh()->assigned_to_user_id);

        $this->actingAs($lead)->get("/safeguarding/{$concern->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.assignable_staff', fn ($staff) => ! collect($staff)->pluck('id')->contains($named->id)
                && collect($staff)->pluck('id')->contains($lead->id)));

        $this->actingAs($lead)->post("/safeguarding/{$concern->id}/assign", ['assigned_to_user_id' => $lead->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($lead->id, (int) $concern->fresh()->assigned_to_user_id);
    }

    public function test_a_named_client_or_a_free_text_name_does_not_block_a_staff_member_with_the_same_id(): void
    {
        $manager = $this->staff(['safeguarding.viewAny']);
        $concern = $this->concern(['alleged_perpetrator_type' => \App\Models\Client::class, 'alleged_perpetrator_id' => $manager->id]);

        $this->assertFalse(app(SafeguardingConcernPolicy::class)->namesAsAllegedPerpetrator($manager, $concern));
        $this->actingAs($manager)->get("/safeguarding/{$concern->id}")->assertOk();
    }

    // ---- P3 ----------------------------------------------------------------------------------------------------

    public function test_a_reporter_sees_their_report_and_status_only(): void
    {
        $reporter = $this->staff(['safeguarding.create']);
        $concern = $this->concern([
            'reported_by_user_id' => $reporter->id,
            'alleged_perpetrator_type' => 'other',
            'alleged_perpetrator_name' => 'Named person',
            'triage_notes' => 'Lead triage notes',
            'closure_summary' => null,
        ]);
        $concern->investigations()->create([
            'investigation_type' => 'internal', 'status' => 'in_progress', 'findings' => 'Private findings',
            'started_at' => now(),
        ]);

        $this->actingAs($reporter)->get("/safeguarding/{$concern->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.reporter_view', true)
            ->where('detail.description', 'What the reporter wrote')
            ->where('detail.status', 'investigating')
            ->missing('detail.investigations')
            ->missing('detail.people')
            ->missing('detail.triage')
            ->missing('detail.action_plans')
            ->missing('detail.attachments')
            ->missing('detail.hs_event'));

        // The safeguarding lead still sees the whole record.
        $lead = $this->staff(['safeguarding.viewAny', 'safeguarding.update']);
        $this->actingAs($lead)->get("/safeguarding/{$concern->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('detail.reporter_view')
            ->where('detail.investigations.0.findings', 'Private findings')
            ->where('detail.people.alleged_perpetrator', 'Named person'));
    }

    public function test_a_reporter_who_also_leads_it_or_holds_register_access_keeps_the_whole_record(): void
    {
        $reporterLead = $this->staff(['safeguarding.create']);
        $led = $this->concern(['reported_by_user_id' => $reporterLead->id, 'assigned_to_user_id' => $reporterLead->id]);
        $this->actingAs($reporterLead)->get("/safeguarding/{$led->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('detail.reporter_view')->has('detail.investigations'));

        $manager = $this->staff(['safeguarding.viewAny']);
        $reported = $this->concern(['reported_by_user_id' => $manager->id]);
        $this->actingAs($manager)->get("/safeguarding/{$reported->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('detail.reporter_view')->has('detail.people'));
    }

    public function test_a_reporter_downloads_only_the_files_they_added(): void
    {
        $reporter = $this->staff(['safeguarding.create']);
        $lead = $this->staff(['safeguarding.viewAny', 'safeguarding.update']);
        $concern = $this->concern(['reported_by_user_id' => $reporter->id]);
        $theirs = $this->attachment($concern, $reporter);
        $leads = $this->attachment($concern, $lead);

        $this->actingAs($reporter)->get("/safeguarding/{$concern->id}/attachments/{$theirs->id}/download")->assertOk();
        $this->actingAs($reporter)->get("/safeguarding/{$concern->id}/attachments/{$leads->id}/download")->assertForbidden();
        $this->actingAs($lead)->get("/safeguarding/{$concern->id}/attachments/{$theirs->id}/download")->assertOk();
    }

    // ---- P6 ----------------------------------------------------------------------------------------------------

    public function test_view_reports_no_longer_opens_safeguarding_at_other_sites(): void
    {
        $hr = $this->staff(['safeguarding.viewAny', 'reports.viewAny']);
        $own = $this->concern();
        $elsewhere = $this->concern(['site_id' => $this->otherSite->id]);

        $this->actingAs($hr)->get("/safeguarding/{$elsewhere->id}")->assertNotFound();
        $this->actingAs($hr)->get("/safeguarding/{$own->id}")->assertOk();
        $this->actingAs($hr)->get('/safeguarding')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)->where('rows.data.0.id', $own->id));
        $this->assertFalse($hr->can('view', $elsewhere));
    }

    public function test_the_safeguarding_all_sites_key_opens_every_site(): void
    {
        $lead = $this->staff(['safeguarding.viewAny', 'safeguarding.viewAllSites']);
        $elsewhere = $this->concern(['site_id' => $this->otherSite->id]);

        $this->actingAs($lead)->get("/safeguarding/{$elsewhere->id}")->assertOk();
        $this->actingAs($lead)->get('/safeguarding')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rows.total', 1));
    }

    public function test_the_migration_gives_the_key_to_admin_only(): void
    {
        $key = Permission::where('key', 'safeguarding.viewAllSites')->sole();
        DB::table('role_permission')->where('permission_id', $key->id)->delete();
        DB::table('permissions')->where('id', $key->id)->delete();

        $migration = require database_path('migrations/2026_11_06_110000_add_safeguarding_view_all_sites.php');
        $migration->up();
        $migration->up(); // safe to run twice

        $id = Permission::where('key', 'safeguarding.viewAllSites')->sole()->id;
        $holders = DB::table('role_permission')->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->where('permission_id', $id)->pluck('roles.name')->all();
        $this->assertSame(['admin'], $holders);
        foreach (['provider_manager', 'hr', 'auditor'] as $role) {
            $this->assertFalse(Role::where('name', $role)->sole()->permissions()->where('key', 'safeguarding.viewAllSites')->exists());
        }
    }

    private function attachment(SafeguardingConcern $concern, User $uploader)
    {
        return $concern->attachments()->create([
            'uploaded_by' => $uploader->id,
            'disk' => 'public',
            'original_name' => 'note.jpg',
            'path' => UploadedFile::fake()->image('e.jpg')->store('safeguarding_attachments', 'public'),
            'mime' => 'image/jpeg',
            'size' => 1024,
            'is_sensitive' => false,
        ]);
    }
}
