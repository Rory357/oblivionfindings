<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAllergy;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\MedicationIncidentIntegrationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationsSafetyOverrideAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private ClientMedication $medication;

    private User $worker;

    private User $manager;

    private User $witness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();

        $site = Site::factory()->create(['name' => 'Safety override test site']);
        $context = ServiceContext::factory()->create([
            'name' => 'Safety override residential',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);

        $this->worker = $this->makeActor('support_worker', $site);
        $this->manager = $this->makeActor('provider_manager', $site);
        $this->witness = $this->makeActor('support_worker', $site, 'witness-secret');

        $this->client->supportWorkers()->syncWithoutDetaching([
            $this->worker->id,
            $this->manager->id,
            $this->witness->id,
        ]);

        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Amoxicillin',
            'dosage' => '500mg',
            'frequency' => 'Once daily',
            'dose_times' => ['09:00'],
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
        ]);

        ClientMedicationStock::query()->create([
            'client_medication_id' => $this->medication->id,
            'on_hand' => 10,
            'unit' => 'capsules',
        ]);

        MedicationAllergy::query()->create([
            'client_id' => $this->client->id,
            'allergen' => 'penicillin',
            'reaction' => 'Anaphylaxis',
            'severity' => 'life_threatening',
            'identified_date' => today(),
            'recorded_by' => $this->manager->id,
        ]);
    }

    public function test_safety_check_exposes_override_availability_from_the_dedicated_capability(): void
    {
        $url = $this->safetyCheckUrl($this->medication);

        $this->actingAs($this->worker, 'sanctum')
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('can_override_safety', false);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('can_override_safety', true);
    }

    public function test_legacy_boolean_override_is_rejected_without_any_partial_mutation(): void
    {
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->administrationUrl($this->medication), [
                ...$this->controlledDosePayload('180fbf23-52aa-43ab-bd95-e9428a0551dc'),
                'override_safety' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('override_safety');

        $this->assertNoNewControlledDoseEffects();
    }

    public function test_legacy_boolean_is_not_authority_when_the_shared_service_is_called_directly(): void
    {
        $result = app(EnhancedMarService::class)->recordAdministration(
            $this->client,
            $this->medication,
            [
                ...$this->controlledDosePayload('85ee4d1a-27ef-4a13-a1dc-9307b1c3eb45'),
                'override_safety' => true,
            ],
            $this->worker->id,
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['safety_check']['blocked']);
        $this->assertNoNewControlledDoseEffects();
    }

    public function test_ordinary_actor_cannot_forge_a_structured_override(): void
    {
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->administrationUrl($this->medication), [
                ...$this->controlledDosePayload('0fc0a43a-821f-4ea2-ae50-ac83cc168ec6'),
                'safety_override' => $this->overrideReason(),
            ])
            ->assertForbidden();

        $this->assertNoNewControlledDoseEffects();
    }

    public function test_privileged_actor_must_supply_a_structured_override_reason(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson($this->administrationUrl($this->medication), [
                ...$this->controlledDosePayload('efdf78a8-5bb0-4198-b36d-69016278fa6b'),
                'safety_override' => ['reason_code' => 'clinical_direction'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('safety_override.reason');

        $this->assertNoNewControlledDoseEffects();
    }

    public function test_privileged_override_is_bound_to_the_resident_medication_and_failed_check(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson($this->administrationUrl($this->medication), [
                ...$this->controlledDosePayload('51d4bf41-3934-4289-9b1f-e5452e4afdcf'),
                'safety_override' => $this->overrideReason(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $administrationId = (int) $response->json('administration.id');
        $audit = AuditLog::query()
            ->where('action', 'medications.safety_override.authorized')
            ->sole();

        $this->assertSame($administrationId, (int) $audit->auditable_id);
        $this->assertSame($this->client->id, (int) $audit->client_id);
        $this->assertSame($this->manager->id, (int) $audit->user_id);
        $this->assertSame($this->client->id, (int) $audit->meta['client_id']);
        $this->assertSame($this->medication->id, (int) $audit->meta['client_medication_id']);
        $this->assertSame('clinical_direction', $audit->meta['reason_code']);
        $this->assertSame($this->overrideReason()['reason'], $audit->meta['reason']);
        $this->assertContains('allergy', $audit->meta['failed_check_types']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $audit->meta['failed_check_fingerprint']);
        $this->assertStringContainsString('Safety override authorised', (string) ClientMedicationAdministration::findOrFail($administrationId)->notes);
        $this->assertSame(9, (int) $this->medication->stock()->value('on_hand'));
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertDatabaseCount('timeline_events', 1);
    }

    public function test_override_replay_cannot_duplicate_the_dose_stock_audit_or_timeline_event(): void
    {
        $payload = [
            ...$this->controlledDosePayload('5e89f8c3-c381-45c6-8c8a-e55685aad0a2'),
            'safety_override' => $this->overrideReason(),
        ];
        $url = $this->administrationUrl($this->medication);

        $firstId = (int) $this->actingAs($this->manager, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->json('administration.id');

        Cache::forget('emar:idempotency:administration:'.$payload['client_request_uuid']);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('administration.id', $firstId)
            ->assertJsonPath('sync.duplicate', true);

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(9, (int) $this->medication->stock()->value('on_hand'));
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.safety_override.authorized')->count());
        $this->assertDatabaseCount('timeline_events', 1);
    }

    public function test_concurrent_privileged_overrides_are_serialized_without_duplicate_effects(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-override-release-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."med-override-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."med-override-ready-b-{$token}",
        ];
        $attemptPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."med-override-attempt-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."med-override-attempt-b-{$token}",
        ];
        $payload = [
            ...$this->controlledDosePayload('6c0a6873-ab1a-4ec5-95d5-e590e4ca7bbc'),
            'safety_override' => $this->overrideReason(),
        ];
        $processes = [];
        $userIds = [$this->worker->id, $this->manager->id, $this->witness->id];
        $siteId = $this->client->site_id;
        $serviceContextId = $this->client->service_context_id;

        // Commit the RefreshDatabase fixtures so independent workers can see
        // them, then queue both calls behind the medication's FOR UPDATE lock.
        $connection->commit();

        try {
            $connection->beginTransaction();
            ClientMedication::query()->whereKey($this->medication->id)->lockForUpdate()->firstOrFail();

            foreach ([0, 1] as $index) {
                $processes[] = $this->startOverrideWorker(
                    $payload,
                    $readyPaths[$index],
                    $attemptPaths[$index],
                    $releasePath,
                    $database,
                );
            }

            $this->waitForWorkerFiles($readyPaths, 'Both safety-override workers did not become ready.');
            touch($releasePath);
            $this->waitForWorkerFiles($attemptPaths, 'Both safety-override workers did not reach the service call.');
            usleep(250_000);

            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A safety-override worker exited before the medication lock was released.',
                );
            }

            $connection->commit();

            $results = collect($processes)->map(function (Process $process): array {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A safety-override concurrency worker failed.',
                );

                return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            });

            $this->assertSame([true, true], $results->pluck('success')->sort()->values()->all());
            $this->assertSame([false, true], $results->pluck('duplicate')->sort()->values()->all());
            $this->assertCount(1, $results->pluck('administration_id')->unique());
            $this->assertDatabaseCount('client_medication_administrations', 1);
            $this->assertDatabaseCount('client_controlled_drug_entries', 1);
            $this->assertSame(9, (int) $this->medication->stock()->value('on_hand'));
            $this->assertSame(1, AuditLog::query()->where('action', 'medications.safety_override.authorized')->count());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            try {
                DB::table('timeline_events')->where('client_id', $this->client->id)->delete();
                DB::table('audit_logs')->where('client_id', $this->client->id)->delete();
                DB::table('client_controlled_drug_entries')->where('client_id', $this->client->id)->delete();
                DB::table('client_medication_administrations')->where('client_id', $this->client->id)->delete();
                DB::table('medication_allergies')->where('client_id', $this->client->id)->delete();
                DB::table('client_medication_stocks')->where('client_medication_id', $this->medication->id)->delete();
                DB::table('client_medications')->where('client_id', $this->client->id)->delete();
                DB::table('client_user')->where('client_id', $this->client->id)->delete();
                DB::table('clients')->where('id', $this->client->id)->delete();
                DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
                DB::table('role_user')->whereIn('user_id', $userIds)->delete();
                DB::table('hr_employee_profiles')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
                DB::table('sites')->where('id', $siteId)->delete();
                DB::table('service_contexts')->where('id', $serviceContextId)->delete();
            } finally {
                $connection->beginTransaction();
            }
        }
    }

    public function test_prn_over_limit_override_preserves_deduplicated_incident_behavior(): void
    {
        $prn = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'max_per_day' => 1,
            'active' => true,
            'state' => 'active',
        ]);

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $this->manager->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
            'dose_given' => '500mg',
        ]);

        $this->mock(MedicationIncidentIntegrationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handlePrnOverLimit')->once();
        });

        $payload = [
            'status' => 'given',
            'dose_given' => '500mg',
            'reason' => 'Breakthrough pain after repositioning',
            'administered_at' => now()->toIso8601String(),
            'client_request_uuid' => '58b4f099-2d31-4a0a-a6f0-94f92ed61602',
            'safety_override' => $this->overrideReason(),
        ];
        $url = $this->administrationUrl($prn);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk();

        Cache::forget('emar:idempotency:administration:'.$payload['client_request_uuid']);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $this->assertSame(2, ClientMedicationAdministration::query()->where('client_medication_id', $prn->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.safety_override.authorized')->count());
        $this->assertDatabaseCount('timeline_events', 1);
    }

    private function makeActor(string $roleName, Site $site, string $password = 'password'): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
            'password' => Hash::make($password),
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
        ]);

        return $user;
    }

    private function controlledDosePayload(string $uuid): array
    {
        return [
            'status' => 'given',
            'dose_given' => '500mg',
            'quantity_administered' => 1,
            'scheduled_for' => now()->startOfMinute()->toIso8601String(),
            'administered_at' => now()->toIso8601String(),
            'witnessed_by' => $this->witness->id,
            'witness_credential' => 'witness-secret',
            'client_request_uuid' => $uuid,
        ];
    }

    private function overrideReason(): array
    {
        return [
            'reason_code' => 'clinical_direction',
            'reason' => 'On-call prescriber directed administration after reviewing the allergy alert.',
        ];
    }

    private function administrationUrl(ClientMedication $medication): string
    {
        return "/api/medications/clients/{$this->client->id}/medications/{$medication->id}/administrations";
    }

    private function safetyCheckUrl(ClientMedication $medication): string
    {
        return "/api/medications/clients/{$this->client->id}/medications/{$medication->id}/safety-check";
    }

    private function assertNoNewControlledDoseEffects(): void
    {
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertSame(10, (int) $this->medication->stock()->value('on_hand'));
        $this->assertSame(0, AuditLog::query()->where('action', 'medications.safety_override.authorized')->count());
    }

    private function startOverrideWorker(
        array $payload,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
        string $database,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$client = App\Models\Client::query()->findOrFail((int) $argv[2]);
$medication = App\Models\ClientMedication::query()->findOrFail((int) $argv[3]);
$payload = json_decode(base64_decode($argv[5], true), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($argv[6], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[8])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the safety-override concurrency release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[7], 'attempting');
$result = $app->make(App\Services\EnhancedMarService::class)->recordAdministration(
    $client,
    $medication,
    $payload,
    (int) $argv[4],
);
echo json_encode([
    'success' => (bool) ($result['success'] ?? false),
    'duplicate' => (bool) ($result['duplicate'] ?? false),
    'administration_id' => $result['administration']->id ?? null,
    'error' => $result['error'] ?? null,
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $this->client->id,
                (string) $this->medication->id,
                (string) $this->manager->id,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                $readyPath,
                $attemptPath,
                $releasePath,
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

    private function waitForWorkerFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn (string $path) => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException($message);
            }

            usleep(10_000);
        }
    }
}
