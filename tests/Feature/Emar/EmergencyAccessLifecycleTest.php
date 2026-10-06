<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\MedicationAlert;
use App\Models\MedicationEmergencyAccessReview;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\UserWitnessPin;
use App\Notifications\AppEventNotification;
use App\Notifications\MedicationAlertNotification;
use App\Services\Medication\EmergencyAccess\EmergencyAccessService;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\Medication\Settings\MedicationSettingsStore;
use App\Services\NotificationService;
use App\Services\Tasks\Providers\MedicationEmergencyAccessReviewProvider;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class EmergencyAccessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reviewer;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->owner = $this->staff('provider_manager', ['medications.breakglass']);
        $this->reviewer = $this->staff('clinical_lead', ['medications.audit.view']);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
    }

    private function staff(string $role, array $permissions): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $rbac = Role::where('name', $role)->first();
        if ($rbac) {
            $user->roles()->syncWithoutDetaching([$rbac->id]);
        }
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications', 'module' => 'Clinical']);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'position_role' => $role, 'is_active' => true, 'start_date' => today()->subDay(), 'end_date' => null,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        return $user->fresh();
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'reason' => 'Relief has not arrived and a dose is due', 'reason_category' => 'Covering an absence',
            'minutes' => 60, 'authorization_mode' => 'self',
            'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true,
        ];
    }

    private function start(array $extra = []): ClientBreakGlassAccess
    {
        return app(EmergencyAccessService::class)->start($this->owner, $this->client, $this->payload($extra));
    }

    public function test_both_acknowledgements_are_required_on_server(): void
    {
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass',
            $this->payload(['acknowledged_min_necessary' => false]))->assertUnprocessable();
        $this->assertDatabaseCount('client_break_glass_accesses', 0);
        $this->assertDatabaseCount('medication_events', 0);
    }

    public function test_required_second_person_has_no_self_authorisation_bypass(): void
    {
        BreakGlassPolicy::updateApplicationPolicy(BreakGlassPolicy::defaults() + []);
        BreakGlassPolicy::current()->update(['second_person' => 'required']);
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('client_break_glass_accesses', 0);
    }

    public function test_a_second_person_must_use_their_pin_and_confirmed_at_is_saved(): void
    {
        UserWitnessPin::updateOrCreate(['user_id' => $this->reviewer->id], ['pin_hash' => Hash::make('384927'), 'set_at' => now(), 'must_change' => false]);
        $payload = $this->payload(['authorization_mode' => 'co_sign', 'co_signed_by' => $this->reviewer->id, 'co_signer_pin' => '000000']);
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('client_break_glass_accesses', 0);
        $payload['co_signer_pin'] = '384927';
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass', $payload)->assertRedirect();
        $this->assertNotNull(ClientBreakGlassAccess::sole()->confirmed_at);
        $this->assertSame($this->reviewer->id, ClientBreakGlassAccess::sole()->co_signed_by);
    }

    public function test_duplicate_live_grant_is_refused_without_another_event(): void
    {
        $this->start();
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('client_break_glass_accesses', 1);
        $this->assertDatabaseCount('medication_events', 1);
    }

    public function test_extension_uses_frozen_policy_after_a_live_policy_change(): void
    {
        $grant = $this->start();
        BreakGlassPolicy::updateApplicationPolicy(['default_minutes' => 5, 'max_minutes' => 5, 'extend_minutes' => 5]);
        $this->travel(51)->minutes();
        app(EmergencyAccessService::class)->extend($this->owner, $grant, 'Relief is still on the way');
        $this->assertEquals($grant->expires_at->copy()->addMinutes(30), $grant->fresh()->expires_at);
        $this->assertSame(240, $grant->fresh()->policy_snapshot['max_minutes']);
        $this->assertDatabaseCount('medication_emergency_access_extensions', 1);
    }

    public static function tightenedPolicies(): array
    {
        return [
            'shorter duration' => [[['default_minutes', '60', '30'], ['max_minutes', '240', '30']], [], 'minutes'],
            'required reason' => [[['reason_required', 'no', 'yes']], ['reason' => null], 'reason'],
            'required second person' => [[['second_person', 'optional', 'required']], [], 'co_signed_by'],
        ];
    }

    #[DataProvider('tightenedPolicies')]
    public function test_new_grant_reads_a_committed_tightening_despite_an_earlier_repeatable_read_snapshot(array $changes, array $input, string $field): void
    {
        $editor = $this->staff('admin', ['medications.emergency_policy.manage']);
        $this->assertDatabaseCount('break_glass_policies', 0);
        BreakGlassPolicy::updateApplicationPolicy(['reason_required' => false]);
        AppSetting::create(['key' => MedicationSettingsStore::REVISION_KEY, 'value' => 0]);

        $this->withCommittedPolicySessions(function (Connection $primary, Connection $writer) use ($editor, $changes, $input, $field): void {
            $primary->beginTransaction();
            $old = BreakGlassPolicy::current()->snapshot(); // Establish a real REPEATABLE READ snapshot.
            $saved = $this->savePolicyOn($writer, $editor, $changes);
            $this->assertSame(count($changes), $saved['saved']);
            $this->assertSame($old, BreakGlassPolicy::current()->snapshot());
            $this->assertSame(count($changes), $writer->table('medication_setting_changes')->where('setting_group', 'ea')->count());
            $this->assertFalse($writer->getPdo()->inTransaction());
            $before = $this->emergencyEvidence($writer);

            try {
                $this->start($input);
                $this->fail('A new grant must use the policy committed by the settings writer.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }

            $this->assertSame($before, $this->emergencyEvidence($writer));
            $this->assertSame(0, $writer->table('client_break_glass_accesses')->count());
            $this->assertSame(0, $writer->table('medication_events')->where('kind', 'emergency_access')->count());
            Notification::assertNothingSent();
        });
    }

    public static function policyStorageStates(): array
    {
        return ['stored policy' => [true], 'absent policy and revision use defaults' => [false]];
    }

    #[DataProvider('policyStorageStates')]
    public function test_new_grant_holds_the_settings_mutex_until_commit_even_when_policy_is_absent(bool $stored): void
    {
        $editor = $this->staff('admin', ['medications.emergency_policy.manage']);
        $other = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->assertDatabaseCount('break_glass_policies', 0);
        $this->assertDatabaseMissing('app_settings', ['key' => MedicationSettingsStore::REVISION_KEY]);
        if ($stored) {
            BreakGlassPolicy::updateApplicationPolicy(BreakGlassPolicy::defaults());
        }
        $changes = [['default_minutes', '60', '30'], ['max_minutes', '240', '30']];

        $this->withCommittedPolicySessions(function (Connection $primary, Connection $writer) use ($editor, $other, $changes, $stored): void {
            $attempted = false;
            $blocked = false;
            $dispatcher = $primary->getEventDispatcher();
            $primary->setEventDispatcher(clone $dispatcher);
            $primary->listen(function (QueryExecuted $query) use ($primary, $writer, $editor, $changes, &$attempted, &$blocked): void {
                if ($attempted || ! str_contains($query->sql, 'from `break_glass_policies`')) {
                    return;
                }
                $attempted = true;
                $this->assertTrue($primary->getPdo()->inTransaction());
                // The real canonical writer contends after the grant's policy read, before its insertion.
                try {
                    $this->savePolicyOn($writer, $editor, $changes);
                } catch (QueryException $exception) {
                    $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
                    $blocked = true;
                }
            });
            try {
                $grant = $this->start();
            } finally {
                $primary->setEventDispatcher($dispatcher);
            }

            $this->assertTrue($attempted);
            $this->assertTrue($blocked, 'The settings writer must not commit between the policy read and grant commit.');
            $this->assertFalse($primary->getPdo()->inTransaction());
            $this->assertFalse($writer->getPdo()->inTransaction());
            $this->assertSame(1, $writer->table('client_break_glass_accesses')->count());
            $this->assertSame(collect(BreakGlassPolicy::defaults())->sortKeys()->all(), collect($grant->fresh()->policy_snapshot)->sortKeys()->all());
            $this->assertSame($stored ? 1 : 0, $writer->table('break_glass_policies')->count());
            $this->assertSame(0, $writer->table('medication_setting_changes')->count());
            $this->assertSame(0, $writer->table('medication_events')->where('kind', 'settings.changed')->count());
            $this->assertSame(1, $writer->table('medication_events')->where('kind', 'emergency_access')->where('facts->action', 'opened')->count());
            $original = $grant->fresh()->getRawOriginal();

            $this->assertSame(2, $this->savePolicyOn($writer, $editor, $changes)['saved']);
            $this->assertSame(30, (int) $writer->table('break_glass_policies')->value('max_minutes'));
            $this->assertSame($original, $grant->fresh()->getRawOriginal());
            $before = $this->emergencyEvidence($writer);
            $notifications = Notification::sentNotifications();
            try {
                app(EmergencyAccessService::class)->start($this->owner, $other, $this->payload());
                $this->fail('The newly committed shorter policy must refuse another sixty-minute grant.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('minutes', $exception->errors());
            }
            $this->assertSame($before, $this->emergencyEvidence($writer));
            $this->assertSame($notifications, Notification::sentNotifications());

            $this->travel(51)->minutes();
            try {
                app(EmergencyAccessService::class)->extend($this->owner, $grant, 'Relief is still on the way');
                $this->assertEquals($grant->expires_at->copy()->addMinutes(30), $grant->fresh()->expires_at);
                $this->assertSame(collect(BreakGlassPolicy::defaults())->sortKeys()->all(), collect($grant->fresh()->policy_snapshot)->sortKeys()->all());
                $this->assertSame(1, $writer->table('medication_emergency_access_extensions')->count());
            } finally {
                $this->travelBack();
            }
        });
    }

    public function test_locked_policy_snapshot_refuses_use_without_an_enclosing_transaction(): void
    {
        $this->withCommittedPolicySessions(function (Connection $primary, Connection $writer): void {
            $before = $this->emergencyEvidence($writer);
            $this->assertSame(0, $primary->transactionLevel());
            try {
                app(MedicationSettingsStore::class)->lockedEmergencyPolicySnapshot();
                $this->fail('An unlocked snapshot must not be exposed as authoritative.');
            } catch (\LogicException $exception) {
                $this->assertSame('The emergency policy snapshot requires an enclosing transaction.', $exception->getMessage());
            }
            $this->assertSame($before, $this->emergencyEvidence($writer));
            Notification::assertNothingSent();
        });
    }

    private function savePolicyOn(Connection $writer, User $editor, array $changes): array
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($writer->getName());
        try {
            $this->assertSame($writer, DB::connection());
            $registry = app(MedicationSettingsRegistry::class);
            $editor = User::query()->findOrFail($editor->id);
            $this->assertSame($writer, $editor->getConnection());
            $this->assertSame($writer->getPdo(), $editor->getConnection()->getPdo());
            $this->assertNotNull($editor->approved_at);
            $this->assertTrue($editor->canDo('medications.emergency_policy.manage'));

            return app(MedicationSettingsStore::class)->apply($editor, array_map(fn (array $change): array => [
                'definition' => $registry->definition('ea', $change[0]), 'site_id' => null,
                'from' => $change[1], 'value' => $change[2],
            ], $changes), false);
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function emergencyEvidence(Connection $connection): array
    {
        return collect([
            'client_break_glass_accesses', 'medication_emergency_access_extensions', 'medication_emergency_access_reviews',
            'medication_events', 'medication_event_heads', 'medication_alerts', 'notifications', 'audit_logs',
            'app_settings', 'break_glass_policies', 'medication_setting_changes',
        ])->mapWithKeys(fn (string $table): array => [$table => $connection->table($table)->get()
            ->map(fn (object $row): array => (array) $row)->sortBy(fn (array $row): string => serialize($row))->values()->all()])->all();
    }

    private function withCommittedPolicySessions(callable $exercise): void
    {
        $primary = DB::connection();
        $database = $primary->getDatabaseName();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $primary->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $database);
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($database, getmypid()));
        $this->assertSame($database, (string) $primary->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $primary->transactionLevel());
        $this->assertTrue($primary->getPdo()->inTransaction());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));

        $writerName = 'emar_emergency_policy_contender';
        $originalConfig = config('database.connections.'.$writerName);
        $originalDefault = DB::getDefaultConnection();
        $originalManager = $this->app['db.transactions'];
        $writer = null;
        $timeout = null;
        DB::commit();
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        $primary->setTransactionManager($manager);
        try {
            $this->assertSame(0, $primary->transactionLevel());
            config(['database.connections.'.$writerName => array_replace($primary->getConfig(), ['name' => $writerName])]);
            DB::purge($writerName);
            $writer = DB::connection($writerName);
            $this->assertSame('emar_emergency_policy_contender', $writer->getName());
            $this->assertNotSame($primary->getName(), $writer->getName());
            $this->assertSame($database, (string) $writer->getPdo()->query('SELECT DATABASE()')->fetchColumn());
            $this->assertNotSame((int) $primary->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
                (int) $writer->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
            $this->assertSame('REPEATABLE-READ', $primary->selectOne('SELECT @@SESSION.transaction_isolation AS isolation_level')->isolation_level);
            $timeout = (int) $writer->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
            $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $exercise($primary, $writer);
        } finally {
            DB::setDefaultConnection($originalDefault);
            if ($writer !== null) {
                while ($writer->transactionLevel() > 0) {
                    $writer->rollBack();
                }
                if ($timeout !== null) {
                    $writer->statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
                }
            }
            DB::purge($writerName);
            config(['database.connections.'.$writerName => $originalConfig]);
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            $this->app->instance('db.transactions', $originalManager);
            $primary->setTransactionManager($originalManager);
            $primary->beginTransaction();
        }
    }

    public function test_auditor_can_view_but_cannot_end_or_extend_another_grant(): void
    {
        $grant = $this->start();
        $auditor = $this->staff('auditor', ['medications.audit.view']);
        $this->actingAs($auditor)->get('/emar/emergency-access')->assertOk();
        $this->actingAs($auditor)->deleteJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id, ['reason' => 'Someone else is available now'])->assertForbidden();
        $this->actingAs($auditor)->postJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/extend', ['reason' => 'Someone else is available now'])->assertForbidden();
        $this->assertTrue($grant->fresh()->isRunning());
    }

    public function test_authorized_colleague_can_end_another_grant_with_current_permission_evidence(): void
    {
        $grant = $this->start();
        $colleague = $this->staff('clinical_lead', ['medications.breakglass.end']);
        $reason = 'The assigned nurse has arrived and can continue care';
        $this->actingAs($colleague)->deleteJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id, ['reason' => $reason])
            ->assertRedirect();

        $ended = ClientBreakGlassAccess::withTrashed()->findOrFail($grant->id);
        $this->assertSame('ended_by', $ended->ended_how);
        $this->assertSame($colleague->id, $ended->ended_by);
        $this->assertSame($reason, $ended->end_reason);
        $this->assertFalse($ended->isRunning());
        $this->assertSame($colleague->id, MedicationEvent::where('facts->action', 'closed')->sole()->actor_id);
        Notification::assertSentTo($this->owner, AppEventNotification::class, fn (AppEventNotification $notification): bool => $notification->payload['event_key'] === 'break_glass_access.ended'
            && $notification->payload['access_id'] === $grant->id
            && $notification->payload['body'] === $reason);
        Notification::assertNotSentTo($colleague, AppEventNotification::class, fn (AppEventNotification $notification): bool => $notification->payload['event_key'] === 'break_glass_access.ended');
    }

    public function test_explicit_support_collection_preserves_recipients_and_user_over_role_preferences(): void
    {
        $enabledByUser = $this->staff('clinical_lead', []);
        $disabledByUser = $this->staff('provider_manager', []);
        $eventKey = 'break_glass_access.ended';
        RoleNotificationPreference::create([
            'role_id' => Role::where('name', 'clinical_lead')->sole()->id,
            'key' => $eventKey, 'enabled' => false,
        ]);
        RoleNotificationPreference::create([
            'role_id' => Role::where('name', 'provider_manager')->sole()->id,
            'key' => $eventKey, 'enabled' => true,
        ]);
        UserNotificationPreference::create(['user_id' => $enabledByUser->id, 'key' => $eventKey, 'enabled' => true]);
        UserNotificationPreference::create(['user_id' => $disabledByUser->id, 'key' => $eventKey, 'enabled' => false]);

        $recipients = collect([$this->owner, $this->reviewer, $enabledByUser, $disabledByUser]);
        $allowed = app(NotificationService::class)->applyPreferences($recipients, $eventKey);

        $this->assertSame([$this->owner->id, $enabledByUser->id], $allowed->pluck('id')->all());
        $this->assertSame($this->owner, $allowed[0]);
        $this->assertSame($enabledByUser, $allowed[1]);
        $this->assertCount(4, $recipients);
    }

    public function test_revoked_grant_is_waiting_for_review_and_self_review_is_denied(): void
    {
        $grant = $this->start();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->actingAs($this->reviewer)->get('/emar/emergency-access')->assertInertia(fn (Assert $page) => $page
            ->where('stats.awaiting_review', 1)->has('reviewQueue', 1));
        $this->actingAs($this->owner)->postJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/review', ['review_outcome' => 'justified'])->assertForbidden();
    }

    public function test_cosigner_is_not_the_independent_reviewer(): void
    {
        UserWitnessPin::updateOrCreate(['user_id' => $this->reviewer->id], ['pin_hash' => Hash::make('384927'), 'set_at' => now(), 'must_change' => false]);
        $grant = $this->start(['authorization_mode' => 'co_sign', 'co_signed_by' => $this->reviewer->id, 'co_signer_pin' => '384927']);
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->actingAs($this->reviewer)->postJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/review', ['review_outcome' => 'justified'])->assertForbidden();
    }

    public function test_review_corrections_append_and_stale_correction_is_rejected(): void
    {
        $grant = $this->start();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $url = '/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/review';
        $this->actingAs($this->reviewer)->postJson($url, ['review_outcome' => 'justified'])->assertRedirect();
        $first = MedicationEmergencyAccessReview::sole();
        $this->actingAs($this->reviewer)->postJson($url, [
            'review_outcome' => 'not_justified', 'review_notes' => 'The roster showed a signed-off colleague was already present',
            'correction_reason' => 'The roster was checked after the first review', 'corrects_review_id' => $first->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('medication_emergency_access_reviews', 2);
        $this->assertSame('justified', $first->fresh()->outcome);
        $this->actingAs($this->reviewer)->postJson($url, [
            'review_outcome' => 'justified', 'correction_reason' => 'Another check was carried out', 'corrects_review_id' => $first->id,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('medication_emergency_access_reviews', 2);
    }

    public function test_running_grant_and_not_justified_without_notes_cannot_be_reviewed(): void
    {
        $grant = $this->start();
        $url = '/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/review';
        $this->actingAs($this->reviewer)->postJson($url, ['review_outcome' => 'justified'])->assertUnprocessable();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->actingAs($this->reviewer)->postJson($url, ['review_outcome' => 'not_justified'])->assertUnprocessable();
        $this->assertDatabaseCount('medication_emergency_access_reviews', 0);
    }

    public function test_expiry_is_recorded_once_at_actual_expiry_time(): void
    {
        $grant = $this->start();
        $grant->update(['expires_at' => now()->subMinute()]);
        $expiredAt = $grant->fresh()->expires_at;
        $this->artisan('emar:expire-emergency-access')->assertSuccessful();
        $this->artisan('emar:expire-emergency-access')->assertSuccessful();
        $this->assertEquals($expiredAt, $grant->fresh()->ended_at);
        $this->assertSame('expired', $grant->fresh()->ended_how);
        $this->assertSame(1, MedicationEvent::where('facts->action', 'closed')->count());
    }

    public function test_daily_report_uses_the_23_hour_nz_day_and_scoped_reviewers(): void
    {
        foreach (['2026-09-26 12:00:00', '2026-09-27 10:59:59', '2026-09-27 11:00:00'] as $at) {
            ClientBreakGlassAccess::forceCreate([
                'client_id' => $this->client->id, 'user_id' => $this->owner->id, 'reason' => 'Synthetic historical grant',
                'created_at' => Carbon::parse($at, 'UTC'), 'expires_at' => Carbon::parse($at, 'UTC')->addHour(),
            ]);
        }
        $hr = $this->staff('hr', []);
        $finance = $this->staff('finance', []);
        $this->artisan('breakglass:daily-report', ['--date' => '2026-09-27'])->assertSuccessful();
        $report = MedicationAlert::where('type', 'breakglass')->sole();
        $this->assertSame(2, $report->subject['used_count']);
        $this->assertSame('2026-09-27', $report->subject['nz_date']);
        Notification::assertSentTo($this->reviewer, MedicationAlertNotification::class);
        $this->artisan('breakglass:daily-report', ['--date' => '2026-09-27'])->assertSuccessful();
        $this->assertSame(1, MedicationAlert::where('type', 'breakglass')->count());
        Notification::assertNotSentTo($hr, MedicationAlertNotification::class);
        Notification::assertNotSentTo($finance, MedicationAlertNotification::class);
    }

    public function test_extension_is_refused_before_the_final_ten_minutes(): void
    {
        $grant = $this->start();
        $this->actingAs($this->owner)->postJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/extend',
            ['reason' => 'Relief has not arrived'])->assertUnprocessable();
        $this->assertDatabaseCount('medication_emergency_access_extensions', 0);
    }

    public function test_service_checks_required_reason_against_the_saved_policy(): void
    {
        BreakGlassPolicy::updateApplicationPolicy(['reason_required' => true]);
        try {
            $this->start(['reason' => '']);
            $this->fail('A required reason cannot be blank.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $this->assertDatabaseCount('client_break_glass_accesses', 0);
    }

    public function test_removed_person_does_not_starve_later_expiry_processing(): void
    {
        $removed = $this->start();
        $removed->update(['expires_at' => now()->subMinute()]);
        $this->client->delete();
        $other = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $valid = app(EmergencyAccessService::class)->start($this->owner, $other, $this->payload());
        $valid->update(['expires_at' => now()->subMinute()]);
        $this->artisan('emar:expire-emergency-access')->assertFailed();
        $this->assertNull($removed->fresh()->ended_at);
        $this->assertNotNull($valid->fresh()->ended_at);
    }

    public function test_repeat_acknowledgement_rechecks_current_permission_after_stale_actor_read(): void
    {
        BreakGlassPolicy::updateApplicationPolicy(['repeat_threshold_count' => 2]);
        $this->start();
        ClientBreakGlassAccess::create([
            'client_id' => $this->client->id, 'user_id' => $this->owner->id,
            'reason' => 'Earlier synthetic use', 'expires_at' => now()->subMinute(),
        ]);
        $this->reviewer->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $permission = Permission::where('key', 'medications.audit.view')->sole();
        $this->reviewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        try {
            app(EmergencyAccessService::class)->acknowledgeRepeat($this->reviewer, $this->site->id, $this->owner->id, 'Reviewed the repeated cover arrangements');
            $this->fail('Revoked review authority must refuse acknowledgement.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('break_glass_flag_dismissals', 0);
        $this->assertSame(0, MedicationEvent::where('facts->action', 'repeat_acknowledged')->count());
    }

    public function test_review_rechecks_approval_after_stale_actor_read(): void
    {
        $grant = $this->start();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->reviewer->newQuery()->whereKey($this->reviewer->id)->update(['approved_at' => null]);
        try {
            app(EmergencyAccessService::class)->review($this->reviewer, $grant, ['review_outcome' => 'justified']);
            $this->fail('Approval withdrawn before the authorization lock must refuse review.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('medication_emergency_access_reviews', 0);
    }

    public function test_manual_end_rechecks_approval_after_stale_actor_read(): void
    {
        $grant = $this->start();
        $this->reviewer->newQuery()->whereKey($this->reviewer->id)->update(['approved_at' => null]);
        try {
            app(EmergencyAccessService::class)->end($this->reviewer, $grant, 'Assigned staff can continue medication care');
            $this->fail('Approval withdrawn before the authorization lock must refuse a manual end.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($grant->fresh()->isRunning());
        $this->assertSame(0, MedicationEvent::where('facts->action', 'closed')->count());
    }

    public function test_unswept_expired_review_tasks_use_each_grants_frozen_deadline(): void
    {
        $expiry = now()->subDays(2)->subMinute();
        $grants = collect([1, 3, null])->map(fn ($days) => ClientBreakGlassAccess::forceCreate([
            'client_id' => $this->client->id, 'user_id' => $this->owner->id,
            'reason' => 'Historical emergency cover awaiting independent review',
            'created_at' => $expiry->copy()->subHour(), 'expires_at' => $expiry,
            'policy_snapshot' => $days === null ? null : array_replace(BreakGlassPolicy::defaults(), ['review_days' => $days]),
        ]));
        $tasks = collect((new MedicationEmergencyAccessReviewProvider)->authorizedTasks($this->reviewer))->keyBy('id');
        $this->assertCount(2, $tasks);
        $this->assertTrue($tasks->has('med_emergency_review-'.$grants[0]->id));
        $this->assertFalse($tasks->has('med_emergency_review-'.$grants[1]->id));
        $this->assertTrue($tasks->has('med_emergency_review-'.$grants[2]->id));
        $this->assertSame($grants[0]->reviewDueTime()->toIso8601String(), $tasks->get('med_emergency_review-'.$grants[0]->id)->dueAt);
    }

    public function test_overdue_review_projection_uses_independent_reviewer_authority(): void
    {
        $grant = $this->start();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->travel(3)->days();
        $provider = new MedicationEmergencyAccessReviewProvider;
        $this->assertCount(1, $provider->authorizedTasks($this->reviewer));
        $this->assertCount(0, $provider->authorizedTasks($this->owner));
        app(EmergencyAccessService::class)->review($this->reviewer, $grant, ['review_outcome' => 'justified']);
        $this->assertCount(0, $provider->authorizedTasks($this->reviewer));
    }
}
