<?php

declare(strict_types=1);

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Http\Controllers\HealthSafety\LoneWorkerController;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerAlert;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\Queclink\LocateNowService;
use App\Services\UserSiteAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LoneWorkerIntegrityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_shift_scope_uses_the_direct_site_before_the_client_site_fallback(): void
    {
        $localSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $viewer = $this->siteScopedUser($localSite, ['hazards.view']);
        $worker = $this->siteScopedUser($localSite);
        $localClient = Client::factory()->create(['site_id' => $localSite->id]);
        $poisonedShift = Shift::factory()->create([
            'site_id' => $foreignSite->id,
            'client_id' => $localClient->id,
            'user_id' => $worker->id,
        ]);

        $query = Shift::query()->whereKey($poisonedShift->id);
        app(UserSiteAccessService::class)->applyShiftScope($query, $viewer);

        $this->assertFalse($query->exists());
    }

    public function test_start_session_rejects_a_shift_whose_direct_site_conflicts_with_its_client(): void
    {
        $shiftSite = Site::factory()->create();
        $clientSite = Site::factory()->create();
        $coordinator = $this->hsLead();
        $worker = $this->siteUser($shiftSite);
        $client = Client::factory()->create(['site_id' => $clientSite->id]);
        $shift = Shift::factory()->create([
            'site_id' => $shiftSite->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);

        $this->actingAs($coordinator)
            ->from('/health-safety/lone-workers')
            ->post('/health-safety/lone-workers/sessions', [
                'user_id' => $worker->id,
                'shift_id' => $shift->id,
                'expected_end_at' => now()->addHours(2)->toDateTimeString(),
            ])
            ->assertRedirect('/health-safety/lone-workers')
            ->assertSessionHasErrors('shift_id');

        $this->assertDatabaseCount('lone_worker_sessions', 0);
    }

    public function test_start_session_rejects_a_shift_linked_to_another_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $coordinator = $this->siteScopedUser($site, ['hazards.view', 'hazards.manage']);
        $worker = $this->siteUser($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $otherSiteShift = Shift::factory()->create([
            'site_id' => $otherSite->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);

        $this->actingAs($coordinator)
            ->post('/health-safety/lone-workers/sessions', [
                'user_id' => $worker->id,
                'shift_id' => $otherSiteShift->id,
                'expected_end_at' => now()->addHours(2)->toDateTimeString(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('lone_worker_sessions', 0);
    }

    public function test_start_session_requires_a_non_null_shift_client_to_equal_the_selected_client_exactly(): void
    {
        $site = Site::factory()->create();
        $coordinator = $this->hsLead();
        $worker = $this->siteScopedUser($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shiftWithClient = Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
        $base = [
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'expected_end_at' => now()->addHours(2)->toDateTimeString(),
        ];

        $this->actingAs($coordinator)
            ->from('/health-safety/lone-workers')
            ->post('/health-safety/lone-workers/sessions', array_merge($base, [
                'shift_id' => $shiftWithClient->id,
            ]))
            ->assertRedirect('/health-safety/lone-workers')
            ->assertSessionHasErrors('client_id');

        $this->assertDatabaseCount('lone_worker_sessions', 0);
    }

    public function test_worker_self_check_in_still_rejects_a_poisoned_session_tuple(): void
    {
        $site = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $worker = $this->siteUser($site);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $session = $this->makeSession($worker, $site, [
            'client_id' => $foreignClient->id,
        ]);

        $this->actingAs($worker)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/check-in", [
                'status' => 'ok',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('lone_worker_check_ins', 0);
        $this->assertSame('active', $session->fresh()->status);
    }

    public function test_end_session_does_not_overwrite_an_emergency_that_won_the_race(): void
    {
        $site = Site::factory()->create();
        $coordinator = $this->hsLead();
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $staleSession = LoneWorkerSession::query()->findOrFail($session->id);
        $emergencyAt = now()->subMinute()->startOfSecond();

        LoneWorkerSession::query()->whereKey($session->id)->update([
            'status' => 'emergency',
            'emergency_triggered_at' => $emergencyAt,
            'emergency_notes' => 'Emergency won the race.',
        ]);

        $request = Request::create("/health-safety/lone-workers/sessions/{$session->id}/end", 'POST');
        $request->setUserResolver(fn (): User => $coordinator);
        app(LoneWorkerController::class)->endSession($request, $staleSession);

        $session->refresh();
        $this->assertSame('emergency', $session->status);
        $this->assertTrue($session->emergency_triggered_at->equalTo($emergencyAt));
        $this->assertNull($session->ended_at);
    }

    public function test_destroy_does_not_remove_a_session_that_became_an_emergency(): void
    {
        $site = Site::factory()->create();
        $coordinator = $this->hsLead();
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site, [
            'status' => 'completed',
            'ended_at' => now()->subMinutes(5),
        ]);
        $staleSession = LoneWorkerSession::query()->findOrFail($session->id);

        LoneWorkerSession::query()->whereKey($session->id)->update([
            'status' => 'emergency',
            'ended_at' => null,
            'emergency_triggered_at' => now()->subMinute(),
        ]);

        $request = Request::create("/health-safety/lone-workers/sessions/{$session->id}", 'DELETE');
        $request->setUserResolver(fn (): User => $coordinator);
        app(LoneWorkerController::class)->destroy($request, $staleSession);

        $this->assertNotSoftDeleted('lone_worker_sessions', ['id' => $session->id]);
        $this->assertSame('emergency', $session->fresh()->status);
    }

    public function test_canonical_acknowledgement_is_idempotent_and_leaves_historical_rows_read_only(): void
    {
        [$coordinator, , $historical, $canonical] = $this->historicalAndCanonicalAlertFixture('open');

        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/alerts/{$canonical->id}/acknowledge")
            ->assertRedirect();
        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/alerts/{$canonical->id}/acknowledge")
            ->assertRedirect();

        $this->assertSame('active', $historical->fresh()->status);
        $this->assertNull($historical->fresh()->acknowledged_at);
        $this->assertSame(ControlRoomAlert::STATUS_ACK, $canonical->fresh()->status);
        $this->assertSame($coordinator->id, $canonical->fresh()->acknowledged_by_user_id);
    }

    public function test_canonical_resolution_is_idempotent_and_leaves_historical_rows_read_only(): void
    {
        [$coordinator, , $historical, $canonical] = $this->historicalAndCanonicalAlertFixture('triaging');

        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/alerts/{$canonical->id}/resolve", [
                'resolution_notes' => 'Worker contacted and confirmed safe.',
            ])
            ->assertRedirect();
        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/alerts/{$canonical->id}/resolve", [
                'resolution_notes' => 'Worker contacted and confirmed safe.',
            ])
            ->assertRedirect();

        $this->assertSame('active', $historical->fresh()->status);
        $this->assertNull($historical->fresh()->resolved_at);
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $canonical->fresh()->status);
        $this->assertSame('resolved_in_health_safety', $canonical->fresh()->resolution_code);
    }

    public function test_resolving_the_canonical_emergency_resumes_the_session_before_it_can_end(): void
    {
        [$coordinator, $session, $historical, $canonical] = $this->historicalAndCanonicalAlertFixture('triaging');
        $triggeredAt = now()->subMinutes(5)->startOfSecond();
        $session->update([
            'status' => 'emergency',
            'emergency_triggered_at' => $triggeredAt,
            'emergency_notes' => 'Worker requested urgent help.',
            'last_check_in_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/alerts/{$canonical->id}/resolve", [
                'resolution_notes' => 'Worker contacted and confirmed safe.',
            ])
            ->assertRedirect();

        $session->refresh();
        $this->assertSame('active', $session->status);
        $this->assertTrue($session->emergency_triggered_at->equalTo($triggeredAt));
        $this->assertSame('Worker requested urgent help.', $session->emergency_notes);
        $this->assertSame('active', $historical->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $canonical->fresh()->status);

        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/end")
            ->assertRedirect();

        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_session_history_separates_canonical_operations_from_read_only_historical_evidence(): void
    {
        [$viewer, $session, $historical, $canonical] = $this->historicalAndCanonicalAlertFixture('open');

        $this->actingAs($viewer)
            ->get("/health-safety/lone-workers?session={$session->id}&period=all")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('detail.alerts', 1)
                ->where('detail.alerts.0.id', 'cr_'.$canonical->id)
                ->where('detail.alerts.0.source', 'control_room')
                ->has('detail.legacy_alert_history', 1)
                ->where('detail.legacy_alert_history.0.id', 'legacy_'.$historical->id)
                ->where('detail.legacy_alert_history.0.status', 'historical')
                ->where('detail.legacy_alert_history.0.source', 'legacy_history'));

        $this->assertSame('active', $historical->fresh()->status);
        $this->assertNull($historical->fresh()->acknowledged_at);
        $this->assertNull($historical->fresh()->resolved_at);
    }

    public function test_canonical_alert_rows_use_verified_session_relations_and_redact_poisoned_context(): void
    {
        $localSite = Site::factory()->create(['name' => 'Verified local site']);
        $foreignSite = Site::factory()->create(['name' => 'Foreign secret site']);
        $viewer = $this->hsLead(['hazards.view']);
        $localWorker = $this->siteUser($localSite);
        $localWorker->update(['name' => 'Verified local worker']);
        $foreignWorker = $this->siteUser($foreignSite);
        $foreignWorker->update(['name' => 'Foreign secret worker']);
        $localSession = $this->makeSession($localWorker, $localSite, [
            'location' => 'Verified local location',
        ]);
        $foreignSession = $this->makeSession($foreignWorker, $foreignSite, [
            'location' => 'Foreign secret location',
        ]);

        $verifiedAlert = $this->canonicalAlert($localSession, $localSite, [
            'worker_name' => 'Poisoned worker name',
            'site_name' => 'Poisoned site name',
            'location' => 'Poisoned location',
        ]);
        $redactedAlert = $this->canonicalAlert($foreignSession, $localSite, [
            'worker_name' => 'Foreign secret worker',
            'site_name' => 'Foreign secret site',
            'location' => 'Foreign secret location',
        ]);

        $this->actingAs($viewer)
            ->get('/health-safety/lone-workers?tab=alerts&period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts.data', function ($alerts) use ($verifiedAlert, $redactedAlert): bool {
                    $rows = collect($alerts)->keyBy('id');
                    $verified = $rows->get('cr_'.$verifiedAlert->id);
                    $redacted = $rows->get('cr_'.$redactedAlert->id);

                    return data_get($verified, 'session.user.name') === 'Verified local worker'
                        && data_get($verified, 'session.site.name') === 'Verified local site'
                        && data_get($verified, 'session.location') === 'Verified local location'
                        && data_get($redacted, 'session') === null;
                })
            );
    }

    public function test_alert_register_batches_session_security_hydration_for_a_full_page(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->hsLead(['hazards.view']);
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);

        foreach (range(1, 25) as $offset) {
            $alert = $this->canonicalAlert($session, $site);
            $alert->updateQuietly(['triggered_at' => now()->subSeconds($offset)]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($viewer)
            ->get('/health-safety/lone-workers?tab=alerts&period=all');
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('alerts.data', 25)
                ->where('alerts.data', fn ($alerts): bool => collect($alerts)
                    ->every(fn ($alert): bool => data_get($alert, 'session.id') === $session->id)));

        $sessionHydrationQueries = $queries
            ->filter(fn (array $entry): bool => str_starts_with(
                strtolower($entry['query']),
                'select * from `lone_worker_sessions`',
            ))
            ->values();

        $this->assertCount(
            1,
            $sessionHydrationQueries,
            'A full alert page must hydrate all canonical session identities in one scoped query.',
        );
        $this->assertStringContainsString(
            ' in (',
            strtolower($sessionHydrationQueries->first()['query']),
        );
    }

    public function test_alert_detail_rejects_non_lone_worker_and_tuple_poisoned_canonical_alerts(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->hsLead(['hazards.view']);
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $nonLoneWorker = ControlRoomAlert::factory()->create([
            'source' => 'sensor',
            'alert_type' => 'door_forced',
            'site_id' => $site->id,
            'context' => [
                'normalized_data' => [
                    'lone_worker_session_id' => $session->id,
                    'incident_id' => 999481,
                ],
            ],
        ]);
        $poisoned = $this->canonicalAlert($session, $otherSite, [
            'site_id' => $otherSite->id,
            'incident_id' => 999482,
        ]);

        foreach ([$nonLoneWorker, $poisoned] as $alert) {
            $this->actingAs($viewer)
                ->get("/health-safety/lone-workers?tab=alerts&period=all&alert=cr_{$alert->id}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('detail', null));
        }
    }

    public function test_alert_detail_uses_only_a_verified_relation_backed_incident_reference(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->hsLead(['hazards.view']);
        $worker = $this->siteUser($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $session = $this->makeSession($worker, $site, ['client_id' => $client->id]);
        $alert = $this->canonicalAlert($session, $site, ['incident_id' => 999999]);
        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $viewer->id,
            'control_room_alert_id' => $alert->id,
        ]);

        $this->actingAs($viewer)
            ->get("/health-safety/lone-workers?tab=alerts&period=all&alert=cr_{$alert->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.incident_id', $incident->id)
                ->where('detail.session.id', $session->id));
    }

    public function test_session_history_excludes_a_canonical_alert_with_a_poisoned_claimed_session_link(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->hsLead(['hazards.view']);
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $poisoned = $this->canonicalAlert($session, $otherSite, [
            'site_id' => $otherSite->id,
        ]);

        $this->actingAs($viewer)
            ->get("/health-safety/lone-workers?session={$session->id}&period=all")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.alerts', fn ($alerts): bool => ! collect($alerts)
                    ->contains('id', 'cr_'.$poisoned->id)));
    }

    public function test_session_history_uses_complete_client_shift_fallback_security_projection(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteScopedUser($site, ['hazards.view']);
        $worker = $this->siteScopedUser($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shift = Shift::factory()->create([
            'site_id' => null,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
        $session = $this->makeSession($worker, $site, [
            'site_id' => null,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
        ]);
        $valid = $this->canonicalAlert($session, $site, ['site_id' => $site->id]);
        $poisoned = $this->canonicalAlert($session, $site, ['site_id' => $otherSite->id]);

        $this->actingAs($viewer)
            ->get("/health-safety/lone-workers?session={$session->id}&period=all")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.alerts', function ($alerts) use ($valid, $poisoned): bool {
                    $ids = collect($alerts)->pluck('id');

                    return $ids->contains('cr_'.$valid->id)
                        && ! $ids->contains('cr_'.$poisoned->id);
                }));
    }

    public function test_panic_acknowledgement_does_not_transition_a_poisoned_claimed_session_alert(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->hsLead();
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $poisoned = $this->canonicalAlert($session, $otherSite, [
            'site_id' => $otherSite->id,
        ]);

        $this->actingAs($viewer)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/acknowledge-panic")
            ->assertRedirect();

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $poisoned->fresh()->status);
    }

    #[DataProvider('malformedControllerCanonicalIdentityProvider')]
    public function test_lone_worker_controller_canonical_identity_rejects_malformed_alert_detail_and_list_links(
        string $field,
        mixed $malformed,
        bool $withClient,
    ): void {
        [$viewer, $session, $alert] = $this->controllerCanonicalIdentityFixture(
            $field,
            $malformed,
            $withClient,
        );

        $this->actingAs($viewer)
            ->get("/health-safety/lone-workers?tab=alerts&period=all&alert=cr_{$alert->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail', null)
                ->where('alerts.data', function ($alerts) use ($alert): bool {
                    $row = collect($alerts)->firstWhere('id', 'cr_'.$alert->id);

                    return is_array($row)
                        && ($row['session'] ?? null) === null
                        && ! array_key_exists('context', $row)
                        && ! array_key_exists('normalized_data', $row);
                }));

        $this->assertSame('active', $session->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
    }

    public function test_lone_worker_controller_canonical_identity_excludes_malformed_history_links(): void
    {
        [$viewer, $session, $alert] = $this->controllerCanonicalIdentityFixture(
            'worker_user_id',
            '01',
            true,
        );

        $this->actingAs($viewer)
            ->get("/health-safety/lone-workers?session={$session->id}&period=all")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.alerts', fn ($alerts): bool => ! collect($alerts)
                    ->contains('id', 'cr_'.$alert->id)));

        $this->assertSame('active', $session->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
    }

    public function test_lone_worker_controller_canonical_identity_blocks_malformed_panic_alert_mutation(): void
    {
        [$actor, $session, $alert] = $this->controllerCanonicalIdentityFixture(
            'site_id',
            '01',
            true,
        );
        $originalContext = $alert->context;

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/acknowledge-panic")
            ->assertRedirect();

        $this->assertSame('active', $session->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
        $this->assertSame(
            $this->normalizeAssociativeKeyOrder($originalContext),
            $this->normalizeAssociativeKeyOrder($alert->fresh()->context),
        );
    }

    #[DataProvider('canonicalControllerIdentityMutationProvider')]
    public function test_lone_worker_controller_canonical_identity_blocks_malformed_operational_actions(
        string $action,
        array $payload,
    ): void {
        [$actor, $session, $canonical] = $this->controllerCanonicalIdentityFixture(
            'client_id',
            '0',
            false,
        );
        $originalContext = $canonical->context;

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/alerts/{$canonical->id}/{$action}", $payload)
            ->assertStatus(409);

        $this->assertSame('active', $session->fresh()->status);
        $this->assertSame(0, $session->alerts()->count());
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $canonical->fresh()->status);
        $this->assertNull($canonical->fresh()->acknowledged_at);
        $this->assertNull($canonical->fresh()->resolved_at);
        $this->assertSame(
            $this->normalizeAssociativeKeyOrder($originalContext),
            $this->normalizeAssociativeKeyOrder($canonical->fresh()->context),
        );
    }

    public function test_lone_worker_controller_canonical_identity_accepts_native_and_canonical_decimal_ids(): void
    {
        $site = Site::factory()->create();
        $worker = $this->siteUser($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $viewer = $this->hsLead(['hazards.view']);
        $session = $this->makeSession($worker, $site, ['client_id' => $client->id]);
        $native = $this->canonicalAlert($session, $site);
        $canonicalDecimal = $this->canonicalAlert($session, $site, [
            'lone_worker_session_id' => (string) $session->id,
            'worker_user_id' => (string) $worker->id,
            'site_id' => (string) $site->id,
            'client_id' => (string) $client->id,
        ]);

        foreach ([$native, $canonicalDecimal] as $alert) {
            $this->actingAs($viewer)
                ->get("/health-safety/lone-workers?tab=alerts&period=all&alert=cr_{$alert->id}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('detail.session.id', $session->id)
                    ->where('alerts.data', fn ($alerts): bool => data_get(
                        collect($alerts)->firstWhere('id', 'cr_'.$alert->id),
                        'session.id',
                    ) === $session->id));
        }

        $this->assertSame('active', $session->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $native->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $canonicalDecimal->fresh()->status);
    }

    public function test_session_update_reloads_actor_provenance_and_rejects_a_stale_site_assignment(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $actor = $this->siteScopedUser($site, ['hazards.manage']);
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $originalEnd = $session->expected_end_at->copy();
        $actor->hrEmployeeProfile()->update([
            'primary_site_id' => $otherSite->id,
        ]);
        $request = Request::create(
            "/health-safety/lone-workers/sessions/{$session->id}",
            'PATCH',
            ['expected_end_at' => now()->addHours(5)->toDateTimeString()],
        );
        $request->setUserResolver(fn (): User => $actor);

        $this->assertHttpForbidden(fn () => app(LoneWorkerController::class)
            ->updateSession($request, $session));

        $this->assertTrue($session->fresh()->expected_end_at->equalTo($originalEnd));
    }

    public function test_session_mutation_rejects_a_linked_shift_without_exact_worker_provenance(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $worker = $this->siteUser($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shift = Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => null,
        ]);
        $session = $this->makeSession($worker, $site, [
            'client_id' => $client->id,
            'shift_id' => $shift->id,
        ]);

        $this->actingAs($actor)
            ->patch("/health-safety/lone-workers/sessions/{$session->id}", [
                'expected_end_at' => now()->addHours(5)->toDateTimeString(),
            ])
            ->assertForbidden();

        $this->assertSame('active', $session->fresh()->status);
    }

    public function test_locate_now_reauthorizes_the_locked_actor_after_a_site_assignment_change(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $actor = $this->siteScopedUser($site, ['hazards.manage']);
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $this->pairWorkerTracker($worker, '861106048610001');
        $actor->hrEmployeeProfile()->update([
            'primary_site_id' => $otherSite->id,
        ]);
        $request = Request::create(
            "/health-safety/lone-workers/sessions/{$session->id}/locate",
            'POST',
        );
        $request->setUserResolver(fn (): User => $actor);

        $this->assertHttpForbidden(fn () => app(LoneWorkerController::class)->locateNow(
            $request,
            $session,
            app(LocateNowService::class),
        ));

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_locate_now_reauthorizes_the_locked_session_tuple_instead_of_stale_relations(): void
    {
        $site = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $actor = $this->hsLead();
        $worker = $this->siteUser($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $session = $this->makeSession($worker, $site, ['client_id' => $client->id]);
        $staleSession = LoneWorkerSession::query()
            ->with(['user', 'site', 'client', 'shift'])
            ->findOrFail($session->id);
        $this->pairWorkerTracker($worker, '861106048620001');
        LoneWorkerSession::query()->whereKey($session->id)->update([
            'client_id' => $foreignClient->id,
        ]);
        $request = Request::create(
            "/health-safety/lone-workers/sessions/{$session->id}/locate",
            'POST',
        );
        $request->setUserResolver(fn (): User => $actor);

        $this->assertHttpForbidden(fn () => app(LoneWorkerController::class)->locateNow(
            $request,
            $staleSession,
            app(LocateNowService::class),
        ));

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_locate_now_rolls_back_the_queued_command_when_strict_audit_writing_fails(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $this->pairWorkerTracker($worker, '861106048630001');

        $caught = $this->captureLoneWorkerAuditFailure(fn () => $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/locate"));

        $this->assertSame('Simulated Lone Worker strict audit failure.', $caught?->getMessage());
        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_tracker_operations_stop_when_personal_tracking_collection_has_stopped(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteScopedUser($site, ['hazards.view', 'hazards.manage']);
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $device = $this->pairWorkerTracker($worker, '861106048635001');
        DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->where('assignable_id', $worker->id)
            ->update([
                'collection_stopped_at' => now(),
                'collection_stop_reason' => 'consent_withdrawn',
            ]);

        $this->actingAs($actor)
            ->get("/health-safety/lone-workers?session={$session->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.tracker', null)
                ->where('detail.tracker_state', 'not_assigned'));

        $this->actingAs($actor)
            ->from('/health-safety/lone-workers')
            ->post("/health-safety/lone-workers/sessions/{$session->id}/locate")
            ->assertRedirect('/health-safety/lone-workers')
            ->assertSessionHas('error', 'This worker does not have a paired GPS tracker.');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_tracker_mutations_share_assignment_device_session_lock_order(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $device = $this->pairWorkerTracker($worker, '861106048640001');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/locate")
            ->assertRedirect();
        $this->assertTrackerSessionLockOrder(DB::getQueryLog(), 'Locate Now');

        $device->forceFill(['meta' => ['panic_active' => true]])->save();
        $this->canonicalAlert($session, $site);
        DB::flushQueryLog();
        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/acknowledge-panic")
            ->assertRedirect();
        $this->assertTrackerSessionLockOrder(DB::getQueryLog(), 'panic acknowledgement');
        DB::disableQueryLog();
    }

    public function test_all_session_safety_mutations_roll_back_when_strict_audit_writing_fails(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $worker = $this->siteScopedUser($site);
        $this->actingAs($actor);

        $beforeStart = LoneWorkerSession::query()->count();
        $caught = $this->captureLoneWorkerAuditFailure(fn () => $this->post(
            '/health-safety/lone-workers/sessions',
            [
                'user_id' => $worker->id,
                'site_id' => $site->id,
                'expected_end_at' => now()->addHours(2)->toDateTimeString(),
            ],
        ));
        $this->assertSame('Simulated Lone Worker strict audit failure.', $caught?->getMessage());
        $this->assertSame($beforeStart, LoneWorkerSession::query()->count());

        $update = $this->makeSession($worker, $site);
        $originalEnd = $update->expected_end_at->copy();
        $caught = $this->captureLoneWorkerAuditFailure(fn () => $this->patch(
            "/health-safety/lone-workers/sessions/{$update->id}",
            ['expected_end_at' => now()->addHours(6)->toDateTimeString()],
        ));
        $this->assertSame('Simulated Lone Worker strict audit failure.', $caught?->getMessage());
        $this->assertTrue($update->fresh()->expected_end_at->equalTo($originalEnd));

        $checkIn = $this->makeSession($worker, $site);
        $originalCheckIn = $checkIn->last_check_in_at->copy();
        $caught = $this->captureLoneWorkerAuditFailure(fn () => $this->post(
            "/health-safety/lone-workers/sessions/{$checkIn->id}/check-in",
            ['status' => 'ok'],
        ));
        $this->assertSame('Simulated Lone Worker strict audit failure.', $caught?->getMessage());
        $this->assertTrue($checkIn->fresh()->last_check_in_at->equalTo($originalCheckIn));
        $this->assertDatabaseMissing('lone_worker_check_ins', ['lone_worker_session_id' => $checkIn->id]);

        $this->mock(LoneWorkerSignalService::class, function ($mock): void {
            $mock->shouldReceive('emitEmergency')->once();
        });
        $emergency = $this->makeSession($worker, $site);
        $caught = $this->captureLoneWorkerAuditFailure(fn () => $this->post(
            "/health-safety/lone-workers/sessions/{$emergency->id}/emergency",
            ['emergency_notes' => 'Audit rollback test.'],
        ));
        $this->assertSame('Simulated Lone Worker strict audit failure.', $caught?->getMessage());
        $this->assertSame('active', $emergency->fresh()->status);
        $this->assertDatabaseMissing('lone_worker_alerts', ['lone_worker_session_id' => $emergency->id]);

        $end = $this->makeSession($worker, $site);
        $caught = $this->captureLoneWorkerAuditFailure(fn () => $this->post(
            "/health-safety/lone-workers/sessions/{$end->id}/end",
        ));
        $this->assertSame('Simulated Lone Worker strict audit failure.', $caught?->getMessage());
        $this->assertSame('active', $end->fresh()->status);
        $this->assertNull($end->fresh()->ended_at);

        $destroy = $this->makeSession($worker, $site, [
            'status' => 'completed',
            'ended_at' => now()->subMinute(),
        ]);
        $caught = $this->captureLoneWorkerAuditFailure(fn () => $this->delete(
            "/health-safety/lone-workers/sessions/{$destroy->id}",
        ));
        $this->assertSame('Simulated Lone Worker strict audit failure.', $caught?->getMessage());
        $this->assertNotSoftDeleted('lone_worker_sessions', ['id' => $destroy->id]);
    }

    public function test_emergency_and_canonical_signal_creation_are_one_atomic_transaction(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $processor = $this->mock(SignalProcessingService::class);
        $processor->shouldReceive('ingest')
            ->once()
            ->andThrow(new RuntimeException('Simulated canonical signal failure.'));
        $caught = null;

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)->post(
                "/health-safety/lone-workers/sessions/{$session->id}/emergency",
                ['emergency_notes' => 'Must roll back with canonical failure.'],
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame('Simulated canonical signal failure.', $caught?->getMessage());
        $this->assertSame('active', $session->fresh()->status);
        $this->assertNull($session->fresh()->emergency_triggered_at);
        $this->assertDatabaseMissing('lone_worker_alerts', ['lone_worker_session_id' => $session->id]);
    }

    public function test_signal_emission_rolls_back_partial_ingest_and_alert_effects_when_processing_fails(): void
    {
        $site = Site::factory()->create();
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $processor = $this->mock(SignalProcessingService::class);
        $processor->shouldReceive('ingest')
            ->once()
            ->andReturnUsing(fn (array $data): Signal => Signal::query()->create(array_merge(
                $data,
                ['status' => 'pending'],
            )));
        $processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (Signal $signal) use ($session, $site): never {
                ControlRoomAlert::factory()->create([
                    'source' => 'lone_worker',
                    'alert_type' => 'Lone Worker Emergency',
                    'site_id' => $site->id,
                    'client_id' => null,
                    'context' => [
                        'signal_id' => $signal->id,
                        'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY,
                        'normalized_data' => [
                            'lone_worker_session_id' => $session->id,
                            'worker_user_id' => $session->user_id,
                            'site_id' => $site->id,
                            'client_id' => null,
                        ],
                    ],
                ]);

                throw new RuntimeException('Simulated partial canonical processing failure.');
            });

        $caught = $this->captureRuntimeFailure(fn () => (new LoneWorkerSignalService(
            $processor,
            app(UserSiteAccessService::class),
        ))
            ->emitEmergency($session));

        $this->assertSame('Simulated partial canonical processing failure.', $caught?->getMessage());
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_real_lone_worker_dedup_preserves_each_session_tuple_and_the_enclosing_transaction(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $actor = $this->hsLead();
        $workerA = $this->siteScopedUser($site);
        $workerB = $this->siteScopedUser($site);
        $sessionA = $this->makeSession($workerA, $site, ['client_id' => $client->id]);
        $sessionB = $this->makeSession($workerB, $site, ['client_id' => $client->id]);
        $source = SignalSource::query()->firstOrCreate(
            ['slug' => 'lone_worker'],
            [
                'name' => 'Lone Worker Safety',
                'vendor' => 'internal',
                'status' => 'active',
                'config' => [],
                'capabilities' => ['manual_trigger', 'scheduled_checks'],
            ],
        );
        $signalType = SignalType::query()->updateOrCreate(
            ['code' => LoneWorkerSignalService::TYPE_EMERGENCY],
            [
                'name' => 'Lone Worker Emergency',
                'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
                'default_severity' => 'critical',
                'is_active' => true,
            ],
        );
        $rule = SignalRule::query()->create([
            'name' => 'Lone Worker Emergency Rule',
            'signal_type_id' => $signalType->id,
            'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY,
            'signal_source_id' => $source->id,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
            'output_severity' => 'critical',
            'output_escalation_level' => 1,
            'output_tier' => 1,
            'notify_roles' => [],
            'notify_users' => [],
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'suppress_in_maintenance' => false,
        ]);
        $this->assertTrue($rule->is_active);
        $this->assertTrue($rule->deduplicate);
        $this->assertTrue($rule->matchesConditions(new Signal([
            'signal_source_id' => $source->id,
            'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY,
        ])));

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$sessionA->id}/emergency", [
                'emergency_notes' => 'Session A emergency.',
            ])
            ->assertRedirect();

        $canonicalA = ControlRoomAlert::query()
            ->where('source', 'lone_worker')
            ->where('context->normalized_data->lone_worker_session_id', $sessionA->id)
            ->sole();
        $originalContextA = $canonicalA->context;
        $originalSignalIdA = (int) data_get($originalContextA, 'signal_id');
        $originalSignalTypeA = data_get($originalContextA, 'signal_type_code');
        $originalNormalizedA = data_get($originalContextA, 'normalized_data');
        $signalA = Signal::query()->findOrFail($originalSignalIdA);
        $this->assertDatabaseMissing('lone_worker_alerts', ['lone_worker_session_id' => $sessionA->id]);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $canonicalA->status);

        $signalCount = Signal::query()->count();
        $canonicalCount = ControlRoomAlert::query()->count();
        $caught = $this->captureLoneWorkerAuditFailure(fn () => $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$sessionB->id}/emergency", [
                'emergency_notes' => 'Session B must roll back with its signal.',
            ]));

        $this->assertNotNull($caught);
        $this->assertSame('active', $sessionB->fresh()->status);
        $this->assertNull($sessionB->fresh()->emergency_triggered_at);
        $this->assertDatabaseMissing('lone_worker_alerts', [
            'lone_worker_session_id' => $sessionB->id,
        ]);
        $this->assertSame($signalCount, Signal::query()->count());
        $this->assertSame($canonicalCount, ControlRoomAlert::query()->count());
        $this->assertSame($originalContextA, $canonicalA->fresh()->context);

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$sessionB->id}/emergency", [
                'emergency_notes' => 'Session B emergency.',
            ])
            ->assertRedirect();

        $canonicalA->refresh();
        $canonicalB = ControlRoomAlert::query()
            ->where('source', 'lone_worker')
            ->where('context->normalized_data->lone_worker_session_id', $sessionB->id)
            ->sole();
        $signalB = Signal::query()->findOrFail((int) data_get($canonicalB->context, 'signal_id'));

        $this->assertNotSame($canonicalA->id, $canonicalB->id);
        $this->assertNotSame($signalA->id, $signalB->id);
        $this->assertSame($originalSignalIdA, (int) data_get($canonicalA->context, 'signal_id'));
        $this->assertSame($originalSignalTypeA, data_get($canonicalA->context, 'signal_type_code'));
        $this->assertSame($originalNormalizedA, data_get($canonicalA->context, 'normalized_data'));
        $this->assertSame($originalContextA, $canonicalA->context);
        $this->assertSame('lone_worker', $canonicalA->source);
        $this->assertSame('Lone Worker Emergency', $canonicalA->alert_type);
        $this->assertSame($site->id, $canonicalA->site_id);
        $this->assertSame($client->id, $canonicalA->client_id);
        $this->assertSame($sessionA->id, data_get($canonicalA->context, 'normalized_data.lone_worker_session_id'));
        $this->assertSame($workerA->id, data_get($canonicalA->context, 'normalized_data.worker_user_id'));
        $this->assertSame(LoneWorkerSignalService::TYPE_EMERGENCY, $signalA->signal_type_code);
        $this->assertSame($sessionA->id, data_get($signalA->normalized_data, 'lone_worker_session_id'));
        $this->assertSame($workerA->id, data_get($signalA->normalized_data, 'worker_user_id'));

        $this->assertSame('lone_worker', $canonicalB->source);
        $this->assertSame('Lone Worker Emergency', $canonicalB->alert_type);
        $this->assertSame($site->id, $canonicalB->site_id);
        $this->assertSame($client->id, $canonicalB->client_id);
        $this->assertSame(LoneWorkerSignalService::TYPE_EMERGENCY, data_get($canonicalB->context, 'signal_type_code'));
        $this->assertSame($sessionB->id, data_get($canonicalB->context, 'normalized_data.lone_worker_session_id'));
        $this->assertSame($workerB->id, data_get($canonicalB->context, 'normalized_data.worker_user_id'));
        $this->assertSame($site->id, data_get($canonicalB->context, 'normalized_data.site_id'));
        $this->assertSame($client->id, data_get($canonicalB->context, 'normalized_data.client_id'));
        $this->assertSame(LoneWorkerSignalService::TYPE_EMERGENCY, $signalB->signal_type_code);
        $this->assertSame($sessionB->id, data_get($signalB->normalized_data, 'lone_worker_session_id'));
        $this->assertSame($workerB->id, data_get($signalB->normalized_data, 'worker_user_id'));

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/alerts/{$canonicalA->id}/acknowledge")
            ->assertRedirect();
        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/alerts/{$canonicalB->id}/resolve", [
                'resolution_notes' => 'Session B separately resolved.',
            ])
            ->assertRedirect();

        $this->assertSame(ControlRoomAlert::STATUS_ACK, $canonicalA->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $canonicalB->fresh()->status);
        $this->assertDatabaseCount('lone_worker_alerts', 0);
    }

    #[DataProvider('malformedCanonicalIdentityProvider')]
    public function test_lone_worker_canonical_identity_rejects_malformed_persisted_tuples(
        string $surface,
        string $field,
        mixed $malformed,
        bool $withClient,
    ): void {
        Carbon::setTestNow(Carbon::parse('2026-07-14 10:07:00'));

        try {
            $site = Site::factory()->create([
                'id' => 1,
            ]);
            $worker = User::factory()->create([
                'id' => $field === 'worker_user_id' ? 1000 : 1,
                'approved_at' => now(),
            ]);
            HrEmployeeProfile::factory()->create([
                'user_id' => $worker->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ]);
            $client = $withClient
                ? Client::factory()->create([
                    'id' => 1,
                    'site_id' => $site->id,
                ])
                : null;
            $actor = $this->hsLead();
            $session = LoneWorkerSession::unguarded(fn (): LoneWorkerSession => $this->makeSession(
                $worker,
                $site,
                [
                    'id' => 1,
                    'client_id' => $client?->id,
                ],
            ));
            $this->assertSame(1, $session->id);
            $source = SignalSource::query()->firstOrCreate(
                ['slug' => 'lone_worker'],
                [
                    'name' => 'Lone Worker Safety',
                    'vendor' => 'internal',
                    'status' => 'active',
                    'config' => [],
                    'capabilities' => ['manual_trigger', 'scheduled_checks'],
                ],
            );
            $signalType = SignalType::query()->updateOrCreate(
                ['code' => LoneWorkerSignalService::TYPE_EMERGENCY],
                [
                    'name' => 'Lone Worker Emergency',
                    'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
                    'default_severity' => 'critical',
                    'is_active' => true,
                ],
            );
            $canonical = [
                'source_module' => 'lone_worker',
                'signal_type' => LoneWorkerSignalService::TYPE_EMERGENCY,
                'lone_worker_session_id' => $session->id,
                'worker_user_id' => $worker->id,
                'site_id' => $site->id,
                'client_id' => $client?->id,
            ];
            $poisoned = array_merge($canonical, [$field => $malformed]);
            $signal = Signal::query()->create([
                'signal_source_id' => $source->id,
                'signal_type_id' => $signalType->id,
                'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY,
                'idempotency_key' => $this->loneWorkerEmergencyIdempotencyKey($session),
                'site_id' => $site->id,
                'client_id' => $client?->id,
                'severity_hint' => 'critical',
                'occurred_at' => now(),
                'payload' => [],
                'normalized_data' => $surface === 'signal' ? $poisoned : $canonical,
                'status' => 'pending',
            ]);
            $existingAlert = null;
            $originalAlertContext = null;
            if ($surface === 'alert') {
                $originalAlertContext = [
                    'signal_id' => $signal->id,
                    'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY,
                    'normalized_data' => $poisoned,
                ];
                $existingAlert = ControlRoomAlert::factory()->create([
                    'source' => 'lone_worker',
                    'alert_type' => 'Lone Worker Emergency',
                    'severity' => 'critical',
                    'status' => ControlRoomAlert::STATUS_OPEN,
                    'site_id' => $site->id,
                    'client_id' => $client?->id,
                    'triggered_at' => now(),
                    'context' => $originalAlertContext,
                ]);
                $signal->update([
                    'status' => 'processed',
                    'alert_id' => $existingAlert->id,
                    'processed_at' => now(),
                ]);
            }

            $caught = $this->captureRuntimeFailure(fn () => $this->actingAs($actor)->post(
                "/health-safety/lone-workers/sessions/{$session->id}/emergency",
                ['emergency_notes' => 'Malformed canonical identity must fail closed.'],
            ));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringContainsString('does not match', $caught->getMessage());
        $this->assertSame('active', $session->fresh()->status);
        $this->assertNull($session->fresh()->emergency_triggered_at);
        $this->assertDatabaseMissing('lone_worker_alerts', [
            'lone_worker_session_id' => $session->id,
        ]);
        $this->assertSame(1, Signal::query()->count());
        if ($surface === 'signal') {
            $this->assertSame('pending', $signal->fresh()->status);
            $this->assertDatabaseCount('control_room_alerts', 0);
        } else {
            $this->assertSame('processed', $signal->fresh()->status);
            $this->assertSame($existingAlert?->id, $signal->fresh()->alert_id);
            $this->assertSame(
                $this->normalizeAssociativeKeyOrder($originalAlertContext),
                $this->normalizeAssociativeKeyOrder($existingAlert?->fresh()->context),
            );
            $this->assertDatabaseCount('control_room_alerts', 1);
        }
    }

    public function test_lone_worker_canonical_identity_accepts_a_valid_same_session_idempotent_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 10:07:00'));

        try {
            $site = Site::factory()->create();
            $worker = $this->siteUser($site);
            $client = Client::factory()->create(['site_id' => $site->id]);
            $session = $this->makeSession($worker, $site, [
                'client_id' => $client->id,
            ]);
            $service = app(LoneWorkerSignalService::class);

            $service->emitEmergency($session, 'First canonical emission.');
            $firstSignal = Signal::query()->sole();
            $firstAlert = ControlRoomAlert::query()->where('source', 'lone_worker')->sole();
            $firstSignalTuple = $firstSignal->normalized_data;
            $firstAlertContext = $firstAlert->context;

            Carbon::setTestNow(now()->addHours(2));
            $service->emitEmergency($session, 'Idempotent retry.');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame($firstSignal->id, Signal::query()->sole()->id);
        $this->assertSame($firstAlert->id, ControlRoomAlert::query()->where('source', 'lone_worker')->sole()->id);
        $this->assertSame($firstSignalTuple, Signal::query()->sole()->normalized_data);
        $this->assertSame($firstAlertContext, ControlRoomAlert::query()->where('source', 'lone_worker')->sole()->context);
        $this->assertIsInt(data_get($firstSignalTuple, 'lone_worker_session_id'));
        $this->assertIsInt(data_get($firstSignalTuple, 'worker_user_id'));
        $this->assertIsInt(data_get($firstSignalTuple, 'site_id'));
        $this->assertIsInt(data_get($firstSignalTuple, 'client_id'));
        $this->assertSame($session->id, data_get($firstSignalTuple, 'lone_worker_session_id'));
        $this->assertSame($worker->id, data_get($firstSignalTuple, 'worker_user_id'));
        $this->assertSame($site->id, data_get($firstSignalTuple, 'site_id'));
        $this->assertSame($client->id, data_get($firstSignalTuple, 'client_id'));
    }

    public function test_wrong_canonical_alert_correlation_rolls_back_the_enclosing_emergency_mutation(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $worker = $this->siteUser($site);
        $otherWorker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $otherSession = $this->makeSession($otherWorker, $site);
        $processor = $this->mock(SignalProcessingService::class);
        $processor->shouldReceive('ingest')
            ->once()
            ->andReturnUsing(fn (array $data): Signal => Signal::query()->create(array_merge(
                $data,
                ['status' => 'pending'],
            )));
        $processor->shouldReceive('process')
            ->once()
            ->andReturnUsing(fn (Signal $signal): ControlRoomAlert => ControlRoomAlert::factory()->create([
                'source' => 'lone_worker',
                'alert_type' => 'Lone Worker Emergency',
                'site_id' => $site->id,
                'client_id' => null,
                'context' => [
                    'signal_id' => $signal->id,
                    'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY,
                    'normalized_data' => [
                        'lone_worker_session_id' => $otherSession->id,
                        'worker_user_id' => $otherSession->user_id,
                        'site_id' => $site->id,
                        'client_id' => null,
                    ],
                ],
            ]));

        $caught = $this->captureRuntimeFailure(fn () => $this->actingAs($actor)->post(
            "/health-safety/lone-workers/sessions/{$session->id}/emergency",
            ['emergency_notes' => 'Wrong canonical correlation must abort.'],
        ));

        $this->assertStringContainsString('does not match', (string) $caught?->getMessage());
        $this->assertSame('active', $session->fresh()->status);
        $this->assertNull($session->fresh()->emergency_triggered_at);
        $this->assertDatabaseMissing('lone_worker_alerts', ['lone_worker_session_id' => $session->id]);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_signal_emission_requires_the_session_to_name_its_authoritative_site(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $worker = $this->siteUser($site);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shift = Shift::factory()->create([
            'site_id' => null,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
        $session = $this->makeSession($worker, $site, [
            'site_id' => null,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
        ]);

        $caught = $this->captureRuntimeFailure(fn () => $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/emergency", [
                'emergency_notes' => 'Fallback site proof.',
            ]));

        $this->assertSame('Lone worker signal requires an authoritative session Site.', $caught?->getMessage());
        $this->assertSame('active', $session->fresh()->status);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('lone_worker_alerts', 0);
    }

    public function test_canonical_acknowledgement_aborts_when_multiple_operational_alerts_claim_one_emergency(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $session = $this->makeSession($this->siteUser($site), $site);
        $first = $this->canonicalAlert($session, $site);
        $second = $this->canonicalAlert($session, $site);

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/alerts/{$first->id}/acknowledge")
            ->assertStatus(409);

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $first->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $second->fresh()->status);
    }

    public function test_canonical_resolution_aborts_for_a_wrong_operational_type(): void
    {
        $site = Site::factory()->create();
        $actor = $this->hsLead();
        $session = $this->makeSession($this->siteUser($site), $site);
        $canonical = $this->canonicalAlert($session, $site);
        $canonical->update(['alert_type' => 'Lone Worker Session Overrun']);

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/alerts/{$canonical->id}/resolve", [
                'resolution_notes' => 'Must not resolve a mismatched operational type.',
            ])
            ->assertStatus(409);

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $canonical->fresh()->status);
    }

    private function siteUser(Site $site, array $permissions = []): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function hsLead(array $extraPermissions = []): User
    {
        $lead = User::factory()->create([
            'approved_at' => now(),
            'role' => 'manager',
        ]);
        $keys = array_values(array_unique([
            'hazards.manage',
            'healthSafety.viewAllSites',
            ...$extraPermissions,
        ]));
        $permissions = Permission::query()->whereIn('key', $keys)->pluck('id');
        $lead->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );

        return $lead;
    }

    /** @return array<string, array{string, string, mixed, bool}> */
    public static function malformedCanonicalIdentityProvider(): array
    {
        return [
            'signal leading-zero session id' => ['signal', 'lone_worker_session_id', '01', true],
            'alert leading-zero session id' => ['alert', 'lone_worker_session_id', '01', true],
            'signal scientific worker id' => ['signal', 'worker_user_id', '1e3', true],
            'alert scientific worker id' => ['alert', 'worker_user_id', '1e3', true],
            'signal lossy float site id' => ['signal', 'site_id', 1.9, true],
            'alert lossy float site id' => ['alert', 'site_id', 1.9, true],
            'signal leading-zero client id' => ['signal', 'client_id', '01', true],
            'alert leading-zero client id' => ['alert', 'client_id', '01', true],
            'signal nullable client requires literal null' => ['signal', 'client_id', '0', false],
            'alert nullable client requires literal null' => ['alert', 'client_id', '0', false],
        ];
    }

    /** @return array<string, array{string, mixed, bool}> */
    public static function malformedControllerCanonicalIdentityProvider(): array
    {
        return [
            'leading-zero session id' => ['lone_worker_session_id', '01', true],
            'scientific worker id' => ['worker_user_id', '1e3', true],
            'lossy float site id' => ['site_id', 1.9, true],
            'leading-zero client id' => ['client_id', '01', true],
            'signed session id' => ['lone_worker_session_id', '+1', true],
            'leading-whitespace worker id' => ['worker_user_id', ' 1', true],
            'trailing-whitespace site id' => ['site_id', '1 ', true],
            'boolean worker id' => ['worker_user_id', true, true],
            'negative site id' => ['site_id', '-1', true],
            'overflow session id' => ['lone_worker_session_id', '92233720368547758070', true],
            'nullable client rejects zero string' => ['client_id', '0', false],
            'nullable client rejects boolean false' => ['client_id', false, false],
        ];
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function canonicalControllerIdentityMutationProvider(): array
    {
        return [
            'acknowledge' => ['acknowledge', []],
            'resolve' => ['resolve', [
                'resolution_notes' => 'Malformed canonical identity must not resolve.',
            ]],
        ];
    }

    private function loneWorkerEmergencyIdempotencyKey(LoneWorkerSession $session): string
    {
        return hash('sha256', implode('|', [
            'lone_worker',
            LoneWorkerSignalService::TYPE_EMERGENCY,
            $session->id,
        ]));
    }

    /** @return array{User, LoneWorkerSession, ControlRoomAlert} */
    private function controllerCanonicalIdentityFixture(
        string $field,
        mixed $malformed,
        bool $withClient,
    ): array {
        $site = Site::factory()->create([
            'id' => 1,
        ]);
        $worker = User::factory()->create([
            'id' => $field === 'worker_user_id' && $malformed === '1e3' ? 1000 : 1,
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        $client = $withClient
            ? Client::factory()->create([
                'id' => 1,
                'site_id' => $site->id,
            ])
            : null;
        $viewer = $this->hsLead(['hazards.view']);
        $session = LoneWorkerSession::unguarded(fn (): LoneWorkerSession => $this->makeSession(
            $worker,
            $site,
            [
                'id' => 1,
                'client_id' => $client?->id,
            ],
        ));
        $alert = $this->canonicalAlert($session, $site, [$field => $malformed]);

        return [$viewer, $session, $alert];
    }

    private function normalizeAssociativeKeyOrder(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeAssociativeKeyOrder($item);
        }
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private function siteScopedUser(
        Site $site,
        array $permissions = [],
    ): User {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function makeSession(User $worker, Site $site, array $overrides = []): LoneWorkerSession
    {
        return LoneWorkerSession::query()->create(array_merge([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHours(2),
            'last_check_in_at' => now()->subMinutes(10),
            'check_in_interval_minutes' => 30,
            'status' => 'active',
            'activity_description' => 'Integrity test session',
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ], $overrides));
    }

    private function assertHttpForbidden(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('The stale or contradictory mutation should have been forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    /** @param array<int, array{query: string, bindings: array<int, mixed>, time: float}> $queries */
    private function assertTrackerSessionLockOrder(array $queries, string $workflow): void
    {
        $locks = collect($queries)
            ->pluck('query')
            ->map(fn (string $query): string => strtolower($query))
            ->filter(fn (string $query): bool => str_contains($query, 'for update'))
            ->values();
        $assignment = $locks->search(fn (string $query): bool => str_contains(
            $query,
            'from `device_assignments`',
        ));
        $device = $locks->search(fn (string $query): bool => str_starts_with(
            $query,
            'select * from `devices`',
        ));
        $session = $locks->search(fn (string $query): bool => str_contains(
            $query,
            'from `lone_worker_sessions`',
        ));

        $this->assertNotFalse($assignment, "{$workflow} must lock the active device assignment.");
        $this->assertNotFalse($device, "{$workflow} must lock the canonical device.");
        $this->assertNotFalse($session, "{$workflow} must lock and revalidate the session.");
        $this->assertLessThan($device, $assignment, "{$workflow} must lock assignment before device.");
        $this->assertLessThan($session, $device, "{$workflow} must lock device before session.");
    }

    private function captureLoneWorkerAuditFailure(callable $mutation): ?RuntimeException
    {
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated Lone Worker strict audit failure.');
        });
        $caught = null;

        $this->withoutExceptionHandling();
        try {
            $mutation();
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $this->withExceptionHandling();
            Event::forget($eventName);
        }

        return $caught;
    }

    private function captureRuntimeFailure(callable $mutation): ?RuntimeException
    {
        $caught = null;

        $this->withoutExceptionHandling();
        try {
            $mutation();
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $this->withExceptionHandling();
        }

        return $caught;
    }

    private function pairWorkerTracker(User $worker, string $imei): Device
    {
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => $imei,
            'device_uid' => $imei,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $worker->id,
            'assigned_at' => now(),
        ]);
        QueclinkDevice::query()->create([
            'imei' => $imei,
            'device_id' => $device->id,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        return $device;
    }

    /** @return array{User, LoneWorkerSession, LoneWorkerAlert, ControlRoomAlert} */
    private function historicalAndCanonicalAlertFixture(string $canonicalStatus): array
    {
        $site = Site::factory()->create();
        $coordinator = $this->siteScopedUser($site, ['hazards.view', 'hazards.manage']);
        $worker = $this->siteUser($site);
        $session = $this->makeSession($worker, $site);
        $historical = $session->alerts()->create([
            'alert_type' => 'emergency',
            'triggered_at' => now(),
            'status' => 'active',
        ]);
        $canonical = $this->canonicalAlert($session, $site, [], $canonicalStatus);

        return [$coordinator, $session, $historical, $canonical];
    }

    /** @param array<string, mixed> $contextOverrides */
    private function canonicalAlert(
        LoneWorkerSession $session,
        Site $alertSite,
        array $contextOverrides = [],
        string $status = 'open',
    ): ControlRoomAlert {
        return ControlRoomAlert::factory()->create([
            'source' => 'lone_worker',
            'alert_type' => 'Lone Worker Emergency',
            'status' => $status,
            'site_id' => $alertSite->id,
            'client_id' => $session->client_id,
            'triggered_at' => now(),
            'context' => [
                'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY,
                'normalized_data' => array_merge([
                    'source_module' => 'lone_worker',
                    'signal_type' => LoneWorkerSignalService::TYPE_EMERGENCY,
                    'lone_worker_session_id' => $session->id,
                    'worker_user_id' => $session->user_id,
                    'worker_name' => $session->user?->name,
                    'site_id' => $session->site_id,
                    'site_name' => $session->site?->name,
                    'client_id' => $session->client_id,
                    'location' => $session->location,
                ], $contextOverrides),
            ],
        ]);
    }
}
