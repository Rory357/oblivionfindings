<?php

namespace App\Services;

use App\Contracts\CalendarOAuthToken;
use App\Domain\It\Data\ItEmailAttachment;
use App\Domain\It\Exceptions\ItInboundContentException;
use App\Domain\It\Exceptions\ItInboundHeaderException;
use App\Domain\It\Services\ItEmailAttachments;
use App\Domain\It\Services\ItEmailContent;
use App\Domain\It\Services\ItEmailHeaders;
use App\Domain\It\Services\ItEmailMessageIdentifiers;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\MailboxMessagePage;
use App\Services\Integration\MailboxProviderHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphService
{
    public function __construct(protected CalendarOAuthToken $token) {}

    protected function client()
    {
        if ($this->token->needsRefresh()) {
            $this->refreshAccessToken();
        }

        return Http::withToken((string) $this->token->getAccessToken())
            ->baseUrl('https://graph.microsoft.com/v1.0');
    }

    protected function refreshAccessToken(): void
    {
        $refreshToken = $this->token->getRefreshToken();
        if (! $refreshToken) {
            return;
        }

        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/'.config('services.microsoft.tenant').'/oauth2/v2.0/token',
            [
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope' => 'https://graph.microsoft.com/.default offline_access',
            ]
        );

        if ($response->successful()) {
            $this->token->storeRefreshedToken(
                (string) $response->json('access_token'),
                $response->json('refresh_token'),
                (int) $response->json('expires_in', 3600),
            );
        } else {
            Log::warning('Microsoft token refresh failed', ['status' => $response->status()]);
        }
    }

    // ------------------------------------------------------------------
    // Personal calendar (/me) — used by the per-user "add to my calendar".
    // ------------------------------------------------------------------

    public function getCalendarEvents(string $from, string $to): array
    {
        $response = $this->client()->get('/me/calendarview', [
            'startDateTime' => $from,
            'endDateTime' => $to,
            '$select' => 'id,subject,start,end,location,body,isAllDay',
            '$top' => 100,
            '$orderby' => 'start/dateTime',
        ]);

        return $response->successful() ? $response->json('value', []) : [];
    }

    public function createCalendarEvent(array $data): ?array
    {
        $response = $this->client()->post('/me/events', $data);

        return $response->successful() ? $response->json() : null;
    }

    public function updateCalendarEvent(string $eventId, array $data): bool
    {
        return $this->client()->patch("/me/events/{$eventId}", $data)->successful();
    }

    public function deleteCalendarEvent(string $eventId): bool
    {
        return $this->client()->delete("/me/events/{$eventId}")->successful();
    }

    // ------------------------------------------------------------------
    // Resource / room mailboxes — used by admin house calendar sync.
    // The connected account must have delegated access to the room mailbox.
    // ------------------------------------------------------------------

    /**
     * List the org's room/resource mailboxes (requires Place.Read.All).
     *
     * @return array<int, array{id:string,name:string,email:string}>
     */
    public function listRooms(): array
    {
        $response = $this->client()->get('/places/microsoft.graph.room', [
            '$top' => 250,
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('value', []))
            ->map(fn (array $room) => [
                'id' => (string) ($room['emailAddress'] ?? $room['id'] ?? ''),
                'name' => (string) ($room['displayName'] ?? $room['emailAddress'] ?? ''),
                'email' => (string) ($room['emailAddress'] ?? ''),
            ])
            ->filter(fn ($room) => $room['id'] !== '')
            ->values()
            ->all();
    }

    public function getRoomCalendarEvents(string $roomUpn, string $from, string $to): array
    {
        $response = $this->client()->get('/users/'.rawurlencode($roomUpn).'/calendarView', [
            'startDateTime' => $from,
            'endDateTime' => $to,
            '$select' => 'id,subject,start,end,location,isAllDay,showAs',
            '$top' => 250,
            '$orderby' => 'start/dateTime',
        ]);

        return $response->successful() ? $response->json('value', []) : [];
    }

    public function createRoomEvent(string $roomUpn, array $data): ?array
    {
        $response = $this->client()->post('/users/'.rawurlencode($roomUpn).'/events', $data);

        return $response->successful() ? $response->json() : null;
    }

    public function updateRoomEvent(string $roomUpn, string $eventId, array $data): bool
    {
        return $this->client()
            ->patch('/users/'.rawurlencode($roomUpn).'/events/'.rawurlencode($eventId), $data)
            ->successful();
    }

    public function deleteRoomEvent(string $roomUpn, string $eventId): bool
    {
        return $this->client()
            ->delete('/users/'.rawurlencode($roomUpn).'/events/'.rawurlencode($eventId))
            ->successful();
    }

    // Mail methods

    /** Preserve notification headers and MIME parts through the existing Graph transport. */
    public function sendMimeMail(string $mime): void
    {
        $response = MailboxProviderHttp::send(fn () => MailboxProviderHttp::client($this->token, 'microsoft')
            ->withBody(base64_encode($mime), 'text/plain')->post('/me/sendMail'));
        if ($response->status() !== 202) {
            throw new MailboxProviderFailure('invalid_response');
        }
    }

    public function sendMail(string $to, string $subject, string $body, array $attachments = []): bool
    {
        $message = [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $body],
                'toRecipients' => [['emailAddress' => ['address' => $to]]],
            ],
        ];

        if (! empty($attachments)) {
            $message['message']['attachments'] = $attachments;
        }

        return $this->client()->post('/me/sendMail', $message)->successful();
    }

    /**
     * Unread inbox messages for a mailbox (requires Mail.Read on the connected
     * account, delegated to the support mailbox), oldest first, normalised to
     * the shape InboundEmailIngestor::ingest() consumes plus `remote_id` for
     * the follow-up markRead().
     *
     * @return array<int, array{remote_id:string,from:string,subject:?string,text:string,message_id:?string,in_reply_to:?string}>
     */
    public function listUnreadMessages(string $mailboxUpn, int $limit = 25): array
    {
        $response = MailboxProviderHttp::send(fn () => MailboxProviderHttp::client($this->token, 'microsoft')->get('/users/'.rawurlencode($mailboxUpn).'/mailFolders/inbox/messages', [
            '$filter' => 'receivedDateTime ge 0001-01-01T00:00:00Z and isRead eq false',
            '$select' => 'id,subject,from,body,bodyPreview,internetMessageId,receivedDateTime',
            '$top' => $limit,
            '$orderby' => 'receivedDateTime asc',
        ]));
        $body = MailboxProviderHttp::object($response);
        if (! is_array($body['value'] ?? null) || ! array_is_list($body['value'])) {
            throw new MailboxProviderFailure('invalid_response');
        }
        foreach ($body['value'] as $message) {
            if (! is_array($message) || ! is_string($message['id'] ?? null) || $message['id'] === '') {
                throw new MailboxProviderFailure('invalid_response');
            }
        }

        return collect($body['value'])
            ->map(fn (array $m) => [
                'remote_id' => (string) ($m['id'] ?? ''),
                'from' => (string) ($m['from']['emailAddress']['address'] ?? ''),
                'subject' => $m['subject'] ?? null,
                'text' => $this->plainTextBody($m),
                'message_id' => $m['internetMessageId'] ?? null,
                // Graph doesn't $select an in-reply-to header; threading keys
                // off the IT-… reference in the subject (InboundEmailIngestor).
                'in_reply_to' => null,
            ])
            ->values()
            ->all();
    }

    /** Discover IDs before changing unread flags. Preserve the provider's opaque nextLink. */
    public function discoverUnread(string $mailboxUpn, ?\DateTimeInterface $before, ?string $continuation = null, int $limit = 100): MailboxMessagePage
    {
        if ($limit < 1 || $limit > 100) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $path = '/users/'.rawurlencode($mailboxUpn).'/mailFolders/inbox/messages';
        if ($continuation !== null) {
            $this->validateMailboxContinuation($mailboxUpn, $continuation);
        }
        $response = MailboxProviderHttp::send(function () use ($continuation, $path, $before, $limit) {
            $request = MailboxProviderHttp::client($this->token, 'microsoft')->withHeaders(['Prefer' => 'IdType="ImmutableId"']);

            return $continuation !== null ? $request->get($continuation) : $request->get($path, [
                '$filter' => ($before ? 'receivedDateTime lt '.\DateTimeImmutable::createFromInterface($before)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : 'receivedDateTime ge 0001-01-01T00:00:00Z').' and isRead eq false',
                '$select' => 'id', '$top' => $limit, '$orderby' => 'receivedDateTime asc',
            ]);
        });
        $body = MailboxProviderHttp::object($response);
        if (! is_array($body['value'] ?? null) || ! array_is_list($body['value'])) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $ids = [];
        foreach ($body['value'] as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                throw new MailboxProviderFailure('invalid_response');
            }
            $ids[] = $item['id'];
        }
        $next = $body['@odata.nextLink'] ?? null;
        if ($next !== null) {
            if (! is_string($next)) {
                throw new MailboxProviderFailure('invalid_response');
            }
            $this->validateMailboxContinuation($mailboxUpn, $next);
        }

        return new MailboxMessagePage($ids, $next);
    }

    private function validateMailboxContinuation(string $mailbox, string $url, ?string $messageId = null): void
    {
        $parts = parse_url($url);
        // Validate before constructing an authenticated request, including restored cursors.
        if (strlen($url) > 16384 || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'graph.microsoft.com'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment'])
            || rawurldecode($parts['path'] ?? '') !== '/v1.0/users/'.$mailbox.($messageId === null ? '/mailFolders/inbox/messages' : '/messages/'.$messageId.'/attachments')
            || empty($parts['query']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new MailboxProviderFailure('invalid_response');
        }
    }

    public function readMessage(string $mailboxUpn, string $messageId, bool $includeAttachments = false): array
    {
        $response = MailboxProviderHttp::send(fn () => MailboxProviderHttp::client($this->token, 'microsoft')
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
            ->get('/users/'.rawurlencode($mailboxUpn).'/messages/'.rawurlencode($messageId), [
                '$select' => 'id,subject,from,body,bodyPreview,internetMessageId,internetMessageHeaders',
            ]));
        $message = MailboxProviderHttp::object($response);
        if (($message['id'] ?? null) !== $messageId || ! is_array($message['body'] ?? null)) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $parser = new ItEmailHeaders;
        $headers = $parser->read($message['internetMessageHeaders'] ?? []);
        (new ItEmailContent)->assertHumanMessage($headers);
        $address = $message['from']['emailAddress']['address'] ?? '';
        if (! is_string($address)) {
            throw new ItInboundHeaderException('sender_ambiguous');
        }
        $from = $parser->sender($address);
        if (isset($headers['from']) && $parser->sender($headers['from']) !== $from) {
            throw new ItInboundHeaderException('sender_ambiguous');
        }
        $identity = $message['internetMessageId'] ?? null;
        if ($identity !== null && ! is_string($identity)) {
            throw new ItInboundHeaderException;
        }
        if (isset($headers['message-id']) && (new ItEmailMessageIdentifiers)->messageId($headers['message-id'])
            !== (new ItEmailMessageIdentifiers)->messageId($identity)) {
            throw new ItInboundHeaderException('conflicting_message_headers');
        }

        return [
            'remote_id' => $messageId,
            'from' => $from,
            'subject' => $message['subject'] ?? null, 'text' => $this->plainTextBody($message),
            'message_id' => $identity, 'in_reply_to' => $headers['in-reply-to'] ?? null,
            'references' => $headers['references'] ?? null,
            ...($includeAttachments ? ['attachments' => $this->attachmentManifest($mailboxUpn, $messageId)] : []),
        ];
    }

    /** Never infer absence from hasAttachments: that flag excludes inline files. */
    private function attachmentManifest(string $mailbox, string $messageId): array
    {
        $files = $seen = [];
        $next = null;
        for ($page = 0; $page < 6; $page++) {
            $body = MailboxProviderHttp::object(MailboxProviderHttp::send(function () use ($mailbox, $messageId, $next) {
                $client = MailboxProviderHttp::client($this->token, 'microsoft')->withHeaders(['Prefer' => 'IdType="ImmutableId"']);

                return $next !== null ? $client->get($next) : $client->get('/users/'.rawurlencode($mailbox).'/messages/'.rawurlencode($messageId).'/attachments', [
                    '$select' => 'id,name,size,contentType,isInline', '$top' => ItEmailAttachments::MAX_FILES + 1,
                ]);
            }));
            if (! is_array($body['value'] ?? null) || ! array_is_list($body['value'])) {
                throw new MailboxProviderFailure('invalid_response');
            }
            $files = [...$files, ...$body['value']];
            if (count($files) > ItEmailAttachments::MAX_FILES) {
                throw new ItInboundContentException('too_many_attachments');
            }
            $next = $body['@odata.nextLink'] ?? null;
            if ($next === null) {
                return (new ItEmailAttachments)->graph($files);
            }
            if (! is_string($next) || isset($seen[$next])) {
                throw new MailboxProviderFailure('invalid_response');
            }
            $this->validateMailboxContinuation($mailbox, $next, $messageId);
            $seen[$next] = true;
        }
        throw new MailboxProviderFailure('invalid_response');
    }

    /** Unscanned bytes: caller must use canonical private staging and scan policy. */
    public function readAttachmentContents(string $mailbox, string $messageId, ItEmailAttachment $file): string
    {
        if ($file->provider !== 'microsoft' || $messageId === '' || strlen($messageId) > 4096 || preg_match('/[\x00-\x20\x7f]/', $messageId)) {
            throw new ItInboundContentException('invalid_attachment_metadata');
        }

        return (new ItEmailAttachments)->contents($file, fn (string $id): array => MailboxProviderHttp::object(
            MailboxProviderHttp::send(fn () => MailboxProviderHttp::client($this->token, 'microsoft', ItEmailAttachments::FILE_RESPONSE_BYTES)
                ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
                ->get('/users/'.rawurlencode($mailbox).'/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($id)))
        ));
    }

    /** Flag a mailbox message read so the next poll doesn't re-ingest it. */
    public function markRead(string $mailboxUpn, string $messageId): bool
    {
        MailboxProviderHttp::send(fn () => MailboxProviderHttp::client($this->token, 'microsoft')
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
            ->patch('/users/'.rawurlencode($mailboxUpn).'/messages/'.rawurlencode($messageId), ['isRead' => true]));

        return true;
    }

    /** Complete bounded body; a provider preview is never a replacement for missing content. */
    private function plainTextBody(array $message): string
    {
        return (new ItEmailContent)->graph($message['body'] ?? null);
    }

    // User info

    public function getProfile(): ?array
    {
        $response = $this->client()->get('/me', [
            '$select' => 'id,displayName,mail,jobTitle,department',
        ]);

        return $response->successful() ? $response->json() : null;
    }
}
