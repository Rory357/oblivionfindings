<?php

namespace Tests\Feature\Emar;

use App\Mail\MailNotSubmitted;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ItMailboxConnection;
use App\Models\MedicationBackupAttempt;
use App\Models\MedicationBackupDelivery;
use App\Models\MedicationBackupSchedule;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\EmailConfiguration;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\BackupDelivery\BackupDeliveryAccess;
use App\Services\Medication\BackupDelivery\BackupDeliveryService;
use App\Services\Medication\BackupDelivery\BackupEmailSender;
use App\Services\Medication\BackupDelivery\BackupMailTransport;
use App\Services\Medication\BackupDelivery\BackupPdfEncryption;
use App\Services\Medication\BackupDelivery\PreparedBackupOAuthTransport;
use App\Services\Medication\Downtime\DowntimePackPdf;
use Carbon\Carbon;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
        Http::preventStrayRequests();
        config(['emar-catalogue-backups.send_enabled' => false]);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->lead = $this->staff($this->site, true);
        $this->recipient = $this->staff($this->site, false);
        $this->order = ClientMedication::factory()->create(['client_id' => $client->id, 'name' => 'Fictional backup medicine', 'form' => 'tablet', 'dosage' => '10 mg', 'dose_amount' => 10, 'dose_unit' => 'mg', 'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'verified_at' => now(), 'start_date' => '2026-10-06', 'end_date' => null, 'dose_times' => ['09:00'], 'route' => 'oral', 'frequency' => 'Once daily', 'controlled_drug' => false, 'is_prn' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Pacific/Auckland')->utc());
        $encryption = Mockery::mock(BackupPdfEncryption::class);
        $encryption->shouldReceive('ready')->andReturnTrue();
        $encryption->shouldReceive('cleanupStale')->andReturn(0);
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
        $this->assertSame($first->getRawOriginal(), $second->getRawOriginal());
        $this->assertSame('ready', $first->state);
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->assertStringNotContainsString('Fictional backup medicine', $first->getRawOriginal('source_snapshot'));
        $this->assertNotSame($first->password, $first->getRawOriginal('password'));
        $this->assertStringNotContainsString('artifact_path', json_encode(app(BackupDeliveryService::class)->dto($first, $this->lead)));
        $this->assertDatabaseCount('medication_backup_attempts', 0);
        Mail::assertNothingSent();
    }

    public static function staleOwnedReadyBackups(): array
    {
        $cases = [];
        foreach (['schedule', 'recipients', 'clinical source', 'missing artifact', 'corrupted artifact'] as $change) {
            $cases[$change.' through explicit prepare'] = [$change, false];
            $cases[$change.' through due scheduler'] = [$change, true];
        }

        return $cases;
    }

    #[DataProvider('staleOwnedReadyBackups')]
    public function test_stale_owned_ready_backup_rebuilds_before_its_only_submission(string $change, bool $scheduled): void
    {
        $schedule = $this->schedule();
        $row = $this->prepare($schedule);
        $before = $row->getRawOriginal();
        $oldPath = $row->artifact_path;
        $bytes = Storage::disk('private')->get($oldPath);
        $service = app(BackupDeliveryService::class);
        $recipientIds = [$this->recipient->id];
        $mailboxes = [$this->recipient->email];
        if ($change === 'schedule') {
            $schedule = $service->schedule($this->lead, $this->site->id, $schedule->version, ['local_time' => '08:00', 'enabled' => true, 'retention_days' => 10]);
        } elseif ($change === 'recipients') {
            $second = $this->staff($this->site, false);
            $schedule = $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $second->id, true);
            $recipientIds[] = $second->id;
            $mailboxes[] = $second->email;
        } elseif ($change === 'clinical source') {
            $this->order->forceFill(['dosage' => '20 mg from current canonical chart'])->saveQuietly();
        } elseif ($change === 'missing artifact') {
            Storage::disk('private')->delete($oldPath);
        } else {
            Storage::disk('private')->put($oldPath, '%PDF-FICTIONAL-CORRUPTED');
        }
        // The new artifact is rendered outside the claim transaction; prior metadata
        // stays recoverable until a newly checked replacement is published.
        $pdf = Mockery::mock(DowntimePackPdf::class);
        $pdf->shouldReceive('render')->once()->andReturnUsing(function (array $pack) use ($row, $before, $bytes, $change): string {
            $preparing = $row->fresh();
            $this->assertSame('preparing', $preparing->state);
            foreach (['artifact_path', 'artifact_sha256', 'password', 'source_snapshot', 'source_sha256', 'recipient_ids', 'recipient_sha256', 'expires_at', 'prepared_by'] as $field) {
                $this->assertSame($before[$field], $preparing->getRawOriginal($field));
            }
            if (! in_array($change, ['missing artifact', 'corrupted artifact'], true)) {
                $this->assertSame($bytes, Storage::disk('private')->get($preparing->artifact_path));
            }
            $this->assertSame($this->lead->name, $pack['printed_by']);
            $this->assertSame($change === 'clinical source' ? '20 mg from current canonical chart' : '10 mg', $pack['_source']['evidence']['orders'][0]['dosage']);

            return '%PDF-1.7 fictional fresh replacement';
        });
        $this->app->instance(DowntimePackPdf::class, $pdf);
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldReceive('send')->once()->with($mailboxes, '%PDF-PROTECTED-FICTIONAL', '2026-10-07');
        $this->app->instance(BackupMailTransport::class, $transport);
        $service = app(BackupDeliveryService::class);
        if ($scheduled) {
            $this->assertSame(['prepared' => 1, 'sent' => 1, 'failed' => 0, 'disabled' => 0], $service->dispatchDue());
        } else {
            $this->actingAs($this->lead)->postJson('/emar/backups/sites/'.$this->site->id.'/prepare', ['version' => $schedule->version, 'nz_date' => '2026-10-07'])->assertOk()->assertJsonPath('state', 'ready');
            $this->assertDatabaseCount('medication_backup_attempts', 0);
            $prepared = $row->fresh();
            $this->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $prepared->version])->assertOk()->assertJsonPath('state', 'sent');
        }
        $fresh = $row->fresh();
        $this->assertSame('sent', $fresh->state);
        $this->assertSame($row->id, $fresh->id);
        $this->assertSame($schedule->version, $fresh->schedule_version);
        $this->assertSame($recipientIds, $fresh->recipient_ids);
        $this->assertSame($this->lead->id, $fresh->prepared_by);
        $this->assertNotSame($oldPath, $fresh->artifact_path);
        $this->assertFalse(Storage::disk('private')->exists($oldPath));
        $this->assertSame([$fresh->artifact_path], Storage::disk('private')->allFiles());
        $this->assertNull($fresh->claim_token);
        $this->assertSame(1, $fresh->attempt_count);
        $this->assertSame('sent', MedicationBackupAttempt::query()->sole()->state);
        $this->assertSame($this->lead->id, MedicationBackupAttempt::query()->sole()->actor_id);
        $this->assertSame([$this->lead->id, $this->lead->id], DB::table('medication_events')->where('kind', 'backup.prepared')->where('subject_id', (string) $row->id)->orderBy('sequence')->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        $sent = $fresh->getRawOriginal();
        $this->assertSame(['prepared' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0], $service->dispatchDue());
        $this->assertSame($sent, $row->fresh()->getRawOriginal());
        $this->assertDatabaseCount('medication_backup_deliveries', 1);
        $this->assertDatabaseCount('medication_backup_attempts', 1);
        Mail::assertNothingSent();
    }

    public function test_owned_expired_ready_backup_can_refresh_on_the_same_25_hour_nz_day(): void
    {
        $this->openDurableBoundary();
        Carbon::setTestNow(Carbon::parse('2026-04-05 00:05', 'Pacific/Auckland')->utc());
        foreach ([$this->lead, $this->recipient] as $actor) {
            $actor->forceFill(['approved_at' => now()->subDay(), 'email_verified_at' => now()->subDay()])->saveQuietly();
        }
        $this->order->forceFill(['start_date' => '2026-04-01', 'verified_at' => now()->subDay()])->saveQuietly();
        $service = app(BackupDeliveryService::class);
        $schedule = $service->schedule($this->lead, $this->site->id, 0, ['local_time' => '23:10', 'enabled' => true, 'retention_days' => 1]);
        $schedule = $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $this->recipient->id, true);
        $row = $service->prepare($this->lead, $this->site->id, '2026-04-05', $schedule->version);
        $oldPath = $row->artifact_path;
        $this->assertSame('2026-04-05T11:05:00+00:00', $row->expires_at->utc()->toIso8601String());
        Carbon::setTestNow(Carbon::parse('2026-04-05 23:10', 'Pacific/Auckland')->utc());
        $this->assertSame($row->nz_date, now('Pacific/Auckland')->toDateString());
        $this->assertTrue($row->expires_at->isPast());
        $this->actingAs($this->lead)->get('/emar/backups/deliveries/'.$row->id.'/download')->assertNotFound();
        $this->postJson('/emar/backups/sites/'.$this->site->id.'/prepare', ['version' => $schedule->version, 'nz_date' => '2026-04-05'])->assertOk()->assertJsonPath('state', 'ready');
        $fresh = $row->fresh();
        $this->assertSame($row->id, $fresh->id);
        $this->assertTrue($fresh->expires_at->isFuture());
        $this->assertNotSame($oldPath, $fresh->artifact_path);
        $this->assertFalse(Storage::disk('private')->exists($oldPath));
        $this->assertSame([$fresh->artifact_path], Storage::disk('private')->allFiles());
        $this->assertSame(1, $schedule->fresh()->retention_days);
        $this->assertDatabaseCount('medication_backup_deliveries', 1);
        $this->assertDatabaseCount('medication_backup_attempts', 0);
        Mail::assertNothingSent();
    }

    public static function unrelatedRecipientChanges(): array
    {
        return ['last approval revoked' => ['revoked', 404, 422],
            'unrelated recipient employment ended' => ['inactive', 403, 403],
            'unrelated verified mailbox changed' => ['mailbox', 409, 409]];
    }

    #[DataProvider('unrelatedRecipientChanges')]
    public function test_retained_chart_reads_require_the_current_reader_without_using_unrelated_recipient_authority(string $change, int $readDenied, int $submissionDenied): void
    {
        $schedule = $this->schedule();
        $service = app(BackupDeliveryService::class);
        $invalid = $this->recipient;
        if ($change !== 'revoked') {
            $invalid = $this->staff($this->site, false);
            $schedule = $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $invalid->id, true);
        }
        $row = $this->prepare($schedule);
        $raw = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        if ($change === 'revoked') {
            $schedule = $service->approveRecipient($this->lead, $schedule->id, $schedule->version, $invalid->id, false);
        } elseif ($change === 'inactive') {
            $invalid->hrEmployeeProfile->forceFill(['is_active' => false])->saveQuietly();
        } else {
            $invalid->forceFill(['email' => 'unrelated-changed-fictional@example.test'])->saveQuietly();
        }
        foreach ($change === 'revoked' ? [$this->lead] : [$this->lead, $this->recipient] as $reader) {
            $this->actingAs($reader)->get('/emar/backups/deliveries/'.$row->id.'/download')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->assertContent($bytes);
            $authenticator = new Google2FA;
            $secret = $authenticator->generateSecretKey();
            $reader->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret), 'two_factor_confirmed_at' => now()])->saveQuietly();
            $this->postJson('/emar/backups/deliveries/'.$row->id.'/password', ['password' => 'fictional-secret', 'verification_code' => $authenticator->getCurrentOtp($secret)])
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('password', $row->password);
        }
        $this->actingAs($invalid)->get('/emar/backups/deliveries/'.$row->id.'/download')->assertStatus($readDenied);
        $this->postJson('/emar/backups/deliveries/'.$row->id.'/password', ['password' => 'fictional-secret', 'verification_code' => '123456'])
            ->assertStatus($readDenied)->assertJsonMissingPath('password');
        // This exception is for an individually authorized retained reader only;
        // all active recipients must still be eligible before preparing or sending.
        $this->actingAs($this->lead)->postJson('/emar/backups/sites/'.$this->site->id.'/prepare', ['version' => $schedule->version, 'nz_date' => '2026-10-07'])->assertStatus($submissionDenied);
        $this->assertSame($raw, $row->fresh()->getRawOriginal());
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(BackupMailTransport::class, $transport);
        $this->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertStatus($change === 'revoked' ? 409 : $submissionDenied);
        $fresh = $row->fresh();
        $this->assertSame('failed', $fresh->state);
        $this->assertSame('not_submitted', $fresh->failure_code);
        $this->assertRetainedArtifact($fresh, $raw, $bytes);
        $this->assertSame('failed', MedicationBackupAttempt::query()->sole()->state);
        $this->assertDatabaseCount('medication_backup_deliveries', 1);
        $this->assertDatabaseCount('medication_backup_attempts', 1);
        Mail::assertNothingSent();
    }

    public static function mailboxApprovalKeys(): array
    {
        return [
            'current raw key' => [false, false],
            'current base64 key' => [true, false],
            'retained previous raw key' => [false, true],
            'retained previous base64 key' => [true, true],
        ];
    }

    #[DataProvider('mailboxApprovalKeys')]
    public function test_unchanged_mailbox_approvals_and_prepared_recipient_digest_survive_current_or_retained_keys(bool $base64, bool $rotate): void
    {
        $beforeKey = (string) config('app.key');
        $beforePrevious = config('app.previous_keys', []);
        $oldBytes = str_repeat('a', 32);
        $oldKey = $base64 ? 'base64:'.base64_encode($oldBytes) : $oldBytes;
        $newBytes = str_repeat('b', 32);
        $newKey = 'base64:'.base64_encode($newBytes);
        try {
            $this->useBackupKeys($oldKey);
            $this->assertSame($oldBytes, app('encrypter')->getKey());
            $authenticator = new Google2FA;
            $secret = $authenticator->generateSecretKey();
            $this->recipient->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret), 'two_factor_confirmed_at' => now()])->saveQuietly();
            $schedule = $this->schedule();
            $approval = $schedule->recipients()->sole()->getRawOriginal();
            $row = $this->prepare($schedule);
            $raw = $row->getRawOriginal();
            $password = $row->password;
            $bytes = Storage::disk('private')->get($row->artifact_path);
            $this->assertSame(hash_hmac('sha256', mb_strtolower(trim($this->recipient->email)), $oldKey), $approval['email_sha256']);
            $this->assertSame(hash_hmac('sha256', json_encode([$this->recipient->id => $this->recipient->email], JSON_THROW_ON_ERROR), $oldKey), $raw['recipient_sha256']);
            if ($rotate) {
                $this->useBackupKeys($newKey, [$oldKey]);
                $this->assertSame([$newBytes, $oldBytes], app('encrypter')->getAllKeys());
                $this->assertNotSame($approval['email_sha256'], app(BackupDeliveryAccess::class)->emailHash($this->recipient));
            }

            $this->actingAs($this->lead)->get('/emar/backups')->assertOk()
                ->assertInertia(fn (Assert $p) => $p->component('emar/backups/index')
                    ->where('schedules.0.version', $schedule->version)
                    ->where('schedules.0.recipients.0.user_id', $this->recipient->id)
                    ->where('schedules.0.recipients.0.status', 'approved'));
            // Exercise actual retained ciphertext and personal MFA, not a cached old encrypter.
            $this->assertSame($password, $row->fresh()->password);
            $this->actingAs($this->recipient)->get('/emar/backups/deliveries/'.$row->id.'/download')
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertContent($bytes);
            $code = $authenticator->getCurrentOtp($secret);
            $this->postJson('/emar/backups/deliveries/'.$row->id.'/password', ['password' => 'fictional-secret', 'verification_code' => $code])
                ->assertOk()->assertJsonPath('password', $password);
            $prepared = app(BackupDeliveryService::class)->prepare($this->lead, $this->site->id, '2026-10-07', $schedule->version);
            $this->assertSame($row->id, $prepared->id);
            $this->assertSame($raw, $prepared->getRawOriginal());
            $this->assertSame($approval, $schedule->recipients()->sole()->getRawOriginal());
            $this->assertSame($schedule->version, $schedule->fresh()->version);

            config(['emar-catalogue-backups.send_enabled' => true]);
            $transport = Mockery::mock(BackupMailTransport::class);
            $transport->shouldReceive('send')->once()->with([$this->recipient->email], $bytes, '2026-10-07');
            $this->app->instance(BackupMailTransport::class, $transport);
            $sent = app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->assertSame('sent', $sent->state);
            $this->assertSame($raw['recipient_sha256'], $sent->getRawOriginal('recipient_sha256'));
            $this->assertSame($raw['password'], $sent->getRawOriginal('password'));
            $this->assertDatabaseCount('medication_backup_deliveries', 1);
            $this->assertDatabaseCount('medication_backup_attempts', 1);
            $this->assertSame('sent', MedicationBackupAttempt::query()->sole()->state);
            $this->assertDatabaseCount('medication_backup_step_up_uses', 1);
            Mail::assertNothingSent();
        } finally {
            $this->useBackupKeys($beforeKey, $beforePrevious);
        }
    }

    public static function mailboxRotationDenials(): array
    {
        return [
            'previous key not retained' => [false],
            'verified mailbox genuinely changed with previous key retained' => [true],
        ];
    }

    #[DataProvider('mailboxRotationDenials')]
    public function test_rotated_key_does_not_authorize_changed_mailbox_or_unretained_approval(bool $changed): void
    {
        $beforeKey = (string) config('app.key');
        $beforePrevious = config('app.previous_keys', []);
        $oldKey = 'base64:'.base64_encode(str_repeat('a', 32));
        try {
            $this->useBackupKeys($oldKey);
            $schedule = $this->schedule();
            $row = $this->prepare($schedule);
            $approval = $schedule->recipients()->sole()->getRawOriginal();
            $raw = $row->getRawOriginal();
            $bytes = Storage::disk('private')->get($row->artifact_path);
            $this->useBackupKeys('base64:'.base64_encode(str_repeat('b', 32)), $changed ? [$oldKey] : []);
            if ($changed) {
                $this->recipient->forceFill(['email' => 'changed-fictional@example.test'])->saveQuietly();
            }
            $this->actingAs($this->lead)->get('/emar/backups')->assertOk()
                ->assertInertia(fn (Assert $p) => $p->where('schedules.0.recipients.0.status', 'review_required'));
            $this->actingAs($this->recipient)->get('/emar/backups/deliveries/'.$row->id.'/download')->assertConflict();
            $this->postJson('/emar/backups/deliveries/'.$row->id.'/password', ['password' => 'fictional-secret', 'verification_code' => '123456'])
                ->assertConflict()->assertJsonMissingPath('password');
            $this->actingAs($this->lead)->postJson('/emar/backups/sites/'.$this->site->id.'/prepare', ['version' => $schedule->version, 'nz_date' => '2026-10-07'])->assertConflict();
            $this->assertSame($raw, $row->fresh()->getRawOriginal());
            $this->assertSame($approval, $schedule->recipients()->sole()->getRawOriginal());
            config(['emar-catalogue-backups.send_enabled' => true]);
            $transport = Mockery::mock(BackupMailTransport::class);
            $transport->shouldNotReceive('send');
            $this->app->instance(BackupMailTransport::class, $transport);
            $this->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertConflict();
            $fresh = $row->fresh();
            $this->assertSame('failed', $fresh->state);
            $this->assertSame('not_submitted', $fresh->failure_code);
            $this->assertSame($raw['artifact_path'], $fresh->getRawOriginal('artifact_path'));
            $this->assertSame($raw['password'], $fresh->getRawOriginal('password'));
            $this->assertSame($bytes, Storage::disk('private')->get($raw['artifact_path']));
            $this->assertSame('failed', MedicationBackupAttempt::query()->sole()->state);
            Mail::assertNothingSent();
        } finally {
            $this->useBackupKeys($beforeKey, $beforePrevious);
        }
    }

    public function test_unretained_prepared_recipient_digest_is_rejected_even_when_mailbox_approval_uses_current_key(): void
    {
        $beforeKey = (string) config('app.key');
        $beforePrevious = config('app.previous_keys', []);
        $currentKey = 'base64:'.base64_encode(str_repeat('b', 32));
        $oldKey = 'base64:'.base64_encode(str_repeat('a', 32));
        try {
            $this->useBackupKeys($currentKey);
            $schedule = $this->schedule();
            $approval = $schedule->recipients()->sole()->getRawOriginal();
            $this->useBackupKeys($oldKey, [$currentKey]);
            $row = $this->prepare($schedule);
            $preparedDigest = $row->getRawOriginal('recipient_sha256');
            $this->useBackupKeys($currentKey);
            $access = app(BackupDeliveryAccess::class);
            $this->assertTrue($access->emailHashMatches($this->recipient, $approval['email_sha256']));
            $this->assertFalse($access->recipientDigestMatches([$this->recipient->id => $this->recipient->email], $preparedDigest));
            config(['emar-catalogue-backups.send_enabled' => true]);
            $transport = Mockery::mock(BackupMailTransport::class);
            $transport->shouldNotReceive('send');
            $this->app->instance(BackupMailTransport::class, $transport);
            $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertConflict();
            $this->assertSame('failed', $row->fresh()->state);
            $this->assertSame('not_submitted', $row->fresh()->failure_code);
            $this->assertSame($preparedDigest, $row->fresh()->getRawOriginal('recipient_sha256'));
            $this->assertSame($approval, $schedule->recipients()->sole()->getRawOriginal());
            $this->assertSame('failed', MedicationBackupAttempt::query()->sole()->state);
            Mail::assertNothingSent();
        } finally {
            $this->useBackupKeys($beforeKey, $beforePrevious);
        }
    }

    public function test_mailbox_hash_verification_rejects_blank_and_unmatched_configured_keys(): void
    {
        $beforeKey = (string) config('app.key');
        $beforePrevious = config('app.previous_keys', []);
        try {
            config(['app.key' => '', 'app.previous_keys' => ['']]);
            $access = app(BackupDeliveryAccess::class);
            $recipients = [$this->recipient->id => $this->recipient->email];
            $this->assertFalse($access->emailHashMatches($this->recipient, hash_hmac('sha256', mb_strtolower(trim($this->recipient->email)), '')));
            $this->assertFalse($access->recipientDigestMatches($recipients, hash_hmac('sha256', json_encode($recipients, JSON_THROW_ON_ERROR), '')));
            config(['app.key' => str_repeat('b', 32), 'app.previous_keys' => ['', str_repeat('a', 32)]]);
            $this->assertFalse($access->emailHashMatches($this->recipient, str_repeat('0', 64)));
            $this->assertFalse($access->recipientDigestMatches($recipients, str_repeat('0', 64)));
            Mail::assertNothingSent();
        } finally {
            $this->useBackupKeys($beforeKey, $beforePrevious);
        }
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

    public function test_retry_claim_rollback_keeps_the_previous_downloadable_artifact_and_raw_metadata(): void
    {
        $row = $this->failedDelivery();
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        try {
            $this->onDeliveryUpdate('preparing', function () use ($row): void {
                $this->assertSame('preparing', DB::table('medication_backup_deliveries')->where('id', $row->id)->value('state'));
                $this->assertTrue(DB::connection()->getPdo()->inTransaction());
                throw new \RuntimeException('Synthetic claim update rollback');
            }, fn () => app(BackupDeliveryService::class)->retry($this->lead, $row->id, $row->version));
            $this->fail('The claim update failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic claim update rollback', $error->getMessage());
        }
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertRetainedArtifact($row->fresh(), $before, $bytes);
        $this->assertSame($row->id, app(BackupDeliveryService::class)->readable($this->lead, $row->id)->id);
        $this->assertDatabaseCount('medication_backup_attempts', 1);
        Mail::assertNothingSent();
    }

    public function test_replacement_render_failure_retains_prior_metadata_expiry_and_readable_bytes(): void
    {
        $row = $this->failedDelivery();
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        Carbon::setTestNow(now()->addHour());
        $pdf = Mockery::mock(DowntimePackPdf::class);
        $pdf->shouldReceive('render')->once()->andReturnUsing(function () use ($row, $before, $bytes): string {
            $preparing = $row->fresh();
            $this->assertSame('preparing', $preparing->state);
            $this->assertRetainedArtifact($preparing, $before, $bytes);
            $dto = app(BackupDeliveryService::class)->dto($preparing, $this->lead);
            $this->assertFalse($dto['can_download']);
            $this->assertFalse($dto['can_reveal']);
            $this->assertFalse($dto['can_send']);
            throw new \RuntimeException('Synthetic replacement render failure');
        });
        $this->app->instance(DowntimePackPdf::class, $pdf);
        try {
            $schedule = MedicationBackupSchedule::query()->findOrFail($row->schedule_id);
            app(BackupDeliveryService::class)->prepare($this->lead, $row->site_id, $row->nz_date, $schedule->version);
            $this->fail('The render failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic replacement render failure', $error->getMessage());
        }
        $fresh = $row->fresh();
        $this->assertSame('failed', $fresh->state);
        $this->assertNull($fresh->claim_token);
        $this->assertRetainedArtifact($fresh, $before, $bytes);
        $this->assertSame($row->id, app(BackupDeliveryService::class)->readable($this->lead, $row->id)->id);
        $this->assertSame([$row->artifact_path], Storage::disk('private')->allFiles());
        Mail::assertNothingSent();
    }

    public static function publicationFailures(): array
    {
        return ['rolled-back publication' => [false], 'committed publication callback' => [true]];
    }

    #[DataProvider('publicationFailures')]
    public function test_replacement_publication_failure_preserves_committed_artifact_and_recovery_evidence(bool $committed): void
    {
        $row = $this->failedDelivery();
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        $oldPath = $row->artifact_path;
        try {
            $this->onDeliveryUpdate('ready', function () use ($row, $committed): void {
                $this->assertSame('ready', DB::table('medication_backup_deliveries')->where('id', $row->id)->value('state'));
                if ($committed) {
                    DB::afterCommit(function (): void {
                        $this->assertSame(0, DB::transactionLevel());
                        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                        throw new \RuntimeException('Synthetic publication failure');
                    });
                } else {
                    throw new \RuntimeException('Synthetic publication failure');
                }
            }, function () use ($row) {
                $schedule = MedicationBackupSchedule::query()->findOrFail($row->schedule_id);

                return app(BackupDeliveryService::class)->prepare($this->lead, $row->site_id, $row->nz_date, $schedule->version);
            });
            $this->fail('The publication failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic publication failure', $error->getMessage());
        }
        $fresh = $row->fresh();
        if ($committed) {
            $this->assertSame('ready', $fresh->state);
            $this->assertNotSame($oldPath, $fresh->artifact_path);
            $this->assertSame(basename($oldPath, '.pdf'), $fresh->claim_token);
            $this->assertTrue(Storage::disk('private')->exists($oldPath));
            $this->assertTrue(Storage::disk('private')->exists($fresh->artifact_path));
            $published = $fresh->getRawOriginal();
            $publishedBytes = Storage::disk('private')->get($fresh->artifact_path);
            $this->assertSame(0, app(BackupDeliveryService::class)->recoverInterrupted());
            $this->assertRetainedArtifact($row->fresh(), $published, $publishedBytes);
            $this->assertFalse(Storage::disk('private')->exists($oldPath));
        } else {
            $this->assertSame('failed', $fresh->state);
            $this->assertRetainedArtifact($fresh, $before, $bytes);
        }
        $this->assertNull($row->fresh()->claim_token);
        $this->assertSame($row->id, app(BackupDeliveryService::class)->readable($this->lead, $row->id)->id);
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('medication_backup_attempts', 1);
        Mail::assertNothingSent();
    }

    public function test_interrupted_replacement_cleans_only_new_staging_and_retains_previous_backup(): void
    {
        $row = $this->failedDelivery();
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        $token = (string) Str::uuid();
        $staged = 'medication-backups/'.$row->id.'/'.$token.'.pdf';
        $row->forceFill(['state' => 'preparing', 'claim_token' => $token, 'claimed_at' => now()->subHour()])->saveOrFail();
        Storage::disk('private')->put($staged, '%PDF-PROTECTED-INTERRUPTED-FICTIONAL');
        $this->assertSame(1, app(BackupDeliveryService::class)->recoverInterrupted());
        $fresh = $row->fresh();
        $this->assertSame('failed', $fresh->state);
        $this->assertNull($fresh->claim_token);
        $this->assertRetainedArtifact($fresh, $before, $bytes);
        $this->assertFalse(Storage::disk('private')->exists($staged));
        $this->assertSame($row->id, app(BackupDeliveryService::class)->readable($this->lead, $row->id)->id);
        $this->assertCount(1, Storage::disk('private')->allFiles());
        Mail::assertNothingSent();
    }

    public static function cleanupRetries(): array
    {
        return ['scheduled recovery' => ['recovery'], 'explicit preparation' => ['prepare'], 'explicit stale preparation after cleanup' => ['prepare_stale']];
    }

    #[DataProvider('cleanupRetries')]
    public function test_previous_artifact_delete_failure_is_visible_and_durably_retryable(string $retry): void
    {
        $row = $this->failedDelivery();
        $oldPath = $row->artifact_path;
        $disk = Storage::disk('private');
        $blockedDisk = Mockery::mock($disk);
        $blockedDisk->shouldReceive('delete')->once()->with($oldPath)->andReturnFalse();
        Storage::set('private', $blockedDisk);
        $schedule = MedicationBackupSchedule::query()->findOrFail($row->schedule_id);
        try {
            $replacement = app(BackupDeliveryService::class)->prepare($this->lead, $row->site_id, $row->nz_date, $schedule->version);
        } finally {
            Storage::set('private', $disk);
        }
        $this->assertSame('ready', $replacement->state);
        $this->assertSame(basename($oldPath, '.pdf'), $replacement->claim_token);
        $this->assertSame('waiting_for_previous_backup_cleanup_before_sending', $replacement->failure_code);
        $this->assertTrue($disk->exists($oldPath));
        $this->assertTrue($disk->exists($replacement->artifact_path));
        $dto = app(BackupDeliveryService::class)->dto($replacement, $this->lead);
        $this->assertFalse($dto['can_send']);
        $this->assertTrue($dto['can_download']);
        $this->assertTrue($dto['can_reveal']);
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $replacement->version])
            ->assertConflict()->assertJsonPath('message', 'The previous protected backup is still being cleaned up. Prepare again to retry cleanup before sending.');
        $this->assertDatabaseCount('medication_backup_attempts', 1);
        $published = $replacement->getRawOriginal();
        $bytes = $disk->get($replacement->artifact_path);
        $disk->put('unrelated.txt', 'Fictional unrelated evidence');
        if ($retry === 'recovery') {
            $this->assertSame(0, app(BackupDeliveryService::class)->recoverInterrupted());
        } else {
            if ($retry === 'prepare_stale') {
                $schedule = app(BackupDeliveryService::class)->schedule($this->lead, $row->site_id, $schedule->version, ['local_time' => '08:00', 'enabled' => true, 'retention_days' => 7]);
            }
            $again = app(BackupDeliveryService::class)->prepare($this->lead, $row->site_id, $row->nz_date, $schedule->version);
            $this->assertSame($replacement->id, $again->id);
        }
        $fresh = $row->fresh();
        $this->assertNull($fresh->claim_token);
        $this->assertNull($fresh->failure_code);
        if ($retry === 'prepare_stale') {
            $this->assertSame($schedule->version, $fresh->schedule_version);
            $this->assertNotSame($published['artifact_path'], $fresh->artifact_path);
            $this->assertFalse($disk->exists($published['artifact_path']));
            $this->assertDatabaseCount('medication_backup_deliveries', 1);
            $this->assertDatabaseCount('medication_backup_attempts', 1);
        } else {
            $this->assertRetainedArtifact($fresh, $published, $bytes);
        }
        $this->assertFalse($disk->exists($oldPath));
        $this->assertTrue($disk->exists('unrelated.txt'));
        $this->assertCount(2, $disk->allFiles());
        $this->assertTrue(app(BackupDeliveryService::class)->dto($fresh, $this->lead)['can_send']);
        Mail::assertNothingSent();
    }

    public function test_replacement_rejects_a_previous_artifact_outside_its_exact_owned_delivery_path(): void
    {
        $row = $this->failedDelivery();
        $foreignPath = 'medication-backups/'.($row->id + 1000).'/'.Str::uuid().'.pdf';
        Storage::disk('private')->put($foreignPath, '%PDF-PROTECTED-FOREIGN-FICTIONAL');
        $row->forceFill(['artifact_path' => $foreignPath])->saveOrFail();
        $before = $row->getRawOriginal();
        $schedule = MedicationBackupSchedule::query()->findOrFail($row->schedule_id);
        $this->actingAs($this->lead)->postJson('/emar/backups/sites/'.$row->site_id.'/prepare', ['version' => $schedule->version, 'nz_date' => $row->nz_date])
            ->assertConflict()->assertJsonPath('message', 'The previous protected backup reference could not be verified.');
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertTrue(Storage::disk('private')->exists($foreignPath));
        $this->assertDatabaseCount('medication_backup_attempts', 1);
        Mail::assertNothingSent();
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
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->twice()->andReturnUsing(function (string $body, \Closure $compose) use (&$calls, &$accepted, $addresses) {
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
        $sender = Mockery::mock(BackupEmailSender::class);
        $sender->shouldReceive('prepare')->once()->andReturnNull();
        $sender->shouldReceive('make')->once()->with($addresses)->andReturn($mailer);
        $this->app->instance(BackupEmailSender::class, $sender);
        $this->app->instance(BackupMailTransport::class, new BackupMailTransport($sender));
        $service = app(BackupDeliveryService::class);
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

    public function test_due_schedule_rebuilds_another_leads_unsent_chart_with_truthful_current_author_and_sends_once(): void
    {
        $schedule = $this->schedule();
        $other = $this->staff($this->site, true);
        $this->openDurableBoundary();
        Carbon::setTestNow(Carbon::parse('2026-10-07 06:00', 'Pacific/Auckland')->utc());
        $service = app(BackupDeliveryService::class);
        $row = $service->prepare($other, $this->site->id, '2026-10-07', $schedule->version);
        $before = $row->getRawOriginal();
        $oldPath = $row->artifact_path;
        $this->assertSame($other->id, $row->prepared_by);
        $this->assertSame($other->name, $row->source_snapshot['printed_by']);
        // Manual preparation stays idempotent and cannot silently take over another author's chart.
        $same = $service->prepare($this->lead, $this->site->id, '2026-10-07', $schedule->version);
        $this->assertSame($before, $same->getRawOriginal());
        $this->assertSame(['prepared' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0], $service->dispatchDue());
        $this->assertSame($before, $row->fresh()->getRawOriginal());

        config(['emar-catalogue-backups.send_enabled' => true]);
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/send', ['version' => $row->version])->assertForbidden();
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        // The new chart is built by the schedule's approver; the former author need not remain employed.
        $other->forceFill(['approved_at' => null])->saveQuietly();
        $other->hrEmployeeProfile->forceFill(['is_active' => false])->saveQuietly();
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Pacific/Auckland')->utc());
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldReceive('send')->once()->with([$this->recipient->email], '%PDF-PROTECTED-FICTIONAL', '2026-10-07');
        $this->app->instance(BackupMailTransport::class, $transport);
        $service = app(BackupDeliveryService::class);
        $this->assertSame(['prepared' => 1, 'sent' => 1, 'failed' => 0, 'disabled' => 0], $service->dispatchDue());
        $fresh = $row->fresh();
        $this->assertSame('sent', $fresh->state);
        $this->assertSame($this->lead->id, $fresh->prepared_by);
        $this->assertSame($this->lead->name, $fresh->source_snapshot['printed_by']);
        $this->assertNotSame($oldPath, $fresh->artifact_path);
        $this->assertFalse(Storage::disk('private')->exists($oldPath));
        $this->assertSame([$fresh->artifact_path], Storage::disk('private')->allFiles());
        $this->assertNull($fresh->claim_token);
        $this->assertSame(1, $fresh->attempt_count);
        $this->assertSame($this->lead->id, MedicationBackupAttempt::query()->sole()->actor_id);
        $this->assertSame([$other->id, $this->lead->id], DB::table('medication_events')->where('kind', 'backup.prepared')->where('subject_id', (string) $row->id)->orderBy('sequence')->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$this->lead->id], DB::table('medication_events')->where('kind', 'backup.sent')->where('subject_id', (string) $row->id)->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['prepared' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0], $service->dispatchDue());
        $this->assertDatabaseCount('medication_backup_deliveries', 1);
        $this->assertDatabaseCount('medication_backup_attempts', 1);
        Mail::assertNothingSent();
    }

    public function test_due_schedule_cannot_rebuild_another_authors_chart_after_approver_authority_is_revoked(): void
    {
        $schedule = $this->schedule();
        $other = $this->staff($this->site, true);
        $this->openDurableBoundary();
        $row = app(BackupDeliveryService::class)->prepare($other, $this->site->id, '2026-10-07', $schedule->version);
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        $this->lead->forceFill(['approved_at' => null])->saveQuietly();
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(BackupMailTransport::class, $transport);
        $this->assertSame(['prepared' => 0, 'sent' => 0, 'failed' => 1, 'disabled' => 0], app(BackupDeliveryService::class)->dispatchDue());
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertRetainedArtifact($row->fresh(), $before, $bytes);
        $this->assertSame([$row->artifact_path], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('medication_backup_attempts', 0);
        $this->assertSame(1, DB::table('medication_events')->where('kind', 'backup.prepared')->count());
        Mail::assertNothingSent();
    }

    public static function scheduledRebuildChanges(): array
    {
        return ['clinical source changed' => [false], 'canonical schedule disabled' => [true]];
    }

    #[DataProvider('scheduledRebuildChanges')]
    public function test_scheduled_rebuild_revalidates_source_and_versioned_schedule_and_preserves_prior_author_on_failure(bool $scheduleChanged): void
    {
        $schedule = $this->schedule();
        $other = $this->staff($this->site, true);
        $this->openDurableBoundary();
        $row = app(BackupDeliveryService::class)->prepare($other, $this->site->id, '2026-10-07', $schedule->version);
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        $pdf = Mockery::mock(DowntimePackPdf::class);
        $pdf->shouldReceive('render')->once()->andReturnUsing(function (array $pack) use ($row, $before, $bytes, $schedule, $scheduleChanged): string {
            $preparing = $row->fresh();
            $this->assertSame('preparing', $preparing->state);
            $this->assertRetainedArtifact($preparing, $before, $bytes);
            $this->assertSame($this->lead->name, $pack['printed_by']);
            $dto = app(BackupDeliveryService::class)->dto($preparing, $this->lead);
            $this->assertFalse($dto['can_download']);
            $this->assertFalse($dto['can_reveal']);
            $this->assertFalse($dto['can_send']);
            if ($scheduleChanged) {
                // Use the canonical versioned edit rather than an impossible unversioned schedule mutation.
                $edited = app(BackupDeliveryService::class)->schedule($this->lead, $this->site->id, $schedule->version, ['local_time' => '07:30', 'enabled' => false, 'retention_days' => 7]);
                $this->assertSame($schedule->version + 1, $edited->version);
            } else {
                $this->order->forceFill(['dosage' => 'Changed during scheduled rebuild'])->saveQuietly();
            }

            return '%PDF-1.7 fictional replacement bytes';
        });
        $this->app->instance(DowntimePackPdf::class, $pdf);
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(BackupMailTransport::class, $transport);
        $this->assertSame(['prepared' => 0, 'sent' => 0, 'failed' => 1, 'disabled' => 0], app(BackupDeliveryService::class)->dispatchDue());
        $fresh = $row->fresh();
        $this->assertSame('failed', $fresh->state);
        $this->assertSame($other->id, $fresh->prepared_by);
        $this->assertSame('preparation_failed', $fresh->failure_code);
        $this->assertNull($fresh->claim_token);
        $this->assertRetainedArtifact($fresh, $before, $bytes);
        $this->assertSame([$row->artifact_path], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('medication_backup_deliveries', 1);
        $this->assertDatabaseCount('medication_backup_attempts', 0);
        $this->assertSame([$other->id], DB::table('medication_events')->where('kind', 'backup.prepared')->where('subject_id', (string) $row->id)->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
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

    private function failedDelivery(): MedicationBackupDelivery
    {
        $row = $this->prepare($this->schedule());
        config(['emar-catalogue-backups.send_enabled' => true]);
        $transport = Mockery::mock(BackupMailTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new MailNotSubmitted('Synthetic no submission'));
        $this->app->instance(BackupMailTransport::class, $transport);
        try {
            app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->fail('The fixture must establish a real known-unsent delivery.');
        } catch (MailNotSubmitted) {
            $this->assertSame('failed', $row->fresh()->state);
        }

        return $row->fresh();
    }

    private function assertRetainedArtifact(MedicationBackupDelivery $row, array $before, string $bytes): void
    {
        foreach (['artifact_path', 'artifact_sha256', 'password', 'source_snapshot', 'source_sha256', 'recipient_ids', 'recipient_sha256', 'expires_at', 'prepared_by'] as $field) {
            $this->assertSame($before[$field], $row->getRawOriginal($field), 'Preserve committed artifact metadata: '.$field);
        }
        $this->assertSame($bytes, Storage::disk('private')->get($row->artifact_path));
    }

    private function onDeliveryUpdate(string $state, \Closure $updated, \Closure $action): mixed
    {
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $triggered = false;
        $connection->getEventDispatcher()->listen(QueryExecuted::class, function (QueryExecuted $query) use ($state, $updated, &$triggered): void {
            if (! $triggered && str_starts_with(strtolower($query->sql), 'update ') && str_contains($query->sql, 'medication_backup_deliveries') && in_array($state, $query->bindings, true)) {
                $triggered = true;
                $updated();
            }
        });
        try {
            return $action();
        } finally {
            $connection->setEventDispatcher($dispatcher);
            $this->assertTrue($triggered, 'The regression must reach an actual native delivery update.');
        }
    }

    public static function currentBackupMailboxChanges(): array
    {
        return [['google', 'disconnected'], ['google', 'version'], ['microsoft', 'disconnected'], ['microsoft', 'version']];
    }

    #[DataProvider('currentBackupMailboxChanges')]
    public function test_backup_transport_current_read_denies_committed_mailbox_changes_despite_an_old_transaction_snapshot(string $provider, string $change): void
    {
        $connection = $this->centralMailbox($provider);
        $this->openDurableBoundary();
        $writerConfig = DB::connection()->getConfig();
        config(['database.connections.backup_email_writer' => $writerConfig]);
        DB::beginTransaction();
        try {
            $stale = ItMailboxConnection::query()->findOrFail($connection->id);
            $writer = DB::connection('backup_email_writer');
            $this->assertSame(self::$isolatedMysqlDatabase, $writer->getDatabaseName());
            $writer->table('it_mailbox_connections')->where('id', $connection->id)->update($change === 'version'
                ? ['configuration_version' => 2] : ['status' => 'disconnected']);
            // The ordinary SELECT remains stale under the actual MySQL RR snapshot.
            $ordinary = ItMailboxConnection::query()->findOrFail($connection->id);
            $this->assertSame('connected', $ordinary->status);
            $this->assertSame(1, $ordinary->configuration_version);
            $transport = new PreparedBackupOAuthTransport($stale, $provider);
            Http::fake();
            try {
                $transport->send((new Email)->from('saved@example.test')->replyTo('saved@example.test')->to($this->recipient->email)->text('Protected synthetic proof'));
                $this->fail('The backup transport must read the latest canonical mailbox before any provider request.');
            } catch (MailNotSubmitted) {
                Http::assertNothingSent();
            }
        } finally {
            DB::rollBack();
            DB::purge('backup_email_writer');
        }
    }

    public function test_central_email_configuration_partial_acceptance_keeps_the_backup_uncertain_and_immutable(): void
    {
        $connection = $this->centralMailbox('google');
        $schedule = $this->schedule();
        $second = $this->staff($this->site, false);
        $schedule = app(BackupDeliveryService::class)->approveRecipient($this->lead, $schedule->id, $schedule->version, $second->id, true);
        $row = $this->prepare($schedule);
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        $orderBefore = $this->order->fresh()->getRawOriginal();
        config(['mail.default' => 'smtp', 'emar-catalogue-backups.send_enabled' => true]);
        Http::fake(function ($request) use ($connection) {
            $this->assertSame('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $request->url());
            $connection->update(['scopes' => []]);

            return Http::response(['id' => 'synthetic-first-accepted'], 200);
        });
        try {
            app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->fail('Partial acceptance is never safe to resend.');
        } catch (\RuntimeException $error) {
            $this->assertNotInstanceOf(MailNotSubmitted::class, $error);
            $this->assertSame('backup_transport_incomplete', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        Http::assertSentCount(1);
        $fresh = $row->fresh();
        $this->assertSame('uncertain', $fresh->state);
        $this->assertSame('submission_unknown', $fresh->failure_code);
        $this->assertSame('uncertain', MedicationBackupAttempt::query()->sole()->state);
        $this->assertRetainedArtifact($fresh, $before, $bytes);
        $this->assertSame($orderBefore, $this->order->fresh()->getRawOriginal());
        $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/retry', ['version' => $fresh->version])->assertConflict();
        $this->assertSame(1, $row->fresh()->attempt_count);
        Http::assertSentCount(1);
        Mail::assertNothingSent();
    }

    public function test_capture_mode_never_marks_a_ready_backup_as_provider_accepted(): void
    {
        $this->centralMailbox('google');
        $row = $this->prepare($this->schedule());
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        config(['mail.default' => 'array', 'emar-catalogue-backups.send_enabled' => true]);
        try {
            app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->fail('Local capture must remain known unsent.');
        } catch (MailNotSubmitted $error) {
            $this->assertSame('backup_email_not_ready', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $fresh = $row->fresh();
        $this->assertSame('failed', $fresh->state);
        $this->assertSame('not_submitted', $fresh->failure_code);
        $this->assertNull($fresh->sent_at);
        $this->assertSame('failed', MedicationBackupAttempt::query()->sole()->state);
        $this->assertRetainedArtifact($fresh, $before, $bytes);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_backup_readiness_is_redacted_and_email_settings_link_uses_its_existing_permission(): void
    {
        $this->centralMailbox('google');
        $this->schedule();
        config(['mail.default' => 'smtp']);
        $this->actingAs($this->recipient)->get('/emar/backups')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can_view_email_settings', false)->where('readiness.email_ready', true)
            ->where('readiness.email_source', 'saved')->where('readiness.email_capture_mode', null)->where('readiness.email_reason', null));
        $permission = Permission::firstOrCreate(['key' => 'settings.access.manage'], ['description' => 'Synthetic settings access', 'group' => 'settings']);
        $this->lead->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $response = $this->actingAs($this->lead->fresh())->get('/emar/backups');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_view_email_settings', true));
        foreach (['synthetic-backup-token', 'saved@example.test', 'synthetic-refresh', 'connection_scope_hash'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public static function rotatedBackupCredentialFailures(): array
    {
        return [['google', 'later rejection'], ['microsoft', 'later rejection'], ['google', 'audit rollback'], ['microsoft', 'audit rollback'], ['google', 'short replacement'], ['microsoft', 'short replacement']];
    }

    #[DataProvider('rotatedBackupCredentialFailures')]
    public function test_rotated_oauth_credentials_remain_durable_when_chart_submission_fails(string $provider, string $failure): void
    {
        $connection = $this->centralMailbox($provider);
        $connection->update(['token_expires_at' => now()->subMinute()]);
        $schedule = $this->schedule();
        if ($failure === 'later rejection') {
            $second = $this->staff($this->site, false);
            $schedule = app(BackupDeliveryService::class)->approveRecipient($this->lead, $schedule->id, $schedule->version, $second->id, true);
        }
        $row = $this->prepare($schedule);
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        $orderBefore = $this->order->fresh()->getRawOriginal();
        config(['mail.default' => 'smtp', 'emar-catalogue-backups.send_enabled' => true,
            'services.'.$provider.'.client_id' => 'synthetic-client', 'services.'.$provider.'.client_secret' => 'synthetic-client-secret',
            'services.microsoft.tenant' => 'synthetic-directory']);
        $refreshes = 0;
        $submissions = 0;
        Http::fake(function ($request) use ($provider, $failure, &$refreshes, &$submissions) {
            if (str_contains($request->url(), 'oauth2')) {
                $refreshes++;
                $this->assertSame($provider === 'google' ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/synthetic-directory/oauth2/v2.0/token', $request->url());
                $this->assertSame('synthetic-refresh', $request['refresh_token']);

                return Http::response(['access_token' => 'rotated-synthetic-access', 'refresh_token' => 'rotated-synthetic-refresh',
                    'expires_in' => $failure === 'short replacement' ? 60 : 3600], 200);
            }
            $submissions++;
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer rotated-synthetic-access'));
            if ($submissions === 2) {
                return Http::response('Synthetic explicit refusal', 422);
            }

            return $provider === 'google' ? Http::response(['id' => 'synthetic-accepted'], 200) : Http::response('', 202);
        });
        if ($failure === 'audit rollback') {
            $recorder = Mockery::mock(MedicationEventRecorder::class);
            $recorder->shouldReceive('append')->once()->andThrow(new \RuntimeException('Synthetic audit rollback after acceptance'));
            $this->app->instance(MedicationEventRecorder::class, $recorder);
        }
        try {
            app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->fail('The synthetic boundary must reject or roll back chart submission.');
        } catch (\RuntimeException $error) {
            if ($failure === 'short replacement') {
                $this->assertInstanceOf(MailNotSubmitted::class, $error);
                $this->assertSame('backup_email_preparation_failed', $error->getMessage());
            } else {
                $this->assertNotInstanceOf(MailNotSubmitted::class, $error);
                $this->assertSame($failure === 'audit rollback' ? 'Synthetic audit rollback after acceptance' : 'backup_transport_incomplete', $error->getMessage());
            }
            $this->assertNull($error->getPrevious());
        }
        $fresh = $row->fresh();
        $this->assertSame($failure === 'short replacement' ? 'failed' : 'uncertain', $fresh->state);
        $this->assertSame($failure === 'short replacement' ? 'not_submitted' : 'submission_unknown', $fresh->failure_code);
        $this->assertSame($fresh->state, MedicationBackupAttempt::query()->sole()->state);
        $this->assertSame(1, $refreshes);
        $this->assertSame($failure === 'short replacement' ? 0 : ($failure === 'later rejection' ? 2 : 1), $submissions);
        $current = $connection->fresh();
        $this->assertSame('rotated-synthetic-access', $current->getAccessToken());
        $this->assertSame('rotated-synthetic-refresh', $current->getRefreshToken());
        $this->assertStringNotContainsString('rotated-synthetic-refresh', $current->getRawOriginal('refresh_token'));
        $this->assertRetainedArtifact($fresh, $before, $bytes);
        $this->assertSame($orderBefore, $this->order->fresh()->getRawOriginal());
        if ($failure !== 'short replacement') {
            $this->actingAs($this->lead)->postJson('/emar/backups/deliveries/'.$row->id.'/retry', ['version' => $fresh->version])->assertConflict();
            $this->assertSame(1, $row->fresh()->attempt_count);
        }
        Http::assertSentCount($refreshes + $submissions);
        Mail::assertNothingSent();
    }

    public function test_first_explicit_provider_rejection_preserves_known_unsent_backup_state(): void
    {
        $this->centralMailbox('google');
        $row = $this->prepare($this->schedule());
        $before = $row->getRawOriginal();
        $bytes = Storage::disk('private')->get($row->artifact_path);
        config(['mail.default' => 'smtp', 'emar-catalogue-backups.send_enabled' => true]);
        Http::fake(fn () => Http::response('Synthetic explicit refusal', 422));
        try {
            app(BackupDeliveryService::class)->send($this->lead, $row->id, $row->version);
            $this->fail('A rejected first recipient is known unaccepted.');
        } catch (MailNotSubmitted $error) {
            $this->assertSame('backup_transport_not_submitted', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $fresh = $row->fresh();
        $this->assertSame('failed', $fresh->state);
        $this->assertSame('not_submitted', $fresh->failure_code);
        $this->assertNull($fresh->sent_at);
        $this->assertSame('failed', MedicationBackupAttempt::query()->sole()->state);
        $this->assertRetainedArtifact($fresh, $before, $bytes);
        Http::assertSentCount(1);
        Mail::assertNothingSent();
    }

    private function centralMailbox(string $provider): ItMailboxConnection
    {
        $connection = ItMailboxConnection::create(['provider' => $provider, 'status' => 'connected',
            'account_email' => 'saved@example.test', 'access_token' => 'synthetic-backup-token', 'refresh_token' => 'synthetic-refresh',
            'token_expires_at' => now()->addHour(), 'scopes' => [$provider === 'google' ? 'https://www.googleapis.com/auth/gmail.send' : 'Mail.Send']])->fresh();
        AppSetting::create(['key' => EmailConfiguration::KEY, 'value' => [
            'configuration_version' => 1, 'provider' => $provider, 'from_name' => 'Saved Sender',
            'it_support' => ['enabled' => false, 'connection_id' => (int) $connection->id,
                'connection_version' => $connection->configuration_version, 'connection_scope_hash' => $connection->mailboxScopeHash()],
        ]]);

        return $connection;
    }

    private function useBackupKeys(string $key, array $previous = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
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
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame('127.0.0.1', $connection->getConfig('host'));
        $this->assertGreaterThan(0, getmypid());
        $this->assertContains($connection->getDatabaseName(), [
            'oblivion_findings_codex_test_'.getmypid(),
            'emar_connected_backup_retry_20261007_'.getmypid(),
            'emar_central_email_20261007_'.getmypid(),
        ]);
        $this->assertSame(self::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS owned_database')->owned_database);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $this->assertSame(0, DB::transactionLevel());
        $manager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
        $this->durable = true;
    }
}
