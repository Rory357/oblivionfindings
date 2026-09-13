<?php

namespace Tests\Concurrency\It;

use App\Domain\It\Data\ItAttachmentStorageReservation;
use App\Domain\It\Services\ItAttachmentStorageIntentService;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/** Run alone with the isolated wrapper: reservation durability needs real commits. */
final class ItAttachmentStorageIntentTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/', (string) getenv('TEST_TOKEN')) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT wrapper for storage intent verification.');
        }

        return parent::createApplication();
    }

    public function test_durable_cleanup_and_real_workers_fence_every_late_write(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertMatchesRegularExpression('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', DB::connection()->getDatabaseName());
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertContains(config('broadcasting.default'), [null, 'null']);
        Notification::fake();
        config(['it.drafts.enabled' => false]);
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $privateRoot = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($privateRoot));
        $actor = User::factory()->create(['approved_at' => now()]);
        $other = User::factory()->create(['approved_at' => now()]);
        $ticket = ItTicket::factory()->create(['requester_user_id' => $actor->id]);
        $otherTicket = ItTicket::factory()->create(['requester_user_id' => $actor->id]);
        $service = app(ItAttachmentStorageIntentService::class);
        $disk = Storage::disk(ItAttachment::DISK);

        // Validate the entire batch before reserving any path or copying bytes.
        $this->rejects(fn () => $service->reserveDirect($ticket, [$this->file(), UploadedFile::fake()->createWithContent('script.html', '<html>unsafe</html>')], $actor), ValidationException::class);
        $this->rejects(fn () => $service->reserveDirect($ticket, array_fill(0, 6, $this->file()), $actor), ValidationException::class);
        $this->assertSame(0, ItAttachmentStorageIntent::query()->count());
        $this->assertSame([], $disk->allFiles());
        $this->assertSame([], $service->reserveDirect($ticket, [], $actor));
        $databaseManager = DB::getFacadeRoot();
        $unavailableDatabase = Mockery::mock(DatabaseManager::class);
        $unavailableDatabase->shouldNotReceive('connection');
        DB::swap($unavailableDatabase);
        try {
            $this->assertSame(['requested' => 0, 'deleted' => 0, 'failed' => 0, 'deferred' => 0, 'reconciliation_required' => 0], $service->requestRollbackCleanup([]));
        } finally {
            DB::swap($databaseManager);
        }
        DB::transaction(function () use ($service, $ticket, $actor): void {
            $paths = [];
            $service->storeReservedDirect($ticket, [], $actor, [], $paths);
            $this->assertSame([], $paths);
        });

        $reference = $service->reserveDirect($ticket, [3 => $this->file()], $actor)[0];
        foreach ([[$ticket, $other, $this->file()], [$otherTicket, $actor, $this->file()],
            [$ticket, $actor, $this->file('Changed bytes')], [$ticket, $actor, $this->file(name: 'renamed.txt')]] as [$parent, $user, $file]) {
            $this->rejects(fn () => DB::transaction(function () use ($service, $parent, $user, $file, $reference): void {
                $paths = [];
                $service->storeReservedDirect($parent, [$file], $user, [$reference], $paths);
            }), DomainException::class);
        }
        $this->assertFalse($disk->exists($reference->path));
        $this->assertSame('reserved', $this->intent($reference)->state);
        $forged = new ItAttachmentStorageReservation($reference->id, (string) Str::uuid(), 'it_attachments/'.Str::uuid());
        $this->assertSame(1, $service->requestRollbackCleanup([$forged])['failed']);
        $this->assertSame('reserved', $this->intent($reference)->state);

        // Metadata survives the real outer rollback; canonical rows do not.
        DB::beginTransaction();
        $parent = ItTicket::factory()->create(['requester_user_id' => $actor->id]);
        $durable = $service->reserveDirect($parent, [$this->file()], $actor)[0];
        $paths = [];
        $service->storeReservedDirect($parent, [$this->file()], $actor, [$durable], $paths);
        DB::rollBack();
        $this->assertNull(ItTicket::query()->find($parent->id));
        $this->assertSame('reserved', $this->intent($durable)->state);
        $this->assertFalse(ItAttachment::query()->where('path', $durable->path)->exists());
        $this->assertTrue($disk->exists($durable->path));

        // False and thrown deletes leave durable, retryable evidence.
        foreach (['false', 'throw'] as $failure) {
            $candidate = $failure === 'false' ? $durable : $service->reserveDirect($ticket, [$this->file()], $actor)[0];
            $disk->put($candidate->path, 'Synthetic leftover bytes');
            $result = $this->withBrokenDelete($failure, fn () => $service->requestRollbackCleanup([$candidate]));
            $this->assertSame(1, $result['failed']);
            $this->assertSame(0, $result['deleted']);
            $this->assertTrue($disk->exists($candidate->path));
            $intent = $this->intent($candidate);
            $this->assertSame('cleanup_pending', $intent->state);
            $this->assertSame(1, $intent->cleanup_attempts);
            $this->assertSame('storage_delete_failed', $intent->cleanup_error_code);
            $this->assertSame(1, (new ItAttachmentStorageIntentService)->retryPending(1)['deleted']);
            $this->assertFalse($disk->exists($candidate->path));
            $this->assertSame('deleted', $this->intent($candidate)->state);
            $this->assertSame(2, $this->intent($candidate)->cleanup_attempts);
            $this->assertSame(1, $service->requestRollbackCleanup([$candidate])['deleted']);
            $this->assertSame(1, $this->audits($candidate, 'completed'));
        }

        // Storage success followed by a failed required audit is not DB success.
        $auditFailure = $service->reserveDirect($ticket, [$this->file()], $actor)[0];
        $disk->put($auditFailure->path, 'Synthetic leftover bytes');
        $event = 'eloquent.creating: '.AuditLog::class;
        Event::listen($event, function (AuditLog $audit): void {
            if ($audit->action === 'it.attachment.cleanup.completed') {
                throw new RuntimeException('Synthetic completion audit unavailable');
            }
        });
        try {
            $this->assertSame(1, $service->requestRollbackCleanup([$auditFailure])['failed']);
        } finally {
            Event::forget($event);
        }
        $this->assertFalse($disk->exists($auditFailure->path));
        $this->assertSame('cleanup_pending', $this->intent($auditFailure)->state);
        $this->assertSame(0, $this->audits($auditFailure, 'completed'));
        $this->assertSame(1, $service->retryPending(1)['deleted']);
        $this->assertSame(1, $this->audits($auditFailure, 'completed'));

        // A bounded pass rotates failed work instead of starving every newer row.
        $fair = $service->reserveDirect($ticket, [$this->file(), $this->file()], $actor);
        foreach ($fair as $candidate) {
            $disk->put($candidate->path, 'Synthetic fair retry bytes');
        }
        $this->withBrokenDelete('false', fn () => $service->requestRollbackCleanup($fair));
        foreach ($fair as $candidate) {
            $this->intent($candidate)->forceFill(['last_cleanup_attempt_at' => now()->subDay()])->save();
        }
        $this->assertSame(1, $this->withBrokenDelete('false', fn () => $service->retryPending(1))['failed']);
        $this->assertSame(1, $service->retryPending(1)['deleted']);
        $this->assertSame('cleanup_pending', $this->intent($fair[0])->state);
        $this->assertSame('deleted', $this->intent($fair[1])->state);
        $this->assertSame(1, $service->retryPending(1)['deleted']);

        // Mechanical cleanup survives a removed historical actor and stale session.
        $removedActor = User::factory()->create();
        $removed = $service->reserveDirect($ticket, [$this->file()], $removedActor)[0];
        $disk->put($removed->path, 'Synthetic removed actor bytes');
        User::query()->whereKey($removedActor->id)->delete();
        $this->actingAs($removedActor);
        $this->assertSame(1, $service->requestRollbackCleanup([$removed])['deleted']);
        $cleanupAudit = AuditLog::query()->where('action', 'it.attachment.cleanup.completed')->where('auditable_id', $removed->id)->sole();
        $this->assertNull($cleanupAudit->user_id);
        $this->assertSame($removedActor->id, $cleanupAudit->meta['original_actor_user_id']);
        $this->assertSame($removedActor->id, $this->intent($removed)->actor_user_id);
        $this->actingAs($actor);
        AuditLogger::logOrFail('it.storage.test.ordinary-attribution', $ticket);
        $this->assertSame($actor->id, AuditLog::query()->where('action', 'it.storage.test.ordinary-attribution')->sole()->user_id);
        auth()->forgetGuards();
        AuditLogger::logOrFail('it.storage.test.fallback-attribution', $ticket, ['actor_id' => (int) $actor->id]);
        $this->assertSame($actor->id, AuditLog::query()->where('action', 'it.storage.test.fallback-attribution')->sole()->user_id);

        // An unknown reservation is never promoted merely because no row exists.
        $disk->put($reference->path, 'Potentially committed original bytes');
        $this->assertSame(0, $service->retryPending()['requested']);
        $this->assertTrue($disk->exists($reference->path));
        $this->assertSame('reserved', $this->intent($reference)->state);
        $this->rejects(fn () => $service->retryPending(1001), LogicException::class);

        // Committed attachment identity is immutable and never cleanup eligible.
        $attached = $service->reserveDirect($ticket, [$this->file()], $actor)[0];
        DB::transaction(function () use ($service, $ticket, $actor, $attached): void {
            $paths = [];
            $service->storeReservedDirect($ticket, [3 => $this->file()], $actor, [$attached], $paths);
            $this->assertSame([$attached->path], $paths);
        });
        $this->assertSame(1, $service->requestRollbackCleanup([$attached])['reconciliation_required']);
        $this->assertTrue($disk->exists($attached->path));
        $this->assertSame('attached', $this->intent($attached)->state);
        $this->rejects(fn () => $this->intent($attached)->forceFill(['attachment_id' => 999999])->save(), LogicException::class);
        $this->rejects(fn () => $this->intent($reference)->forceFill(['path' => 'it_attachments/changed'])->save(), LogicException::class);
        $this->rejects(fn () => $this->intent($durable)->forceFill(['state' => 'reserved'])->save(), LogicException::class);
        $this->rejects(fn () => $this->intent($durable)->delete(), LogicException::class);

        // A late writer and rollback cleanup must serialize on the same row.
        $raced = $service->reserveDirect($otherTicket, [$this->fencingFile()], $actor)[0];
        $outcomes = $this->race($actor, $otherTicket, $raced, ['store', 'cleanup']);
        $intent = $this->intent($raced);
        if ($outcomes[0]['status'] === 'attached') {
            $this->assertSame('attached', $intent->state);
            $this->assertSame(1, $outcomes[1]['reconciliation_required']);
            $this->assertSame(1, ItAttachment::query()->where('path', $raced->path)->count());
            $this->assertTrue($disk->exists($raced->path));
        } else {
            $this->assertSame('fenced', $outcomes[0]['status']);
            $this->assertSame('deleted', $intent->state);
            $this->assertSame(1, $outcomes[1]['deleted']);
            $this->assertFalse(ItAttachment::query()->where('path', $raced->path)->exists());
            $this->assertFalse($disk->exists($raced->path));
        }
        $duplicate = $service->reserveDirect($otherTicket, [$this->fencingFile()], $actor)[0];
        $disk->put($duplicate->path, 'Synthetic duplicate cleanup bytes');
        $this->withBrokenDelete('false', fn () => $service->requestRollbackCleanup([$duplicate]));
        $outcomes = $this->race($actor, $otherTicket, $duplicate, ['cleanup', 'cleanup']);
        $this->assertSame([1, 1], array_column($outcomes, 'deleted'));
        $this->assertSame(1, $this->audits($duplicate, 'requested'));
        $this->assertSame(1, $this->audits($duplicate, 'completed'));
        $this->assertFalse($disk->exists($duplicate->path));
        $this->rejects(fn () => DB::transaction(function () use ($service, $otherTicket, $actor, $duplicate): void {
            $paths = [];
            $service->storeReservedDirect($otherTicket, [$this->fencingFile()], $actor, [$duplicate], $paths);
        }), DomainException::class);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(collect(array_keys(DB::getConnections()))->contains(fn ($name) => str_starts_with($name, 'it_storage_intent_')));
    }

    private function file(string $contents = 'Synthetic immutable direct bytes', string $name = 'evidence.txt'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function fencingFile(): UploadedFile
    {
        return $this->file('Synthetic exact storage fencing bytes', 'fencing.txt');
    }

    private function intent(ItAttachmentStorageReservation $reference): ItAttachmentStorageIntent
    {
        return ItAttachmentStorageIntent::query()->findOrFail($reference->id);
    }

    private function audits(ItAttachmentStorageReservation $reference, string $action): int
    {
        return AuditLog::query()->where('action', 'it.attachment.cleanup.'.$action)
            ->where('auditable_type', (new ItAttachmentStorageIntent)->getMorphClass())->where('auditable_id', $reference->id)->count();
    }

    private function rejects(callable $callback, string $class): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($class, $exception);

            return;
        }
        $this->fail('Expected '.$class.' before an unsafe storage action.');
    }

    private function withBrokenDelete(string $failure, callable $callback): mixed
    {
        $manager = Storage::getFacadeRoot();
        $disk = $manager->disk(ItAttachment::DISK);
        $brokenDisk = Mockery::mock($disk)->makePartial();
        $brokenDisk->shouldReceive('delete')->andReturnUsing(function () use ($failure): bool {
            if ($failure === 'throw') {
                throw new RuntimeException('Synthetic unavailable private storage');
            }

            return false;
        });
        $brokenManager = Mockery::mock(FilesystemManager::class);
        $brokenManager->shouldReceive('disk')->with(ItAttachment::DISK)->andReturn($brokenDisk);
        Storage::swap($brokenManager);
        try {
            return $callback();
        } finally {
            Storage::swap($manager);
        }
    }

    private function race(User $actor, ItTicket $ticket, ItAttachmentStorageReservation $reference, array $operations): array
    {
        $barrier = storage_path('framework/testing/it-storage-fence-'.Str::uuid());
        $processes = [];
        $paths = [];
        DB::beginTransaction();
        ItAttachmentStorageIntent::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $ready = $barrier.'-'.$index.'.ready';
                $attempt = $barrier.'-'.$index.'.attempt';
                array_push($paths, $ready, $attempt);
                $processes[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/attachment-storage-concurrency-worker.php'),
                    $operation, (string) $actor->id, (string) $ticket->id, (string) $reference->id, $reference->uuid,
                    $ready, $attempt, $barrier.'.release'], base_path(), timeout: 45);
                $worker->start();
            }
            $this->waitFor([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->waitFor([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($processes as $worker) {
                $this->assertTrue($worker->isRunning(), 'Both workers attempted storage while the parent held its intent lock.');
            }
            DB::commit();
            $outcomes = [];
            foreach ($processes as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), trim($worker->getErrorOutput()));
                $outcomes[] = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
            foreach ($outcomes as $outcome) {
                $this->assertLessThanOrEqual($releasedAt, $outcome['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $outcome['completed_at']);
            }

            return $outcomes;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function waitFor(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($processes as $worker) {
                if (! $worker->isRunning()) {
                    throw new RuntimeException('Storage worker stopped before its barrier: '.trim($worker->getErrorOutput()));
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Storage workers did not reach their barrier.');
            }
            usleep(10_000);
        }
    }
}
