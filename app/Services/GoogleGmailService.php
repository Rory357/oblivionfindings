<?php

namespace App\Services;

use App\Contracts\CalendarOAuthToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gmail read for the IT support mailbox poller (E5). Sibling of
 * GoogleCalendarService on the same CalendarOAuthToken contract — same
 * client/refresh shape, Gmail base URL. Gmail always reads the CONNECTED
 * account's own inbox (/users/me): for a shared support inbox, connect the
 * support account itself. The $mailbox parameter exists for signature parity
 * with MicrosoftGraphService so PollItMailboxJob treats providers uniformly.
 */
class GoogleGmailService
{
    public function __construct(protected CalendarOAuthToken $token) {}

    protected function client()
    {
        if ($this->token->needsRefresh()) {
            $this->refreshAccessToken();
        }

        return Http::withToken((string) $this->token->getAccessToken())
            ->baseUrl('https://gmail.googleapis.com/gmail/v1');
    }

    protected function refreshAccessToken(): void
    {
        $refreshToken = $this->token->getRefreshToken();
        if (! $refreshToken) {
            return;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->successful()) {
            $this->token->storeRefreshedToken(
                (string) $response->json('access_token'),
                $response->json('refresh_token'),
                (int) $response->json('expires_in', 3600),
            );
        } else {
            Log::warning('Google token refresh failed', ['status' => $response->status()]);
        }
    }

    /**
     * Unread inbox messages, normalised to the shape
     * InboundEmailIngestor::ingest() consumes plus `remote_id` for markRead().
     *
     * @return array<int, array{remote_id:string,from:string,subject:?string,text:string,message_id:?string,in_reply_to:?string}>
     */
    public function listUnreadMessages(string $mailbox = 'me', int $limit = 25): array
    {
        $list = $this->client()->get('/users/me/messages', [
            'q' => 'is:unread in:inbox',
            'maxResults' => $limit,
        ]);

        if (! $list->successful()) {
            return [];
        }

        return collect($list->json('messages', []))
            ->map(fn (array $stub) => $this->fetchMessage((string) ($stub['id'] ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    /** Remove the UNREAD label so the next poll doesn't re-ingest the message. */
    public function markRead(string $mailbox, string $messageId): bool
    {
        return $this->client()
            ->post('/users/me/messages/'.rawurlencode($messageId).'/modify', [
                'removeLabelIds' => ['UNREAD'],
            ])
            ->successful();
    }

    /**
     * Full message → normalised row, or null when unfetchable/malformed.
     *
     * @return array{remote_id:string,from:string,subject:?string,text:string,message_id:?string,in_reply_to:?string}|null
     */
    private function fetchMessage(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $response = $this->client()->get('/users/me/messages/'.rawurlencode($id), ['format' => 'full']);
        if (! $response->successful()) {
            return null;
        }

        $payload = (array) $response->json('payload', []);
        $headers = collect($payload['headers'] ?? [])
            ->keyBy(fn ($h) => strtolower((string) ($h['name'] ?? '')));
        $header = fn (string $name): ?string => $headers->get($name)['value'] ?? null;

        $from = $this->addressFrom((string) $header('from'));
        if ($from === '') {
            return null;
        }

        return [
            'remote_id' => $id,
            'from' => $from,
            'subject' => $header('subject'),
            'text' => $this->plainTextBody($payload, (string) $response->json('snippet', '')),
            'message_id' => $header('message-id'),
            'in_reply_to' => $header('in-reply-to'),
        ];
    }

    /** Bare address from an RFC "Name <address>" From header. */
    private function addressFrom(string $header): string
    {
        return preg_match('/<([^>]+)>/', $header, $m) ? trim($m[1]) : trim($header);
    }

    /** Best-effort plain text: text/plain part, else stripped html, else snippet. */
    private function plainTextBody(array $payload, string $snippet): string
    {
        $plain = $this->findPart($payload, 'text/plain');
        if ($plain !== null) {
            return trim($plain);
        }

        $html = $this->findPart($payload, 'text/html');
        if ($html !== null) {
            return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
        }

        return trim($snippet);
    }

    /** Depth-first hunt for a MIME part's base64url-decoded body. */
    private function findPart(array $part, string $mimeType): ?string
    {
        if (($part['mimeType'] ?? '') === $mimeType && ! empty($part['body']['data'])) {
            $decoded = base64_decode(strtr((string) $part['body']['data'], '-_', '+/'));

            return $decoded === false ? null : $decoded;
        }

        foreach ((array) ($part['parts'] ?? []) as $child) {
            $found = $this->findPart((array) $child, $mimeType);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
