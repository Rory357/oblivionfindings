<?php

namespace App\Mail;

use App\Models\Identity;
use App\Models\ItMailboxConnection;
use App\Services\GoogleGmailService;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class GoogleGmailTransport extends AbstractTransport
{
    private ?SupportMailboxSender $supportSender;

    public function __construct(private Identity|ItMailboxConnection|null $identity = null)
    {
        parent::__construct();
        $this->supportSender = $identity instanceof ItMailboxConnection ? new SupportMailboxSender($identity) : null;
    }

    protected function doSend(SentMessage $message): void
    {
        if (! $this->identity) {
            throw new MailNotSubmitted('Google mail identity is not configured.');
        }
        if ($this->identity->provider !== 'google') {
            throw new MailNotSubmitted('The configured mail identity is not a Google account.');
        }
        $mime = ApiMailMessage::mime($message, 'Google');
        $identity = $this->supportSender?->resolve($message, 'google') ?? $this->identity;
        try {
            $providerId = (new GoogleGmailService($identity))->sendMimeMail($mime);
        } catch (MailboxProviderFailure|ProviderRateLimited $exception) {
            $reason = $exception instanceof MailboxProviderFailure ? $exception->reason : 'rate_limited';
            $guidance = match ($reason) {
                'configuration' => 'Google mail OAuth configuration is incomplete. Review the provider settings.',
                'authentication' => 'Google mail authorization expired or was revoked. Reconnect the approved account.',
                'permission' => 'Google denied mail submission. Review the approved sending permissions.',
                'rate_limited' => 'Google delayed mail submission. Review the delivery record before retrying.',
                'rejected' => 'Google rejected mail submission. Review the delivery record before retrying.',
                default => 'Google mail acceptance was not confirmed. Reconcile the delivery outcome before retrying.',
            };
            throw in_array($reason, ['configuration', 'authentication', 'permission', 'rate_limited', 'rejected'], true)
                ? new MailSubmissionRejected($guidance) : new TransportException($guidance);
        }
        // Symfony's transport ID is separate from the already recorded RFC Message-ID.
        $message->setMessageId($providerId);
    }

    public function __toString(): string
    {
        return 'google-gmail';
    }
}
