<?php

use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItEmailMessageIdentifiers;
use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->sender = User::factory()->create(['email' => 'identity-sender@example.test']);
    ensureCanonicalHrStaffProfile($this->sender);
    $permission = Permission::query()->firstOrCreate(['key' => 'it.request'], [
        'description' => 'Create IT requests', 'group' => 'it', 'module' => 'Operations',
    ]);
    $role = Role::query()->create(['name' => 'identity-requester', 'label' => 'Identity requester', 'level' => 10, 'type' => 'custom']);
    $role->permissions()->attach($permission);
    $this->sender->roles()->attach($role);
    $this->message = ['from' => $this->sender->email, 'subject' => 'Synthetic identity request',
        'text' => 'Synthetic message body', 'message_id' => '<identity-a@Mail.test>'];
});

test('equivalent quoted identity replays one canonical row while preserving full encrypted headers', function () {
    $ingestor = app(InboundEmailIngestor::class);
    $first = $ingestor->ingest($this->message);
    $second = $ingestor->ingest([...$this->message, 'message_id' => '(outer) <"identity-a"@Mail.test>']);
    expect($second->id)->toBe($first->id)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and(ItInboundEmail::query()->count())->toBe(1)
        ->and($first->normalized_message_id)->toBe('<identity-a@Mail.test>')
        ->and($first->identity_claim_key)->toBe(hash('sha256', '<identity-a@Mail.test>'))
        ->and(DB::table('it_inbound_emails')->value('normalized_message_id'))->not->toContain('identity-a')
        ->and($first->toArray())->not->toHaveKeys(['normalized_message_id', 'identity_claim_key', 'message_content_hash']);
});

test('opaque case and differences after the old column limit cannot collapse separate messages', function () {
    $ingestor = app(InboundEmailIngestor::class);
    $ids = ['<Identity-a@Mail.test>', '<identity-a@Mail.test>',
        '<'.str_repeat('a', 300).'x@Mail.test>', '<'.str_repeat('a', 300).'y@Mail.test>'];
    foreach ($ids as $id) {
        $row = $ingestor->ingest([...$this->message, 'message_id' => $id]);
        expect($row->status)->toBe('processed')->and($row->normalized_message_id)->toBe($id);
        if (strlen($id) > 255) {
            expect($row->message_id)->toBeNull();
        }
    }
    expect(ItTicket::query()->count())->toBe(4);
});

test('a changed subject threads through parent and References evidence', function () {
    $ingestor = app(InboundEmailIngestor::class);
    $first = $ingestor->ingest($this->message);
    $reply = $ingestor->ingest([...$this->message, 'subject' => 'An entirely different subject',
        'message_id' => '<reply@Mail.test>', 'in_reply_to' => '<"identity-a"@Mail.test>',
        'references' => '<unknown-ancestor@external.test> <identity-a@Mail.test>', 'text' => 'A threaded reply']);
    expect($reply->status)->toBe('processed')->and($reply->it_ticket_id)->toBe($first->it_ticket_id)
        ->and($reply->parent_message_ids)->toBe(['<identity-a@Mail.test>'])
        ->and($reply->reference_message_ids)->toBe(['<unknown-ancestor@external.test>', '<identity-a@Mail.test>'])
        ->and($first->ticket->comments()->where('body', 'A threaded reply')->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe(1);
});

test('conflicting subject and header references quarantine without choosing a ticket', function () {
    $ingestor = app(InboundEmailIngestor::class);
    $first = $ingestor->ingest($this->message);
    $other = $ingestor->ingest([...$this->message, 'message_id' => '<other@Mail.test>']);
    $reply = $ingestor->ingest([...$this->message, 'message_id' => '<conflict@Mail.test>',
        'subject' => 'Re: '.$other->ticket->reference, 'in_reply_to' => $first->normalized_message_id]);
    expect($reply->status)->toBe('quarantined')->and($reply->quarantine_reason)->toBe('reference_ambiguous')
        ->and($reply->it_ticket_id)->toBeNull()->and($reply->body_preview)->toBeNull()
        ->and($first->ticket->comments()->count())->toBe(0)->and($other->ticket->comments()->count())->toBe(0);
});

test('a reply with only unknown ancestry is quarantined rather than silently opening another ticket', function () {
    $row = app(InboundEmailIngestor::class)->ingest([...$this->message, 'references' => '<unknown@Mail.test>']);
    expect($row->quarantine_reason)->toBe('reference_not_found')->and(ItTicket::query()->count())->toBe(0);
});

test('changed content or sender cannot reuse an existing message identity or disclose its ticket', function () {
    $ingestor = app(InboundEmailIngestor::class);
    $first = $ingestor->ingest($this->message);
    foreach ([['text' => 'A conflicting body'], ['from' => 'unapproved@example.test']] as $change) {
        $collision = $ingestor->ingest([...$this->message, ...$change]);
        expect($collision->status)->toBe('quarantined')->and($collision->quarantine_reason)->toBe('message_id_collision')
            ->and($collision->it_ticket_id)->toBeNull()->and($collision->identity_claim_key)->toBeNull();
    }
    $reply = $ingestor->ingest([...$this->message, 'message_id' => '<after-collision@Mail.test>',
        'in_reply_to' => $first->normalized_message_id]);
    expect($reply->quarantine_reason)->toBe('reference_ambiguous')->and(ItTicket::query()->count())->toBe(1);
});

test('duplicate delivery rechecks current sender access before returning a ticket identity', function () {
    $ingestor = app(InboundEmailIngestor::class);
    $ingestor->ingest($this->message);
    $this->sender->forceFill(['approved_at' => null])->save();
    $replay = $ingestor->ingest($this->message);
    expect($replay->status)->toBe('quarantined')->and($replay->quarantine_reason)->toBe('sender_unauthorized')
        ->and($replay->it_ticket_id)->toBeNull()->and(ItTicket::query()->count())->toBe(1);
});

test('separate provider receipts link to the original without creating another identity owner', function () {
    $ingestor = app(InboundEmailIngestor::class);
    $first = $ingestor->ingest($this->message);
    for ($index = 0; $index < 3; $index++) {
        $receipt = new ItInboundEmail;
        $receipt->forceFill(['from_email' => '', 'status' => 'pending', 'transport_key' => hash('sha256', 'transport-'.$index)])->save();
        $duplicate = $ingestor->ingest($this->message, $receipt);
        expect($duplicate->id)->toBe($receipt->id)->and($duplicate->status)->toBe('duplicate')
            ->and($duplicate->duplicate_of_id)->toBe($first->id)->and($duplicate->identity_claim_key)->toBeNull();
    }
    expect($ingestor->ingest($this->message)->id)->toBe($first->id)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and(ItInboundEmail::query()->whereNotNull('identity_claim_key')->count())->toBe(1);
});

test('legacy identity evidence is retained and cannot be mistaken for a full-message fingerprint', function () {
    $ticket = ItTicket::factory()->create(['requester_user_id' => $this->sender->id]);
    $legacy = new ItInboundEmail;
    $legacy->forceFill(['from_email' => $this->sender->email, 'message_id' => $this->message['message_id'],
        'message_identity_hash' => (new ItEmailMessageIdentifiers)->hash($this->message['message_id']),
        'it_ticket_id' => $ticket->id, 'status' => 'processed', 'body_preview' => 'Original legacy preview'])->save();
    $row = app(InboundEmailIngestor::class)->ingest($this->message);
    expect($row->quarantine_reason)->toBe('legacy_message_identity_unverified')
        ->and($legacy->fresh()->body_preview)->toBe('Original legacy preview')
        ->and($legacy->fresh()->it_ticket_id)->toBe($ticket->id)
        ->and(ItTicket::query()->count())->toBe(1);
});

test('malformed identification headers are quarantined without body content or truncated keys', function () {
    $row = app(InboundEmailIngestor::class)->ingest([...$this->message,
        'message_id' => '<one@Mail.test> <two@Mail.test>', 'text' => 'PRIVATE BODY']);
    expect($row->status)->toBe('quarantined')->and($row->quarantine_reason)->toBe('invalid_message_headers')
        ->and($row->body_preview)->toBeNull()->and($row->message_id)->toBeNull()
        ->and($row->identity_claim_key)->toBeNull()->and(ItTicket::query()->count())->toBe(0);
});

test('identity and canonical work roll back together and can be retried', function () {
    $ingestor = app(InboundEmailIngestor::class);
    try {
        DB::transaction(function () use ($ingestor) {
            $ingestor->ingest($this->message);
            throw new RuntimeException('Synthetic rollback');
        });
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toBe('Synthetic rollback');
    }
    expect(ItInboundEmail::query()->count())->toBe(0)->and(ItTicket::query()->count())->toBe(0);
    expect($ingestor->ingest($this->message)->status)->toBe('processed')
        ->and(ItInboundEmail::query()->count())->toBe(1)->and(ItTicket::query()->count())->toBe(1);
});

test('content fingerprint includes subject differences beyond the display column', function () {
    $ingestor = app(InboundEmailIngestor::class);
    $prefix = str_repeat('s', 300);
    $first = $ingestor->ingest([...$this->message, 'subject' => $prefix.' first']);
    $conflicting = $ingestor->ingest([...$this->message, 'subject' => $prefix.' different']);
    expect($first->status)->toBe('processed')->and(strlen($first->subject))->toBe(255)
        ->and($conflicting->status)->toBe('quarantined')
        ->and($conflicting->quarantine_reason)->toBe('message_id_collision')
        ->and(ItTicket::count())->toBe(1);
});

test('invalid provider field types and encoding are quarantined without casting or persisting private text', function (array $invalid) {
    $row = app(InboundEmailIngestor::class)->ingest([...$this->message, ...$invalid]);
    expect($row->status)->toBe('quarantined')->and($row->quarantine_reason)->toBe('invalid_message_fields')
        ->and($row->body_preview)->toBeNull()->and($row->identity_claim_key)->toBeNull()
        ->and(ItTicket::count())->toBe(0);
})->with([
    'sender array' => [['from' => ['private@example.test']]],
    'subject array' => [['subject' => ['PRIVATE SUBJECT']]],
    'body array' => [['text' => ['PRIVATE BODY']]],
    'sender encoding' => [['from' => "invalid\xff@example.test"]],
    'subject encoding' => [['subject' => "PRIVATE\xff"]],
    'body encoding' => [['text' => "PRIVATE\xff"]],
]);

test('oversized provider fields quarantine before identity reservation', function (array $invalid, string $reason) {
    $row = app(InboundEmailIngestor::class)->ingest([...$this->message, ...$invalid]);
    expect($row->quarantine_reason)->toBe($reason)->and($row->status)->toBe('quarantined')
        ->and($row->body_preview)->toBeNull()->and($row->identity_claim_key)->toBeNull()
        ->and(ItTicket::count())->toBe(0);
})->with([
    'sender bound' => [['from' => str_repeat('a', 300).'@example.test'], 'invalid_message_fields'],
    'subject bound' => [['subject' => str_repeat('s', 16385)], 'message_headers_too_large'],
    'body bound' => [['text' => str_repeat('b', 100001)], 'message_body_too_large'],
]);
