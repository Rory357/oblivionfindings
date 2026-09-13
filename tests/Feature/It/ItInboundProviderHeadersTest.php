<?php

use App\Domain\It\InboundEmailIngestor;
use App\Jobs\PollItMailboxJob;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('provider messages requiring review quarantine once and acknowledgement recovery does not reprocess', function (string $provider, string $case, string $reason) {
    Http::preventStrayRequests();
    $sender = User::factory()->create(['email' => 'header-sender@example.test']);
    ensureCanonicalHrStaffProfile($sender);
    $permission = Permission::query()->firstOrCreate(['key' => 'it.request'], [
        'description' => 'Create IT requests', 'group' => 'it', 'module' => 'Operations',
    ]);
    $role = Role::query()->create(['name' => 'header-requester', 'label' => 'Header requester', 'level' => 10, 'type' => 'custom']);
    $role->permissions()->attach($permission);
    $sender->roles()->attach($role);
    $connection = ItMailboxConnection::create([
        'provider' => $provider, 'status' => 'connected', 'account_email' => 'inbox@example.test',
        'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'token_expires_at' => now()->addHour(),
    ]);
    $reads = $acks = [];
    Http::fake(function ($request) use ($provider, $case, &$reads, &$acks) {
        $url = $request->url();
        if ($provider === 'microsoft' && preg_match('~/messages/[^/?]+/attachments(?:\?|$)~', $url)) {
            return Http::response(['value' => []]);
        }
        if ($request->method() === 'GET' && ($provider === 'google' ? str_contains($url, '/messages?') : str_contains($url, '/mailFolders/inbox/messages'))) {
            $ids = [['id' => 'ambiguous'], ['id' => 'valid']];

            return Http::response($provider === 'google' ? ['messages' => $ids] : ['value' => $ids]);
        }
        preg_match('~/messages/([^/?]+)~', $url, $matches);
        $id = $matches[1] ?? '';
        if ($request->method() !== 'GET') {
            $acks[$id] = ($acks[$id] ?? 0) + 1;

            return Http::response([], $id === 'ambiguous' && $acks[$id] === 1 ? 503 : 200);
        }
        $reads[$id] = ($reads[$id] ?? 0) + 1;
        $from = '"Worker, One" <header-sender@example.test>';
        $headers = [
            ['name' => 'From', 'value' => $from], ['name' => 'Subject', 'value' => 'Synthetic header test'],
            ['name' => 'Message-ID', 'value' => '<'.$id.'@example.test>'],
            ['name' => 'Received', 'value' => 'synthetic hop1'], ['name' => 'Received', 'value' => 'synthetic hop2'],
        ];
        if ($id === 'ambiguous') {
            if ($case === 'multiple_senders') {
                $headers[0]['value'] .= ', Other <other@example.test>';
            } elseif ($case === 'provider_sender_conflict') {
                $headers[0]['value'] = 'other@example.test';
            } elseif ($case === 'provider_identity_conflict') {
                $headers[2]['value'] = '<other@example.test>';
            } elseif ($case === 'oversized') {
                $headers[] = ['name' => 'X-Synthetic', 'value' => str_repeat('x', 16385)];
            } elseif ($case === 'automatic') {
                $headers[] = ['name' => 'Auto-Submitted', 'value' => '(automatic) auto-replied (reply)'];
            } elseif ($case === 'report') {
                $headers[] = ['name' => 'Content-Type', 'value' => '(report) multipart/report; report-type=delivery-status'];
            } elseif ($case === 'null_path') {
                $headers[] = ['name' => 'Return-Path', 'value' => '(no return address) <>'];
            } elseif (in_array($case, ['malformed_body', 'missing_body'], true)) {
                // The invalid body is supplied below; headers remain valid.
            } else {
                $headers[] = ['name' => $case, 'value' => '<first@example.test>'];
                $headers[] = ['name' => strtoupper($case), 'value' => '<second@example.test>'];
            }
        }

        $googleBody = ['data' => base64_encode('Private synthetic body')];
        $graphBody = ['contentType' => 'text', 'content' => 'Private synthetic body'];
        if ($id === 'ambiguous' && $case === 'malformed_body') {
            $googleBody = ['data' => 'invalid%%%'];
            $graphBody['content'] = ['invalid'];
        } elseif ($id === 'ambiguous' && $case === 'missing_body') {
            $googleBody = ['size' => 12];
            unset($graphBody['content']);
        }

        return Http::response($provider === 'google' ? ['id' => $id, 'snippet' => 'Not a complete body', 'payload' => [
            'headers' => $headers, 'mimeType' => 'text/plain', 'body' => $googleBody,
        ]] : [
            'id' => $id, 'from' => ['emailAddress' => ['address' => 'header-sender@example.test']],
            'subject' => 'Synthetic header test', 'internetMessageId' => '<'.$id.'@example.test>',
            'internetMessageHeaders' => $headers, 'body' => $graphBody, 'bodyPreview' => 'Not a complete body',
        ]);
    });
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    $quarantine = ItInboundEmail::query()->where('status', 'quarantined')->sole();
    expect($quarantine->quarantine_reason)->toBe($reason)
        ->and($quarantine->it_ticket_id)->toBeNull()->and($quarantine->body_preview)->toBeNull()
        ->and($quarantine->from_email)->toBe('')->and($quarantine->subject)->toBeNull()
        ->and($quarantine->message_identity_hash)->toBeNull()->and($quarantine->acknowledged_at)->toBeNull()
        ->and(ItTicket::query()->count())->toBe(1)->and($connection->fresh()->last_polled_at)->toBeNull();
    $this->travel(61)->seconds();
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($reads)->toBe(['ambiguous' => 1, 'valid' => 1])
        ->and($acks)->toBe(['ambiguous' => 2, 'valid' => 1])
        ->and($quarantine->fresh()->acknowledged_at)->not->toBeNull()
        ->and($connection->fresh()->last_polled_at)->not->toBeNull()
        ->and(ItInboundEmail::query()->count())->toBe(2)->and(ItTicket::query()->count())->toBe(1);
})->with([
    ['google', 'from', 'duplicate_message_headers'],
    ['microsoft', 'from', 'duplicate_message_headers'],
    ['google', 'message-id', 'duplicate_message_headers'],
    ['microsoft', 'message-id', 'duplicate_message_headers'],
    ['google', 'references', 'duplicate_message_headers'],
    ['microsoft', 'references', 'duplicate_message_headers'],
    ['google', 'in-reply-to', 'duplicate_message_headers'],
    ['microsoft', 'in-reply-to', 'duplicate_message_headers'],
    ['google', 'multiple_senders', 'sender_ambiguous'],
    ['microsoft', 'multiple_senders', 'sender_ambiguous'],
    ['microsoft', 'provider_sender_conflict', 'sender_ambiguous'],
    ['microsoft', 'provider_identity_conflict', 'conflicting_message_headers'],
    ['google', 'oversized', 'message_headers_too_large'],
    ['microsoft', 'oversized', 'message_headers_too_large'],
    ['google', 'automatic', 'automatic_message'],
    ['microsoft', 'automatic', 'automatic_message'],
    ['google', 'report', 'delivery_report'],
    ['microsoft', 'report', 'delivery_report'],
    ['google', 'malformed_body', 'invalid_message_content'],
    ['microsoft', 'malformed_body', 'invalid_message_content'],
    ['google', 'missing_body', 'invalid_message_content'],
    ['microsoft', 'missing_body', 'invalid_message_content'],
    ['google', 'null_path', 'automatic_message'],
    ['microsoft', 'null_path', 'automatic_message'],
]);
