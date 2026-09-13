<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Services\ItAttachmentWriteContext;
use App\Domain\It\Services\ItMailboxPollState;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/** Run alone: outer rollback and commit acknowledgements require real commits. */
final class ItInboundAttachmentTransactionTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT wrapper for attachment transaction verification.');
        }

        return parent::createApplication();
    }

    public function test_mailbox_owner_cleans_proven_outer_rollback_and_preserves_committed_files(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        Http::preventStrayRequests();
        Notification::fake();
        config(['it.drafts.enabled' => false]);
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $root = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($root));
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $actor->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        $site = Site::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null,
        ]);
        $mailbox = ItMailboxConnection::create([
            'provider' => 'google', 'status' => 'connected', 'created_by' => $actor->id,
            'account_email' => 'synthetic-mailbox@demo.test',
        ]);
        $state = app(ItMailboxPollState::class);
        $claim = $state->claim($mailbox->id);
        $this->assertNotNull($claim);
        $input = ['request_uuid' => (string) Str::uuid(), 'site_id' => $site->id,
            'title' => 'Synthetic file transaction', 'description' => 'Private test content',
            'priority' => 'normal', 'category' => 'other'];
        $files = [UploadedFile::fake()->createWithContent('evidence.txt', 'Isolated private evidence')];
        $intake = app(ItTicketIntakeService::class);
        $interaction = app(ItTicketInteractionService::class);

        // Both commands returned successfully, but the mailbox's later write fails.
        $context = new ItAttachmentWriteContext;
        $failure = new RuntimeException('Synthetic inbound ledger failure');
        try {
            $state->withinClaim($claim, function () use ($context, $intake, $interaction, $actor, $input, $files, $failure): void {
                $ticket = $intake->createCommand($actor, $input, $files, ItTicketCommandChannel::Email, $context)->ticket;
                $interaction->addCommentCommand($ticket, $actor, [
                    'request_uuid' => (string) Str::uuid(), 'actor_user_id' => $actor->id,
                    'expected_version' => $ticket->lock_version, 'body' => 'Synthetic email reply', 'is_internal' => false,
                ], $files, ItTicketCommandChannel::Email, $context);
                $this->assertSame(2, ItAttachment::count());
                throw $failure;
            }, $context);
            $this->fail('Expected the original ledger failure.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertSame(0, ItTicket::count());
        $this->assertSame(0, ItAttachment::count());
        $this->assertSame(0, ItTicketCommandReceipt::count());
        $this->assertSame(2, ItAttachmentStorageIntent::where('state', 'deleted')->count());
        $this->assertSame(2, AuditLog::where('action', 'it.attachment.cleanup.completed')->count());
        $this->assertSame([], Storage::disk(ItAttachment::DISK)->allFiles());
        $this->assertSame(0, DB::transactionLevel());

        // The same request can retry after proven rollback without stale files.
        $retryContext = new ItAttachmentWriteContext;
        $created = $state->withinClaim($claim,
            fn () => $intake->createCommand($actor, $input, $files, ItTicketCommandChannel::Email, $retryContext), $retryContext);
        $attachment = $created->ticket->attachments()->sole();
        $this->assertSame('Isolated private evidence', Storage::disk(ItAttachment::DISK)->get($attachment->path));
        $this->assertSame('attached', ItAttachmentStorageIntent::where('attachment_id', $attachment->id)->sole()->state);
        $this->assertSame(1, ItTicket::count());
        $this->assertSame(1, ItAttachment::count());

        // A callback failure after the physical commit cannot authorize deletion.
        $uncertain = new ItAttachmentWriteContext;
        $replyUuid = (string) Str::uuid();
        $commitFailure = new RuntimeException('Synthetic after-commit acknowledgement failure');
        $replyInput = ['request_uuid' => $replyUuid, 'actor_user_id' => $actor->id,
            'expected_version' => $created->ticket->lock_version, 'body' => 'Committed reply', 'is_internal' => false];
        try {
            $state->withinClaim($claim, function () use ($uncertain, $created, $interaction, $actor, $replyInput, $files, $commitFailure) {
                $result = $interaction->addCommentCommand($created->ticket, $actor, $replyInput, $files, ItTicketCommandChannel::Email, $uncertain);
                DB::afterCommit(fn () => throw $commitFailure);

                return $result;
            }, $uncertain);
            $this->fail('Expected the synthetic commit acknowledgement failure.');
        } catch (RuntimeException $caught) {
            $this->assertSame($commitFailure, $caught);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(2, ItAttachment::count());
        $this->assertSame(2, ItAttachmentStorageIntent::where('state', 'attached')->count());
        $this->assertSame(2, AuditLog::where('action', 'it.attachment.cleanup.completed')->count());
        $recovery = new ItAttachmentWriteContext;
        $reply = $state->withinClaim($claim,
            fn () => $interaction->addCommentCommand($created->ticket, $actor, $replyInput, $files, ItTicketCommandChannel::Email, $recovery), $recovery);
        $this->assertTrue($reply->replayed);
        $this->assertSame(1, $created->ticket->comments()->count());
        $this->assertSame(4, ItAttachmentStorageIntent::count());
        foreach (ItAttachment::all() as $file) {
            $this->assertSame('Isolated private evidence', Storage::disk(ItAttachment::DISK)->get($file->path));
        }

        // Closed contexts cannot be reused by a later command/transaction.
        foreach ([fn () => $context->transaction(fn () => null), fn () => $recovery->assertActive()] as $invalid) {
            try {
                $invalid();
                $this->fail('Expected stale attachment context rejection.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }
}
