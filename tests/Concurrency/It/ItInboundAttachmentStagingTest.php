<?php

namespace Tests\Concurrency\It;

use App\Domain\It\Data\ItEmailAttachment;
use App\Domain\It\Exceptions\ItInboundAttachmentUnavailable;
use App\Domain\It\Exceptions\ItInboundContentException;
use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Domain\It\Services\ItInboundAttachmentStaging;
use App\Domain\It\Services\ItMailboxPollState;
use App\Models\AuditLog;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\Role;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/** Run alone: real commits/private storage, no parallel workers or live scanner/provider. */
final class ItInboundAttachmentStagingTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT wrapper for attachment staging verification.');
        }

        return parent::createApplication();
    }

    public function test_private_preparation_survives_failures_and_never_exposes_unaccepted_files(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('array', config('mail.default'));
        Http::preventStrayRequests();
        Notification::fake();
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $root = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($root));
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now(), 'email_verified_at' => now()]);
        $admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $mailbox = ItMailboxConnection::create(['provider' => 'google', 'status' => 'connected',
            'created_by' => $admin->id, 'account_email' => 'synthetic-stage@demo.test']);
        $state = app(ItMailboxPollState::class);
        $claim = $state->claim($mailbox->id);
        $this->assertNotNull($claim);
        $makeReceipt = function () use ($claim): ItInboundEmail {
            $receipt = new ItInboundEmail;
            $receipt->forceFill(['status' => 'pending', 'from_email' => '', 'remote_message_id' => (string) Str::uuid(),
                'it_mailbox_connection_id' => $claim->id, 'mailbox_scope_hash' => $claim->mailboxScopeHash()])->save();

            return $receipt;
        };
        $file = new ItEmailAttachment('evidence.txt', 'text/plain', 23, false, 'google', 'synthetic-file', null, 'evidence.txt');
        $bytes = 'Private staged evidence';
        $this->assertSame($file->size, strlen($bytes));
        $scanner = new class extends MalwareScanner
        {
            public int $calls = 0;

            public bool $failCommitAcknowledgement = false;

            public MalwareScanDisposition $verdict = MalwareScanDisposition::Clean;

            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                $this->calls++;
                if (! is_file($path)) {
                    throw new RuntimeException('Synthetic scanner did not receive an actual private file.');
                }
                if ($this->failCommitAcknowledgement) {
                    DB::afterCommit(fn () => throw new RuntimeException('Synthetic commit acknowledgement interruption.'));
                }

                return new MalwareScanResult($this->verdict, 'synthetic-scanner');
            }
        };
        $stage = new ItInboundAttachmentStaging($state, $scanner);
        $receipt = $makeReceipt();

        // Default missing executable is a real adapter result, not an invented clean verdict.
        config(['it.inbound_mail.malware_scanner.binary' => null]);
        $unavailable = new ItInboundAttachmentStaging($state, new MalwareScanner);
        $this->failure(fn () => $unavailable->receive($claim, $receipt, [$file], fn () => $bytes),
            ItInboundAttachmentUnavailable::class, 'scanner_unavailable');
        $attachment = $receipt->attachments()->sole();
        $this->assertSame('failed', $attachment->inbound_storage_state);
        $this->assertSame('unavailable', $attachment->malware_scan_status);
        $this->assertNotNull($attachment->malware_scan_attempted_at);
        $this->assertNull($attachment->malware_scanned_at);
        $this->assertNull($attachment->uploaded_by);
        $this->assertSame(hash('sha256', $bytes), $attachment->inbound_content_hash);
        $this->assertSame($bytes, Storage::disk('private')->get($attachment->path));
        $this->actingAs($admin)->get('/it/attachments/'.$attachment->id)->assertNotFound();
        $this->failure(fn () => $state->withinClaim($claim, fn () => $stage->filesForIngestion($claim, $receipt)),
            ItInboundAttachmentUnavailable::class, 'attachment_not_ready');

        // A later clean scan reuses the same reservation. Exact retry performs no second scan.
        $stage->receive($claim, $receipt, [$file], fn () => $bytes);
        $stage->receive($claim, $receipt, [$file], fn () => $bytes);
        $this->assertSame(1, $scanner->calls);
        $this->assertSame($attachment->id, $receipt->attachments()->sole()->id);
        $this->assertSame('ready', $attachment->refresh()->inbound_storage_state);
        $this->assertSame('clean', $attachment->malware_scan_status);
        $this->actingAs($admin)->get('/it/attachments/'.$attachment->id)->assertNotFound();
        $uploads = $state->withinClaim($claim, fn () => $stage->filesForIngestion($claim, $receipt));
        $this->assertCount(1, $uploads);
        $this->assertSame($bytes, $uploads[0]->getContent());
        $this->assertSame('evidence.txt', $uploads[0]->getClientOriginalName());
        $this->assertStringNotContainsString($attachment->inbound_content_hash, $attachment->toJson());

        $this->failure(fn () => $stage->receive($claim, $receipt, [$file], fn () => str_repeat('x', 23)),
            ItInboundContentException::class, 'attachment_content_changed');
        $this->failure(fn () => $stage->receive($claim, $receipt, [], fn () => ''),
            ItInboundContentException::class, 'attachment_manifest_changed');
        $this->assertSame($bytes, Storage::disk('private')->get($attachment->path));

        // Changed local bytes cannot be consumed under an old verdict; repair must scan again.
        Storage::disk('private')->put($attachment->path, str_repeat('x', 23));
        $this->failure(fn () => $state->withinClaim($claim, fn () => $stage->filesForIngestion($claim, $receipt)),
            ItInboundAttachmentUnavailable::class, 'attachment_storage_changed');
        $stage->receive($claim, $receipt, [$file], fn () => $bytes);
        $this->assertSame(2, $scanner->calls);
        $this->assertSame($bytes, Storage::disk('private')->get($attachment->path));

        // A failed required audit after physical write rolls back only the preparation transition.
        $auditReceipt = $makeReceipt();
        $failAudit = true;
        $failDeleteAudit = false;
        AuditLog::creating(function (AuditLog $audit) use (&$failAudit, &$failDeleteAudit): void {
            if (($failAudit && $audit->action === 'it.inbound.attachment.prepared')
                || ($failDeleteAudit && $audit->action === 'it.inbound.attachment.deleted')) {
                throw new RuntimeException('Synthetic required scan audit failure.');
            }
        });
        $this->failure(fn () => $stage->receive($claim, $auditReceipt, [$file], fn () => $bytes), RuntimeException::class);
        $failAudit = false;
        $auditFile = $auditReceipt->attachments()->sole();
        $this->assertSame('reserved', $auditFile->inbound_storage_state);
        $this->assertSame(hash('sha256', $bytes), $auditFile->inbound_content_hash);
        $this->assertFileExists(Storage::disk('private')->path($auditFile->path));
        $stage->receive($claim, $auditReceipt, [$file], fn () => $bytes);
        $this->assertSame('ready', $auditFile->refresh()->inbound_storage_state);

        // Failed transport leaves an empty durable reservation, resumed without duplicate rows.
        $transportReceipt = $makeReceipt();
        $this->failure(fn () => $stage->receive($claim, $transportReceipt, [$file], function () {
            $this->assertSame(0, DB::transactionLevel());
            throw new RuntimeException('Synthetic download interruption.');
        }), RuntimeException::class);
        $transportFile = $transportReceipt->attachments()->sole();
        $this->assertFileDoesNotExist(Storage::disk('private')->path($transportFile->path));
        $stage->receive($claim, $transportReceipt, [$file], fn () => $bytes);
        $this->assertSame($transportFile->id, $transportReceipt->attachments()->sole()->id);

        // An actual unwritable destination reports failure; clearing it resumes the same path.
        $storageReceipt = $makeReceipt();
        $this->failure(fn () => $stage->receive($claim, $storageReceipt, [$file], function () use ($storageReceipt, $bytes) {
            $reserved = $storageReceipt->attachments()->sole();
            $this->assertTrue(mkdir(Storage::disk('private')->path($reserved->path)));

            return $bytes;
        }), ItInboundAttachmentUnavailable::class, 'attachment_storage_unavailable');
        $storageFile = $storageReceipt->attachments()->sole();
        $this->assertSame('failed', $storageFile->inbound_storage_state);
        $this->assertTrue(rmdir(Storage::disk('private')->path($storageFile->path)));
        $stage->receive($claim, $storageReceipt, [$file], fn () => $bytes);
        $this->assertSame('ready', $storageFile->refresh()->inbound_storage_state);

        // Loss of acknowledgement after COMMIT preserves ready bytes; retry is idempotent.
        $commitReceipt = $makeReceipt();
        $scanner->failCommitAcknowledgement = true;
        $this->failure(fn () => $stage->receive($claim, $commitReceipt, [$file], fn () => $bytes), RuntimeException::class);
        $scanner->failCommitAcknowledgement = false;
        $commitFile = $commitReceipt->attachments()->sole();
        $this->assertSame('ready', $commitFile->inbound_storage_state);
        $this->assertFileExists(Storage::disk('private')->path($commitFile->path));
        $calls = $scanner->calls;
        $stage->receive($claim, $commitReceipt, [$file], fn () => $bytes);
        $this->assertSame($calls, $scanner->calls);

        // Extension alone is insufficient; actual HTML disguised as .txt is rejected before scan.
        $badReceipt = $makeReceipt();
        $badBytes = '<html><body><script>alert(1)</script></body></html>';
        $badFile = new ItEmailAttachment('evidence.txt', 'text/plain', strlen($badBytes), false, 'google', 'bad', null, 'evidence.txt');
        $calls = $scanner->calls;
        $this->failure(fn () => $stage->receive($claim, $badReceipt, [$badFile], fn () => $badBytes),
            ItInboundContentException::class, 'unsupported_attachment_content');
        $this->assertSame($calls, $scanner->calls);
        $this->assertSame('rejected', $badReceipt->attachments()->sole()->inbound_storage_state);

        $infectedReceipt = $makeReceipt();
        $scanner->verdict = MalwareScanDisposition::Infected;
        $this->failure(fn () => $stage->receive($claim, $infectedReceipt, [$file], fn () => $bytes),
            ItInboundContentException::class, 'attachment_infected');
        $infected = $infectedReceipt->attachments()->sole();
        $this->assertSame('infected', $infected->malware_scan_status);
        $this->actingAs($admin)->get('/it/attachments/'.$infected->id)->assertNotFound();
        $scanner->verdict = MalwareScanDisposition::Clean;
        $this->failure(fn () => $stage->receive($claim, $infectedReceipt, [$file], fn () => $bytes),
            ItInboundContentException::class, 'attachment_infected');
        $this->assertSame('rejected', $infected->refresh()->inbound_storage_state);

        // Outer rollback cannot mark temporary files disposable. No content retention is invented.
        $this->failure(fn () => $state->withinClaim($claim, function () use ($stage, $receipt): void {
            $receipt->forceFill(['status' => 'processed'])->save();
            $stage->finish($receipt);
            throw new RuntimeException('Synthetic final ledger failure.');
        }), RuntimeException::class);
        $this->assertSame('pending', $receipt->refresh()->status);
        $this->assertSame('ready', $attachment->refresh()->inbound_storage_state);
        $this->assertSame(['deleted' => 0, 'failed' => 0], $stage->cleanupPending());
        $state->withinClaim($claim, function () use ($stage, $receipt): void {
            $receipt->forceFill(['status' => 'processed'])->save();
            $stage->finish($receipt);
        });
        $failDeleteAudit = true;
        $this->assertSame(['deleted' => 0, 'failed' => 1], $stage->cleanupPending());
        $failDeleteAudit = false;
        $this->assertSame('cleanup_pending', $attachment->refresh()->inbound_storage_state);
        $this->assertSame('cleanup_record_failed', $attachment->inbound_error_code);
        $this->assertGreaterThan(0, $attachment->inbound_cleanup_attempts);
        $this->assertFileDoesNotExist(Storage::disk('private')->path($attachment->path));
        $this->assertSame(['deleted' => 1, 'failed' => 0], $stage->cleanupPending());
        $this->assertSame('deleted', $attachment->refresh()->inbound_storage_state);
        $this->assertFileDoesNotExist(Storage::disk('private')->path($attachment->path));
        $this->assertFileExists(Storage::disk('private')->path($infected->path));
        $this->assertSame(['deleted' => 0, 'failed' => 0], $stage->cleanupPending());
        $this->failure(fn () => DB::transaction(fn () => $stage->receive($claim, $makeReceipt(), [], fn () => '')), LogicException::class);
        $this->failure(fn () => $stage->filesForIngestion($claim, $auditReceipt), LogicException::class);

        // Reconfiguration fences out a delayed downloader before any private write.
        $lateReceipt = $makeReceipt();
        $this->failure(fn () => $stage->receive($claim, $lateReceipt, [$file], function () use ($mailbox, $bytes) {
            $mailbox->refresh()->forceFill(['configuration_version' => $mailbox->configuration_version + 1])->save();

            return $bytes;
        }), ItMailboxPollSuperseded::class);
        $late = $lateReceipt->attachments()->sole();
        $this->assertSame('reserved', $late->inbound_storage_state);
        $this->assertFileDoesNotExist(Storage::disk('private')->path($late->path));
        $this->assertGreaterThan(0, AuditLog::where('action', 'it.inbound.attachment.prepared')->count());
        $this->assertSame(1, AuditLog::where('action', 'it.inbound.attachment.deleted')->count());
        Http::assertNothingSent();
    }

    private function failure(callable $action, string $class, ?string $reason = null): void
    {
        try {
            $action();
        } catch (Throwable $failure) {
            $this->assertInstanceOf($class, $failure);
            if ($reason !== null) {
                $this->assertSame($reason, $failure->reason);
            }

            return;
        }
        $this->fail('Expected '.$class.' was not thrown.');
    }
}
