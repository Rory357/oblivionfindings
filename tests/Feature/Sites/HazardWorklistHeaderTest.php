<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Notifications\Sites\HazardAssignedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HazardWorklistHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function reader(Site $site): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        $permission = Permission::firstOrCreate(['key' => 'hazards.view'], ['description' => 'Read hazards', 'module' => 'Test', 'group' => 'test']);
        $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);

        return $user;
    }

    private function hazard(Site $site, User $user, string $reference, array $attributes = []): SiteHazard
    {
        return SiteHazard::withoutEvents(fn () => SiteHazard::forceCreate([
            'site_id' => $site->id, 'reported_by_user_id' => $user->id,
            'reference_number' => $reference, 'hazard_type' => 'slip_trip_fall',
            'severity' => 'high', 'likelihood' => 'likely', 'risk_rating' => 'extreme',
            'description' => 'Needle synthetic hazard', 'status' => 'open',
            'due_date' => '2026-10-08', 'created_at' => '2026-10-07 12:00:00',
            ...$attributes,
        ]));
    }

    public function test_attention_counts_lists_and_exports_match_the_same_refinements(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['type' => 'house']);
        $reader = $this->reader($site);
        $this->hazard($site, $reader, 'HZ-TODAY', ['assigned_to_user_id' => $reader->id]);
        $this->hazard($site, $reader, 'HZ-OVERDUE', ['status' => 'in_progress', 'due_date' => '2026-10-07']);
        $this->hazard($site, $reader, 'HZ-MITIGATED', ['status' => 'mitigated']);
        $this->hazard($site, $reader, 'HZ-CLOSED', ['status' => 'closed']);
        $this->hazard($site, $reader, 'HZ-OTHER', ['description' => 'Other hazard']);
        $this->hazard(Site::factory()->create(['type' => 'house']), $reader, 'HZ-FOREIGN');
        $filters = ['q' => 'Needle', 'severity' => 'high', 'site_id' => $site->id, 'from' => '2026-10-08', 'to' => '2026-10-08'];
        foreach (['overdue' => 'HZ-OVERDUE', 'due_soon' => 'HZ-TODAY', 'unassigned' => 'HZ-OVERDUE', 'mitigated' => 'HZ-MITIGATED', 'closed_only' => 'HZ-CLOSED'] as $tab => $reference) {
            $query = http_build_query([...$filters, 'tab' => $tab]);
            $this->actingAs($reader)->get('/compliance/hazards?'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('hazards.total', 1)->where('hazards.data.0.reference_number', $reference)
                ->where('tabCounts.all', 4)->where('tabCounts.critical', 2)->where('tabCounts.closed', 2)
                ->where('hero.live.overdue', 1)->where('hero.attention.due_soon', 1)->where('hero.attention.unassigned', 1)
                ->where('hero.attention.mitigated', 1)->where('hero.attention.closed_period', 1)->missing('nzBadges'));
            foreach (['/compliance/hazards/export', "/sites/{$site->id}/hazards/export"] as $url) {
                $csv = $this->get($url.'?'.$query)->assertOk()->streamedContent();
                $lines = array_values(array_filter(explode("\n", trim($csv))));
                $this->assertCount(2, $lines);
                $this->assertStringContainsString($reference, $csv);
                $this->assertStringNotContainsString('HZ-FOREIGN', $csv);
                $this->assertStringContainsString('2026-10-08', $lines[1]);
            }
        }
    }

    public function test_dashboard_open_work_destination_and_exports_include_open_and_in_progress_at_the_selected_site(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $reader = $this->reader($site);
        $this->hazard($site, $reader, 'HZ-ACTIVE-OPEN');
        $this->hazard($site, $reader, 'HZ-ACTIVE-WORKING', ['status' => 'in_progress']);
        $this->hazard($site, $reader, 'HZ-INACTIVE-MITIGATED', ['status' => 'mitigated']);
        $this->hazard($site, $reader, 'HZ-INACTIVE-CLOSED', ['status' => 'closed']);
        $this->hazard(Site::factory()->create(['type' => 'house']), $reader, 'HZ-ACTIVE-FOREIGN');
        $query = http_build_query(['tab' => 'active', 'site_id' => $site->id]);
        $this->actingAs($reader)->get('/compliance/hazards?'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'active')->where('filters.site_id', (string) $site->id)
            ->where('tabCounts.active', 2)->where('tabCounts.all', 4)->where('hazards.total', 2));
        foreach (['/compliance/hazards/export', "/sites/{$site->id}/hazards/export"] as $url) {
            $csv = $this->get($url.'?'.$query)->assertOk()->streamedContent();
            $this->assertStringContainsString('HZ-ACTIVE-OPEN', $csv);
            $this->assertStringContainsString('HZ-ACTIVE-WORKING', $csv);
            foreach (['HZ-INACTIVE-MITIGATED', 'HZ-INACTIVE-CLOSED', 'HZ-ACTIVE-FOREIGN'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $csv);
            }
        }
    }

    public function test_nz_logged_day_boundaries_and_due_today_are_consistent_and_ranges_are_validated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['type' => 'house']);
        $reader = $this->reader($site);
        $before = $this->hazard($site, $reader, 'HZ-BEFORE', ['created_at' => '2026-10-07 10:59:59']);
        $first = $this->hazard($site, $reader, 'HZ-FIRST', ['created_at' => '2026-10-07 11:00:00']);
        $last = $this->hazard($site, $reader, 'HZ-LAST', ['created_at' => '2026-10-08 10:59:59']);
        $after = $this->hazard($site, $reader, 'HZ-AFTER', ['created_at' => '2026-10-08 11:00:00']);
        $this->actingAs($reader)->get('/compliance/hazards?from=2026-10-08&to=2026-10-08')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hazards.total', 2)->where('hero.live.overdue', 0)->where('hero.attention.due_soon', 2)
            ->where('hazards.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$first->id, $last->id])->sort()->values()->all())
            ->where('hazards.data.0.flags.overdue', false)->where('hazards.data.0.flags.due_soon', true));
        $this->get('/hazards/'.$first->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.created_at', '2026-10-07T11:00:00.000000Z'));
        foreach ([$before, $first, $last, $after] as $hazard) {
            $this->assertFalse($hazard->isOverdue());
            $this->assertTrue($hazard->isDueSoon());
        }
        foreach (['/compliance/hazards', '/compliance/hazards/export', "/sites/{$site->id}/hazards", "/sites/{$site->id}/hazards/export"] as $url) {
            $this->getJson($url.'?from=2026-10-09&to=2026-10-08')->assertUnprocessable();
            $this->getJson($url.'?from=not-a-date')->assertUnprocessable();
        }
    }

    public function test_export_keeps_all_matching_rows_across_chunks_without_the_old_five_thousand_row_cutoff(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $reader = $this->reader($site);
        $first = $this->hazard($site, $reader, 'HZ-BULK-0');
        $attributes = $first->getAttributes();
        unset($attributes['id']);
        $rows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = [...$attributes, 'reference_number' => 'HZ-BULK-'.$i];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('site_hazards')->insert($chunk);
        }
        $this->hazard(Site::factory()->create(['type' => 'house']), $reader, 'HZ-BULK-HIDDEN');
        $csv = $this->actingAs($reader)->get('/compliance/hazards/export?q=HZ-BULK')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(5002, $lines);
        $this->assertCount(5002, array_unique($lines));
        $this->assertStringContainsString('HZ-BULK-5000', $csv);
        $this->assertStringContainsString('HZ-BULK-0,', $csv);
        $this->assertStringNotContainsString('HZ-BULK-HIDDEN', $csv);
    }

    public function test_owner_and_action_assignments_use_the_same_current_staff_boundary_as_the_picker(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->reader($site);
        foreach (['hazards.create', 'hazards.assign', 'hazards.manage', 'sites.viewAny'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'module' => 'Test', 'group' => 'test']);
            $writer->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }
        $current = $this->reader($site);
        $remote = $this->reader(Site::factory()->create(['type' => 'house']));
        $inactive = $this->reader($site);
        $inactive->hrEmployeeProfile()->update(['is_active' => false, 'end_date' => now()->subDay()->toDateString()]);
        $hazard = $this->hazard($site, $writer, 'HZ-ASSIGN');
        $this->actingAs($writer)->get('/hazards/'.$hazard->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.assignable_staff', fn ($staff) => collect($staff)->pluck('id')->sort()->values()->all() === collect([$writer->id, $current->id])->sort()->values()->all()));
        foreach ([$remote, $inactive] as $rejected) {
            $this->postJson("/hazards/{$hazard->id}/assign", ['assigned_to_user_id' => $rejected->id])->assertUnprocessable();
            $this->postJson("/hazards/{$hazard->id}/actions", ['title' => 'Rejected assignment', 'assigned_to_user_id' => $rejected->id])->assertUnprocessable();
            $this->postJson("/sites/{$site->id}/hazards", ['hazard_type' => 'slip_trip_fall', 'severity' => 'low', 'likelihood' => 'rare', 'description' => 'Rejected initial assignment', 'assigned_to_user_id' => $rejected->id])->assertUnprocessable();
        }
        $this->assertSame(1, SiteHazard::count());
        $this->assertSame(0, $hazard->actions()->count());
        $this->assertNull($hazard->fresh()->assigned_to_user_id);
        Notification::assertNothingSent();
        $this->post("/hazards/{$hazard->id}/assign", ['assigned_to_user_id' => $current->id])->assertRedirect();
        $this->assertSame($current->id, $hazard->fresh()->assigned_to_user_id);
        Notification::assertSentTo($current, HazardAssignedNotification::class);
    }

    public function test_hazard_read_permission_opens_only_approved_hazard_records_without_granting_site_profiles_or_writes(): void
    {
        Storage::fake('private');
        $site = Site::factory()->create(['type' => 'house']);
        $foreign = Site::factory()->create(['type' => 'house']);
        $reader = $this->reader($site);
        $hazard = $this->hazard($site, $reader, 'HZ-READ');
        $hidden = $this->hazard($foreign, $reader, 'HZ-HIDDEN');
        $path = "hazards/{$hazard->id}/documents/receipt.txt";
        Storage::disk('private')->put($path, 'Synthetic authorized evidence');
        $hazard->forceFill(['document_paths' => [['path' => $path, 'name' => 'receipt.txt']]])->saveQuietly();
        $this->actingAs($reader)->get('/hazards/'.$hazard->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.id', $hazard->id)->where('detail.can.manage', false)->where('detail.can.assign', false)->where('detail.can.close', false)->where('detail.can.create', false));
        $this->get("/sites/{$site->id}/hazards")->assertOk();
        $this->get("/hazards/{$hazard->id}")->assertOk();
        $this->get("/hazards/{$hazard->id}/media/document/0")->assertOk();
        $this->get("/sites/{$site->id}")->assertForbidden();
        $this->post("/hazards/{$hazard->id}/status", ['status' => 'in_progress'])->assertForbidden();
        $this->get("/compliance/hazards?site_id={$foreign->id}")->assertForbidden();
        $this->get("/sites/{$site->id}/hazards/export?site_id={$foreign->id}")->assertForbidden();
        foreach (["/hazards/{$hidden->id}", "/hazards/{$hidden->id}/media/document/0", "/sites/{$foreign->id}/hazards", "/sites/{$foreign->id}/hazards/export"] as $url) {
            $this->get($url)->assertNotFound()->assertDontSee('HZ-HIDDEN');
        }
        $this->assertSame('open', $hazard->fresh()->status);
    }
}
