<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationInteraction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The MAR chart must apply the per-person medication check
 * (ClientPolicy::viewMedications) — not just the Site boundary — to an
 * explicitly requested resident, must not name controlled medicines in
 * interaction pairs for a reader without controlled view, and must accept the
 * syringe-driver request the MAR dialog actually sends.
 */
class MarChartPersonScopeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    }

    public function test_unassigned_support_worker_gets_not_found_for_an_explicit_client_id(): void
    {
        $worker = $this->supportWorker();
        $assigned = $this->residentWithMedication('Aroha', 'Assigned');
        $unassigned = $this->residentWithMedication('Hemi', 'Unassigned');
        $assigned->supportWorkers()->attach($worker->id);

        // Same Site, no assignment: indistinguishable from a missing record.
        $this->actingAs($worker)
            ->get(route('emar.mar', ['client_id' => $unassigned->id]))
            ->assertNotFound();
        $this->actingAs($worker)
            ->get(route('emar.clients.inr.index', $unassigned))
            ->assertNotFound();

        // The assigned resident opens, and the picker never names the other.
        $mar = $this->actingAs($worker)
            ->get(route('emar.mar', ['client_id' => $assigned->id]))
            ->assertOk();
        $this->assertSame($assigned->id, $mar->inertiaProps('selectedClient.id'));
        $this->assertSame([$assigned->id], collect($mar->inertiaProps('clients'))->pluck('id')->all());

        // A bare visit auto-picks only a resident the worker may view.
        $this->actingAs($worker)
            ->withSession(['emar.mar.last_client_id' => $unassigned->id])
            ->get(route('emar.mar'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected_client_info.id', $assigned->id));

        // A Site-wide medication reader is unaffected by the person gate.
        $siteReader = $this->userWithPermissions(['medications.view', 'clients.viewAny']);
        $this->actingAs($siteReader)
            ->get(route('emar.mar', ['client_id' => $unassigned->id]))
            ->assertOk();
    }

    public function test_interactions_never_name_controlled_medicines_for_a_reader_without_controlled_view(): void
    {
        $client = $this->residentWithMedication('Mere', 'Interacts', 'Warfarin');
        $this->medication($client, 'Aspirin');
        $this->medication($client, 'Morphine', controlled: true);
        MedicationInteraction::query()->create([
            'medication_a' => 'Warfarin',
            'medication_b' => 'Aspirin',
            'severity' => 'major',
            'description' => 'Increased bleeding risk.',
        ]);
        MedicationInteraction::query()->create([
            'medication_a' => 'Warfarin',
            'medication_b' => 'Morphine',
            'severity' => 'moderate',
            'description' => 'Controlled pair.',
        ]);

        $reader = $this->supportWorker(deny: ['medications.controlled.view']);
        $client->supportWorkers()->attach($reader->id);
        $this->assertFalse($reader->canDo('medications.controlled.view'));

        $interactions = collect(
            $this->actingAs($reader)
                ->get(route('emar.mar', ['client_id' => $client->id]))
                ->assertOk()
                ->inertiaProps('interactions'),
        );
        $this->assertSame([['Warfarin', 'Aspirin']], $interactions->map(fn ($i) => [$i['drug_a'], $i['drug_b']])->all());
        $this->assertStringNotContainsStringIgnoringCase('morphine', json_encode($interactions->all()));

        $controlledReader = $this->supportWorker();
        $client->supportWorkers()->attach($controlledReader->id);
        $this->assertTrue($controlledReader->canDo('medications.controlled.view'));

        $visible = collect(
            $this->actingAs($controlledReader)
                ->get(route('emar.mar', ['client_id' => $client->id]))
                ->assertOk()
                ->inertiaProps('interactions'),
        );
        $this->assertContains('Morphine', $visible->pluck('drug_b')->all());
    }

    public function test_syringe_driver_starts_from_the_request_the_mar_dialog_sends(): void
    {
        $client = $this->residentWithMedication('Tui', 'Palliative', 'Haloperidol');
        $medication = $client->medications()->firstOrFail();
        $manager = $this->userWithPermissions(['medications.view', 'medications.orders.manage']);
        // Worker wall clock as a datetime-local input sends it (no offset).
        $commencedLocal = Carbon::now(config('app.worker_timezone'))->subMinutes(5)->format('Y-m-d\TH:i');
        $dialogFields = [
            'commenced_at' => $commencedLocal,
            'rate' => '2',
            'rate_unit' => 'mL/hr',
            'site_of_insertion' => 'Left upper arm',
            'notes' => '',
            'witnessed_by' => null,
            'witness_credential' => '',
        ];

        // The previous dialog sent a free-text name with no medicine id and
        // was refused on every submit.
        $this->actingAs($manager)
            ->from(route('emar.mar', ['client_id' => $client->id]))
            ->post(route('emar.clients.syringe_drivers.store', $client), [
                ...$dialogFields,
                'contents' => [['name' => 'Haloperidol', 'dose' => '1', 'unit' => 'mg', 'requires_witness' => false]],
            ])
            ->assertSessionHasErrors('contents.0.client_medication_id');
        $this->assertDatabaseCount('medication_syringe_drivers', 0);

        $this->actingAs($manager)
            ->from(route('emar.mar', ['client_id' => $client->id]))
            ->post(route('emar.clients.syringe_drivers.store', $client), [
                ...$dialogFields,
                'contents' => [['client_medication_id' => $medication->id, 'dose' => '1', 'unit' => 'mg']],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('emar.mar', ['client_id' => $client->id]));

        $driver = $client->syringeDrivers()->firstOrFail();
        $this->assertSame('running', $driver->status);
        $this->assertSame($manager->id, (int) $driver->commenced_by);
        $this->assertSame('Haloperidol', $driver->contents[0]['name']);
        $this->assertSame($medication->id, $driver->contents[0]['client_medication_id']);
        // Stored as the same instant the worker entered, not 12–13 hours off.
        $this->assertSame(
            Carbon::parse($commencedLocal, config('app.worker_timezone'))->utc()->toDateTimeString(),
            $driver->getRawOriginal('commenced_at'),
        );
    }

    public function test_mar_payload_flags_every_controlled_medicine_as_witness_required(): void
    {
        // The syringe-driver dialog shows its witness fields from this payload;
        // the server demands a witness for any controlled content
        // (ClientMedication::requiresWitness), whatever the column says.
        $client = $this->residentWithMedication('Hine', 'Palliative', 'Haloperidol');
        $this->medication($client, 'Midazolam', controlled: true, witnessRequired: false);
        $reader = $this->userWithPermissions([
            'medications.view',
            'clients.viewAny',
            'medications.controlled.view',
        ]);

        $rows = collect(
            $this->actingAs($reader)
                ->get(route('emar.mar', ['client_id' => $client->id]))
                ->assertOk()
                ->inertiaProps('marData.scheduled'),
        )->keyBy('name');

        $this->assertTrue($rows['Midazolam']['controlled_drug']);
        $this->assertTrue($rows['Midazolam']['witness_required']);
        $this->assertFalse($rows['Haloperidol']['witness_required']);
    }

    private function residentWithMedication(string $first, string $last, string $medication = 'Paracetamol'): Client
    {
        $client = Client::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $this->medication($client, $medication);

        return $client;
    }

    private function medication(
        Client $client,
        string $name,
        bool $controlled = false,
        ?bool $witnessRequired = null,
    ): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['08:00'],
            'is_prn' => false,
            'controlled_drug' => $controlled,
            'witness_required' => $witnessRequired ?? $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
    }

    /** The seeded Support Worker role (assignment-scoped medication reader). */
    private function supportWorker(array $deny = []): User
    {
        $user = $this->siteStaff();
        $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $user->permissionOverrides()->sync(
            Permission::query()->whereIn('key', $deny)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
                ->all(),
        );

        return $user->refresh();
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = $this->siteStaff();
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $ids);
        $user->permissionOverrides()->sync(
            $ids->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );

        return $user->refresh();
    }

    private function siteStaff(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
        ]);

        return $user;
    }
}
