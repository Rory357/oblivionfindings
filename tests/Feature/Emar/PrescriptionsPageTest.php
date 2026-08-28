<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationPrescriberOrder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationScopeDecisionService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * The redesigned Prescriptions & Orders page serves a flat order/covert payload
 * (+ medications for the covert/link selects, + active-site brand colour), and
 * verbal orders now capture read-back metadata + a countersignature method.
 */
class PrescriptionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_serves_flat_payload_with_brand_colour(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
            'clients.update',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#6D4C41']);
        $client = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $site->id, 'status' => 'active']);
        $this->assignUserToClient($user, $client);
        $recorder = $this->makeRoleUser('support_worker');
        $this->assignUserToClient($recorder, $client);
        $med = ClientMedication::query()->create(['client_id' => $client->id, 'name' => 'Warfarin', 'dosage' => '3mg', 'frequency' => 'Once daily', 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        MedicationPrescriberOrder::create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'order_type' => 'new', 'status' => 'pending',
            'prescriber_name' => 'Dr Singh', 'medication_name' => 'Warfarin', 'dose' => '3mg', 'route' => 'Oral', 'frequency' => 'Once daily', 'order_date' => '2026-06-10',
            'controlled_drug_snapshot' => false,
            'received_by' => $recorder->id,
        ]);
        $covert = MedicationCovertAuthorisation::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'authorised_by_name' => 'Dr Singh',
            'clinical_justification' => 'Administration is clinically justified.',
            'authorised_date' => today(),
            'review_date' => today()->addMonth(),
            'status' => 'active',
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/emar/prescriptions?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Prescriptions')
                ->where('site_brand_colour', '#6D4C41')
                ->has('orders', 1)
                ->where('orders.0.medication_name', 'Warfarin')
                ->where('orders.0.client_name', 'Aroha Ngata')
                // Detail-modal enrichment: site (room is null here) is serialised.
                ->where('orders.0.client_site', $site->name)
                ->has('orders.0.client_room')
                ->where('orders.0.can_confirm', true)
                ->where('orders.0.can_countersign', false)
                ->where('orders.0.can_dispense', false)
                ->where('orders.0.can_link', false)
                ->where('orders.0.can_cancel', true)
                ->has('medications', 1)
                ->where('medications.0.id', $med->id)
                ->where('medications.0.controlled_drug', false)
                ->where('medications.0.can_create_covert_authorisation', true)
                ->where('medications.0.can_link_prescriber_order', true)
                ->has('covert', 1)
                ->where('covert.0.id', $covert->id)
                ->where('covert.0.client_medication_id', $med->id)
                ->where('covert.0.can_revoke', true)
                ->has('clients')
                // Client EntityFilter description is driven by site_name.
                ->where('clients.0.site_name', $site->name)
                ->where('can.manage_orders', true)
                ->where('can.verify_orders', true)
                ->where('can_create_manual_order', true)
                ->where('can_create_covert', true)
                ->where('current_user_id', $user->id)
            );
    }

    public function test_page_emits_false_mutation_capabilities_for_a_view_only_actor(): void
    {
        $this->seed(RbacSeeder::class);
        $viewer = $this->makeRoleUser('support_worker');
        $this->grantPermissions($viewer, ['medications.view']);
        $this->denyPermissions($viewer, [
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $client = $this->makeScopedClient($viewer);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Viewer',
            'medication_name' => $medication->name,
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
            'controlled_drug_snapshot' => false,
        ]);
        MedicationCovertAuthorisation::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'authorised_by_name' => 'Dr Viewer',
            'clinical_justification' => 'Administration is clinically justified.',
            'authorised_date' => today(),
            'review_date' => today()->addMonth(),
            'status' => 'active',
            'recorded_by' => $viewer->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('emar.prescriptions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.manage_orders', false)
                ->where('can.verify_orders', false)
                ->where('can_create_manual_order', false)
                ->where('can_create_covert', false)
                ->where('orders.0.can_confirm', false)
                ->where('orders.0.can_countersign', false)
                ->where('orders.0.can_dispense', false)
                ->where('orders.0.can_link', false)
                ->where('orders.0.can_cancel', false)
                ->where('medications.0.can_create_covert_authorisation', false)
                ->where('medications.0.can_link_prescriber_order', false)
                ->where('covert.0.client_medication_id', $medication->id)
                ->where('covert.0.can_revoke', false));
    }

    public function test_verbal_order_captures_read_back_and_requires_countersign(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.orders.manage',
            'clients.update',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);
        $client = $this->makeScopedClient($user);
        $witness = $this->makeRoleUser('support_worker');
        $witness->forceFill(['password' => Hash::make('witness-secret')])->save();
        $this->assignUserToClient($witness, $client);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignWitness = $this->makeRoleUser('support_worker');
        $foreignWitness->forceFill(['password' => Hash::make('foreign-secret')])->save();
        HrEmployeeProfile::factory()->create([
            'user_id' => $foreignWitness->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $payload = [
            'client_id' => $client->id,
            'order_type' => 'verbal',
            'prescriber_name' => 'Dr Lee',
            'medication_name' => 'Amoxicillin',
            'controlled_drug_snapshot' => false,
            'dose' => '500mg',
            'route' => 'Oral',
            'frequency' => 'Three times daily',
            'order_date' => '2026-06-15',
        ];

        $this->actingAs($user)
            ->post('/emar/prescriptions', $payload)
            ->assertSessionHasErrors('read_back_confirmed');
        $this->actingAs($user)
            ->post('/emar/prescriptions', [
                ...$payload,
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $witness->id,
            ])
            ->assertSessionHasErrors('read_back_witness_credential');
        $missingWitnessId = (int) User::query()->max('id') + 1000;
        foreach ([$missingWitnessId, $foreignWitness->id] as $concealedWitnessId) {
            $this->actingAs($user)
                ->post('/emar/prescriptions', [
                    ...$payload,
                    'read_back_confirmed' => true,
                    'read_back_witnessed_by' => $concealedWitnessId,
                ])
                ->assertNotFound();
        }
        $this->actingAs($user)
            ->post('/emar/prescriptions', [
                ...$payload,
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $user->id,
                'read_back_witness_credential' => 'password',
            ])
            ->assertSessionHasErrors('read_back_witnessed_by');
        $wrongCredentialResponse = $this->actingAs($user)
            ->post('/emar/prescriptions', [
                ...$payload,
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $witness->id,
                'read_back_witness_credential' => 'wrong-secret',
            ])
            ->assertSessionHasErrors('read_back_witness_credential');
        $wrongCredentialResponse->assertSessionHasErrors('read_back_witness_credential');
        $this->assertArrayNotHasKey(
            'read_back_witness_credential',
            session()->getOldInput(),
        );
        $this->assertStringNotContainsString(
            'wrong-secret',
            json_encode(session()->getOldInput(), JSON_THROW_ON_ERROR),
        );
        $this->actingAs($user)
            ->post('/emar/prescriptions', [
                ...$payload,
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $foreignWitness->id,
                'read_back_witness_credential' => 'foreign-secret',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->post('/emar/prescriptions', [
                ...$payload,
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $witness->id,
                'read_back_witness_credential' => 'witness-secret',
            ])
            ->assertSessionHasNoErrors();

        $order = MedicationPrescriberOrder::where('medication_name', 'Amoxicillin')->first();
        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->requires_countersign);
        $this->assertTrue((bool) $order->read_back_confirmed);
        $this->assertSame($witness->id, $order->read_back_witnessed_by);
        $this->assertNotNull($order->read_back_verified_at);
        $this->assertSame(
            MedicationPrescriberOrder::READ_BACK_VERIFICATION_METHOD_PASSWORD,
            $order->read_back_verification_method,
        );
        $this->assertArrayNotHasKey('read_back_witness_credential', $order->getAttributes());
        $this->assertSame(0, RateLimiter::attempts(implode(':', [
            'medications',
            'prescriber-order-read-back',
            $user->id,
            $witness->id,
            $client->site_id,
        ])));
        $audit = AuditLog::query()
            ->where('action', 'medications.prescriber_order.created')
            ->where('auditable_id', $order->id)
            ->sole();
        $this->assertSame($witness->id, (int) $audit->meta['read_back_witnessed_by']);
        $this->assertSame('password', $audit->meta['read_back_witness_method']);
        $this->assertNotEmpty($audit->meta['read_back_witnessed_at']);
        $this->assertStringNotContainsString('witness-secret', $audit->toJson());
        $this->assertSame(1, MedicationPrescriberOrder::query()->where('medication_name', 'Amoxicillin')->count());
    }

    public function test_unlinked_ordinary_order_needs_only_base_authority_and_controlled_classification_requires_both_capabilities(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('support_worker');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $client = $this->makeScopedClient($user);
        $basePayload = [
            'client_id' => $client->id,
            'order_type' => 'new',
            'prescriber_name' => 'Dr Classifier',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today()->toDateString(),
        ];

        $this->denyPermissions($user, [
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->get(route('emar.prescriptions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_create_manual_order', true)
                ->where('can_classify_manual_orders', false)
                ->where('clients.0.id', $client->id)
                ->where('clients.0.can_create_prescriber_order', true));

        foreach ([
            'neither' => [false, false],
            'view_only' => [true, false],
            'record_only' => [false, true],
        ] as $case => [$canView, $canRecord]) {
            $canView
                ? $this->grantPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY])
                : $this->denyPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY]);
            $canRecord
                ? $this->grantPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY])
                : $this->denyPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY]);
            $user->unsetRelation('permissionOverrides')->unsetRelation('roles');

            $this->actingAs($user)
                ->post(route('emar.prescriptions.store'), [
                    ...$basePayload,
                    'medication_name' => 'Ordinary manual classification '.$case,
                    'controlled_drug_snapshot' => false,
                ])
                ->assertSessionHasNoErrors();
            $this->actingAs($user)
                ->post(route('emar.prescriptions.store'), [
                    ...$basePayload,
                    'medication_name' => 'Blocked controlled classification '.$case,
                    'controlled_drug_snapshot' => true,
                ])
                ->assertNotFound();
        }

        $this->grantPermissions($user, [
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        foreach ([false, true] as $controlled) {
            $this->actingAs($user)
                ->post(route('emar.prescriptions.store'), [
                    ...$basePayload,
                    'medication_name' => $controlled
                        ? 'Privileged controlled classification'
                        : 'Privileged ordinary classification',
                    'controlled_drug_snapshot' => $controlled,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, MedicationPrescriberOrder::query()
            ->where('medication_name', 'like', 'Ordinary manual classification %')
            ->where('controlled_drug_snapshot', false)
            ->count());
        $this->assertSame(0, MedicationPrescriberOrder::query()
            ->where('medication_name', 'like', 'Blocked controlled classification %')
            ->count());
        $this->assertDatabaseHas('medication_prescriber_orders', [
            'medication_name' => 'Privileged ordinary classification',
            'controlled_drug_snapshot' => false,
        ]);
        $this->assertDatabaseHas('medication_prescriber_orders', [
            'medication_name' => 'Privileged controlled classification',
            'controlled_drug_snapshot' => true,
        ]);
    }

    public function test_linked_order_target_is_concealed_before_content_validation_and_medication_name_is_canonical(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->makeRoleUser('support_worker');
        $this->grantPermissions($actor, [
            'medications.view',
            'medications.orders.manage',
        ]);
        $this->denyPermissions($actor, [
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $client = $this->makeScopedClient($actor);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Canonical linked medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Concealed controlled medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => Client::factory()->create(['site_id' => $client->site_id])->id,
            'name' => 'Foreign linked medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $validPayload = [
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'order_type' => 'cease',
            'prescriber_name' => 'Dr Canonical',
            'medication_name' => 'Forged different medication',
            'dose' => 'Cease',
            'route' => 'Oral',
            'frequency' => 'Cease',
            'order_date' => today()->toDateString(),
        ];

        $this->actingAs($actor)
            ->post(route('emar.prescriptions.store'), $validPayload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $storedOrder = MedicationPrescriberOrder::query()
            ->where('client_medication_id', $ordinaryMedication->id)
            ->sole();
        $this->assertSame($ordinaryMedication->name, $storedOrder->medication_name);
        $this->assertDatabaseMissing('medication_prescriber_orders', [
            'medication_name' => 'Forged different medication',
        ]);

        $this->actingAs($actor)
            ->post(route('emar.prescriptions.store'), [
                ...$validPayload,
                'client_medication_id' => 0,
            ])
            ->assertSessionHasErrors('client_medication_id');

        $missingMedicationId = (int) ClientMedication::query()->max('id') + 1000;
        foreach ([$missingMedicationId, $foreignMedication->id, $controlledMedication->id] as $concealedId) {
            $this->actingAs($actor)
                ->post(route('emar.prescriptions.store'), [
                    'client_id' => $client->id,
                    'client_medication_id' => $concealedId,
                ])
                ->assertNotFound();
        }

        $this->assertSame(1, MedicationPrescriberOrder::query()->count());
    }

    public function test_countersign_persists_method_and_confirms(): void
    {
        $this->seed(RbacSeeder::class);
        $receiver = $this->makeRoleUser('admin');
        $this->grantPermissions($receiver, [
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);
        $client = $this->makeScopedClient($receiver);
        $witness = $this->makeRoleUser('support_worker');
        $witness->forceFill(['password' => Hash::make('read-back-secret')])->save();
        $countersigner = $this->makeRoleUser('support_worker');
        foreach ([$witness, $countersigner] as $actor) {
            $this->grantPermissions($actor, [
                'medications.view',
                'medications.orders.manage',
                'medications.orders.verify',
            ]);
            $this->assignUserToClient($actor, $client);
        }
        $verifyOnly = $this->makeRoleUser('support_worker');
        $this->grantPermissions($verifyOnly, [
            'medications.view',
            'medications.orders.verify',
        ]);
        $this->denyPermissions($verifyOnly, ['medications.orders.manage']);
        $this->assignUserToClient($verifyOnly, $client);
        $legacyOrder = MedicationPrescriberOrder::create([
            'client_id' => $client->id, 'order_type' => 'verbal', 'status' => 'pending', 'requires_countersign' => true,
            'prescriber_name' => 'Dr Legacy', 'medication_name' => 'Legacy verbal order', 'dose' => '500mg', 'route' => 'Oral', 'frequency' => 'TDS', 'order_date' => '2026-06-15',
            'controlled_drug_snapshot' => false,
            'read_back_confirmed' => true,
            'read_back_witnessed_by' => $witness->id,
            'received_by' => $receiver->id,
        ]);
        $this->actingAs($countersigner)
            ->post(route('emar.prescriptions.countersign', $legacyOrder), [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertSessionHasErrors('countersign');
        $this->assertSame('pending', $legacyOrder->fresh()->status);

        $this->actingAs($receiver)
            ->post(route('emar.prescriptions.store'), [
                'client_id' => $client->id,
                'order_type' => 'verbal',
                'prescriber_name' => 'Dr Lee',
                'medication_name' => 'Verified Amoxicillin order',
                'controlled_drug_snapshot' => false,
                'dose' => '500mg',
                'route' => 'Oral',
                'frequency' => 'Three times daily',
                'order_date' => '2026-06-15',
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $witness->id,
                'read_back_witness_credential' => 'read-back-secret',
            ])
            ->assertSessionHasNoErrors();
        $order = MedicationPrescriberOrder::query()
            ->where('medication_name', 'Verified Amoxicillin order')
            ->sole();
        $this->assertTrue($order->hasVerifiedReadBack());

        foreach ([$receiver, $witness] as $nonIndependentActor) {
            $this->actingAs($nonIndependentActor)
                ->post('/emar/prescriptions/'.$order->id.'/countersign', [
                    'countersign_method' => 'electronic',
                    'prescriber_declaration' => true,
                ])
                ->assertSessionHasErrors('countersign');
        }
        $this->actingAs($verifyOnly)
            ->post('/emar/prescriptions/'.$order->id.'/countersign', [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertForbidden();
        $this->actingAs($countersigner)
            ->post('/emar/prescriptions/'.$order->id.'/countersign', [
                'countersign_method' => 'fax',
                'prescriber_declaration' => true,
            ])
            ->assertSessionHasErrors('countersign_method');
        $this->actingAs($countersigner)
            ->post('/emar/prescriptions/'.$order->id.'/countersign', [
                'countersign_method' => 'electronic',
            ])
            ->assertSessionHasErrors('prescriber_declaration');
        $this->actingAs($countersigner)
            ->post('/emar/prescriptions/'.$order->id.'/countersign', [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->countersigned_at);
        $this->assertSame('electronic', $order->countersign_method);
        $this->assertSame($countersigner->id, (int) $order->countersigned_by);
        $this->assertSame('confirmed', $order->status);
        $audit = AuditLog::query()
            ->where('action', 'medications.prescriber_order.countersigned')
            ->where('auditable_id', $order->id)
            ->sole();
        $this->assertSame($countersigner->id, (int) $audit->user_id);
        $this->assertSame($countersigner->id, (int) $audit->meta['actor_id']);
        $this->assertSame('pending', $audit->meta['status_before']);
        $this->assertSame('confirmed', $audit->meta['status_after']);
        $this->assertSame('electronic', $audit->meta['countersign_method']);
        $this->actingAs($countersigner)
            ->post('/emar/prescriptions/'.$order->id.'/countersign', [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertSessionHasErrors('countersign');
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medications.prescriber_order.countersigned')
            ->where('auditable_id', $order->id)
            ->count());
    }

    public function test_verified_read_back_freezes_attested_update_content_until_independent_countersign(): void
    {
        $this->seed(RbacSeeder::class);
        $receiver = $this->makeRoleUser('admin');
        $this->grantPermissions($receiver, [
            'medications.view',
            'medications.orders.manage',
        ]);
        $client = $this->makeScopedClient($receiver);
        $witness = $this->makeRoleUser('support_worker');
        $witness->forceFill(['password' => Hash::make('frozen-read-back-secret')])->save();
        $this->assignUserToClient($witness, $client);
        $countersigner = $this->makeRoleUser('support_worker');
        $this->grantPermissions($countersigner, [
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $this->assignUserToClient($countersigner, $client);
        $linkCandidate = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Different charted medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $this->actingAs($receiver)
            ->post(route('emar.prescriptions.store'), [
                'client_id' => $client->id,
                'order_type' => 'verbal',
                'prescriber_name' => 'Dr Attested',
                'medication_name' => 'Original witnessed medication',
                'controlled_drug_snapshot' => false,
                'dose' => '1 tablet',
                'route' => 'Oral',
                'frequency' => 'Once daily',
                'instructions' => 'Give with the evening meal.',
                'clinical_notes' => 'Read back exactly and witnessed.',
                'order_date' => today()->toDateString(),
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $witness->id,
                'read_back_witness_credential' => 'frozen-read-back-secret',
            ])
            ->assertSessionHasNoErrors();

        $order = MedicationPrescriberOrder::query()
            ->where('medication_name', 'Original witnessed medication')
            ->sole();
        $this->assertTrue($order->hasVerifiedReadBack());
        $baselineAttributes = $order->getAttributes();
        $attestedFields = [
            'client_medication_id',
            'controlled_drug_snapshot',
            'medication_name',
            'instructions',
            'clinical_notes',
            'requires_countersign',
            'read_back_confirmed',
            'read_back_witnessed_by',
            'read_back_verified_at',
            'read_back_verification_method',
            'received_by',
        ];
        $attestedAttributes = collect($baselineAttributes)->only($attestedFields)->all();
        $auditCount = fn (): int => AuditLog::query()
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->id)
            ->count();
        $baselineAuditCount = $auditCount();

        $this->actingAs($receiver)
            ->put(route('emar.prescriptions.update', $order), [
                'instructions' => $order->instructions,
                'clinical_notes' => $order->clinical_notes,
                'client_medication_id' => null,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame($baselineAttributes, $order->fresh()->getAttributes());
        $this->assertSame($baselineAuditCount, $auditCount());

        foreach ([
            'instructions' => ['instructions' => 'Give without food instead.'],
            'clinical_notes' => ['clinical_notes' => 'Changed after the witnessed read-back.'],
            'client_medication_id' => ['client_medication_id' => $linkCandidate->id],
        ] as $errorField => $mutation) {
            $this->actingAs($receiver)
                ->put(route('emar.prescriptions.update', $order), $mutation)
                ->assertSessionHasErrors($errorField);
            $this->assertSame($baselineAttributes, $order->fresh()->getAttributes());
            $this->assertSame($baselineAuditCount, $auditCount());
        }

        $this->actingAs($countersigner)
            ->post(route('emar.prescriptions.countersign', $order), [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame($countersigner->id, (int) $order->countersigned_by);
        $this->assertSame('electronic', $order->countersign_method);
        $this->assertSame(
            $attestedAttributes,
            collect($order->getAttributes())->only($attestedFields)->all(),
        );
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medications.prescriber_order.countersigned')
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->id)
            ->where('user_id', $countersigner->id)
            ->count());
    }

    public function test_dedicated_transitions_block_generic_status_updates_and_attribute_dispensing_to_actor(): void
    {
        $this->seed(RbacSeeder::class);
        $manager = $this->makeRoleUser('support_worker');
        $this->grantPermissions($manager, [
            'medications.view',
            'medications.orders.manage',
        ]);
        $this->denyPermissions($manager, ['medications.orders.verify']);
        $client = $this->makeScopedClient($manager);
        $verifier = $this->makeRoleUser('support_worker');
        $this->grantPermissions($verifier, [
            'medications.view',
            'medications.orders.verify',
        ]);
        $this->denyPermissions($verifier, ['medications.orders.manage']);
        $this->assignUserToClient($verifier, $client);
        $order = MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Lifecycle',
            'medication_name' => 'Lifecycle medicine',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
            'controlled_drug_snapshot' => false,
            'received_by' => $manager->id,
        ]);
        $legacyOrder = MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Legacy',
            'medication_name' => 'Legacy order without recorder provenance',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
            'controlled_drug_snapshot' => false,
            'received_by' => null,
        ]);

        $this->actingAs($verifier)
            ->get(route('emar.prescriptions'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders', function ($orders) use ($legacyOrder): bool {
                    $row = collect($orders)->firstWhere('id', $legacyOrder->id);

                    return $row !== null && $row['can_confirm'] === false;
                }));
        $this->actingAs($verifier)
            ->post(route('emar.prescriptions.confirm', $legacyOrder))
            ->assertSessionHasErrors('confirm');
        $this->assertSame('pending', $legacyOrder->fresh()->status);

        foreach (['confirmed', 'cancelled', 'dispensed'] as $status) {
            $this->actingAs($manager)
                ->put(route('emar.prescriptions.update', $order), ['status' => $status])
                ->assertSessionHasErrors('status');
        }
        $this->actingAs($manager)
            ->put(route('emar.prescriptions.update', $order), [
                'dispensed_by' => $verifier->id,
                'dispensed_at' => now()->toIso8601String(),
            ])
            ->assertSessionHasErrors(['dispensed_by', 'dispensed_at']);
        $this->actingAs($manager)
            ->put(route('emar.prescriptions.update', $order), [
                'pharmacy_name' => 'Bypass Pharmacy',
                'pharmacy_notes' => 'Bypass notes',
                'batch_number' => 'BYPASS',
                'batch_expiry' => today()->addYear()->toDateString(),
            ])
            ->assertSessionHasErrors([
                'pharmacy_name',
                'pharmacy_notes',
                'batch_number',
                'batch_expiry',
            ]);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->dispensed_by);

        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$order->id.'/confirm')
            ->assertForbidden();
        $this->actingAs($verifier)
            ->post('/emar/prescriptions/'.$order->id.'/confirm')
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $order->fresh()->status);
        $confirmAudit = AuditLog::query()
            ->where('action', 'medications.prescriber_order.confirmed')
            ->where('auditable_id', $order->id)
            ->sole();
        $this->assertSame($verifier->id, (int) $confirmAudit->user_id);
        $this->assertSame('pending', $confirmAudit->meta['status_before']);
        $this->assertSame('confirmed', $confirmAudit->meta['status_after']);
        $this->actingAs($verifier)
            ->post('/emar/prescriptions/'.$order->id.'/confirm')
            ->assertSessionHasErrors('confirm');
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medications.prescriber_order.confirmed')
            ->where('auditable_id', $order->id)
            ->count());

        $workerToday = now(config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
        $dispensePayload = [
            'pharmacy_name' => 'Community Pharmacy',
            'batch_number' => 'BATCH-001',
            'dispensed_at' => $workerToday->toDateString(),
        ];
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$order->id.'/dispense')
            ->assertSessionHasErrors(['pharmacy_name', 'dispensed_at']);
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$order->id.'/dispense', [
                ...$dispensePayload,
                'dispensed_at' => $workerToday->copy()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('dispensed_at');
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignDispenser = $this->makeRoleUser('support_worker');
        $this->grantPermissions($foreignDispenser, ['medications.orders.manage']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $foreignDispenser->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->actingAs($foreignDispenser)
            ->post('/emar/prescriptions/'.$order->id.'/dispense', $dispensePayload)
            ->assertNotFound();
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$order->id.'/dispense', [
                ...$dispensePayload,
                'dispensed_by' => $verifier->id,
            ])
            ->assertSessionHasErrors('dispensed_by');
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$order->id.'/dispense', $dispensePayload)
            ->assertSessionHasNoErrors();
        $this->assertSame('dispensed', $order->fresh()->status);
        $this->assertSame($manager->id, (int) $order->fresh()->dispensed_by);
        $this->assertSame('Community Pharmacy', $order->fresh()->pharmacy_name);
        $dispenseAudit = AuditLog::query()
            ->where('action', 'medications.prescriber_order.dispensed')
            ->where('auditable_id', $order->id)
            ->sole();
        $this->assertSame($manager->id, (int) $dispenseAudit->user_id);
        $this->assertSame($manager->id, (int) $dispenseAudit->meta['actor_id']);
        $this->assertSame('confirmed', $dispenseAudit->meta['status_before']);
        $this->assertSame('dispensed', $dispenseAudit->meta['status_after']);
        $this->assertSame('Community Pharmacy', $dispenseAudit->meta['pharmacy_name']);
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$order->id.'/dispense', $dispensePayload)
            ->assertSessionHasErrors('dispense');
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medications.prescriber_order.dispensed')
            ->where('auditable_id', $order->id)
            ->count());

        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$order->id.'/cancel', [
                'reason' => 'Must not cancel a completed dispense.',
            ])
            ->assertSessionHasErrors('cancel');
        $this->assertSame('dispensed', $order->fresh()->status);
    }

    public function test_cancel_requires_a_reason_and_cannot_relabel_a_confirmed_cease_order(): void
    {
        $this->seed(RbacSeeder::class);
        $manager = $this->makeRoleUser('support_worker');
        $this->grantPermissions($manager, [
            'medications.view',
            'medications.orders.manage',
        ]);
        $this->denyPermissions($manager, ['medications.orders.verify']);
        $client = $this->makeScopedClient($manager);
        $verifier = $this->makeRoleUser('support_worker');
        $this->grantPermissions($verifier, [
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $this->assignUserToClient($verifier, $client);

        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Cease order medicine',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $unlinkedCease = MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'order_type' => 'cease',
            'status' => 'pending',
            'prescriber_name' => 'Dr Link First',
            'medication_name' => 'Unlinked cease medicine',
            'dose' => 'Cease',
            'route' => 'Oral',
            'frequency' => 'Cease',
            'order_date' => today(),
            'controlled_drug_snapshot' => false,
            'received_by' => $manager->id,
        ]);
        $this->actingAs($verifier)
            ->get(route('emar.prescriptions'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders', function ($orders) use ($unlinkedCease): bool {
                    $row = collect($orders)->firstWhere('id', $unlinkedCease->id);

                    return $row !== null
                        && $row['can_link'] === true
                        && $row['can_confirm'] === false
                        && $row['can_dispense'] === false;
                }));
        $this->actingAs($verifier)
            ->post('/emar/prescriptions/'.$unlinkedCease->id.'/confirm')
            ->assertSessionHasErrors('confirm');
        $this->assertSame('pending', $unlinkedCease->fresh()->status);

        $pending = MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Cancel',
            'medication_name' => 'Pending cancellation medicine',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
            'controlled_drug_snapshot' => false,
            'received_by' => $manager->id,
        ]);
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$pending->id.'/cancel')
            ->assertSessionHasErrors('reason');
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$pending->id.'/cancel', [
                'reason' => str_repeat('x', 501),
            ])
            ->assertSessionHasErrors('reason');
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$pending->id.'/cancel', [
                'reason' => 'The prescriber replaced this pending order.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $pending->fresh()->status);
        $cancelAudit = AuditLog::query()
            ->where('action', 'medications.prescriber_order.cancelled')
            ->where('auditable_id', $pending->id)
            ->sole();
        $this->assertSame($manager->id, (int) $cancelAudit->user_id);
        $this->assertSame('The prescriber replaced this pending order.', $cancelAudit->meta['reason']);
        $this->assertSame('pending', $cancelAudit->meta['status_before']);
        $this->assertSame('cancelled', $cancelAudit->meta['status_after']);
        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$pending->id.'/cancel', [
                'reason' => 'Replay must not rewrite terminal state.',
            ])
            ->assertSessionHasErrors('cancel');
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medications.prescriber_order.cancelled')
            ->where('auditable_id', $pending->id)
            ->count());

        $this->actingAs($manager)
            ->post('/emar/prescriptions', [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'order_type' => 'cease',
                'prescriber_name' => 'Dr Cease',
                'medication_name' => $medication->name,
                'dose' => 'Cease',
                'route' => 'Oral',
                'frequency' => 'Cease',
                'order_date' => today()->toDateString(),
            ])
            ->assertSessionHasNoErrors();
        $ceaseOrder = MedicationPrescriberOrder::query()
            ->where('client_medication_id', $medication->id)
            ->where('order_type', 'cease')
            ->sole();
        $this->assertSame('pending', $ceaseOrder->status);

        $this->actingAs($verifier)
            ->post('/emar/prescriptions/'.$ceaseOrder->id.'/confirm')
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $ceaseOrder->fresh()->status);
        $this->assertSame('ceased', $medication->fresh()->state);
        $this->assertFalse((bool) $medication->fresh()->active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.prescriber_order.confirmed',
            'auditable_id' => $ceaseOrder->id,
            'user_id' => $verifier->id,
        ]);

        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$ceaseOrder->id.'/dispense', [
                'pharmacy_name' => 'Community Pharmacy',
                'dispensed_at' => now(config('app.worker_timezone') ?: config('app.timezone', 'UTC'))->toDateString(),
            ])
            ->assertSessionHasErrors('dispense');

        $this->actingAs($manager)
            ->post('/emar/prescriptions/'.$ceaseOrder->id.'/cancel', [
                'reason' => 'Must not detach the order from its cease effect.',
            ])
            ->assertSessionHasErrors('cancel');
        $this->assertSame('confirmed', $ceaseOrder->fresh()->status);
        $this->assertSame('ceased', $medication->fresh()->state);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.prescriber_order.cancelled',
            'auditable_id' => $ceaseOrder->id,
        ]);
    }

    public function test_covert_authorisations_reject_future_dates_and_parallel_active_records(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        $client = $this->makeScopedClient($user);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $workerToday = now(config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
        $payload = [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'authorised_by_name' => 'Dr Covert',
            'clinical_justification' => 'Clinically justified',
            'authorised_date' => $workerToday->toDateString(),
            'review_date' => $workerToday->copy()->addMonth()->toDateString(),
        ];

        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$payload,
                'authorised_date' => $workerToday->copy()->addDay()->toDateString(),
                'review_date' => $workerToday->copy()->addMonth()->toDateString(),
            ])
            ->assertSessionHasErrors('authorised_date');
        $this->actingAs($user)
            ->post(route('emar.covert.store'), $payload)
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$payload,
                'clinical_justification' => 'Overlapping authorisation',
            ])
            ->assertSessionHasErrors('clinical_justification');
        $this->assertSame(1, MedicationCovertAuthorisation::query()
            ->where('client_medication_id', $medication->id)
            ->where('status', 'active')
            ->count());
        $authorisation = MedicationCovertAuthorisation::query()
            ->where('client_medication_id', $medication->id)
            ->sole();
        $audit = AuditLog::query()
            ->where('action', 'medications.covert_authorisation.created')
            ->where('auditable_id', $authorisation->id)
            ->sole();
        $this->assertSame($user->id, (int) $audit->user_id);
        $this->assertSame($medication->id, (int) $audit->meta['client_medication_id']);
        $this->assertSame('active', $audit->meta['status_after']);
    }

    public function test_order_creation_defaults_prescriber_type_when_posted_blank(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage', 'clients.update']);
        $client = $this->makeScopedClient($user);

        // The create dialog posts an empty prescriber_type; ConvertEmptyStringsToNull
        // turns it into null. The column is NOT NULL with a 'gp' default, so a blank
        // prescriber type must fall back to the default instead of a 500 (regression
        // for the integrity-constraint violation on every browser-submitted order).
        $this->actingAs($user)
            ->post('/emar/prescriptions', [
                'client_id' => $client->id,
                'order_type' => 'new',
                'prescriber_name' => 'Dr Lee',
                'prescriber_type' => '',
                'medication_name' => 'Paracetamol',
                'controlled_drug_snapshot' => false,
                'dose' => '1g',
                'route' => 'Oral',
                'frequency' => 'Twice daily',
                'order_date' => '2026-06-15',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $order = MedicationPrescriberOrder::where('medication_name', 'Paracetamol')->first();
        $this->assertNotNull($order);
        $this->assertSame('gp', $order->prescriber_type);
        $audit = AuditLog::query()
            ->where('action', 'medications.prescriber_order.created')
            ->where('auditable_id', $order->id)
            ->sole();
        $this->assertSame($user->id, (int) $audit->user_id);
        $this->assertSame('pending', $audit->meta['status_after']);
    }

    public function test_order_creation_rolls_back_when_strict_audit_fails(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->makeRoleUser('admin');
        $this->grantPermissions($actor, [
            'medications.view',
            'medications.orders.manage',
        ]);
        $client = $this->makeScopedClient($actor);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Strict audit creation medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $this->assertStrictAuditFailureRollsBack(
            'medications.prescriber_order.created',
            fn () => $this->actingAs($actor)
                ->post(route('emar.prescriptions.store'), [
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'order_type' => 'new',
                    'prescriber_name' => 'Dr Audit Failure',
                    'medication_name' => $medication->name,
                    'dose' => '1 tablet',
                    'route' => 'Oral',
                    'frequency' => 'Once daily',
                    'order_date' => today()->toDateString(),
                ]),
        );

        $this->assertDatabaseMissing('medication_prescriber_orders', [
            'medication_name' => $medication->name,
        ]);
        $this->assertDatabaseHas('client_medications', [
            'id' => $medication->id,
            'state' => 'active',
            'active' => true,
            'approval_status' => 'verified',
        ]);
    }

    public function test_cease_confirmation_rolls_back_every_effect_when_strict_audit_fails(): void
    {
        $this->seed(RbacSeeder::class);
        $recorder = $this->makeRoleUser('admin');
        $this->grantPermissions($recorder, [
            'medications.view',
            'medications.orders.manage',
        ]);
        $client = $this->makeScopedClient($recorder);
        $verifier = $this->makeRoleUser('support_worker');
        $this->grantPermissions($verifier, [
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $this->assignUserToClient($verifier, $client);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Strict audit cease medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'end_date' => null,
            'ceased_at' => null,
            'ceased_reason' => null,
            'ceased_by' => null,
            'version' => 1,
        ]);
        $order = MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'order_type' => 'cease',
            'status' => 'pending',
            'prescriber_name' => 'Dr Audit Failure',
            'medication_name' => $medication->name,
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
            'controlled_drug_snapshot' => false,
            'received_by' => $recorder->id,
        ]);

        $this->assertStrictAuditFailureRollsBack(
            'medications.prescriber_order.confirmed',
            fn () => $this->actingAs($verifier)
                ->post(route('emar.prescriptions.confirm', $order)),
        );

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseHas('client_medications', [
            'id' => $medication->id,
            'state' => 'active',
            'active' => true,
            'end_date' => null,
            'ceased_at' => null,
            'ceased_reason' => null,
            'ceased_by' => null,
            'version' => 1,
        ]);
        $this->assertFalse(MedicationOrderVersion::query()
            ->where('client_medication_id', $medication->id)
            ->exists());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.discontinued',
            'auditable_id' => $medication->id,
        ]);
    }

    public function test_covert_creation_rolls_back_when_strict_audit_fails(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->makeRoleUser('admin');
        $this->grantPermissions($actor, [
            'medications.view',
            'medications.orders.manage',
        ]);
        $client = $this->makeScopedClient($actor);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $workerToday = now(config('app.worker_timezone') ?: config('app.timezone', 'UTC'));

        $this->assertStrictAuditFailureRollsBack(
            'medications.covert_authorisation.created',
            fn () => $this->actingAs($actor)
                ->post(route('emar.covert.store'), [
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'authorised_by_name' => 'Dr Audit Failure',
                    'clinical_justification' => 'Must roll back with strict audit.',
                    'authorised_date' => $workerToday->toDateString(),
                    'review_date' => $workerToday->copy()->addMonth()->toDateString(),
                ]),
        );

        $this->assertDatabaseMissing('medication_covert_authorisations', [
            'client_medication_id' => $medication->id,
            'clinical_justification' => 'Must roll back with strict audit.',
        ]);
    }

    public function test_covert_revoke_rolls_back_when_strict_audit_fails(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->makeRoleUser('admin');
        $this->grantPermissions($actor, [
            'medications.view',
            'medications.orders.manage',
        ]);
        $client = $this->makeScopedClient($actor);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $authorisation = MedicationCovertAuthorisation::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'authorised_by_name' => 'Dr Audit Failure',
            'clinical_justification' => 'Must remain active on audit failure.',
            'authorised_date' => today(),
            'review_date' => today()->addMonth(),
            'status' => 'active',
            'recorded_by' => $actor->id,
        ]);

        $this->assertStrictAuditFailureRollsBack(
            'medications.covert_authorisation.revoked',
            fn () => $this->actingAs($actor)
                ->post(route('emar.covert.revoke', $authorisation), [
                    'reason' => 'Governance decision superseded.',
                ]),
        );

        $this->assertSame('active', $authorisation->fresh()->status);
        $this->assertDatabaseHas('medication_covert_authorisations', [
            'id' => $authorisation->id,
            'status' => 'active',
        ]);
    }

    public function test_prescriber_order_creation_enforces_chronology_and_allows_equal_and_future_effective_dates(): void
    {
        $workerNow = Carbon::parse('2026-08-28 08:00:00', 'Pacific/Auckland');
        Carbon::setTestNow($workerNow);

        try {
            $this->seed(RbacSeeder::class);
            $actor = $this->makeRoleUser('admin');
            $this->grantPermissions($actor, [
                'medications.view',
                'medications.orders.manage',
                'medications.orders.verify',
                'clients.update',
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
                MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            ]);
            $client = $this->makeScopedClient($actor);
            $basePayload = [
                'client_id' => $client->id,
                'controlled_drug_snapshot' => false,
                'order_type' => 'new',
                'prescriber_name' => 'Dr Chronology',
                'dose' => '1 tablet',
                'route' => 'Oral',
                'frequency' => 'Once daily',
            ];
            $orderCountBefore = MedicationPrescriberOrder::query()->count();
            $auditCountBefore = AuditLog::query()
                ->where('action', 'medications.prescriber_order.created')
                ->count();

            foreach ([
                'Effective before order' => [
                    ['order_date' => '2026-08-28', 'effective_date' => '2026-08-27', 'expiry_date' => '2026-08-30'],
                    'effective_date',
                ],
                'Expiry before order' => [
                    ['order_date' => '2026-08-28', 'expiry_date' => '2026-08-27'],
                    'expiry_date',
                ],
                'Expiry before effective' => [
                    ['order_date' => '2026-08-28', 'effective_date' => '2026-08-30', 'expiry_date' => '2026-08-29'],
                    'expiry_date',
                ],
            ] as $medicationName => [$dates, $errorKey]) {
                $this->actingAs($actor)
                    ->post(route('emar.prescriptions.store'), [
                        ...$basePayload,
                        'medication_name' => $medicationName,
                        ...$dates,
                    ])
                    ->assertSessionHasErrors($errorKey);
            }

            $this->assertSame($orderCountBefore, MedicationPrescriberOrder::query()->count());
            $this->assertSame(
                $auditCountBefore,
                AuditLog::query()->where('action', 'medications.prescriber_order.created')->count(),
            );

            foreach ([
                'Equal chronology' => [
                    'order_date' => '2026-08-28',
                    'effective_date' => '2026-08-28',
                    'expiry_date' => '2026-08-28',
                ],
                'Equal chronology without effective date' => [
                    'order_date' => '2026-08-28',
                    'expiry_date' => '2026-08-28',
                ],
                'Valid future effective chronology' => [
                    'order_date' => '2026-08-28',
                    'effective_date' => '2026-09-04',
                    'expiry_date' => '2026-09-28',
                ],
            ] as $medicationName => $dates) {
                $this->actingAs($actor)
                    ->post(route('emar.prescriptions.store'), [
                        ...$basePayload,
                        'medication_name' => $medicationName,
                        ...$dates,
                    ])
                    ->assertSessionHasNoErrors();

                $this->assertDatabaseHas('medication_prescriber_orders', [
                    'client_id' => $client->id,
                    'medication_name' => $medicationName,
                    ...$dates,
                ]);
            }

            $this->assertSame($orderCountBefore + 3, MedicationPrescriberOrder::query()->count());
            $this->assertSame(
                $auditCountBefore + 3,
                AuditLog::query()->where('action', 'medications.prescriber_order.created')->count(),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dispensing_date_cannot_predate_order_but_may_equal_order_before_future_effective_date(): void
    {
        $workerTimezone = 'Pacific/Auckland';
        $workerNow = Carbon::parse('2026-08-28 08:00:00', $workerTimezone);
        Carbon::setTestNow($workerNow);

        try {
            $this->seed(RbacSeeder::class);
            $actor = $this->makeRoleUser('support_worker');
            $this->grantPermissions($actor, [
                'medications.view',
                'medications.orders.manage',
            ]);
            $client = $this->makeScopedClient($actor);
            $medication = ClientMedication::factory()->create([
                'client_id' => $client->id,
                'name' => 'Future-effective dispensing medication',
                'controlled_drug' => false,
                'active' => true,
                'state' => 'active',
                'approval_status' => 'verified',
            ]);
            $order = MedicationPrescriberOrder::query()->create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'controlled_drug_snapshot' => false,
                'order_type' => 'new',
                'status' => 'confirmed',
                'prescriber_name' => 'Dr Dispensing Chronology',
                'medication_name' => $medication->name,
                'dose' => '1 tablet',
                'route' => 'Oral',
                'frequency' => 'Once daily',
                'order_date' => '2026-08-20',
                'effective_date' => '2026-09-04',
                'expiry_date' => '2026-09-28',
                'received_by' => $actor->id,
            ]);
            $orderBefore = $order->fresh()->getRawOriginal();
            $auditsBefore = AuditLog::query()
                ->where('auditable_type', $order->getMorphClass())
                ->where('auditable_id', $order->id)
                ->orderBy('id')
                ->get()
                ->map(fn (AuditLog $audit): array => $audit->getRawOriginal())
                ->all();

            $this->actingAs($actor)
                ->post(route('emar.prescriptions.dispense', $order), [
                    'dispensed_at' => '2026-08-19',
                    'pharmacy_name' => 'Chronology Pharmacy',
                ])
                ->assertSessionHasErrors([
                    'dispensed_at' => 'The dispensing date cannot be before the order date.',
                ]);

            $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
            $this->assertSame(
                $auditsBefore,
                AuditLog::query()
                    ->where('auditable_type', $order->getMorphClass())
                    ->where('auditable_id', $order->id)
                    ->orderBy('id')
                    ->get()
                    ->map(fn (AuditLog $audit): array => $audit->getRawOriginal())
                    ->all(),
            );

            $this->actingAs($actor)
                ->post(route('emar.prescriptions.dispense', $order), [
                    'dispensed_at' => '2026-08-20',
                    'pharmacy_name' => 'Chronology Pharmacy',
                ])
                ->assertSessionHasNoErrors();

            $dispensedOrder = $order->fresh();
            $this->assertSame('dispensed', $dispensedOrder->status);
            $this->assertSame($actor->id, (int) $dispensedOrder->dispensed_by);
            $this->assertSame('2026-08-20', $dispensedOrder->order_date?->toDateString());
            $this->assertSame('2026-09-04', $dispensedOrder->effective_date?->toDateString());
            $this->assertSame(
                '2026-08-20',
                $dispensedOrder->dispensed_at?->setTimezone($workerTimezone)->toDateString(),
            );
            $dispenseAudit = AuditLog::query()
                ->where('action', 'medications.prescriber_order.dispensed')
                ->where('auditable_type', $order->getMorphClass())
                ->where('auditable_id', $order->id)
                ->sole();
            $this->assertSame($actor->id, (int) $dispenseAudit->user_id);
            $this->assertSame('confirmed', $dispenseAudit->meta['status_before']);
            $this->assertSame('dispensed', $dispenseAudit->meta['status_after']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invalid_pre_existing_chronology_blocks_transitions_without_mutation_or_audit(): void
    {
        $workerNow = Carbon::parse('2026-08-28 08:00:00', 'Pacific/Auckland');
        Carbon::setTestNow($workerNow);

        try {
            $this->seed(RbacSeeder::class);
            $actor = $this->makeRoleUser('support_worker');
            $this->grantPermissions($actor, [
                'medications.view',
                'medications.orders.manage',
                'medications.orders.verify',
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
                MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            ]);
            $client = $this->makeScopedClient($actor);
            $recorder = $this->makeRoleUser('support_worker');
            $this->assignUserToClient($recorder, $client);
            $witness = $this->makeRoleUser('support_worker');
            $this->assignUserToClient($witness, $client);
            $medication = ClientMedication::factory()->create([
                'client_id' => $client->id,
                'name' => 'Chronology transition medication',
                'controlled_drug' => false,
                'active' => true,
                'state' => 'active',
                'approval_status' => 'verified',
            ]);
            $makeOrder = fn (string $name, array $attributes = []): MedicationPrescriberOrder => MedicationPrescriberOrder::query()->create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'controlled_drug_snapshot' => false,
                'order_type' => 'new',
                'status' => 'pending',
                'prescriber_name' => 'Dr Chronology',
                'medication_name' => $name,
                'dose' => '1 tablet',
                'route' => 'Oral',
                'frequency' => 'Once daily',
                'order_date' => '2026-08-28',
                'received_by' => $recorder->id,
                ...$attributes,
            ]);
            $confirmOrder = $makeOrder('Invalid confirmation chronology', [
                'order_date' => '2026-08-30',
                'expiry_date' => '2026-08-29',
            ]);
            $countersignOrder = $makeOrder('Invalid countersign chronology', [
                'order_type' => 'verbal',
                'order_date' => '2026-08-30',
                'effective_date' => '2026-08-29',
                'expiry_date' => '2026-08-31',
                'requires_countersign' => true,
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $witness->id,
                'read_back_verified_at' => $workerNow,
                'read_back_verification_method' => MedicationPrescriberOrder::READ_BACK_VERIFICATION_METHOD_PASSWORD,
            ]);
            $dispenseOrder = $makeOrder('Invalid dispensing chronology', [
                'status' => 'confirmed',
                'effective_date' => '2026-08-30',
                'expiry_date' => '2026-08-29',
            ]);
            $futureEffectiveOrder = $makeOrder('Valid future effective transition', [
                'effective_date' => '2026-09-04',
                'expiry_date' => '2026-09-28',
            ]);
            $chronologyError = 'This prescriber order has invalid date chronology and cannot be actioned.';

            $this->actingAs($actor)
                ->post(route('emar.prescriptions.confirm', $confirmOrder))
                ->assertSessionHasErrors(['confirm' => $chronologyError]);
            $this->actingAs($actor)
                ->post(route('emar.prescriptions.countersign', $countersignOrder), [
                    'countersign_method' => 'electronic',
                    'prescriber_declaration' => true,
                ])
                ->assertSessionHasErrors(['countersign' => $chronologyError]);
            $this->actingAs($actor)
                ->post(route('emar.prescriptions.dispense', $dispenseOrder), [
                    'dispensed_at' => $workerNow->toDateString(),
                    'pharmacy_name' => 'Chronology Pharmacy',
                ])
                ->assertSessionHasErrors(['dispense' => $chronologyError]);

            $this->assertSame('pending', $confirmOrder->fresh()->status);
            $this->assertSame('pending', $countersignOrder->fresh()->status);
            $this->assertNull($countersignOrder->fresh()->countersigned_at);
            $this->assertNull($countersignOrder->fresh()->countersigned_by);
            $this->assertNull($countersignOrder->fresh()->countersign_method);
            $this->assertSame('confirmed', $dispenseOrder->fresh()->status);
            $this->assertNull($dispenseOrder->fresh()->dispensed_at);
            $this->assertNull($dispenseOrder->fresh()->dispensed_by);
            $this->assertNull($dispenseOrder->fresh()->pharmacy_name);
            $this->assertSame(0, AuditLog::query()
                ->whereIn('action', [
                    'medications.prescriber_order.confirmed',
                    'medications.prescriber_order.countersigned',
                    'medications.prescriber_order.dispensed',
                ])
                ->whereIn('auditable_id', [
                    $confirmOrder->id,
                    $countersignOrder->id,
                    $dispenseOrder->id,
                ])
                ->count());

            $this->actingAs($actor)
                ->post(route('emar.prescriptions.confirm', $futureEffectiveOrder))
                ->assertSessionHasNoErrors();
            $this->assertSame('confirmed', $futureEffectiveOrder->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expired_orders_are_read_only_across_every_transition_and_expiry_day_is_inclusive(): void
    {
        $workerNow = Carbon::parse('2026-08-28 08:00:00', 'Pacific/Auckland');
        Carbon::setTestNow($workerNow);

        try {
            $this->seed(RbacSeeder::class);
            $actor = $this->makeRoleUser('support_worker');
            $this->grantPermissions($actor, [
                'medications.view',
                'medications.orders.manage',
                'medications.orders.verify',
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
                MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            ]);
            $client = $this->makeScopedClient($actor);
            $recorder = $this->makeRoleUser('support_worker');
            $this->assignUserToClient($recorder, $client);
            $witness = $this->makeRoleUser('support_worker');
            $this->assignUserToClient($witness, $client);
            $medication = ClientMedication::factory()->create([
                'client_id' => $client->id,
                'name' => 'Expiry boundary medication',
                'controlled_drug' => false,
                'active' => true,
                'state' => 'active',
                'approval_status' => 'verified',
            ]);
            $expiredDate = $workerNow->copy()->subDay()->toDateString();
            $makeOrder = fn (string $name, array $attributes = []): MedicationPrescriberOrder => MedicationPrescriberOrder::query()->create([
                'client_id' => $client->id,
                'order_type' => 'new',
                'status' => 'pending',
                'prescriber_name' => 'Dr Expiry',
                'medication_name' => $name,
                'controlled_drug_snapshot' => false,
                'dose' => '1 tablet',
                'route' => 'Oral',
                'frequency' => 'Once daily',
                'order_date' => '2026-08-01',
                'expiry_date' => $expiredDate,
                'received_by' => $recorder->id,
                ...$attributes,
            ]);

            $updateOrder = $makeOrder('Expired update order');
            $linkOrder = $makeOrder('Expired link order');
            $confirmOrder = $makeOrder('Expired confirm order', [
                'client_medication_id' => $medication->id,
            ]);
            $countersignOrder = $makeOrder('Expired countersign order', [
                'order_type' => 'verbal',
                'requires_countersign' => true,
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $witness->id,
                'read_back_verified_at' => $workerNow,
                'read_back_verification_method' => MedicationPrescriberOrder::READ_BACK_VERIFICATION_METHOD_PASSWORD,
            ]);
            $dispenseOrder = $makeOrder('Expired dispense order', [
                'client_medication_id' => $medication->id,
                'status' => 'confirmed',
            ]);
            $cancelOrder = $makeOrder('Expired cancel order');
            $inclusiveOrder = $makeOrder('Inclusive expiry-day order', [
                'client_medication_id' => $medication->id,
                'expiry_date' => $workerNow->toDateString(),
            ]);
            $cancelledTerminalOrder = $makeOrder('Expired-date cancelled order', [
                'status' => 'cancelled',
            ]);
            $dispensedTerminalOrder = $makeOrder('Expired-date dispensed order', [
                'status' => 'dispensed',
                'dispensed_by' => $actor->id,
                'dispensed_at' => $workerNow->copy()->subDay(),
            ]);
            $confirmedCeaseTerminalOrder = $makeOrder('Expired-date confirmed cease order', [
                'client_medication_id' => $medication->id,
                'order_type' => 'cease',
                'status' => 'confirmed',
            ]);

            $this->actingAs($actor)
                ->put(route('emar.prescriptions.update', $updateOrder), [
                    'clinical_notes' => 'Must not persist.',
                ])
                ->assertSessionHasErrors('update');
            $this->actingAs($actor)
                ->put(route('emar.prescriptions.update', $linkOrder), [
                    'client_medication_id' => $medication->id,
                ])
                ->assertSessionHasErrors('update');
            $this->actingAs($actor)
                ->post(route('emar.prescriptions.confirm', $confirmOrder))
                ->assertSessionHasErrors('confirm');
            $this->actingAs($actor)
                ->post(route('emar.prescriptions.countersign', $countersignOrder), [
                    'countersign_method' => 'electronic',
                    'prescriber_declaration' => true,
                ])
                ->assertSessionHasErrors('countersign');
            $this->actingAs($actor)
                ->post(route('emar.prescriptions.dispense', $dispenseOrder), [
                    'dispensed_at' => $workerNow->toDateString(),
                    'pharmacy_name' => 'Expiry Pharmacy',
                ])
                ->assertSessionHasErrors('dispense');
            $this->actingAs($actor)
                ->post(route('emar.prescriptions.cancel', $cancelOrder), [
                    'reason' => 'Must not cancel an expired order.',
                ])
                ->assertSessionHasErrors('cancel');

            $expiredIds = collect([
                $updateOrder,
                $linkOrder,
                $confirmOrder,
                $countersignOrder,
                $dispenseOrder,
                $cancelOrder,
            ])->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $terminalStatusById = collect([
                $cancelledTerminalOrder->id => 'cancelled',
                $dispensedTerminalOrder->id => 'dispensed',
                $confirmedCeaseTerminalOrder->id => 'confirmed',
            ]);
            $this->actingAs($actor)
                ->get(route('emar.prescriptions'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('orders', function ($orders) use ($expiredIds, $inclusiveOrder, $terminalStatusById): bool {
                        $rows = collect($orders);
                        $expiredRows = $rows->whereIn('id', $expiredIds);
                        $inclusive = $rows->firstWhere('id', $inclusiveOrder->id);
                        $terminalRowsArePreserved = $terminalStatusById->every(
                            function (string $expectedStatus, int $orderId) use ($rows): bool {
                                $row = $rows->firstWhere('id', $orderId);

                                return $row !== null
                                    && $row['expired'] === true
                                    && $row['status'] === $expectedStatus
                                    && $row['is_open_lifecycle'] === false;
                            },
                        );

                        return $expiredRows->count() === count($expiredIds)
                            && $expiredRows->every(fn (array $row): bool => $row['expired'] === true
                                && $row['status'] === 'expired'
                                && $row['is_open_lifecycle'] === false
                                && $row['can_confirm'] === false
                                && $row['can_countersign'] === false
                                && $row['can_dispense'] === false
                                && $row['can_link'] === false
                                && $row['can_cancel'] === false)
                            && $terminalRowsArePreserved
                            && $inclusive !== null
                            && $inclusive['expired'] === false
                            && $inclusive['status'] === 'pending'
                            && $inclusive['is_open_lifecycle'] === true
                            && $inclusive['can_confirm'] === true;
                    }));

            $this->assertNull($updateOrder->fresh()->clinical_notes);
            $this->assertNull($linkOrder->fresh()->client_medication_id);
            $this->assertSame('pending', $confirmOrder->fresh()->status);
            $this->assertSame('pending', $countersignOrder->fresh()->status);
            $this->assertSame('confirmed', $dispenseOrder->fresh()->status);
            $this->assertNull($dispenseOrder->fresh()->dispensed_at);
            $this->assertSame('pending', $cancelOrder->fresh()->status);

            $this->actingAs($actor)
                ->post(route('emar.prescriptions.confirm', $inclusiveOrder))
                ->assertSessionHasNoErrors();
            $this->assertSame('confirmed', $inclusiveOrder->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_read_back_witness_failures_are_rate_limited_and_security_logged_without_secrets(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->makeRoleUser('support_worker');
        $this->grantPermissions($actor, [
            'medications.view',
            'medications.orders.manage',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);
        $client = $this->makeScopedClient($actor);
        $witness = $this->makeRoleUser('support_worker');
        $witness->forceFill(['password' => Hash::make('correct-read-back-secret')])->save();
        $this->assignUserToClient($witness, $client);
        $rateLimitKey = implode(':', [
            'medications',
            'prescriber-order-read-back',
            $actor->id,
            $witness->id,
            $client->site_id,
        ]);
        RateLimiter::clear($rateLimitKey);
        Log::spy();
        $payload = [
            'client_id' => $client->id,
            'order_type' => 'verbal',
            'prescriber_name' => 'Dr Rate Limit',
            'medication_name' => 'Rate limited verbal order',
            'controlled_drug_snapshot' => false,
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today()->toDateString(),
            'read_back_confirmed' => true,
            'read_back_witnessed_by' => $witness->id,
            'read_back_witness_credential' => 'never-log-this-secret',
        ];
        $genericFailure = 'The witness credential could not be verified.';

        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->actingAs($actor)
                    ->post(route('emar.prescriptions.store'), $payload)
                    ->assertSessionHasErrors([
                        'read_back_witness_credential' => $genericFailure,
                    ]);
            }
            $this->actingAs($actor)
                ->post(route('emar.prescriptions.store'), [
                    ...$payload,
                    'read_back_witness_credential' => 'a-different-secret',
                ])
                ->assertSessionHasErrors([
                    'read_back_witness_credential' => $genericFailure,
                ]);

            $this->assertSame(5, RateLimiter::attempts($rateLimitKey));
            $this->assertDatabaseMissing('medication_prescriber_orders', [
                'medication_name' => 'Rate limited verbal order',
            ]);
            Log::shouldHaveReceived('warning')
                ->withArgs(function (string $message, array $context) use ($actor, $witness, $client): bool {
                    return $message === 'Medication prescriber-order read-back witness credential rejected.'
                        && $context['security_event'] === 'medication_prescriber_order_read_back_credential_rejected'
                        && $context['outcome'] === 'mismatch'
                        && $context['actor_id'] === $actor->id
                        && $context['witness_id'] === $witness->id
                        && $context['client_id'] === $client->id
                        && $context['site_id'] === $client->site_id
                        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'never-log-this-secret');
                })
                ->times(5);
            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message, array $context): bool => $message === 'Medication prescriber-order read-back witness credential rejected.'
                    && $context['outcome'] === 'throttled'
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'a-different-secret'))
                ->once();
        } finally {
            RateLimiter::clear($rateLimitKey);
        }
    }

    public function test_page_mutation_flags_require_current_work_scope_and_emit_staff_site_membership(): void
    {
        $workerNow = Carbon::parse('2026-08-28 00:30:00', 'Pacific/Auckland');
        Carbon::setTestNow($workerNow);

        try {
            $this->seed(RbacSeeder::class);
            $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
            $hiddenSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
            $actor = $this->makeRoleUser('support_worker');
            $this->grantPermissions($actor, [
                'medications.view',
                'medications.orders.manage',
                'medications.orders.verify',
                MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
                MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
            ]);
            HrEmployeeProfile::factory()->create([
                'user_id' => $actor->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'start_date' => '2026-08-28',
                'end_date' => '2026-08-28',
                'is_active' => true,
            ]);
            $shiftClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
            $breakGlassClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
            $recorder = $this->makeRoleUser('support_worker');
            HrEmployeeProfile::factory()->create([
                'user_id' => $recorder->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'start_date' => '2026-08-28',
                'end_date' => null,
                'is_active' => true,
            ]);
            $witness = $this->makeRoleUser('support_worker');
            HrEmployeeProfile::factory()->create([
                'user_id' => $witness->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [$hiddenSite->id],
                'start_date' => '2026-08-28',
                'end_date' => '2026-08-28',
                'is_active' => true,
            ]);
            $medications = collect([$shiftClient, $breakGlassClient])->mapWithKeys(
                fn (Client $client): array => [$client->id => ClientMedication::factory()->create([
                    'client_id' => $client->id,
                    'name' => 'Scoped medication '.$client->id,
                    'controlled_drug' => false,
                    'active' => true,
                    'state' => 'active',
                    'approval_status' => 'verified',
                ])],
            );
            $orders = collect([$shiftClient, $breakGlassClient])->mapWithKeys(
                fn (Client $client): array => [$client->id => MedicationPrescriberOrder::query()->create([
                    'client_id' => $client->id,
                    'controlled_drug_snapshot' => false,
                    'order_type' => 'new',
                    'status' => 'pending',
                    'prescriber_name' => 'Dr Work Scope',
                    'medication_name' => 'Scoped order '.$client->id,
                    'dose' => '1 tablet',
                    'route' => 'Oral',
                    'frequency' => 'Once daily',
                    'order_date' => '2026-08-28',
                    'received_by' => $recorder->id,
                ])],
            );

            $this->actingAs($actor)
                ->get(route('emar.prescriptions'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('can_create_manual_order', false)
                    ->where('clients', function ($clients) use ($shiftClient, $breakGlassClient, $site): bool {
                        $rows = collect($clients)->keyBy(fn (array $client): int => (int) $client['id']);

                        return $rows->count() === 2
                            && $rows->has([$shiftClient->id, $breakGlassClient->id])
                            && (int) $rows->get($shiftClient->id)['site_id'] === (int) $site->id
                            && $rows->get($shiftClient->id)['can_create_prescriber_order'] === false
                            && (int) $rows->get($breakGlassClient->id)['site_id'] === (int) $site->id
                            && $rows->get($breakGlassClient->id)['can_create_prescriber_order'] === false;
                    })
                    ->where('staff', function ($staff) use ($witness, $site): bool {
                        $row = collect($staff)->firstWhere('id', $witness->id);

                        return $row !== null
                            && array_map('intval', $row['site_ids']) === [(int) $site->id];
                    })
                    ->where('orders', function ($rows) use ($orders): bool {
                        $matched = collect($rows)->whereIn('id', $orders->pluck('id'));

                        return $matched->count() === 2
                            && $matched->every(fn (array $row): bool => $row['can_confirm'] === false
                                && $row['can_countersign'] === false
                                && $row['can_dispense'] === false
                                && $row['can_link'] === false
                                && $row['can_cancel'] === false);
                    })
                    ->where('medications', function ($rows) use ($medications): bool {
                        $matched = collect($rows)->whereIn('id', $medications->pluck('id'));

                        return $matched->count() === 2
                            && $matched->every(fn (array $row): bool => $row['can_link_prescriber_order'] === false);
                    }));

            $shift = Shift::factory()->create([
                'client_id' => $shiftClient->id,
                'site_id' => $site->id,
                'user_id' => $actor->id,
                'starts_at' => $workerNow->copy()->subHour()->utc(),
                'ends_at' => $workerNow->copy()->addHours(2)->utc(),
                'actual_starts_at' => $workerNow->copy()->subHour()->utc(),
                'actual_ends_at' => null,
                'started_by' => $actor->id,
                'status' => 'in_progress',
            ]);
            $this->grantPermissions($actor, ['medications.breakglass']);
            $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
            $breakGlass = ClientBreakGlassAccess::query()->create([
                'client_id' => $breakGlassClient->id,
                'user_id' => $actor->id,
                'reason' => 'Immediate medication-order continuity required.',
                'reason_category' => 'urgent_care',
                'authorization_mode' => 'self',
                'acknowledged_min_necessary' => true,
                'acknowledged_incident_report' => true,
                'expires_at' => $workerNow->copy()->addMinutes(30)->utc(),
            ]);
            $breakGlass->forceFill([
                'created_at' => $workerNow->copy()->utc(),
                'updated_at' => $workerNow->copy()->utc(),
            ])->saveQuietly();

            $this->assertTrue($actor->canDo('medications.orders.manage'));
            $this->assertTrue($actor->canDo('medications.orders.verify'));
            $this->assertTrue($actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY));
            $this->assertTrue($actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY));
            $this->assertTrue($actor->canDo('medications.breakglass'));
            $this->assertSame(
                $workerNow->copy()->subHour()->utc()->format('Y-m-d H:i:s'),
                $shift->fresh()->getRawOriginal('actual_starts_at'),
            );
            $this->assertSame(
                $workerNow->copy()->utc()->format('Y-m-d H:i:s'),
                $breakGlass->fresh()->getRawOriginal('created_at'),
            );
            $this->assertSame(
                $workerNow->copy()->addMinutes(30)->utc()->format('Y-m-d H:i:s'),
                $breakGlass->fresh()->getRawOriginal('expires_at'),
            );
            $this->assertSame(
                collect([$shiftClient->id, $breakGlassClient->id])->sort()->values()->all(),
                app(MedicationScopeDecisionService::class)->clientIdsWithCurrentAuthority(
                    $actor,
                    [$shiftClient->id, $breakGlassClient->id],
                    now(),
                ),
            );

            $this->actingAs($actor)
                ->get(route('emar.prescriptions'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('can_create_manual_order', true)
                    ->where('clients', function ($clients) use ($shiftClient, $breakGlassClient): bool {
                        $rows = collect($clients)->keyBy(fn (array $client): int => (int) $client['id']);

                        return $rows->count() === 2
                            && $rows->has([$shiftClient->id, $breakGlassClient->id])
                            && $rows->get($shiftClient->id)['can_create_prescriber_order'] === true
                            && $rows->get($breakGlassClient->id)['can_create_prescriber_order'] === true;
                    })
                    ->where('orders', fn ($rows): bool => collect($rows)
                        ->whereIn('id', $orders->pluck('id'))
                        ->count() === 2
                        && collect($rows)
                            ->whereIn('id', $orders->pluck('id'))
                            ->every(fn (array $row): bool => $row['can_confirm'] === true
                                && $row['can_cancel'] === true))
                    ->where('medications', fn ($rows): bool => collect($rows)
                        ->whereIn('id', $medications->pluck('id'))
                        ->count() === 2
                        && collect($rows)
                            ->whereIn('id', $medications->pluck('id'))
                            ->every(fn (array $row): bool => $row['can_link_prescriber_order'] === true)));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_active_covert_authorisations_can_be_revoked_after_canonical_medication_supersession_or_soft_deletion(): void
    {
        $this->seed(RbacSeeder::class);
        $actor = $this->makeRoleUser('support_worker');
        $this->grantPermissions($actor, [
            'medications.view',
            'medications.orders.manage',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);
        $client = $this->makeScopedClient($actor);
        $replacement = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Replacement controlled medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $superseded = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Superseded controlled medication',
            'controlled_drug' => true,
            'active' => false,
            'state' => 'discontinued',
            'approval_status' => 'verified',
        ]);
        $deleted = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Soft-deleted controlled medication',
            'controlled_drug' => true,
            'active' => false,
            'state' => 'discontinued',
            'approval_status' => 'verified',
        ]);
        $makeAuthorisation = fn (ClientMedication $medication): MedicationCovertAuthorisation => MedicationCovertAuthorisation::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'authorised_by_name' => 'Dr Historical',
            'clinical_justification' => 'Historical best-interest authorisation.',
            'authorised_date' => today()->subWeek(),
            'review_date' => today()->addWeek(),
            'status' => 'active',
            'recorded_by' => $actor->id,
        ]);
        $supersededAuthorisation = $makeAuthorisation($superseded);
        $deletedAuthorisation = $makeAuthorisation($deleted);
        $superseded->forceFill(['superseded_by' => $replacement->id])->saveQuietly();
        $deleted->forceFill(['deleted_at' => now()])->saveQuietly();

        $this->denyPermissions($actor, [MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY]);
        $this->grantPermissions($actor, [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY]);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($actor)
            ->post(route('emar.covert.revoke', $supersededAuthorisation), [
                'reason' => 'Must remain concealed without controlled view.',
            ])
            ->assertNotFound();
        $this->assertSame('active', $supersededAuthorisation->fresh()->status);

        $this->grantPermissions($actor, [MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY]);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($actor)
            ->get(route('emar.prescriptions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('covert', function ($rows) use ($supersededAuthorisation, $deletedAuthorisation): bool {
                    $byId = collect($rows)->keyBy(fn (array $row): int => (int) $row['id']);

                    return $byId->get($supersededAuthorisation->id)['medication_name'] === 'Superseded controlled medication'
                        && $byId->get($supersededAuthorisation->id)['can_revoke'] === true
                        && $byId->get($deletedAuthorisation->id)['medication_name'] === 'Soft-deleted controlled medication'
                        && $byId->get($deletedAuthorisation->id)['can_revoke'] === true;
                }));

        foreach ([
            $supersededAuthorisation->id => 'Superseded historical authorisation closed.',
            $deletedAuthorisation->id => 'Soft-deleted historical authorisation closed.',
        ] as $authorisationId => $reason) {
            $this->actingAs($actor)
                ->post(route('emar.covert.revoke', $authorisationId), ['reason' => $reason])
                ->assertSessionHasNoErrors();
            $this->assertDatabaseHas('medication_covert_authorisations', [
                'id' => $authorisationId,
                'status' => 'revoked',
            ]);
            $audit = AuditLog::query()
                ->where('action', 'medications.covert_authorisation.revoked')
                ->where('auditable_id', $authorisationId)
                ->sole();
            $this->assertSame($reason, $audit->meta['reason']);
        }
    }

    public function test_controlled_and_unknown_orders_are_concealed_and_classification_is_server_bound(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('support_worker');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $this->denyPermissions($user, [
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
            MedicationGovernanceScopeService::CONTROLLED_CAPABILITY,
        ]);
        $client = $this->makeScopedClient($user);
        $recorder = $this->makeRoleUser('support_worker');
        $this->assignUserToClient($recorder, $client);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Ordinary linked order medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Restricted linked order medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $client->site_id,
            'status' => 'active',
        ]);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'name' => 'FORGED foreign linked order medication',
            'controlled_drug' => false,
        ]);
        $createOrder = fn (array $attributes): MedicationPrescriberOrder => MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Classification',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
            'received_by' => $recorder->id,
            ...$attributes,
        ]);
        $ordinaryOrder = $createOrder([
            'client_medication_id' => $ordinaryMedication->id,
            'controlled_drug_snapshot' => false,
            'medication_name' => 'Visible ordinary linked order',
        ]);
        $controlledOrder = $createOrder([
            'client_medication_id' => $controlledMedication->id,
            'controlled_drug_snapshot' => true,
            'medication_name' => 'Restricted controlled linked order',
        ]);
        $ordinaryUnlinked = $createOrder([
            'controlled_drug_snapshot' => false,
            'medication_name' => 'Visible ordinary unlinked order',
        ]);
        $createOrder([
            'controlled_drug_snapshot' => true,
            'medication_name' => 'Restricted controlled unlinked order',
        ]);
        $unknownOrder = $createOrder([
            'controlled_drug_snapshot' => null,
            'medication_name' => 'Restricted legacy unknown order',
        ]);
        $forgedOrder = $createOrder([
            'client_medication_id' => $foreignMedication->id,
            'controlled_drug_snapshot' => false,
            'medication_name' => 'FORGED cross-client order',
        ]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignSiteClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'status' => 'active',
        ]);
        $foreignSiteMedication = ClientMedication::factory()->create([
            'client_id' => $foreignSiteClient->id,
            'name' => 'FORGED foreign Site medication',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $foreignSiteOrder = MedicationPrescriberOrder::query()->create([
            'client_id' => $foreignSiteClient->id,
            'client_medication_id' => $foreignSiteMedication->id,
            'controlled_drug_snapshot' => false,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Foreign Site',
            'medication_name' => 'FORGED foreign Site order',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
        ]);
        $createCovert = fn (array $attributes): MedicationCovertAuthorisation => MedicationCovertAuthorisation::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'authorised_by_name' => 'Dr Covert',
            'clinical_justification' => 'Clinically required',
            'authorised_date' => today(),
            'review_date' => today()->addMonth(),
            'status' => 'active',
            'recorded_by' => $user->id,
            ...$attributes,
        ]);
        $ordinaryCovert = $createCovert([]);
        $controlledCovert = $createCovert([
            'client_medication_id' => $controlledMedication->id,
            'clinical_justification' => 'RESTRICTED controlled covert authorisation',
        ]);
        $forgedCovert = $createCovert([
            'client_medication_id' => $foreignMedication->id,
            'clinical_justification' => 'FORGED cross-client covert authorisation',
        ]);
        $foreignSiteCovert = MedicationCovertAuthorisation::query()->create([
            'client_id' => $foreignSiteClient->id,
            'client_medication_id' => $foreignSiteMedication->id,
            'authorised_by_name' => 'Dr Foreign Site',
            'clinical_justification' => 'FORGED foreign Site covert authorisation',
            'authorised_date' => today(),
            'review_date' => today()->addMonth(),
            'status' => 'active',
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('emar.prescriptions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders', function ($orders): bool {
                    $names = collect($orders)->pluck('medication_name');

                    return $names->contains('Visible ordinary linked order')
                        && $names->contains('Visible ordinary unlinked order')
                        && $names->doesntContain('Restricted controlled linked order')
                        && $names->doesntContain('Restricted controlled unlinked order')
                        && $names->doesntContain('Restricted legacy unknown order')
                        && $names->doesntContain('FORGED cross-client order')
                        && $names->doesntContain('FORGED foreign Site order');
                })
                ->where('medications', function ($medications) use ($ordinaryMedication, $foreignMedication): bool {
                    $actual = collect($medications)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
                    $expected = collect([$ordinaryMedication->id, $foreignMedication->id])->map(fn ($id) => (int) $id)->sort()->values();

                    return $actual->all() === $expected->all();
                }));

        $this->actingAs($user)
            ->get(route('emar.prescriptions'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('covert', function ($authorisations) use ($ordinaryCovert): bool {
                    $ids = collect($authorisations)->pluck('id');

                    return $ids->all() === [$ordinaryCovert->id];
                }));

        $basePayload = [
            'client_id' => $client->id,
            'order_type' => 'new',
            'prescriber_name' => 'Dr Classification',
            'medication_name' => 'New classified order',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today()->toDateString(),
        ];
        $this->actingAs($user)
            ->post(route('emar.prescriptions.store'), $basePayload)
            ->assertSessionHasErrors('controlled_drug_snapshot');
        $this->actingAs($user)
            ->post(route('emar.prescriptions.store'), [
                ...$basePayload,
                'medication_name' => 'Blocked unlinked controlled create',
                'controlled_drug_snapshot' => true,
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.prescriptions.store'), [
                ...$basePayload,
                'client_medication_id' => $controlledMedication->id,
                'controlled_drug_snapshot' => false,
                'medication_name' => 'Blocked linked controlled create',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$controlledOrder->id.'/confirm')
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$controlledOrder->id.'/dispense', [
                'pharmacy_name' => 'Concealed Pharmacy',
                'dispensed_at' => now(config('app.worker_timezone') ?: config('app.timezone', 'UTC'))->toDateString(),
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$unknownOrder->id.'/cancel', [
                'reason' => 'Must be concealed before cancellation validation.',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$forgedOrder->id.'/confirm')
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$foreignSiteOrder->id.'/cancel', [
                'reason' => 'Must be concealed before cancellation validation.',
            ])
            ->assertNotFound();
        $covertPayload = [
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'authorised_by_name' => 'Dr Covert',
            'clinical_justification' => 'Canonical covert authorisation',
            'authorised_date' => today()->toDateString(),
            'review_date' => today()->addMonth()->toDateString(),
        ];
        $this->actingAs($user)
            ->post(route('emar.covert.store'), $covertPayload)
            ->assertRedirect()
            ->assertSessionHasErrors('clinical_justification');
        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$covertPayload,
                'client_medication_id' => $controlledMedication->id,
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$covertPayload,
                'client_medication_id' => $foreignMedication->id,
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => 'Concealed controlled authorisation.',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $forgedCovert), [
                'reason' => 'Concealed cross-client authorisation.',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $foreignSiteCovert), [
                'reason' => 'Concealed foreign-Site authorisation.',
            ])
            ->assertNotFound();
        $this->assertSame('active', $controlledCovert->fresh()->status);
        $this->assertSame('active', $forgedCovert->fresh()->status);
        $this->assertSame('active', $foreignSiteCovert->fresh()->status);

        $this->grantPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$covertPayload,
                'client_medication_id' => $controlledMedication->id,
                'clinical_justification' => 'Must remain blocked from a record-only actor',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => 'Still concealed without controlled view.',
            ])
            ->assertNotFound();
        $this->assertSame('active', $controlledCovert->fresh()->status);
        $this->assertDatabaseMissing('medication_covert_authorisations', [
            'clinical_justification' => 'Must remain blocked from a record-only actor',
        ]);

        $this->denyPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY]);
        $this->grantPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->get(route('emar.prescriptions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders', function ($orders): bool {
                    $names = collect($orders)->pluck('medication_name');

                    return $names->contains('Restricted controlled linked order')
                        && $names->contains('Restricted controlled unlinked order')
                        && $names->contains('Restricted legacy unknown order')
                        && $names->doesntContain('FORGED cross-client order');
                })
                ->where('medications', function ($medications) use ($controlledMedication): bool {
                    $row = collect($medications)->firstWhere('id', $controlledMedication->id);

                    return $row !== null
                        && $row['can_create_covert_authorisation'] === false
                        && $row['can_link_prescriber_order'] === false;
                })
                ->where('covert', function ($authorisations) use ($ordinaryCovert, $controlledCovert, $forgedCovert, $foreignSiteCovert): bool {
                    $ids = collect($authorisations)->pluck('id');

                    return $ids->contains($ordinaryCovert->id)
                        && $ids->contains($controlledCovert->id)
                        && $ids->doesntContain($forgedCovert->id)
                        && $ids->doesntContain($foreignSiteCovert->id);
                }));

        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$covertPayload,
                'client_medication_id' => $controlledMedication->id,
                'clinical_justification' => 'Must remain blocked without controlled record authority',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => 'Still concealed without controlled record.',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.prescriptions.store'), [
                ...$basePayload,
                'client_medication_id' => $controlledMedication->id,
                'controlled_drug_snapshot' => false,
                'medication_name' => 'Blocked view-only controlled order',
            ])
            ->assertNotFound();
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$controlledOrder->id.'/confirm')
            ->assertNotFound();
        $this->assertSame('active', $controlledCovert->fresh()->status);
        $this->assertSame('pending', $controlledOrder->fresh()->status);
        $this->assertDatabaseMissing('medication_prescriber_orders', [
            'medication_name' => 'Blocked view-only controlled order',
        ]);

        $this->grantPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->get(route('emar.prescriptions'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('medications', function ($medications) use ($controlledMedication): bool {
                    $row = collect($medications)->firstWhere('id', $controlledMedication->id);

                    return $row !== null
                        && $row['can_create_covert_authorisation'] === true
                        && $row['can_link_prescriber_order'] === true;
                })
                ->where('orders', function ($orders) use ($controlledOrder): bool {
                    $row = collect($orders)->firstWhere('id', $controlledOrder->id);

                    return $row !== null
                        && $row['can_confirm'] === true
                        && $row['can_cancel'] === true;
                }));
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert))
            ->assertSessionHasErrors('reason');
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => str_repeat('x', 501),
            ])
            ->assertSessionHasErrors('reason');
        $this->assertSame('active', $controlledCovert->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.covert_authorisation.revoked',
            'auditable_id' => $controlledCovert->id,
        ]);
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => '  Best-interest decision superseded.  ',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('revoked', $controlledCovert->fresh()->status);
        $revokeAudit = AuditLog::query()
            ->where('action', 'medications.covert_authorisation.revoked')
            ->where('auditable_id', $controlledCovert->id)
            ->sole();
        $this->assertSame($user->id, (int) $revokeAudit->user_id);
        $this->assertSame('active', $revokeAudit->meta['status_before']);
        $this->assertSame('revoked', $revokeAudit->meta['status_after']);
        $this->assertSame('Best-interest decision superseded.', $revokeAudit->meta['reason']);
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => 'Attempted duplicate revocation.',
            ])
            ->assertSessionHasErrors('authorisation');
        $this->assertSame('revoked', $controlledCovert->fresh()->status);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medications.covert_authorisation.revoked')
            ->where('auditable_id', $controlledCovert->id)
            ->count());
        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$covertPayload,
                'client_medication_id' => $controlledMedication->id,
                'clinical_justification' => 'Permitted controlled covert authorisation',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('emar.prescriptions.store'), [
                ...$basePayload,
                'client_medication_id' => $controlledMedication->id,
                'controlled_drug_snapshot' => false,
                'medication_name' => 'Server-derived controlled order',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) MedicationPrescriberOrder::query()
            ->where('medication_name', 'Server-derived controlled order')
            ->value('controlled_drug_snapshot'));

        $this->actingAs($user)
            ->put(route('emar.prescriptions.update', $ordinaryUnlinked), [
                'client_medication_id' => $controlledMedication->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('client_medication_id');
        $this->actingAs($user)
            ->put(route('emar.prescriptions.update', $unknownOrder), [
                'client_medication_id' => $controlledMedication->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame($controlledMedication->id, (int) $unknownOrder->fresh()->client_medication_id);
        $this->assertTrue($unknownOrder->fresh()->controlled_drug_snapshot);
        $linkAudit = AuditLog::query()
            ->where('action', 'medications.prescriber_order.linked')
            ->where('auditable_id', $unknownOrder->id)
            ->sole();
        $this->assertSame($user->id, (int) $linkAudit->user_id);
        $this->assertSame($controlledMedication->id, (int) $linkAudit->meta['client_medication_id']);
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$unknownOrder->id.'/cancel', [
                'reason' => 'Controlled order replaced after classification.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $unknownOrder->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.prescriber_order.cancelled',
            'auditable_id' => $unknownOrder->id,
            'user_id' => $user->id,
        ]);

        // A governed mutation hydrates this shared test actor with its bounded
        // authorization snapshot. A real next request rehydrates the actor, so
        // clear that snapshot before exercising the distinct verify capability.
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$controlledOrder->id.'/confirm')
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $controlledOrder->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.prescriber_order.confirmed',
            'auditable_id' => $controlledOrder->id,
            'user_id' => $user->id,
        ]);
        $this->actingAs($user)
            ->get(route('emar.prescriptions'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders', function ($orders) use ($controlledOrder): bool {
                    $row = collect($orders)->firstWhere('id', $controlledOrder->id);

                    return $row !== null
                        && $row['can_dispense'] === true
                        && $row['can_cancel'] === false
                        && $row['can_link'] === false;
                }));
        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$controlledOrder->id.'/dispense', [
                'pharmacy_name' => 'Controlled Pharmacy',
                'dispensed_at' => now(config('app.worker_timezone') ?: config('app.timezone', 'UTC'))->toDateString(),
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('dispensed', $controlledOrder->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.prescriber_order.dispensed',
            'auditable_id' => $controlledOrder->id,
            'user_id' => $user->id,
        ]);
        $this->actingAs($user)
            ->put(route('emar.prescriptions.update', $ordinaryOrder), ['status' => 'confirmed'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame('pending', $ordinaryOrder->fresh()->status);
    }

    private function assertStrictAuditFailureRollsBack(string $action, callable $mutation): void
    {
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use ($action, &$injectFailure): void {
            if ($injectFailure && $audit->action === $action) {
                throw new RuntimeException('Injected strict audit failure for '.$action.'.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $mutation();
            $this->fail('The injected strict audit failure should escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected strict audit failure for '.$action.'.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => $action]);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    protected function makeScopedClient(User $user): Client
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['status' => 'active', 'site_id' => $site->id]);
        $this->assignUserToClient($user, $client);

        return $client;
    }

    protected function assignUserToClient(User $user, Client $client): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $client->site_id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $user->id,
            'status' => 'in_progress',
        ]);
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
