<?php

use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItTicketMergeService;
use App\Jobs\PollItMailboxJob;
use App\Models\AuditLog;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

test('provider replies follow authorized merge ancestry and retain exactly-once evidence', function (string $provider, string $scenario, ?string $failure) {
    Http::preventStrayRequests();
    Notification::fake();
    $this->seed(RbacSeeder::class);
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $sender = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$sender, $agent] as $actor) {
        $actor->roles()->attach(Role::where('name', $actor->role)->firstOrFail());
        ensureCanonicalHrStaffProfile($actor, $site);
    }
    $ingestor = app(InboundEmailIngestor::class);
    $originals = collect(range(1, 3))->map(fn ($index) => $ingestor->ingest([
        'from' => $sender->email, 'message_id' => '<merge-original-'.$index.'@example.test>',
        'subject' => 'Synthetic merge original '.$index, 'text' => 'Original report '.$index,
    ]));
    [$source, $target, $third] = $originals->map(fn ($row) => $row->ticket)->all();
    $merge = app(ItTicketMergeService::class);
    $merge->merge($source, $target, $agent, 'Synthetic duplicate reviewed by IT.', $source->lock_version, $target->lock_version);
    $destination = $target->fresh();
    if (in_array($scenario, ['chain', 'converging_headers', 'intermediate_denied'], true)) {
        $merge->merge($destination, $third, $agent, 'Synthetic related duplicate reviewed by IT.', $destination->lock_version, $third->lock_version);
        $destination = $third->fresh();
    }
    if (in_array($scenario, ['destination_denied', 'intermediate_denied', 'source_denied'], true)) {
        // Simulate a later canonical audience change. Historical linkage grants no access.
        $changed = $scenario === 'source_denied' ? $source : ($scenario === 'intermediate_denied' ? $target : $destination);
        $changed->fresh()->forceFill(['requester_user_id' => $agent->id, 'requested_for_user_id' => $agent->id])->save();
    }
    if ($scenario === 'missing') {
        expect(fn () => $source->fresh()->forceFill(['merged_into_ticket_id' => 999999999])->save())
            ->toThrow(QueryException::class);
        expect($source->fresh()->merged_into_ticket_id)->toBe($target->id);
    } elseif ($scenario === 'cycle') {
        // Deliberately corrupt legacy data; ordinary merge commands reject cycles.
        $target->fresh()->forceFill(['merged_into_ticket_id' => $source->id, 'merged_at' => now()])->save();
    }
    $subject = $scenario === 'subject' ? 'Re: '.$source->reference : 'Changed subject after a reviewed merge';
    if ($scenario === 'conflicting') {
        $subject = 'Re: '.$third->reference;
    }
    $parents = $scenario === 'subject' ? [] : [['name' => 'In-Reply-To', 'value' => '<merge-original-1@example.test>']];
    if ($scenario === 'converging_headers') {
        $parents[] = ['name' => 'References', 'value' => '<merge-original-2@example.test>'];
    }
    $body = 'Synthetic reply to the original merged report.';
    $connection = ItMailboxConnection::create(['provider' => $provider, 'status' => 'connected',
        'account_email' => 'inbox@example.test', 'access_token' => 'synthetic-access',
        'refresh_token' => 'synthetic-refresh', 'token_expires_at' => now()->addHour()]);
    $reads = $acks = 0;
    Http::fake(function ($request) use ($provider, $sender, $subject, $parents, $body, &$reads, &$acks) {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($host !== ($provider === 'microsoft' ? 'graph.microsoft.com' : 'gmail.googleapis.com')) {
            throw new RuntimeException('Unexpected synthetic provider host.');
        }
        if ($request->method() === 'GET' && ($provider === 'microsoft' ? str_ends_with($path, '/mailFolders/inbox/messages') : str_ends_with($path, '/messages'))) {
            return Http::response([$provider === 'microsoft' ? 'value' : 'messages' => [['id' => 'merged-reply']]]);
        }
        if ($provider === 'microsoft' && $request->method() === 'GET' && str_ends_with($path, '/messages/merged-reply/attachments')) {
            return Http::response(['value' => []]);
        }
        if (($provider === 'microsoft' && $request->method() === 'PATCH' && str_ends_with($path, '/messages/merged-reply'))
            || ($provider === 'google' && $request->method() === 'POST' && str_ends_with($path, '/messages/merged-reply/modify'))) {
            $acks++;

            return Http::response([], $acks === 1 ? 503 : 200);
        }
        if ($request->method() !== 'GET' || ! str_ends_with($path, '/messages/merged-reply')) {
            throw new RuntimeException('Unexpected synthetic mailbox operation.');
        }
        $reads++;
        $headers = [['name' => 'From', 'value' => $sender->email], ['name' => 'Subject', 'value' => $subject],
            ['name' => 'Message-ID', 'value' => '<merged-reply@example.test>'], ...$parents];

        return Http::response($provider === 'microsoft' ? [
            'id' => 'merged-reply', 'from' => ['emailAddress' => ['address' => $sender->email]],
            'subject' => $subject, 'internetMessageId' => '<merged-reply@example.test>', 'internetMessageHeaders' => $headers,
            'body' => ['contentType' => 'text', 'content' => $body],
        ] : ['id' => 'merged-reply', 'payload' => ['mimeType' => 'text/plain', 'headers' => $headers,
            'body' => ['size' => strlen($body), 'data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')]]]);
    });
    $auditBefore = AuditLog::where('action', 'it.ticket.comment.added')->count();
    (new PollItMailboxJob)->handle($ingestor);
    $receipt = ItInboundEmail::where('mailbox_scope_hash', $connection->mailboxScopeHash())->sole();
    expect($receipt->status)->toBe($failure === null ? 'processed' : 'quarantined')
        ->and($receipt->quarantine_reason)->toBe($failure)->and($receipt->acknowledged_at)->toBeNull();
    expect(ItTicket::count())->toBe(3)
        ->and(ItTicketComment::where('body', $body)->count())->toBe($failure === null ? 1 : 0)
        ->and(ItTicketCommandReceipt::where('operation', 'ticket.comment')->count())->toBe($failure === null ? 1 : 0)
        ->and(AuditLog::where('action', 'it.ticket.comment.added')->count() - $auditBefore)->toBe($failure === null ? 1 : 0)
        ->and($originals[0]->fresh()->it_ticket_id)->toBe($source->id);
    if ($failure === null) {
        expect($receipt->it_ticket_id)->toBe($destination->id)
            ->and($destination->comments()->where('body', $body)->sole()->source_channel)->toBe('email');
    } else {
        expect($receipt->it_ticket_id)->toBeNull()->and($receipt->body_preview)->toBeNull();
    }
    $this->travel(61)->seconds();
    (new PollItMailboxJob)->handle($ingestor);
    expect($receipt->fresh()->acknowledged_at)->not->toBeNull()
        ->and($connection->fresh()->last_poll_failure_code)->toBeNull()
        ->and($reads)->toBe(1)->and($acks)->toBe(2);
    (new PollItMailboxJob)->handle($ingestor);
    expect(ItTicket::count())->toBe(3)->and($reads)->toBe(1)->and($acks)->toBe(2)
        ->and(ItTicketComment::where('body', $body)->count())->toBe($failure === null ? 1 : 0);
})->with(['microsoft', 'google'])->with([
    'old subject reference' => ['subject', null],
    'changed subject with original parent' => ['changed_subject', null],
    'two merge steps' => ['chain', null],
    'ancestry converges on one destination' => ['converging_headers', null],
    'destination audience changed' => ['destination_denied', 'reference_unavailable'],
    'intermediate audience changed' => ['intermediate_denied', 'reference_unavailable'],
    'original audience changed' => ['source_denied', 'sender_unauthorized'],
    'subject and ancestry conflict' => ['conflicting', 'reference_ambiguous'],
    'missing destination rejected by the database' => ['missing', null],
    'corrupt legacy merge cycle' => ['cycle', 'reference_unavailable'],
]);
