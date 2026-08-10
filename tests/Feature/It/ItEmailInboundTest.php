<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\InboundEmailIngestor;
use App\Http\Controllers\It\ItInboundEmailController;
use App\Http\Requests\It\IngestInboundEmailRequest;
use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

/*
 * §P-S4 (S11) — the email-in ingestion log + `email` source. The webhook,
 * parser and reply-threading land in S12.
 */

function itInboundRequestSender(string $email): User
{
    $site = Site::factory()->create();
    $sender = User::factory()->create(['email' => $email]);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'it.request'],
        ['description' => 'Create IT requests', 'group' => 'it', 'module' => 'Operations'],
    );
    $role = Role::query()->create([
        'name' => 'inbound-requester-'.str()->uuid(),
        'label' => 'Inbound requester',
        'level' => 10,
        'type' => 'custom',
    ]);
    $role->permissions()->attach($permission);
    $sender->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $sender->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'created_by' => $sender->id,
        'updated_by' => $sender->id,
    ]);

    return $sender;
}

test('email is a recognised ticket source', function () {
    expect(ItTicket::SOURCES)->toContain('email');
});

test('an inbound email row records its ticket link', function () {
    $ticket = ItTicket::factory()->create(['source' => 'email']);

    $inbound = ItInboundEmail::create([
        'it_ticket_id' => $ticket->id,
        'from_email' => 'requester@example.test',
        'subject' => 'The printer is jammed',
        'message_id' => '<abc123@mail.example.test>',
        'body_preview' => 'It has been stuck since this morning.',
        'status' => 'processed',
        'received_at' => now(),
    ]);

    expect($inbound->ticket->is($ticket))->toBeTrue();
    expect($ticket->fresh()->source)->toBe('email');
    expect($inbound->status)->toBe('processed');
});

test('an unmatched inbound email can be logged without a ticket', function () {
    $inbound = ItInboundEmail::create([
        'it_ticket_id' => null,
        'from_email' => 'stranger@example.test',
        'subject' => 'Random',
        'status' => 'unmatched',
        'received_at' => now(),
    ]);

    expect($inbound->it_ticket_id)->toBeNull();
    expect($inbound->ticket)->toBeNull();
});

/* ------------------------------------------------------------------ */
/*  §P-S4 (S12) — the inbound webhook */
/* ------------------------------------------------------------------ */

test('the inbound webhook uses its dedicated form request', function () {
    $parameter = (new ReflectionMethod(ItInboundEmailController::class, '__invoke'))
        ->getParameters()[0];

    expect($parameter->getType()?->getName())->toBe(IngestInboundEmailRequest::class);
});

test('the webhook rejects a missing or wrong shared secret', function () {
    config(['it.inbound_mail.secret' => 'top-secret']);

    $this->postJson('/api/it/email/inbound', ['from' => 'x@example.test'])
        ->assertForbidden();

    $this->postJson('/api/it/email/inbound', ['from' => 'x@example.test'], ['X-IT-Inbound-Secret' => 'nope'])
        ->assertForbidden();

    $this->postJson('/api/it/email/inbound', ['from' => 'not-an-email'], ['X-IT-Inbound-Secret' => 'top-secret'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('from');
});

test('the webhook is inert when no secret is configured', function () {
    config(['it.inbound_mail.secret' => null]);

    $this->postJson('/api/it/email/inbound', ['from' => 'x@example.test'], ['X-IT-Inbound-Secret' => 'anything'])
        ->assertForbidden();
});

test('a known sender opens a new ticket by email', function () {
    config(['it.inbound_mail.secret' => 'top-secret']);
    $sender = itInboundRequestSender('worker@example.test');

    $this->postJson('/api/it/email/inbound', [
        'from' => 'worker@example.test',
        'subject' => 'Printer jammed',
        'text' => 'It has been stuck all morning.',
        'message_id' => '<a@mail>',
    ], ['X-IT-Inbound-Secret' => 'top-secret'])->assertOk();

    $ticket = ItTicket::query()->where('requester_user_id', $sender->id)->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->source)->toBe('email');
    expect($ticket->title)->toBe('Printer jammed');
    expect(ItInboundEmail::query()->where('it_ticket_id', $ticket->id)->where('status', 'processed')->exists())->toBeTrue();
});

test('a reply carrying a ticket reference threads onto that ticket', function () {
    config(['it.inbound_mail.secret' => 'top-secret']);
    $sender = User::factory()->create(['email' => 'worker@example.test']);
    $ticket = ItTicket::factory()->create(['requester_user_id' => $sender->id]);

    $this->postJson('/api/it/email/inbound', [
        'from' => 'worker@example.test',
        'subject' => "Re: {$ticket->reference} still broken",
        'text' => 'Any update?',
        'message_id' => '<reply-a@mail>',
    ], ['X-IT-Inbound-Secret' => 'top-secret'])->assertOk();

    expect($ticket->comments()->where('body', 'Any update?')->exists())->toBeTrue();
    // No NEW ticket spawned for this sender.
    expect(ItTicket::query()->where('requester_user_id', $sender->id)->count())->toBe(1);
});

test('the ingestor can be driven directly — the poller contract (E1)', function () {
    $sender = User::factory()->create(['email' => 'worker@example.test']);
    $ticket = ItTicket::factory()->create(['requester_user_id' => $sender->id]);

    $inbound = app(InboundEmailIngestor::class)->ingest([
        'from' => 'worker@example.test',
        'subject' => "Re: {$ticket->reference}",
        'text' => 'Direct call, no HTTP.',
        'message_id' => '<poller-a@mail>',
    ]);

    expect($inbound->status)->toBe('processed');
    expect($inbound->it_ticket_id)->toBe($ticket->id);
    expect($ticket->comments()->where('body', 'Direct call, no HTTP.')->exists())->toBeTrue();
});

test('an unknown sender is quarantined and never auto-ticketed', function () {
    config(['it.inbound_mail.secret' => 'top-secret']);

    $this->postJson('/api/it/email/inbound', [
        'from' => 'stranger@example.test',
        'subject' => 'Help',
        'text' => 'I am not staff.',
        'message_id' => '<unknown-a@mail>',
    ], ['X-IT-Inbound-Secret' => 'top-secret'])->assertOk();

    $inbound = ItInboundEmail::query()->where('from_email', 'stranger@example.test')->firstOrFail();
    expect($inbound->status)->toBe('quarantined')
        ->and($inbound->quarantine_reason)->toBe('sender_unknown')
        ->and($inbound->subject)->toBeNull()
        ->and($inbound->body_preview)->toBeNull();
    expect(ItTicket::query()->count())->toBe(0);
});
