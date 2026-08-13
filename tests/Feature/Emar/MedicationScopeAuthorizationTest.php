<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BreakGlassAccessEvent;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Site $site;

    private ServiceContext $serviceContext;

    private Client $client;

    private ClientMedication $medication;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-14 09:30:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Medication scope test',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->worker->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'support_worker')->firstOrFail()->id,
        ]);
        $this->grantPermissions($this->worker, [
            'clients.viewAssigned',
            'medications.administer.record',
            'medications.orders.manage',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today(),
            'expiry_date' => today()->addYear(),
        ]);

        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        $this->client->supportWorkers()->syncWithoutDetaching([$this->worker->id]);
        $this->shift = $this->activeShift($this->client);
        $this->medication = $this->scheduledMedication($this->client);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scheduled_recording_rejects_a_nonexistent_cell_and_an_off_shift_action(): void
    {
        $this->actingAs($this->worker)
            ->post('/meds/today/record', $this->scheduledPayload([
                'scheduled_for' => now()->addMinutes(15)->toIso8601String(),
            ]))
            ->assertNotFound();

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(0, $this->administrationTimelineCount());

        $this->shift->delete();

        $this->actingAs($this->worker)
            ->post('/meds/today/record', $this->scheduledPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(0, $this->administrationTimelineCount());
    }

    public function test_a_shift_for_another_resident_at_the_same_site_does_not_authorize_the_action(): void
    {
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $this->shift->update(['client_id' => $otherClient->id]);

        $this->actingAs($this->worker)
            ->post('/meds/today/record', $this->scheduledPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_a_completed_shift_that_does_not_cover_the_offline_action_time_is_rejected(): void
    {
        $this->shift->forceFill([
            'status' => 'completed',
            'actual_starts_at' => now()->subHours(3),
            'actual_ends_at' => now()->subHour(),
            'completed_by' => $this->worker->id,
        ])->save();

        $this->actingAs($this->worker)
            ->post('/meds/today/record', $this->scheduledPayload([
                'captured_offline_at' => now()->toIso8601String(),
                'queued_offline' => true,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_guided_round_rejects_a_medication_from_another_site_before_any_effect(): void
    {
        $round = $this->round();
        $otherSite = Site::factory()->create(['is_active' => true]);
        $otherClient = Client::factory()->create([
            'site_id' => $otherSite->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $otherMedication = $this->scheduledMedication($otherClient);

        $this->actingAs($this->worker)
            ->postJson(
                "/emar/rounds/{$round->id}/guided/items/{$otherMedication->id}",
                [
                    'status' => 'given',
                    'scheduled_for' => now()->toIso8601String(),
                ],
            )
            ->assertNotFound();

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(0, $this->administrationTimelineCount());
    }

    public function test_a_durable_replay_identifier_cannot_be_reused_for_another_round_medication(): void
    {
        $round = $this->round();
        $otherMedication = $this->scheduledMedication($this->client, ['name' => 'Second scheduled medicine']);
        $uuid = '145511d4-772d-42b5-8cfa-6e72426cfbd9';
        $payload = [
            'status' => 'given',
            'scheduled_for' => now()->toIso8601String(),
            'client_request_uuid' => $uuid,
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'scope-test-device',
            'queued_offline' => false,
        ];

        $this->actingAs($this->worker)
            ->postJson("/emar/rounds/{$round->id}/guided/items/{$this->medication->id}", $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->worker)
            ->postJson("/emar/rounds/{$round->id}/guided/items/{$otherMedication->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_field', 'client_request_uuid');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame(1, $this->administrationTimelineCount());
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $this->medication->id,
            'client_request_uuid' => $uuid,
        ]);
    }

    public function test_foreign_site_prn_effectiveness_id_is_not_disclosed_or_mutated(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true]);
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $otherPrn = ClientMedication::factory()->create([
            'client_id' => $otherClient->id,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $foreignAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $otherClient->id,
            'client_medication_id' => $otherPrn->id,
            'administered_by' => User::factory()->create()->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);

        $this->actingAs($this->worker)
            ->post('/meds/today/prn/effect', [
                'client_medication_administration_id' => $foreignAdministration->id,
                'effectiveness' => 'effective',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('medication_prn_effectiveness', 0);
        $this->assertDatabaseCount('break_glass_access_events', 0);
    }

    public function test_canonical_finite_break_glass_allows_and_audits_an_otherwise_off_shift_dose(): void
    {
        $this->shift->delete();
        $this->grantPermissions($this->worker, ['medications.breakglass']);
        $access = ClientBreakGlassAccess::query()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->worker->id,
            'reason' => 'Urgent clinical cover for the medication round',
            'reason_category' => 'Staff absence / cover',
            'authorization_mode' => 'self',
            'acknowledged_min_necessary' => true,
            'acknowledged_incident_report' => true,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $this->scheduledPayload())
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertNull($administration->shift_id);
        $this->assertDatabaseHas('break_glass_access_events', [
            'break_glass_access_id' => $access->id,
            'action' => 'recorded_dose',
        ]);
        $this->assertSame(1, BreakGlassAccessEvent::query()->count());
    }

    public function test_expired_or_overlong_break_glass_does_not_replace_a_current_assignment(): void
    {
        $this->shift->delete();
        $this->grantPermissions($this->worker, ['medications.breakglass']);
        ClientBreakGlassAccess::query()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->worker->id,
            'reason' => 'Expired cover',
            'expires_at' => now()->subMinute(),
        ]);
        $overlong = ClientBreakGlassAccess::query()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->worker->id,
            'reason' => 'Non-finite emergency cover',
            'authorization_mode' => 'self',
            'acknowledged_min_necessary' => true,
            'acknowledged_incident_report' => true,
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->worker)
            ->post('/meds/today/record', $this->scheduledPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('break_glass_access_events', 0);

        $overlong->delete();
        ClientBreakGlassAccess::query()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->worker->id,
            'reason' => 'Incomplete emergency declaration',
            'authorization_mode' => 'self',
            'acknowledged_min_necessary' => false,
            'acknowledged_incident_report' => true,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->actingAs($this->worker)
            ->post('/meds/today/record', $this->scheduledPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('break_glass_access_events', 0);
    }

    public function test_cease_order_resident_mismatch_and_medication_reassignment_are_rejected(): void
    {
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $foreignMedication = $this->scheduledMedication($otherClient, ['name' => 'Foreign resident medicine']);

        $this->actingAs($this->worker)
            ->post('/emar/prescriptions', [
                'client_id' => $this->client->id,
                'client_medication_id' => $foreignMedication->id,
                'order_type' => 'cease',
                'prescriber_name' => 'Dr Scope',
                'medication_name' => $foreignMedication->name,
                'dose' => $foreignMedication->dosage,
                'route' => 'Oral',
                'frequency' => 'Once daily',
                'order_date' => today()->toDateString(),
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('medication_prescriber_orders', 0);
        $this->assertTrue((bool) $foreignMedication->fresh()->active);

        $this->actingAs($this->worker)
            ->put("/emar/medications/{$this->medication->id}", [
                'client_id' => $otherClient->id,
                'medication_name' => 'Illicit reassignment',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('client_id');

        $this->assertSame($this->client->id, (int) $this->medication->fresh()->client_id);
        $this->assertNotSame('Illicit reassignment', $this->medication->fresh()->name);

        $order = MedicationPrescriberOrder::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Scope',
            'medication_name' => $this->medication->name,
            'dose' => $this->medication->dosage,
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
        ]);
        $replacement = $this->scheduledMedication($this->client, ['name' => 'Replacement medicine']);

        $this->actingAs($this->worker)
            ->put("/emar/prescriptions/{$order->id}", [
                'client_medication_id' => $replacement->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('client_medication_id');

        $this->assertSame($this->medication->id, (int) $order->fresh()->client_medication_id);
    }

    public function test_concurrent_shift_reassignment_wins_before_the_administration_write(): void
    {
        Carbon::setTestNow();
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $actionAt = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfMinute();
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $this->medication->forceFill([
            'dose_times' => [$actionAt->format('H:i')],
            'start_date' => $actionAt->copy()->subMonth()->toDateString(),
        ])->save();
        $this->shift->forceFill([
            'starts_at' => $actionAt->copy()->subHour(),
            'ends_at' => $actionAt->copy()->addHours(2),
            'actual_starts_at' => $actionAt->copy()->subHour(),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ])->save();

        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-scope-ready-{$token}";
        $process = null;

        // Publish fixtures to an independent connection. Hold the assignment
        // row while the medication request begins, then commit reassignment
        // first: the waiting request must re-read the row and fail closed.
        $connection->commit();

        try {
            $connection->beginTransaction();
            Shift::query()->whereKey($this->shift->id)->lockForUpdate()->firstOrFail();
            $process = $this->startAssignmentRaceWorker(
                $readyPath,
                $database,
                $actionAt,
            );
            $this->waitForAssignmentRaceWorker($readyPath);
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Administration finished before the assignment lock was released.');

            DB::table('shifts')->where('id', $this->shift->id)->update([
                'client_id' => $otherClient->id,
                'updated_at' => now(),
            ]);
            $connection->commit();

            $process->wait();
            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getErrorOutput()) ?: 'The assignment concurrency worker failed.',
            );
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['success']);
            $this->assertSame(403, $result['status']);
            $this->assertDatabaseCount('client_medication_administrations', 0);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            if ($process?->isRunning()) {
                $process->stop(1);
            }
            if (is_file($readyPath)) {
                unlink($readyPath);
            }

            try {
                DB::table('break_glass_access_events')->whereIn(
                    'break_glass_access_id',
                    DB::table('client_break_glass_accesses')->where('user_id', $this->worker->id)->select('id'),
                )->delete();
                DB::table('client_break_glass_accesses')->where('user_id', $this->worker->id)->delete();
                DB::table('medication_prn_effectiveness')
                    ->whereIn('client_id', [$this->client->id, $otherClient->id])
                    ->delete();
                DB::table('client_medication_administrations')
                    ->whereIn('client_id', [$this->client->id, $otherClient->id])
                    ->delete();
                DB::table('shift_clients')->where('shift_id', $this->shift->id)->delete();
                DB::table('shifts')->where('id', $this->shift->id)->delete();
                DB::table('medication_competency_assessments')->where('user_id', $this->worker->id)->delete();
                DB::table('client_user')->whereIn('client_id', [$this->client->id, $otherClient->id])->delete();
                DB::table('medication_prescriber_orders')
                    ->whereIn('client_id', [$this->client->id, $otherClient->id])
                    ->delete();
                DB::table('client_medications')
                    ->whereIn('client_id', [$this->client->id, $otherClient->id])
                    ->delete();
                DB::table('timeline_events')
                    ->whereIn('client_id', [$this->client->id, $otherClient->id])
                    ->delete();
                DB::table('clients')->whereIn('id', [$this->client->id, $otherClient->id])->delete();
                DB::table('permission_user')->where('user_id', $this->worker->id)->delete();
                DB::table('role_user')->where('user_id', $this->worker->id)->delete();
                DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->delete();
                DB::table('users')->where('id', $this->worker->id)->delete();
                DB::table('sites')->where('id', $this->site->id)->delete();
                DB::table('service_contexts')->where('id', $this->serviceContext->id)->delete();
            } finally {
                $connection->beginTransaction();
                Carbon::setTestNow(Carbon::parse(
                    '2026-08-14 09:30:00',
                    config('app.worker_timezone', 'Pacific/Auckland'),
                ));
            }
        }
    }

    private function activeShift(Client $client): Shift
    {
        return Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $this->worker->id,
            'created_by' => $this->worker->id,
            'status' => 'in_progress',
        ]);
    }

    private function scheduledMedication(Client $client, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Scoped scheduled medicine',
            'dosage' => '1 tablet',
            'frequency' => 'Once daily',
            'dose_times' => ['09:30'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => today()->subMonth(),
        ], $overrides));
    }

    private function round(): MedicationRound
    {
        return MedicationRound::query()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'name' => 'Scoped morning round',
            'scheduled_time' => '09:30',
            'window_minutes' => 60,
            'round_date' => Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
            'status' => 'in_progress',
            'assigned_to' => $this->worker->id,
            'started_by' => $this->worker->id,
            'started_at' => now(),
            'total_medications' => 2,
        ]);
    }

    private function scheduledPayload(array $overrides = []): array
    {
        return array_merge([
            'client_medication_id' => $this->medication->id,
            'scheduled_for' => now()->toIso8601String(),
            'administered_at' => now()->toIso8601String(),
            'status' => 'given',
        ], $overrides);
    }

    private function administrationTimelineCount(): int
    {
        return (int) \DB::table('timeline_events')
            ->where('source_type', ClientMedicationAdministration::class)
            ->count();
    }

    private function startAssignmentRaceWorker(string $readyPath, string $database, Carbon $actionAt): Process
    {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$performer = App\Models\User::query()->findOrFail((int) $argv[2]);
$client = App\Models\Client::query()->findOrFail((int) $argv[3]);
$medication = App\Models\ClientMedication::query()->findOrFail((int) $argv[4]);
$actionAt = Carbon\Carbon::parse($argv[5]);
file_put_contents($argv[6], 'ready');
try {
    $result = $app->make(App\Services\Medication\MedicationScopeDecisionService::class)->forAdministration(
        $performer,
        $client,
        $medication,
        $actionAt,
        $actionAt,
        null,
        null,
        function (App\Services\Medication\MedicationScopeDecision $scope) use ($app, $actionAt): array {
            return $app->make(App\Services\EnhancedMarService::class)->recordAdministration(
                $scope->client,
                $scope->medication,
                [
                    'status' => 'given',
                    'scheduled_for' => $actionAt->toIso8601String(),
                    'administered_at' => $actionAt->toIso8601String(),
                    'scope_authorized' => true,
                ],
                $scope->performer->id,
                $scope->shiftId(),
            );
        },
    );
    echo json_encode([
        'success' => (bool) ($result['success'] ?? false),
        'status' => null,
        'error' => $result['error'] ?? null,
    ], JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    echo json_encode([
        'success' => false,
        'status' => $exception->getStatusCode(),
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $this->worker->id,
                (string) $this->client->id,
                (string) $this->medication->id,
                $actionAt->toIso8601String(),
                $readyPath,
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function waitForAssignmentRaceWorker(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The assignment concurrency worker did not start.');
            }

            usleep(10_000);
        }
    }

    /** @param array<int, string> $permissionKeys */
    private function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissions = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissions);
        $user->unsetRelation('permissionOverrides');
        $user->unsetRelation('roles');
    }
}
