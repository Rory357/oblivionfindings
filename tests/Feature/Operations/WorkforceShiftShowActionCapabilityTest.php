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
use App\Policies\ClientPolicy;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class WorkforceShiftShowActionCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private ServiceContext $context;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 00:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['site_id' => null, 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('show_access_writer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_scoped_reader_with_general_update_gets_no_edit_action_pickers_or_client_link_for_another_worker(): void
    {
        $reader = $this->person(['shifts.viewAny', 'shifts.update']);
        $worker = $this->person();
        $shift = $this->shift($worker);
        $this->assertFalse($reader->canDo('shifts.manageAny'));
        $state = $this->state();
        $queue = $this->queue();

        $this->actingAs($reader)->get(route('operations.shifts.show', $shift))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/show')->where('shift.id', $shift->id)
            ->where('can.edit_shift', false)->where('can.view_client_profile', false)->where('links.client_care', null)
            ->has('clients', 0)->has('staff', 0)->has('sites', 0)->has('serviceContexts', 0));
        $this->getJson(route('operations.shifts.editable', $shift))->assertForbidden();
        $this->get(route('operations.clients.show', $this->client))->assertForbidden();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    #[DataProvider('editorCases')]
    public function test_edit_capability_matches_the_existing_editable_endpoint_without_granting_management(string $kind, bool $allowed): void
    {
        $keys = ['shifts.viewAny'];
        if ($kind !== 'management without update') {
            $keys[] = 'shifts.update';
        }
        if ($kind !== 'owner') {
            $keys[] = 'shifts.manageAny';
        }
        $actor = $this->person($keys);
        $worker = $kind === 'owner' ? $actor : $this->person();
        $shift = $this->shift($worker);
        $state = $this->state();
        $queue = $this->queue();

        $this->actingAs($actor)->get(route('operations.shifts.show', $shift))->assertOk()->assertInertia(function (Assert $page) use ($shift, $allowed): void {
            $page->component('operations/shifts/show')->where('shift.id', $shift->id)->where('can.edit_shift', $allowed)
                ->where('can.view_client_profile', false)->where('links.client_care', null);
            if ($allowed) {
                $page->where('clients', fn ($rows) => collect($rows)->contains('id', $this->client->id))
                    ->where('staff', fn ($rows) => collect($rows)->contains('id', $shift->user_id))
                    ->where('sites', fn ($rows) => collect($rows)->contains('id', $this->site->id))
                    ->where('serviceContexts', fn ($rows) => collect($rows)->contains('id', $this->context->id));
            } else {
                $page->has('clients', 0)->has('staff', 0)->has('sites', 0)->has('serviceContexts', 0);
            }
        });
        $this->getJson(route('operations.shifts.editable', $shift))->assertStatus($allowed ? 200 : 403);
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function editorCases(): array
    {
        return ['own assigned record' => ['owner', true], 'manager with exact update' => ['manager', true],
            'management alone does not confer update' => ['management without update', false]];
    }

    #[DataProvider('clientCases')]
    public function test_client_profile_link_requires_its_actual_route_and_record_policy(string $kind, bool $allowed): void
    {
        $keys = ['shifts.viewAny'];
        if (in_array($kind, ['general', 'foreign reporting', 'foreign clinical'], true)) {
            $keys[] = 'clients.viewAny';
        } elseif (in_array($kind, ['assigned', 'unrelated assignment'], true)) {
            $keys[] = 'clients.viewAssigned';
        } else {
            $keys[] = 'clinical.accessAllSites';
        }
        if (str_starts_with($kind, 'foreign')) {
            $keys[] = 'reports.viewAny';
            if ($kind === 'foreign clinical') {
                $keys[] = 'clinical.accessAllSites';
            }
        }
        $actor = $this->person($keys);
        $worker = $this->person();
        $targetClient = $this->client;
        $values = [];
        if (str_starts_with($kind, 'foreign')) {
            $outside = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
            $targetClient = Client::factory()->create(['site_id' => $outside->id, 'service_context_id' => $this->context->id]);
            $worker->hrEmployeeProfile->update(['primary_site_id' => $outside->id]);
            $values = ['site_id' => $outside->id, 'client_id' => $targetClient->id];
        }
        $shift = $this->shift($worker, $values);
        if ($kind === 'assigned') {
            $this->client->supportWorkers()->attach($actor);
        } elseif ($kind === 'unrelated assignment') {
            Client::factory()->create(['site_id' => $this->site->id])->supportWorkers()->attach($actor);
        }
        $state = $this->state();
        $queue = $this->queue();

        $this->actingAs($actor)->get(route('operations.shifts.show', $shift))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/show')->where('can.edit_shift', false)->where('can.view_client_profile', $allowed)
            ->where('links.client_care', $allowed ? route('operations.clients.show', $targetClient) : null)
            ->has('clients', 0)->has('staff', 0)->has('sites', 0)->has('serviceContexts', 0));
        $this->assertSame($allowed, app(ClientPolicy::class)->view($actor->fresh(), $targetClient));
        if (! $allowed) {
            $this->get(route('operations.clients.show', $targetClient))->assertForbidden();
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function clientCases(): array
    {
        return ['current Site reader' => ['general', true], 'exact Client assignment' => ['assigned', true],
            'another Client assignment is insufficient' => ['unrelated assignment', false],
            'clinical Site bypass without Client view is insufficient' => ['clinical only', false],
            'Shift reporting bypass does not grant foreign Client profile' => ['foreign reporting', false],
            'installed Client clinical Site bypass with exact view grant is preserved' => ['foreign clinical', true]];
    }

    public function test_soft_deleted_client_retains_shift_history_but_withholds_edit_and_profile_access(): void
    {
        $actor = $this->person(['shifts.viewAny', 'shifts.update', 'shifts.manageAny', 'clients.viewAny']);
        $shift = $this->shift($this->person());
        $state = $this->state();
        $queue = $this->queue();

        $this->actingAs($actor)->get(route('operations.shifts.show', $shift))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/show')->where('can.edit_shift', true)
            ->where('can.view_client_profile', true)->where('links.client_care', route('operations.clients.show', $this->client)));
        $this->getJson(route('operations.shifts.editable', $shift))->assertOk();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());

        $this->assertTrue($this->client->delete());
        $this->assertSoftDeleted('clients', ['id' => $this->client->id]);
        $this->assertSame($this->client->id, $shift->fresh()->client_id);
        $this->assertNull($shift->fresh()->client);
        $state = $this->state();
        $queue = $this->queue();

        $this->getJson(route('operations.shifts.show', $shift))->assertForbidden()
            ->assertJsonMissingPaths(['can.edit_shift', 'can.view_client_profile', 'links.client_care']);
        $this->getJson(route('operations.shifts.editable', $shift))->assertNotFound();
        $this->getJson(route('operations.clients.show', $this->client))->assertNotFound();
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    #[DataProvider('currentChanges')]
    public function test_primed_repeatable_read_withholds_actions_after_an_independently_committed_change(string $kind, bool $edit, bool $profile): void
    {
        $actor = $this->person(['shifts.viewAny', 'shifts.update', 'clients.viewAssigned']);
        $this->client->supportWorkers()->attach($actor);
        $shift = $this->shift($actor);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id]);
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        $stale = $actor->fresh()->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertTrue($stale->canDo('shifts.update'));
        $this->assertTrue($stale->canDo('clients.viewAssigned'));
        $this->assertTrue($this->client->fresh()->supportWorkers->contains('id', $actor->id));
        $this->assertSame($this->client->id, Shift::findOrFail($shift->id)->client_id);

        if ($kind === 'update grant' || $kind === 'Client grant') {
            $key = $kind === 'update grant' ? 'shifts.update' : 'clients.viewAssigned';
            $writer->table('permission_user')->where('user_id', $actor->id)
                ->where('permission_id', Permission::where('key', $key)->firstOrFail()->id)->update(['allowed' => false]);
        } elseif ($kind === 'Client assignment') {
            $writer->table('client_user')->where('client_id', $this->client->id)->where('user_id', $actor->id)->delete();
        } else {
            $writer->table('shifts')->where('id', $shift->id)->update(['client_id' => $otherClient->id]);
        }
        $this->assertTrue($stale->canDo('shifts.update'), 'The old account still holds its primed grant.');
        $this->assertTrue($stale->canDo('clients.viewAssigned'));
        $state = $this->state($writer);
        $queue = $this->queue();

        $this->actingAs($stale)->get(route('operations.shifts.show', $shift))->assertOk()->assertInertia(function (Assert $page) use ($edit, $profile): void {
            $page->component('operations/shifts/show')->where('can.edit_shift', $edit)
                ->where('can.view_client_profile', $profile)->where('links.client_care', $profile ? route('operations.clients.show', $this->client) : null);
            if (! $edit) {
                $page->has('clients', 0)->has('staff', 0)->has('sites', 0)->has('serviceContexts', 0);
            }
        });
        $this->assertSame($state, $this->state($writer));
        $this->assertSame($queue, $this->queue());
        DB::rollBack();
    }

    public static function currentChanges(): array
    {
        return ['revoked update' => ['update grant', false, true], 'revoked Client view' => ['Client grant', true, false],
            'removed exact Client assignment' => ['Client assignment', true, false], 'retargeted bound source' => ['source', false, false]];
    }

    private function person(array $keys = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'show-access-'.Str::uuid(), 'label' => 'Synthetic Show participant', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function shift(User $worker, array $values = []): Shift
    {
        return Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'user_id' => $worker->id, 'created_by' => $worker->id,
            'starts_at' => Carbon::parse('2026-10-20 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-20 10:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'scheduled', 'published_at' => null, ...$values])->fresh();
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['users', 'permission_user', 'client_user', 'hr_employee_profiles', 'sites', 'clients', 'service_contexts',
            'shifts', 'shift_tasks', 'shift_series', 'shift_eligibility_overrides', 'coverage_reservations', 'shift_replacement_requests',
            'shift_open_positions', 'site_checklist_runs', 'audit_logs', 'notifications', 'workforce_eligibility_rechecks',
            'workforce_eligibility_observations', 'timeline_events', 'shift_handovers', 'shift_notes', 'timesheets',
            'hr_time_entries', 'billing_entries', 'shift_signals', 'shift_signal_outbox'];
        $sort = ['permission_user' => ['user_id', 'permission_id'], 'client_user' => ['client_id', 'user_id']];

        return collect($tables)->mapWithKeys(function (string $table) use ($connection, $sort): array {
            $query = ($connection ?? DB::connection())->table($table);
            foreach ($sort[$table] ?? ['id'] as $key) {
                $query->orderBy($key);
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

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function writer(): Connection
    {
        $name = 'show_access_writer';
        config(['database.connections.'.$name => [...DB::connection()->getConfig(), 'name' => $name]]);
        DB::purge($name);
        $writer = DB::connection($name);
        $this->assertSame($name, $writer->getName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame($writer->getDatabaseName(), $writer->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }
}
