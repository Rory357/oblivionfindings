<?php

use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->sender = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->sender, $this->agent] as $actor) {
        $actor->roles()->attach(Role::where('name', $actor->role)->firstOrFail());
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->message = ['from' => $this->sender->email, 'message_id' => '<canonical-intake@example.test>',
        'subject' => 'Synthetic canonical intake', 'text' => 'A synthetic email request.'];
    $this->ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id, 'requester_user_id' => $this->sender->id,
        'requested_for_user_id' => $this->sender->id, 'assigned_to_user_id' => $this->agent->id,
        'status' => 'open', 'first_responded_at' => null,
    ]);
    $this->ticket->refresh();
});

test('email intake commits canonical audit routing priority responsibility and one notification intent', function () {
    $this->message['subject'] = str_repeat('s', 250);
    $ingestor = app(InboundEmailIngestor::class);
    $row = $ingestor->ingest($this->message);
    $ticket = $row->ticket;
    expect($ticket->source)->toBe('email')->and($ticket->requested_for_user_id)->toBe($this->sender->id)
        ->and($ticket->next_response_party)->toBe('it')->and($ticket->priority_decision['mode'])->toBe('automatic')
        ->and(AuditLog::where('action', 'it.ticket.created')->count())->toBe(1)
        ->and(ItTicketCommandReceipt::where('channel', 'email')->where('operation', 'ticket.create')->count())->toBe(1)
        ->and(ItEmailDelivery::where('it_ticket_id', $ticket->id)->count())->toBe(1)
        ->and(mb_strlen(ItEmailDelivery::where('it_ticket_id', $ticket->id)->sole()->subject))->toBeGreaterThan(255)
        ->and(ItEmailDelivery::where('it_ticket_id', $ticket->id)->sole()->subject)->toEndWith($ticket->title)
        ->and($ingestor->ingest($this->message)->id)->toBe($row->id)
        ->and(ItTicketCommandReceipt::count())->toBe(1)
        ->and(ItEmailDelivery::where('it_ticket_id', $ticket->id)->count())->toBe(1);
});

test('an IT email reply records canonical first response responsibility version audit and delivery once', function () {
    $version = $this->ticket->lock_version;
    $message = [...$this->message, 'from' => $this->agent->email,
        'subject' => 'Re: '.$this->ticket->reference, 'message_id' => '<canonical-reply@example.test>'];
    $ingestor = app(InboundEmailIngestor::class);
    $row = $ingestor->ingest($message);
    $ticket = $this->ticket->fresh();
    $comment = $ticket->comments()->sole();
    expect($row->status)->toBe('processed')->and($comment->source_channel)->toBe('email')
        ->and($comment->speaker_side)->toBe('it')->and($comment->is_internal)->toBeFalse()
        ->and($ticket->first_responded_at)->not->toBeNull()->and($ticket->next_response_party)->toBe('requester')
        ->and($ticket->lock_version)->toBeGreaterThan($version)
        ->and(AuditLog::where('action', 'it.ticket.comment.added')->count())->toBe(1)
        ->and(ItEmailDelivery::where('it_ticket_id', $ticket->id)->count())->toBe(1)
        ->and($ingestor->ingest($message)->id)->toBe($row->id)
        ->and($ticket->comments()->count())->toBe(1)
        ->and(ItTicketCommandReceipt::where('channel', 'email')->where('operation', 'ticket.comment')->count())->toBe(1)
        ->and($ticket->fresh()->lock_version)->toBe($ticket->lock_version);
});

test('requester email uses the canonical waiting-to-active conversation transition', function () {
    $this->ticket->forceFill(['status' => 'waiting', 'workflow_state' => 'waiting',
        'waiting_party' => 'requester', 'waiting_since' => now()->subHour()])->save();
    $row = app(InboundEmailIngestor::class)->ingest([...$this->message,
        'subject' => 'Re: '.$this->ticket->reference]);
    expect($row->status)->toBe('processed')->and($this->ticket->fresh()->status)->toBe('in_progress')
        ->and($this->ticket->fresh()->waiting_party)->toBeNull()
        ->and($this->ticket->fresh()->next_response_party)->toBe('it')
        ->and($this->ticket->comments()->sole()->source_channel)->toBe('email');
});

test('settled email waits for governed policy without silently appending or reopening', function () {
    $this->ticket->forceFill(['status' => 'closed', 'closed_at' => now(), 'workflow_state' => 'closed'])->save();
    $version = $this->ticket->lock_version;
    $row = app(InboundEmailIngestor::class)->ingest([...$this->message,
        'subject' => 'Re: '.$this->ticket->reference]);
    expect($row->status)->toBe('quarantined')->and($row->quarantine_reason)->toBe('settled_reference_requires_related_request')
        ->and($row->body_preview)->toBeNull()->and($this->ticket->comments()->count())->toBe(0)
        ->and($this->ticket->fresh()->status)->toBe('closed')->and($this->ticket->fresh()->lock_version)->toBe($version)
        ->and(ItTicketCommandReceipt::count())->toBe(0)->and(ItEmailDelivery::count())->toBe(0);
});

test('eligible settled email reuses the canonical reopen rule and records full reply once', function (string $side, int $days) {
    $actor = $side === 'requester' ? $this->sender : $this->agent;
    $this->ticket->forceFill(['status' => 'resolved', 'workflow_state' => 'resolved',
        'resolved_at' => now()->subDays($days)])->save();
    $message = [...$this->message, 'from' => $actor->email, 'subject' => 'Re: '.$this->ticket->reference];
    $ingestor = app(InboundEmailIngestor::class);
    $row = $ingestor->ingest($message);
    $ticket = $this->ticket->fresh();
    $comments = $ticket->comments()->orderBy('id')->get();
    expect($row->status)->toBe('processed')->and($ticket->status)->toBe('open')
        ->and($ticket->reopened_count)->toBe(1)->and($comments)->toHaveCount(2)
        ->and($comments[0]->source_channel)->toBe('email')->and($comments[1]->source_channel)->toBe('email')
        ->and($comments[0]->is_internal)->toBe($side !== 'requester')
        ->and($comments[1]->is_internal)->toBeFalse()->and($comments[1]->body)->toBe($message['text'])
        ->and(AuditLog::where('action', 'it.ticket.reopened')->count())->toBe(1)
        ->and($ingestor->ingest($message)->id)->toBe($row->id)
        ->and($ticket->fresh()->reopened_count)->toBe(1)->and($ticket->comments()->count())->toBe(2);
})->with(['requester inside window' => ['requester', 2], 'responsible staff after window' => ['agent', 30]]);

test('a requester outside the existing reopen window cannot reopen by email', function () {
    $this->ticket->forceFill(['status' => 'resolved', 'workflow_state' => 'resolved',
        'resolved_at' => now()->subDays(8)])->save();
    $row = app(InboundEmailIngestor::class)->ingest([...$this->message, 'subject' => 'Re: '.$this->ticket->reference]);
    expect($row->quarantine_reason)->toBe('settled_reference_requires_related_request')
        ->and($this->ticket->fresh()->status)->toBe('resolved')->and($this->ticket->comments()->count())->toBe(0);
});

test('the full accepted byte boundary persists in canonical intake and replies', function (string $body) {
    $ingestor = app(InboundEmailIngestor::class);
    $initial = $ingestor->ingest([...$this->message, 'text' => $body]);
    expect(strlen($body))->toBe(100000)->and($initial->ticket->description)->toBe($body);
    $reply = $ingestor->ingest([...$this->message, 'message_id' => '<large-reply@example.test>',
        'text' => $body, 'subject' => 'Re: '.$initial->ticket->reference]);
    expect($reply->status)->toBe('processed')->and($initial->ticket->comments()->sole()->body)->toBe($body);
    $migration = require database_path('migrations/2026_09_11_000018_expand_it_email_content_capacity.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retain full ticket and conversation content');
    expect($initial->ticket->fresh()->description)->toBe($body)
        ->and($initial->ticket->comments()->sole()->body)->toBe($body);
})->with(['ASCII byte boundary' => [str_repeat('a', 100000)], 'UTF-8 byte boundary' => [str_repeat('€', 33333).'x']]);

test('email and browser command identities cannot replay or recover each others receipts', function () {
    $uuid = (string) Str::uuid();
    $input = ['request_uuid' => $uuid, 'title' => 'Channel-specific synthetic intake',
        'category' => 'other', 'priority' => 'normal', 'site_id' => $this->site->id];
    $intake = app(ItTicketIntakeService::class);
    $email = $intake->createCommand($this->sender, $input, channel: ItTicketCommandChannel::Email);
    expect(fn () => $intake->recoverCommand($this->sender, $uuid))->toThrow(ModelNotFoundException::class);
    $browser = $intake->createCommand($this->sender, [...$input, 'channel' => 'email', 'source' => 'email']);
    expect($browser->ticket->source)->toBe('portal')->and($browser->ticket->id)->not->toBe($email->ticket->id)
        ->and($intake->recoverCommand($this->sender, $uuid)->ticket->id)->toBe($browser->ticket->id)
        ->and($intake->recoverCommand($this->sender, $uuid, ItTicketCommandChannel::Email)->ticket->id)->toBe($email->ticket->id);
});

test('only a trusted email command accepts bounded longer public replies and browser recovery stays separate', function () {
    $commands = app(ItTicketInteractionService::class);
    $input = ['request_uuid' => (string) Str::uuid(), 'actor_user_id' => $this->sender->id,
        'expected_version' => $this->ticket->lock_version, 'body' => str_repeat('x', 6000),
        'is_internal' => false, 'channel' => 'email'];
    expect(fn () => $commands->addCommentCommand($this->ticket, $this->sender, $input))->toThrow(ValidationException::class);
    $result = $commands->addCommentCommand($this->ticket, $this->sender, $input, channel: ItTicketCommandChannel::Email);
    expect($result->comment->source_channel)->toBe('email')->and(strlen($result->comment->body))->toBe(6000);
    expect(fn () => $commands->recoverCommentCommand($this->ticket, $this->sender, $input['request_uuid']))
        ->toThrow(ModelNotFoundException::class);
    expect($commands->recoverCommentCommand($this->ticket, $this->sender, $input['request_uuid'], ItTicketCommandChannel::Email)->comment->id)
        ->toBe($result->comment->id);
    expect(fn () => $commands->addCommentCommand($this->ticket, $this->agent,
        [...$input, 'actor_user_id' => $this->agent->id, 'is_internal' => true], channel: ItTicketCommandChannel::Email))
        ->toThrow(ValidationException::class);
});

test('a canonical reply audit failure rolls back inbound identity conversation version and delivery then retries once', function () {
    $failAudit = true;
    AuditLog::creating(function (AuditLog $audit) use (&$failAudit): void {
        if ($failAudit && $audit->action === 'it.ticket.comment.added') {
            throw new RuntimeException('Synthetic audit unavailable');
        }
    });
    $message = [...$this->message, 'subject' => 'Re: '.$this->ticket->reference];
    $version = $this->ticket->lock_version;
    $ingestor = app(InboundEmailIngestor::class);
    expect(fn () => $ingestor->ingest($message))->toThrow(RuntimeException::class, 'Synthetic audit unavailable');
    expect(ItInboundEmail::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)
        ->and($this->ticket->comments()->count())->toBe(0)->and($this->ticket->fresh()->lock_version)->toBe($version)
        ->and(ItEmailDelivery::count())->toBe(0);
    $failAudit = false;
    expect($ingestor->ingest($message)->status)->toBe('processed')
        ->and($this->ticket->comments()->count())->toBe(1)
        ->and(ItTicketCommandReceipt::count())->toBe(1);
});

test('outer rollback removes the canonical intake receipt notification intent and inbound identity together', function () {
    $ingestor = app(InboundEmailIngestor::class);
    expect(fn () => DB::transaction(function () use ($ingestor) {
        $ingestor->ingest($this->message);
        throw new RuntimeException('Synthetic outer rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic outer rollback');
    expect(ItInboundEmail::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)
        ->and(ItEmailDelivery::count())->toBe(0)->and(ItTicket::count())->toBe(1);
    expect($ingestor->ingest($this->message)->status)->toBe('processed')
        ->and(ItTicketCommandReceipt::count())->toBe(1)->and(ItEmailDelivery::count())->toBe(1);
});

test('a nested command acknowledgement failure leaves reconciliation to the inbound transaction owner', function () {
    $parentLevel = DB::transactionLevel();
    $failAcknowledgement = true;
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($parentLevel, &$failAcknowledgement): void {
        if ($failAcknowledgement && $event->connection->transactionLevel() === $parentLevel + 2
            && ItTicketCommandReceipt::where('channel', 'email')->whereNotNull('committed_at')->exists()) {
            $failAcknowledgement = false;
            throw new RuntimeException('Synthetic nested acknowledgement failed');
        }
    });
    $ingestor = app(InboundEmailIngestor::class);
    expect(fn () => $ingestor->ingest($this->message))->toThrow(RuntimeException::class, 'Synthetic nested acknowledgement failed');
    expect($failAcknowledgement)->toBeFalse()->and(DB::transactionLevel())->toBe($parentLevel)
        ->and(ItInboundEmail::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)
        ->and(ItEmailDelivery::count())->toBe(0)->and(ItTicket::count())->toBe(1);
    expect($ingestor->ingest($this->message)->status)->toBe('processed')
        ->and(ItTicketCommandReceipt::count())->toBe(1)->and(ItEmailDelivery::count())->toBe(1);
});
