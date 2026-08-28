<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland')));
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
            'status' => 'scheduled',
        ]);

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
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland')));
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

        // 3 scheduled meds × 3 in-window dose times = 9 slots, no PRN meds (PRN
        // 24h counts hit the administrations table through a separate accessor).
        // Before F1 the list issued one administration query per slot.
        foreach (['Med A', 'Med B', 'Med C'] as $name) {
            ClientMedication::query()->create([
                'client_id' => $client->id,
                'name' => $name,
                'dosage' => '1 tablet',
                'frequency' => 'Three times daily',
                'dose_times' => ['08:00', '10:00', '12:00'],
                'is_prn' => false,
                'active' => true,
                'state' => 'active',
            ]);
        }

        DB::enableQueryLog();

        $this->actingAs($worker)->get('/meds/today')->assertOk();

        $adminQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'client_medication_administrations'))
            ->count();

        DB::disableQueryLog();

        // Exactly two fixed administration queries, regardless of how many
        // dose slots exist: the board's day query (slot matching + PRN
        // follow-ups) and the sidebar overdue-badge window query in
        // HandleInertiaRequests (cached for 60s after this first load).
        // Before F1 the list issued one administration query PER SLOT.
        $this->assertSame(2, $adminQueries);
    }

    public function test_sidebar_badge_keeps_an_overnight_shift_after_midnight(): void
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:20:00', $timezone));
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
