<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerParticipationMeetingDelivery;
use App\Notifications\HealthSafety\WorkerParticipationMeetingNotice;
use App\Services\HealthSafety\WorkerParticipationMeetingDeliveryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Physical claim commits; only the external transport is fake. Native UNRUN; never execute without runtime admission. */
final class WorkerParticipationMeetingMailDeliveryTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private runIsolatedDatabaseMigrations; }

    protected function configureIsolatedTestingDatabase(): void
    {
        $database = $this->environmentValue('DB_DATABASE');
        if ($this->environmentValue('APP_ENV') !== 'testing' || $this->environmentValue('DB_CONNECTION') !== 'mysql'
            || ! is_string($database) || ! preg_match('/^oblivion_findings_codex_test(?:_[A-Za-z0-9_]+)?$/D', $database)) {
            throw new LogicException('Physical delivery fixtures require the explicit isolated codex test database.');
        }
        parent::configureIsolatedTestingDatabase();
    }

    public function runDatabaseMigrations(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
            || $database !== $this->environmentValue('DB_DATABASE')
            || ! preg_match('/^oblivion_findings_codex_test_[A-Za-z0-9_]+$/D', $database)) {
            throw new LogicException('Refuse destructive fixture setup outside the exact isolated runtime database.');
        }
        $this->runIsolatedDatabaseMigrations();
    }

    private User $manager;
    private User $recipient;
    private Site $site;
    private string $id;
    private WorkerParticipationMeetingDeliveryService $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->manager = $this->staff('health_safety_officer'); $this->recipient = $this->staff('support_worker');
        $committee = HsCommittee::create(['name' => 'Private committee', 'site_id' => $this->site->id, 'status' => 'active',
            'meeting_frequency' => 'monthly', 'established_at' => '2025-01-01', 'members' => []]);
        $meeting = $committee->meetings()->create(['scheduled_at' => '2026-01-15 00:00:00', 'status' => 'scheduled', 'location' => 'Private location']);
        $meeting->attendeeUsers()->attach($this->recipient->id);
        $this->actingAs($this->manager); Queue::fake(); Notification::fake();
        $this->delivery = app(WorkerParticipationMeetingDeliveryService::class);
        $this->id = DB::transaction(function () use ($meeting): string {
            $event = $this->delivery->recordCurrentEpoch($meeting, $this->manager, 'scheduled');
            return $this->delivery->stageToAudience($this->manager, $event['event_id'], [(int) $this->recipient->id])[0];
        });
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        // Test-owned data only: the production migration refuses destructive rollback of retained delivery history.
        if (isset($this->id)) {
            DB::table('notifications')->where('id', $this->id)->delete();
            DB::table('worker_participation_meeting_deliveries')->delete();
            DB::table('worker_participation_meeting_events')->delete();
        }
        parent::tearDown();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now(), 'external_clinical_account' => false]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null]);
        return $user;
    }

    public function test_success_commits_claim_before_fake_transport_and_never_duplicates_terminal_send(): void
    {
        $this->delivery->deliver($this->id); $this->delivery->deliver($this->id);
        Notification::assertSentToTimes($this->recipient, WorkerParticipationMeetingNotice::class, 1);
        $receipt = WorkerParticipationMeetingDelivery::findOrFail($this->id);
        $this->assertSame('delivered', $receipt->database_state); $this->assertSame('sent', $receipt->mail_state);
        $this->assertNotNull($receipt->mail_claim_id); $this->assertSame(1, $receipt->mail_attempts);
        $this->assertSame(1, DB::table('notifications')->where('id', $this->id)->count());
    }

    public function test_ambiguous_transport_failure_becomes_uncertain_without_automatic_resend(): void
    {
        $attempts = 0;
        Notification::shouldReceive('sendNow')->once()->andReturnUsing(function () use (&$attempts): void {
            $attempts++;
            $this->assertSame('sending', DB::table('worker_participation_meeting_deliveries')->where('id', $this->id)->value('mail_state'));
            $this->assertNotNull(DB::table('worker_participation_meeting_deliveries')->where('id', $this->id)->value('mail_claim_id'));
            throw new RuntimeException('Transport outcome not known');
        });
        $this->delivery->deliver($this->id); $this->travel(20)->minutes(); $this->delivery->recoverPending();
        $this->assertSame(1, $attempts);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->id, 'mail_state' => 'uncertain', 'mail_attempts' => 1]);
    }

    public function test_crashed_claim_is_uncertain_and_recovery_never_sends_again(): void
    {
        $this->delivery->deliverDatabase($this->id);
        DB::table('worker_participation_meeting_deliveries')->where('id', $this->id)->update(['mail_state' => 'sending',
            'mail_claim_id' => '11111111-1111-4111-8111-111111111111', 'mail_attempts' => 1, 'mail_started_at' => now()->subMinutes(11), 'next_attempt_at' => null]);
        $this->delivery->recoverPending();
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->id, 'mail_state' => 'uncertain']);
        Notification::assertNothingSent();
    }

    public static function mailTerminals(): array { return [['sent'], ['suppressed'], ['skipped'], ['uncertain']]; }

    #[DataProvider('mailTerminals')]
    public function test_stale_preclaim_source_failure_cannot_regress_another_worker_terminal_state(string $terminal): void
    {
        $this->delivery->deliverDatabase($this->id);
        $armed = true; $metadataReads = 0; $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$armed, &$metadataReads, &$fired, $terminal): void {
            if ($armed && str_starts_with(ltrim($query->sql), 'select') && str_contains($query->sql, 'worker_participation_meeting_deliveries')) {
                // deliverDatabase's already-terminal metadata first; deliverMail's original metadata second.
                if (++$metadataReads !== 2) { return; }
                $armed = false; $fired = true;
                DB::table('worker_participation_meeting_deliveries')->where('id', $this->id)->update(['mail_state' => $terminal,
                    'mail_attempts' => 9, 'mail_claim_id' => '22222222-2222-4222-8222-222222222222']);
                $this->site->update(['is_active' => false]);
            }
        });
        try { $this->delivery->deliver($this->id); } finally { $armed = false; }
        $this->assertTrue($fired);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->id, 'mail_state' => $terminal, 'mail_attempts' => 9]);
        Notification::assertNothingSent();
    }

    public function test_different_worker_claim_is_not_failed_or_sent_after_original_claim_loses_ownership(): void
    {
        $this->delivery->deliverDatabase($this->id); $armed = true; $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$armed, &$fired): void {
            if ($armed && str_starts_with(ltrim($query->sql), 'update') && str_contains($query->sql, 'worker_participation_meeting_deliveries')
                && in_array('sending', $query->bindings, true)) {
                $armed = false; $fired = true;
                DB::table('worker_participation_meeting_deliveries')->where('id', $this->id)->update([
                    'mail_claim_id' => '33333333-3333-4333-8333-333333333333', 'mail_attempts' => 9]);
            }
        });
        try { $this->delivery->deliver($this->id); } finally { $armed = false; }
        $this->assertTrue($fired);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->id, 'mail_state' => 'sending', 'mail_attempts' => 9,
            'mail_claim_id' => '33333333-3333-4333-8333-333333333333']);
        Notification::assertNothingSent();
    }

    public function test_caller_outer_transaction_cannot_attempt_external_delivery(): void
    {
        try {
            DB::transaction(fn () => $this->delivery->deliver($this->id)); $this->fail('Mail must require a physical commit boundary.');
        } catch (LogicException $error) { $this->assertStringContainsString('outermost transaction', $error->getMessage()); }
        Notification::assertNothingSent();
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->id, 'database_state' => 'pending', 'mail_state' => 'pending']);
    }

    public function test_saved_email_optout_suppresses_transport_but_preserves_database_delivery(): void
    {
        $this->actingAs($this->recipient)->put('/settings/notifications', ['prefs' => [WorkerParticipationMeetingDeliveryService::EVENT_KEY =>
            ['enabled' => true, 'inapp' => true, 'email' => false, 'push' => false]]])->assertRedirect();
        $this->actingAs($this->manager); $this->delivery->deliver($this->id);
        $this->assertDatabaseHas('notifications', ['id' => $this->id]);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->id, 'database_state' => 'delivered', 'mail_state' => 'suppressed']);
        Notification::assertNothingSent();
    }

    public function test_saved_inapp_optout_does_not_suppress_independently_enabled_mail(): void
    {
        $this->actingAs($this->recipient)->put('/settings/notifications', ['prefs' => [WorkerParticipationMeetingDeliveryService::EVENT_KEY =>
            ['enabled' => true, 'inapp' => false, 'email' => true, 'push' => false]]])->assertRedirect();
        $this->actingAs($this->manager); $this->delivery->deliver($this->id);
        $this->assertDatabaseMissing('notifications', ['id' => $this->id]);
        $this->assertDatabaseHas('worker_participation_meeting_deliveries', ['id' => $this->id, 'database_state' => 'suppressed', 'mail_state' => 'sent']);
        Notification::assertSentToTimes($this->recipient, WorkerParticipationMeetingNotice::class, 1);
    }
}
