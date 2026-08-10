<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FleetKeyLog;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class FleetKeyLedgerSiteAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $localSite;

    private Site $otherSite;

    private Asset $localVehicle;

    private Asset $otherVehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->localSite = Site::factory()->create(['name' => 'Harbour House']);
        $this->otherSite = Site::factory()->create(['name' => 'Forest House']);
        $this->localVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->localSite->id,
            'home_site_id' => $this->localSite->id,
            'name' => 'Harbour Van',
        ]);
        $this->otherVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->otherSite->id,
            'home_site_id' => $this->otherSite->id,
            'name' => 'Forest Van',
        ]);
    }

    public function test_site_scoped_viewer_sees_only_local_vehicles_and_intrinsically_local_logs(): void
    {
        $viewer = $this->makeCurrentStaff($this->localSite, ['fleet.viewAny']);
        $localHolder = $this->makeCurrentStaff($this->localSite);
        $otherHolder = $this->makeCurrentStaff($this->otherSite);
        $localLog = $this->keyLog($this->localVehicle, $localHolder, 'checked_out');
        $this->keyLog($this->otherVehicle, $otherHolder, 'checked_out');

        $this->actingAs($viewer)
            ->get('/fleet-assets/keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/keys/index')
                ->where('hero.tracked', 1)
                ->where('hero.checked_out', 1)
                ->has('current_holders', 1)
                ->where('current_holders.0.vehicle_id', $this->localVehicle->id)
                ->where('current_holders.0.holder_id', $localHolder->id)
                ->has('recent_logs', 1)
                ->where('recent_logs.0.id', $localLog->id)
                ->has('users', 0)
                ->has('vehicles', 0));
    }

    public function test_poisoned_latest_log_is_concealed_instead_of_falling_back_to_a_stale_holder(): void
    {
        $viewer = $this->makeCurrentStaff($this->localSite, ['fleet.viewAny']);
        $localHolder = $this->makeCurrentStaff($this->localSite);
        $otherHolder = $this->makeCurrentStaff($this->otherSite);
        $validLog = $this->keyLog($this->localVehicle, $localHolder, 'checked_out');
        $poisonedLog = $this->keyLog($this->localVehicle, $otherHolder, 'checked_out');
        FleetKeyLog::query()->whereKey($poisonedLog->id)->update(['site_id' => null]);

        $this->actingAs($viewer)
            ->get('/fleet-assets/keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hero.checked_out', 0)
                ->where('current_holders.0.vehicle_id', $this->localVehicle->id)
                ->where('current_holders.0.status', 'unknown')
                ->where('current_holders.0.holder_id', null)
                ->where('recent_logs', fn ($logs): bool => collect($logs)->pluck('id')->all() === [$validLog->id])
                ->where('recent_logs', fn ($logs): bool => ! collect($logs)->contains('id', $poisonedLog->id)));
    }

    public function test_event_site_history_does_not_follow_a_vehicle_to_its_new_site(): void
    {
        $localViewer = $this->makeCurrentStaff($this->localSite, ['fleet.viewAny']);
        $otherViewer = $this->makeCurrentStaff($this->otherSite, ['fleet.viewAny']);
        $holder = $this->makeCurrentStaff($this->localSite);
        $log = $this->keyLog($this->localVehicle, $holder, 'checked_out');

        $this->localVehicle->update([
            'site_id' => $this->otherSite->id,
            'home_site_id' => $this->otherSite->id,
        ]);

        $this->actingAs($localViewer)
            ->get('/fleet-assets/keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hero.tracked', 0)
                ->where('recent_logs', fn ($logs): bool => collect($logs)->pluck('id')->all() === [$log->id]));

        $this->actingAs($otherViewer)
            ->get('/fleet-assets/keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hero.tracked', 2)
                ->where('current_holders', function ($holders): bool {
                    $moved = collect($holders)->firstWhere('vehicle_id', $this->localVehicle->id);

                    return ($moved['status'] ?? null) === 'unknown'
                        && ($moved['holder_id'] ?? null) === null;
                })
                ->has('recent_logs', 0));
    }

    public function test_event_site_provenance_cannot_be_changed_after_creation(): void
    {
        $holder = $this->makeCurrentStaff($this->localSite);
        $log = $this->keyLog($this->localVehicle, $holder, 'checked_out');

        $this->expectException(LogicException::class);

        $log->update(['site_id' => $this->otherSite->id]);
    }

    public function test_historical_event_survives_staff_reassignment_without_leaking_the_name(): void
    {
        $viewer = $this->makeCurrentStaff($this->localSite, ['fleet.viewAny']);
        $holder = $this->makeCurrentStaff($this->localSite);
        $log = $this->keyLog($this->localVehicle, $holder, 'checked_out');
        $holder->hrEmployeeProfile()->update([
            'primary_site_id' => $this->otherSite->id,
            'secondary_site_ids' => [],
        ]);

        $this->actingAs($viewer)
            ->get('/fleet-assets/keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_holders.0.status', 'unknown')
                ->where('current_holders.0.holder_id', null)
                ->where('recent_logs.0.id', $log->id)
                ->where('recent_logs.0.user', null));
    }

    public function test_sql_site_scope_returns_valid_history_beyond_ambiguous_legacy_rows(): void
    {
        $viewer = $this->makeCurrentStaff($this->localSite, ['fleet.viewAny']);
        $holder = $this->makeCurrentStaff($this->localSite);
        $validLog = $this->keyLog($this->localVehicle, $holder, 'checked_out');
        $now = now();

        DB::table('fleet_key_logs')->insert(collect(range(1, 251))
            ->map(fn (): array => [
                'asset_id' => $this->localVehicle->id,
                'site_id' => null,
                'user_id' => $holder->id,
                'action' => 'checked_out',
                'transferred_to_user_id' => null,
                'location' => 'with_driver',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all());

        $this->actingAs($viewer)
            ->get('/fleet-assets/keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hero.activity_today', 1)
                ->where('current_holders.0.status', 'unknown')
                ->where('recent_logs', fn ($logs): bool => collect($logs)->pluck('id')->all() === [$validLog->id]));
    }

    public function test_explicit_fleet_manager_gets_application_wide_vehicle_and_current_staff_options(): void
    {
        $manager = $this->makeCurrentStaff($this->localSite, ['fleet.viewAny', 'fleet.manage']);
        $localStaff = $this->makeCurrentStaff($this->localSite);
        $otherStaff = $this->makeCurrentStaff($this->otherSite);
        $endedStaff = $this->makeCurrentStaff($this->otherSite);
        $endedStaff->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
        $unapprovedStaff = $this->makeCurrentStaff($this->otherSite, [], false);
        $siteLessStaff = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $siteLessStaff->id,
            'primary_site_id' => null,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get('/fleet-assets/keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('hero.tracked', 2)
                ->where('vehicles', fn ($vehicles): bool => collect($vehicles)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$this->localVehicle->id, $this->otherVehicle->id])
                    ->sort()
                    ->values()
                    ->all())
                ->where('users', function ($users) use (
                    $manager,
                    $localStaff,
                    $otherStaff,
                    $endedStaff,
                    $unapprovedStaff,
                    $siteLessStaff,
                ): bool {
                    $ids = collect($users)->pluck('id');

                    return $ids->contains($manager->id)
                        && $ids->contains($localStaff->id)
                        && $ids->contains($otherStaff->id)
                        && ! $ids->contains($endedStaff->id)
                        && ! $ids->contains($unapprovedStaff->id)
                        && ! $ids->contains($siteLessStaff->id);
                }));
    }

    public function test_fleet_manager_bypass_can_record_each_key_action_at_another_active_site(): void
    {
        $manager = $this->makeCurrentStaff($this->localSite, ['fleet.manage']);
        $otherSiteHolder = $this->makeCurrentStaff($this->otherSite);
        $otherSiteRecipient = $this->makeCurrentStaff($this->otherSite);

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/checkout', [
                'asset_id' => $this->otherVehicle->id,
                'user_id' => $otherSiteHolder->id,
                'key_number' => 'FOREST-1',
            ])
            ->assertRedirect();

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/transfer', [
                'asset_id' => $this->otherVehicle->id,
                'transferred_to_user_id' => $otherSiteRecipient->id,
                'key_number' => 'FOREST-1',
            ])
            ->assertRedirect();

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/return', [
                'asset_id' => $this->otherVehicle->id,
                'key_number' => 'FOREST-1',
            ])
            ->assertRedirect();

        $logs = FleetKeyLog::query()
            ->where('asset_id', $this->otherVehicle->id)
            ->orderBy('id')
            ->get();
        $checkout = $logs->firstWhere('action', 'checked_out');
        $transfer = $logs->firstWhere('action', 'transferred');
        $return = $logs->firstWhere('action', 'returned');

        $this->assertDatabaseHas('fleet_key_logs', [
            'asset_id' => $this->otherVehicle->id,
            'site_id' => $this->otherSite->id,
            'user_id' => $otherSiteHolder->id,
            'action' => 'checked_out',
            'key_number' => 'FOREST-1',
        ]);
        $this->assertDatabaseHas('fleet_key_logs', [
            'asset_id' => $this->otherVehicle->id,
            'site_id' => $this->otherSite->id,
            'user_id' => $otherSiteRecipient->id,
            'action' => 'returned',
            'key_number' => 'FOREST-1',
        ]);
        $this->assertDatabaseHas('fleet_key_logs', [
            'asset_id' => $this->otherVehicle->id,
            'site_id' => $this->otherSite->id,
            'user_id' => $otherSiteHolder->id,
            'action' => 'transferred',
            'transferred_to_user_id' => $otherSiteRecipient->id,
            'key_number' => 'FOREST-1',
        ]);

        foreach ([
            ['fleet-assets.keys.checkout', $checkout, $otherSiteHolder->id, null],
            ['fleet-assets.keys.transfer', $transfer, $otherSiteHolder->id, $otherSiteRecipient->id],
            ['fleet-assets.keys.return', $return, $otherSiteRecipient->id, null],
        ] as [$action, $log, $holderId, $recipientId]) {
            $audit = AuditLog::query()
                ->where('action', $action)
                ->where('auditable_type', (new FleetKeyLog)->getMorphClass())
                ->where('auditable_id', $log->id)
                ->sole();

            $this->assertSame($manager->id, $audit->user_id);
            $this->assertSame($this->otherSite->id, data_get($audit->meta, 'site_id'));
            $this->assertSame($this->otherVehicle->id, data_get($audit->meta, 'asset_id'));
            if ($action === 'fleet-assets.keys.checkout') {
                $this->assertSame($holderId, data_get($audit->meta, 'user_id'));
            } else {
                $this->assertSame($holderId, data_get($audit->meta, $recipientId ? 'from_user_id' : 'holder_user_id'));
            }
            if ($recipientId) {
                $this->assertSame($recipientId, data_get($audit->meta, 'transferred_to'));
            }
        }

        $otherSiteViewer = $this->makeCurrentStaff($this->otherSite, ['fleet.viewAny']);
        $this->actingAs($otherSiteViewer)
            ->get('/fleet-assets/keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_holders.0.status', 'returned')
                ->where('current_holders.0.holder_id', null)
                ->where('recent_logs', function ($logs): bool {
                    $logs = collect($logs);
                    $returned = $logs->firstWhere('action', 'returned');
                    $transferred = $logs->firstWhere('action', 'transferred');

                    return $logs->count() === 3
                        && ($returned['user'] ?? null) !== null
                        && ($transferred['user'] ?? null) !== null;
                }));
    }

    public function test_per_vehicle_custody_transitions_reject_stale_and_duplicate_submissions(): void
    {
        $manager = $this->makeCurrentStaff($this->localSite, ['fleet.manage']);
        $holder = $this->makeCurrentStaff($this->localSite);
        $recipient = $this->makeCurrentStaff($this->localSite);

        $this->actingAs($manager)
            ->postJson('/fleet-assets/keys/transfer', [
                'asset_id' => $this->localVehicle->id,
                'transferred_to_user_id' => $recipient->id,
            ])
            ->assertConflict();
        $this->actingAs($manager)
            ->postJson('/fleet-assets/keys/return', ['asset_id' => $this->localVehicle->id])
            ->assertConflict();

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/checkout', [
                'asset_id' => $this->localVehicle->id,
                'user_id' => $holder->id,
                'key_number' => 'HARBOUR-1',
            ])
            ->assertRedirect();
        $this->actingAs($manager)
            ->post('/fleet-assets/keys/checkout', [
                'asset_id' => $this->localVehicle->id,
                'user_id' => $recipient->id,
                'key_number' => 'HARBOUR-1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('custody')
            ->assertSessionHas('error');

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/transfer', [
                'asset_id' => $this->localVehicle->id,
                'transferred_to_user_id' => $recipient->id,
                'key_number' => 'HARBOUR-1',
            ])
            ->assertRedirect();
        $this->actingAs($manager)
            ->postJson('/fleet-assets/keys/transfer', [
                'asset_id' => $this->localVehicle->id,
                'transferred_to_user_id' => $recipient->id,
                'key_number' => 'HARBOUR-1',
            ])
            ->assertConflict();

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/return', [
                'asset_id' => $this->localVehicle->id,
                'key_number' => 'HARBOUR-1',
            ])
            ->assertRedirect();
        $this->actingAs($manager)
            ->postJson('/fleet-assets/keys/return', [
                'asset_id' => $this->localVehicle->id,
                'key_number' => 'HARBOUR-1',
            ])
            ->assertConflict();

        $this->assertSame(3, FleetKeyLog::query()
            ->where('asset_id', $this->localVehicle->id)
            ->count());
        $this->assertSame(3, AuditLog::query()
            ->whereIn('action', [
                'fleet-assets.keys.checkout',
                'fleet-assets.keys.return',
                'fleet-assets.keys.transfer',
            ])
            ->count());
    }

    public function test_integrity_audit_failure_rolls_back_the_key_event(): void
    {
        $manager = $this->makeCurrentStaff($this->localSite, ['fleet.manage']);
        $holder = $this->makeCurrentStaff($this->localSite);
        $auditAttempts = 0;
        AuditLog::creating(function () use (&$auditAttempts): void {
            $auditAttempts++;
            if ($auditAttempts === 2) {
                throw new RuntimeException('Forced integrity-audit failure.');
            }
        });

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/checkout', [
                'asset_id' => $this->localVehicle->id,
                'user_id' => $holder->id,
            ])
            ->assertServerError();

        $this->assertSame(2, $auditAttempts);
        $this->assertDatabaseCount('fleet_key_logs', 0);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_type', (new FleetKeyLog)->getMorphClass())
            ->count());
    }

    public function test_key_mutations_conceal_forged_vehicle_and_cross_site_staff_ids(): void
    {
        $manager = $this->makeCurrentStaff($this->localSite, ['fleet.manage']);
        $localStaff = $this->makeCurrentStaff($this->localSite);
        $otherStaff = $this->makeCurrentStaff($this->otherSite);
        $nonVehicle = Asset::factory()->create([
            'site_id' => $this->localSite->id,
            'category' => 'Medical Device',
        ]);
        $otherSiteClient = Client::factory()->create(['site_id' => $this->otherSite->id]);
        $poisonedVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $this->localSite->id,
            'home_site_id' => $this->localSite->id,
            'client_id' => $otherSiteClient->id,
        ]);

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/checkout', [
                'asset_id' => $this->localVehicle->id,
                'user_id' => $otherStaff->id,
            ])
            ->assertNotFound();

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/transfer', [
                'asset_id' => $this->localVehicle->id,
                'transferred_to_user_id' => $otherStaff->id,
            ])
            ->assertNotFound();

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/checkout', [
                'asset_id' => $nonVehicle->id,
                'user_id' => $localStaff->id,
            ])
            ->assertNotFound();

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/checkout', [
                'asset_id' => $poisonedVehicle->id,
                'user_id' => $localStaff->id,
            ])
            ->assertNotFound();

        $this->actingAs($manager)
            ->post('/fleet-assets/keys/return', [
                'asset_id' => PHP_INT_MAX,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('fleet_key_logs', 0);
        $this->assertSame(0, AuditLog::query()
            ->whereIn('action', [
                'fleet-assets.keys.checkout',
                'fleet-assets.keys.return',
                'fleet-assets.keys.transfer',
            ])
            ->count());
    }

    public function test_fleet_permission_does_not_turn_a_former_worker_into_a_key_ledger_actor(): void
    {
        $formerManager = $this->makeCurrentStaff($this->localSite, ['fleet.manage']);
        $formerManager->hrEmployeeProfile()->update([
            'is_active' => false,
            'end_date' => today()->subDay(),
        ]);

        $this->actingAs($formerManager)
            ->post('/fleet-assets/keys/return', [
                'asset_id' => $this->localVehicle->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_key_logs', 0);
    }

    /** @param array<int, string> $permissionKeys */
    private function makeCurrentStaff(
        Site $site,
        array $permissionKeys = [],
        bool $approved = true,
    ): User {
        $user = User::factory()->create([
            'approved_at' => $approved ? now() : null,
            'role' => 'support_worker',
        ]);
        $this->grantPermissions($user, $permissionKeys);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    }

    /** @param array<int, string> $permissionKeys */
    private function grantPermissions(User $user, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                [
                    'description' => $permissionKey,
                    'group' => 'fleet',
                    'module' => 'Fleet',
                ],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }
    }

    private function keyLog(Asset $vehicle, User $user, string $action): FleetKeyLog
    {
        $siteId = $vehicle->site_id ?: $vehicle->home_site_id ?: $vehicle->client?->site_id;

        return FleetKeyLog::query()->create([
            'asset_id' => $vehicle->id,
            'site_id' => $siteId,
            'user_id' => $user->id,
            'action' => $action,
            'location' => 'with_driver',
        ]);
    }
}
