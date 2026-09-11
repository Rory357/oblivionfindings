<?php

/** Opt-in, fingerprinted disposable-browser fixtures. Never loaded by production application code. */

use App\Models\ItEmailDelivery;
use App\Models\ItMailboxConnection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function w11BrowserCreateMailboxFixtures(array $context, array $fixtures): array
{
    w06BrowserRequire(($context['mailbox_fixtures'] ?? false) && DB::scalar('SELECT DATABASE()') === $context['database']
        && ItMailboxConnection::query()->count() === 0, 'Only the exact empty synthetic mailbox fixture scope is allowed.');
    $operator = User::query()->where('email', 'w06-settings@demo.test')->sole();
    $role = Role::query()->where('name', 'w06-browser-settings')->sole();
    // The disposable settings operator also reviews W13 Operations health;
    // ordinary technicians and settings viewers retain their restricted roles.
    $role->permissions()->syncWithoutDetaching(Permission::query()
        ->whereIn('key', ['integrations.manage_secrets', 'it.view', 'it.manage'])->pluck('id'));
    w06BrowserRequire($operator->fresh()->canDo('integrations.manage_secrets'), 'The synthetic operator grant is unavailable.');
    $inactive = User::factory()->withoutTwoFactor()->create([
        'name' => 'W11 synthetic inactive sender', 'email' => 'w11-inactive@demo.test',
        'approved_at' => null, 'email_verified_at' => null,
    ]);
    $connections = [];
    foreach (['microsoft' => 'w11-ms@demo.test', 'google' => 'w11-gmail@demo.test'] as $provider => $email) {
        $connection = ItMailboxConnection::query()->create([
            'provider' => $provider, 'status' => 'connected', 'account_email' => $email,
            'account_name' => 'W11 synthetic '.$provider.' mailbox',
            'access_token' => 'synthetic-browser-token-with-no-provider-access', 'refresh_token' => null,
            // Recorded synthetic capability for local outbound settings acceptance;
            // this fixture grants no real provider access and uses array mail.
            'scopes' => $provider === 'google' ? ['https://www.googleapis.com/auth/gmail.modify'] : ['Mail.ReadWrite', 'Mail.Send'],
            'token_expires_at' => now()->addHours(2), 'created_by' => $operator->id,
        ]);
        $connections[$provider] = $connection->id;
    }
    w06BrowserSaveNew($context['root'].'/mailbox-provider-state.json', [
        'token' => $context['token'], 'synthetic_only' => true,
        'unread' => ['microsoft' => range(1, 43), 'google' => range(1, 43)],
        'ack_attempts' => ['microsoft' => [], 'google' => []], 'reads' => ['microsoft' => [], 'google' => []],
        'external_body_reads' => 0,
    ]);
    w06BrowserSaveNew($context['root'].'/mailbox-scanner-state.json', [
        'token' => $context['token'], 'synthetic_only' => true, 'attempts' => [],
    ]);
    w06BrowserSaveNew($context['root'].'/delivery-provider-state.json', [
        'token' => $context['token'], 'synthetic_only' => true, 'attempts' => [], 'outcomes' => [],
    ]);

    return ['synthetic_only' => true, 'operator_id' => $operator->id, 'connections' => $connections,
        'inactive_sender_id' => $inactive->id, 'messages_per_provider' => 43,
        'new_requests_per_provider' => 29, 'changed_subject_reply' => 28,
        'duplicate_reply' => 29, 'conflicting_identity' => 30, 'inactive_sender' => 31,
        'duplicate_reference_header' => 32, 'multiple_senders' => 33,
        'automatic_response' => 34, 'delivery_report' => 35, 'null_return_path' => 36,
        'complete_body' => 37, 'missing_full_body' => 38,
        'attachment_request' => 39, 'attachment_reply' => 40, 'duplicate_attachment_reply' => 41,
        'unknown_sender_attachment' => 42, 'infected_attachment' => 43,
        'scanner_is_synthetic' => true, 'first_request_file_scan_unavailable_then_clean' => true,
        'microsoft_first_acknowledgement_is_lost' => true];
}

/** Harmless bytes with explicit synthetic scanner outcomes; no actual malware. */
function w11BrowserFile(string $provider, int $id): array
{
    $identity = $id === 41 ? 40 : $id;
    $bytes = 'W11 synthetic '.$provider.' '.($id === 43 ? 'infected' : ($id === 39 ? 'retryable' : 'clean')).' file '.$identity;

    return ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'w11-file-'.$id,
        'name' => 'synthetic-evidence.txt', 'size' => strlen($bytes), 'contentType' => 'text/plain',
        'isInline' => false, 'contentBytes' => base64_encode($bytes)];
}

function w11BrowserInstallMailboxHttp(array $context): void
{
    w06BrowserRequire(($context['mailbox_fixtures'] ?? false) && $context['database'] === W06_BROWSER_DATABASE_PREFIX.$context['token'], 'Unexpected mailbox fixture context.');
    app()->instance(MalwareScanner::class, new class($context) extends MalwareScanner
    {
        public function __construct(private array $context) {}

        public function scanPath(string $path, array $settings): MalwareScanResult
        {
            $root = w06BrowserPath($this->context['storage'].'/app/private/it_attachments/');
            w06BrowserRequire(is_file($path) && ! is_link($path) && str_starts_with(w06BrowserPath((string) realpath($path)), $root), 'Scanner fixture requires an owned private file.');
            $bytes = file_get_contents($path);
            w06BrowserRequire(is_string($bytes) && str_starts_with($bytes, 'W11 synthetic '), 'Only clearly synthetic file bytes may use this fixture scanner.');
            $file = $this->context['root'].'/mailbox-scanner-state.json';
            w06BrowserRequire(is_file($file) && ! is_link($file), 'Owned scanner state is unavailable.');
            $handle = fopen($file, 'r+');
            w06BrowserRequire($handle !== false && flock($handle, LOCK_EX), 'Could not lock synthetic scanner state.');
            try {
                $state = json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
                w06BrowserRequire($state['token'] === $this->context['token'] && $state['synthetic_only'] === true, 'Scanner fixture identity differs.');
                $hash = hash('sha256', $bytes);
                $attempt = $state['attempts'][$hash] = ($state['attempts'][$hash] ?? 0) + 1;
                rewind($handle);
                w06BrowserRequire(ftruncate($handle, 0) && fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)) !== false && fflush($handle), 'Could not save synthetic scanner evidence.');
                $disposition = str_contains($bytes, ' infected ') ? MalwareScanDisposition::Infected
                    : (str_contains($bytes, ' retryable ') && $attempt === 1 ? MalwareScanDisposition::Unavailable : MalwareScanDisposition::Clean);

                return new MalwareScanResult($disposition, 'synthetic-browser-scanner', $disposition === MalwareScanDisposition::Unavailable ? 'scanner_unavailable' : null);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    });
    Http::fake(function ($request) use ($context) {
        $url = parse_url($request->url());
        $host = $url['host'] ?? '';
        $provider = $host === 'graph.microsoft.com' ? 'microsoft' : ($host === 'gmail.googleapis.com' ? 'google' : null);
        if ($provider === null || ($url['scheme'] ?? '') !== 'https') {
            return null; // preventStrayRequests rejects every other request.
        }
        $path = rawurldecode($url['path'] ?? '');
        $mailbox = null;
        if ($provider === 'microsoft') {
            if (! preg_match('~^/v1\.0/users/([^/]+)/(.*)$~D', $path, $match)) {
                return Http::response([], 403);
            }
            $mailbox = $match[1];
            if (! in_array($mailbox, ['w11-ms@demo.test', 'w11-shared@demo.test'], true)) {
                return Http::response(['error' => 'Synthetic mailbox permission denial'], 403);
            }
            $relative = $match[2];
        } else {
            if (! str_starts_with($path, '/gmail/v1/users/me/')) {
                return Http::response([], 403);
            }
            $relative = substr($path, strlen('/gmail/v1/users/me/'));
        }
        $file = $context['root'].'/mailbox-provider-state.json';
        w06BrowserRequire(is_file($file) && ! is_link($file) && str_starts_with(w06BrowserPath((string) realpath($file)), w06BrowserPath($context['root']).'/'), 'The owned synthetic provider state is missing or escaped.');
        $handle = fopen($file, 'r+');
        w06BrowserRequire($handle !== false && flock($handle, LOCK_EX), 'Could not lock owned synthetic provider state.');
        try {
            $state = json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
            w06BrowserRequire(($state['token'] ?? null) === $context['token'] && ($state['synthetic_only'] ?? false), 'Synthetic provider state identity changed.');
            parse_str($url['query'] ?? '', $query);
            $list = $request->method() === 'GET' && $relative === ($provider === 'microsoft' ? 'mailFolders/inbox/messages' : 'messages');
            $prefix = $provider === 'microsoft' ? 'w11-ms-' : 'w11-gm-';
            if ($list) {
                $offset = (int) ($query['$skip'] ?? $query['pageToken'] ?? 0);
                $ids = array_slice($state['unread'][$provider], $offset, min(25, max(1, (int) ($query['$top'] ?? $query['maxResults'] ?? 25))));
                $next = $offset + count($ids) < count($state['unread'][$provider]) ? $offset + count($ids) : null;
                $stubs = array_map(fn (int $id) => ['id' => $prefix.$id], $ids);

                return Http::response($provider === 'microsoft'
                    ? ['value' => $stubs, ...($next !== null ? ['@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/mailFolders/inbox/messages?$skip='.$next] : [])]
                    : ['messages' => $stubs, ...($next !== null ? ['nextPageToken' => (string) $next] : [])]);
            }
            if ($provider === 'google' && $relative === 'messages/w11-gm-37/attachments/w11-body-37' && $request->method() === 'GET') {
                $state['external_body_reads']++;
                rewind($handle);
                w06BrowserRequire(ftruncate($handle, 0) && fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)) !== false && fflush($handle), 'Synthetic body evidence could not be persisted.');
                $fullBody = "Complete external report.\nVerified full content.";

                return Http::response(['data' => base64_encode($fullBody), 'size' => strlen($fullBody)]);
            }
            if ($request->method() === 'GET' && preg_match('~^messages/'.preg_quote($prefix, '~').'([1-9][0-9]?)/attachments(?:/(w11-file-[1-9][0-9]?))?$~D', $relative, $fileMatch)) {
                $fileId = (int) $fileMatch[1];
                if ($fileId > 43 && $fileId <= 52 && (isset($state['merge_scenario']) || isset($state['outbound_scenario'])) && ! isset($fileMatch[2]) && $provider === 'microsoft') {
                    return Http::response(['value' => []]);
                }
                if ($fileId > 43 || ($provider === 'google' && ! isset($fileMatch[2]))) {
                    return Http::response([], 404);
                }
                if (! isset($fileMatch[2])) {
                    $metadata = $fileId >= 39 ? w11BrowserFile($provider, $fileId) : null;
                    if ($metadata !== null) {
                        unset($metadata['contentBytes']);
                    }

                    return Http::response(['value' => $metadata === null ? [] : [$metadata]]);
                }
                if ($fileId < 39 || $fileMatch[2] !== 'w11-file-'.$fileId) {
                    return Http::response([], 404);
                }
                $file = w11BrowserFile($provider, $fileId);

                return Http::response($provider === 'microsoft' ? $file : ['data' => $file['contentBytes'], 'size' => $file['size']]);
            }
            if (! preg_match('~^messages/'.preg_quote($prefix, '~').'([1-9][0-9]?)(/modify)?$~D', $relative, $match)
                || ($id = (int) $match[1]) > (isset($state['outbound_scenario']) ? 52 : (isset($state['merge_scenario']) ? 48 : 43))) {
                return Http::response([], 404);
            }
            $remote = $prefix.$id;
            if ($request->method() === 'GET' && ! isset($match[2])) {
                $state['reads'][$provider][$id] = ($state['reads'][$provider][$id] ?? 0) + 1;
                $messageId = '<'.$context['token'].'-'.$remote.'@demo.test>';
                $subject = 'W11 synthetic '.$provider.' request '.$id;
                $body = 'Disposable browser verification request. No real operational work is required.';
                $sender = 'w06-requester@demo.test';
                $threadHeaders = [];
                if (in_array($id, [28, 29, 30], true)) {
                    $messageId = '<'.$context['token'].'-'.$prefix.'28@demo.test>';
                    $subject = 'Changed subject: additional synthetic details';
                    $body = $id === 30 ? 'Synthetic conflicting reuse of the reply identity.' : 'Synthetic requester reply through canonical conversation commands.';
                    $parent = '<'.$context['token'].'-'.$prefix.'1@demo.test>';
                    $threadHeaders = [['name' => 'In-Reply-To', 'value' => $parent],
                        ['name' => 'References', 'value' => '<unknown-ancestor@demo.test> '.$parent]];
                } elseif ($id === 31) {
                    $sender = 'w11-inactive@demo.test';
                } elseif ($id === 32) {
                    $threadHeaders = [['name' => 'References', 'value' => '<first@demo.test>'],
                        ['name' => 'REFERENCES', 'value' => '<second@demo.test>']];
                } elseif ($id === 34) {
                    $threadHeaders = [['name' => 'Auto-Submitted', 'value' => '(automatic) auto-replied (response)']];
                } elseif ($id === 35) {
                    $threadHeaders = [['name' => 'Content-Type', 'value' => '(report) multipart/report; report-type=delivery-status']];
                } elseif ($id === 36) {
                    $threadHeaders = [['name' => 'Return-Path', 'value' => '(empty) <>']];
                } elseif ($id === 37) {
                    $body = "Complete external report.\nVerified full content.";
                } elseif (in_array($id, [40, 41], true)) {
                    $messageId = '<'.$context['token'].'-'.$prefix.'40@demo.test>';
                    $subject = 'Changed subject: synthetic file reply';
                    $body = 'Synthetic reply with a protected file.';
                    $threadHeaders = [['name' => 'In-Reply-To', 'value' => '<'.$context['token'].'-'.$prefix.'1@demo.test>']];
                } elseif ($id === 42) {
                    $sender = 'w11-unknown@demo.test';
                } elseif ($id >= 49) {
                    w06BrowserRequire(isset($state['outbound_scenario']), 'Outgoing reply fixture binding is missing.');
                    $outgoing = ItEmailDelivery::findOrFail($state['outbound_scenario']['delivery_id']);
                    w06BrowserRequire((int) $outgoing->it_ticket_id === $state['outbound_scenario']['ticket_id']
                        && $outgoing->recipient_email === 'w06-requester@demo.test' && $outgoing->rfc_message_id !== null,
                        'Canonical outgoing receipt changed.');
                    $subject = 'Changed subject from an actual outgoing receipt';
                    $body = 'W12 '.$provider.' changed subject reply.';
                    $threadHeaders = [['name' => 'In-Reply-To', 'value' => $outgoing->rfc_message_id]];
                    if ($id === 50) {
                        $messageId = $outgoing->rfc_message_id;
                        $body = 'Synthetic returned notification without its automated marker.';
                    } elseif ($id === 51) {
                        $sender = 'w06-other@demo.test';
                    } elseif ($id === 52) {
                        $messageId = '<'.$context['token'].'-'.$prefix.'49@demo.test>';
                    }
                } elseif ($id >= 44) {
                    w06BrowserRequire(isset($state['merge_scenario']['source_reference']), 'Merged-reply fixture binding is missing.');
                    $subject = $id <= 45 ? 'Re: '.$state['merge_scenario']['source_reference'] : 'Changed subject after the reviewed merge';
                    $body = 'W11 '.$provider.' '.($id === 44 ? 'before merge' : ($id === 45 ? 'old subject after merge' : 'changed subject after merge')).' reply.';
                    if ($id >= 46) {
                        $threadHeaders = [['name' => 'In-Reply-To', 'value' => '<'.$context['token'].'-'.$prefix.'44@demo.test>']];
                    }
                    if ($id === 47) {
                        $messageId = '<'.$context['token'].'-'.$prefix.'46@demo.test>';
                    } elseif ($id === 48) {
                        $sender = 'w06-other@demo.test';
                    }
                }
                $senderHeader = $id === 33 ? $sender.', Other <w06-other@demo.test>' : $sender;
                $headers = [
                    ['name' => 'From', 'value' => $senderHeader], ['name' => 'Subject', 'value' => $subject],
                    ['name' => 'Message-ID', 'value' => $messageId], ...$threadHeaders,
                ];
                $graphBody = ['contentType' => 'text', 'content' => $body];
                $gmailPayload = ['mimeType' => 'text/plain', 'body' => ['data' => base64_encode($body)], 'headers' => $headers];
                if ($id === 37) {
                    $gmailPayload = ['mimeType' => 'multipart/alternative', 'headers' => $headers, 'parts' => [
                        ['mimeType' => 'text/html', 'body' => ['data' => base64_encode('<p>Short alternative.</p>')]],
                        ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'w11-body-37', 'size' => strlen($body)]],
                    ]];
                } elseif ($id === 38) {
                    unset($graphBody['content']);
                    $gmailPayload['body'] = ['size' => 44];
                } elseif ($id >= 39 && $id <= 43) {
                    $file = w11BrowserFile($provider, $id);
                    $gmailPayload = ['mimeType' => 'multipart/mixed', 'headers' => $headers, 'parts' => [
                        ['mimeType' => 'text/plain', 'body' => ['data' => base64_encode($body)]],
                        ['mimeType' => 'text/plain', 'filename' => $file['name'],
                            'body' => ['attachmentId' => $file['id'], 'size' => $file['size']]],
                    ]];
                }
                $response = Http::response($provider === 'microsoft' ? [
                    'id' => $remote, 'subject' => $subject, 'from' => ['emailAddress' => ['address' => $sender]],
                    'body' => $graphBody, 'bodyPreview' => 'A preview must not replace the full message.', 'internetMessageId' => $messageId,
                    'internetMessageHeaders' => $headers,
                ] : ['id' => $remote, 'snippet' => 'A preview must not replace the full message.', 'payload' => $gmailPayload]);
            } elseif (($provider === 'microsoft' && $request->method() === 'PATCH' && $request['isRead'] === true)
                || ($provider === 'google' && $request->method() === 'POST' && isset($match[2]) && $request['removeLabelIds'] === ['UNREAD'])) {
                $state['ack_attempts'][$provider][$id] = ($state['ack_attempts'][$provider][$id] ?? 0) + 1;
                $state['unread'][$provider] = array_values(array_diff($state['unread'][$provider], [$id]));
                $failFirstAck = ($provider === 'microsoft' && $id === 1) || (isset($state['merge_scenario']) && $id === 45)
                    || (isset($state['outbound_scenario']) && $id === 49);
                $response = Http::response([], $failFirstAck && $state['ack_attempts'][$provider][$id] === 1 ? 503 : 200);
            } else {
                return Http::response([], 405);
            }
            rewind($handle);
            w06BrowserRequire(ftruncate($handle, 0) && fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)) !== false && fflush($handle), 'Synthetic provider state could not be persisted.');

            return $response;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    });
}
