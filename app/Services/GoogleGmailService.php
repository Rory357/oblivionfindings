<?php

namespace App\Services;

use App\Contracts\CalendarOAuthToken;
use App\Domain\It\Data\ItEmailAttachment;
use App\Domain\It\Exceptions\ItInboundContentException;
use App\Domain\It\Services\ItEmailAttachments;
use App\Domain\It\Services\ItEmailContent;
use App\Domain\It\Services\ItEmailHeaders;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\MailboxMessagePage;
use App\Services\Integration\MailboxProviderHttp;
use App\Services\Integration\MailboxResponseBody;
use DateTimeInterface;

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
        return MailboxProviderHttp::client($this->token, 'google');
    }

    /** Submit once; a missing acknowledgement is uncertain and must not trigger a blind resend. */
    public function sendMimeMail(string $mime): string
    {
        $response = MailboxProviderHttp::send(fn () => $this->client()->post('/users/me/messages/send', [
            'raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '='),
        ]));
        if ($response->status() !== 200) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $body = MailboxProviderHttp::object($response);
        $id = $body['id'] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 255 || preg_match('/[\x00-\x20\x7f]/', $id)) {
            throw new MailboxProviderFailure('invalid_response');
        }

        return $id;
    }

    /**
     * Unread inbox messages, normalised to the shape
     * InboundEmailIngestor::ingest() consumes plus `remote_id` for markRead().
     *
     * @return array<int, array{remote_id:string,from:string,subject:?string,text:string,message_id:?string,in_reply_to:?string}>
     */
    public function listUnreadMessages(string $mailbox = 'me', int $limit = 25): array
    {
        $page = $this->discoverUnread($mailbox, null, null, $limit);

        return array_map(fn (string $id) => $this->readMessage($mailbox, $id), $page->remoteIds);
    }

    public function discoverUnread(string $mailbox, ?DateTimeInterface $before, ?string $continuation = null, int $limit = 100): MailboxMessagePage
    {
        if ($limit < 1 || $limit > 100 || ($continuation !== null && (strlen($continuation) > 16384 || $continuation === ''))) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $list = MailboxProviderHttp::send(fn () => $this->client()->get('/users/me/messages', [
            'q' => 'is:unread in:inbox'.($before ? ' before:'.$before->getTimestamp() : ''),
            'maxResults' => $limit,
            ...($continuation !== null ? ['pageToken' => $continuation] : []),
        ]));
        $body = MailboxProviderHttp::object($list);
        $stubs = $body['messages'] ?? [];
        if (! is_array($stubs) || ! array_is_list($stubs)) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $ids = [];
        foreach ($stubs as $stub) {
            if (! is_array($stub) || ! is_string($stub['id'] ?? null) || $stub['id'] === '') {
                throw new MailboxProviderFailure('invalid_response');
            }
            $ids[] = $stub['id'];
        }
        $next = $body['nextPageToken'] ?? null;
        if ($next !== null && ! is_string($next)) {
            throw new MailboxProviderFailure('invalid_response');
        }

        return new MailboxMessagePage($ids, $next);
    }

    /** Remove the UNREAD label so the next poll doesn't re-ingest the message. */
    public function markRead(string $mailbox, string $messageId): bool
    {
        MailboxProviderHttp::send(fn () => $this->client()
            ->post('/users/me/messages/'.rawurlencode($messageId).'/modify', [
                'removeLabelIds' => ['UNREAD'],
            ]));

        return true;
    }

    /**
     * A failed or malformed detail is a failed read, never a silently dropped message.
     *
     * @return array{remote_id:string,from:string,subject:?string,text:string,message_id:?string,in_reply_to:?string}
     */
    public function readMessage(string $mailbox, string $id, bool $includeAttachments = false): array
    {
        if ($id === '') {
            throw new MailboxProviderFailure('invalid_response');
        }

        $response = MailboxProviderHttp::send(fn () => MailboxProviderHttp::client($this->token, 'google',
            $includeAttachments ? ItEmailAttachments::MESSAGE_RESPONSE_BYTES : MailboxResponseBody::DEFAULT_LIMIT)
            ->get('/users/me/messages/'.rawurlencode($id), ['format' => 'full']));
        $body = MailboxProviderHttp::object($response);
        if (($body['id'] ?? null) !== $id || ! is_array($body['payload'] ?? null) || ! is_array($body['payload']['headers'] ?? null)) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $payload = $body['payload'];
        $parser = new ItEmailHeaders;
        $headers = $parser->read($payload['headers']);
        $header = fn (string $name): ?string => $headers[$name] ?? null;
        $content = new ItEmailContent;
        $content->assertHumanMessage($headers, is_string($payload['mimeType'] ?? null) ? $payload['mimeType'] : null);
        $from = $parser->sender($header('from'));

        return [
            'remote_id' => $id,
            'from' => $from,
            'subject' => $header('subject'),
            'text' => $content->gmail($payload, fn (string $attachmentId): array => MailboxProviderHttp::object(
                MailboxProviderHttp::send(fn () => $this->client()->get('/users/me/messages/'.rawurlencode($id).'/attachments/'.rawurlencode($attachmentId)))
            )),
            'message_id' => $header('message-id'),
            'in_reply_to' => $header('in-reply-to'),
            'references' => $header('references'),
            ...($includeAttachments ? ['attachments' => (new ItEmailAttachments)->gmail($payload)] : []),
        ];
    }

    /** Unscanned bytes: caller must use canonical private staging and scan policy. */
    public function readAttachmentContents(string $mailbox, string $messageId, ItEmailAttachment $file): string
    {
        if ($file->provider !== 'google' || $messageId === '' || strlen($messageId) > 4096 || preg_match('/[\x00-\x20\x7f]/', $messageId)) {
            throw new ItInboundContentException('invalid_attachment_metadata');
        }

        return (new ItEmailAttachments)->contents($file, fn (string $id): array => MailboxProviderHttp::object(
            MailboxProviderHttp::send(fn () => MailboxProviderHttp::client($this->token, 'google', ItEmailAttachments::FILE_RESPONSE_BYTES)
                ->get('/users/me/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($id)))
        ));
    }
}
