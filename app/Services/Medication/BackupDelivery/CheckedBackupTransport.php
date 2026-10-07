<?php

namespace App\Services\Medication\BackupDelivery;

use App\Mail\MailNotSubmitted;
use Closure;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\MessageConverter;

/** Recheck after message listeners, immediately before the underlying transport. */
final class CheckedBackupTransport extends AbstractTransport
{
    private int $recipientIndex = 0;

    public function __construct(private readonly TransportInterface $inner, private readonly Closure $check, private readonly string $sender, private readonly array $recipients)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        ($this->check)();
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $addresses = static fn (array $values): array => array_map(static fn ($value): string => strtolower($value->getAddress()), $values);
        $to = $addresses($email->getTo());
        if ($addresses($email->getFrom()) !== [strtolower($this->sender)]
            || $addresses($email->getReplyTo()) !== [strtolower($this->sender)]
            || strtolower($message->getEnvelope()->getSender()->getAddress()) !== strtolower($this->sender)
            || $email->getCc() !== [] || $email->getBcc() !== [] || count($to) !== 1
            || $to !== [strtolower($this->recipients[$this->recipientIndex] ?? '')]
            || $addresses($message->getEnvelope()->getRecipients()) !== $to) {
            throw new MailNotSubmitted('backup_email_address_changed');
        }
        $sent = $this->inner->send($message->getOriginalMessage(), $message->getEnvelope());
        if ($sent === null) {
            throw new MailNotSubmitted('backup_email_not_submitted');
        }
        $message->setMessageId($sent->getMessageId());
        $this->recipientIndex++;
    }

    public function __toString(): string
    {
        return 'emar-backup';
    }
}
