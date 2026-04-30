<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($worker, ['medications.administer.record']);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Worker Meds',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
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

        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $worker->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);

        $this->actingAs($worker)
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
     * @param array<int, string> $permissionKeys
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
}
