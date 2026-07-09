<?php

use App\Models\ItInboundEmail;
use App\Models\ItTicket;

/*
 * §P-S4 (S11) — the email-in ingestion log + `email` source. The webhook,
 * parser and reply-threading land in S12.
 */

test('email is a recognised ticket source', function () {
    expect(ItTicket::SOURCES)->toContain('email');
});

test('an inbound email row records its ticket link', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'source' => 'email']);

    $inbound = ItInboundEmail::create([
        'tenant_id' => 1,
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
        'tenant_id' => 1,
        'it_ticket_id' => null,
        'from_email' => 'stranger@example.test',
        'subject' => 'Random',
        'status' => 'unmatched',
        'received_at' => now(),
    ]);

    expect($inbound->it_ticket_id)->toBeNull();
    expect($inbound->ticket)->toBeNull();
});
