<?php

/** Opt-in empty-schema fixture: real HTTP kernel/API commands, local sinks only. */

use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItAutomationScheduleCatalog;
use App\Models\ItApiRequest;
use App\Models\ItAutomationRun;
use App\Models\ItEmailDelivery;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function w13BrowserCreateApiFixtures(array $context, array $fixtures): array
{
    w06BrowserRequire(PHP_SAPI === 'cli' && ($context['api_fixtures'] ?? false)
        && DB::scalar('SELECT DATABASE()') === W06_BROWSER_DATABASE_PREFIX.$context['token']
        && config('mail.default') === 'array' && config('queue.default') === 'sync'
        && ItServiceIdentity::query()->count() === 0 && ItApiRequest::query()->count() === 0,
        'API fixtures require their reviewed empty isolated schema and local sinks.');

    $siteId = $fixtures['sites']['a'];
    $credentials = [];
    foreach (['cover', 'tech'] as $key) {
        $actor = User::query()->findOrFail($fixtures['actors'][$key]['id']);
        $credentials[$key] = app(ItServiceIdentityCredentialService::class)->create($actor, [
            'name' => 'W13 '.$context['token'].' synthetic '.$key.' connector',
            'actor_user_id' => $actor->id,
            'abilities' => ['work:create', 'work:comment', 'work:read', 'work:update', 'work:link', 'work:transition'], 'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [$siteId],
            'allowed_fields' => ['create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
                'read' => ['category'], 'update' => ['category', 'priority']],
            'require_signature' => false, 'rate_limit_per_minute' => 60,
        ]);
    }

    // Generated credentials exist in memory only. No token/secret is emitted,
    // passed in process arguments, or written to fixture/evidence files.
    $send = static function (string $actorKey, string $path, array $body, string $key, string $method = 'POST', int $expectedStatus = 201) use (&$credentials): array {
        $request = Request::create('http://127.0.0.1:8766'.$path, $method, server: [
            'HTTP_HOST' => '127.0.0.1:8766', 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$credentials[$actorKey]['token'],
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $result = ['status' => $response->getStatusCode(),
            'id' => data_get(json_decode($response->getContent(), true), 'data.id'),
            'lock_version' => data_get(json_decode($response->getContent(), true), 'data.lock_version'),
            'replay' => $response->headers->get('X-Idempotent-Replay') === 'true'];
        $kernel->terminate($request, $response);
        w06BrowserRequire($result['status'] === $expectedStatus && ($expectedStatus >= 400 || is_int($result['id'])), 'Synthetic API command failed; no response body or secret emitted.');

        return $result;
    };
    $payload = ['title' => 'W13 '.$context['token'].' API-created printer request',
        'description' => 'Synthetic local request created through the authenticated API. Verify its conversation in the desktop web app.',
        'category' => 'hardware', 'priority' => 'normal', 'work_type' => 'incident', 'site_id' => $siteId];
    $createKey = 'w13-'.$context['token'].'-create';
    $create = $send('cover', '/api/v1/it/work-items', $payload, $createKey);
    $createReplay = $send('cover', '/api/v1/it/work-items', $payload, $createKey);
    $commentPath = '/api/v1/it/work-items/'.$create['id'].'/comments';
    $reply = ['body' => 'Synthetic API technician reply: the printer connection has been checked. Please confirm the test page from the web app.'];
    $replyKey = 'w13-'.$context['token'].'-comment';
    $comment = $send('tech', $commentPath, $reply, $replyKey);
    $commentReplay = $send('tech', $commentPath, $reply, $replyKey);
    $ticket = ItTicket::query()->findOrFail($create['id']);
    $counts = ['tickets' => ItTicket::query()->where('title', $payload['title'])->count(),
        'comments' => $ticket->comments()->count(),
        'api_receipts' => ItApiRequest::query()->count(),
        'canonical_receipts' => ItTicketCommandReceipt::query()->where('channel', 'service_api')->count(),
        'deliveries' => ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->count()];
    w06BrowserRequire($createReplay['replay'] && $commentReplay['replay']
        && $create['id'] === $createReplay['id'] && $comment['id'] === $commentReplay['id']
        && $counts['tickets'] === 1 && $counts['comments'] === 1 && $counts['api_receipts'] === 2
        && $counts['canonical_receipts'] === 2 && $ticket->first_responded_at !== null
        && $ticket->next_response_party === 'requester', 'Synthetic canonical API publication or replay invariant failed.');
    $target = $send('cover', '/api/v1/it/work-items', [
        ...$payload, 'title' => 'W13 '.$context['token'].' related network investigation',
    ], 'w13-'.$context['token'].'-related-target');
    $sourcePath = '/api/v1/it/work-items/'.$ticket->id;
    $read = $send('tech', $sourcePath, [], 'unused-read-key', 'GET', 200);
    $updatePayload = ['expected_version' => $read['lock_version'], 'category' => 'network',
        'priority' => 'high', 'priority_reason' => 'Synthetic verified impact requires prompt investigation.'];
    $updated = $send('tech', $sourcePath, $updatePayload, 'w13-'.$context['token'].'-update', 'PATCH', 200);
    $updateReplay = $send('tech', $sourcePath, $updatePayload, 'w13-'.$context['token'].'-update', 'PATCH', 200);
    $linkPayload = ['target_ticket_id' => $target['id'], 'source_version' => $updated['lock_version'],
        'target_version' => $target['lock_version'], 'action' => 'add', 'relationship' => 'related_ticket'];
    $linked = $send('tech', $sourcePath.'/relationships', $linkPayload, 'w13-'.$context['token'].'-link', 'POST', 200);
    $linkReplay = $send('tech', $sourcePath.'/relationships', $linkPayload, 'w13-'.$context['token'].'-link', 'POST', 200);
    $transitionPayload = ['to' => 'in_progress', 'expected_version' => $linked['lock_version'],
        'reason' => 'Synthetic connector has started the linked investigation.'];
    $transition = $send('tech', $sourcePath.'/transitions', $transitionPayload, 'w13-'.$context['token'].'-transition', 'POST', 200);
    $transitionReplay = $send('tech', $sourcePath.'/transitions', $transitionPayload, 'w13-'.$context['token'].'-transition', 'POST', 200);
    $ticket->refresh();
    w06BrowserRequire($updateReplay['replay'] && $linkReplay['replay'] && $transitionReplay['replay']
        && $ticket->category === 'network' && $ticket->priority === 'high' && $ticket->status === 'in_progress'
        && $ticket->links()->where('relationship', 'related_ticket')->where('linkable_id', $target['id'])->count() === 1
        && ItTicketCommandReceipt::query()->where('channel', 'service_api')->where('operation', 'ticket.update')->count() === 1
        && ItTicketCommandReceipt::query()->where('channel', 'service_api')->where('operation', 'ticket.relationship')->count() === 1,
        'Synthetic API mutation publication/replay failed; no response or credential emitted.');
    $mutationCounts = ['api_receipts' => ItApiRequest::query()->count(),
        'canonical_receipts' => ItTicketCommandReceipt::query()->where('channel', 'service_api')->count()];

    // Real rejected API requests populate bounded diagnostic pagination. These
    // fixture-only requests use the same kernel and permission/receipt paths.
    foreach (range(1, 26) as $index) {
        $send('tech', '/api/v1/it/work-items', [], 'w13-'.$context['token'].'-invalid-'.$index, 'POST', 422);
    }
    $failureTitle = 'W13 '.$context['token'].' synthetic retained rollback';
    $recoveryTitle = 'W13 '.$context['token'].' synthetic recovered rollback';
    $faultTitles = [$failureTitle, $recoveryTitle];
    ItTicket::creating(static function (ItTicket $candidate) use (&$faultTitles): void {
        if (in_array($candidate->title, $faultTitles, true)) {
            $faultTitles = array_values(array_diff($faultTitles, [$candidate->title]));
            throw new RuntimeException('Synthetic local publication failure; provider-secret-marker must not reach diagnostics.');
        }
    });
    $send('tech', '/api/v1/it/work-items', [...$payload, 'title' => $failureTitle], 'w13-'.$context['token'].'-rollback', 'POST', 500);
    $recoveryKey = 'w13-'.$context['token'].'-recover';
    $recoveryPayload = [...$payload, 'title' => $recoveryTitle];
    $send('tech', '/api/v1/it/work-items', $recoveryPayload, $recoveryKey, 'POST', 500);
    $recovered = $send('tech', '/api/v1/it/work-items', $recoveryPayload, $recoveryKey);
    $recoveredReplay = $send('tech', '/api/v1/it/work-items', $recoveryPayload, $recoveryKey);
    $recoveredReceipt = ItApiRequest::query()->where('idempotency_key', $recoveryKey)->sole();
    w06BrowserRequire($recoveredReplay['replay'] && $recovered['id'] === $recoveredReplay['id']
        && $recoveredReceipt->attempt_count === 2 && $recoveredReceipt->execution_state === 'committed'
        && ItTicket::query()->where('title', $recoveryTitle)->count() === 1,
        'Synthetic failed intent did not recover to one canonical result.');

    // Explicit historical fixture, not a claim about a live pending command.
    ItApiRequest::query()->create(['service_identity_id' => $credentials['tech']['identity']->id,
        'method' => 'POST', 'path' => '/private/provider-secret-marker',
        'idempotency_key' => 'w13-'.$context['token'].'-legacy-pending',
        'request_hash' => hash('sha256', 'synthetic legacy pending'),
        'response_body' => ['private' => 'provider-secret-marker']]);
    $legacyDelivery = ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)->firstOrFail();
    $legacyDelivery->update(['status' => 'failed', 'failed_at' => now(), 'last_error' => 'provider-secret-marker']);
    $diagnostics = ['recorded_validation_failures' => 26, 'retained_rollback_failures' => 1,
        'recovered_attempts' => $recoveredReceipt->attempt_count, 'recovery_replayed' => $recoveredReplay['replay'],
        'synthetic_legacy_pending' => 1, 'synthetic_legacy_delivery' => (int) $legacyDelivery->id];

    // Exercise the framework's real event sequence without running operational commands.
    app(ItAutomationScheduleCatalog::class)->register();
    $task = clone collect(app(Schedule::class)->events())->firstWhere('description', 'it.close-resolved');
    $recorder = app(ItAutomationRunRecorder::class);
    $beforeRuns = ItAutomationRun::query()->count();
    $recorder->starting(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    $recorder->finished(new ScheduledTaskFinished($task, 0.75));
    $recorder->failed(new ScheduledTaskFailed($task, new RuntimeException('synthetic-scheduler-secret-marker')));
    w06BrowserRequire(ItAutomationRun::query()->count() === $beforeRuns + 1
        && ItAutomationRun::query()->latest('id')->first()->status === 'failed',
        'Synthetic scheduler events did not retain one failed outcome.');
    $diagnostics['scheduler_failed_run_id'] = ItAutomationRun::query()->latest('id')->value('id');
    $legacyRun = ItAutomationRun::query()->create(['automation_key' => 'it.dispatch-notifications',
        'status' => 'failed', 'started_at' => now(), 'finished_at' => now(),
        'error_summary' => 'synthetic-scheduler-secret-marker', 'result_summary' => ['private' => 'synthetic-scheduler-payload-marker']]);
    $diagnostics['scheduler_legacy_run_id'] = $legacyRun->id;
    // Retained history spans two real pages; these are explicitly synthetic outcomes.
    ItAutomationRun::query()->create(['automation_key' => 'it.close-resolved', 'status' => 'running', 'started_at' => now()->subDay()]);
    for ($index = 0; $index < 25; $index++) {
        $skippedRun = $recorder->begin('it.close-resolved');
        $recorder->completeRun($skippedRun, 'skipped', 0);
    }
    foreach ([
        'completed' => ['connections' => 2, 'failed' => 0, 'pending' => 0, 'skipped' => 0],
        'skipped' => ['connections' => 2, 'failed' => 0, 'pending' => 0, 'skipped' => 2],
        'no_work' => ['connections' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0],
        'pending' => ['connections' => 2, 'failed' => 0, 'pending' => 1, 'skipped' => 0],
    ] as $name => $summary) {
        $mailboxRun = $recorder->begin('it.poll-mailbox');
        $recorder->completeRun($mailboxRun, 'succeeded', 750, result: $summary);
        $diagnostics['scheduler_mailbox_'.$name.'_id'] = $mailboxRun->id;
    }
    $diagnostics['scheduler_run_count'] = ItAutomationRun::query()->count();
    unset($credentials);

    return ['ticket_id' => $ticket->id, 'comment_id' => $comment['id'],
        'requester_actor' => 'cover', 'technician_actor' => 'tech',
        'create' => $create, 'create_replay' => $createReplay,
        'comment' => $comment, 'comment_replay' => $commentReplay,
        'foundation_counts' => $counts, 'mutation_counts' => $mutationCounts, 'diagnostics' => $diagnostics,
        'mutations' => ['target_ticket_id' => $target['id'], 'read' => $read, 'update' => $updated,
            'update_replay' => $updateReplay, 'link' => $linked, 'link_replay' => $linkReplay,
            'transition' => $transition, 'transition_replay' => $transitionReplay], 'source' => $ticket->source,
        'next_response_party' => $ticket->next_response_party, 'first_response_recorded' => true];
}
