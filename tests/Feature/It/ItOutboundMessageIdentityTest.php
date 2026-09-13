<?php

use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItEmailMessageIdentifiers;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketMergeService;
use App\Jobs\PollItMailboxJob;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->sender = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->sender->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->sender, $this->site);
    $this->ticket = app(ItTicketIntakeService::class)->createCommand($this->sender, [
        'request_uuid' => (string) Str::uuid(), 'title' => 'Synthetic outgoing identity', 'category' => 'other', 'priority' => 'normal',
    ])->ticket;
    app(ItEmailDeliveryService::class)->dispatchPending();
    $this->delivery = ItEmailDelivery::where('it_ticket_id', $this->ticket->id)->where('recipient_user_id', $this->sender->id)->sole();
});

test('submitted message identity is encrypted immutable and separate from provider evidence', function () {
    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    $id = (new ItEmailMessageIdentifiers)->messageId($message->getHeaders()->get('Message-ID')->getBodyAsString());
    expect($this->delivery->status)->toBe('accepted')->and($this->delivery->rfc_message_id)->toBe($id)
        ->and($this->delivery->provider_message_id)->toBeNull()
        ->and($this->delivery->getRawOriginal('rfc_message_id'))->not->toBe($id)
        ->and($this->delivery->toArray())->not->toHaveKeys(['rfc_message_id', 'rfc_message_id_hash', 'rfc_message_id_recorded_at'])
        ->and(AuditLog::where('action', 'it.email.message_identity_recorded')->count())->toBe(1);
    $this->delivery->forceFill(['provider_message_id' => 'opaque-provider-transport-id'])->save();
    $replacement = (new Email)->from('support@example.test')->to($this->sender->email)->text('Synthetic re-preparation');
    Event::dispatch(new MessageSending($replacement, ['__laravel_notification' => TicketCreatedNotification::class,
        '__laravel_notification_id' => $this->delivery->notification_uuid]));
    expect($replacement->getHeaders()->get('Message-ID')->getBodyAsString())->toBe($id)
        ->and($this->delivery->fresh()->provider_message_id)->toBe('opaque-provider-transport-id')
        ->and(AuditLog::where('action', 'it.email.message_identity_recorded')->count())->toBe(1);
    expect(fn () => $this->delivery->forceFill(['rfc_message_id_hash' => str_repeat('0', 64)])->save())->toThrow(LogicException::class);
    $migration = require base_path('database/migrations/2026_09_11_000022_record_it_outbound_message_identity.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

test('returning our outgoing identity quarantines even if its automated marker was stripped', function () {
    $row = app(InboundEmailIngestor::class)->ingest(['from' => $this->sender->email,
        'subject' => 'Changed subject', 'text' => 'Returned synthetic notification', 'message_id' => $this->delivery->rfc_message_id]);
    expect($row->status)->toBe('quarantined')->and($row->quarantine_reason)->toBe('automatic_message')
        ->and($row->it_ticket_id)->toBeNull()->and($row->body_preview)->toBeNull()
        ->and(ItTicket::count())->toBe(1)->and(ItTicketComment::count())->toBe(0)->and(ItEmailDelivery::count())->toBe(1);
});

test('both providers thread a changed-subject reply using submitted outbound identity safely', function (string $provider, string $scenario) {
    $sender = $this->sender;
    $destination = $this->ticket;
    $parents = $this->delivery->rfc_message_id;
    if (in_array($scenario, ['merged', 'conflict'], true)) {
        $second = app(ItTicketIntakeService::class)->createCommand($sender, [
            'request_uuid' => (string) Str::uuid(), 'title' => 'Second synthetic report', 'category' => 'other', 'priority' => 'normal',
        ])->ticket;
        if ($scenario === 'merged') {
            $agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
            $agent->roles()->attach(Role::where('name', 'hr')->firstOrFail());
            ensureCanonicalHrStaffProfile($agent, $this->site);
            app(ItTicketMergeService::class)->merge($this->ticket->fresh(), $second, $agent, 'Synthetic duplicate reviewed.',
                $this->ticket->fresh()->lock_version, $second->lock_version);
            $destination = $second;
        } else {
            app(ItEmailDeliveryService::class)->dispatchPending();
            $parents .= ' '.ItEmailDelivery::where('it_ticket_id', $second->id)->sole()->rfc_message_id;
        }
    }
    if ($scenario === 'denied') {
        $sender = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $sender->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        ensureCanonicalHrStaffProfile($sender, $this->site);
    }
    if ($scenario === 'provider_id') {
        $this->delivery->forceFill(['provider_message_id' => '<opaque@example.test>'])->save();
        $parents = '<opaque@example.test>';
    }
    $connection = ItMailboxConnection::create(['provider' => $provider, 'status' => 'connected',
        'account_email' => 'inbox@example.test', 'access_token' => 'synthetic-access', 'token_expires_at' => now()->addHour()]);
    $reads = $acks = 0;
    Http::fake(function ($request) use ($provider, $sender, $parents, &$reads, &$acks) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $expectedHost = $provider === 'google' ? 'gmail.googleapis.com' : 'graph.microsoft.com';
        expect(parse_url($request->url(), PHP_URL_HOST))->toBe($expectedHost);
        if (str_ends_with($path, '/attachments')) {
            return Http::response(['value' => []]);
        }
        if (str_ends_with($path, '/messages')) {
            return Http::response($provider === 'google' ? ['messages' => [['id' => 'human-reply']]] : ['value' => [['id' => 'human-reply']]]);
        }
        if ($request->method() !== 'GET') {
            return Http::response([], ++$acks === 1 ? 503 : 200);
        }
        $reads++;
        $headers = [['name' => 'From', 'value' => $sender->email], ['name' => 'Message-ID', 'value' => '<human-reply@example.test>'],
            ['name' => 'Subject', 'value' => 'A completely changed subject'], ['name' => 'References', 'value' => $parents],
            ['name' => 'Auto-Submitted', 'value' => 'no']];

        return Http::response($provider === 'google' ? ['id' => 'human-reply', 'payload' => ['mimeType' => 'text/plain',
            'headers' => $headers, 'body' => ['data' => base64_encode('Human follow-up')]]] : ['id' => 'human-reply',
                'from' => ['emailAddress' => ['address' => $sender->email]], 'subject' => 'A completely changed subject',
                'internetMessageId' => '<human-reply@example.test>', 'internetMessageHeaders' => $headers,
                'body' => ['contentType' => 'text', 'content' => 'Human follow-up']]);
    });
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    $receipt = ItInboundEmail::where('mailbox_scope_hash', $connection->mailboxScopeHash())->sole();
    $reason = match ($scenario) {
        'denied' => 'sender_unauthorized', 'conflict' => 'reference_ambiguous', 'provider_id' => 'reference_not_found', default => null
    };
    expect($receipt->status)->toBe($reason === null ? 'processed' : 'quarantined')
        ->and($receipt->quarantine_reason)->toBe($reason)
        ->and($receipt->it_ticket_id)->toBe($reason === null ? $destination->id : null)
        ->and(ItTicketComment::count())->toBe($reason === null ? 1 : 0);
    $ticketCount = ItTicket::count();
    $deliveryCount = ItEmailDelivery::count();
    $this->travel(61)->seconds();
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($receipt->fresh()->acknowledged_at)->not->toBeNull()->and($reads)->toBe(1)->and($acks)->toBe(2)
        ->and(ItTicket::count())->toBe($ticketCount)->and(ItEmailDelivery::count())->toBe($deliveryCount)
        ->and(ItTicketComment::count())->toBe($reason === null ? 1 : 0);
})->with([
    ['google', 'normal'], ['microsoft', 'normal'], ['google', 'merged'], ['microsoft', 'merged'],
    ['google', 'denied'], ['microsoft', 'denied'], ['google', 'conflict'], ['microsoft', 'conflict'],
    ['google', 'provider_id'], ['microsoft', 'provider_id'],
]);

test('identity audit failure rolls back the reservation and prevents sending', function () {
    $second = app(ItTicketIntakeService::class)->createCommand($this->sender, [
        'request_uuid' => (string) Str::uuid(), 'title' => 'Audit failure', 'category' => 'other', 'priority' => 'normal',
    ])->ticket;
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function ($audit) {
        if ($audit->action === 'it.email.message_identity_recorded') {
            throw new RuntimeException('Synthetic audit failure');
        }
    });
    try {
        app(ItEmailDeliveryService::class)->dispatchPending();
    } finally {
        Event::forget($event);
    }
    $delivery = ItEmailDelivery::where('it_ticket_id', $second->id)->sole();
    expect($delivery->rfc_message_id)->toBeNull()->and($delivery->rfc_message_id_hash)->toBeNull()
        ->and($delivery->status)->not->toBe('accepted')
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});
