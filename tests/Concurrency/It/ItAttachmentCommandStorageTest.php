<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItAttachmentStorageReservation;
use App\Domain\It\Services\ItAttachmentStorageService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Run alone through the reviewed isolated wrapper. There is no RefreshDatabase
 * transaction: these checks must observe actual outer commits and rollbacks.
 */
final class ItAttachmentCommandStorageTest extends TestCase
{
    public function createApplication()
    {
        $token = (string) getenv('TEST_TOKEN');
        $database = (string) getenv('DB_DATABASE');
        $owned = 'oblivion_it_support_test_'.$token;
        $samePreparedSchema = self::$isolatedMysqlPrepared && self::$isolatedMysqlDatabase === $owned && $database === $owned;
        if (getenv('APP_ENV') !== 'testing' || ($database !== 'oblivion_it_support_test' && ! $samePreparedSchema)
            || preg_match('/^it_[a-f0-9]{16}$/', $token) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT wrapper for attachment command verification.');
        }

        return parent::createApplication();
    }

    public function test_direct_command_rollback_and_retry_use_durable_intents_with_drafts_disabled(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        config(['it.drafts.enabled' => false]);
        Notification::fake();
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $storageRoot = configureItDraftConcurrencyStorage();
        $site = Site::factory()->create();
        $requester = $this->actor('support_worker', $site);
        $agent = $this->actor('hr', $site);

        try {
            $schema = Schema::getFacadeRoot();
            $missingSetup = Mockery::mock($schema)->makePartial();
            $missingSetup->shouldReceive('hasTable')->with('it_attachment_storage_intents')->andReturn(false);
            Schema::swap($missingSetup);
            try {
                foreach (['intake', 'typed', 'legacy'] as $mode) {
                    $ticket = ItTicket::factory()->create(['site_id' => $site->id,
                        'requester_user_id' => $requester->id, 'assigned_to_user_id' => $agent->id]);
                    $this->invoke($mode, $requester, $agent, $ticket, (string) Str::uuid(), withFile: false);
                    $ticket->refresh();
                    $before = [ItTicket::count(), $ticket->comments()->count(), ItTicketCommandReceipt::count()];
                    try {
                        $this->invoke($mode, $requester, $agent, $ticket, (string) Str::uuid());
                        $this->fail('Missing attachment setup must not write an untracked file.');
                    } catch (ValidationException $exception) {
                        $this->assertArrayHasKey('attachments', $exception->errors());
                        $this->assertStringContainsString('setup is complete', $exception->errors()['attachments'][0]);
                    }
                    $this->assertSame($before, [ItTicket::count(), $ticket->comments()->count(), ItTicketCommandReceipt::count()]);
                    $this->assertSame([], Storage::disk(ItAttachment::DISK)->allFiles());
                }
                $this->assertSame(0, ItAttachmentStorageIntent::count());
            } finally {
                Schema::swap($schema);
            }

            foreach (['intake', 'typed', 'legacy'] as $mode) {
                $ticket = ItTicket::factory()->create(['site_id' => $site->id,
                    'requester_user_id' => $requester->id, 'assigned_to_user_id' => $agent->id]);
                $uuid = (string) Str::uuid();
                $firstIntentId = (int) ItAttachmentStorageIntent::query()->max('id');
                $auditEvent = 'eloquent.creating: '.AuditLog::class;
                Event::listen($auditEvent, function (AuditLog $audit) use ($mode, $ticket): void {
                    if (($mode === 'intake' && $audit->action === 'it.ticket.created')
                        || ($mode !== 'intake' && $audit->action === 'it.ticket.comment.added'
                            && (int) $audit->auditable_id === (int) $ticket->id)) {
                        throw new RuntimeException('Original synthetic business failure');
                    }
                });
                $disk = Storage::disk(ItAttachment::DISK);
                $broken = Mockery::mock($disk)->makePartial();
                $broken->shouldReceive('delete')->once()->andReturn(false);
                Storage::set(ItAttachment::DISK, $broken);
                try {
                    $this->invoke($mode, $requester, $agent, $ticket, $uuid);
                    $this->fail('The failed business command cannot claim success.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Original synthetic business failure', $exception->getMessage());
                } finally {
                    Event::forget($auditEvent);
                    Storage::set(ItAttachment::DISK, $disk);
                }
                $this->assertSame(0, DB::transactionLevel());
                $intent = ItAttachmentStorageIntent::query()->where('id', '>', $firstIntentId)->sole();
                $this->assertSame('cleanup_pending', $intent->state);
                $this->assertTrue($disk->exists($intent->path));
                $this->assertSame(0, ItAttachment::query()->where('path', $intent->path)->count());
                $this->assertSame(0, ItTicketCommandReceipt::query()->where('request_uuid', $uuid)->count());
                $this->assertSame(0, $ticket->comments()->count());
                if ($mode === 'intake') {
                    $this->assertSame(0, ItTicket::query()->where('title', 'Isolated attachment '.$uuid)->count());
                }

                // A fresh owner uses persisted pending state, not remembered paths.
                app()->forgetInstance(ItAttachmentStorageService::class);
                app(ItAttachmentStorageService::class)->retryPending(100);
                $this->assertSame('deleted', $intent->fresh()->state);
                $this->assertFalse($disk->exists($intent->path));
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            removeItDraftConcurrencyStorage($storageRoot);
        }
    }

    public function test_nested_rollback_defers_cleanup_until_its_outer_transaction_has_ended(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        Notification::fake();
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $storageRoot = configureItDraftConcurrencyStorage();
        $site = Site::factory()->create();
        $requester = $this->actor('support_worker', $site);
        $agent = $this->actor('hr', $site);
        $ticket = ItTicket::factory()->create(['site_id' => $site->id,
            'requester_user_id' => $requester->id, 'assigned_to_user_id' => $agent->id]);
        $firstIntentId = (int) ItAttachmentStorageIntent::query()->max('id');
        $receiptEvent = 'eloquent.updating: '.ItTicketCommandReceipt::class;
        Event::listen($receiptEvent, fn () => throw new RuntimeException('Synthetic nested receipt failure'));
        DB::beginTransaction();
        try {
            try {
                $this->invoke('typed', $requester, $agent, $ticket, (string) Str::uuid());
                $this->fail('The nested command cannot claim success.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic nested receipt failure', $exception->getMessage());
            }
            $this->assertSame(1, DB::transactionLevel());
            $intent = ItAttachmentStorageIntent::query()->where('id', '>', $firstIntentId)->sole();
            $this->assertSame('reserved', $intent->state);
            $this->assertTrue(Storage::disk(ItAttachment::DISK)->exists($intent->path));
            $this->assertSame(0, $ticket->comments()->count());
            $reference = new ItAttachmentStorageReservation((int) $intent->id, $intent->intent_uuid, $intent->path);
            $result = app(ItAttachmentStorageService::class)->requestRollbackCleanup([$reference]);
            $this->assertSame(1, $result['deferred']);
            DB::rollBack();
            $this->assertSame('reserved', $intent->fresh()->state);
            app(ItAttachmentStorageService::class)->requestRollbackCleanup([$reference]);
            $this->assertSame('deleted', $intent->fresh()->state);
            $this->assertFalse(Storage::disk(ItAttachment::DISK)->exists($intent->path));
        } finally {
            Event::forget($receiptEvent);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            removeItDraftConcurrencyStorage($storageRoot);
        }
    }

    public function test_postcommit_exceptions_and_receipt_replay_preserve_canonical_direct_files(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        Notification::fake();
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $storageRoot = configureItDraftConcurrencyStorage();
        $site = Site::factory()->create();
        $requester = $this->actor('support_worker', $site);
        $agent = $this->actor('hr', $site);

        try {
            foreach (['intake', 'typed', 'legacy'] as $mode) {
                $ticket = ItTicket::factory()->create(['site_id' => $site->id,
                    'requester_user_id' => $requester->id, 'assigned_to_user_id' => $agent->id]);
                $uuid = (string) Str::uuid();
                $firstIntentId = (int) ItAttachmentStorageIntent::query()->max('id');
                $connection = DB::connection();
                $thrown = false;
                Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($connection, $mode, $uuid, $ticket, &$thrown): void {
                    if (! $thrown && $event->connection === $connection && $connection->transactionLevel() === 0
                        && ($mode === 'legacy' ? $ticket->comments()->exists()
                            : ItTicketCommandReceipt::query()->where('request_uuid', $uuid)->whereNotNull('committed_at')->exists())) {
                        $thrown = true;
                        throw new RuntimeException('Synthetic failure after actual attachment commit');
                    }
                });
                try {
                    try {
                        $this->invoke($mode, $requester, $agent, $ticket, $uuid);
                        $this->assertNotSame('legacy', $mode, 'Legacy acknowledgement remains unknown.');
                    } catch (RuntimeException $exception) {
                        $this->assertSame('legacy', $mode);
                        $this->assertSame('Synthetic failure after actual attachment commit', $exception->getMessage());
                    }
                } finally {
                    Event::forget(TransactionCommitted::class);
                }
                $this->assertTrue($thrown);
                $intent = ItAttachmentStorageIntent::query()->where('id', '>', $firstIntentId)->sole();
                $this->assertSame('attached', $intent->state);
                $attachment = ItAttachment::query()->where('path', $intent->path)->sole();
                $this->assertSame('Exact isolated attachment bytes', Storage::disk(ItAttachment::DISK)->get($attachment->path));
                app(ItAttachmentStorageService::class)->retryPending(100);
                $this->assertTrue(Storage::disk(ItAttachment::DISK)->exists($attachment->path));
                if ($mode !== 'legacy') {
                    $this->invoke($mode, $requester, $agent, $ticket, $uuid);
                    $this->assertSame(1, ItAttachmentStorageIntent::query()->where('id', '>', $firstIntentId)->count());
                    $this->assertSame(1, ItAttachment::query()->where('path', $intent->path)->count());
                }
            }
        } finally {
            removeItDraftConcurrencyStorage($storageRoot);
        }
    }

    private function invoke(string $mode, User $requester, User $agent, ItTicket $ticket, string $uuid, bool $withFile = true): mixed
    {
        $files = $withFile ? [UploadedFile::fake()->createWithContent('isolated-evidence.txt', 'Exact isolated attachment bytes')] : [];
        if ($mode === 'intake') {
            return app(ItTicketIntakeService::class)->createCommand($requester, [
                'request_uuid' => $uuid, 'title' => 'Isolated attachment '.$uuid,
                'category' => 'hardware', 'priority' => 'normal',
            ], $files);
        }
        if ($mode === 'typed') {
            return app(ItTicketInteractionService::class)->addCommentCommand($ticket, $agent, [
                'request_uuid' => $uuid, 'actor_user_id' => $agent->id,
                'expected_version' => $ticket->lock_version, 'body' => 'Isolated attachment command', 'is_internal' => false,
            ], $files);
        }

        return app(ItTicketInteractionService::class)->addComment($ticket, $agent, 'Isolated legacy attachment command', false, $files);
    }

    private function actor(string $role, Site $site): User
    {
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $actor->roles()->sync(Role::query()->where('name', $role)->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

        return $actor;
    }
}
