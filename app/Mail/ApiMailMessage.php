<?php

namespace App\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\MessageConverter;

/** Preserve the complete message for APIs that take recipients from MIME headers. */
final class ApiMailMessage
{
    public static function mime(SentMessage $message, string $provider): string
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $addresses = static fn (array $values): array => array_values(array_unique(array_map(
            static fn ($address): string => strtolower($address->getAddress()), $values,
        )));
        $recipients = $addresses([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]);
        $envelope = $addresses($message->getEnvelope()->getRecipients());
        sort($recipients);
        sort($envelope);
        if ($recipients === [] || $recipients !== $envelope) {
            throw new MailNotSubmitted($provider.' mail recipients do not match the delivery envelope.');
        }

        $headers = $email->getPreparedHeaders();
        // Symfony strips Bcc for SMTP, where recipients travel in the envelope.
        // These APIs have no separate recipient envelope.
        if ($email->getBcc() !== []) {
            $headers->addMailboxListHeader('Bcc', $email->getBcc());
        }
        $headers->remove('Message-ID');
        $headers->addIdHeader('Message-ID', $message->getMessageId());

        return $headers->toString().$email->getBody()->toString();
    }
}
