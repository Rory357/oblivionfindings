<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkforceConflictQueueShiftReturnContextTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private ServiceContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-10 00:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['is_active' => true, 'site_id' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
    }

    #[DataProvider('queueWeeks')]
    public function test_actual_queue_shift_handoff_returns_to_the_same_canonical_week_without_writes(string $week): void
    {
        $actor = $this->person(['shifts.viewAny', 'rostering.viewAny']);
        $shift = $this->shift($week);
        $state = $this->state();
        $queue = $this->queue();
        $returnTo = route('operations.rostering.conflicts', ['week' => $week], false);
        $response = $this->actingAs($actor)->get($returnTo)->assertOk();
        $url = $response->inertiaProps('openShifts.0.urls.shift');
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $this->assertSame($returnTo, $query['return_to']);
        $this->assertSame(route('operations.shifts.show', $shift, false), parse_url($url, PHP_URL_PATH));
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('operations/shifts/show')
            ->where('shift.id', $shift->id)->where('can.edit_shift', false)
            ->where('returnContext', ['scope' => 'conflict_queue', 'week' => $week, 'href' => $returnTo, 'label' => 'Conflict queue']));
        $this->get($returnTo)->assertOk()->assertInertia(fn (Assert $page) => $page->where('weekStart', $week));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function queueWeeks(): array
    {
        return ['NZ daylight civil Monday' => ['2026-10-26'], 'NZ daylight-end civil Monday' => ['2026-03-30']];
    }

    #[DataProvider('invalidHints')]
    public function test_malformed_or_unrelated_return_hints_preserve_normal_shift_read_and_default_navigation(mixed $hint): void
    {
        $actor = $this->person(['shifts.viewAny', 'rostering.viewAny']);
        $shift = $this->shift();
        $state = $this->state();
        $queue = $this->queue();
        $this->actingAs($actor)->get(route('operations.shifts.show', ['shift' => $shift, 'return_to' => $hint]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('shift.id', $shift->id)->where('returnContext', null));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function invalidHints(): array
    {
        $path = '/operations/rostering/conflicts';

        return [
            'no hint' => [null],
            'external origin' => ['https://example.invalid'.$path.'?week=2026-10-26'],
            'protocol relative origin' => ['//example.invalid'.$path.'?week=2026-10-26'],
            'unrelated local route' => ['/operations/shifts?week=2026-10-26'],
            'impossible calendar date' => [$path.'?week=2026-02-30'],
            'noncanonical Tuesday' => [$path.'?week=2026-10-27'],
            'datetime instead of civil date' => [$path.'?week=2026-10-26T00%3A00%3A00Z'],
            'duplicate week keys' => [$path.'?week=2026-10-26&week=2026-11-02'],
            'array week' => [$path.'?week%5B0%5D=2026-10-26'],
            'nested return chain' => [$path.'?week=2026-10-26&return_to=%2Fmy-day'],
            'fragment' => [$path.'?week=2026-10-26#finding'],
            'encoded backslash' => ['/operations%5Crostering/conflicts?week=2026-10-26'],
            'encoded control' => [$path.'?week=2026-10-26%0d'],
            'array hint' => [[$path.'?week=2026-10-26']],
        ];
    }

    public function test_return_context_disappears_after_actual_queue_permission_is_withdrawn(): void
    {
        $actor = $this->person(['shifts.viewAny', 'rostering.viewAny']);
        $shift = $this->shift();
        $returnTo = route('operations.rostering.conflicts', ['week' => '2026-10-26'], false);
        $this->actingAs($actor)->get($returnTo)->assertOk();
        $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'rostering.viewAny')->firstOrFail()->id, ['allowed' => false]);
        $actor = $actor->fresh();
        $this->assertTrue($actor->canDo('shifts.viewAny'));
        $this->assertFalse($actor->canDo('rostering.viewAny'));
        $state = $this->state();
        $queue = $this->queue();
        $this->actingAs($actor)->get(route('operations.shifts.show', ['shift' => $shift, 'return_to' => $returnTo]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('returnContext', null));
        $this->getJson($returnTo)->assertForbidden();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public function test_valid_queue_hint_does_not_authorize_a_foreign_site_shift(): void
    {
        $actor = $this->person(['shifts.viewAny', 'rostering.viewAny']);
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $client = Client::factory()->create(['site_id' => $foreign->id, 'service_context_id' => $this->context->id]);
        $shift = $this->shift(values: ['site_id' => $foreign->id, 'client_id' => $client->id, 'location' => 'PRIVATE RETURN source']);
        $state = $this->state();
        $queue = $this->queue();
        $this->actingAs($actor)->get(route('operations.shifts.show', ['shift' => $shift,
            'return_to' => route('operations.rostering.conflicts', ['week' => '2026-10-26'], false)]))->assertForbidden()->assertDontSee('PRIVATE RETURN source', false);
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    private function person(array $keys): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'return-context-'.Str::uuid(), 'label' => 'Synthetic return reader', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function shift(string $week = '2026-10-26', array $values = []): Shift
    {
        $start = Carbon::parse($week.' 09:00:00', 'Pacific/Auckland')->addDay()->utc();

        return Shift::factory()->create(['site_id' => $this->site->id, 'client_id' => $this->client->id, 'service_context_id' => $this->context->id,
            'user_id' => null, 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'status' => 'scheduled', 'published_at' => null,
            'roster_period_id' => null, 'respite_booking_id' => null, ...$values])->fresh();
    }

    private function state(): array
    {
        $tables = ['users', 'permission_user', 'client_user', 'hr_employee_profiles', 'sites', 'clients', 'service_contexts',
            'roster_periods', 'shifts', 'shift_tasks', 'shift_series', 'shift_eligibility_overrides', 'coverage_reservations',
            'shift_replacement_requests', 'shift_open_positions', 'site_checklist_runs', 'audit_logs', 'notifications',
            'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'timeline_events', 'shift_handovers', 'shift_notes',
            'timesheets', 'hr_time_entries', 'billing_entries', 'shift_signals', 'shift_signal_outbox'];
        $sort = ['permission_user' => ['user_id', 'permission_id'], 'client_user' => ['client_id', 'user_id']];

        return collect($tables)->mapWithKeys(function (string $table) use ($sort): array {
            $query = DB::table($table);
            foreach ($sort[$table] ?? ['id'] as $column) {
                $query->orderBy($column);
            }

            return [$table => $query->get()->map(fn ($row) => (array) $row)->all()];
        })->all();
    }

    private function queue(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }
}
