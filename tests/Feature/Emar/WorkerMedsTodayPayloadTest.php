<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicationReadQueryInventory;
use Tests\TestCase;

class WorkerMedsTodayPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_worker_meds_today_payload_sorts_due_now_and_prn_limit_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);

        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.administer.record']);
        $this->denyPermissions($worker, [
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Worker Meds',
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        $client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => Carbon::parse('2026-04-30 09:00:00', config('app.worker_timezone'))->utc(),
            'ends_at' => Carbon::parse('2026-04-30 13:00:00', config('app.worker_timezone'))->utc(),
            // Clocked in at 09:00: Meds today shows the people a worker is
            // assigned to or clocked in with (the person rule, C6).
            'actual_starts_at' => Carbon::parse('2026-04-30 09:00:00', config('app.worker_timezone'))->utc(),
            'status' => 'in_progress',
        ]);

        // Entered at the start of the day: a dose due before an order's entry is not owed.
        Carbon::setTestNow(Carbon::parse('2026-04-30 00:00:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Three times daily',
            'dose_times' => ['08:00', '10:00', '16:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());

        $prn = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain, Headache',
            'max_per_day' => 2,
            'active' => true,
            'state' => 'active',
        ]);

        $untouchedPrn = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Zulu untouched PRN',
            'dosage' => '5mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Breakthrough symptom',
            'max_per_day' => 2,
            'min_hours_between_doses' => 4,
            'active' => true,
            'state' => 'active',
        ]);

        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);

        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'PRIVATE CONTROLLED BOARD SCHEDULE',
            'dosage' => '5mg',
            'frequency' => 'Three times daily',
            'dose_times' => ['08:00', '10:00', '16:00'],
            'is_prn' => false,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $controlledPrn = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'PRIVATE CONTROLLED BOARD PRN',
            'dosage' => '2mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $controlledAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledPrn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subMinutes(30),
            'status' => 'given',
            'dose_given' => 'PRIVATE CONTROLLED DOSE',
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $controlledPrn->id,
            'on_hand' => 1,
            'reorder_level' => 2,
            'unit' => 'tablets',
        ]);
        TimelineEvent::query()->create([
            'source_type' => ClientMedicationAdministration::class,
            'source_id' => $controlledAdministration->id,
            'occurred_at' => now()->subMinutes(30),
            'type' => 'medication_given',
            'actor_user_id' => $worker->id,
            'client_id' => $client->id,
            'subject' => 'PRIVATE CONTROLLED BOARD ACTIVITY',
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $worker->id,
        ]);

        $foreignClient = Client::factory()->create(['status' => 'active']);
        $foreignPrn = ClientMedication::query()->create([
            'client_id' => $foreignClient->id,
            'name' => 'FORGED foreign PRN',
            'dosage' => '1mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $forgedDayAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignPrn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subMinutes(20),
            'status' => 'given',
            'notes' => 'FORGED local-client foreign-medication administration',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $untouchedPrn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subMinutes(10),
            'status' => 'given',
            'notes' => 'FORGED foreign-client local-medication administration',
        ]);

        $response = $this->actingAs($worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('stats.meds_due', 3)
                ->where('stats.due_now', 2)
                ->where('stats.due_later', 1)
                ->where('has_shift_context', true)
                ->where('due_now.0.status', 'overdue')
                ->where('due_now.1.status', 'due')
                ->where('due_later.0.status', 'upcoming')
                ->where('prn_medications.0.id', $prn->id)
                ->where('prn_medications.0.given_last_24h', 1)
                ->where('prn_medications.0.remaining_today', 1)
                ->where('prn_medications.0.near_limit', false)
                ->where('prn_medications.0.over_limit', false)
                ->where('prn_medications', function ($rows) use ($untouchedPrn): bool {
                    $row = collect($rows)->firstWhere('id', $untouchedPrn->id);

                    return data_get($row, 'given_last_24h') === 0
                        && data_get($row, 'remaining_today') === 2
                        && data_get($row, 'last_given_at') === null
                        && data_get($row, 'next_allowed_at') === null
                        && data_get($row, 'interval_blocked') === false;
                })
                ->where('prn_follow_ups', fn ($rows) => collect($rows)
                    ->pluck('administration_id')
                    ->doesntContain($forgedDayAdministration->id))
            );
        $this->assertStringNotContainsString('PRIVATE CONTROLLED BOARD', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE CONTROLLED DOSE', $response->getContent());
    }

    public function test_meds_due_matches_administrations_with_a_single_query(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);

        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.administer.record']);

        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Worker Meds N+1',
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => Carbon::parse('2026-04-30 09:00:00', config('app.worker_timezone'))->utc(),
            'ends_at' => Carbon::parse('2026-04-30 13:00:00', config('app.worker_timezone'))->utc(),
            'status' => 'scheduled',
        ]);
        // The badge counts the people the worker supports or may open (C6d).
        $client->supportWorkers()->attach($worker->id);

        $this->denyPermissions($worker, ['medications.controlled.view', 'medications.controlled.record']);
        $orders = [];
        $createOrder = function (Client $person, array $times, bool $controlled = false) use (&$orders): ClientMedication {
            $order = ClientMedication::query()->create([
                'client_id' => $person->id, 'name' => 'Query fixture medicine '.(count($orders) + 1),
                'dosage' => '1 tablet', 'frequency' => 'Scheduled', 'dose_times' => $times,
                'is_prn' => false, 'controlled_drug' => $controlled, 'active' => true, 'state' => 'active',
                'approval_status' => 'verified', 'start_date' => '2026-04-01', 'end_date' => null,
            ]);
            if (! $controlled) {
                $orders[] = $order;
            }

            return $order;
        };
        $createOrder($client, ['10:00']);
        $unassigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreign = Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id, 'status' => 'active',
        ]);
        $foreign->supportWorkers()->attach($worker->id);
        $hidden = [
            $createOrder($unassigned, ['10:00']), $createOrder($foreign, ['10:00']),
            $createOrder($client, ['10:00'], controlled: true),
        ];
        $orders = [$orders[0]];

        foreach ([1, 7, 25] as $slotCount) {
            if ($slotCount === 7) {
                $createOrder($client, ['10:00', '11:00', '12:00']);
                $createOrder($client, ['10:00', '11:00', '12:00']);
            } elseif ($slotCount === 25) {
                for ($personIndex = 0; $personIndex < 2; $personIndex++) {
                    $person = Client::factory()->create([
                        'site_id' => $site->id, 'service_context_id' => $serviceContext->id, 'status' => 'active',
                    ]);
                    $person->supportWorkers()->attach($worker->id);
                    Shift::factory()->create([
                        'client_id' => $person->id, 'site_id' => $site->id, 'service_context_id' => $serviceContext->id,
                        'user_id' => $worker->id, 'starts_at' => now()->subMinutes(30), 'ends_at' => now()->addHours(4),
                        'status' => 'scheduled',
                    ]);
                    for ($orderIndex = 0; $orderIndex < 3; $orderIndex++) {
                        $createOrder($person, ['10:00', '11:00', '12:00']);
                    }
                }
            }
            app()->forgetScopedInstances();
            auth()->forgetUser();
            Cache::forget("user:{$worker->id}:task-nav:v1");
            Cache::forget(HandleInertiaRequests::medsOverdueBadgeCacheKey(
                $worker->id, Carbon::now(config('app.worker_timezone'))->toDateString(),
            ));
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $response = $this->actingAs($worker->fresh())->get('/meds/today')->assertOk();
                $reads = MedicationReadQueryInventory::fromLog(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertCount($slotCount, $response->inertiaProps('schedule'));
            $this->assertSame(
                collect($orders)->pluck('id')->sort()->values()->all(),
                collect($response->inertiaProps('schedule'))->pluck('medication_id')->unique()->sort()->values()->all(),
            );
            // Keep the original two fixed board/badge reads, and account for
            // independent canonical evidence by its actual top-level purpose.
            $this->assertSame(2, count($reads['board_day']) + count($reads['scheduled_window']));
            $this->assertSame([
                'scheduled_window' => 1, 'board_day' => 1, 'prn_unresolved' => 1,
                'administration_batch' => 0, 'followup_scope' => 1, 'refusal_scope' => 1, 'unexpected' => 0,
            ], MedicationReadQueryInventory::counts($reads), MedicationReadQueryInventory::describe($reads));
            $this->assertDatabaseCount('client_medication_administrations', 0);
        }
        foreach ($hidden as $order) {
            $this->actingAs($worker->fresh())->getJson(route('emar.medications.detail', $order))->assertNotFound();
        }
    }

    public function test_sidebar_badge_keeps_an_overnight_shift_after_midnight(): void
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:20:00', $timezone)->utc());
        $this->seed(RbacSeeder::class);

        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.administer.record']);

        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Overnight worker meds',
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $site->id,
        ]);

        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => Carbon::parse('2026-04-30 23:20:00', $timezone)->utc(),
            'ends_at' => Carbon::parse('2026-05-01 05:00:00', $timezone)->utc(),
            'status' => 'in_progress',
        ]);
        // The badge counts the people the worker supports or may open (C6d).
        $client->supportWorkers()->attach($worker->id);

        // Entered before its 00:05 dose (a dose due before an order's entry is not owed).
        Carbon::setTestNow(Carbon::parse('2026-04-30 22:00:00', $timezone)->utc());
        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Overnight tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['00:05'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
        // 01:20: the 00:05 dose's window (to 01:05) has ended — overdue (C6d).
        Carbon::setTestNow(Carbon::parse('2026-05-01 01:20:00', $timezone)->utc());

        $this->actingAs($worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.medications.overdueTodayCount', 1)
                ->where('has_shift_context', true)
                ->where('clients.0.id', $client->id)
            );
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function denyPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
