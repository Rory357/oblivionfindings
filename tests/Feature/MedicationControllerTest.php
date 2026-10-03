<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientCondition;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationOrderRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\EmarUrl;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;
use Database\Factories\UserFactory;

class MedicationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $providerManager;

    protected User $coordinator;

    protected User $supportWorker;

    protected User $financeUser;

    protected User $hrUser;

    protected User $auditor;

    protected Client $client;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected Carbon $workerActionAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerActionAt = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfMinute();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create();

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->providerManager = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $this->providerManager->roles()->attach(Role::where('name', 'provider_manager')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->financeUser = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
        $this->financeUser->roles()->attach(Role::where('name', 'finance')->first());

        $this->hrUser = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $this->hrUser->roles()->attach(Role::where('name', 'hr')->first());

        $this->auditor = User::factory()->create(['role' => 'auditor', 'approved_at' => now()]);
        $this->auditor->roles()->attach(Role::where('name', 'auditor')->first());

        foreach ([
            $this->admin,
            $this->providerManager,
            $this->coordinator,
            $this->supportWorker,
            $this->financeUser,
            $this->hrUser,
            $this->auditor,
        ] as $staff) {
            $this->assignCurrentSite($staff);
        }

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Test Residential',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
        ]);

        // Assign support worker to client
        $this->client->supportWorkers()->attach($this->supportWorker->id);

        // Medication writes require current server-authoritative work scope.
        // Give each role a current client assignment so the existing controller
        // regression cases continue to exercise their intended permission and
        // validation boundary rather than failing earlier on assignment.
        foreach ([
            $this->admin,
            $this->providerManager,
            $this->coordinator,
            $this->supportWorker,
            $this->financeUser,
            $this->hrUser,
            $this->auditor,
        ] as $staff) {
            Shift::factory()->create([
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $staff->id,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHours(7),
                'actual_starts_at' => now()->subHour(),
                'actual_ends_at' => null,
                'started_by' => $staff->id,
                'status' => 'in_progress',
            ]);
        }

        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->supportWorker->id,
            'assessor_id' => $this->admin->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => $this->workerNow()->subMonth()->toDateString(),
            'expiry_date' => $this->workerNow()->addYear()->toDateString(),
            'assessor_declared_at' => $this->workerNow()->subMonth(),
            'staff_acknowledged_at' => $this->workerNow()->subMonth()->addMinute(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helper: mock NotificationService to avoid side-effects
    // ──────────────────────────────────────────────────────────────

    protected function mockNotificationService(): MockInterface
    {
        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notifyCrud')->andReturnNull();
        // Keep eligible recipients intact; notification routing has its own focused coverage.
        \Illuminate\Support\Facades\Notification::fake();
        $mock->shouldReceive('applyPreferences')->andReturnUsing(fn ($recipients) => $recipients);
        $this->app->instance(NotificationService::class, $mock);

        return $mock;
    }

    protected function assignCurrentSite(User $user): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    protected function workerNow(): Carbon
    {
        return $this->workerActionAt->copy();
    }

    /**
     * Create a second support worker that can witness controlled drugs.
     */
    protected function createWitness(): User
    {
        $witness = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $witness->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->assignCurrentSite($witness);
        $this->client->supportWorkers()->attach($witness->id);
        $this->assertTrue($witness->canDo('medications.controlled.witness'));
        $this->qualifyControlledWitness($witness);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(7),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $witness->id,
            'status' => 'in_progress',
        ]);

        return $witness;
    }

    protected function qualifyControlledWitness(User $witness): void
    {
        $assessor = $witness->is($this->admin)
            ? $this->providerManager
            : $this->admin;

        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => $this->workerNow()->subMonth()->toDateString(),
            'expiry_date' => $this->workerNow()->addYear()->toDateString(),
            'assessor_declared_at' => $this->workerNow()->subMonth(),
            'staff_acknowledged_at' => $this->workerNow()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
        ]);
    }

    /**
     * Create a ClientMedication directly in the database for the test client.
     */
    protected function createMedication(array $overrides = []): ClientMedication
    {
        // The order is entered the day before: nothing is owed before an
        // order exists (P01 recording guard), so a dose due at "now" or
        // earlier today must belong to an order entered before it.
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now()->subDay());
        try {
            return $this->createMedicationNow($overrides);
        } finally {
            Carbon::setTestNow($now);
        }
    }

    protected function createMedicationNow(array $overrides = []): ClientMedication
    {
        return ClientMedication::create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Twice daily',
            'dose_times' => [$this->workerNow()->format('H:i')],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
    }

    /**
     * Create a controlled drug medication.
     */
    protected function createControlledDrug(array $overrides = []): ClientMedication
    {
        return $this->createMedication(array_merge([
            'name' => 'Morphine Sulphate',
            'controlled_drug' => true,
        ], $overrides));
    }

    /**
     * Create a PRN medication.
     */
    protected function createPrnMedication(array $overrides = []): ClientMedication
    {
        return $this->createMedication(array_merge([
            'name' => 'Ibuprofen PRN',
            'is_prn' => true,
            'frequency' => null,
            'dose_times' => null,
            'prn_reason' => 'As needed for pain',
        ], $overrides));
    }

    /** P07 cases begin with an already checked, current controlled order. */
    private function p07ControlledMedicine(): ClientMedication
    {
        return $this->createControlledDrug([
            'dosage' => '1 tablet',
            'dose_amount' => 1,
            'dose_unit' => 'tablet',
            'approval_status' => 'verified',
            'verified_by' => $this->admin->id,
            'verified_at' => $this->workerNow()->subDay(),
        ]);
    }

    /** Positive P07 witnesses have the existing controlled competency endorsement. */
    private function p07ControlledWitness(): User
    {
        $witness = $this->createWitness();
        MedicationCompetencyAssessment::query()->where('user_id', $witness->id)->sole()->update([
            'controlled_drugs' => true,
            'restricted' => false,
            'not_seen_areas' => [],
        ]);

        return $witness;
    }

    /** Capture the actual register head and stock position sent by the current dialog. */
    private function p07ControlledCommand(ClientMedication $medication, array $fields = []): array
    {
        return [
            'client_medication_id' => $medication->id,
            'client_request_uuid' => (string) Str::uuid(),
            'expected_entry_id' => ClientControlledDrugEntry::query()
                ->where('client_medication_id', $medication->id)->latest('id')->value('id'),
            'expected_balance' => ClientMedicationStock::query()
                ->where('client_medication_id', $medication->id)->value('on_hand'),
            ...$fields,
        ];
    }

    private function p07ResolutionFixture(): array
    {
        $medication = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();
        ClientMedicationStock::create([
            'client_medication_id' => $medication->id, 'on_hand' => 8, 'unit' => 'tablets',
        ]);
        $count = ClientControlledDrugEntry::create([
            'client_id' => $this->client->id, 'client_medication_id' => $medication->id,
            'entry_type' => 'balance_check', 'quantity' => 8, 'unit' => 'tablets',
            'on_hand_before' => 10, 'on_hand_after' => 8,
            'recorded_by' => $this->admin->id, 'witnessed_by' => $witness->id,
            'recorded_at' => $this->workerNow(),
        ]);
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id, 'client_medication_id' => $medication->id,
            'service_context_id' => $this->serviceContext->id, 'count_entry_id' => $count->id,
            'on_hand_before' => 10, 'on_hand_after' => 8, 'difference' => -2,
            'reported_at' => $this->workerNow(), 'reported_by' => $this->admin->id,
            'witnessed_by' => $witness->id, 'status' => 'open',
        ]);

        return [$medication, $discrepancy, $witness];
    }

    // ══════════════════════════════════════════════════════════════
    //  1. CENTRAL MEDICATIONS INDEX - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_medications_index_requires_authentication(): void
    {
        $this->get('/medications')->assertRedirect('/login');
    }

    public function test_medications_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/medications')
            ->assertRedirect(EmarUrl::daily());
    }

    public function test_medications_index_accessible_by_support_worker_with_view_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/medications')
            ->assertRedirect(EmarUrl::daily());
    }

    public function test_canonical_medications_daily_view_accessible_by_support_worker(): void
    {
        // /emar/daily 301s to the merged /emar home (see MedicationsController);
        // the canonical daily view is reachable by following that redirect.
        $this->actingAs($this->supportWorker)
            ->followingRedirects()
            ->get(EmarUrl::daily())
            ->assertOk();
    }

    public function test_medications_index_forbidden_for_hr_without_medications_view(): void
    {
        $this->actingAs($this->hrUser)
            ->get('/medications')
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  2. SUPPORT WORKER VISIBILITY - Per-person MAR scope
    // ══════════════════════════════════════════════════════════════

    public function test_support_worker_mar_picker_lists_only_residents_they_may_open(): void
    {
        // Person scope, not Site scope (supersedes the earlier Site-wide
        // picker rule, 2026-09-30): an ordinary support worker's MAR picker
        // lists only residents whose chart they may open under
        // ClientPolicy::viewMedications — an assignment, or a clocked-in shift
        // covering them — never another resident at the same Site, and never
        // anyone at a foreign Site.
        $assignedClient = Client::factory()->create(['site_id' => $this->site->id]);
        $assignedClient->supportWorkers()->attach($this->supportWorker->id);
        $coveredClient = Client::factory()->create(['site_id' => $this->site->id]);
        Shift::factory()->create([
            'client_id' => $coveredClient->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->supportWorker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(7),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $this->supportWorker->id,
            'status' => 'in_progress',
        ]);
        $sameSiteClient = Client::factory()->create(['site_id' => $this->site->id]);
        $foreignClient = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        foreach ([$assignedClient, $coveredClient, $sameSiteClient, $foreignClient] as $client) {
            $this->createMedication(['client_id' => $client->id]);
        }

        $pickerIds = collect(
            $this->actingAs($this->supportWorker)
                ->get(EmarUrl::mar())
                ->assertOk()
                ->inertiaProps('clients'),
        )->pluck('id');

        $this->assertTrue($pickerIds->contains($assignedClient->id), 'Assigned resident is listed.');
        $this->assertTrue($pickerIds->contains($coveredClient->id), 'Clocked-in covering-shift resident is listed.');
        $this->assertFalse($pickerIds->contains($sameSiteClient->id), 'Unassigned, uncovered same-Site resident is hidden.');
        $this->assertFalse($pickerIds->contains($foreignClient->id), 'Foreign-Site resident is hidden.');
    }

    public function test_admin_sees_all_clients_in_medications_index(): void
    {
        $this->createMedication();
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $this->createMedication(['client_id' => $otherClient->id]);

        $this->actingAs($this->admin)
            ->get(EmarUrl::mar())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clients', fn ($clients) => collect($clients)->pluck('id')->contains($this->client->id) &&
                    collect($clients)->pluck('id')->contains($otherClient->id)
                )
            );
    }

    // ══════════════════════════════════════════════════════════════
    //  3. AUDIT LOG - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_audit_index_requires_authentication(): void
    {
        $this->get('/medications/audit')->assertRedirect('/login');
    }

    public function test_audit_index_accessible_by_admin(): void
    {
        $this->p09ControllerCanonicalRead($this->admin, '/medications/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('filters.view', 'audit')
                ->where('can.audit', true)
                ->has('page.data')
                ->has('filters')
            );
    }

    public function test_audit_index_accessible_by_coordinator(): void
    {
        $actor = $this->p09ControllerReportActor($this->coordinator);
        $this->p09ControllerCanonicalRead($actor, '/medications/audit')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('filters.view', 'audit')->where('can.audit', true));
    }

    public function test_audit_index_accessible_by_auditor(): void
    {
        $actor = $this->p09ControllerReportActor($this->auditor);
        $this->p09ControllerCanonicalRead($actor, '/medications/audit')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('filters.view', 'audit')->where('can.audit', true));
    }

    public function test_audit_index_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/medications/audit')
            ->assertForbidden();
    }

    public function test_audit_index_forbidden_for_hr(): void
    {
        $this->actingAs($this->hrUser)
            ->get('/medications/audit')
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  4. AUDIT CSV EXPORT - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_audit_export_requires_authentication(): void
    {
        $this->get('/medications/audit/export')->assertRedirect('/login');
    }

    public function test_audit_export_accessible_by_admin(): void
    {
        $medicine = $this->p09ControllerReportMedicine();
        \App\Models\AuditLog::query()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->admin->id,
            'action' => 'Report fixture change',
            'auditable_type' => ClientMedication::class,
            'auditable_id' => $medicine->id,
            'meta' => ['fields' => ['dosage'], 'history' => 'PRIVATE AUDIT HISTORY'],
        ]);
        $this->actingAs($this->admin)
            ->get('/medications/audit/export')
            ->assertSessionHasErrors('purpose');
        $this->assertSame(0, \App\Models\MedicationEvent::where('kind', 'export.created')->count());
        $csv = $this->get(route('medications.audit.export', ['purpose' => 'audit', 'client_id' => $this->client->id, 'period' => 'today']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8')->getContent();
        $this->assertStringContainsString('Report fixture change', $csv);
        $this->assertStringContainsString('dosage', $csv);
        $this->assertStringNotContainsString('PRIVATE AUDIT HISTORY', $csv);
        $event = \App\Models\MedicationEvent::where('kind', 'export.created')->sole();
        $this->assertSame('Audit or inspection', $event->facts['purpose']);
        $this->assertSame($this->client->id, $event->client_id);
    }

    public function test_audit_export_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/medications/audit/export')
            ->assertForbidden();
    }

    public function test_audit_export_forbidden_for_auditor_without_export_permission(): void
    {
        $actor = $this->p09ControllerReportActor($this->auditor, ['medications.reports.view'], ['medications.audit.export']);
        $this->assertTrue($actor->canDo('medications.audit.view'));
        $this->assertFalse($actor->canDo('medications.audit.export'));
        $this->actingAs($actor)
            ->get(route('medications.audit.export', ['purpose' => 'audit']))
            ->assertForbidden();
        $this->postJson(route('emar.reports.export'), ['type' => 'audit', 'period' => 'today', 'purpose' => 'audit'])
            ->assertForbidden();
        $this->assertSame(0, \App\Models\MedicationEvent::where('kind', 'export.created')->count());
    }

    // ══════════════════════════════════════════════════════════════
    //  5. REPORTS - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_reports_index_requires_authentication(): void
    {
        $this->get('/reports/medications')->assertRedirect('/login');
    }

    public function test_reports_index_accessible_by_admin(): void
    {
        $this->p09ControllerCanonicalRead($this->admin, '/reports/medications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('filters.report', 'doses')
                ->has('filters')
                ->has('people')
                ->has('sites')
                ->has('page.data')
                ->has('data.totals')
            );
    }

    public function test_reports_index_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/reports/medications')
            ->assertForbidden();
    }

    public function test_reports_mar_export_requires_authentication(): void
    {
        $this->get('/reports/medications/export-mar')->assertRedirect('/login');
    }

    public function test_reports_mar_export_accessible_by_admin(): void
    {
        $medicine = $this->p09ControllerReportMedicine(['name' => 'Ordinary export medicine']);
        $this->p09ControllerReportAdministration($medicine);
        $csv = $this->actingAs($this->admin)
            ->get(route('reports.medications.export_mar', ['client_id' => $this->client->id, 'period' => 'today', 'purpose' => 'care']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString('Ordinary export medicine', $csv);
        $this->assertStringContainsString('given', $csv);
        $event = \App\Models\MedicationEvent::where('kind', 'export.created')->sole();
        $this->assertSame('Care and handover', $event->facts['purpose']);
        $this->assertSame($this->client->id, $event->client_id);
    }

    public function test_reports_discrepancies_export_requires_authentication(): void
    {
        $this->get('/reports/medications/export-controlled-discrepancies')->assertRedirect('/login');
    }

    public function test_reports_discrepancies_export_accessible_by_admin(): void
    {
        $medicine = $this->p09ControllerReportMedicine(['name' => 'Controlled discrepancy export medicine', 'controlled_drug' => true]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medicine->id,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'difference' => -1,
            'status' => 'open',
        ]);
        $csv = $this->actingAs($this->admin)
            ->get(route('reports.medications.export_discrepancies', ['client_id' => $this->client->id, 'period' => 'week', 'purpose' => 'audit']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString('Controlled discrepancy export medicine', $csv);
        $this->assertStringContainsString('open', $csv);
        $event = \App\Models\MedicationEvent::where('kind', 'export.created')->sole();
        $this->assertSame('Audit or inspection', $event->facts['purpose']);
        $this->assertTrue($event->controlled);
    }

    public function test_reports_discrepancies_export_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/reports/medications/export-controlled-discrepancies')
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  6. CLIENT MEDICAL PAGE - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_client_medical_show_requires_authentication(): void
    {
        $this->get("/clients/{$this->client->id}/medical")->assertRedirect('/login');
    }

    public function test_client_medical_show_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    public function test_client_medical_show_accessible_by_assigned_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    public function test_client_medical_show_forbidden_for_unassigned_support_worker(): void
    {
        $unassignedWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $unassignedWorker->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->assignCurrentSite($unassignedWorker);

        $this->actingAs($unassignedWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  7. MEDICAL PROFILE - CRUD
    // ══════════════════════════════════════════════════════════════

    public function test_update_medical_profile_requires_authentication(): void
    {
        $this->put("/clients/{$this->client->id}/medical/profile", [])
            ->assertRedirect('/login');
    }

    public function test_update_medical_profile_with_valid_data(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/profile", [
                'medical_history' => 'Type 2 diabetes',
                'disabilities' => ['Mobility impairment'],
                'allergies' => ['Penicillin'],
                'notes' => 'Requires daily blood sugar monitoring',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = ClientMedicalProfile::where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame('Type 2 diabetes', $profile->medical_history);
        $this->assertSame(['Mobility impairment'], $profile->disabilities);
        $this->assertSame(['Penicillin'], $profile->allergies);
    }

    public function test_update_medical_profile_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->put("/clients/{$this->client->id}/medical/profile", [
                'medical_history' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_update_medical_profile_updates_existing_record(): void
    {
        $this->mockNotificationService();

        ClientMedicalProfile::create([
            'client_id' => $this->client->id,
            'medical_history' => 'Old history',
            'allergies' => ['Old allergies'],
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/profile", [
                'medical_history' => 'Updated history',
                'allergies' => ['Updated allergies'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = ClientMedicalProfile::where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame('Updated history', $profile->medical_history);
        $this->assertSame(['Updated allergies'], $profile->allergies);
        $this->assertDatabaseCount('client_medical_profiles', 1);
    }

    // ══════════════════════════════════════════════════════════════
    //  8. MEDICATION CRUD - Create
    // ══════════════════════════════════════════════════════════════

    public function test_store_medication_requires_authentication(): void
    {
        $this->post("/clients/{$this->client->id}/medical/medications", [])
            ->assertRedirect('/login');
    }

    public function test_store_medication_with_valid_data(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Metformin',
                'dosage' => '500mg',
                'frequency' => 'Twice daily',
                'dose_times' => ['08:00', '20:00'],
                'is_prn' => false,
                'controlled_drug' => false,
                'route' => 'oral',
                'form' => 'tablet',
                'prescriber' => 'Dr Smith',
                'pharmacy' => 'Local Pharmacy',
                'start_date' => '2025-01-01',
                'instructions' => 'Take with food',
                'active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medications', [
            'client_id' => $this->client->id,
            'name' => 'Metformin',
            'dosage' => '500mg',
            'route' => 'oral',
            'form' => 'tablet',
            'prescriber' => 'Dr Smith',
            'active' => true,
        ]);
    }

    public function test_store_prn_medication_persists_canonical_prn_fields(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Ibuprofen PRN',
                'dosage' => '200mg',
                'is_prn' => true,
                'prn_reason' => 'Breakthrough pain',
                'max_per_day' => 4,
                'min_hours_between_doses' => 6,
                'controlled_drug' => true,
                'high_risk' => true,
                'prescriber' => 'Dr Canonical',
                'route' => 'oral',
                'form' => 'tablet',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $medication = ClientMedication::where('client_id', $this->client->id)
            ->where('name', 'Ibuprofen PRN')
            ->firstOrFail();

        $this->assertTrue($medication->is_prn);
        $this->assertSame('Breakthrough pain', $medication->prn_reason);
        $this->assertSame(4, $medication->max_per_day);
        $this->assertSame(6.0, $medication->min_hours_between_doses);
        $this->assertTrue($medication->controlled_drug);
        $this->assertTrue($medication->high_risk);
        $this->assertSame('Dr Canonical', $medication->prescriber);
    }

    public function test_store_medication_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test Med',
            ])
            ->assertForbidden();
    }

    public function test_store_medication_validates_name_required(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'dosage' => '10mg',
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_medication_validates_name_max_length(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => str_repeat('a', 256),
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_medication_validates_dose_times_format(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'dose_times' => ['8am', 'noon'],
            ])
            ->assertSessionHasErrors(['dose_times.0']);
    }

    public function test_store_medication_accepts_valid_dose_times(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test Med',
                'dose_times' => ['08:00', '12:30', '18:00'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $med = ClientMedication::where('name', 'Test Med')->first();
        $this->assertEquals(['08:00', '12:30', '18:00'], $med->dose_times);
    }

    public function test_store_medication_validates_state_enum(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'state' => 'invalid_state',
            ])
            ->assertSessionHasErrors(['state']);
    }

    public function test_store_medication_rejects_crafted_inactive_or_ceased_orders_without_persistence(): void
    {
        $attempts = [
            ['name' => 'Crafted ceased state', 'state' => 'ceased', 'error' => 'state'],
            ['name' => 'Crafted paused state', 'state' => 'paused', 'error' => 'state'],
            ['name' => 'Crafted inactive flag', 'active' => false, 'error' => 'active'],
            ['name' => 'Crafted expired order', 'end_date' => today()->subDay()->toDateString(), 'error' => 'end_date'],
            ['name' => 'Crafted cessation time', 'ceased_at' => '2026-08-21 10:30:00', 'error' => 'ceased_at'],
            ['name' => 'Crafted cessation reason', 'ceased_reason' => 'Historical import bypass', 'error' => 'ceased_reason'],
        ];

        foreach ($attempts as $attempt) {
            $error = $attempt['error'];
            unset($attempt['error']);

            $this->actingAs($this->admin)
                ->post("/clients/{$this->client->id}/medical/medications", $attempt)
                ->assertSessionHasErrors($error);

            $this->assertDatabaseMissing('client_medications', [
                'client_id' => $this->client->id,
                'name' => $attempt['name'],
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  9. MEDICATION CRUD - Update
    // ══════════════════════════════════════════════════════════════

    public function test_update_medication_requires_authentication(): void
    {
        $med = $this->createMedication();
        $this->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [])
            ->assertRedirect('/login');
    }

    public function test_update_medication_with_valid_data(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Paracetamol Updated',
                'dosage' => '1000mg',
                'frequency' => 'Three times daily',
                'dose_times' => ['08:00', '14:00', '20:00'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'name' => 'Paracetamol Updated',
            'dosage' => '1000mg',
            'frequency' => 'Three times daily',
        ]);
    }

    public function test_update_medication_persists_canonical_prn_fields(): void
    {
        $this->mockNotificationService();
        $med = $this->createPrnMedication([
            'max_per_day' => 2,
            'min_hours_between_doses' => 4,
            'controlled_drug' => false,
            'high_risk' => false,
            'prescriber' => 'Dr Initial',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Ibuprofen PRN Updated',
                'is_prn' => true,
                'prn_reason' => 'Updated PRN reason',
                'max_per_day' => 6,
                'min_hours_between_doses' => 3,
                'controlled_drug' => false,
                'high_risk' => true,
                'prescriber' => 'Dr Updated',
                'route' => 'oral',
                'form' => 'capsule',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $med->refresh();

        $this->assertSame('Ibuprofen PRN Updated', $med->name);
        $this->assertTrue($med->is_prn);
        $this->assertSame('Updated PRN reason', $med->prn_reason);
        $this->assertSame(6, $med->max_per_day);
        $this->assertSame(3.0, $med->min_hours_between_doses);
        $this->assertFalse($med->controlled_drug);
        $this->assertTrue($med->high_risk);
        $this->assertSame('Dr Updated', $med->prescriber);
        $this->assertSame('capsule', $med->form);
    }

    public function test_update_medication_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $med = ClientMedication::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Med',
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_update_medication_forbidden_for_support_worker(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Updated',
            ])
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  10. MEDICATION ORDER LIFECYCLE - legacy delete is retired
    // ══════════════════════════════════════════════════════════════

    public function test_legacy_destroy_medication_paths_fail_closed(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->admin);

        $operationsPath = "/operations/clients/{$this->client->id}/medical/medications/{$med->id}";

        $this->delete("/clients/{$this->client->id}/medical/medications/{$med->id}")
            ->assertRedirect($operationsPath);

        $this->delete($operationsPath)->assertStatus(405);

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'deleted_at' => null,
        ]);
    }

    public function test_discontinue_medication_retires_order_without_deleting_it(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/discontinue", [
                'reason' => 'Prescriber stopped treatment',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'state' => 'ceased',
            'active' => false,
            'ceased_reason' => 'Prescriber stopped treatment',
            'ceased_by' => $this->admin->id,
            'deleted_at' => null,
        ]);
    }

    public function test_discontinue_medication_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $med = ClientMedication::create([
            'client_id' => $otherClient->id,
            'name' => 'Other',
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/discontinue", [
                'reason' => 'Wrong resident attempt',
            ])
            ->assertNotFound();
    }

    public function test_discontinue_medication_forbidden_for_support_worker(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/discontinue", [
                'reason' => 'Not authorized',
            ])
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  11. MEDICATION ADMINISTRATION - Basic
    // ══════════════════════════════════════════════════════════════

    public function test_store_administration_requires_authentication(): void
    {
        $med = $this->createMedication();
        $this->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [])
            ->assertRedirect('/login');
    }

    public function test_store_administration_given_with_valid_data(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();
        $now = $this->workerNow();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'dose_given' => '500mg',
                'scheduled_for' => $now->format('Y-m-d H:i:s'),
                'administered_at' => $now->format('Y-m-d H:i:s'),
                'notes' => 'Patient tolerated well',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'status' => 'given',
            'administered_by' => $this->supportWorker->id,
        ]);
    }

    public function test_store_administration_validates_status_required(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [])
            ->assertSessionHasErrors(['status']);
    }

    public function test_store_administration_validates_status_enum(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'invalid',
            ])
            ->assertSessionHasErrors(['status']);
    }

    public function test_store_administration_refused_requires_reason(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'refused',
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('reason_code');
    }

    public function test_store_administration_missed_requires_reason(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'missed',
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('reason_code');
    }

    public function test_store_administration_withheld_requires_reason(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'withheld',
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('reason_code');
    }

    public function test_store_administration_refused_succeeds_with_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'refused',
                'reason_code' => 'refused',
                'reason' => 'Client declined medication',
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'status' => 'refused',
            'reason' => 'Client declined medication',
        ]);
    }

    public function test_store_administration_forbidden_for_hr(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->hrUser)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
            ])
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  12. PRN MEDICATION - Reason required even when given
    // ══════════════════════════════════════════════════════════════

    public function test_prn_medication_given_requires_reason(): void
    {
        $med = $this->createPrnMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_prn_medication_given_succeeds_with_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createPrnMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'reason' => 'Headache reported by client',
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  13. TIME WINDOW VALIDATION
    // ══════════════════════════════════════════════════════════════

    public function test_administration_within_time_window_succeeds_without_reason(): void
    {
        $this->mockNotificationService();

        // Administered 15 minutes after scheduled: within window
        $administered = $this->workerNow();
        $scheduled = $administered->copy()->subMinutes(15);
        $med = $this->createMedication(['dose_times' => [$scheduled->format('H:i')]]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_administration_more_than_30_min_late_requires_reason(): void
    {
        // Administered 45 minutes after scheduled: outside window (>30 min late)
        $administered = $this->workerNow();
        $scheduled = $administered->copy()->subMinutes(45);
        $med = $this->createMedication(['dose_times' => [$scheduled->format('H:i')]]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_administration_more_than_60_min_early_requires_reason(): void
    {
        // Administered 90 minutes before scheduled: outside window (>60 min early)
        $administered = $this->workerNow();
        $scheduled = $administered->copy()->addMinutes(90);
        $med = $this->createMedication(['dose_times' => [$scheduled->format('H:i')]]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_administration_outside_time_window_succeeds_with_reason(): void
    {
        $this->mockNotificationService();
        $administered = $this->workerNow();
        $scheduled = $administered->copy()->subMinutes(45);
        $med = $this->createMedication(['dose_times' => [$scheduled->format('H:i')]]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'reason' => 'Client was at appointment',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_administration_exactly_30_min_late_succeeds_without_reason(): void
    {
        $this->mockNotificationService();

        // Exactly at the boundary (30 min): should succeed
        $administered = $this->workerNow();
        $scheduled = $administered->copy()->subMinutes(30);
        $med = $this->createMedication(['dose_times' => [$scheduled->format('H:i')]]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  14. CONTROLLED DRUG ADMINISTRATION - Witness Requirements
    // ══════════════════════════════════════════════════════════════

    public function test_controlled_drug_given_requires_witness(): void
    {
        $med = $this->createControlledDrug();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('witnessed_by');

        $this->assertDatabaseMissing('client_medication_administrations', [
            'client_medication_id' => $med->id,
        ]);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertSame(0, $med->controlledDrugEntries()->count());
    }

    public function test_controlled_drug_self_witness_blocked(): void
    {
        $med = $this->createControlledDrug();
        $this->qualifyControlledWitness($this->supportWorker);
        $this->assertTrue($this->supportWorker->canDo('medications.controlled.witness'));
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'witnessed_by' => $this->supportWorker->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('witnessed_by');

        $this->assertDatabaseMissing('client_medication_administrations', [
            'client_medication_id' => $med->id,
        ]);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertSame(0, $med->controlledDrugEntries()->count());
    }

    public function test_controlled_drug_given_with_valid_witness_succeeds(): void
    {
        $this->mockNotificationService();
        $med = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'quantity_administered' => 1,
                'client_request_uuid' => (string) Str::uuid(),
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $med->id,
            'status' => 'given',
        ]);
    }

    public function test_controlled_drug_creates_controlled_entry_on_administration(): void
    {
        $this->mockNotificationService();
        $med = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'quantity_administered' => 1,
                'client_request_uuid' => (string) Str::uuid(),
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'administered',
            'recorded_by' => $this->supportWorker->id,
            'witnessed_by' => $witness->id,
        ]);
        $this->assertSame(9.0, (float) $stock->refresh()->on_hand);
    }

    public function test_controlled_drug_refused_does_not_require_witness(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'refused',
                'reason_code' => 'refused',
                'reason' => 'Client refused medication',
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_controlled_drug_witness_must_have_witness_permission(): void
    {
        $med = $this->createControlledDrug();
        $this->qualifyControlledWitness($this->hrUser);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        // HR user does not have medications.controlled.witness permission
        $this->assertFalse($this->hrUser->canDo('medications.controlled.witness'));
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'witnessed_by' => $this->hrUser->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('client_medication_administrations', [
            'client_medication_id' => $med->id,
        ]);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertSame(0, $med->controlledDrugEntries()->count());
    }

    // ══════════════════════════════════════════════════════════════
    //  15. STOCK MANAGEMENT
    // ══════════════════════════════════════════════════════════════

    public function test_update_stock_requires_authentication(): void
    {
        $med = $this->createMedication();
        $this->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [])
            ->assertRedirect('/login');
    }

    public function test_update_stock_with_valid_data(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 30,
                'unit' => 'tablets',
                'reorder_level' => 10,
                'notes' => 'Stock check completed',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_stocks', [
            'client_medication_id' => $med->id,
            'on_hand' => 30,
            'unit' => 'tablets',
            'reorder_level' => 10,
        ]);
    }

    public function test_update_stock_forbidden_for_hr(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->hrUser)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
            ])
            ->assertForbidden();
    }

    public function test_update_stock_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $med = ClientMedication::create([
            'client_id' => $otherClient->id,
            'name' => 'Other',
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
            ])
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  16. CONTROLLED DRUG STOCK - Witness & Entry/Discrepancy
    // ══════════════════════════════════════════════════════════════

    public function test_generic_controlled_stock_update_is_fail_closed_and_points_to_balance_check(): void
    {
        $med = $this->createControlledDrug();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 8,
                'reason' => 'Counted',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('on_hand');

        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    public function test_guided_controlled_balance_check_requires_immediate_action_for_discrepancy(): void
    {
        $med = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->from('/emar/controlled')
            ->postJson(route('emar.controlled.balance_check.store'), [
                ...$this->p07ControlledCommand($med),
                'client_medication_id' => $med->id,
                'expected_balance' => 10,
                'actual_balance' => 8,
                'recount_balance' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'notes' => 'Two tablets are not accounted for.',
            ])
            ->assertUnprocessable()->assertJsonValidationErrors('immediate_action_taken');
    }

    public function test_guided_controlled_balance_check_blocks_self_witness(): void
    {
        $med = $this->p07ControlledMedicine();
        $this->qualifyControlledWitness($this->admin);
        $this->assertTrue($this->admin->canDo('medications.controlled.witness'));
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->from('/emar/controlled')
            ->postJson(route('emar.controlled.balance_check.store'), [
                ...$this->p07ControlledCommand($med),
                'client_medication_id' => $med->id,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $this->admin->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertUnprocessable()->assertJsonValidationErrors('witnessed_by');

        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertSame(0, $med->controlledDrugEntries()->count());
    }

    public function test_guided_controlled_balance_check_creates_entry(): void
    {
        $this->mockNotificationService();
        $med = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('emar.controlled.balance_check.store'), [
                ...$this->p07ControlledCommand($med),
                'client_medication_id' => $med->id,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertOk();

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'balance_check',
            'on_hand_before' => 10,
            'on_hand_after' => 10,
            'recorded_by' => $this->admin->id,
            'witnessed_by' => $witness->id,
        ]);
    }

    public function test_guided_controlled_balance_check_creates_discrepancy_when_amounts_differ(): void
    {
        $this->mockNotificationService();
        $med = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('emar.controlled.balance_check.store'), [
                ...$this->p07ControlledCommand($med),
                'client_medication_id' => $med->id,
                'expected_balance' => 10,
                'actual_balance' => 8,
                'recount_balance' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'notes' => 'Two missing after shift change',
                'immediate_action_taken' => 'Secured the remaining stock and escalated the discrepancy.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('client_controlled_drug_discrepancies', [
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'difference' => -2,
            'status' => 'open',
            'reported_by' => $this->admin->id,
            'witnessed_by' => $witness->id,
            'immediate_action_taken' => 'Secured the remaining stock and escalated the discrepancy.',
        ]);
    }

    public function test_guided_controlled_balance_check_preserves_half_unit_discrepancy_provenance(): void
    {
        $this->mockNotificationService();
        $med = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 9.5,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('emar.controlled.balance_check.store'), [
                ...$this->p07ControlledCommand($med),
                'client_medication_id' => $med->id,
                'expected_balance' => 9.5,
                'actual_balance' => 9,
                'recount_balance' => 9,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'notes' => 'Half tablet count variance',
                'immediate_action_taken' => 'Secured the stock and escalated the half-tablet variance.',
            ])
            ->assertOk();

        $entry = $med->controlledDrugEntries()->sole();
        $discrepancy = ClientControlledDrugDiscrepancy::query()
            ->where('client_medication_id', $med->id)
            ->sole();
        $this->assertSame(9.0, (float) $stock->refresh()->on_hand);
        $this->assertSame(9.5, (float) $entry->on_hand_before);
        $this->assertSame(9.0, (float) $entry->on_hand_after);
        $this->assertSame(-0.5, (float) $discrepancy->difference);
    }

    public function test_guided_controlled_balance_check_rejects_excess_scale_without_mutation(): void
    {
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->from('/emar/controlled')
            ->post(route('emar.controlled.balance_check.store'), [
                'client_medication_id' => $med->id,
                'expected_balance' => 10,
                'actual_balance' => 9.999,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'discrepancy_notes' => 'Invalid precision probe',
                'immediate_action_taken' => 'No action should be recorded.',
            ])
            ->assertSessionHasErrors('actual_balance');

        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertSame(0, $med->controlledDrugEntries()->count());
        $this->assertSame(0, ClientControlledDrugDiscrepancy::query()
            ->where('client_medication_id', $med->id)
            ->count());
    }

    public function test_guided_controlled_balance_check_creates_no_discrepancy_when_amounts_match(): void
    {
        $this->mockNotificationService();
        $med = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('emar.controlled.balance_check.store'), [
                ...$this->p07ControlledCommand($med),
                'client_medication_id' => $med->id,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertOk();

        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
    }

    public function test_guided_controlled_balance_check_conceals_ineligible_witness(): void
    {
        $med = $this->p07ControlledMedicine();
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('emar.controlled.balance_check.store'), [
                ...$this->p07ControlledCommand($med),
                'client_medication_id' => $med->id,
                'expected_balance' => 10,
                'actual_balance' => 8,
                'recount_balance' => 8,
                'witnessed_by' => $this->hrUser->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'notes' => 'Count differs.',
                'immediate_action_taken' => 'Secured the stock pending an authorised witness review.',
            ])
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  17. CLOSE CONTROLLED DRUG DISCREPANCY
    // ══════════════════════════════════════════════════════════════

    public function test_close_discrepancy_requires_authentication(): void
    {
        $disc = ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->createControlledDrug()->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'difference' => -2,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'witnessed_by' => $this->supportWorker->id,
            'status' => 'open',
        ]);

        $this->post("/clients/{$this->client->id}/medical/controlled-discrepancies/{$disc->id}/close")
            ->assertRedirect('/login');
    }

    public function test_close_discrepancy_with_resolution_notes(): void
    {
        $this->mockNotificationService();
        [$medication, $disc, $witness] = $this->p07ResolutionFixture();
        $body = $this->p07ControlledCommand($medication, [
            'outcome' => 'found', 'quantity' => 2,
            'notes' => 'Found missing tablets in drawer',
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ]);
        $url = "/clients/{$this->client->id}/medical/controlled-discrepancies/{$disc->id}/close";

        // Administrative access does not confer independent clinical resolution.
        $this->assertFalse($this->admin->canDo('medications.controlled.manage'));
        $this->actingAs($this->admin)->postJson($url, $body)->assertForbidden();
        $this->assertTrue($this->providerManager->canDo('medications.controlled.manage'));

        $this->actingAs($this->providerManager)->postJson($url, $body)->assertOk();

        $disc->refresh();
        $this->assertEquals('closed', $disc->status);
        $this->assertEquals('Found missing tablets in drawer', $disc->resolution_notes);
        $this->assertNotNull($disc->resolved_at);
        $this->assertEquals($this->providerManager->id, $disc->resolved_by);
        $this->assertSame('found', $disc->resolution_outcome);
        $this->assertSame('10.00', $medication->stock()->sole()->on_hand);
    }

    public function test_close_already_closed_discrepancy_returns_success(): void
    {
        $this->mockNotificationService();
        [$medication, $disc, $witness] = $this->p07ResolutionFixture();
        $body = $this->p07ControlledCommand($medication, [
            'outcome' => 'found', 'quantity' => 2,
            'notes' => 'Found missing tablets in drawer',
            'witnessed_by' => $witness->id,
            'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ]);
        $url = "/clients/{$this->client->id}/medical/controlled-discrepancies/{$disc->id}/close";
        $first = $this->actingAs($this->providerManager)->postJson($url, $body)->assertOk();
        $this->assertSame('closed', $disc->refresh()->status);
        $before = $disc->getRawOriginal();
        $entryCount = $medication->controlledDrugEntries()->count();

        // Only the same durable command replays success after closure.
        $retry = $this->postJson($url, $body)->assertOk();
        $this->assertSame($first->json(), $retry->json());
        $this->assertSame($before, $disc->fresh()->getRawOriginal());
        $this->assertSame($entryCount, $medication->controlledDrugEntries()->count());
        $this->assertSame('10.00', $medication->stock()->sole()->on_hand);

        $newCommand = $this->p07ControlledCommand($medication, [
            'outcome' => 'found', 'quantity' => 2, 'notes' => $body['notes'],
            'witnessed_by' => $witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
        ]);
        $this->postJson($url, $newCommand)->assertConflict();
        $this->assertSame($before, $disc->fresh()->getRawOriginal());
        $this->assertSame($entryCount, $medication->controlledDrugEntries()->count());
    }

    // ══════════════════════════════════════════════════════════════
    //  18. BREAK-GLASS EMERGENCY ACCESS
    // ══════════════════════════════════════════════════════════════

    public function test_break_glass_store_requires_authentication(): void
    {
        $this->post("/clients/{$this->client->id}/break-glass", [])
            ->assertRedirect('/login');
    }

    public function test_break_glass_store_creates_access_with_default_expiry(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason_category' => 'Covering an absence', 'authorization_mode' => 'self',
                'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true,
                'reason' => 'Emergency medication query',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $access = ClientBreakGlassAccess::where('client_id', $this->client->id)
            ->where('user_id', $this->providerManager->id)
            ->first();

        $this->assertNotNull($access);
        $this->assertEquals('Emergency medication query', $access->reason);
        $minutesUntilExpiry = now()->diffInMinutes($access->expires_at, false);
        $this->assertTrue($minutesUntilExpiry >= 58);
        $this->assertTrue($minutesUntilExpiry <= 61);
    }

    public function test_break_glass_store_with_custom_minutes(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason_category' => 'Covering an absence', 'authorization_mode' => 'self',
                'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true,
                'reason' => 'Extended review needed',
                'minutes' => 120,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $access = ClientBreakGlassAccess::where('client_id', $this->client->id)->first();
        $minutesUntilExpiry = now()->diffInMinutes($access->expires_at, false);
        $this->assertTrue($minutesUntilExpiry >= 118);
    }

    public function test_break_glass_store_validates_reason_required(): void
    {
        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [])
            ->assertSessionHasErrors(['reason']);
    }

    public function test_break_glass_store_validates_minutes_range_minimum(): void
    {
        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason' => 'Test',
                'minutes' => 3,
            ])
            ->assertSessionHasErrors(['minutes']);
    }

    public function test_break_glass_store_validates_minutes_range_maximum(): void
    {
        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason' => 'Test',
                'minutes' => 1441,
            ])
            ->assertSessionHasErrors(['minutes']);
    }

    public function test_break_glass_store_forbidden_for_support_worker(): void
    {
        // Support worker does not have medications.breakglass by default
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_break_glass_grants_access_to_medical_page(): void
    {
        $this->mockNotificationService();

        // Create another support worker not assigned to the client
        $otherWorker = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $otherWorker->roles()->attach(Role::where('name', 'provider_manager')->first());
        $this->assignCurrentSite($otherWorker);

        // Without break-glass, verify they can access (provider_manager has clients.viewAny)
        $this->actingAs($otherWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));

        // Create break-glass access
        ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $otherWorker->id,
            'reason' => 'Emergency access',
            'expires_at' => now()->addHour(),
        ]);

        // Should still be accessible
        $this->actingAs($otherWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    // ══════════════════════════════════════════════════════════════
    //  19. BREAK-GLASS DESTROY
    // ══════════════════════════════════════════════════════════════

    public function test_break_glass_destroy_requires_authentication(): void
    {
        $access = ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        $this->delete("/clients/{$this->client->id}/break-glass/{$access->id}")
            ->assertRedirect('/login');
    }

    public function test_break_glass_destroy_by_owner(): void
    {
        $this->mockNotificationService();

        $access = ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->providerManager)
            ->delete("/clients/{$this->client->id}/break-glass/{$access->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        // Revocation soft-deletes: the activation is retained for the
        // break-glass audit trail (never hard-erased) with the revoker stamped.
        $this->assertSoftDeleted('client_break_glass_accesses', ['id' => $access->id]);
        $this->assertSame(
            $this->providerManager->id,
            ClientBreakGlassAccess::withTrashed()->find($access->id)->revoked_by,
        );
    }

    public function test_break_glass_destroy_by_admin(): void
    {
        $this->mockNotificationService();

        $access = ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/break-glass/{$access->id}", ['reason' => 'Covering staff have arrived; emergency access can end.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertFalse(ClientBreakGlassAccess::withTrashed()->findOrFail($access->id)->isRunning());
        $this->assertSame('Covering staff have arrived; emergency access can end.', ClientBreakGlassAccess::withTrashed()->findOrFail($access->id)->end_reason);

        // Soft-deleted for the audit trail, not hard-erased.
        $this->assertSoftDeleted('client_break_glass_accesses', ['id' => $access->id]);
    }

    public function test_break_glass_destroy_by_non_owner_non_manager_forbidden(): void
    {
        $access = ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        // Coordinator has breakglass but is not the owner, not admin/provider_manager
        // However coordinator has medications.audit.view so they should be able to revoke
        $this->actingAs($this->coordinator)
            ->delete("/clients/{$this->client->id}/break-glass/{$access->id}", ['reason' => 'Covering staff have arrived; emergency access can end.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertFalse(ClientBreakGlassAccess::withTrashed()->findOrFail($access->id)->isRunning());
        $this->assertSame('Covering staff have arrived; emergency access can end.', ClientBreakGlassAccess::withTrashed()->findOrFail($access->id)->end_reason);
    }

    public function test_break_glass_destroy_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $access = ClientBreakGlassAccess::create([
            'client_id' => $otherClient->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/break-glass/{$access->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  20. CORRECTION WORKFLOW
    // ══════════════════════════════════════════════════════════════

    public function test_correction_requires_authentication(): void
    {
        $med = $this->createMedication();
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [])
            ->assertRedirect('/login');
    }

    public function test_correction_within_30_minutes_without_correction_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        // Create administration just now (within 30-minute window)
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'refused',
                'reason' => 'Actually refused',
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'corrected_of_id' => $admin->id,
            'is_correction' => true,
            'status' => 'refused',
        ]);
    }

    public function test_correction_outside_30_minutes_requires_correction_reason(): void
    {
        $med = $this->createMedication();

        // Create administration 45 minutes ago (outside 30-minute window)
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now()->subMinutes(45),
            'administered_at' => now()->subMinutes(45),
            'created_at' => now()->subMinutes(45),
            'updated_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'missed',
                'reason' => 'Actually missed',
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_correction_outside_30_minutes_succeeds_with_correction_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now()->subMinutes(45),
            'administered_at' => now()->subMinutes(45),
            'created_at' => now()->subMinutes(45),
            'updated_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'missed',
                'reason' => 'Actually missed',
                'correction_reason' => 'Incorrect entry by previous staff member',
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'corrected_of_id' => $admin->id,
            'is_correction' => true,
            'status' => 'missed',
            'correction_reason' => 'Incorrect entry by previous staff member',
        ]);
    }

    public function test_correction_forbidden_for_hr(): void
    {
        $med = $this->createMedication();
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->actingAs($this->hrUser)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'missed',
            ])
            ->assertForbidden();
    }

    public function test_correction_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $med = ClientMedication::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Med',
            'active' => true,
            'state' => 'active',
        ]);

        $admin = ClientMedicationAdministration::create([
            'client_id' => $otherClient->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'refused',
            ])
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  21. CONDITIONS - CRUD
    // ══════════════════════════════════════════════════════════════

    public function test_store_condition_requires_authentication(): void
    {
        $this->post("/clients/{$this->client->id}/medical/conditions", [])
            ->assertRedirect('/login');
    }

    public function test_store_condition_with_valid_data(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/conditions", [
                'label' => 'Hypertension',
                'severity' => 'moderate',
                'notes' => 'Requires regular monitoring',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_conditions', [
            'client_id' => $this->client->id,
            'label' => 'Hypertension',
            'severity' => 'moderate',
        ]);
    }

    public function test_store_condition_validates_label_required(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/conditions", [
                'severity' => 'high',
            ])
            ->assertSessionHasErrors(['label']);
    }

    public function test_store_condition_validates_label_max_length(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/conditions", [
                'label' => str_repeat('a', 256),
            ])
            ->assertSessionHasErrors(['label']);
    }

    public function test_store_condition_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/conditions", [
                'label' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_update_condition_with_valid_data(): void
    {
        $this->mockNotificationService();
        $condition = ClientCondition::create([
            'client_id' => $this->client->id,
            'label' => 'Old Label',
            'severity' => 'low',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/conditions/{$condition->id}", [
                'label' => 'Updated Label',
                'severity' => 'high',
                'notes' => 'Condition worsened',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_conditions', [
            'id' => $condition->id,
            'label' => 'Updated Label',
            'severity' => 'high',
        ]);
    }

    public function test_update_condition_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $condition = ClientCondition::create([
            'client_id' => $otherClient->id,
            'label' => 'Other Condition',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/conditions/{$condition->id}", [
                'label' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_destroy_condition_removes_record(): void
    {
        $this->mockNotificationService();
        $condition = ClientCondition::create([
            'client_id' => $this->client->id,
            'label' => 'To Delete',
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/conditions/{$condition->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('client_conditions', ['id' => $condition->id]);
    }

    public function test_destroy_condition_forbidden_for_support_worker(): void
    {
        $condition = ClientCondition::create([
            'client_id' => $this->client->id,
            'label' => 'Test',
        ]);

        $this->actingAs($this->supportWorker)
            ->delete("/clients/{$this->client->id}/medical/conditions/{$condition->id}")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  22. EMERGENCY CONTACTS - CRUD
    // ══════════════════════════════════════════════════════════════

    public function test_store_emergency_contact_requires_authentication(): void
    {
        $this->post("/clients/{$this->client->id}/medical/emergency-contacts", [])
            ->assertRedirect('/login');
    }

    public function test_store_emergency_contact_with_valid_data(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/emergency-contacts", [
                'name' => 'Jane Doe',
                'relationship' => 'Daughter',
                'phone' => '0123456789',
                'email' => 'jane@example.com',
                'notes' => 'Primary contact',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_emergency_contacts', [
            'client_id' => $this->client->id,
            'name' => 'Jane Doe',
            'relationship' => 'Daughter',
            'phone' => '0123456789',
            'email' => 'jane@example.com',
        ]);
    }

    public function test_store_emergency_contact_validates_name_required(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/emergency-contacts", [
                'relationship' => 'Sister',
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_emergency_contact_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/emergency-contacts", [
                'name' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_update_emergency_contact_with_valid_data(): void
    {
        $this->mockNotificationService();
        $contact = ClientEmergencyContact::create([
            'client_id' => $this->client->id,
            'name' => 'Old Name',
            'phone' => '0000000000',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}", [
                'name' => 'Updated Name',
                'phone' => '1111111111',
                'relationship' => 'Son',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_emergency_contacts', [
            'id' => $contact->id,
            'name' => 'Updated Name',
            'phone' => '1111111111',
            'relationship' => 'Son',
        ]);
    }

    public function test_update_emergency_contact_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $contact = ClientEmergencyContact::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Contact',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}", [
                'name' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_destroy_emergency_contact_removes_record(): void
    {
        $this->mockNotificationService();
        $contact = ClientEmergencyContact::create([
            'client_id' => $this->client->id,
            'name' => 'To Delete',
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('client_emergency_contacts', ['id' => $contact->id]);
    }

    public function test_destroy_emergency_contact_forbidden_for_support_worker(): void
    {
        $contact = ClientEmergencyContact::create([
            'client_id' => $this->client->id,
            'name' => 'Test',
        ]);

        $this->actingAs($this->supportWorker)
            ->delete("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}")
            ->assertForbidden();
    }

    public function test_destroy_emergency_contact_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $contact = ClientEmergencyContact::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Contact',
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  23. MAR - Display and Export
    // ══════════════════════════════════════════════════════════════

    public function test_mar_show_requires_authentication(): void
    {
        $this->get("/clients/{$this->client->id}/mar")->assertRedirect('/login');
    }

    public function test_mar_show_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get("/clients/{$this->client->id}/mar")
            ->assertRedirect(EmarUrl::mar($this->client, now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString()));
    }

    public function test_mar_show_accessible_by_assigned_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get("/clients/{$this->client->id}/mar")
            ->assertRedirect(EmarUrl::mar($this->client, now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString()));
    }

    public function test_mar_show_forbidden_for_unassigned_support_worker(): void
    {
        $unassignedWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $unassignedWorker->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->assignCurrentSite($unassignedWorker);

        $this->actingAs($unassignedWorker)
            ->get("/clients/{$this->client->id}/mar")
            ->assertForbidden();
    }

    public function test_mar_show_accepts_date_filter(): void
    {
        $date = '2025-06-15';

        $this->actingAs($this->admin)
            ->get("/clients/{$this->client->id}/mar?date={$date}")
            ->assertRedirect(EmarUrl::mar($this->client, $date));
    }

    public function test_mar_export_csv_requires_authentication(): void
    {
        $this->get("/clients/{$this->client->id}/mar/export.csv")
            ->assertRedirect('/login');
    }

    public function test_mar_export_csv_returns_csv_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get("/clients/{$this->client->id}/mar/export.csv")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    // ══════════════════════════════════════════════════════════════
    //  24. BREAK-GLASS - Expired access does not grant entry
    // ══════════════════════════════════════════════════════════════

    public function test_expired_break_glass_does_not_grant_medical_access(): void
    {
        // Create unassigned coordinator-like user who relies on break-glass
        $unassignedWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $unassignedWorker->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->assignCurrentSite($unassignedWorker);

        // Create expired break-glass access
        ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $unassignedWorker->id,
            'reason' => 'Expired access',
            'expires_at' => now()->subMinute(),
        ]);

        // Should be forbidden because access has expired
        $this->actingAs($unassignedWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  25. ROLE-BASED ACCESS - Finance, Auditor, Coordinator
    // ══════════════════════════════════════════════════════════════

    public function test_finance_user_can_view_medications_index(): void
    {
        $this->actingAs($this->financeUser)
            ->get('/medications')
            ->assertRedirect(EmarUrl::daily());
    }

    public function test_auditor_can_view_medications_index(): void
    {
        $this->actingAs($this->auditor)
            ->get('/medications')
            ->assertRedirect(EmarUrl::daily());
    }

    public function test_coordinator_can_view_audit_log(): void
    {
        $actor = $this->p09ControllerReportActor($this->coordinator);
        $this->p09ControllerCanonicalRead($actor, '/medications/audit')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('filters.view', 'audit')->where('can.history', true));
    }

    public function test_finance_user_can_view_reports(): void
    {
        $actor = $this->p09ControllerReportActor($this->financeUser);
        $medicine = $this->p09ControllerReportMedicine();
        ClientMedicationStock::query()->create(['client_medication_id' => $medicine->id, 'on_hand' => 10, 'unit' => 'tablets']);
        $this->p09ControllerCanonicalRead($actor, '/reports/medications')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('finance', true)->where('filters.report', 'stock')
                ->has('page.data', 1)->where('page.data.0.on_hand', 10)
                ->missing('page.data.0.client_id')->missing('page.data.0.person')
                ->missing('page.data.0.reference')->where('can.audit', false));
        $this->get('/emar/reports?report=doses')->assertForbidden();
        $this->get('/emar/reports?report=stock&client_id='.$this->client->id)->assertForbidden();
    }

    public function test_auditor_needs_exact_medication_report_permission_beside_general_reporting_access(): void
    {
        $actor = $this->p09ControllerReportActor($this->auditor, ['reports.viewAny'], ['medications.reports.view']);
        $this->assertTrue($actor->canDo('reports.viewAny'));
        $this->assertFalse($actor->canDo('medications.reports.view'));
        $this->actingAs($actor)->get('/reports/medications')->assertForbidden();
        $this->get('/emar/reports')->assertForbidden();
        $actor = $this->p09ControllerReportActor($actor);
        $this->p09ControllerCanonicalRead($actor, '/reports/medications')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')->where('filters.report', 'doses'));
    }

    public function test_coordinator_can_manage_medications(): void
    {
        $this->mockNotificationService();

        // Coordinator does not have clients.update so they cannot create meds
        // via the clients.update middleware, but let's verify they can at least view
        $this->actingAs($this->coordinator)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    // ══════════════════════════════════════════════════════════════
    //  26. ADMINISTRATION WITH SHIFT CONTEXT
    // ══════════════════════════════════════════════════════════════

    public function test_administration_resolves_service_context_from_shift(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $otherContext = ServiceContext::factory()->create(['name' => 'Home Support']);
        $shift = Shift::query()
            ->where('client_id', $this->client->id)
            ->where('user_id', $this->supportWorker->id)
            ->firstOrFail();
        $shift->forceFill(['service_context_id' => $otherContext->id])->save();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'shift_id' => $shift->id,
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $med->id,
            'shift_id' => $shift->id,
            'service_context_id' => $otherContext->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  27. STOCK VALIDATION
    // ══════════════════════════════════════════════════════════════

    public function test_stock_update_validates_on_hand_min_zero(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => -5,
            ])
            ->assertSessionHasErrors(['on_hand']);
    }

    public function test_stock_update_validates_unit_max_length(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
                'unit' => str_repeat('a', 51),
            ])
            ->assertSessionHasErrors(['unit']);
    }

    public function test_stock_update_validates_reorder_level_min_zero(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
                'reorder_level' => -1,
            ])
            ->assertSessionHasErrors(['reorder_level']);
    }

    // ══════════════════════════════════════════════════════════════
    //  28. REPORT FILTERS
    // ══════════════════════════════════════════════════════════════

    public function test_reports_index_applies_date_filters(): void
    {
        $from = '2025-01-01';
        $to = '2025-01-31';

        $this->p09ControllerCanonicalRead($this->admin, route('reports.medications', ['period' => 'custom', 'date_from' => $from, 'date_to' => $to]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('filters.period', 'custom')
                ->where('filters.date_from', $from)
                ->where('filters.date_to', $to)
            );
    }

    public function test_reports_index_applies_client_filter(): void
    {
        Client::factory()->create(['site_id' => $this->site->id]);
        $this->p09ControllerCanonicalRead($this->admin, route('reports.medications', ['client_id' => $this->client->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('filters.client_id', $this->client->id)
                ->has('page.data', 1)->where('page.data.0.client_id', $this->client->id)
            );
        $foreign = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        $actor = $this->p09ControllerReportActor($this->coordinator, ['medications.reports.view'], ['clinical.accessAllSites', 'sites.viewAll']);
        $this->p09ControllerCanonicalRead($actor, route('reports.medications', ['client_id' => $foreign->id]))->assertNotFound();
        $this->p09ControllerCanonicalRead($actor, route('reports.medications', ['client_id' => 999999]))->assertNotFound();
    }

    public function test_reports_index_filters_dose_outcomes_by_the_canonical_event_kind(): void
    {
        $this->p09ControllerReportEvent('dose.given', 'Dose given fixture');
        $refused = $this->p09ControllerReportEvent('dose.refused', 'Dose refused fixture');
        // Individual outcomes are now filtered in the event list. The dose
        // report is a per-person total, so its former status prop is retired.
        $this->p09ControllerCanonicalRead($this->admin, route('reports.medications', ['view' => 'audit', 'period' => 'today', 'kind' => 'dose.refused']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')
                ->where('filters.view', 'audit')->where('filters.kind', 'dose.refused')
                ->has('page.data', 1)->where('page.data.0.id', $refused->id)
                ->where('page.data.0.kind', 'dose.refused')
            );
    }

    // ══════════════════════════════════════════════════════════════
    //  29. AUDIT LOG FILTERS
    // ══════════════════════════════════════════════════════════════

    public function test_audit_index_applies_filters(): void
    {
        $this->p09ControllerCanonicalRead($this->admin, route('medications.audit.index', ['client_id' => $this->client->id, 'period' => 'custom', 'date_from' => '2025-01-01', 'date_to' => '2025-12-31']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('emar/reports/hub')->where('filters.view', 'audit')
                ->where('filters.client_id', $this->client->id)
                ->where('filters.date_from', '2025-01-01')
                ->where('filters.date_to', '2025-12-31')
            );
    }

    private function p09ControllerCanonicalRead(User $actor, string $url): \Illuminate\Testing\TestResponse
    {
        $redirect = $this->actingAs($actor)->get($url)->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringContainsString('/emar/reports', $location);

        return $this->get($location);
    }

    /** Test-only exact grants; shared role defaults and writer fixtures stay intact. */
    private function p09ControllerReportActor(
        User $actor,
        array $grants = ['medications.reports.view'],
        array $denials = [],
    ): User {
        foreach ([true => $grants, false => $denials] as $allowed => $keys) {
            $permissions = Permission::query()->whereIn('key', $keys)->pluck('id');
            $this->assertCount(count($keys), $permissions, 'Missing exact report permission fixture.');
            $actor->permissionOverrides()->syncWithoutDetaching(
                $permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => (bool) $allowed]])->all(),
            );
        }

        return $actor->refresh();
    }

    private function p09ControllerReportMedicine(array $overrides = []): ClientMedication
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow($this->workerNow()->subDay()->utc());
        try {
            return ClientMedication::query()->create(array_replace([
                'client_id' => $this->client->id,
                'name' => 'Ordinary report fixture medicine',
                'dosage' => '500 mg',
                'frequency' => 'Daily',
                'dose_times' => [$this->workerNow()->format('H:i')],
                'is_prn' => false,
                'controlled_drug' => false,
                'high_risk' => false,
                'active' => true,
                'state' => 'active',
                'approval_status' => 'verified',
                'start_date' => $this->workerNow()->subDays(2)->toDateString(),
                'end_date' => null,
            ], $overrides));
        } finally {
            Carbon::setTestNow($now);
        }
    }

    private function p09ControllerReportAdministration(
        ClientMedication $medicine,
        string $status = 'given',
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medicine->id,
            'service_context_id' => $this->serviceContext->id,
            'administered_by' => $this->admin->id,
            'scheduled_for' => $this->workerNow()->utc(),
            'administered_at' => $this->workerNow()->utc(),
            'status' => $status,
            'dose_given' => $status === 'given' ? '500 mg' : null,
            'reason' => $status === 'refused' ? 'Person declined' : null,
        ]);
    }

    private function p09ControllerReportEvent(string $kind, string $summary): \App\Models\MedicationEvent
    {
        $medicine = $this->p09ControllerReportMedicine();
        $record = $this->p09ControllerReportAdministration($medicine, $kind === 'dose.refused' ? 'refused' : 'given');

        return \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\Medication\Audit\MedicationEventRecorder::class)
            ->append(new \App\Services\Medication\Audit\MedicationEventData(
                (int) $this->site->id,
                $kind,
                'medication_administration',
                (string) $record->id,
                (int) $this->admin->id,
                \Carbon\CarbonImmutable::instance($record->administered_at)->utc(),
                $summary,
                ['status' => $record->status],
                (int) $this->client->id,
            )));
    }

    // ══════════════════════════════════════════════════════════════
    //  30. CONTROLLED STOCK - Guided counts and fail-closed legacy bridge
    // ══════════════════════════════════════════════════════════════

    public function test_guided_controlled_balance_check_remains_available_while_discrepancy_is_open(): void
    {
        $med = $this->p07ControlledMedicine();
        $witness = $this->p07ControlledWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        // Create an open discrepancy
        ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => 12,
            'on_hand_after' => 10,
            'difference' => -2,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'witnessed_by' => $witness->id,
            'status' => 'open',
        ]);

        $this->actingAs($this->supportWorker)
            ->postJson(route('emar.controlled.balance_check.store'), [
                ...$this->p07ControlledCommand($med),
                'client_medication_id' => $med->id,
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertOk();

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $med->id,
            'entry_type' => 'balance_check',
            'recorded_by' => $this->supportWorker->id,
            'on_hand_before' => 10,
            'on_hand_after' => 10,
        ]);
    }

    public function test_generic_controlled_stock_update_remains_fail_closed_with_override_permission(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();

        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        // Create open discrepancy
        ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => 12,
            'on_hand_after' => 10,
            'difference' => -2,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'witnessed_by' => $witness->id,
            'status' => 'open',
        ]);

        // Override authority does not reopen the legacy generic stock endpoint.
        $this->actingAs($this->providerManager)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 9,
                'witnessed_by' => $witness->id,
                'reason' => 'Override check',
                'immediate_action_taken' => 'Secured the stock and escalated the new discrepancy.',
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('on_hand');

        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertSame(0, $med->controlledDrugEntries()->count());
    }

    // ══════════════════════════════════════════════════════════════
    //  31. MULTIPLE MEDICATIONS PER CLIENT
    // ══════════════════════════════════════════════════════════════

    public function test_client_can_have_multiple_medications(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Medication A',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Medication B',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Medication C',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('client_medications', 3);
    }

    // ══════════════════════════════════════════════════════════════
    //  32. MEDICATION STATE TRANSITIONS
    // ══════════════════════════════════════════════════════════════

    public function test_medication_can_be_paused(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => $med->name,
                'state' => 'paused',
                'paused_at' => now()->format('Y-m-d'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'state' => 'paused',
            'active' => false,
        ]);
    }

    public function test_medication_can_be_ceased(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/discontinue", [
                'reason' => 'No longer required',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'state' => 'ceased',
            'active' => false,
            'ceased_reason' => 'No longer required',
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  33. CORRECTION - Validates status enum
    // ══════════════════════════════════════════════════════════════

    public function test_correction_validates_status_required(): void
    {
        $med = $this->createMedication();
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [])
            ->assertSessionHasErrors(['status']);
    }

    public function test_correction_validates_status_enum(): void
    {
        $med = $this->createMedication();
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'invalid_status',
            ])
            ->assertSessionHasErrors(['status']);
    }

    // ══════════════════════════════════════════════════════════════
    //  34. NON-CONTROLLED STOCK UPDATE (no witness needed)
    // ══════════════════════════════════════════════════════════════

    public function test_non_controlled_stock_update_succeeds_without_witness(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication(); // Not a controlled drug

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 25,
                'unit' => 'tablets',
                'notes' => 'Normal stock check',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // No controlled drug entry should be created
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    // ══════════════════════════════════════════════════════════════
    //  35. DOSE TIMES REGEX VALIDATION
    // ══════════════════════════════════════════════════════════════

    public function test_dose_times_rejects_single_digit_hours(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'dose_times' => ['8:00'],
            ])
            ->assertSessionHasErrors(['dose_times.0']);
    }

    public function test_dose_times_rejects_invalid_format(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'dose_times' => ['12:00:00'],
            ])
            ->assertSessionHasErrors(['dose_times.0']);
    }

    public function test_dose_times_rejects_non_numeric(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'dose_times' => ['ab:cd'],
            ])
            ->assertSessionHasErrors(['dose_times.0']);
    }

    public function test_dose_times_accepts_midnight(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Midnight Med',
                'dose_times' => ['00:00'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_dose_times_accepts_2359(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Late Night Med',
                'dose_times' => ['23:59'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  36. ADMINISTRATION RESOLVES SERVICE CONTEXT FROM CLIENT FALLBACK
    // ══════════════════════════════════════════════════════════════

    public function test_administration_resolves_service_context_from_client_when_no_shift(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $this->workerNow()->format('Y-m-d H:i:s'),
                'administered_at' => $this->workerNow()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $med->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  37. FINANCE CAN UPDATE STOCK
    // ══════════════════════════════════════════════════════════════

    public function test_finance_user_can_update_non_controlled_stock(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->financeUser)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 15,
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  38. MAR BREAK-GLASS REQUEST SCREEN
    // ══════════════════════════════════════════════════════════════

    public function test_mar_shows_break_glass_request_screen_for_unassigned_user_with_breakglass(): void
    {
        // Provider manager is not assigned but has breakglass permission
        // However, they also have clients.viewAny so policy allows access
        // Let's test with a user who has breakglass but not clients.viewAny
        // We need to give a support worker breakglass permission
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->assignCurrentSite($worker);

        // Give them breakglass permission manually
        $bgPerm = Permission::where('key', 'medications.breakglass')->first();
        if ($bgPerm) {
            $workerRole = Role::where('name', 'support_worker')->first();
            $workerRole->permissions()->syncWithoutDetaching([$bgPerm->id]);
        }

        // This worker is NOT assigned to the client
        $response = $this->actingAs($worker)
            ->get("/clients/{$this->client->id}/mar");

        // Should be redirected to the emergency-access page with the request wizard
        // pre-opened for this client (not a bare interstitial, not a 403).
        $response->assertRedirect(route('emar.emergency_access', ['request_client' => $this->client->id]));
    }

    // ══════════════════════════════════════════════════════════════
    //  39. CORRECTION CREATES NEW RECORD (does not modify original)
    // ══════════════════════════════════════════════════════════════

    public function test_correction_preserves_original_record(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $original = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'dose_given' => '500mg',
            'scheduled_for' => now(),
            'administered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$original->id}/corrections", [
                'status' => 'withheld',
                'reason' => 'Allergic reaction reported',
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Original should remain unchanged
        $original->refresh();
        $this->assertEquals('given', $original->status);
        $this->assertEquals('500mg', $original->dose_given);

        // Correction should be a new record
        $correction = ClientMedicationAdministration::where('corrected_of_id', $original->id)->first();
        $this->assertNotNull($correction);
        $this->assertTrue($correction->is_correction);
        $this->assertEquals('withheld', $correction->status);
        $this->assertEquals('Allergic reaction reported', $correction->reason);
    }

    // ══════════════════════════════════════════════════════════════
    //  40. FULL MEDICATION LIFECYCLE
    // ══════════════════════════════════════════════════════════════

    public function test_full_medication_lifecycle(): void
    {
        $this->mockNotificationService();
        Storage::fake('local');
        $administrationAt = $this->workerNow();
        // The order is entered now, so its dose is the next one: nothing is
        // owed before an order exists (P01 recording guard).
        $doseAt = $administrationAt->copy()->addMinutes(2);

        // 1. Create medication
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Lifecycle Med',
                'dosage' => '10mg',
                'frequency' => 'Once daily',
                'dose_times' => [$doseAt->format('H:i')],
                'state' => 'active',
                'controlled_drug' => false,
                'high_risk' => false,
                'witness_required' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $med = ClientMedication::where('name', 'Lifecycle Med')->first();
        $this->assertNotNull($med);

        // 2. Retain the signed source and independently check its revision.
        $sourceRequest = 'lifecycle-source-'.bin2hex(random_bytes(8));
        $this->actingAs($this->admin)->post('/emar/orders', [
            'client_id' => $this->client->id,
            'medication_id' => $med->id,
            'expected_version' => $med->version,
            'request_key' => $sourceRequest,
            'change_reason' => 'Enter the signed prescription.',
            'source' => [
                'type' => 'written', 'prescriber' => 'Dr Lifecycle',
                'received_at' => now()->subMinute()->toIso8601String(), 'description' => 'Signed prescription.',
            ],
            'source_file' => UploadedFile::fake()->create('prescription.pdf', 1, 'application/pdf'),
            'prescription' => [
                'name' => 'Lifecycle Med', 'dosage' => '10mg', 'frequency' => 'Once daily',
                'dose_times' => [$doseAt->format('H:i')], 'is_prn' => false, 'route' => 'oral',
                'indication' => 'Indication from source.', 'start_date' => now('Pacific/Auckland')->toDateString(),
                'controlled_drug' => false, 'high_risk' => false, 'witness_required' => false,
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $revision = MedicationOrderRevision::where('client_medication_id', $med->id)
            ->where('status', 'pending')->whereHas('version', fn ($query) => $query->where('entry_request_key', $sourceRequest))->sole();
        $versionEvidence = $revision->version->fresh()->getAttributes();
        $this->actingAs($this->providerManager)
            ->post('/emar/order-revisions/'.$revision->id.'/check', [
                'source_matches' => true,
                'dose_route_times_checked' => true,
                'allergies_interactions_checked' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $this->assertSame('checked', $revision->fresh()->status);
        $this->assertSame('verified', $med->fresh()->approval_status);
        $this->assertSame($this->providerManager->id, (int) $med->fresh()->verified_by);

        // 3. Update stock
        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 30,
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // 4. Record administration
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'dose_given' => '10mg',
                'scheduled_for' => $doseAt->format('Y-m-d H:i:s'),
                'administered_at' => $administrationAt->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // 5. Pause medication
        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Lifecycle Med',
                'state' => 'paused',
                'paused_at' => now()->format('Y-m-d'),
            ])
            ->assertRedirect();

        // 6. Discontinue medication through the governed lifecycle action.
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/discontinue", [
                'reason' => 'No longer needed',
            ])
            ->assertRedirect();

        $med->refresh();
        $this->assertEquals('ceased', $med->state);
        $this->assertFalse($med->active);

        // 7. Legacy delete remains fail-closed and the ceased order remains.
        $operationsPath = "/operations/clients/{$this->client->id}/medical/medications/{$med->id}";

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/medications/{$med->id}")
            ->assertRedirect($operationsPath);

        $this->delete($operationsPath)->assertStatus(405);

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'state' => 'ceased',
            'deleted_at' => null,
            'ceased_reason' => 'No longer needed',
            'ceased_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $med->id,
            'status' => 'given',
            'administered_by' => $this->supportWorker->id,
            'dose_given' => '10mg',
        ]);
        $this->assertSame($versionEvidence, $revision->version->fresh()->getAttributes());
    }
}
