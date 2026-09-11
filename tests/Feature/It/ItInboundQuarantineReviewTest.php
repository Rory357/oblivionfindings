<?php

use App\Domain\It\InboundEmailIngestor;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    config(['inertia.ssr.enabled' => false]);
    $this->seed(RbacSeeder::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->sender = User::factory()->create(['role' => 'support_worker', 'approved_at' => null]);
    foreach ([$this->admin, $this->sender] as $user) {
        $user->roles()->attach(Role::where('name', $user->role)->firstOrFail());
    }
    ensureCanonicalHrStaffProfile($this->sender);
    $this->sender->forceFill(['approved_at' => null])->save();
    $this->connection = ItMailboxConnection::create(['provider' => 'microsoft', 'status' => 'connected',
        'account_email' => 'support@example.test', 'access_token' => 'synthetic-access', 'token_expires_at' => now()->addDay()]);
    $this->message = ['from' => $this->sender->email, 'subject' => 'PRIVATE synthetic subject',
        'text' => 'PRIVATE synthetic body', 'message_id' => '<quarantine-review@example.test>'];
    $receipt = new ItInboundEmail;
    $receipt->forceFill(['status' => 'pending', 'from_email' => '', 'remote_message_id' => 'PRIVATE-provider-id',
        'it_mailbox_connection_id' => $this->connection->id, 'mailbox_scope_hash' => $this->connection->mailboxScopeHash(),
        'transport_key' => hash('sha256', 'synthetic-quarantine-transport')])->save();
    $this->receipt = app(InboundEmailIngestor::class)->ingest($this->message, $receipt);
    $this->receipt->forceFill(['acknowledged_at' => now()])->save();
    $this->input = ['connection_id' => $this->connection->id, 'expected_version' => $this->connection->configuration_version, 'expected_review_version' => 0];
    $this->url = '/settings/it-mailbox/quarantine/microsoft';
    $this->retryUrl = $this->url.'/'.$this->receipt->id.'/retry';
});

test('quarantine listing is bounded metadata only and denies existing restricted roles', function () {
    $this->actingAs($this->admin)->getJson($this->url.'?'.http_build_query($this->input))
        ->assertOk()->assertJsonPath('records.0.can_retry', true)
        ->assertJsonPath('records.0.reason', 'Sender is not active or approved')
        ->assertDontSee('PRIVATE')->assertDontSee($this->sender->email)
        ->assertJsonCount(1, 'records')
        ->assertJsonMissingPath('records.0.it_ticket_id')->assertJsonMissingPath('records.0.attachments');
    $this->sender->forceFill(['approved_at' => now()])->save();
    $this->actingAs($this->sender)->getJson($this->url.'?'.http_build_query($this->input))->assertForbidden();
    $this->postJson($this->retryUrl, $this->input)->assertForbidden();
    expect($this->receipt->fresh()->status)->toBe('quarantined');
});

test('a retry rechecks current sender access and creates the canonical request only once', function () {
    $originalHash = $this->receipt->message_content_hash;
    $originalReceived = $this->receipt->received_at->toIso8601String();
    $this->actingAs($this->admin)->postJson($this->retryUrl, $this->input)->assertOk()
        ->assertJsonPath('status', 'requested')->assertJsonPath('record.version', 1)->assertJsonPath('record.can_retry', false);
    $this->postJson($this->retryUrl, $this->input)->assertConflict();
    expect(ItTicket::count())->toBe(0)->and($this->receipt->fresh()->acknowledged_at)->toBeNull();
    $this->travel(5)->minutes();
    $stillDenied = app(InboundEmailIngestor::class)->ingest($this->message, $this->receipt->fresh());
    expect($stillDenied->status)->toBe('quarantined')->and($stillDenied->quarantine_reason)->toBe('sender_inactive')
        ->and($stillDenied->quarantine_review_version)->toBe(2)->and($stillDenied->message_content_hash)->toBe($originalHash)
        ->and($stillDenied->received_at->toIso8601String())->toBe($originalReceived)->and(ItTicket::count())->toBe(0);
    $stillDenied->forceFill(['acknowledged_at' => now()])->save();
    $this->sender->forceFill(['approved_at' => now()])->save();
    $this->postJson($this->retryUrl, [...$this->input, 'expected_review_version' => 2])->assertOk();
    $result = app(InboundEmailIngestor::class)->ingest($this->message, $stillDenied->fresh());
    expect($result->status)->toBe('processed')->and($result->quarantine_review_version)->toBe(4)
        ->and($result->quarantine_retry_requested_at)->toBeNull()->and(ItTicket::count())->toBe(1)
        ->and(ItTicketCommandReceipt::where('channel', 'email')->where('operation', 'ticket.create')->count())->toBe(1)
        ->and(app(InboundEmailIngestor::class)->ingest($this->message)->id)->toBe($result->id)
        ->and(AuditLog::where('action', 'settings.it_mailbox.quarantine_retry_requested')->count())->toBe(2)
        ->and(AuditLog::where('action', 'it.inbound_email.quarantine_retry_completed')->count())->toBe(2);
});

test('changed provider content cannot turn a quarantine retry into a different message', function (array $changes, string $reason) {
    $identity = $this->receipt->message_identity_hash;
    $content = $this->receipt->message_content_hash;
    $this->actingAs($this->admin)->postJson($this->retryUrl, $this->input)->assertOk();
    $this->sender->forceFill(['approved_at' => now()])->save();
    $result = app(InboundEmailIngestor::class)->ingest([...$this->message, ...$changes], $this->receipt->fresh());
    expect($result->status)->toBe('quarantined')->and($result->quarantine_reason)->toBe($reason)
        ->and($result->message_identity_hash)->toBe($identity)->and($result->message_content_hash)->toBe($content)
        ->and($result->quarantine_review_version)->toBe(2)->and($result->body_preview)->toBeNull()->and(ItTicket::count())->toBe(0);
    $result->forceFill(['acknowledged_at' => now()])->save();
    $this->postJson($this->retryUrl, [...$this->input, 'expected_review_version' => 2])->assertConflict();
})->with([
    [['text' => 'Altered content'], 'retry_message_changed'],
    [['message_id' => '<replacement@example.test>'], 'retry_identity_changed'],
    [['message_id' => null], 'retry_identity_changed'],
    [['auto_submitted' => 'auto-replied'], 'retry_message_invalid'],
]);

test('busy cooldown changed mailbox and missing acknowledgement deny retry without losing evidence', function (array $changes, bool $receiptChange) {
    ($receiptChange ? $this->receipt : $this->connection)->forceFill($changes)->save();
    $this->actingAs($this->admin)->postJson($this->retryUrl, $this->input)->assertConflict();
    expect($this->receipt->fresh()->status)->toBe('quarantined')
        ->and(AuditLog::where('action', 'settings.it_mailbox.quarantine_retry_requested')->count())->toBe(0);
})->with([
    [['poll_claim_token' => 'synthetic-owner', 'poll_claim_expires_at' => now()->addHour()], false],
    [['next_poll_at' => now()->addHour()], false],
    [['configuration_version' => 2], false],
    [['acknowledged_at' => null], true],
    [['quarantine_reason' => 'message_id_collision'], true],
    [['message_content_hash' => null], true],
]);

test('a different mailbox receipt is concealed and unsafe files cannot be manually released', function () {
    $this->receipt->forceFill(['mailbox_scope_hash' => hash('sha256', 'other-mailbox')])->save();
    $this->actingAs($this->admin)->postJson($this->retryUrl, $this->input)->assertNotFound();
    $this->receipt->forceFill(['mailbox_scope_hash' => $this->connection->mailboxScopeHash()])->save();
    $file = new ItAttachment;
    $file->forceFill(['attachable_type' => $this->receipt->getMorphClass(), 'attachable_id' => $this->receipt->id,
        'path' => 'PRIVATE-fixture-path', 'original_name' => 'PRIVATE-file.txt', 'size' => 1, 'mime' => 'text/plain',
        'inbound_storage_state' => 'rejected', 'malware_scan_status' => 'infected'])->save();
    $this->postJson($this->retryUrl, $this->input)->assertConflict();
    $this->getJson($this->url.'?'.http_build_query($this->input))->assertOk()->assertJsonPath('records.0.can_retry', false)->assertDontSee('PRIVATE');
    $this->get('/it/attachments/'.$file->id)->assertNotFound();
});

test('required audit failure rolls back the request and the canonical retry outcome', function () {
    $failAction = 'settings.it_mailbox.quarantine_retry_requested';
    AuditLog::creating(function ($log) use (&$failAction) {
        if ($log->action === $failAction) {
            throw new RuntimeException('Synthetic audit failure');
        }
    });
    $this->actingAs($this->admin)->postJson($this->retryUrl, $this->input)->assertStatus(500);
    expect($this->receipt->fresh()->quarantine_review_version)->toBe(0)->and($this->receipt->fresh()->status)->toBe('quarantined');
    $failAction = null;
    $this->postJson($this->retryUrl, $this->input)->assertOk();
    $this->sender->forceFill(['approved_at' => now()])->save();
    $failAction = 'it.inbound_email.quarantine_retry_completed';
    expect(fn () => app(InboundEmailIngestor::class)->ingest($this->message, $this->receipt->fresh()))->toThrow(RuntimeException::class);
    expect(ItTicket::count())->toBe(0)->and($this->receipt->fresh()->status)->toBe('pending')
        ->and($this->receipt->fresh()->quarantine_review_version)->toBe(1);
    $failAction = null;
    expect(app(InboundEmailIngestor::class)->ingest($this->message, $this->receipt->fresh())->status)->toBe('processed');
});

test('review pages use a bounded stable cursor and do not include another mailbox', function () {
    for ($number = 0; $number < 26; $number++) {
        $row = new ItInboundEmail;
        $row->forceFill(['status' => 'quarantined', 'from_email' => '', 'quarantine_reason' => 'sender_unknown',
            'mailbox_scope_hash' => $this->connection->mailboxScopeHash()])->save();
    }
    $first = $this->actingAs($this->admin)->getJson($this->url.'?'.http_build_query($this->input))->assertOk()->assertJsonCount(25, 'records')->json();
    $second = $this->getJson($this->url.'?'.http_build_query([...$this->input, 'before_id' => $first['next_before_id']]))
        ->assertOk()->assertJsonCount(2, 'records')->assertJsonPath('next_before_id', null)->json();
    expect(array_intersect(array_column($first['records'], 'id'), array_column($second['records'], 'id')))->toBe([]);
});
