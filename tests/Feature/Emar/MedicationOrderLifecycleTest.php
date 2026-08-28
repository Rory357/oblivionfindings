<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationPrescriberOrder;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationOrderLifecycleService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\MedicationReportingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $serviceContext;

    private Client $client;

    private User $manager;

    private Shift $shift;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Medication lifecycle test context',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $this->manager = $this->scopedUser([
            'medications.view',
            'clients.viewAny',
            'clients.update',
            'medications.orders.manage',
            'reports.viewAny',
        ]);
        $this->shift = $this->activeShift($this->manager, $this->client);

        $notification = \Mockery::mock(NotificationService::class);
        $notification->shouldReceive('notifyCrud')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(NotificationService::class, $notification);
    }

    public function test_emar_and_both_profile_paths_use_the_same_reasoned_retained_lifecycle(): void
    {
        $emarManager = $this->scopedUser([
            'medications.view',
            'medications.orders.manage',
        ]);
        $this->activeShift($emarManager, $this->client);
        $actions = [
            [fn (ClientMedication $medication): string => "/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", $this->manager],
            [fn (ClientMedication $medication): string => "/operations/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", $this->manager],
            [fn (ClientMedication $medication): string => "/emar/medications/{$medication->id}/discontinue", $emarManager],
        ];

        foreach ($actions as $index => [$action, $actor]) {
            $medication = $this->medication(['name' => 'Retained order '.($index + 1)]);

            $this->actingAs($actor)
                ->post($action($medication), [
                    'reason' => 'Prescriber confirmed cessation '.($index + 1),
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            $medication->refresh();
            $this->assertSame('ceased', $medication->state);
            $this->assertFalse($medication->active);
            $this->assertNull($medication->deleted_at);
            $this->assertSame($actor->id, (int) $medication->ceased_by);
            $this->assertNotNull($medication->ceased_at);
            $this->assertDatabaseHas('medication_order_versions', [
                'client_medication_id' => $medication->id,
                'version_number' => 2,
                'state' => 'ceased',
                'changed_by' => $actor->id,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'medication_order.discontinued',
                'auditable_id' => $medication->id,
                'user_id' => $actor->id,
            ]);
        }
    }

    public function test_reason_and_explicit_permission_matrix_fail_closed(): void
    {
        $medication = $this->medication();

        $this->actingAs($this->manager)
            ->post("/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", [
                'reason' => '   ',
            ])
            ->assertSessionHasErrors('reason');

        $viewer = $this->scopedUser(['medications.view', 'clients.viewAny']);
        $this->activeShift($viewer, $this->client);
        $this->actingAs($viewer)
            ->post("/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", [
                'reason' => 'Viewer must not cease orders',
            ])
            ->assertForbidden();

        $clientEditor = $this->scopedUser([
            'medications.view',
            'clients.viewAny',
            'clients.update',
        ]);
        $this->activeShift($clientEditor, $this->client);
        $this->actingAs($clientEditor)
            ->post("/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", [
                'reason' => 'Client editor without medication order authority must not cease orders',
            ])
            ->assertForbidden();

        $ordersManager = $this->scopedUser([
            'medications.view',
            'clients.viewAny',
            'medications.orders.manage',
        ]);
        $this->activeShift($ordersManager, $this->client);
        $this->actingAs($ordersManager)
            ->post("/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", [
                'reason' => 'Order manager confirmed cessation',
            ])
            ->assertRedirect();

        $this->assertSame($ordersManager->id, (int) $medication->fresh()->ceased_by);
    }

    public function test_wrong_client_site_and_assignment_are_denied_without_mutation(): void
    {
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $otherMedication = $this->medication(['client_id' => $otherClient->id]);

        $this->actingAs($this->manager)
            ->post("/clients/{$this->client->id}/medical/medications/{$otherMedication->id}/discontinue", [
                'reason' => '   ',
            ])
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->post("/clients/{$this->client->id}/medical/medications/999999/discontinue", [
                'reason' => '   ',
            ])
            ->assertNotFound();

        $otherSite = Site::factory()->create();
        $siteClient = Client::factory()->create([
            'site_id' => $otherSite->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $siteMedication = $this->medication(['client_id' => $siteClient->id]);
        $this->actingAs($this->manager)
            ->post("/clients/{$siteClient->id}/medical/medications/{$siteMedication->id}/discontinue", [
                'reason' => 'Wrong Site attempt',
            ])
            ->assertNotFound();

        $unassigned = $this->scopedUser([
            'medications.view',
            'clients.viewAny',
            'medications.orders.manage',
        ]);
        $this->actingAs($unassigned)
            ->post("/clients/{$this->client->id}/medical/medications/{$otherMedication->id}/discontinue", [
                'reason' => 'Wrong order and no assignment',
            ])
            ->assertNotFound();
        $this->actingAs($unassigned)
            ->post("/clients/{$this->client->id}/medical/medications/{$this->medication()->id}/discontinue", [
                'reason' => 'No current assignment',
            ])
            ->assertForbidden();

        foreach ([$otherMedication, $siteMedication] as $medication) {
            $this->assertDatabaseHas('client_medications', [
                'id' => $medication->id,
                'state' => 'active',
                'deleted_at' => null,
            ]);
        }
    }

    public function test_explicit_global_site_scope_and_canonical_break_glass_are_positive_paths(): void
    {
        $otherSite = Site::factory()->create();
        $otherClient = Client::factory()->create([
            'site_id' => $otherSite->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $globalUser = $this->scopedUser([
            'medications.view',
            'medications.orders.manage',
            'clinical.accessAllSites',
        ]);
        $this->activeShift($globalUser, $otherClient);
        $globalMedication = $this->medication(['client_id' => $otherClient->id]);

        $this->actingAs($globalUser)
            ->post("/operations/clients/{$otherClient->id}/medical/medications/{$globalMedication->id}/discontinue", [
                'reason' => 'Central clinical lead confirmed cessation',
            ])
            ->assertRedirect();

        $breakGlassUser = $this->scopedUser([
            'medications.view',
            'medications.orders.manage',
            'medications.breakglass',
        ]);
        $breakGlassMedication = $this->medication();
        $access = ClientBreakGlassAccess::query()->create([
            'client_id' => $this->client->id,
            'user_id' => $breakGlassUser->id,
            'reason' => 'Urgent medication order review',
            'reason_category' => 'Staff absence / cover',
            'authorization_mode' => 'self',
            'acknowledged_min_necessary' => true,
            'acknowledged_incident_report' => true,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->actingAs($breakGlassUser)
            ->post("/clients/{$this->client->id}/medical/medications/{$breakGlassMedication->id}/discontinue", [
                'reason' => 'Emergency prescriber instruction',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('break_glass_access_events', [
            'break_glass_access_id' => $access->id,
            'action' => 'ceased_medication_order',
        ]);
        $audit = AuditLog::query()
            ->where('action', 'medication_order.discontinued')
            ->where('auditable_id', $breakGlassMedication->id)
            ->firstOrFail();
        $this->assertSame($access->id, (int) data_get($audit->meta, 'break_glass_access_id'));
    }

    public function test_service_rejects_an_actor_without_order_management_capability_even_with_global_scope(): void
    {
        $scopeOnlyActor = $this->scopedUser([
            'medications.view',
            'clinical.accessAllSites',
        ]);
        $this->activeShift($scopeOnlyActor, $this->client);
        $medication = $this->medication(['name' => 'Action capability boundary']);

        try {
            app(MedicationOrderLifecycleService::class)->discontinue(
                $scopeOnlyActor,
                $medication,
                'Scope must not become action authority',
                $this->client->id,
            );
            $this->fail('A scope-only actor discontinued a medication order.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertOrderStillActive($medication);
        $this->assertDatabaseMissing('medication_order_versions', [
            'client_medication_id' => $medication->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.discontinued',
            'auditable_id' => $medication->id,
        ]);
    }

    public function test_confirmed_prescriber_cease_uses_exact_lifecycle_evidence_and_rolls_back_replay_and_audit_failure(): void
    {
        Carbon::setTestNow('2026-08-21 10:15:30');
        $managerShift = $this->activeShift($this->manager, $this->client);
        $verifier = $this->scopedUser([
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $this->activeShift($verifier, $this->client);
        $medication = $this->verifiedMedication($verifier, [
            'name' => 'Confirmed prescriber cease',
            'controlled_drug' => false,
        ]);
        $payload = $this->prescriberCeasePayload($medication, 'Dr Confirmed', 'Course complete');
        $this->assertTrue($this->manager->canDo('medications.orders.manage'));
        $this->assertContains(
            $this->client->id,
            app(MedicationScopeDecisionService::class)->clientIdsWithCurrentAuthority(
                $this->manager,
                [$this->client->id],
                now(),
            ),
        );
        $this->assertSame(
            $managerShift->id,
            app(MedicationScopeDecisionService::class)->forClient(
                $this->manager,
                $this->client->id,
                now(),
                static fn (MedicationScopeDecision $scope): ?int => $scope->shiftId(),
            ),
        );
        $this->assertDatabaseHas('shifts', [
            'id' => $managerShift->id,
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->manager->id,
            'status' => 'in_progress',
            'actual_ends_at' => null,
        ]);
        $this->assertSame('verified', $medication->approval_status);
        $this->assertSame($verifier->id, (int) $medication->verified_by);
        $this->assertNotNull($medication->verified_at);
        $this->assertFalse((bool) $medication->controlled_drug);
        $this->assertSame(
            $medication->id,
            ClientMedication::query()
                ->whereKey($medication->id)
                ->where('client_id', $this->client->id)
                ->where('state', 'active')
                ->where('active', true)
                ->where('approval_status', 'verified')
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->sole()
                ->id,
        );

        $this->actingAs($this->manager)
            ->post('/emar/prescriptions', $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = MedicationPrescriberOrder::query()
            ->where('client_medication_id', $medication->id)
            ->firstOrFail();
        $this->assertFalse($order->requires_countersign);
        $this->assertSame('pending', $order->status);
        $this->assertOrderStillActive($medication);

        $this->actingAs($verifier)
            ->post(route('emar.prescriptions.confirm', $order))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $reason = 'Prescriber cease order — Dr Confirmed: Course complete';
        $ceasedAt = $this->assertExactLifecycleEvidence($medication, $verifier, $reason);

        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($this->manager)
            ->post('/emar/prescriptions', $payload)
            ->assertNotFound();

        $this->assertSame(1, MedicationPrescriberOrder::query()->where('client_medication_id', $medication->id)->count());
        $this->assertSame(1, MedicationOrderVersion::query()->where('client_medication_id', $medication->id)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medication_order.discontinued')
            ->where('auditable_id', $medication->id)
            ->count());
        $this->assertTrue($medication->fresh()->ceased_at->equalTo($ceasedAt));

        $rollbackMedication = $this->verifiedMedication($verifier, [
            'name' => 'Confirmed cease rollback',
            'controlled_drug' => false,
        ]);
        $this->actingAs($this->manager)
            ->post(
                '/emar/prescriptions',
                $this->prescriberCeasePayload($rollbackMedication, 'Dr Rollback', 'Audit must persist'),
            )
            ->assertSessionHasNoErrors();
        $rollbackOrder = MedicationPrescriberOrder::query()
            ->where('client_medication_id', $rollbackMedication->id)
            ->sole();
        AuditLog::creating(static function (AuditLog $audit) use ($rollbackMedication): void {
            if ($audit->action === 'medication_order.discontinued'
                && (int) $audit->auditable_id === (int) $rollbackMedication->id) {
                throw new RuntimeException('Injected prescriber cease audit failure');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($verifier)->post(route('emar.prescriptions.confirm', $rollbackOrder));
            $this->fail('Prescriber cease audit failure did not escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected prescriber cease audit failure', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertOrderStillActive($rollbackMedication);
        $this->assertSame('pending', $rollbackOrder->fresh()->status);
        $this->assertDatabaseMissing('medication_order_versions', [
            'client_medication_id' => $rollbackMedication->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.discontinued',
            'auditable_id' => $rollbackMedication->id,
        ]);
    }

    public function test_written_prescriber_cease_requires_independent_confirmation_and_rolls_back_replay_and_audit_failure(): void
    {
        Carbon::setTestNow('2026-08-21 11:20:40');
        $this->shift->update([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
        ]);
        $verifier = $this->scopedUser([
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $this->activeShift($verifier, $this->client);
        $medication = $this->verifiedMedication($verifier, ['name' => 'Countersigned prescriber cease']);
        $order = $this->pendingWrittenCeaseOrder($medication, 'Dr Written', 'Independent stop');

        $this->actingAs($this->manager)
            ->post(route('emar.prescriptions.confirm', $order))
            ->assertForbidden();
        $this->actingAs($verifier)
            ->post(route('emar.prescriptions.confirm', $order))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNull($order->countersigned_at);
        $reason = 'Prescriber cease order — Dr Written: Independent stop';
        $ceasedAt = $this->assertExactLifecycleEvidence($medication, $verifier, $reason);

        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($verifier)
            ->post(route('emar.prescriptions.confirm', $order))
            ->assertSessionHasErrors('confirm');

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertTrue($medication->fresh()->ceased_at->equalTo($ceasedAt));
        $this->assertSame(1, MedicationOrderVersion::query()->where('client_medication_id', $medication->id)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medication_order.discontinued')
            ->where('auditable_id', $medication->id)
            ->count());

        $rollbackMedication = $this->verifiedMedication($verifier, ['name' => 'Countersign rollback']);
        $rollbackOrder = $this->pendingWrittenCeaseOrder(
            $rollbackMedication,
            'Dr Confirmation Rollback',
            'Audit must persist',
        );
        AuditLog::creating(static function (AuditLog $audit) use ($rollbackMedication): void {
            if ($audit->action === 'medication_order.discontinued'
                && (int) $audit->auditable_id === (int) $rollbackMedication->id) {
                throw new RuntimeException('Injected countersign audit failure');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($verifier)->post(route('emar.prescriptions.confirm', $rollbackOrder));
            $this->fail('Confirmation audit failure did not escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected countersign audit failure', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $rollbackOrder->refresh();
        $this->assertSame('pending', $rollbackOrder->status);
        $this->assertNull($rollbackOrder->countersigned_at);
        $this->assertNull($rollbackOrder->countersigned_by);
        $this->assertNull($rollbackOrder->countersign_method);
        $this->assertOrderStillActive($rollbackMedication);
        $this->assertDatabaseMissing('medication_order_versions', [
            'client_medication_id' => $rollbackMedication->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.discontinued',
            'auditable_id' => $rollbackMedication->id,
        ]);
    }

    public function test_countersigned_cease_uses_exact_lifecycle_refuses_unlinked_orders_and_rolls_back_audit_failure(): void
    {
        Carbon::setTestNow('2026-08-21 12:30:45');
        $witness = $this->scopedUser([]);
        $countersigner = $this->scopedUser([
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $this->activeShift($countersigner, $this->client);

        $medication = $this->verifiedMedication($countersigner, ['name' => 'Countersigned cease medication']);
        $order = $this->pendingCountersignCeaseOrder(
            $medication,
            $witness,
            'Dr Telephone Cease',
            'Telephone stop',
        );

        $this->actingAs($countersigner)
            ->post(route('emar.prescriptions.countersign', $order), [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame($countersigner->id, (int) $order->countersigned_by);
        $this->assertSame('electronic', $order->countersign_method);
        $reason = 'Prescriber cease order — Dr Telephone Cease: Telephone stop';
        $ceasedAt = $this->assertExactLifecycleEvidence($medication, $countersigner, $reason);
        $this->assertTrue($order->countersigned_at->equalTo($ceasedAt));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.prescriber_order.countersigned',
            'auditable_id' => $order->id,
            'user_id' => $countersigner->id,
        ]);

        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($countersigner)
            ->post(route('emar.prescriptions.countersign', $order), [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertSessionHasErrors('countersign');
        $this->assertSame(1, MedicationOrderVersion::query()->where('client_medication_id', $medication->id)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medication_order.discontinued')
            ->where('auditable_id', $medication->id)
            ->count());

        $unlinkedOrder = $this->pendingCountersignCeaseOrder(
            null,
            $witness,
            'Dr Unlinked Cease',
            'Must remain unlinked',
        );
        $this->actingAs($countersigner)
            ->post(route('emar.prescriptions.countersign', $unlinkedOrder), [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertSessionHasErrors('countersign');
        $this->assertSame('pending', $unlinkedOrder->fresh()->status);
        $this->assertNull($unlinkedOrder->fresh()->countersigned_at);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.prescriber_order.countersigned',
            'auditable_id' => $unlinkedOrder->id,
        ]);

        $rollbackMedication = $this->verifiedMedication($countersigner, ['name' => 'Countersigned cease rollback']);
        $rollbackOrder = $this->pendingCountersignCeaseOrder(
            $rollbackMedication,
            $witness,
            'Dr Countersign Rollback',
            'Audit must persist',
        );
        AuditLog::creating(static function (AuditLog $audit) use ($rollbackMedication): void {
            if ($audit->action === 'medication_order.discontinued'
                && (int) $audit->auditable_id === (int) $rollbackMedication->id) {
                throw new RuntimeException('Injected countersigned cease audit failure');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($countersigner)
                ->post(route('emar.prescriptions.countersign', $rollbackOrder), [
                    'countersign_method' => 'electronic',
                    'prescriber_declaration' => true,
                ]);
            $this->fail('Countersigned cease audit failure did not escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected countersigned cease audit failure', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $rollbackOrder->refresh();
        $this->assertSame('pending', $rollbackOrder->status);
        $this->assertNull($rollbackOrder->countersigned_at);
        $this->assertNull($rollbackOrder->countersigned_by);
        $this->assertNull($rollbackOrder->countersign_method);
        $this->assertOrderStillActive($rollbackMedication);
        $this->assertDatabaseMissing('medication_order_versions', [
            'client_medication_id' => $rollbackMedication->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.prescriber_order.countersigned',
            'auditable_id' => $rollbackOrder->id,
        ]);
    }

    public function test_future_effective_cease_cannot_be_confirmed_or_countersigned_before_the_worker_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 23:30:00', 'Pacific/Auckland'));
        $witness = $this->scopedUser([]);
        $verifier = $this->scopedUser([
            'medications.view',
            'medications.orders.manage',
            'medications.orders.verify',
        ]);
        $this->activeShift($verifier, $this->client);

        $confirmMedication = $this->verifiedMedication($verifier, ['name' => 'Future confirmed cease']);
        $confirmOrder = $this->pendingWrittenCeaseOrder(
            $confirmMedication,
            'Dr Future Confirm',
            'Stop tomorrow',
        );
        $confirmOrder->update(['effective_date' => today()->addDay()]);

        $this->actingAs($verifier)
            ->post(route('emar.prescriptions.confirm', $confirmOrder))
            ->assertSessionHasErrors('confirm');
        $this->assertSame('pending', $confirmOrder->fresh()->status);
        $this->assertOrderStillActive($confirmMedication);

        $countersignMedication = $this->verifiedMedication($verifier, ['name' => 'Future countersigned cease']);
        $countersignOrder = $this->pendingCountersignCeaseOrder(
            $countersignMedication,
            $witness,
            'Dr Future Countersign',
            'Stop tomorrow',
        );
        $countersignOrder->update(['effective_date' => today()->addDay()]);

        $this->actingAs($verifier)
            ->post(route('emar.prescriptions.countersign', $countersignOrder), [
                'countersign_method' => 'electronic',
                'prescriber_declaration' => true,
            ])
            ->assertSessionHasErrors('countersign');
        $countersignOrder->refresh();
        $this->assertSame('pending', $countersignOrder->status);
        $this->assertNull($countersignOrder->countersigned_at);
        $this->assertNull($countersignOrder->countersigned_by);
        $this->assertOrderStillActive($countersignMedication);
        $this->assertDatabaseCount('medication_order_versions', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.discontinued',
        ]);
    }

    public function test_discontinuation_retains_administration_stock_controlled_drug_and_version_context(): void
    {
        $controlledPermissionIds = Permission::query()
            ->whereIn('key', [
                'medications.controlled.view',
                'medications.controlled.record',
            ])
            ->pluck('id');
        $this->manager->permissionOverrides()->syncWithoutDetaching(
            $controlledPermissionIds
                ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
                ->all(),
        );
        $this->manager->unsetRelation('permissionOverrides')->unsetRelation('roles');

        $medication = $this->medication(['controlled_drug' => true]);
        $administration = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $this->shift->id,
            'service_context_id' => $this->serviceContext->id,
            'administered_by' => $this->manager->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => '1 tablet',
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 18,
            'unit' => 'tablets',
        ]);
        $controlled = ClientControlledDrugEntry::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $this->shift->id,
            'service_context_id' => $this->serviceContext->id,
            'entry_type' => 'administered',
            'quantity' => 1,
            'unit' => 'tablet',
            'on_hand_before' => 19,
            'on_hand_after' => 18,
            'recorded_at' => now(),
            'recorded_by' => $this->manager->id,
        ]);

        app(MedicationOrderLifecycleService::class)->discontinue(
            $this->manager,
            $medication,
            'Course completed',
            $this->client->id,
        );

        $this->assertDatabaseHas('client_medication_administrations', ['id' => $administration->id]);
        $this->assertDatabaseHas('client_medication_stocks', ['id' => $stock->id, 'on_hand' => 18]);
        $this->assertDatabaseHas('client_controlled_drug_entries', ['id' => $controlled->id]);
        $this->assertSame($medication->id, $administration->fresh()->medication?->id);
        $this->assertSame($medication->id, $stock->fresh()->medication?->id);
        $this->assertSame($medication->id, $controlled->fresh()->medication?->id);
        $this->assertCount(1, $medication->fresh()->versions);
    }

    public function test_legacy_soft_deleted_parent_remains_resolvable_in_history_and_export_without_a_fabricated_reason(): void
    {
        $medication = $this->medication(['name' => 'Legacy retained administration order']);
        $administration = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $this->shift->id,
            'service_context_id' => $this->serviceContext->id,
            'administered_by' => $this->manager->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => '1 tablet',
        ]);
        $controlledEntry = ClientControlledDrugEntry::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $this->shift->id,
            'service_context_id' => $this->serviceContext->id,
            'entry_type' => 'administered',
            'quantity' => 1,
            'unit' => 'tablet',
            'on_hand_before' => 10,
            'on_hand_after' => 9,
            'recorded_at' => now(),
            'recorded_by' => $this->manager->id,
        ]);
        $version = MedicationOrderVersion::query()->create([
            'client_medication_id' => $medication->id,
            'client_id' => $this->client->id,
            'version_number' => 1,
            'name' => $medication->name,
            'dosage' => $medication->dosage,
            'state' => 'active',
            'active' => true,
            'change_reason' => 'Legacy retained baseline',
            'changed_by' => $this->manager->id,
            'changed_at' => now(),
        ]);
        DB::table('client_medications')->where('id', $medication->id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $historicalOrder = $administration->fresh()->medication;
        $this->assertNotNull($historicalOrder);
        $this->assertTrue($historicalOrder->trashed());
        $this->assertNull($historicalOrder->ceased_reason);
        $this->assertFalse(ClientMedication::query()->whereKey($medication->id)->exists());
        $this->assertSame(
            'Legacy retained administration order (legacy removed order)',
            $controlledEntry->fresh()->medication?->historicalDisplayName(),
        );
        $this->assertSame(
            'Legacy retained administration order (legacy removed order)',
            $version->fresh()->medication?->historicalDisplayName(),
        );

        $csv = $this->actingAs($this->manager)
            ->get('/emar/reports/export?report_type=administration&client_id='.$this->client->id)
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString(
            'Legacy retained administration order (legacy removed order)',
            $csv,
        );
        $mar = app(MedicationReportingService::class)->exportMar(
            $this->client->id,
            now()->subDay(),
            now()->addDay(),
            siteIds: [(int) $this->client->site_id],
        );
        $this->assertSame(
            'Legacy retained administration order (legacy removed order)',
            data_get($mar, 'records.0.medication'),
        );
    }

    public function test_replay_and_direct_model_delete_are_fail_closed(): void
    {
        $medication = $this->medication();
        $requestKey = (string) Str::uuid();
        app(MedicationOrderLifecycleService::class)->discontinue(
            $this->manager,
            $medication,
            'First and only cessation',
            $this->client->id,
            requestKey: $requestKey,
        );

        $this->actingAs($this->manager)
            ->post("/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", [
                'reason' => 'First and only cessation',
                'request_key' => $requestKey,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->post("/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", [
                'reason' => 'Replay attempt',
                'request_key' => $requestKey,
            ])
            ->assertSessionHasErrors('request_key');

        $this->actingAs($this->manager)
            ->post("/clients/{$this->client->id}/medical/medications/{$medication->id}/discontinue", [
                'reason' => 'Distinct terminal action attempt',
                'request_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('medication');
        $this->assertDatabaseCount('medication_order_versions', 1);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medication_order.discontinued')
            ->where('auditable_id', $medication->id)
            ->count());

        $this->actingAs($this->manager)
            ->put("/emar/medications/{$medication->id}", [
                'medication_name' => 'Tampered ceased medication',
            ])
            ->assertNotFound();

        try {
            $medication->fresh()->delete();
            $this->fail('Medication deletion did not fail closed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('discontinue', $exception->getMessage());
        }

        try {
            $medication->fresh()->update([
                'name' => 'Tampered ceased medication',
                'state' => 'active',
                'active' => true,
            ]);
            $this->fail('Ceased medication mutation did not fail closed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertDatabaseHas('client_medications', [
            'id' => $medication->id,
            'state' => 'ceased',
            'active' => false,
            'name' => 'Lifecycle medication order',
            'deleted_at' => null,
        ]);
    }

    public function test_version_and_strict_audit_failures_each_roll_the_order_back(): void
    {
        $versionFailure = $this->medication(['name' => 'Version rollback order']);
        MedicationOrderVersion::creating(static function (MedicationOrderVersion $version) use ($versionFailure): void {
            if ((int) $version->client_medication_id === (int) $versionFailure->id) {
                throw new RuntimeException('Injected version evidence failure');
            }
        });

        try {
            app(MedicationOrderLifecycleService::class)->discontinue(
                $this->manager,
                $versionFailure,
                'Must roll back on version failure',
                $this->client->id,
            );
            $this->fail('Version failure did not escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected version evidence failure', $exception->getMessage());
        }

        $this->assertOrderStillActive($versionFailure);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.discontinued',
            'auditable_id' => $versionFailure->id,
        ]);

        $auditFailure = $this->medication(['name' => 'Audit rollback order']);
        AuditLog::creating(static function (): never {
            throw new RuntimeException('Injected strict audit failure');
        });

        try {
            app(MedicationOrderLifecycleService::class)->discontinue(
                $this->manager,
                $auditFailure,
                'Must roll back on audit failure',
                $this->client->id,
            );
            $this->fail('Audit failure did not escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected strict audit failure', $exception->getMessage());
        }

        $this->assertOrderStillActive($auditFailure);
        $this->assertDatabaseMissing('medication_order_versions', [
            'client_medication_id' => $auditFailure->id,
        ]);
    }

    public function test_version_evidence_cannot_be_updated_or_deleted(): void
    {
        $medication = $this->medication();
        app(MedicationOrderLifecycleService::class)->discontinue(
            $this->manager,
            $medication,
            'Immutable evidence test',
            $this->client->id,
        );
        $version = MedicationOrderVersion::query()->where('client_medication_id', $medication->id)->firstOrFail();

        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $version->update(['change_reason' => 'Tampered']);
                } else {
                    $version->delete();
                }
                $this->fail("Medication version {$operation} did not fail closed.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }

        $this->assertDatabaseHas('medication_order_versions', [
            'id' => $version->id,
            'change_reason' => 'Medication discontinued: Immutable evidence test',
        ]);
    }

    public function test_two_process_discontinue_race_creates_exactly_one_cessation(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $medication = $this->medication(['name' => 'Concurrent discontinue order']);
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."med-order-stop-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."med-order-stop-b-{$token}",
        ];
        $processes = [];
        $connection->commit();

        try {
            $connection->beginTransaction();
            ClientMedication::query()->whereKey($medication->id)->lockForUpdate()->firstOrFail();
            foreach ($readyPaths as $index => $readyPath) {
                $processes[] = $this->startRaceWorker(
                    'discontinue',
                    $readyPath,
                    $database,
                    $medication,
                    'Concurrent reason '.($index + 1),
                );
            }
            $this->waitForWorkers($readyPaths);
            usleep(250_000);
            $this->assertTrue($processes[0]->isRunning() && $processes[1]->isRunning());
            $connection->commit();

            $results = array_map(function (Process $process): array {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));

                return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }, $processes);

            $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['success']));
            $this->assertCount(1, array_filter($results, fn (array $result): bool => ! $result['success']));
            $this->assertSame('ceased', $medication->fresh()->state);
            $this->assertSame(1, MedicationOrderVersion::query()->where('client_medication_id', $medication->id)->count());
            $this->assertSame(1, AuditLog::query()->where('action', 'medication_order.discontinued')->where('auditable_id', $medication->id)->count());
        } finally {
            $this->finishCommittedRace($connection, $processes, $readyPaths);
        }
    }

    public function test_administration_waiting_behind_discontinue_lock_fails_after_revalidation(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->grantPermissions($this->manager, ['medications.administer.record']);
        $actionAt = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfMinute();
        $verifier = $this->scopedUser(['medications.orders.verify']);
        $medication = $this->verifiedMedication($verifier, [
            'name' => 'Administration race order',
            'dose_times' => [$actionAt->format('H:i')],
            'start_date' => $actionAt->copy()->subMonth()->toDateString(),
        ]);
        $database = $connection->getDatabaseName();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'med-order-admin-'.Str::uuid();
        $process = null;
        $connection->commit();

        try {
            $connection->beginTransaction();
            Client::query()->whereKey($this->client->id)->lockForUpdate()->firstOrFail();
            ClientMedication::query()->whereKey($medication->id)->lockForUpdate()->firstOrFail();
            $process = $this->startRaceWorker(
                'administer',
                $readyPath,
                $database,
                $medication,
                $actionAt->toIso8601String(),
            );
            $this->waitForWorkers([$readyPath]);
            usleep(250_000);
            $this->assertTrue($process->isRunning());

            app(MedicationOrderLifecycleService::class)->discontinue(
                $this->manager,
                $medication,
                'Stopped while administration was waiting',
                $this->client->id,
            );
            $connection->commit();

            $process->wait();
            $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['success']);
            // The post-lock reload sees active=false. Canonical schedule-cell
            // resolution therefore conceals the now-unavailable order before
            // the later active-state validator can return 422.
            $this->assertSame(404, $result['status']);
            $this->assertDatabaseMissing('client_medication_administrations', [
                'client_medication_id' => $medication->id,
            ]);
            $this->assertSame('ceased', $medication->fresh()->state);
        } finally {
            $this->finishCommittedRace($connection, array_filter([$process]), [$readyPath], [$verifier]);
        }
    }

    public function test_discontinue_waiting_behind_an_administration_uses_the_post_lock_cessation_time(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->grantPermissions($this->manager, ['medications.administer.record']);
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfMinute();
        $verifier = $this->scopedUser(['medications.orders.verify']);
        $medication = $this->verifiedMedication($verifier, [
            'name' => 'Administration-first race order',
            'dose_times' => [$scheduledFor->format('H:i')],
            'start_date' => $scheduledFor->copy()->subMonth()->toDateString(),
        ]);
        $database = $connection->getDatabaseName();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'med-order-stop-waiting-'.Str::uuid();
        $process = null;
        $connection->commit();

        try {
            $connection->beginTransaction();
            Client::query()->whereKey($this->client->id)->lockForUpdate()->firstOrFail();
            ClientMedication::query()->whereKey($medication->id)->lockForUpdate()->firstOrFail();
            $process = $this->startRaceWorker(
                'discontinue',
                $readyPath,
                $database,
                $medication,
                'Stopped after in-flight administration',
            );
            $this->waitForWorkers([$readyPath]);
            usleep(1_100_000);
            $this->assertTrue($process->isRunning());

            $administeredAt = now();
            app(MedicationScopeDecisionService::class)->forAdministration(
                $this->manager,
                $this->client,
                $medication,
                $administeredAt,
                $scheduledFor,
                null,
                null,
                function (MedicationScopeDecision $scope) use ($administeredAt, $scheduledFor): void {
                    ClientMedicationAdministration::query()->create([
                        'client_id' => $scope->client->id,
                        'client_medication_id' => $scope->medication->id,
                        'shift_id' => $scope->shiftId(),
                        'service_context_id' => $scope->client->service_context_id,
                        'administered_by' => $scope->performer->id,
                        'scheduled_for' => $scheduledFor,
                        'administered_at' => $administeredAt,
                        'status' => 'given',
                        'dose_given' => '1 tablet',
                    ]);
                },
            );
            $connection->commit();

            $process->wait();
            $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['success']);
            $administration = ClientMedicationAdministration::query()
                ->where('client_medication_id', $medication->id)
                ->sole();
            $this->assertTrue($administration->administered_at->lessThanOrEqualTo($medication->fresh()->ceased_at));
        } finally {
            $this->finishCommittedRace($connection, array_filter([$process]), [$readyPath], [$verifier]);
        }
    }

    private function medication(array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'created_by' => $this->manager->id,
            'name' => 'Lifecycle medication order',
            'dosage' => '1 tablet',
            'frequency' => 'Once daily',
            'dose_times' => [now()->format('H:i')],
            'start_date' => today()->subMonth(),
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ], $overrides));
    }

    private function verifiedMedication(User $verifier, array $overrides = []): ClientMedication
    {
        $medication = $this->medication($overrides);
        $medication->forceFill([
            'approval_status' => 'verified',
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->saveQuietly();

        return $medication->refresh();
    }

    /** @return array<string, mixed> */
    private function prescriberCeasePayload(
        ClientMedication $medication,
        string $prescriber,
        string $clinicalNotes,
    ): array {
        return [
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'order_type' => 'cease',
            'prescriber_name' => $prescriber,
            'medication_name' => $medication->name,
            'dose' => $medication->dosage,
            'route' => $medication->route ?? 'Oral',
            'frequency' => $medication->frequency,
            'clinical_notes' => $clinicalNotes,
            'order_date' => today()->toDateString(),
        ];
    }

    private function pendingWrittenCeaseOrder(
        ClientMedication $medication,
        string $prescriber,
        string $clinicalNotes,
    ): MedicationPrescriberOrder {
        return MedicationPrescriberOrder::query()->create([
            ...$this->prescriberCeasePayload($medication, $prescriber, $clinicalNotes),
            'status' => 'pending',
            'requires_countersign' => false,
            'received_by' => $this->manager->id,
        ]);
    }

    private function pendingCountersignCeaseOrder(
        ?ClientMedication $medication,
        User $witness,
        string $prescriber,
        string $clinicalNotes,
    ): MedicationPrescriberOrder {
        return MedicationPrescriberOrder::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication?->id,
            'controlled_drug_snapshot' => $medication?->controlled_drug ?? false,
            'order_type' => 'cease',
            'status' => 'pending',
            'prescriber_name' => $prescriber,
            'medication_name' => $medication?->name ?? 'Unlinked cease medication',
            'dose' => $medication?->dosage ?? 'Cease',
            'route' => $medication?->route ?? 'Oral',
            'frequency' => $medication?->frequency ?? 'Cease',
            'clinical_notes' => $clinicalNotes,
            'order_date' => today(),
            'requires_countersign' => true,
            'read_back_confirmed' => true,
            'read_back_witnessed_by' => $witness->id,
            'read_back_verified_at' => now(),
            'read_back_verification_method' => MedicationPrescriberOrder::READ_BACK_VERIFICATION_METHOD_PASSWORD,
            'received_by' => $this->manager->id,
        ]);
    }

    private function assertExactLifecycleEvidence(
        ClientMedication $medication,
        User $actor,
        string $reason,
    ): Carbon {
        $medication->refresh();
        $version = MedicationOrderVersion::query()
            ->where('client_medication_id', $medication->id)
            ->sole();
        $audit = AuditLog::query()
            ->where('action', 'medication_order.discontinued')
            ->where('auditable_id', $medication->id)
            ->sole();

        $this->assertSame('ceased', $medication->state);
        $this->assertFalse($medication->active);
        $this->assertNull($medication->deleted_at);
        $this->assertSame($actor->id, (int) $medication->ceased_by);
        $this->assertSame($reason, $medication->ceased_reason);
        $this->assertNotNull($medication->ceased_at);
        $this->assertSame('ceased', $version->state);
        $this->assertSame($reason, $version->ceased_reason);
        $this->assertSame($actor->id, (int) $version->changed_by);
        $this->assertNotEmpty($version->cessation_request_key);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $version->cessation_payload_sha256);
        $this->assertTrue($version->ceased_at->equalTo($medication->ceased_at));
        $this->assertTrue($version->changed_at->equalTo($medication->ceased_at));
        $this->assertSame($reason, data_get($audit->meta, 'reason'));
        $this->assertSame($medication->ceased_at->toIso8601String(), data_get($audit->meta, 'ceased_at'));
        $this->assertSame($version->id, (int) data_get($audit->meta, 'medication_order_version_id'));
        $this->assertSame($version->cessation_request_key, data_get($audit->meta, 'cessation_request_key'));
        $this->assertSame($version->cessation_payload_sha256, data_get($audit->meta, 'cessation_payload_sha256'));

        return $medication->ceased_at->copy();
    }

    /** @param  array<int, string>  $permissions */
    private function scopedUser(array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $user->permissionOverrides()->sync(
            $ids->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
        );
        $user->unsetRelation('permissionOverrides');
        $user->unsetRelation('roles');

        return $user;
    }

    private function activeShift(User $user, Client $client): Shift
    {
        return Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $user->id,
            'created_by' => $user->id,
            'status' => 'in_progress',
        ]);
    }

    /** @param  array<int, string>  $permissions */
    private function grantPermissions(User $user, array $permissions): void
    {
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $ids);
        $user->permissionOverrides()->syncWithoutDetaching(
            $ids->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
        );
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }

    private function assertOrderStillActive(ClientMedication $medication): void
    {
        $fresh = $medication->fresh();
        $this->assertSame('active', $fresh->state);
        $this->assertTrue($fresh->active);
        $this->assertNull($fresh->ceased_at);
        $this->assertNull($fresh->ceased_by);
        $this->assertNull($fresh->deleted_at);
    }

    private function startRaceWorker(
        string $mode,
        string $readyPath,
        string $database,
        ClientMedication $medication,
        string $argument,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$performer = App\Models\User::query()->findOrFail((int) $argv[3]);
$client = App\Models\Client::query()->findOrFail((int) $argv[4]);
$medication = App\Models\ClientMedication::query()->findOrFail((int) $argv[5]);
file_put_contents($argv[6], 'ready');
try {
    if ($argv[2] === 'discontinue') {
        $app->make(App\Services\Medication\MedicationOrderLifecycleService::class)->discontinue(
            $performer,
            $medication,
            $argv[7],
            $client->id,
        );
    } else {
        $actionAt = Carbon\Carbon::parse($argv[7]);
        $app->make(App\Services\Medication\MedicationScopeDecisionService::class)->forAdministration(
            $performer,
            $client,
            $medication,
            $actionAt,
            $actionAt,
            null,
            null,
            function (App\Services\Medication\MedicationScopeDecision $scope) use ($actionAt): void {
                App\Models\ClientMedicationAdministration::query()->create([
                    'client_id' => $scope->client->id,
                    'client_medication_id' => $scope->medication->id,
                    'shift_id' => $scope->shiftId(),
                    'service_context_id' => $scope->client->service_context_id,
                    'administered_by' => $scope->performer->id,
                    'scheduled_for' => $actionAt,
                    'administered_at' => $actionAt,
                    'status' => 'given',
                    'dose_given' => '1 tablet',
                ]);
            },
        );
    }
    echo json_encode(['success' => true, 'status' => 200], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $exception) {
    echo json_encode(['success' => false, 'status' => 422, 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    echo json_encode(['success' => false, 'status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR);
}
PHP;

        $process = new Process([
            PHP_BINARY,
            '-r',
            $worker,
            base_path(),
            $mode,
            (string) $this->manager->id,
            (string) $this->client->id,
            (string) $medication->id,
            $readyPath,
            $argument,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $database,
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param  array<int, string>  $paths */
    private function waitForWorkers(array $paths): void
    {
        $deadline = microtime(true) + 15;
        while (array_filter($paths, fn (string $path): bool => ! is_file($path)) !== []) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for the medication lifecycle race workers.');
            }
            usleep(10_000);
        }
    }

    /**
     * @param  array<int, Process>  $processes
     * @param  array<int, string>  $readyPaths
     * @param  array<int, User>  $auxiliaryUsers
     */
    private function finishCommittedRace(
        $connection,
        array $processes,
        array $readyPaths,
        array $auxiliaryUsers = [],
    ): void {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ($readyPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $userIds = collect([$this->manager, ...$auxiliaryUsers])
            ->pluck('id')
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values()
            ->all();
        $profileIds = DB::table('hr_employee_profiles')
            ->whereIn('user_id', $userIds)
            ->pluck('id')
            ->map(fn ($profileId): int => (int) $profileId)
            ->all();
        $shiftIds = DB::table('shifts')
            ->where('client_id', $this->client->id)
            ->pluck('id')
            ->map(fn ($shiftId): int => (int) $shiftId)
            ->all();

        DB::table('audit_logs')
            ->where(function ($audits) use ($profileIds): void {
                $audits
                    ->where('client_id', $this->client->id)
                    ->orWhere(function ($siteAudits): void {
                        $siteAudits
                            ->where('auditable_type', $this->site->getMorphClass())
                            ->where('auditable_id', $this->site->id);
                    })
                    ->orWhere(function ($contextAudits): void {
                        $contextAudits
                            ->where('auditable_type', $this->serviceContext->getMorphClass())
                            ->where('auditable_id', $this->serviceContext->id);
                    })
                    ->orWhere(function ($profileAudits) use ($profileIds): void {
                        $profileAudits
                            ->where('auditable_type', (new HrEmployeeProfile)->getMorphClass())
                            ->whereIn('auditable_id', $profileIds);
                    });
            })
            ->delete();

        DB::table('break_glass_access_events')->whereIn(
            'break_glass_access_id',
            DB::table('client_break_glass_accesses')->where('client_id', $this->client->id)->select('id'),
        )->delete();
        DB::table('client_break_glass_accesses')->where('client_id', $this->client->id)->delete();
        DB::table('client_controlled_drug_entries')->where('client_id', $this->client->id)->delete();
        DB::table('client_medication_stocks')->whereIn(
            'client_medication_id',
            DB::table('client_medications')->where('client_id', $this->client->id)->select('id'),
        )->delete();
        DB::table('client_medication_administrations')->where('client_id', $this->client->id)->delete();
        DB::table('medication_order_versions')->where('client_id', $this->client->id)->delete();
        DB::table('timeline_events')
            ->where('client_id', $this->client->id)
            ->where('source_type', Shift::class)
            ->whereIn('source_id', $shiftIds)
            ->delete();
        DB::table('shifts')->where('client_id', $this->client->id)->delete();
        DB::table('client_user')->where('client_id', $this->client->id)->delete();
        DB::table('client_medications')->where('client_id', $this->client->id)->delete();
        DB::table('clients')->where('id', $this->client->id)->delete();
        DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
        DB::table('role_user')->whereIn('user_id', $userIds)->delete();
        DB::table('hr_employee_profile_versions')->whereIn('employee_profile_id', $profileIds)->delete();
        DB::table('hr_employee_profiles')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        DB::table('sites')->where('id', $this->site->id)->delete();
        DB::table('service_contexts')->where('id', $this->serviceContext->id)->delete();

        $connection->beginTransaction();
    }
}
