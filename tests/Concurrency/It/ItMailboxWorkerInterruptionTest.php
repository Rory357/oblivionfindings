<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItAttachmentStorageService;
use App\Domain\It\Services\ItMailboxPollState;
use App\Jobs\PollItMailboxJob;
use App\Models\AuditLog;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Run alone: terminates only exact child processes in the wrapper-owned schema/private disk. */
final class ItMailboxWorkerInterruptionTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
            || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || ! preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN'))) {
            throw new RuntimeException('Use the isolated IT wrapper for worker interruption verification.');
        }

        return parent::createApplication();
    }

    public function test_process_termination_before_and_after_commit_recovers_without_losing_files_or_duplicating_tickets(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        require_once base_path('tests/Support/It/mailbox-interruption-fixture.php');
        $root = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($root));
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $actor->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        $site = Site::factory()->create();
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
        $orphaned = [];
        $outcomes = [];
        foreach (['microsoft', 'google'] as $provider) {
            $connection = ItMailboxConnection::create(['provider' => $provider, 'status' => 'connected',
                'account_email' => 'support@demo.test', 'access_token' => 'synthetic-worker-token', 'token_expires_at' => now()->addDay()]);
            foreach (['before_commit', 'after_commit'] as $phase) {
                $key = 'interruption-'.$provider.'-'.$phase;
                $barrier = $root.'/'.$key.'.ready.json';
                $worker = new Process([PHP_BINARY, base_path('tests/Support/It/mailbox-interruption-worker.php'),
                    $provider, $phase, (string) $actor->id, (string) $connection->id], base_path(), timeout: 45);
                try {
                    $worker->start();
                    $deadline = microtime(true) + 20;
                    while (! is_file($barrier)) {
                        if (! $worker->isRunning()) {
                            throw new RuntimeException('Interruption worker ended before the checkpoint: '.$worker->getErrorOutput());
                        }
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Interruption worker checkpoint timed out.');
                        }
                        usleep(10000);
                    }
                    $proof = json_decode(file_get_contents($barrier), true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame($phase, $proof['phase']);
                    $this->assertSame($phase === 'before_commit', $proof['transaction_level'] > 0);
                    $this->assertTrue($worker->isRunning(), 'The exact child must still be alive at the interruption checkpoint.');
                    $childPid = $worker->getPid();
                    $intent = ItAttachmentStorageIntent::findOrFail($proof['intent_id']);
                    $this->assertSame($phase === 'before_commit' ? 'reserved' : 'attached', $intent->state);
                    $this->assertTrue(Storage::disk('private')->exists($intent->path));
                    $activeCleanup = app(ItAttachmentStorageService::class)->retryPending(10);
                    $this->assertSame($phase === 'before_commit' ? 0 : 1, $activeCleanup['requested'], 'Only an already accepted source copy may be cleaned while its writer remains alive.');
                    $this->assertTrue(Storage::disk('private')->exists($intent->path));
                    // Process owns this PID; Windows uses forced termination, not a caught PHP exception.
                    $worker->stop(0);
                    $this->assertFalse($worker->isRunning());
                    $this->assertFalse($worker->isSuccessful());
                } finally {
                    if ($worker->isRunning()) {
                        $worker->stop(0);
                    }
                }
                $receipt = ItInboundEmail::where('transport_key', hash('sha256', $connection->mailboxScopeHash()."\0".$key))->sole();
                $this->assertSame($phase === 'before_commit' ? 'pending' : 'processed', $receipt->status);
                $this->assertNull($receipt->acknowledged_at);
                $this->assertNull(app(ItMailboxPollState::class)->claim($connection->id), 'A killed process does not itself expire its durable lease.');
                $this->assertSame(0, app(ItAttachmentStorageService::class)->retryPending(10)['requested']);
                $this->assertSame($phase === 'before_commit' ? 0 : 1, ItTicket::where('title', $key)->count());
                // Deterministic elapsed-time fixture; no live claim or operational clock is changed.
                $this->travelTo($connection->fresh()->poll_claim_expires_at->copy()->addSecond());
                $calls = [];
                configureMailboxInterruptionFixture($connection, $actor->email, $key, $calls);
                (new PollItMailboxJob($connection->id))->handle(app(InboundEmailIngestor::class));
                $receipt->refresh();
                $this->assertSame('processed', $receipt->status);
                $this->assertNotNull($receipt->acknowledged_at);
                $this->assertSame(1, ItTicket::where('title', $key)->count());
                $ticket = $receipt->ticket;
                $file = $ticket->attachments()->sole();
                $this->assertSame('Synthetic private interruption evidence '.$key, Storage::disk('private')->get($file->path));
                $this->assertSame('clean', $file->malware_scan_status);
                $this->assertSame('deleted', $receipt->attachments()->sole()->inbound_storage_state);
                $this->assertSame(1, ItTicketCommandReceipt::where('it_ticket_id', $ticket->id)->where('operation', 'ticket.create')->count());
                $this->assertSame(1, AuditLog::where('action', 'it.ticket.created')->where('auditable_id', $ticket->id)->count());
                $cleanup = app(ItAttachmentStorageService::class)->retryPending(10);
                if ($phase === 'after_commit') {
                    $detail = ($provider === 'google' ? '/gmail/v1/users/me/messages/' : '/v1.0/users/support%40demo.test/messages/').$key;
                    $this->assertNotContains(['GET', $detail], $calls, 'A committed receipt must recover acknowledgement without ingesting again.');
                    $this->assertSame('attached', $intent->fresh()->state);
                    $this->assertSame($intent->path, $file->path);
                } elseif ($intent->fresh()->state === 'reserved' && Storage::disk('private')->exists($intent->path)) {
                    $orphaned[] = ['provider' => $provider, 'intent_id' => $intent->id];
                }
                if ($phase === 'before_commit' && $intent->fresh()->state !== 'reserved') {
                    $this->assertSame('deleted', $intent->fresh()->state);
                    $this->assertFalse(Storage::disk('private')->exists($intent->path));
                    $this->assertSame(1, $cleanup['deleted']);
                }
                $outcomes[] = ['provider' => $provider, 'phase' => $phase, 'child_pid' => $childPid,
                    'observed_running_at_checkpoint' => true, 'terminated' => ! $worker->isRunning(),
                    'intent_id' => $intent->id, 'final_intent_state' => $intent->fresh()->state,
                    'receipt_id' => $receipt->id, 'ticket_id' => $ticket->id, 'canonical_file_id' => $file->id,
                    'canonical_file_preserved' => Storage::disk('private')->exists($file->path), 'cleanup' => $cleanup];
                $calls = [];
                (new PollItMailboxJob($connection->id))->handle(app(InboundEmailIngestor::class));
                $this->assertSame(1, ItTicket::where('title', $key)->count());
                $this->assertSame(1, $ticket->attachments()->count());
                $this->travelBack();
            }
        }
        file_put_contents(base_path('docs/audits/2026-09-08-it-support/evidence/'.getenv('TEST_TOKEN').'.worker-interruption.json'),
            json_encode(['token' => getenv('TEST_TOKEN'), 'synthetic_only' => true, 'outcomes' => $outcomes, 'orphans' => $orphaned], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->assertSame([], $orphaned, 'Replacement ownership must reconcile pre-commit mailbox copies while preserving committed files.');
        $this->verifyReconciliationBoundaries($connection, $receipt, $actor, $ticket);
    }

    private function verifyReconciliationBoundaries(ItMailboxConnection $connection, ItInboundEmail $receipt, User $actor, ItTicket $ticket): void
    {
        $source = $receipt->attachments()->sole();
        $canonical = $ticket->attachments()->sole();
        $bytes = Storage::disk('private')->get($canonical->path);
        $writerToken = (string) Str::uuid();
        $connection->forceFill(['poll_claim_token' => $writerToken, 'poll_claim_expires_at' => now()->addMinute()])->save();
        $seed = function (array $overrides = [], bool $owned = true) use ($source, $connection, $receipt, $actor, $ticket, $bytes, $writerToken): ItAttachmentStorageIntent {
            $uuid = (string) Str::uuid();
            $intent = ItAttachmentStorageIntent::create([
                'intent_uuid' => $uuid, 'path' => 'it_attachments/'.$uuid, 'actor_user_id' => $actor->id,
                'parent_type' => $ticket->getMorphClass(), 'parent_id' => $ticket->id, 'state' => 'reserved',
                'content_sha256' => $source->inbound_content_hash, 'original_name_sha256' => hash('sha256', $source->original_name), 'size' => $source->size,
                ...($owned ? ['inbound_email_id' => $receipt->id, 'source_inbound_attachment_id' => $source->id,
                    'mailbox_connection_id' => $connection->id, 'mailbox_configuration_version' => $connection->configuration_version,
                    'mailbox_claim_token' => $writerToken] : []), ...$overrides,
            ]);
            Storage::disk('private')->put($intent->path, $bytes);

            return $intent;
        };
        $legacy = $seed(owned: false);
        $interrupted = $seed();
        $this->assertSame(0, app(ItAttachmentStorageService::class)->retryPending(1)['requested']);
        $this->assertTrue(Storage::disk('private')->exists($legacy->path));
        $this->assertTrue(Storage::disk('private')->exists($interrupted->path));
        $connection->forceFill(['poll_claim_expires_at' => now()->subSecond()])->save();
        $failAudit = true;
        AuditLog::creating(function ($audit) use (&$failAudit, $interrupted): void {
            if ($failAudit && $audit->action === 'it.attachment.cleanup.requested' && (int) $audit->auditable_id === (int) $interrupted->id) {
                throw new RuntimeException('Synthetic mailbox classification audit failure.');
            }
        });
        $failed = app(ItAttachmentStorageService::class)->retryPending(1);
        $this->assertSame(1, $failed['failed']);
        $this->assertSame('reserved', $interrupted->fresh()->state);
        $this->assertSame(1, $interrupted->fresh()->cleanup_attempts);
        $this->assertSame($writerToken, $connection->fresh()->poll_claim_token, 'A failed classification audit must roll back its fence too.');
        $this->assertTrue(Storage::disk('private')->exists($interrupted->path));
        $failAudit = false;
        $recovered = app(ItAttachmentStorageService::class)->retryPending(1);
        $this->assertSame(1, $recovered['deleted']);
        $this->assertNull($connection->fresh()->poll_claim_token);
        $this->assertSame('deleted', $interrupted->fresh()->state);
        $this->assertSame('reserved', $legacy->fresh()->state);
        $this->assertTrue(Storage::disk('private')->exists($legacy->path));
        $this->assertSame($bytes, Storage::disk('private')->get($canonical->path));

        $mismatch = $seed(['content_sha256' => hash('sha256', 'different bytes')]);
        $blocked = app(ItAttachmentStorageService::class)->retryPending(1);
        $this->assertSame(1, $blocked['reconciliation_required']);
        $this->assertSame('reconciliation_required', $mismatch->fresh()->state);
        $this->assertTrue(Storage::disk('private')->exists($mismatch->path));
        $this->assertSame(0, app(ItAttachmentStorageService::class)->retryPending(1)['requested']);

        $conflict = $seed();
        $ticket->attachments()->create(['path' => $conflict->path, 'original_name' => $source->original_name,
            'size' => $source->size, 'mime' => 'text/plain', 'uploaded_by' => $actor->id]);
        $blocked = app(ItAttachmentStorageService::class)->retryPending(1);
        $this->assertSame(1, $blocked['reconciliation_required']);
        $this->assertTrue(Storage::disk('private')->exists($conflict->path));
        $this->assertSame('reconciliation_required', $conflict->fresh()->state);

        $missingSource = $seed(['source_inbound_attachment_id' => 999999999]);
        $this->assertSame(1, app(ItAttachmentStorageService::class)->retryPending(1)['reconciliation_required']);
        $this->assertTrue(Storage::disk('private')->exists($missingSource->path));
        $disconnected = $seed();
        $connection->delete();
        $this->assertSame(1, app(ItAttachmentStorageService::class)->retryPending(1)['deleted']);
        $this->assertSame('deleted', $disconnected->fresh()->state);
        $this->assertTrue(Storage::disk('private')->exists($legacy->path));
        $this->assertSame($bytes, Storage::disk('private')->get($canonical->path));
    }
}
