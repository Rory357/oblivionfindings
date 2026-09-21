<?php

namespace Tests\Unit\Tracking;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Jobs\DispatchDeviceCommand;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\ConsentValidationService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Queclink\Listener\ConnectionState;
use App\Services\Queclink\Listener\FrameRouter;
use App\Services\Tracking\ClientLocationEvidenceBusy;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClientLocateFixture;
use Tests\Support\CommittedDatabaseTestCase;

class ClientLocateConcurrencyTest extends CommittedDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
        Mail::fake();
        $this->assertSame(0, DB::transactionLevel());
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        config(['database.connections.locate_interleaving' => [...DB::connection()->getConfig(), 'name' => 'locate_interleaving']]);
        $other = DB::connection('locate_interleaving');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertSame(DB::connection()->getDatabaseName(), $other->getDatabaseName());
        $this->assertNotSame(DB::connection()->getPdo(), $other->getPdo());
        $this->assertSame('locate_interleaving', $other->getName());
    }

    protected function tearDown(): void
    {
        DB::connection('locate_interleaving')->rollBack(0);
        DB::rollBack(0);
        DB::purge('locate_interleaving');
        parent::tearDown();
    }

    private function ready(array $fixture)
    {
        return app(DeviceCommandRequestService::class)->request($fixture['device'], $fixture['actor'],
            new CommandRequestInput('tracking.location_refresh', [], 'Request the location for this agreed check.', 'client-location:'.Str::uuid(),
                stepUpConfirmedAt: CarbonImmutable::now(), originContext: $fixture['origin']));
    }

    public function test_withdrawal_committed_after_an_old_snapshot_denies_queueing(): void
    {
        $fixture = ClientLocateFixture::make();
        $command = $this->ready($fixture);
        $other = DB::connection('locate_interleaving');
        try {
            DB::transaction(function () use ($fixture, $command, $other): void {
                $consent = fn () => DB::table('client_consents')->where('id', $fixture['consent']->id);
                $this->assertSame('given', $consent()->value('status'));
                $other->table('client_consents')->where('id', $fixture['consent']->id)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
                $this->assertSame('given', $consent()->value('status'), 'The ordinary snapshot remains older than withdrawal.');
                app(DeviceCommandQueueService::class)->queue($command, $fixture['actor']);
            });
            $this->fail('Current evidence must deny the withdrawn consent.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Client location access changed', $error->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(CommandStatus::Ready, $command->fresh()->status, 'The owning transaction rolled back all command writes.');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_busy_client_evidence_rolls_back_nested_claim_and_recovers_with_same_request(): void
    {
        $fixture = ClientLocateFixture::make();
        $command = $this->ready($fixture);
        $expiry = $command->expires_at->toISOString();
        $auditCount = $command->auditEvents()->count();
        $other = DB::connection('locate_interleaving');
        $other->beginTransaction();
        $other->table('clients')->where('id', $fixture['client']->id)->lockForUpdate()->first();
        try {
            DB::transaction(fn () => app(DeviceCommandQueueService::class)->queue($command, $fixture['actor']));
            $this->fail('A busy current evidence row must defer this claim.');
        } catch (ClientLocationEvidenceBusy $error) {
            $this->assertSame(3572, (int) $error->getPrevious()->errorInfo[1]);
        }
        $this->assertSame(0, DB::transactionLevel());
        // The failing path locked consent before reaching Client. A second
        // connection can now lock it, proving rollback reached the outer level.
        $this->assertNotNull($other->table('client_consents')->where('id', $fixture['consent']->id)->lock('for update nowait')->first());
        $this->assertSame(CommandStatus::Ready, $command->fresh()->status);
        $this->assertSame($auditCount, $command->auditEvents()->count());
        Queue::assertNothingPushed();
        $other->commit();
        $queued = app(DeviceCommandQueueService::class)->queue($command->fresh(), $fixture['actor']);
        $this->assertSame($command->id, $queued->id);
        $this->assertSame($expiry, $queued->expires_at->toISOString());
        Queue::assertPushed(DispatchDeviceCommand::class, 1);
        fwrite(STDERR, '\nLocate NOWAIT verified on '.DB::selectOne('SELECT VERSION() AS version')->version.' / '.DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level."\n");
    }

    public function test_queue_claim_holds_care_evidence_until_the_owning_transaction_commits(): void
    {
        $fixture = ClientLocateFixture::make();
        $command = $this->ready($fixture);
        $other = DB::connection('locate_interleaving');
        $remove = fn () => $other->table('client_user')->where('client_id', $fixture['client']->id)->where('user_id', $fixture['actor']->id)->delete();
        DB::transaction(function () use ($fixture, $command, $remove): void {
            app(DeviceCommandQueueService::class)->queue($command, $fixture['actor']);
            try {
                $remove();
                $this->fail('The current care relationship must be held through commit.');
            } catch (QueryException $error) {
                $this->assertSame(1205, (int) $error->errorInfo[1]);
            }
        });
        $this->assertSame(1, $remove());
        $this->assertSame(CommandStatus::Queued, $command->fresh()->status);
        Queue::assertPushed(DispatchDeviceCommand::class, 1);
        Http::assertNothingSent();
    }

    public function test_independent_clients_share_policy_evidence_while_a_policy_writer_is_excluded(): void
    {
        $first = ClientLocateFixture::make();
        $second = ClientLocateFixture::make();
        $second['actor']->roles()->sync($first['actor']->roles()->pluck('roles.id')->all());
        $firstCommand = $this->ready($first);
        $secondCommand = $this->ready($second);
        $connectionName = DB::getDefaultConnection();
        $primary = DB::connection();
        $other = DB::connection('locate_interleaving');
        $primary->beginTransaction();
        try {
            app(DeviceCommandQueueService::class)->queue($firstCommand, $first['actor']);
            DB::setDefaultConnection('locate_interleaving');
            try {
                app(DeviceCommandQueueService::class)->queue($secondCommand, $second['actor']);
                try {
                    $other->table('consent_types')->where('id', $first['consent']->consent_type_id)->update(['active' => false]);
                    $this->fail('Policy writers must still wait for the first reader.');
                } catch (QueryException $error) {
                    $this->assertSame(1205, (int) $error->errorInfo[1]);
                }
            } finally {
                DB::setDefaultConnection($connectionName);
            }
            $primary->commit();
        } finally {
            DB::setDefaultConnection($connectionName);
            $primary->rollBack(0);
        }
        $this->assertSame(CommandStatus::Queued, $firstCommand->fresh()->status);
        $this->assertSame(CommandStatus::Queued, $secondCommand->fresh()->status);
        Queue::assertPushed(DispatchDeviceCommand::class, 2);
    }

    public function test_old_snapshot_cannot_restore_revoked_scope_authority_or_capacity(): void
    {
        foreach (['scope', 'authority', 'capacity'] as $target) {
            $f = ClientLocateFixture::substitute();
            try {
                $this->assertTrue(ConsentValidationService::isValidResidentLocationConsent($f['consent'], $f['client']));
                $command = $this->ready($f);
                $table = $f[$target]->getTable();
                $id = $f[$target]->id;
                try {
                    DB::transaction(function () use ($f, $command, $table, $id, $target): void {
                        $before = DB::table($table)->where('id', $id)->first();
                        $change = match ($target) {
                            'scope' => ['revoked_at' => now()],
                            'authority' => ['legal_authority_verified_at' => null],
                            'capacity' => ['capacity_outcome' => 'has_capacity'],
                        };
                        DB::connection('locate_interleaving')->table($table)->where('id', $id)->update($change);
                        $this->assertEquals($before, DB::table($table)->where('id', $id)->first(), 'The original RR snapshot is deliberately stale.');
                        app(DeviceCommandQueueService::class)->queue($command, $f['actor']);
                    });
                    $this->fail('Current '.$target.' evidence must deny queueing.');
                } catch (ValidationException $error) {
                    $this->assertStringContainsString('Client location access changed', $error->getMessage());
                }
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame(CommandStatus::Ready, $command->fresh()->status);
            } finally {
                $f['type']->update(['requires_capacity_assessment' => false]);
            }
        }
        Queue::assertNothingPushed();
    }

    private function providerFrame(array $f): array
    {
        return app(FrameRouter::class)->handleInbound(
            '+RESP:GTHBD,8020090100,'.$f['providerDevice']->imei.',GV500CG,20230811075652,09CF$',
            new ConnectionState('192.0.2.10:54321'),
        );
    }

    public function test_provider_claim_uses_current_consent_after_an_older_snapshot(): void
    {
        $f = ClientLocateFixture::awaitingDelivery();
        DB::transaction(function () use ($f): void {
            $this->assertSame('given', DB::table('client_consents')->where('id', $f['consent']->id)->value('status'));
            DB::connection('locate_interleaving')->table('client_consents')->where('id', $f['consent']->id)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            $this->assertSame('given', DB::table('client_consents')->where('id', $f['consent']->id)->value('status'));
            $this->assertSame(['+SACK:GTHBD,8020090100,09CF$'], $this->providerFrame($f));
        });
        $this->assertSame(CommandStatus::Failed, $f['command']->fresh()->status);
        $this->assertSame('failed', $f['pending']->fresh()->status);
        $this->assertNull($f['pending']->fresh()->sent_at);
    }

    public function test_provider_busy_evidence_unwinds_the_outer_transaction_and_later_reuses_the_same_request(): void
    {
        $f = ClientLocateFixture::awaitingDelivery();
        $expiry = $f['command']->expires_at->toISOString();
        $other = DB::connection('locate_interleaving');
        $other->beginTransaction();
        $other->table('clients')->where('id', $f['client']->id)->lockForUpdate()->first();
        try {
            DB::transaction(fn () => $this->providerFrame($f));
            $this->fail('Nested provider contention must reach its owning transaction.');
        } catch (ClientLocationEvidenceBusy) {
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->assertNotNull($other->table('client_consents')->where('id', $f['consent']->id)->lock('for update nowait')->first());
        $this->assertSame('queued', $f['pending']->fresh()->status);
        $this->assertSame(CommandStatus::Accepted, $f['command']->fresh()->status);
        $other->commit();
        $this->assertContains($f['pending']->raw_command, $this->providerFrame($f));
        $this->assertSame($expiry, $f['command']->fresh()->expires_at->toISOString());
        $this->assertSame(CommandStatus::Running, $f['command']->fresh()->status);
        $this->assertSame(1, $f['command']->auditEvents()->where('action', 'provider_delivery_started')->count());
        Queue::assertNothingPushed();
    }

    public function test_provider_claim_first_holds_care_evidence_until_commit_without_claiming_socket_recall(): void
    {
        $f = ClientLocateFixture::awaitingDelivery();
        $remove = fn () => DB::connection('locate_interleaving')->table('client_user')
            ->where('client_id', $f['client']->id)->where('user_id', $f['actor']->id)->delete();
        DB::transaction(function () use ($f, $remove): void {
            $this->assertContains($f['pending']->raw_command, $this->providerFrame($f));
            try {
                $remove();
                $this->fail('Care revocation must wait for the claim transaction.');
            } catch (QueryException $error) {
                $this->assertSame(1205, (int) $error->errorInfo[1]);
            }
        });
        $this->assertSame(1, $remove());
        $this->assertSame('sent', $f['pending']->fresh()->status);
        $this->assertSame(['+SACK:GTHBD,8020090100,09CF$'], $this->providerFrame($f), 'A sent row is never retransmitted.');
    }

    public function test_current_authority_ignores_old_snapshots_and_primed_permission_or_site_caches(): void
    {
        foreach (['site', 'employment', 'role', 'explicit_deny', 'staff_type'] as $change) {
            $f = ClientLocateFixture::make();
            $newSite = Site::factory()->create();
            $eligibleRole = null;
            if ($change === 'role') {
                $f['actor']->update(['role' => 'admin']); // No legacy eligible-worker alternative.
                $eligibleRole = Role::query()->firstOrCreate(['name' => 'support_worker'], ['label' => 'Support worker', 'level' => 10, 'type' => 'system']);
                $f['actor']->roles()->attach($eligibleRole->id);
            }
            if ($change === 'explicit_deny') {
                $admin = Permission::query()->firstOrCreate(['key' => 'securityDevices.commands.admin'], ['description' => 'test', 'group' => 'test', 'module' => 'Test']);
                $f['actor']->permissionOverrides()->attach($admin->id, ['allowed' => true]);
            }
            $f['actor']->unsetRelations();
            $command = $this->ready($f);
            try {
                DB::transaction(function () use ($f, $change, $newSite, $eligibleRole, $command): void {
                    $this->assertContains($f['site']->id, app(UserSiteAccessService::class)->accessibleSiteIds($f['actor']));
                    $authorization = app(DeviceManagementAuthorizationService::class);
                    $capability = app(CommandCapabilityRegistry::class)->definition('tracking.location_refresh');
                    $this->assertTrue($authorization->evaluate($f['actor'], $f['device'], $capability)->allowed);
                    $other = DB::connection('locate_interleaving');
                    match ($change) {
                        'site' => $other->table('hr_employee_profiles')->where('user_id', $f['actor']->id)->update(['primary_site_id' => $newSite->id, 'secondary_site_ids' => '[]']),
                        'employment' => $other->table('hr_employee_profiles')->where('user_id', $f['actor']->id)->update(['is_active' => false]),
                        'role' => $other->table('role_user')->where('user_id', $f['actor']->id)->where('role_id', $eligibleRole->id)->delete(),
                        'explicit_deny' => $other->table('permission_user')->where('user_id', $f['actor']->id)
                            ->where('permission_id', Permission::query()->where('key', 'securityDevices.commands.operate')->value('id'))->update(['allowed' => false]),
                        'staff_type' => $other->table('users')->where('id', $f['actor']->id)->update(['role' => 'next_of_kin']),
                    };
                    // These caches deliberately still say yes. The guarded path
                    // must run its own current predicates, including nested SQL.
                    $this->assertContains($f['site']->id, app(UserSiteAccessService::class)->accessibleSiteIds($f['actor']));
                    $this->assertTrue($authorization->evaluate($f['actor'], $f['device'], $capability)->allowed);
                    app(DeviceCommandQueueService::class)->queue($command, $f['actor']);
                });
                $this->fail('Current '.$change.' evidence must deny this request.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString('Client location access changed', $error->getMessage());
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(CommandStatus::Ready, $command->fresh()->status);
        }
        Queue::assertNothingPushed();
    }

    public function test_current_read_scope_cannot_be_reused_after_its_callback_or_with_no_transaction(): void
    {
        $reads = DB::transaction(fn () => CurrentAuthorizationReads::within(fn ($scope) => $scope));
        $this->expectException(\LogicException::class);
        DB::transaction(fn () => $reads->query(Client::query())->first());
    }

    public function test_staff_with_an_additional_portal_role_keeps_the_canonical_staff_path(): void
    {
        $f = ClientLocateFixture::make();
        $role = Role::query()->firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of kin', 'level' => 1, 'type' => 'system']);
        $f['actor']->roles()->attach($role->id);
        $f['actor']->unsetRelations();
        $this->assertFalse(app(ClientProfileSectionAccess::class)->for($f['actor'], $f['client'])['tracking'],
            'An additional portal role does not gain the canonical assigned-worker tracking path.');
        // The assigned-worker branch canonically excludes portal roles. This
        // actor has the separate existing viewAny grant so both policies allow
        // staff access while the HR record still excludes the portal shortcut.
        $permission = Permission::query()->firstOrCreate(['key' => 'assets.viewAny'], ['description' => 'test', 'group' => 'test', 'module' => 'Test']);
        $f['actor']->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $f['actor']->unsetRelations();
        $this->assertInstanceOf(HasOne::class, $f['actor']->hrEmployeeProfile()->withTrashed());
        DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($f): void {
            $this->assertFalse($f['actor']->canAccessClientPortal($f['client'], $reads), 'An HR record excludes the portal shortcut.');
        }));
        $command = $this->ready($f);
        $queued = app(DeviceCommandQueueService::class)->queue($command, $f['actor']);
        $this->assertSame(CommandStatus::Queued, $queued->status);
        Queue::assertPushed(DispatchDeviceCommand::class, 1);
    }

    public function test_portal_hr_absence_and_membership_use_current_evidence(): void
    {
        $f = ClientLocateFixture::make();
        $role = Role::query()->firstOrCreate(['name' => 'client'], ['label' => 'Client', 'level' => 1, 'type' => 'system']);
        foreach (['hr_added', 'portal_removed'] as $change) {
            $actor = User::factory()->create(['role' => 'client']);
            $actor->roles()->attach($role->id);
            $actor->portalClients()->attach($f['client']->id, ['relation' => 'self']);
            DB::transaction(function () use ($actor, $f, $change): void {
                $this->assertTrue($actor->canAccessClientPortal($f['client']));
                $other = DB::connection('locate_interleaving');
                if ($change === 'hr_added') {
                    $profile = HrEmployeeProfile::factory()->make([
                        'user_id' => $actor->id, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'deleted_at' => now(),
                    ]);
                    $profile->setConnection('locate_interleaving')->save();
                } else {
                    $other->table('client_portal_users')->where('user_id', $actor->id)->delete();
                }
                $this->assertTrue($actor->canAccessClientPortal($f['client']), 'The ordinary RR snapshot still grants portal access.');
                CurrentAuthorizationReads::within(fn ($reads) => $this->assertFalse($actor->canAccessClientPortal($f['client'], $reads)));
            });
        }
    }

    public function test_current_read_scope_rejects_foreign_connection_builders_even_with_a_transaction(): void
    {
        $other = DB::connection('locate_interleaving');
        $other->beginTransaction();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('different transaction connection');
        DB::transaction(fn () => CurrentAuthorizationReads::within(fn ($reads) => $reads->query(Client::on('locate_interleaving'))->first()));
    }
}
