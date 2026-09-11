<?php

namespace Tests\Concurrency\It;

use App\Domain\It\Data\ItEmailAttachment;
use App\Domain\It\Services\ItAttachmentCleanupReadService;
use App\Domain\It\Services\ItAttachmentStorageService;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItInboundAttachmentStaging;
use App\Domain\It\Services\ItMailboxPollState;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItAutomationRun;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Real recorder and storage; run alone through the isolated wrapper. */
final class ItAttachmentCleanupCommandTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/', (string) getenv('TEST_TOKEN')) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT wrapper for cleanup command verification.');
        }

        return parent::createApplication();
    }

    public function test_real_cleanup_batches_record_partial_failure_and_recovery_without_touching_unknown_files(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('oblivion_it_support_test_'.getenv('TEST_TOKEN'), DB::connection()->getDatabaseName());
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        Notification::fake();
        Http::preventStrayRequests();
        config(['it.drafts.enabled' => false]);
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $privateRoot = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($privateRoot));
        $actor = User::factory()->create(['approved_at' => now()]);
        $ticket = ItTicket::factory()->create(['requester_user_id' => $actor->id]);
        $storage = app(ItAttachmentStorageService::class);
        $manager = Storage::getFacadeRoot();
        $disk = Storage::disk(ItAttachment::DISK);
        $files = [UploadedFile::fake()->createWithContent('one.txt', 'Synthetic first bytes'), UploadedFile::fake()->createWithContent('two.txt', 'Synthetic second bytes')];
        $references = $storage->reserveDirect($ticket, $files, $actor);
        DB::beginTransaction();
        $paths = [];
        $storage->storeReservedDirect($ticket, $files, $actor, $references, $paths);
        DB::rollBack();
        $broken = Mockery::mock($disk)->makePartial();
        $broken->shouldReceive('delete')->andReturn(false);
        $brokenManager = Mockery::mock(FilesystemManager::class);
        $brokenManager->shouldReceive('disk')->with(ItAttachment::DISK)->andReturn($broken);
        Storage::swap($brokenManager);
        try {
            $this->assertSame(2, $storage->requestRollbackCleanup($references)['failed']);
        } finally {
            Storage::swap($manager);
        }
        $unknown = $storage->reserveDirect($ticket, [UploadedFile::fake()->createWithContent('unknown.txt', 'Potentially committed original bytes')], $actor)[0];
        $disk->put($unknown->path, 'Potentially committed original bytes');

        $partialDisk = Mockery::mock($disk)->makePartial();
        $partialDisk->shouldReceive('delete')->andReturnUsing(fn ($path) => $path === $references[0]->path ? false : $disk->delete($path));
        $partialManager = Mockery::mock(FilesystemManager::class);
        $partialManager->shouldReceive('disk')->with(ItAttachment::DISK)->andReturn($partialDisk);
        Storage::swap($partialManager);
        try {
            $this->artisan('it:retry-attachment-cleanup', ['--limit' => 2])->assertExitCode(1);
        } finally {
            Storage::swap($manager);
        }
        $run = ItAutomationRun::query()->where('automation_key', 'it.retry-attachment-cleanup')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('partial', $run->result_summary['outcome']);
        // JSON object key order is not preserved by MySQL; retain strict key,
        // value and integer-type comparisons without treating order as data.
        $expectedCounts = ['requested' => 2, 'deleted' => 1, 'failed' => 1, 'deferred' => 0, 'reconciliation_required' => 0];
        $persistedCounts = $run->result_summary['counts'];
        ksort($expectedCounts);
        ksort($persistedCounts);
        $this->assertSame($expectedCounts, $persistedCounts);
        $this->assertTrue($disk->exists($references[0]->path));
        $this->assertFalse($disk->exists($references[1]->path));
        $this->assertTrue($disk->exists($unknown->path));

        $task = collect(app(Schedule::class)->events())->firstWhere('description', 'it.retry-attachment-cleanup');
        $recorder = app(ItAutomationRunRecorder::class);
        $recorder->starting(new ScheduledTaskStarting($task));
        $this->artisan('it:retry-attachment-cleanup', ['--limit' => 1])->assertExitCode(0);
        $recorder->finished(new ScheduledTaskFinished($task, 0.1));
        $this->assertSame(2, ItAutomationRun::query()->where('automation_key', 'it.retry-attachment-cleanup')->count());
        $recovery = ItAutomationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('succeeded', $recovery->status);
        $this->assertSame(1, $recovery->result_summary['counts']['deleted']);
        $this->assertSame(0, $recovery->result_summary['remaining']['cleanup_pending']);
        $this->assertSame(1, $recovery->result_summary['remaining']['reserved_unclassified']);
        $this->assertFalse($disk->exists($references[0]->path));
        $this->assertTrue($disk->exists($unknown->path));
        $this->assertSame(0, ItAttachment::query()->count());

        // A committed email copy must be recoverable without its mailbox connection.
        $mailbox = ItMailboxConnection::create(['provider' => 'google', 'status' => 'connected',
            'account_email' => 'synthetic-cleanup@demo.test', 'created_by' => $actor->id]);
        $state = app(ItMailboxPollState::class);
        $claim = $state->claim($mailbox->id);
        $scanner = new class extends MalwareScanner
        {
            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                if (! is_file($path)) {
                    throw new RuntimeException('Synthetic scanner needs a real test file.');
                }

                return new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic-scanner');
            }
        };
        $staging = new ItInboundAttachmentStaging($state, $scanner);
        $newReceipt = function () use ($claim): ItInboundEmail {
            $receipt = new ItInboundEmail;
            $receipt->forceFill(['status' => 'pending', 'from_email' => '',
                'it_mailbox_connection_id' => $claim->id, 'mailbox_scope_hash' => $claim->mailboxScopeHash()])->save();

            return $receipt;
        };
        $bytes = 'Synthetic accepted email evidence';
        $descriptor = new ItEmailAttachment('email.txt', 'text/plain', strlen($bytes), false, 'google', 'file-1', null, 'email.txt');
        $accepted = $newReceipt();
        $staging->receive($claim, $accepted, [$descriptor], fn () => $bytes);
        $staged = $accepted->attachments()->sole();
        $final = $state->withinClaim($claim, function () use ($claim, $staging, $accepted, $staged, $storage, $ticket, $actor): ItAttachment {
            $files = $staging->filesForIngestion($claim, $accepted);
            $references = $storage->reserveDirect($ticket, $files, $actor);
            $paths = [];
            $storage->storeReservedDirect($ticket, $files, $actor, $references, $paths);
            $accepted->forceFill(['status' => 'processed', 'it_ticket_id' => $ticket->id])->save();
            $staging->finish($accepted);

            return $ticket->attachments()->where('source_inbound_attachment_id', $staged->id)->sole();
        });
        $quarantine = $newReceipt();
        $staging->receive($claim, $quarantine, [$descriptor], fn () => $bytes);
        $quarantine->forceFill(['status' => 'quarantined', 'quarantine_reason' => 'sender_unknown'])->save();
        $retained = $quarantine->attachments()->sole();
        $mailbox->delete();
        $this->assertNull($accepted->refresh()->it_mailbox_connection_id);

        // A failed direct cleanup and a failed email cleanup share one --limit budget.
        $directFile = [UploadedFile::fake()->createWithContent('later.txt', 'Synthetic later direct file')];
        $later = $storage->reserveDirect($ticket, $directFile, $actor);
        DB::beginTransaction();
        $paths = [];
        $storage->storeReservedDirect($ticket, $directFile, $actor, $later, $paths);
        DB::rollBack();
        Storage::swap($brokenManager);
        try {
            $this->assertSame(1, $storage->requestRollbackCleanup($later)['failed']);
        } finally {
            Storage::swap($manager);
        }
        $this->travel(2)->seconds();
        $failedEmailDisk = Mockery::mock($disk)->makePartial();
        $failedEmailDisk->shouldReceive('delete')->andReturnUsing(fn ($path) => $path === $staged->path ? false : $disk->delete($path));
        $failedEmailManager = Mockery::mock(FilesystemManager::class);
        $failedEmailManager->shouldReceive('disk')->with(ItAttachment::DISK)->andReturn($failedEmailDisk);
        Storage::swap($failedEmailManager);
        try {
            $this->artisan('it:retry-attachment-cleanup', ['--limit' => 1])->assertExitCode(1);
        } finally {
            Storage::swap($manager);
        }
        $failedEmail = ItAutomationRun::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $failedEmail->result_summary['counts']['requested']);
        $this->assertSame(1, $failedEmail->result_summary['counts']['failed']);
        $this->assertSame(2, $failedEmail->result_summary['remaining']['cleanup_pending']);
        $this->assertSame(1, $staged->refresh()->inbound_cleanup_attempts);
        $this->assertTrue($disk->exists($staged->path));

        // The older direct retry gets the next slot; the failed email cannot monopolize runs.
        $this->artisan('it:retry-attachment-cleanup', ['--limit' => 1])->assertExitCode(0);
        $this->assertFalse($disk->exists($later[0]->path));
        $this->assertTrue($disk->exists($staged->path));
        $next = ItAutomationRun::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $next->result_summary['counts']['requested']);
        $this->assertSame(1, $next->result_summary['remaining']['cleanup_pending']);
        $this->artisan('it:retry-attachment-cleanup', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame('deleted', $staged->refresh()->inbound_storage_state);
        $this->assertFalse($disk->exists($staged->path));
        $this->assertSame($bytes, $disk->get($final->path));
        $this->assertSame('clean', $final->refresh()->malware_scan_status);
        $this->assertTrue($disk->exists($retained->path));
        $this->assertTrue($disk->exists($unknown->path));

        // Restored invalid cleanup ownership is visible for reconciliation, never removal.
        $retained->forceFill(['inbound_storage_state' => 'cleanup_pending'])->save();
        $this->assertSame(1, app(ItAttachmentCleanupReadService::class)->currentCounts()['reconciliation_required']);
        $this->artisan('it:retry-attachment-cleanup', ['--limit' => 1])->assertExitCode(1);
        $ineligible = ItAutomationRun::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $ineligible->result_summary['counts']['requested']);
        $this->assertSame('reconciliation_required', $ineligible->result_summary['outcome']);
        $this->assertTrue($disk->exists($retained->path));
        $retained->forceFill(['inbound_storage_state' => 'ready'])->save();

        // A failed deletion audit must not abort the rest of an accepted-copy batch.
        // These are explicit committed cleanup fixtures, not ingestion acceptance.
        $auditCopies = [];
        foreach (['audit-failure', 'healthy-neighbour'] as $label) {
            $copy = new ItAttachment;
            $copy->forceFill(['attachable_type' => $accepted->getMorphClass(), 'attachable_id' => $accepted->id,
                'path' => 'it_attachments/'.Str::uuid(), 'original_name' => $label.'.txt',
                'mime' => 'text/plain', 'size' => strlen($bytes), 'inbound_content_hash' => hash('sha256', $bytes),
                'inbound_storage_state' => 'cleanup_pending'])->save();
            $disk->put($copy->path, $bytes);
            $auditCopies[] = $copy;
        }
        $failDeleteAudit = true;
        AuditLog::creating(function (AuditLog $audit) use (&$failDeleteAudit, $auditCopies): void {
            if ($failDeleteAudit && $audit->action === 'it.inbound.attachment.deleted'
                && (int) $audit->auditable_id === (int) $auditCopies[0]->id) {
                throw new RuntimeException('Synthetic deletion audit failure.');
            }
        });
        try {
            $this->artisan('it:retry-attachment-cleanup', ['--limit' => 2])->assertExitCode(1);
        } finally {
            $failDeleteAudit = false;
        }
        $auditFailure = ItAutomationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('partial', $auditFailure->result_summary['outcome']);
        $this->assertSame(2, $auditFailure->result_summary['counts']['requested']);
        $this->assertSame(1, $auditFailure->result_summary['counts']['failed']);
        $this->assertSame(1, $auditFailure->result_summary['counts']['deleted']);
        $this->assertSame('cleanup_pending', $auditCopies[0]->refresh()->inbound_storage_state);
        $this->assertSame('cleanup_record_failed', $auditCopies[0]->inbound_error_code);
        $this->assertSame(1, $auditCopies[0]->inbound_cleanup_attempts);
        $this->assertSame('deleted', $auditCopies[1]->refresh()->inbound_storage_state);
        foreach ($auditCopies as $copy) {
            $this->assertFalse($disk->exists($copy->path));
        }
        $this->assertSame(0, AuditLog::where('action', 'it.inbound.attachment.deleted')->where('auditable_id', $auditCopies[0]->id)->count());
        $this->artisan('it:retry-attachment-cleanup', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame('deleted', $auditCopies[0]->refresh()->inbound_storage_state);
        $this->assertNull($auditCopies[0]->inbound_error_code);
        $this->artisan('it:retry-attachment-cleanup', ['--limit' => 1])->assertExitCode(0);
        foreach ($auditCopies as $copy) {
            $this->assertSame(1, AuditLog::where('action', 'it.inbound.attachment.deleted')->where('auditable_id', $copy->id)->count());
        }
        $this->assertSame($bytes, $disk->get($final->path));
        $this->assertTrue($disk->exists($retained->path));
        $this->assertTrue($disk->exists($unknown->path));

        ItAttachmentStorageIntent::query()->findOrFail($unknown->id)->forceFill(['state' => 'reconciliation_required'])->save();
        $this->artisan('it:retry-attachment-cleanup')->assertExitCode(1);
        $last = ItAutomationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('reconciliation_required', $last->result_summary['outcome']);
        $this->assertSame(0, $last->result_summary['counts']['requested']);
        $this->assertSame(1, $last->result_summary['remaining']['reconciliation_required']);
        $this->assertTrue($disk->exists($unknown->path));
        $this->assertSame(0, DB::transactionLevel());
        Http::assertNothingSent();
    }
}
