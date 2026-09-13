<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Run alone through the isolated IT wrapper: these fixtures really commit. */
final class ItTicketCommitRecoveryTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing'
            || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/', (string) getenv('TEST_TOKEN')) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT test wrapper for standalone commit recovery verification.');
        }

        return parent::createApplication();
    }

    public function test_committed_files_survive_local_failure_and_uncertain_reconciliation(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertMatchesRegularExpression('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', DB::connection()->getDatabaseName());
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        Notification::fake();
        Storage::fake(ItAttachment::DISK);
        $site = Site::factory()->create();
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'it-commit-'.Str::uuid(), 'label' => 'Synthetic commit recovery', 'level' => 50, 'type' => 'custom']);
        $permission = Permission::query()->firstOrCreate(['key' => 'it.request'], ['description' => 'Request IT', 'group' => 'it', 'module' => 'Operations']);
        $role->permissions()->attach($permission);
        $actor->roles()->attach($role);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
        ]);
        $intake = app(ItTicketIntakeService::class);

        foreach ([true, false] as $withCommand) {
            $input = $this->input();
            if (! $withCommand) {
                unset($input['request_uuid']);
            }
            $this->throwAfterCommit();
            try {
                $ticket = $intake->create($actor, $input, [$this->file()]);
            } finally {
                Event::forget(TransactionCommitted::class);
            }
            $this->assertSame($input['title'], $ticket->title);
            $this->assertSavedEvidence($ticket);
            if ($withCommand) {
                $recovered = $intake->recoverCommand($actor, $input['request_uuid']);
                $this->assertSame($ticket->reference, $recovered->ticket->reference);
                $replay = $intake->createCommand($actor, $input, [$this->file()]);
                $this->assertSame($ticket->id, $replay->ticket->id);
                $this->assertTrue($replay->replayed);
                $this->assertSame(1, ItTicketCommandReceipt::query()->where('request_uuid', $input['request_uuid'])->count());
            }
            $this->assertSame(1, ItTicket::query()->where('title', $input['title'])->count());
        }

        $input = $this->input();
        $this->throwAfterCommit();
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if (str_starts_with($event->connection->getName(), 'it_command_recovery_')) {
                throw new RuntimeException('Synthetic unavailable reconciliation connection');
            }
        });
        try {
            try {
                $intake->createCommand($actor, $input, [$this->file()]);
                $this->fail('An unverified outcome must not return a confirmed result.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic post-commit listener failure', $exception->getMessage());
            }
        } finally {
            Event::forget(TransactionCommitted::class);
            Event::forget(ConnectionEstablished::class);
        }
        $recovered = $intake->recoverCommand($actor, $input['request_uuid']);
        $this->assertSavedEvidence($recovered->ticket);
        $this->assertSame($recovered->ticket->id, $intake->createCommand($actor, $input, [$this->file()])->ticket->id);

        $input = $this->input();
        $this->throwAfterCommit(fn () => User::query()->whereKey($actor->id)->update(['approved_at' => null]));
        try {
            try {
                $intake->createCommand($actor, $input, [$this->file()]);
                $this->fail('A committed record must not be returned after the actor loses access.');
            } catch (AuthorizationException) {
                $ticket = ItTicket::query()->where('title', $input['title'])->sole();
                $this->assertSavedEvidence($ticket);
            }
        } finally {
            Event::forget(TransactionCommitted::class);
            User::query()->whereKey($actor->id)->update(['approved_at' => now()]);
        }

        $fileCount = count(Storage::disk(ItAttachment::DISK)->allFiles());
        $input = $this->input();
        $event = 'eloquent.updating: '.ItTicketCommandReceipt::class;
        Event::listen($event, fn () => throw new RuntimeException('Synthetic pre-commit failure'));
        try {
            try {
                $intake->createCommand($actor, $input, [$this->file()]);
                $this->fail('A rolled-back command must fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic pre-commit failure', $exception->getMessage());
            }
        } finally {
            Event::forget($event);
        }
        $this->assertSame(0, ItTicket::query()->where('title', $input['title'])->count());
        $this->assertSame(0, ItTicketCommandReceipt::query()->where('request_uuid', $input['request_uuid'])->count());
        $this->assertCount($fileCount, Storage::disk(ItAttachment::DISK)->allFiles());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(collect(array_keys(DB::getConnections()))->contains(fn ($name) => str_starts_with($name, 'it_command_recovery_')));
    }

    private function throwAfterCommit(?callable $beforeFailure = null): void
    {
        $armed = true;
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$armed, $beforeFailure): void {
            if ($armed && $event->connection->transactionLevel() === 0) {
                $armed = false;
                $beforeFailure?->__invoke();
                throw new RuntimeException('Synthetic post-commit listener failure');
            }
        });
    }

    private function input(): array
    {
        return ['request_uuid' => (string) Str::uuid(), 'title' => 'Synthetic commit '.Str::uuid(), 'category' => 'hardware', 'priority' => 'normal'];
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('evidence.txt', 'Synthetic stable attachment');
    }

    private function assertSavedEvidence(ItTicket $ticket): void
    {
        $attachment = $ticket->attachments()->sole();
        Storage::disk(ItAttachment::DISK)->assertExists($attachment->path);
        $this->assertSame(1, $ticket->events()->where('type', 'created')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.created')->where('auditable_id', $ticket->id)->count());
        $this->assertSame(1, ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->count());
    }
}
