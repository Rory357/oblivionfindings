<?php

/** Disposable-browser transport outcomes only; never loaded by application code. */

use App\Domain\It\Services\ItEmailMessageIdentifiers;
use App\Mail\MailSubmissionRejected;
use App\Models\ItEmailDelivery;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

function w12BrowserInstallDeliveryTransport(array $context): void
{
    w06BrowserRequire(($context['mailbox_fixtures'] ?? false)
        && $context['database'] === W06_BROWSER_DATABASE_PREFIX.$context['token']
        && config('mail.default') === 'array', 'Synthetic delivery requires the owned local capture environment.');
    Mail::mailer('array')->setSymfonyTransport(new class($context) extends ArrayTransport
    {
        public function __construct(private readonly array $context)
        {
            parent::__construct();
        }

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            if (! $message instanceof Email) {
                return parent::send($message, $envelope);
            }
            $parser = new ItEmailMessageIdentifiers;
            $id = $parser->messageId($message->getHeaders()->get('Message-ID')?->getBodyAsString());
            $delivery = $id === null ? null : ItEmailDelivery::query()->with('comment', 'ticket')
                ->where('rfc_message_id_hash', $parser->hash($id))->first();
            $prefix = 'W12 '.$this->context['token'].' browser ';
            $body = $delivery?->comment?->body;
            if (! $delivery || $delivery->notification_type !== 'ticket_replied' || ! is_string($body)
                || ! in_array($body, [$prefix.'partial delivery.', $prefix.'uncertain delivery.'], true)) {
                return parent::send($message, $envelope);
            }
            w06BrowserRequire($delivery->status === 'sending' && ! $delivery->comment->is_internal
                && $delivery->ticket?->title === 'W06 '.$this->context['token'].' public_workspace'
                && count($message->getTo()) === 1 && $message->getTo()[0]->getAddress() === $delivery->recipient_email
                && in_array($delivery->recipient_email, ['w06-tech@demo.test', 'w06-cover@demo.test'], true),
                'Only the exact public synthetic ticket and its two approved fixture recipients may use delivery outcomes.');
            $path = $this->context['root'].'/delivery-provider-state.json';
            w06BrowserRequire(is_file($path) && ! is_link($path)
                && w06BrowserPath((string) realpath($path)) === w06BrowserPath($path), 'Owned delivery state is unavailable.');
            $handle = fopen($path, 'r+');
            w06BrowserRequire($handle !== false && flock($handle, LOCK_EX), 'Could not lock synthetic delivery evidence.');
            try {
                $state = json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
                w06BrowserRequire(($state['token'] ?? null) === $this->context['token'] && ($state['synthetic_only'] ?? false) === true,
                    'Synthetic delivery identity changed.');
                $attempt = $state['attempts'][(string) $delivery->id] = ($state['attempts'][(string) $delivery->id] ?? 0) + 1;
                w06BrowserRequire($attempt === 1, 'A recorded synthetic attempt was submitted more than once.');
                $outcome = $delivery->recipient_email === 'w06-cover@demo.test' && $delivery->retry_of_delivery_id === null
                    ? ($body === $prefix.'partial delivery.' ? 'rejected' : 'uncertain') : 'accepted';
                $state['outcomes'][(string) $delivery->id] = $outcome;
                $json = json_encode($state, JSON_THROW_ON_ERROR);
                rewind($handle);
                w06BrowserRequire(ftruncate($handle, 0) && fwrite($handle, $json) === strlen($json) && fflush($handle),
                    'Synthetic submission evidence was not persisted.');
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            if ($outcome === 'rejected') {
                throw new MailSubmissionRejected('Synthetic browser provider rejected this recipient. No external mail was sent.');
            }
            if ($outcome === 'uncertain') {
                throw new TransportException('Synthetic browser acknowledgement was lost. No external mail was sent.');
            }

            return parent::send($message, $envelope);
        }
    });
}
