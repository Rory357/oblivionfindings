<?php

namespace App\Services;

use App\Contracts\CalendarOAuthToken;
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
            'https://login.microsoftonline.com/' . config('services.microsoft.tenant') . '/oauth2/v2.0/token',
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

    public function sendMail(string $to, string $subject, string $body, array $attachments = []): bool
    {
        $message = [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $body],
                'toRecipients' => [['emailAddress' => ['address' => $to]]],
            ],
        ];

        if (!empty($attachments)) {
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
        $response = $this->client()->get('/users/'.rawurlencode($mailboxUpn).'/mailFolders/inbox/messages', [
            '$filter' => 'isRead eq false',
            '$select' => 'id,subject,from,body,bodyPreview,internetMessageId,receivedDateTime',
            '$top' => $limit,
            '$orderby' => 'receivedDateTime asc',
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('value', []))
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
            ->filter(fn (array $m) => $m['remote_id'] !== '' && $m['from'] !== '')
            ->values()
            ->all();
    }

    /** Flag a mailbox message read so the next poll doesn't re-ingest it. */
    public function markRead(string $mailboxUpn, string $messageId): bool
    {
        return $this->client()
            ->patch('/users/'.rawurlencode($mailboxUpn).'/messages/'.rawurlencode($messageId), ['isRead' => true])
            ->successful();
    }

    /** Best-effort plain text from a Graph message body (text, html, or preview). */
    private function plainTextBody(array $message): string
    {
        $type = strtolower((string) ($message['body']['contentType'] ?? ''));
        $content = (string) ($message['body']['content'] ?? '');

        if ($content !== '' && $type !== 'html') {
            return trim($content);
        }
        if ($content !== '') {
            return trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5));
        }

        return trim((string) ($message['bodyPreview'] ?? ''));
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
