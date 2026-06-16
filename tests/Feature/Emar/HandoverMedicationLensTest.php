<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the eMAR medication lens layered onto shift handovers:
 *  - the live "Medications this shift" snapshot endpoint (window-scoped),
 *  - controlled-drug two-person count persistence, and
 *  - optimistic-concurrency (version) edit-locking on the shared draft.
 */
class HandoverMedicationLensTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected User $witness;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->worker = $this->makeUser('admin', [
            'medications.view', 'handovers.create', 'handovers.viewAny',
            'shifts.update', 'shifts.manageAny', 'clients.update',
        ]);
        $this->witness = $this->makeUser('support_worker', ['shifts.update']);

        $this->site = Site::factory()->create(['type' => 'house', 'name' => 'Tui House', 'is_active' => true]);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential', 'type' => 'residential', 'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
    }

    public function test_shift_medication_snapshot_requires_a_shift_id(): void
    {
        $this->actingAs($this->worker)
            ->getJson('/emar/handovers/shift-medications')
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift_id');
    }

    public function test_shift_medication_snapshot_returns_window_scoped_picture(): void
    {
        $shift = $this->makeShift();

        // A PRN dose given inside the shift window, with no effectiveness review yet —
        // exercises the snapshot's direct prn-given / reviews-outstanding queries.
        $prn = ClientMedication::factory()->create([
            'client_id' => $this->client->id, 'name' => 'Lorazepam', 'dosage' => '1mg',
            'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $prn->id,
            'status' => 'given',
            'administered_at' => now()->subHours(2),
            'administered_by' => $this->worker->id,
        ]);

        $response = $this->actingAs($this->worker)
            ->getJson('/emar/handovers/shift-medications?shift_id='.$shift->id)
            ->assertOk()
            ->assertJsonStructure([
                'snapshot' => [
                    'window' => ['start', 'end'],
                    'counts' => ['due', 'given', 'missed', 'refused', 'cd_due', 'prn_given', 'reviews_outstanding', 'omissions'],
                    'due', 'alerts', 'generated_at',
                ],
            ]);

        $response->assertJsonPath('snapshot.counts.prn_given', 1);
        $response->assertJsonPath('snapshot.counts.reviews_outstanding', 1);
    }

    public function test_controlled_drug_count_is_persisted_on_store(): void
    {
        $shift = $this->makeShift();

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $shift->id,
                'handover_notes' => 'CD register reconciled with the incoming worker.',
                'cd_result' => 'verified',
                'cd_witness_id' => $this->witness->id,
                'cd_notes' => 'All controlled-drug counts matched.',
                'submit' => false,
            ])
            ->assertRedirect();

        $handover = ShiftHandover::query()->latest('id')->firstOrFail();
        $cd = $handover->cd_verification;

        $this->assertIsArray($cd);
        $this->assertSame('verified', $cd['result']);
        $this->assertSame($this->witness->id, (int) $cd['witness_id']);
        $this->assertSame($this->witness->name, $cd['witness_name']);
        $this->assertSame($this->worker->id, (int) $cd['verified_by']);
        $this->assertNotNull($cd['verified_at']);
    }

    public function test_optimistic_version_blocks_a_stale_concurrent_edit(): void
    {
        $shift = $this->makeShift();

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $shift->id,
                'handover_notes' => 'Initial draft.',
                'submit' => false,
            ])
            ->assertRedirect();

        $handover = ShiftHandover::query()->latest('id')->firstOrFail();
        $this->assertSame(1, (int) $handover->version);

        // Editing with the current version succeeds and bumps the token.
        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'handover_notes' => 'First edit.',
                'version' => 1,
                'submit' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $handover->fresh()->version);

        // A second editor still on version 1 is blocked, not silently overwritten.
        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'handover_notes' => 'Stale edit that should be rejected.',
                'version' => 1,
                'submit' => false,
            ])
            ->assertSessionHasErrors('handover');

        $this->assertSame('First edit.', $handover->fresh()->handover_notes);
    }

    protected function makeShift(): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->subMinutes(15),
            'actual_starts_at' => now()->subHours(4),
            'status' => 'in_progress',
            'started_by' => $this->worker->id,
            'created_by' => $this->worker->id,
        ]);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeUser(string $roleName, array $permissionKeys = []): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        if ($permissionKeys !== []) {
            $map = Permission::query()
                ->whereIn('key', $permissionKeys)
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all();
            $user->permissionOverrides()->syncWithoutDetaching($map);
        }

        return $user;
    }
}
