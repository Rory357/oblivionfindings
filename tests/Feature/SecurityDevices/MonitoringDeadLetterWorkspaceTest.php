<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Jobs\ReplayMonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

if (getenv('MONITORING_USE_PREBUILT_TEST_DATABASE') === '1') {
    $databasePath = getenv('DB_DATABASE');
    if (getenv('APP_ENV') !== 'testing'
        || getenv('DB_CONNECTION') !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || ! is_file($databasePath)
    ) {
        throw new RuntimeException(
            'MONITORING_USE_PREBUILT_TEST_DATABASE requires APP_ENV=testing, DB_CONNECTION=sqlite, and an existing file-backed database.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

final class MonitoringDeadLetterWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(HandleInertiaRequests::class);

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        config()->set('monitoring.signing', [
            'active_key_id' => 'workspace-test-key',
            'keys' => [
                'workspace-test-key' => base64_encode(str_repeat("\x2a", SODIUM_CRYPTO_AUTH_KEYBYTES)),
            ],
        ]);
    }

    public function test_operator_sees_only_authorised_safe_delivery_evidence_and_can_replay_or_discard(): void
    {
        Queue::fake();
        $allowedSite = Site::factory()->create([
            'name' => 'Allowed delivery Site',
            'archived' => false,
            'archived_at' => null,
        ]);
        $hiddenSite = Site::factory()->create([
            'name' => 'Hidden delivery Site',
            'archived' => false,
            'archived_at' => null,
        ]);
        $operator = $this->viewer($allowedSite, [
            'securityDevices.events.view',
            'securityDevices.integrations.manage',
        ]);
        $allowed = $this->letter(
            messageId: '018f0000-0000-7000-8000-000000000201',
            site: $allowedSite,
            payload: ['site_id' => $allowedSite->id],
        );
        $this->letter(
            messageId: '018f0000-0000-7000-8000-000000000202',
            site: $allowedSite,
            payload: ['site_id' => $hiddenSite->id],
        );
        $invalid = MonitoringDeadLetter::create([
            'message_id' => '018f0000-0000-7000-8000-000000000203',
            'consumer' => 'event-projector',
            'source' => 'untrusted',
            'sequence' => 1,
            'idempotency_key' => 'invalid-delivery:1',
            'reason_code' => 'invalid_signature',
            'reason_message' => 'Envelope authentication failed.',
            'envelope_bytes' => '{not-json',
            'site_id' => $allowedSite->id,
        ]);

        $this->assertContains(
            $allowedSite->id,
            app(SecurityDevicesAccessService::class)->accessibleSiteIds($operator),
        );

        // S&D rebuild C6: the monitoring page's delivery list is gone (server repair, not a staff task:
        // plan "Setup › Hubs › Server check"); replay and discard stay on their routes.
        $this->actingAs($operator)->get('/security-devices/monitoring?tab=collection')->assertRedirect('/security-devices/alerts');

        $this->actingAs($operator)
            ->post("/security-devices/monitoring/dead-letters/{$allowed->id}/replay", [
                'reason' => 'Missing sequence restored and verified.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        Queue::assertPushed(ReplayMonitoringDeadLetter::class);
        $this->assertNotNull($allowed->fresh()->replay_requested_at);

        $this->actingAs($operator)
            ->post("/security-devices/monitoring/dead-letters/{$invalid->id}/discard", [
                'reason' => 'Invalid authentication evidence confirmed.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertNotNull($invalid->fresh()->resolved_at);
    }

    public function test_non_operator_gets_no_dead_letter_counts_rows_or_mutation_access(): void
    {
        $site = Site::factory()->create(['archived' => false, 'archived_at' => null]);
        $viewer = $this->viewer($site, ['securityDevices.events.view']);
        $letter = $this->letter(
            messageId: '018f0000-0000-7000-8000-000000000211',
            site: $site,
            payload: ['site_id' => $site->id],
        );

        $this->actingAs($viewer)
            ->get('/security-devices/monitoring?tab=collection')
            ->assertRedirect('/security-devices/alerts');

        $this->actingAs($viewer)
            ->post("/security-devices/monitoring/dead-letters/{$letter->id}/replay", [
                'reason' => 'Attempt recovery',
            ])
            ->assertForbidden();
    }

    /** @param array<string, mixed> $payload */
    private function letter(string $messageId, Site $site, array $payload): MonitoringDeadLetter
    {
        $at = CarbonImmutable::parse('2026-07-27T01:02:03.456789Z');
        $bytes = app(RuntimeEnvelopeCodec::class)->encode(new RuntimeEnvelope(
            schemaVersion: 1,
            messageId: $messageId,
            type: RuntimeMessageType::Event,
            source: 'collector:workspace-test',
            sequence: (int) substr($messageId, -2),
            occurredAt: $at,
            ingestedAt: $at,
            idempotencyKey: 'workspace:'.$messageId,
            traceId: '018f0000-0000-7000-8000-000000000299',
            payload: $payload,
        ));

        return MonitoringDeadLetter::create([
            'message_id' => $messageId,
            'consumer' => 'event-projector',
            'source' => 'collector:workspace-test',
            'sequence' => (int) substr($messageId, -2),
            'idempotency_key' => 'workspace:'.$messageId,
            'reason_code' => 'sequence_gap',
            'reason_message' => 'Expected an earlier sequence.',
            'envelope_bytes' => $bytes,
            'site_id' => $site->id,
        ]);
    }

    /** @param list<string> $permissions */
    private function viewer(Site $site, array $permissions): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $permissionIds = Permission::query()
            ->whereIn('key', ['securityDevices.viewAny', ...$permissions])
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($permissionIds);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $viewer;
    }
}
