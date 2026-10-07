<?php

namespace App\Services\Medication\BackupDelivery;

use App\Mail\ApiMailMessage;
use App\Mail\MailSubmissionRejected;
use App\Mail\SupportMailboxSender;
use App\Models\ItMailboxConnection;
use App\Services\GoogleGmailService;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use App\Services\MicrosoftGraphService;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** Canonical OAuth/MIME boundaries with refresh excluded from clinical transactions. */
final class PreparedBackupOAuthTransport extends AbstractTransport
{
    private readonly SupportMailboxSender $sender;

    public function __construct(ItMailboxConnection $connection, private readonly string $provider)
    {
        parent::__construct();
        $this->sender = new SupportMailboxSender($connection, currentRead: true);
    }

    protected function doSend(SentMessage $message): void
    {
        $mime = ApiMailMessage::mime($message, $this->provider);
        $token = new PreparedBackupMailboxToken($this->sender->resolve($message, $this->provider));
        try {
            if ($this->provider === 'google') {
                $message->setMessageId((new GoogleGmailService($token))->sendMimeMail($mime));
            } else {
                (new MicrosoftGraphService($token))->sendMimeMail($mime);
            }
        } catch (MailboxProviderFailure|ProviderRateLimited $failure) {
            $reason = $failure instanceof MailboxProviderFailure ? $failure->reason : 'rate_limited';
            throw in_array($reason, ['configuration', 'authentication', 'permission', 'rate_limited', 'rejected'], true)
                ? new MailSubmissionRejected('backup_provider_rejected') : new TransportException('backup_provider_acceptance_unknown');
        }
    }

    public function __toString(): string
    {
        return 'emar-backup-oauth';
    }
}
