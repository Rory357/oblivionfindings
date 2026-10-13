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
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * The retained supply and dispensing reader serves the prescriber-order payload.
 * Governed chart entry and covert changes use the canonical Orders workflow.
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
        $med = ClientMedication::query()->create(['client_id' => $client->id, 'name' => 'Warfarin', 'dosage' => '3mg', 'frequency' => 'Once daily', 'controlled_drug' => false, 'active' => true, 'state' => 'active']);
        $this->markChecked($med, $user);
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
            ->get('/emar/prescriptions/legacy?site_id='.$site->id)
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
                // EA-053 (Lane C3): old prescriber-order records are read only.
                ->where('read_only', true)
                ->where('orders.0.can_confirm', false)
                ->where('orders.0.can_countersign', false)
                ->where('orders.0.can_dispense', false)
                ->where('orders.0.can_link', false)
                ->where('orders.0.can_cancel', false)
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
                ->where('can_create_manual_order', false)
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
        $this->markChecked($medication, $viewer);
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
            ->get(route('emar.prescriptions.legacy'))
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

    public function test_covert_authorisations_reject_future_dates_and_replace_active_records_without_parallel_authority(): void
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
        $this->markChecked($medication, $user);
        Storage::fake('local');
        $workerToday = now(config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
        $payload = $this->covertInput($client, $medication);

        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$payload,
                'authorised_date' => $workerToday->copy()->addDay()->toDateString(),
                'review_date' => $workerToday->copy()->addMonth()->toDateString(),
            ])
            ->assertSessionHasErrors('authorised_date');
        $this->actingAs($user)
            ->post(route('emar.covert.store'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $authorisation = MedicationCovertAuthorisation::where('client_medication_id', $medication->id)->sole();
        $originalEvidence = $authorisation->structured_evidence;
        $this->actingAs($user)
            ->post(route('emar.covert.store'), [
                ...$payload,
                'request_key' => 'covert-replacement',
                'capacity_record' => 'Updated documented capacity assessment.',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, MedicationCovertAuthorisation::query()
            ->where('client_medication_id', $medication->id)
            ->where('status', 'active')
            ->count());
        $this->assertSame(2, MedicationCovertAuthorisation::where('client_medication_id', $medication->id)->count());
        $this->assertSame('revoked', $authorisation->refresh()->status);
        $this->assertSame($originalEvidence, $authorisation->structured_evidence);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $audits = AuditLog::where('action', 'medication_order.covert_authorised')->where('auditable_id', $medication->id)->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame($user->id, (int) $audits->first()->user_id);
        $this->assertSame($authorisation->id, (int) $audits->first()->meta['authorisation_id']);
        $this->assertSame($client->id, (int) $audits->first()->meta['client_id']);
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
        $this->markChecked($medication, $actor);
        Storage::fake('local');
        $this->assertStrictAuditFailureRollsBack(
            'medication_order.covert_authorised',
            fn () => $this->actingAs($actor)
                ->post(route('emar.covert.store'), $this->covertInput($client, $medication, 'Must roll back with strict audit.')),
        );

        $this->assertDatabaseMissing('medication_covert_authorisations', [
            'client_medication_id' => $medication->id,
            'clinical_justification' => 'Must roll back with strict audit.',
        ]);
        $this->assertCount(0, Storage::disk('local')->allFiles());
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
            'medication_order.covert_revoked',
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

    public function test_page_mutation_flags_require_current_work_scope_and_emit_staff_site_membership(): void
    {
        $workerNow = Carbon::parse('2026-08-28 00:30:00', 'Pacific/Auckland');
        Carbon::setTestNow($workerNow->copy()->utc());

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
            // Person assignments permit reading; neither grants current work authority.
            $shiftClient->supportWorkers()->attach($actor->id);
            $breakGlassClient->supportWorkers()->attach($actor->id);
            $this->assertTrue($actor->can('viewMedications', $shiftClient));
            $this->assertTrue($actor->can('viewMedications', $breakGlassClient));
            $this->assertSame([], app(MedicationScopeDecisionService::class)->clientIdsWithCurrentAuthority(
                $actor,
                [$shiftClient->id, $breakGlassClient->id],
                now(),
            ));
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
                ->get(route('emar.prescriptions.legacy'))
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
                ->get(route('emar.prescriptions.legacy'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    // EA-053 (Lane C3): read only even with current work scope.
                    ->where('can_create_manual_order', false)
                    ->where('read_only', true)
                    ->where('clients', function ($clients) use ($shiftClient, $breakGlassClient): bool {
                        $rows = collect($clients)->keyBy(fn (array $client): int => (int) $client['id']);

                        return $rows->count() === 2
                            && $rows->has([$shiftClient->id, $breakGlassClient->id])
                            && $rows->get($shiftClient->id)['can_create_prescriber_order'] === false
                            && $rows->get($breakGlassClient->id)['can_create_prescriber_order'] === false;
                    })
                    ->where('orders', fn ($rows): bool => collect($rows)
                        ->whereIn('id', $orders->pluck('id'))
                        ->count() === 2
                        && collect($rows)
                            ->whereIn('id', $orders->pluck('id'))
                            ->every(fn (array $row): bool => $row['can_confirm'] === false
                                && $row['can_cancel'] === false))
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
            ->get(route('emar.prescriptions.legacy'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('covert', function ($rows) use ($supersededAuthorisation, $deletedAuthorisation): bool {
                    $byId = collect($rows)->keyBy(fn (array $row): int => (int) $row['id']);

                    return $byId->get($supersededAuthorisation->id)['medication_name'] === 'Superseded controlled medication'
                        && $byId->get($supersededAuthorisation->id)['can_revoke'] === true
                        && $byId->get($deletedAuthorisation->id)['medication_name'] === 'Soft-deleted controlled medication'
                        && $byId->get($deletedAuthorisation->id)['can_revoke'] === true;
                }));

        $retainedOrders = [$superseded->fresh()->getRawOriginal(), $deleted->fresh()->getRawOriginal()];
        foreach ([
            $supersededAuthorisation->id => 'Superseded historical authorisation closed.',
            $deletedAuthorisation->id => 'Soft-deleted historical authorisation closed.',
        ] as $authorisationId => $reason) {
            $this->actingAs($actor)
                ->post(route('emar.covert.revoke', $authorisationId), ['reason' => $reason])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('medication_covert_authorisations', [
                'id' => $authorisationId,
                'status' => 'revoked',
            ]);
            $audit = AuditLog::query()
                ->where('action', 'medication_order.covert_revoked')
                ->where('meta->authorisation_id', $authorisationId)
                ->sole();
            $this->assertSame($reason, $audit->meta['reason']);
        }
        $this->assertSame($retainedOrders, [$superseded->fresh()->getRawOriginal(), $deleted->fresh()->getRawOriginal()]);
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
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        // Same-Site membership and order permissions do not reveal an unassigned chart.
        $this->assertFalse($user->can('viewMedications', $foreignClient));
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
            ->get(route('emar.prescriptions.legacy'))
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
                ->where('clients', fn ($clients): bool => collect($clients)->pluck('id')->map(fn ($id) => (int) $id)->all() === [(int) $client->id])
                ->where('medications', function ($medications) use ($ordinaryMedication): bool {
                    $ids = collect($medications)->pluck('id')->map(fn ($id) => (int) $id);

                    return $ids->all() === [(int) $ordinaryMedication->id];
                }));

        $this->actingAs($user)
            ->get(route('emar.prescriptions.legacy', ['client_id' => $foreignClient->id]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('emar.prescriptions.legacy'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('covert', function ($authorisations) use ($ordinaryCovert): bool {
                    $ids = collect($authorisations)->pluck('id');

                    return $ids->all() === [$ordinaryCovert->id];
                }));

        // EA-053 (Lane C3): every old prescriber-order write is refused the same
        // way — before any lookup, so a concealed or foreign order isn't revealed.
        $before = MedicationPrescriberOrder::query()->count();
        foreach ([
            [route('emar.prescriptions.store'), ['client_id' => $client->id, 'medication_name' => 'Blocked create', 'controlled_drug_snapshot' => true]],
            ['/emar/prescriptions/'.$controlledOrder->id.'/confirm', []],
            ['/emar/prescriptions/'.$controlledOrder->id.'/dispense', ['pharmacy_name' => 'Concealed Pharmacy']],
            ['/emar/prescriptions/'.$unknownOrder->id.'/cancel', ['reason' => 'Refused before any lookup.']],
            ['/emar/prescriptions/'.$forgedOrder->id.'/confirm', []],
            ['/emar/prescriptions/'.$foreignSiteOrder->id.'/cancel', ['reason' => 'Refused before any lookup.']],
        ] as [$url, $payload]) {
            $this->actingAs($user)->postJson($url, $payload)->assertStatus(409)->assertJsonMissingPath('order');
        }
        $this->assertSame($before, MedicationPrescriberOrder::query()->count());
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
            ->assertStatus(303)
            ->assertRedirect('/emar/prescriptions?'.http_build_query(['client_id' => $client->id, 'order_id' => $ordinaryMedication->id, 'action' => 'covert']))
            ->assertSessionHas('info');
        $this->assertDatabaseMissing('medication_covert_authorisations', ['clinical_justification' => 'Canonical covert authorisation']);
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
            ->get(route('emar.prescriptions.legacy'))
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
        // EA-053: the old order writes are refused before any lookup.
        $this->actingAs($user)
            ->postJson(route('emar.prescriptions.store'), [
                'client_id' => $client->id,
                'client_medication_id' => $controlledMedication->id,
                'controlled_drug_snapshot' => false,
                'medication_name' => 'Blocked view-only controlled order',
            ])
            ->assertStatus(409)->assertJsonMissingPath('order');
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->postJson('/emar/prescriptions/'.$controlledOrder->id.'/confirm')
            ->assertStatus(409)->assertJsonMissingPath('order');
        $this->assertSame('active', $controlledCovert->fresh()->status);
        $this->assertSame('pending', $controlledOrder->fresh()->status);
        $this->assertDatabaseMissing('medication_prescriber_orders', [
            'medication_name' => 'Blocked view-only controlled order',
        ]);

        $this->grantPermissions($user, [MedicationGovernanceScopeService::CONTROLLED_CAPABILITY]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->get(route('emar.prescriptions.legacy'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('medications', function ($medications) use ($controlledMedication): bool {
                    $row = collect($medications)->firstWhere('id', $controlledMedication->id);

                    return $row !== null
                        && $row['can_create_covert_authorisation'] === true
                        && $row['can_link_prescriber_order'] === true;
                })
                ->where('read_only', true)
                ->where('orders', function ($orders) use ($controlledOrder): bool {
                    $row = collect($orders)->firstWhere('id', $controlledOrder->id);

                    // EA-053: old prescriber-order records are read only.
                    return $row !== null
                        && $row['can_confirm'] === false
                        && $row['can_cancel'] === false;
                }));
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert))
            ->assertSessionHasErrors('reason');
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => str_repeat('x', 2001),
            ])
            ->assertSessionHasErrors('reason');
        $this->assertSame('active', $controlledCovert->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.covert_revoked',
            'auditable_id' => $controlledMedication->id,
        ]);
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => '  Best-interest decision superseded.  ',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('revoked', $controlledCovert->fresh()->status);
        $revokeAudit = AuditLog::query()
            ->where('action', 'medication_order.covert_revoked')
            ->where('auditable_id', $controlledMedication->id)
            ->sole();
        $this->assertSame($user->id, (int) $revokeAudit->user_id);
        $this->assertSame($controlledCovert->id, (int) $revokeAudit->meta['authorisation_id']);
        $this->assertSame($client->id, (int) $revokeAudit->meta['client_id']);
        $this->assertSame('Best-interest decision superseded.', $revokeAudit->meta['reason']);
        $this->actingAs($user)
            ->post(route('emar.covert.revoke', $controlledCovert), [
                'reason' => 'Attempted duplicate revocation.',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('revoked', $controlledCovert->fresh()->status);
        $this->assertSame('Best-interest decision superseded.', $controlledCovert->fresh()->revoke_reason);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medication_order.covert_revoked')
            ->where('auditable_id', $controlledMedication->id)
            ->count());
        $this->markChecked($controlledMedication, $user);
        Storage::fake('local');
        $this->actingAs($user)
            ->post(route('emar.covert.store'), $this->covertInput($client, $controlledMedication, 'Permitted controlled covert authorisation'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // EA-053 (Lane C3): with full controlled and order authority the old
        // prescriber-order writes are still refused and change nothing.
        $statuses = MedicationPrescriberOrder::query()->pluck('status', 'id')->all();
        $links = MedicationPrescriberOrder::query()->pluck('client_medication_id', 'id')->all();
        foreach ([
            ['post', route('emar.prescriptions.store'), ['client_id' => $client->id, 'client_medication_id' => $controlledMedication->id, 'controlled_drug_snapshot' => false, 'medication_name' => 'Server-derived controlled order']],
            ['put', route('emar.prescriptions.update', $unknownOrder), ['client_medication_id' => $controlledMedication->id]],
            ['post', '/emar/prescriptions/'.$unknownOrder->id.'/cancel', ['reason' => 'Controlled order replaced after classification.']],
            ['post', '/emar/prescriptions/'.$controlledOrder->id.'/confirm', []],
            ['post', '/emar/prescriptions/'.$controlledOrder->id.'/dispense', ['pharmacy_name' => 'Controlled Pharmacy']],
            ['put', route('emar.prescriptions.update', $ordinaryOrder), ['status' => 'confirmed']],
        ] as [$method, $url, $payload]) {
            $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
            $this->actingAs($user)->{$method.'Json'}($url, $payload)->assertStatus(409)->assertJsonMissingPath('order');
        }
        $this->assertDatabaseMissing('medication_prescriber_orders', ['medication_name' => 'Server-derived controlled order']);
        $this->assertSame($statuses, MedicationPrescriberOrder::query()->pluck('status', 'id')->all());
        $this->assertSame($links, MedicationPrescriberOrder::query()->pluck('client_medication_id', 'id')->all());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.prescriber_order.linked']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.prescriber_order.confirmed']);
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

    private function markChecked(ClientMedication $medication, User $actor): void
    {
        $medication->forceFill(['approval_status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now(), 'start_date' => now('Pacific/Auckland')->toDateString(), 'end_date' => null])->saveQuietly();
    }

    private function covertInput(Client $client, ClientMedication $medication, string $capacityRecord = 'Clinically justified'): array
    {
        $today = now('Pacific/Auckland');

        return [
            'client_id' => $client->id, 'client_medication_id' => $medication->id,
            'capacity_lacking' => true, 'capacity_assessor' => 'Recorded assessor', 'capacity_date' => $today->toDateString(), 'capacity_record' => $capacityRecord,
            'consulted_name' => 'Recorded guardian', 'consulted_role' => 'Welfare guardian', 'consulted_record' => 'Documented consultation.',
            'pharmacist_name' => 'Recorded pharmacist', 'pharmacist_advice' => 'Documented medicine-specific advice.',
            'authorised_by_name' => 'Dr Covert', 'authorised_date' => $today->toDateString(), 'legal_basis' => 'Recorded legal source',
            'administration_method' => 'Method in signed authorisation', 'review_date' => $today->copy()->addMonth()->toDateString(),
            'request_key' => 'covert-'.bin2hex(random_bytes(8)), 'gp_file' => UploadedFile::fake()->create('authorisation.pdf', 1, 'application/pdf'),
        ];
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
