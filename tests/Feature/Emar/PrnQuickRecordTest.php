<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PrnQuickRecordTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected Client $client;

    protected ClientMedication $prn;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Cache::flush();

        $this->worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($this->worker, ['medications.administer.record']);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'PRN Quick',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);

        $this->prn = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'max_per_day' => 4,
            'active' => true,
            'state' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prn_quick_record_creates_administration_with_shift_context(): void
    {
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', [
                'client_medication_id' => $this->prn->id,
                'reason' => 'Pain',
                'dose_given' => '500mg',
                'notes' => 'Settled after lunch.',
                'client_request_uuid' => 'prn-quick-uuid',
                'captured_offline_at' => now()->toIso8601String(),
                'origin_device_id' => 'test-device',
                'queued_offline' => false,
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_id' => $this->client->id,
            'client_medication_id' => $this->prn->id,
            'administered_by' => $this->worker->id,
            'status' => 'given',
            'reason' => 'Pain',
            'dose_given' => '500mg',
        ]);
    }

    public function test_prn_quick_record_is_idempotent_for_replayed_uuid(): void
    {
        $payload = [
            'client_medication_id' => $this->prn->id,
            'reason' => 'Pain',
            'dose_given' => '500mg',
            'client_request_uuid' => 'prn-replay-uuid',
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'test-device',
            'queued_offline' => true,
        ];

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', $payload)
            ->assertRedirect('/meds/today');

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success', 'Already saved — no changes needed.');

        $this->assertDatabaseCount('client_medication_administrations', 1);
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
