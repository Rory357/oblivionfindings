<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationControlledOrderMutationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Controlled order authority',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);
    }

    public function test_every_controlled_order_mutation_denies_either_single_controlled_capability(): void
    {
        foreach ([
            'view_without_record' => ['medications.controlled.view'],
            'record_without_view' => ['medications.controlled.record'],
        ] as $case => $controlledCapabilities) {
            $actor = $this->makeSiteActor([
                'clients.update',
                'medications.view',
                'medications.orders.manage',
                'medications.orders.verify',
                ...$controlledCapabilities,
            ]);
            $creator = User::factory()->create(['approved_at' => now()]);
            $medication = $this->controlledMedication([
                'name' => 'Protected controlled order '.$case,
                'created_by' => $creator->id,
            ]);
            $createdName = 'Blocked controlled creation '.$case;

            $this->actingAs($actor)
                ->post(route('emar.medications.store'), [
                    'client_id' => $this->client->id,
                    'medication_name' => $createdName,
                    'dose' => '5 mg',
                    'frequency' => 'Once daily',
                    'controlled_drug' => true,
                ])
                ->assertNotFound();
            $this->assertDatabaseMissing('client_medications', ['name' => $createdName]);

            foreach ([
                'clients.medical.medications.store',
                'operations.clients.medical.medications.store',
            ] as $routeIndex => $routeName) {
                $profileCreatedName = "Blocked profile controlled creation {$case} {$routeIndex}";

                $this->actingAs($actor)
                    ->post(route($routeName, $this->client), [
                        'name' => $profileCreatedName,
                        'dosage' => '5 mg',
                        'frequency' => 'Once daily',
                        'controlled_drug' => true,
                    ])
                    ->assertNotFound();
                $this->assertDatabaseMissing('client_medications', ['name' => $profileCreatedName]);
            }

            foreach ([
                'clients.medical.medications.update',
                'operations.clients.medical.medications.update',
            ] as $routeIndex => $routeName) {
                $profileUpdateTarget = $this->controlledMedication([
                    'name' => "Protected profile controlled update {$case} {$routeIndex}",
                    'created_by' => $creator->id,
                ]);

                $this->actingAs($actor)
                    ->put(route($routeName, [$this->client, $profileUpdateTarget]), [
                        'name' => "Blocked profile controlled update {$case} {$routeIndex}",
                    ])
                    ->assertNotFound();
                $this->assertSame(
                    "Protected profile controlled update {$case} {$routeIndex}",
                    $profileUpdateTarget->fresh()->name,
                );
            }

            $this->actingAs($actor)
                ->put(route('emar.medications.update', $medication), [
                    'medication_name' => 'Blocked controlled update '.$case,
                ])
                ->assertNotFound();
            $this->assertSame('Protected controlled order '.$case, $medication->fresh()->name);

            $this->actingAs($actor)
                ->post(route('emar.medications.verify', $medication))
                ->assertNotFound();
            $this->assertSame('pending_verification', $medication->fresh()->approval_status);

            $this->actingAs($actor)
                ->post(route('emar.medications.reject', $medication), [
                    'rejection_reason' => 'This direct mutation must remain concealed.',
                ])
                ->assertNotFound();
            $this->assertSame('pending_verification', $medication->fresh()->approval_status);

            foreach ([
                'clients.medical.medications.discontinue',
                'operations.clients.medical.medications.discontinue',
                'emar.medications.discontinue',
            ] as $index => $routeName) {
                $discontinueTarget = $this->controlledMedication([
                    'name' => "Protected discontinuation {$case} {$index}",
                    'created_by' => $creator->id,
                ]);
                $routeParameters = $routeName === 'emar.medications.discontinue'
                    ? [$discontinueTarget]
                    : [$this->client, $discontinueTarget];

                $this->actingAs($actor)
                    ->post(route($routeName, $routeParameters), [
                        'reason' => 'The prescriber stopped this medication.',
                        'request_key' => "controlled-denial-{$case}-{$index}",
                    ])
                    ->assertNotFound();
                $this->assertSame('active', $discontinueTarget->fresh()->state);
                $this->assertTrue((bool) $discontinueTarget->fresh()->active);
            }
        }
    }

    public function test_exact_dual_controlled_capabilities_allow_each_controlled_order_mutation(): void
    {
        $actor = $this->makeSiteActor([
            'clients.update',
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        $creator = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($actor)
            ->post(route('emar.medications.store'), [
                'client_id' => $this->client->id,
                'medication_name' => 'Authorised controlled creation',
                'dose' => '5 mg',
                'frequency' => 'Once daily',
                'controlled_drug' => true,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('client_medications', [
            'client_id' => $this->client->id,
            'name' => 'Authorised controlled creation',
            'controlled_drug' => true,
        ]);

        foreach ([
            'clients.medical.medications.store',
            'operations.clients.medical.medications.store',
        ] as $routeIndex => $routeName) {
            $profileCreatedName = 'Authorised profile controlled creation '.$routeIndex;

            $this->actingAs($actor)
                ->post(route($routeName, $this->client), [
                    'name' => $profileCreatedName,
                    'dosage' => '5 mg',
                    'frequency' => 'Once daily',
                    'controlled_drug' => true,
                ])
                ->assertRedirect();
            $this->assertDatabaseHas('client_medications', [
                'client_id' => $this->client->id,
                'name' => $profileCreatedName,
                'controlled_drug' => true,
            ]);
        }

        foreach ([
            'clients.medical.medications.update',
            'operations.clients.medical.medications.update',
        ] as $routeIndex => $routeName) {
            $profileUpdateTarget = $this->controlledMedication([
                'name' => 'Controlled profile update target '.$routeIndex,
                'created_by' => $creator->id,
            ]);
            $updatedName = 'Authorised profile controlled update '.$routeIndex;

            $this->actingAs($actor)
                ->put(route($routeName, [$this->client, $profileUpdateTarget]), [
                    'name' => $updatedName,
                ])
                ->assertRedirect();
            $this->assertSame($updatedName, $profileUpdateTarget->fresh()->name);
        }

        $updateTarget = $this->controlledMedication(['created_by' => $creator->id]);
        $this->actingAs($actor)
            ->put(route('emar.medications.update', $updateTarget), [
                'medication_name' => 'Authorised controlled update',
            ])
            ->assertRedirect();
        $this->assertSame('Authorised controlled update', $updateTarget->fresh()->name);

        $verifyTarget = $this->controlledMedication(['created_by' => $creator->id]);
        $this->actingAs($actor)
            ->post(route('emar.medications.verify', $verifyTarget))
            ->assertRedirect();
        $this->assertSame('verified', $verifyTarget->fresh()->approval_status);
        $this->assertSame($actor->id, (int) $verifyTarget->fresh()->verified_by);

        $rejectTarget = $this->controlledMedication(['created_by' => $creator->id]);
        $this->actingAs($actor)
            ->post(route('emar.medications.reject', $rejectTarget), [
                'rejection_reason' => 'The controlled order does not match the signed chart.',
            ])
            ->assertRedirect();
        $this->assertSame('rejected', $rejectTarget->fresh()->approval_status);

        foreach ([
            'clients.medical.medications.discontinue',
            'operations.clients.medical.medications.discontinue',
            'emar.medications.discontinue',
        ] as $index => $routeName) {
            $discontinueTarget = $this->controlledMedication([
                'name' => 'Authorised controlled discontinuation '.$index,
                'approval_status' => 'verified',
                'created_by' => $creator->id,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]);
            $routeParameters = $routeName === 'emar.medications.discontinue'
                ? [$discontinueTarget]
                : [$this->client, $discontinueTarget];

            $this->actingAs($actor)
                ->post(route($routeName, $routeParameters), [
                    'reason' => 'The prescriber stopped this controlled medication.',
                    'request_key' => 'controlled-allow-'.$index,
                ])
                ->assertRedirect();
            $this->assertSame('ceased', $discontinueTarget->fresh()->state);
            $this->assertFalse((bool) $discontinueTarget->fresh()->active);
        }
    }

    public function test_profile_routes_preserve_ordinary_mutations_without_controlled_capabilities(): void
    {
        $actor = $this->makeSiteActor([
            'clients.update',
            'medications.view',
            'medications.orders.manage',
        ]);

        $this->actingAs($actor)
            ->post(route('emar.medications.store'), [
                'client_id' => $this->client->id,
                'medication_name' => 'Ordinary eMAR medication',
                'dose' => '10 mg',
                'frequency' => 'Once daily',
                'controlled_drug' => false,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('client_medications', [
            'client_id' => $this->client->id,
            'name' => 'Ordinary eMAR medication',
            'controlled_drug' => false,
        ]);

        $ordinaryEmarMedication = $this->controlledMedication([
            'name' => 'Ordinary eMAR update target',
            'controlled_drug' => false,
            'witness_required' => false,
            'created_by' => $actor->id,
        ]);
        $this->actingAs($actor)
            ->put(route('emar.medications.update', $ordinaryEmarMedication), [
                'medication_name' => 'Ordinary eMAR medication updated',
            ])
            ->assertRedirect();
        $this->assertSame('Ordinary eMAR medication updated', $ordinaryEmarMedication->fresh()->name);

        foreach ([
            'clients.medical.medications.store',
            'operations.clients.medical.medications.store',
        ] as $routeIndex => $routeName) {
            $createdName = 'Ordinary profile medication '.$routeIndex;

            $this->actingAs($actor)
                ->post(route($routeName, $this->client), [
                    'name' => $createdName,
                    'dosage' => '10 mg',
                    'frequency' => 'Once daily',
                    'controlled_drug' => false,
                ])
                ->assertRedirect();
            $this->assertDatabaseHas('client_medications', [
                'client_id' => $this->client->id,
                'name' => $createdName,
                'controlled_drug' => false,
            ]);
        }

        foreach ([
            'clients.medical.medications.update',
            'operations.clients.medical.medications.update',
        ] as $routeIndex => $routeName) {
            $ordinaryMedication = $this->controlledMedication([
                'name' => 'Ordinary profile update target '.$routeIndex,
                'controlled_drug' => false,
                'witness_required' => false,
                'created_by' => $actor->id,
            ]);
            $updatedName = 'Ordinary profile medication updated '.$routeIndex;

            $this->actingAs($actor)
                ->put(route($routeName, [$this->client, $ordinaryMedication]), [
                    'name' => $updatedName,
                ])
                ->assertRedirect();
            $this->assertSame($updatedName, $ordinaryMedication->fresh()->name);
        }
    }

    /** @param array<int, string> $permissions */
    private function makeSiteActor(array $permissions): User
    {
        $actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionMap = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $actor->permissionOverrides()->sync($permissionMap);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $actor->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $actor->id,
            'status' => 'in_progress',
        ]);

        return $actor;
    }

    private function controlledMedication(array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Controlled order',
            'dosage' => '5 mg',
            'frequency' => 'Once daily',
            'dose_times' => ['10:00'],
            'controlled_drug' => true,
            'high_risk' => false,
            'witness_required' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'pending_verification',
            'verified_by' => null,
            'verified_at' => null,
        ], $overrides));
    }
}
