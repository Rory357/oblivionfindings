<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkforceShiftReadPermissionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private Client $client;

    private Client $foreignClient;

    private ServiceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        AppSetting::query()->updateOrCreate(['key' => 'features.rostering.publish'], ['value' => true]);
        app()->forgetInstance(RosteringFeatureFlags::class);
        $this->site = Site::factory()->create(['name' => 'READ TEST own House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['name' => 'PRIVATE READ TEST House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['is_active' => true, 'site_id' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $this->foreignClient = Client::factory()->create(['site_id' => $this->foreignSite->id, 'service_context_id' => $this->context->id]);
    }

    public function test_view_any_scheduler_reads_other_workers_open_and_draft_shifts_without_manage_any(): void
    {
        $reader = $this->actor($this->site, ['rostering.viewAny', 'shifts.viewAny'], 'coordinator');
        $worker = $this->actor($this->site, ['shifts.viewAssigned']);
        $assigned = $this->shift($worker, ['created_by' => $reader->id]);
        $draft = $this->shift($worker, ['status' => 'draft', 'starts_at' => $assigned->starts_at->copy()->addHours(2), 'ends_at' => $assigned->ends_at->copy()->addHours(2)]);
        $open = $this->shift(null, ['starts_at' => $assigned->starts_at->copy()->addHours(4), 'ends_at' => $assigned->ends_at->copy()->addHours(4)]);
        $this->assertFalse($reader->canDo('shifts.manageAny'));
        $this->assertFalse($reader->canDo('reports.viewAny'));
        $this->assertNull($assigned->published_at);
        $this->assertTrue(app(RosteringFeatureFlags::class)->publishEnabled());
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->indexUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/index')->has('shifts.data', 3)
            ->where('shifts.data.0.id', $assigned->id)->where('shifts.data.1.id', $draft->id)->where('shifts.data.2.id', $open->id)
            ->where('stats.total', 3));
        foreach ([$assigned, $draft, $open] as $row) {
            $this->get(route('operations.shifts.show', $row))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('operations/shifts/show')->where('shift.id', $row->id)->where('can.assign_shift', false));
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_view_any_reader_retains_canonical_site_worker_and_client_privacy(): void
    {
        $reader = $this->actor($this->site, ['rostering.viewAny', 'shifts.viewAny'], 'coordinator');
        $localWorker = $this->actor($this->site, ['shifts.viewAssigned']);
        $foreignWorker = $this->actor($this->foreignSite, ['shifts.viewAssigned']);
        $legacy = $this->shift($localWorker, ['site_id' => null]);
        $foreign = $this->shift($foreignWorker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id, 'location' => 'PRIVATE SHIFT foreign']);
        $conflicting = $this->shift($localWorker, ['client_id' => $this->foreignClient->id, 'location' => 'PRIVATE SHIFT conflicting']);
        $inactiveWorker = $this->actor($this->site, ['shifts.viewAssigned']);
        $inactiveWorker->hrEmployeeProfile->update(['is_active' => false]);
        $inactive = $this->shift($inactiveWorker, ['location' => 'PRIVATE SHIFT inactive worker']);
        $closedSite = Site::factory()->create(['is_active' => false, 'archived' => false, 'archived_at' => null]);
        $closedClient = Client::factory()->create(['site_id' => $closedSite->id]);
        $reader->hrEmployeeProfile->update(['secondary_site_ids' => [$closedSite->id]]);
        $closed = $this->shift(null, ['site_id' => $closedSite->id, 'client_id' => $closedClient->id, 'location' => 'PRIVATE SHIFT inactive Site']);
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->indexUrl())->assertOk()->assertDontSee('PRIVATE SHIFT', false)
            ->assertInertia(fn (Assert $page) => $page->component('operations/shifts/index')->has('shifts.data', 1)->where('shifts.data.0.id', $legacy->id));
        $this->get(route('operations.shifts.show', $legacy))->assertOk();
        foreach ([$foreign, $conflicting, $inactive, $closed] as $row) {
            $this->get(route('operations.shifts.show', $row))->assertForbidden()->assertDontSee($row->location, false);
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_assigned_only_scheduler_still_reads_only_own_published_shifts(): void
    {
        $reader = $this->actor($this->site, ['rostering.viewAny', 'shifts.viewAssigned'], 'coordinator');
        $other = $this->actor($this->site, ['shifts.viewAssigned']);
        $published = $this->shift($reader, ['published_at' => now()]);
        $unpublished = $this->shift($reader);
        $otherPublished = $this->shift($other, ['published_at' => now()]);
        $this->assertFalse($reader->canDo('shifts.viewAny'));
        $this->assertFalse($reader->canDo('shifts.manageAny'));
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get($this->indexUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/index')->has('shifts.data', 1)->where('shifts.data.0.id', $published->id));
        $this->get(route('operations.shifts.show', $published))->assertOk();
        $this->get(route('operations.shifts.show', $unpublished))->assertNotFound();
        $this->get(route('operations.shifts.show', $otherPublished))->assertForbidden();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_frontline_assigned_only_route_and_detail_privacy_remain_intact(): void
    {
        $worker = $this->actor($this->site, ['shifts.viewAssigned']);
        $other = $this->actor($this->site, ['shifts.viewAssigned']);
        $published = $this->shift($worker, ['published_at' => now()]);
        $unpublished = $this->shift($worker);
        $otherPublished = $this->shift($other, ['published_at' => now()]);
        $foreign = $this->shift($worker, ['site_id' => $this->foreignSite->id, 'client_id' => $this->foreignClient->id, 'published_at' => now()]);
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($worker)->get($this->indexUrl())->assertRedirect(route('my-day'));
        $this->get(route('operations.shifts.show', $published))->assertOk();
        $this->get(route('operations.shifts.show', $unpublished))->assertNotFound();
        $this->get(route('operations.shifts.show', $otherPublished))->assertForbidden();
        $this->get(route('operations.shifts.show', $foreign))->assertForbidden();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_view_any_does_not_authorize_editing_or_assigning_another_workers_shift(): void
    {
        $reader = $this->actor($this->site, ['rostering.viewAny', 'shifts.viewAny', 'shifts.update'], 'coordinator');
        $worker = $this->actor($this->site, ['shifts.viewAssigned']);
        $row = $this->shift($worker);
        $state = $this->state();
        $queue = $this->queueState();

        $this->actingAs($reader)->get(route('operations.shifts.show', $row))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/show')->where('shift.id', $row->id)->where('can.assign_shift', false));
        $this->getJson(route('operations.shifts.editable', $row))->assertForbidden();
        $this->putJson(route('operations.shifts.update', $row), ['location' => 'must not save'])->assertForbidden();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    private function actor(Site $site, array $keys, string $role = 'support_worker'): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => $role, 'external_clinical_account' => false]);
        $user->permissionOverrides()->sync(collect($keys)->mapWithKeys(fn ($key) => [Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user;
    }

    private function shift(?User $worker, array $values = []): Shift
    {
        return Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'user_id' => $worker?->id, 'created_by' => $worker?->id,
            'starts_at' => Carbon::parse('2026-10-20 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-20 10:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'scheduled', 'published_at' => null, ...$values])->fresh();
    }

    private function indexUrl(): string
    {
        return route('operations.shifts.index', ['from' => '2026-10-19', 'to' => '2026-10-25']);
    }

    private function state(): array
    {
        $tables = ['shifts', 'shift_tasks', 'shift_series', 'shift_eligibility_overrides', 'coverage_reservations',
            'shift_replacement_requests', 'shift_open_positions', 'site_checklist_runs', 'audit_logs', 'notifications',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'timeline_events',
            'shift_handovers', 'shift_notes', 'timesheets', 'hr_time_entries', 'billing_entries', 'shift_signals', 'shift_signal_outbox'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function queueState(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }
}
