<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Domain\It\Services\ItTicketDraftService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItEmailDelivery;
use App\Models\ItSavedTicketFilter;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketDraft;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Run this file alone through run-isolated-it-tests.ps1. It deliberately lives
 * outside the regular suites: real workers need committed fixtures, and this
 * one process owns a new schema which Tests\TestCase removes at shutdown.
 */
final class ItTicketCommandConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing'
            || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/', (string) getenv('TEST_TOKEN')) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT test wrapper for this standalone concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_real_workers_serialize_create_replay_and_stale_ticket_edits(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed in this standalone schema, never by escaping a shared test transaction.');
        $this->assertMatchesRegularExpression('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', DB::connection()->getDatabaseName());
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $requester = $this->actor('support_worker', $site);
        $agent = $this->actor('hr', $site);

        $uuid = (string) Str::uuid();
        $created = $this->race($requester, ['create', 'create'], $uuid);
        $this->assertSame(['committed', 'committed'], array_column($created, 'status'));
        $this->assertSame($created[0]['id'], $created[1]['id']);
        $this->assertSame($created[0]['reference'], $created[1]['reference']);
        $replays = array_column($created, 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);
        $this->assertSame(1, ItTicket::query()->count());
        $this->assertSame(1, ItTicketCommandReceipt::query()->count());
        $ticket = ItTicket::query()->sole();
        $this->assertSame(1, $ticket->events()->where('type', 'created')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.created')->count());
        $deliveries = ItEmailDelivery::query()->get();
        $this->assertSame(1, $deliveries->count(), 'Identical simultaneous creates prepare one requester delivery intent.');
        $this->assertSame((int) $requester->id, (int) $deliveries->sole()->recipient_user_id);
        $this->assertNotNull($deliveries->sole()->dispatch_requested_at);

        $version = $ticket->lock_version;
        $mutations = $this->race($agent, ['priority-high', 'priority-urgent'], $uuid, $ticket);
        $outcomes = array_column($mutations, 'status');
        sort($outcomes);
        $this->assertSame(['committed', 'stale_ticket'], $outcomes);
        // A command may persist both assessment and routing. The token is
        // monotonic across model writes; the command winner remains unique.
        $this->assertGreaterThan($version, $ticket->fresh()->lock_version);
        $this->assertSame(1, $ticket->events()->where('type', 'priority_changed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.triage.updated')->count());
        $winner = collect($mutations)->firstWhere('status', 'committed');
        $loser = collect($mutations)->firstWhere('status', 'stale_ticket');
        $this->assertSame($winner['version'], $loser['version']);
        $this->assertSame($winner['version'], $ticket->fresh()->lock_version);
        $this->assertSame($winner['priority'], $ticket->fresh()->priority);

        // W05: both workers pass their pre-write point while the same parent
        // user remains locked. The store must recheck count and name only
        // after acquiring that lock, not rely on earlier request validation.
        foreach (range(1, 24) as $number) {
            ItSavedTicketFilter::query()->create([
                'user_id' => $agent->id, 'name' => 'Existing '.$number,
                'filters' => ['ticket_status' => 'open'],
            ]);
        }
        $limited = $this->race($agent, ['filter-limit-a', 'filter-limit-b'], (string) Str::uuid());
        $statuses = array_column($limited, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'validation_error'], $statuses);
        $this->assertSame(25, ItSavedTicketFilter::query()->where('user_id', $agent->id)->count());
        $limitLoser = collect($limited)->firstWhere('status', 'validation_error');
        $this->assertArrayHasKey('filters', $limitLoser['errors']);

        $other = $this->actor('hr', $site);
        $duplicates = $this->race($other, ['filter-same', 'filter-same'], (string) Str::uuid());
        $statuses = array_column($duplicates, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'validation_error'], $statuses);
        $this->assertSame(1, ItSavedTicketFilter::query()->where('user_id', $other->id)->count());
        $duplicateLoser = collect($duplicates)->firstWhere('status', 'validation_error');
        $this->assertArrayHasKey('name', $duplicateLoser['errors']);

        // W06: workers must serialize the same actor/context slot and its
        // revision before a second tab can initialize, save, discard or commit.
        config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $privateRoot = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($privateRoot));
        $initialized = $this->race($agent, ['draft-initialize', 'draft-initialize'], (string) Str::uuid(), $ticket);
        $this->assertSame(['initialized', 'initialized'], array_column($initialized, 'status'));
        $this->assertSame($initialized[0]['draft_uuid'], $initialized[1]['draft_uuid']);
        $this->assertSame(1, ItTicketDraft::query()->count());
        $draftUuid = $initialized[0]['draft_uuid'];
        $saved = $this->race($agent, ['draft-save-a', 'draft-save-b'], $draftUuid, $ticket, 0);
        $statuses = array_column($saved, 'status');
        sort($statuses);
        $this->assertSame(['draft_conflict', 'saved'], $statuses);
        $this->assertSame(1, ItTicketDraft::query()->sole()->revision);
        $this->assertSame(1, AuditLog::query()->where('action', 'it.draft.saved')->count());

        $discarded = $this->race($agent, ['draft-save-a', 'draft-discard'], $draftUuid, $ticket, 1);
        $statuses = array_column($discarded, 'status');
        sort($statuses);
        $this->assertContains($statuses, [['draft_conflict', 'saved'], ['discarded', 'draft_terminal']]);
        $draft = ItTicketDraft::query()->sole();
        $this->assertSame(2, $draft->revision);
        if ($draft->state === 'discarded') {
            $this->assertNull($draft->encrypted_payload);
        } else {
            $this->assertSame('active', $draft->state);
            $this->assertNotNull($draft->encrypted_payload);
        }

        $intake = app(ItTicketDraftService::class)->initialize($requester, ItTicketDraftPurpose::RequesterIntake, null, (string) Str::uuid());
        app(ItTicketDraftService::class)->save($requester, $intake['draft_uuid'], 0, ['title' => 'Initial intake snapshot'], 0, null);
        $ticketsBefore = ItTicket::query()->count();
        $receiptsBefore = ItTicketCommandReceipt::query()->count();
        $commits = $this->race($requester, ['draft-save-b', 'draft-create'], $intake['draft_uuid'], null, 1);
        $statuses = array_column($commits, 'status');
        sort($statuses);
        $this->assertContains($statuses, [['draft_conflict', 'saved'], ['committed', 'draft_terminal']]);
        $intakeRow = ItTicketDraft::query()->where('draft_uuid', $intake['draft_uuid'])->sole();
        if (in_array('committed', $statuses, true)) {
            $this->assertSame('consumed', $intakeRow->state);
            $this->assertNull($intakeRow->encrypted_payload);
            $this->assertSame($ticketsBefore + 1, ItTicket::query()->count());
            $this->assertSame($receiptsBefore + 1, ItTicketCommandReceipt::query()->count());
        } else {
            $this->assertSame('active', $intakeRow->state);
            $this->assertSame($ticketsBefore, ItTicket::query()->count());
            $this->assertSame($receiptsBefore, ItTicketCommandReceipt::query()->count());
        }

        $files = app(ItTicketDraftService::class)->initialize($requester, ItTicketDraftPurpose::RequesterIntake, null, (string) Str::uuid());
        $uploads = $this->race($requester, ['draft-upload', 'draft-upload'], $files['draft_uuid'], null, 0);
        $this->assertSame(['uploaded', 'uploaded'], array_column($uploads, 'status'));
        $this->assertSame($uploads[0]['attachment_id'], $uploads[1]['attachment_id']);
        $this->assertSame([2, 2], array_column($uploads, 'revision'));
        $this->assertSame(1, ItAttachment::query()->count());
        $this->assertCount(1, Storage::disk('private')->allFiles('it_attachments'));

        $discardFiles = app(ItTicketDraftService::class)->initialize($requester, ItTicketDraftPurpose::RequesterIntake, null, (string) Str::uuid());
        $removed = $this->race($requester, ['draft-upload', 'draft-discard'], $discardFiles['draft_uuid'], null, 0);
        $statuses = array_column($removed, 'status');
        sort($statuses);
        $this->assertContains($statuses, [['draft_conflict', 'uploaded'], ['discarded', 'draft_terminal']]);
        $discardRow = ItTicketDraft::query()->where('draft_uuid', $discardFiles['draft_uuid'])->sole();
        if ($discardRow->state === 'discarded') {
            $this->assertSame(0, $discardRow->attachments()->count());
        } else {
            $this->assertSame('ready', $discardRow->attachments()->sole()->draft_storage_state);
        }

        $commitFiles = app(ItTicketDraftService::class)->initialize($requester, ItTicketDraftPurpose::RequesterIntake, null, (string) Str::uuid());
        $ticketsBefore = ItTicket::query()->count();
        $raced = $this->race($requester, ['draft-upload', 'draft-create'], $commitFiles['draft_uuid'], null, 0);
        $statuses = array_column($raced, 'status');
        sort($statuses);
        $this->assertContains($statuses, [['draft_conflict', 'uploaded'], ['committed', 'draft_terminal']]);
        $commitRow = ItTicketDraft::query()->where('draft_uuid', $commitFiles['draft_uuid'])->sole();
        if ($commitRow->state === 'consumed') {
            $this->assertSame(0, $commitRow->attachments()->count());
            $this->assertSame($ticketsBefore + 1, ItTicket::query()->count());
        } else {
            $this->assertSame('ready', $commitRow->attachments()->sole()->draft_storage_state);
            $this->assertSame($ticketsBefore, ItTicket::query()->count());
        }
        $this->assertSame(ItAttachment::query()->count(), count(Storage::disk('private')->allFiles('it_attachments')));

        // W07: both requests freeze the same reply identity and draft revision
        // before the parent releases their ticket lock. The loser recovers the
        // first commit rather than consuming twice or treating it as stale.
        $conversation = ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $requester->id,
            'assigned_to_user_id' => $agent->id, 'status' => 'open', 'first_responded_at' => null]);
        $replyDraft = app(ItTicketDraftService::class)->initialize($agent, ItTicketDraftPurpose::PublicReply, $conversation->id, null);
        app(ItTicketDraftService::class)->save($agent, $replyDraft['draft_uuid'], 0,
            ['body' => 'Concurrent isolated public reply'], 0, $conversation->lock_version);
        $replyUuid = (string) Str::uuid();
        $replies = $this->race($agent, ['comment-draft', 'comment-draft'], $replyUuid, $conversation);
        $this->assertSame(['committed', 'committed'], array_column($replies, 'status'));
        $this->assertSame($replies[0]['comment_id'], $replies[1]['comment_id']);
        $this->assertSame($replies[0]['version'], $replies[1]['version']);
        $replayed = array_column($replies, 'replayed');
        sort($replayed);
        $this->assertSame([false, true], $replayed);
        $this->assertSame(1, $conversation->comments()->count());
        $this->assertSame(1, $conversation->events()->where('type', 'first_response_recorded')->count());
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('request_uuid', $replyUuid)->count());
        $this->assertSame(1, ItEmailDelivery::query()->where('it_ticket_id', $conversation->id)->count());
        $consumed = ItTicketDraft::query()->where('draft_uuid', $replyDraft['draft_uuid'])->sole();
        $this->assertSame('consumed', $consumed->state);
        $this->assertSame(2, $consumed->revision);
        $this->assertNull($consumed->encrypted_payload);

        $settlement = ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $requester->id,
            'assigned_to_user_id' => $agent->id, 'status' => 'open', 'first_responded_at' => null]);
        $race = $this->race($agent, ['comment', 'resolve'], (string) Str::uuid(), $settlement);
        $statuses = array_column($race, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'stale_ticket'], $statuses);
        $this->assertSame(1, $settlement->comments()->count(), 'The winner has one public reply or resolution note; a stale loser writes neither.');
        $this->assertSame($race[0]['status'] === 'committed' ? 'open' : 'resolved', $settlement->fresh()->status);
        $this->assertSame($race[0]['status'] === 'committed' ? 1 : 0,
            ItTicketCommandReceipt::query()->where('it_ticket_id', $settlement->id)->count());

        $cancellable = ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $requester->id,
            'assigned_to_user_id' => $agent->id, 'status' => 'open', 'first_responded_at' => null]);
        $heldDraft = app(ItTicketDraftService::class)->initialize($agent, ItTicketDraftPurpose::PublicReply, $cancellable->id, null);
        app(ItTicketDraftService::class)->save($agent, $heldDraft['draft_uuid'], 0,
            ['body' => 'Concurrent isolated public reply'], 0, $cancellable->lock_version);
        $cancelUuid = (string) Str::uuid();
        $cancellation = $this->race($agent, ['comment-draft', 'comment-cancel'], $cancelUuid, $cancellable);
        $outcomes = array_column($cancellation, 'status');
        $this->assertContains($outcomes, [['committed', 'committed'], ['cancelled', 'cancelled']]);
        $didCommit = $outcomes[0] === 'committed';
        $this->assertSame($didCommit ? 1 : 0, $cancellable->comments()->count());
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('request_uuid', $cancelUuid)->count());
        $held = ItTicketDraft::query()->where('draft_uuid', $heldDraft['draft_uuid'])->sole();
        $this->assertSame($didCommit ? 'consumed' : 'active', $held->state);
        $this->assertSame($didCommit ? 2 : 1, $held->revision);

        // These failures happen after the real outer commit. RefreshDatabase's
        // wrapping transaction cannot establish that files survive this case.
        foreach ([false, true] as $command) {
            $committed = ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $requester->id,
                'assigned_to_user_id' => $agent->id, 'status' => 'open', 'first_responded_at' => null]);
            $connection = DB::connection();
            $thrown = false;
            Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($connection, $committed, &$thrown): void {
                if (! $thrown && $event->connection === $connection && $connection->transactionLevel() === 0
                    && $committed->comments()->exists()) {
                    $thrown = true;
                    throw new RuntimeException('Synthetic exception after the reply transaction committed');
                }
            });
            try {
                $file = UploadedFile::fake()->createWithContent('committed-evidence.txt', 'Keep these committed bytes');
                if ($command) {
                    $result = app(ItTicketInteractionService::class)->addCommentCommand($committed, $agent, [
                        'request_uuid' => (string) Str::uuid(), 'actor_user_id' => $agent->id, 'expected_version' => $committed->lock_version,
                        'body' => 'Committed reply evidence', 'is_internal' => false,
                    ], [$file]);
                    $this->assertTrue($result->replayed, 'A new connection proves the durable receipt after an unknown acknowledgement.');
                    $this->assertSame((int) $committed->comments()->sole()->id, (int) $result->comment->id);
                } else {
                    try {
                        app(ItTicketInteractionService::class)->addComment($committed, $agent, 'Committed legacy reply evidence', false, [$file]);
                        $this->fail('The legacy adapter must preserve its unknown outcome without claiming an acknowledgement.');
                    } catch (RuntimeException $exception) {
                        $this->assertSame('Synthetic exception after the reply transaction committed', $exception->getMessage());
                    }
                }
                $this->assertTrue($thrown);
                $this->assertSame(1, $committed->comments()->count());
                $savedFile = $committed->comments()->sole()->attachments()->sole();
                $this->assertTrue(Storage::disk('private')->exists($savedFile->path));
                $this->assertSame('Keep these committed bytes', Storage::disk('private')->get($savedFile->path));
            } finally {
                Event::forget(TransactionCommitted::class);
            }
        }

        // W07 watchers share the aggregate lock and exact desired-state/version
        // contract. Concurrent identical additions audit once; different stale
        // membership proposals cannot silently overwrite one another.
        $watched = ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $requester->id,
            'assigned_to_user_id' => $agent->id, 'status' => 'open']);
        $subscriptions = $this->race($agent, ['watch-self', 'watch-self'], (string) Str::uuid(), $watched);
        $this->assertSame(['committed', 'committed'], array_column($subscriptions, 'status'));
        $changes = array_column($subscriptions, 'changed');
        sort($changes);
        $this->assertSame([false, true], $changes);
        $this->assertSame($subscriptions[0]['version'], $subscriptions[1]['version']);
        $this->assertSame(1, $watched->watchers()->count());
        $this->assertSame(1, $watched->events()->where('type', 'watcher_added')->count());
        $this->assertSame(true, $watched->events()->where('type', 'watcher_added')->sole()->payload['self']);
        $this->assertSame(1, AuditLog::query()->where('action', 'it.ticket.watcher.added')
            ->where('auditable_type', $watched->getMorphClass())->where('auditable_id', $watched->id)->count());
        $watched->refresh();
        $subscriptions = $this->race($agent, ['unwatch-self', 'watch-requester'], (string) Str::uuid(), $watched);
        $outcomes = array_column($subscriptions, 'status');
        sort($outcomes);
        $this->assertSame(['committed', 'stale_ticket'], $outcomes);
        $removedSelf = $subscriptions[0]['status'] === 'committed';
        $this->assertSame($removedSelf ? 0 : 2, $watched->watchers()->count());
        $this->assertSame(2, $watched->events()->whereIn('type', ['watcher_added', 'watcher_removed'])->count());
        $this->assertSame(2, AuditLog::query()->whereIn('action', ['it.ticket.watcher.added', 'it.ticket.watcher.removed'])
            ->where('auditable_type', $watched->getMorphClass())->where('auditable_id', $watched->id)->count());
    }

    private function actor(string $role, Site $site): User
    {
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $actor->roles()->sync(Role::query()->where('name', $role)->pluck('id'));
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
        ]);

        return $actor;
    }

    /** @return list<array<string, mixed>> */
    private function race(User $actor, array $operations, string $uuid, ?ItTicket $ticket = null, ?int $draftRevision = null): array
    {
        $barrier = storage_path('framework/testing/it-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $processes = [];
        $paths = [];
        DB::beginTransaction();
        if ($ticket) {
            ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        } else {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        }

        try {
            foreach ($operations as $index => $operation) {
                $ready = $barrier.'-'.$index.'.ready';
                $attempt = $barrier.'-'.$index.'.attempt';
                $paths[] = $ready;
                $paths[] = $attempt;
                $processes[] = $worker = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/It/ticket-command-concurrency-worker.php'),
                    $operation, (string) $actor->id, $uuid,
                    (string) ($ticket?->id ?? 0), (string) ($draftRevision ?? $ticket?->lock_version ?? 0),
                    $ready, $attempt, $barrier.'.release',
                ], base_path(), timeout: 45);
                $worker->start();
            }
            $this->waitForBarriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->waitForBarriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both workers must reach the command while its canonical row remains locked.');
            }
            DB::commit();

            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
                $outcomes[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
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
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @param list<string> $paths @param list<Process> $processes */
    private function waitForBarriers(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('A concurrency worker stopped before reaching its barrier: '.trim($process->getErrorOutput()));
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The isolated concurrency workers did not reach their barrier in time.');
            }
            usleep(10_000);
        }
    }
}
