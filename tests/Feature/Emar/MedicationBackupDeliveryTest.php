<?php

namespace Tests\Feature\Emar;

use App\Mail\MailNotSubmitted;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationBackupAttempt;
use App\Models\MedicationBackupDelivery;
use App\Models\MedicationBackupSchedule;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\BackupDelivery\BackupDeliveryService;
use App\Services\Medication\BackupDelivery\BackupMailTransport;
use App\Services\Medication\BackupDelivery\BackupPdfEncryption;
use App\Services\Medication\Downtime\DowntimePackPdf;
use Carbon\Carbon;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

class MedicationBackupDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $recipient;

    private Site $site;

    private ClientMedication $order;

    private bool $durable = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:00', 'Pacific/Auckland')->utc());
        // Explicit fixture grants avoid committing updates to pre-existing RBAC definitions.
        foreach (['medications.view', 'clients.viewAny', 'medications.backups.manage', 'medications.reports.view', 'medications.reports.export', 'medications.controlled.view'] as $key) {
            Permission::query()->firstOrCreate(['key' => $key], ['description' => 'Synthetic backup permission', 'group' => 'medications', 'module' => 'Clinical']);
        }
        if (! Route::has('emar.backups.index')) {
            Route::middleware('web')->group(base_path('routes/emar-catalogue-backups.php'));
        }
        Storage::fake('private');
        Mail::fake();
        config(['emar-catalogue-backups.send_enabled' => false]);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->lead = $this->staff($this->site, true);
        $this->recipient = $this->staff($this->site, false);
        $this->order = ClientMedication::factory()->create(['client_id' => $client->id, 'name' => 'Fictional backup medicine', 'form' => 'tablet', 'dosage' => '10 mg', 'dose_amount' => 10, 'dose_unit' => 'mg', 'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'verified_at' => now(), 'start_date' => '2026-10-06', 'end_date' => null, 'dose_times' => ['09:00'], 'route' => 'oral', 'frequency' => 'Once daily', 'controlled_drug' => false, 'is_prn' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Pacific/Auckland')->utc());
        $encryption = Mockery::mock(BackupPdfEncryption::class);
        $encryption->shouldReceive('ready')->andReturnTrue();
        $encryption->shouldReceive('encrypt')->andReturn('%PDF-PROTECTED-FICTIONAL');
        $this->app->instance(BackupPdfEncryption::class, $encryption);
        $pdf = Mockery::mock(DowntimePackPdf::class);
        $pdf->shouldReceive('render')->andReturn('%PDF-1.7 fictional bytes');
        $this->app->instance(DowntimePackPdf::class, $pdf);
    }

    protected function tearDown(): void
    {
        if ($this->durable && DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preparation_is_per_day_idempotent_private_and_encrypted_at_rest(): void
    {
        $schedule = $this->schedule();
        $first = $this->prepare($schedule);
        $second = $this->prepare($schedule);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('ready', $first->state);
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->assertStringNotContainsString('Fictional backup medicine', $first->getRawOriginal('source_snapshot'));
        $this->assertNotSame($first->password, $first->getRawOriginal('password'));
        $this->assertStringNotContainsString('artifact_path', json_encode(app(BackupDeliveryService::class)->dto($first, $this->lead)));
        $this->assertDatabaseCount('medication_backup_attempts', 0);
        Mail::assertNothingSent();
    }

    public function test_unverified_or_foreign_recipient_cannot_be_approved(): void
    {
        $schedule = $this->schedule(false);
        $this->recipient->forceFill(['email_verified_at' => null])->saveQuietly();
        $url = '/emar/backups/schedules/'.$schedule->id.'/recipients';
        $this->actingAs($this->lead)->postJson($url, ['version' => $schedule->version, 'user_id' => $this->recipient->id, 'approved' => true])->assertUnprocessable();
        $foreign = $this->staff(Site::factory()->create(['is_active' => true, 'archived' => false]), false);
        $this->actingAs($this->lead)->postJson($url, ['version' => $schedule->version, 'user_id' => $foreign->id, 'approved' => true])->assertNotFound();
        $this->assertDatabaseCount('medication_backup_recipients', 0);
        $this->assertSame($schedule->version, $schedule->fresh()->version);
    }

    public function test_revoked_recipient_cannot_exceed_active_limit_but_existing_active_and_released_capacity_remain_usable(): void
    {
        $service = app(BackupDeliveryService::class);
        $schedule = $this->schedule();
        $original = $schedule->recipients()->where('user_id', $this->recipient->id)->sole();
        $schedule = $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $this->recipient->id, false);
        $others = [];
        for ($index = 0; $index < 20; $index++) {
            $other = $this->staff($this->site, false);
            $others[] = $other;
            $schedule = $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $other->id, true);
        }
        $this->assertSame(20, $schedule->recipients()->whereNull('revoked_at')->count());
        $beforeSchedule = $schedule->fresh()->getRawOriginal();
        $beforeRecipients = $schedule->recipients()->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all();
        $url = '/emar/backups/schedules/'.$schedule->id.'/recipients';
        $this->actingAs($this->lead)->postJson($url, ['version' => $schedule->version, 'user_id' => $this->recipient->id, 'approved' => true])->assertUnprocessable();
        $this->assertSame($beforeSchedule, $schedule->fresh()->getRawOriginal());
        $this->assertSame($beforeRecipients, $schedule->recipients()->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all());
        $this->assertSame(20, $schedule->recipients()->whereNull('revoked_at')->count());

        // Reapproval of an already-active recipient consumes no additional place.
        $this->postJson($url, ['version' => $schedule->version, 'user_id' => $others[0]->id, 'approved' => true])->assertOk()->assertJsonPath('version', $schedule->version + 1);
        $schedule->refresh();
        $this->assertSame(20, $schedule->recipients()->whereNull('revoked_at')->count());
        $schedule = $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $others[0]->id, false);
        $this->assertSame(19, $schedule->recipients()->whereNull('revoked_at')->count());
        $this->postJson($url, ['version' => $schedule->version, 'user_id' => $this->recipient->id, 'approved' => true])->assertOk()->assertJsonPath('version', $schedule->version + 1);
        $schedule->refresh();
        $restored = $schedule->recipients()->where('user_id', $this->recipient->id)->sole();
        $this->assertSame($original->id, $restored->id);
        $this->assertNull($restored->revoked_at);
        $this->assertSame($this->lead->id, $restored->approved_by);
        $this->assertSame(20, $schedule->recipients()->whereNull('revoked_at')->count());
        $this->assertSame(21, $schedule->recipients()->count());
        $this->assertDatabaseCount('medication_backup_deliveries', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
        Mail::assertNothingSent();
    }

    public function test_current_revoked_manager_authority_blocks_cached_actor_without_a_schedule(): void
    {
        $this->lead->canDo('medications.backups.manage');
        $permission = Permission::query()->where('key', 'medications.backups.manage')->sole();
        $this->lead->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->actingAs($this->lead)->putJson('/emar/backups/sites/'.$this->site->id.'/schedule', ['version' => 0, 'local_time' => '07:30', 'enabled' => true, 'retention_days' => 7])->assertForbidden();
        $this->assertDatabaseCount('medication_backup_schedules', 0);
    }

    public function test_source_change_during_render_discards_owned_artifact_before_release(): void
    {
        $schedule = $this->schedule();
        $pdf = Mockery::mock(DowntimePackPdf::class);
        $pdf->shouldReceive('render')->once()->andReturnUsing(function (): string {
            $this->order->forceFill(['dosage' => 'Changed fictional dose'])->saveQuietly();

            return '%PDF-1.7 fictional bytes';
        });
        $this->app->instance(DowntimePackPdf::class, $pdf);
        $this->openDurableBoundary();
        $this->actingAs($this->lead)->postJson('/emar/backups/sites/'.$this->site->id.'/prepare', ['version' => $schedule->version, 'nz_date' => '2026-10-07'])->assertConflict();
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame('failed', MedicationBackupDelivery::query()->sole()->state);
        $this->assertDatabaseCount('medication_backup_attempts', 0);
    }

    public function test_disabled_transport_does_not_claim_or_send_a_ready_backup(): void
    {
        $row = $this->prepare($this->schedule());
        $this->openDurableBoundary();
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertStatus(503);
        $this->assertSame('ready', $row->fresh()->state);
        $this->assertDatabaseCount('medication_backup_attempts', 0);
        Mail::assertNothingSent();
    }

    public function test_successful_submission_is_once_and_replay_cannot_duplicate_it(): void
    {
        $row = $this->prepare($this->schedule());
        $this->openDurableBoundary();
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldReceive('send')->once()->with([$this->recipient->email], '%PDF-PROTECTED-FICTIONAL', '2026-10-07');
        $this->app->instance(BackupMailTransport::class, $transport);
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertOk()->assertJsonPath('state', 'sent');
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertConflict();
        $this->assertSame(1, $row->fresh()->attempt_count);
        $this->assertSame('sent', MedicationBackupAttempt::query()->sole()->state);
    }

    public function test_known_unsent_failure_can_rebuild_and_retry_without_duplicate_day(): void
    {
        $row = $this->prepare($this->schedule());
        $this->openDurableBoundary();
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new MailNotSubmitted('Synthetic no submission'));
        $this->app->instance(BackupMailTransport::class, $transport);
        try {
            app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->fail('Expected local preflight failure.');
        } catch (MailNotSubmitted) {
            $this->assertSame('failed', $row->fresh()->state);
        }
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldReceive('send')->once();
        $this->app->instance(BackupMailTransport::class, $transport);
        $sent = app(BackupDeliveryService::class)->retry($this->lead, $row->id, $row->fresh()->version);
        $this->assertSame('sent', $sent->state);
        $this->assertSame(2, $sent->attempt_count);
        $this->assertDatabaseCount('medication_backup_deliveries', 1);
        $this->assertDatabaseCount('medication_backup_attempts', 2);
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_ambiguous_submission_is_never_retryable(): void
    {
        $row = $this->prepare($this->schedule());
        $this->openDurableBoundary();
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic unknown acceptance'));
        $this->app->instance(BackupMailTransport::class, $transport);
        try {
            app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->fail('Expected unknown transport result.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic unknown acceptance', $error->getMessage());
        }
        $this->assertSame('uncertain', $row->fresh()->state);
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/retry', ['version' => $row->fresh()->version])->assertConflict();
        $this->assertSame('uncertain', MedicationBackupAttempt::query()->sole()->state);
    }

    public function test_later_recipient_preflight_failure_after_accepted_mail_is_uncertain_and_not_retryable(): void
    {
        $service = app(BackupDeliveryService::class);
        $schedule = $this->schedule();
        $second = $this->staff($this->site, false);
        $schedule = $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $second->id, true);
        $row = $this->prepare($schedule);
        config(['emar-catalogue-backups.send_enabled' => true]);
        $accepted = [];
        $addresses = [$this->recipient->email, $second->email];
        $calls = 0;
        Mail::shouldReceive('raw')->twice()->andReturnUsing(function (string $body, \Closure $compose) use (&$calls, &$accepted, $addresses) {
            $email = (new Email)->from('fictional-sender@example.test')->text($body);
            $compose(new Message($email));
            $this->assertSame([$addresses[$calls]], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
            $this->assertSame('Protected chart backup', $email->getSubject());
            $this->assertStringNotContainsString('Fictional backup medicine', $body);
            $calls++;
            if ($calls === 2) {
                throw new MailNotSubmitted('Synthetic second-recipient preflight denial');
            }
            $accepted[] = $addresses[0];

            return new SentMessage(new \Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));
        });
        try {
            $service->send($this->lead, $row->id, $row->version);
            $this->fail('Accepted earlier mail must make a later no-submission result uncertain.');
        } catch (\RuntimeException $error) {
            $this->assertNotInstanceOf(MailNotSubmitted::class, $error);
            $this->assertSame('backup_transport_incomplete', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame([$this->recipient->email], $accepted);
        $this->assertSame(2, $calls);
        $fresh = $row->fresh();
        $this->assertSame('uncertain', $fresh->state);
        $this->assertSame(1, $fresh->attempt_count);
        $this->assertSame('uncertain', MedicationBackupAttempt::query()->sole()->state);
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/retry', ['version' => $fresh->version])->assertConflict();
        $this->assertSame(1, $row->fresh()->attempt_count);
    }

    public function test_after_commit_exception_preserves_canonical_sent_artifact_and_password(): void
    {
        $row = $this->prepare($this->schedule());
        $path = $row->artifact_path;
        $password = $row->password;
        $this->openDurableBoundary();
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldReceive('send')->once()->andReturnUsing(function (): void {
            DB::afterCommit(function (): void {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                throw new \RuntimeException('Synthetic committed callback error');
            });
        });
        $this->app->instance(BackupMailTransport::class, $transport);
        try {
            app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->fail('Expected actual postcommit callback failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic committed callback error', $error->getMessage());
        }
        $this->assertSame('sent', $row->fresh()->state);
        $this->assertSame($password, $row->fresh()->password);
        $this->assertTrue(Storage::disk('private')->exists($path));
        $this->assertSame('sent', MedicationBackupAttempt::query()->sole()->state);
    }

    public function test_source_or_recipient_change_before_send_is_revalidated_without_transport(): void
    {
        $row = $this->prepare($this->schedule());
        $this->order->forceFill(['dosage' => 'Changed after preparation'])->saveQuietly();
        $this->openDurableBoundary();
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(BackupMailTransport::class, $transport);
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertConflict();
        $this->assertSame('failed', $row->fresh()->state);
        $this->assertSame('not_submitted', $row->fresh()->failure_code);
    }

    public function test_changed_verified_mailbox_requires_recipient_reapproval_before_send(): void
    {
        $row = $this->prepare($this->schedule());
        $this->recipient->forceFill(['email' => 'new-fictional@example.test'])->saveQuietly();
        $this->openDurableBoundary();
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(BackupMailTransport::class, $transport);
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertConflict();
        $this->assertSame('failed', $row->fresh()->state);
    }

    public function test_moved_person_and_revoked_recipient_cannot_open_historical_chart(): void
    {
        $row = $this->prepare($this->schedule());
        $this->actingAs($this->recipient)->get('/emar/backups/deliveries/'.$row->id.'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->order->client->forceFill(['site_id' => $foreign->id])->saveQuietly();
        $this->actingAs($this->recipient)->get('/emar/backups/deliveries/'.$row->id.'/download')->assertNotFound();
        $this->actingAs($this->recipient)->get('/emar/backups/deliveries/9999999/download')->assertNotFound();
    }

    public function test_password_needs_own_password_current_mfa_and_nonreplayed_code(): void
    {
        $row = $this->prepare($this->schedule());
        $url = '/emar/backups/deliveries/'.$row->id.'/password';
        $this->actingAs($this->recipient)->postJson($url, ['password' => 'fictional-secret', 'verification_code' => '123456'])->assertUnprocessable()->assertJsonMissingPath('password');
        $authenticator = new Google2FA;
        $secret = $authenticator->generateSecretKey();
        $this->recipient->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret), 'two_factor_confirmed_at' => now()])->saveQuietly();
        $code = $authenticator->getCurrentOtp($secret);
        $this->actingAs($this->recipient)->postJson($url, ['password' => 'wrong-password', 'verification_code' => $code])->assertUnprocessable();
        $this->actingAs($this->recipient)->postJson($url, ['password' => 'fictional-secret', 'verification_code' => $code])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('password', $row->password);
        $this->actingAs($this->recipient)->postJson($url, ['password' => 'fictional-secret', 'verification_code' => $code])->assertUnprocessable()->assertJsonMissingPath('password');
        $this->assertDatabaseCount('medication_backup_step_up_uses', 1);
        Mail::assertNothingSent();
    }

    public function test_retention_purges_only_expired_artifact_password_and_clinical_snapshot(): void
    {
        $row = $this->prepare($this->schedule());
        Storage::disk('private')->put('unrelated.txt', 'Fictional unrelated evidence');
        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame(1, app(BackupDeliveryService::class)->purgeExpired());
        $this->assertSame(0, app(BackupDeliveryService::class)->purgeExpired());
        $fresh = $row->fresh();
        $this->assertSame('purged', $fresh->state);
        $this->assertNull($fresh->source_snapshot);
        $this->assertNull($fresh->password);
        $this->assertNull($fresh->artifact_path);
        $this->assertSame(['unrelated.txt'], Storage::disk('private')->allFiles());
        $this->actingAs($this->lead)->get('/emar/backups/deliveries/'.$row->id.'/download')->assertNotFound();
    }

    public function test_interrupted_sending_becomes_uncertain_without_resubmission(): void
    {
        $row = $this->prepare($this->schedule());
        $token = (string) Str::uuid();
        $row->forceFill(['state' => 'sending', 'claim_token' => $token, 'claimed_at' => now()->subHour()])->saveOrFail();
        MedicationBackupAttempt::query()->create(['delivery_id' => $row->id, 'token' => $token, 'actor_id' => $this->lead->id, 'state' => 'sending', 'started_at' => now()->subHour()]);
        $this->assertSame(1, app(BackupDeliveryService::class)->recoverInterrupted());
        $this->assertSame('uncertain', $row->fresh()->state);
        $this->assertSame('uncertain', MedicationBackupAttempt::query()->sole()->state);
        Mail::assertNothingSent();
    }

    public function test_schedule_command_is_unavailable_before_installation_without_querying_new_records(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('medication_backup_schedules')->andReturnFalse();
        $this->assertSame(['prepared' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 1, 'installation' => 'unavailable'], app(BackupDeliveryService::class)->dispatchDue());
        Mail::assertNothingSent();
    }

    public function test_prepare_refuses_an_outer_uncommitted_transaction_before_files_or_delivery(): void
    {
        $schedule = $this->schedule();
        try {
            app(BackupDeliveryService::class)->prepare($this->lead, $this->site->id, '2026-10-07', $schedule->version);
            $this->fail('The per-day preparation claim must commit before expensive I/O.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('outer durable', $error->getMessage());
        }
        $this->assertDatabaseCount('medication_backup_deliveries', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    private function staff(Site $site, bool $manager): User
    {
        $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now(), 'email_verified_at' => now(), 'password' => 'fictional-secret']);
        ensureCanonicalHrStaffProfile($user, $site, ['start_date' => '2025-01-01']);
        $keys = ['medications.view', 'clients.viewAny', 'medications.reports.view', 'medications.reports.export', 'medications.controlled.view'];
        if ($manager) {
            $keys[] = 'medications.backups.manage';
        }
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id');
        $user->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        if (! $manager) {
            $permission = Permission::query()->where('key', 'medications.backups.manage')->sole();
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }

        return $user->fresh();
    }

    private function schedule(bool $approve = true): MedicationBackupSchedule
    {
        $service = app(BackupDeliveryService::class);
        $schedule = $service->schedule($this->lead, $this->site->id, 0, ['local_time' => '07:30', 'enabled' => true, 'retention_days' => 7]);

        return $approve ? $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $this->recipient->id, true) : $schedule;
    }

    private function prepare(MedicationBackupSchedule $schedule): MedicationBackupDelivery
    {
        $this->openDurableBoundary();

        return app(BackupDeliveryService::class)->prepare($this->lead, $this->site->id, '2026-10-07', $schedule->version);
    }

    private function openDurableBoundary(): void
    {
        if ($this->durable) {
            return;
        }
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $this->assertSame(0, DB::transactionLevel());
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
        $this->durable = true;
    }
}
