<?php

namespace App\Mail;

use App\Models\Identity;
use App\Models\ItMailboxConnection;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use App\Services\MicrosoftGraphService;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class MicrosoftGraphTransport extends AbstractTransport
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
            throw new MailNotSubmitted('Microsoft mail identity is not configured.');
        }
        if ($this->identity->provider !== 'microsoft') {
            throw new MailNotSubmitted('The configured mail identity is not a Microsoft account.');
        }

        $mime = ApiMailMessage::mime($message, 'Microsoft');
        $identity = $this->supportSender?->resolve($message, 'microsoft') ?? $this->identity;
        $service = new MicrosoftGraphService($identity);
        try {
            $service->sendMimeMail($mime);
        } catch (MailboxProviderFailure|ProviderRateLimited $exception) {
            $reason = $exception instanceof MailboxProviderFailure ? $exception->reason : 'rate_limited';
            $guidance = match ($reason) {
                'configuration' => 'Microsoft mail OAuth configuration is incomplete. Review the provider settings.',
                'authentication' => 'Microsoft mail authorization expired or was revoked. Reconnect the approved account.',
                'permission' => 'Microsoft denied mail submission. Review the approved sending permissions.',
                'rate_limited' => 'Microsoft delayed mail submission. Review the delivery record before retrying.',
                'rejected' => 'Microsoft rejected mail submission. Review the delivery record before retrying.',
                default => 'Microsoft mail acceptance was not confirmed. Reconcile the delivery outcome before retrying.',
            };
            throw in_array($reason, ['configuration', 'authentication', 'permission', 'rate_limited', 'rejected'], true)
                ? new MailSubmissionRejected($guidance) : new TransportException($guidance);
        }
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }
}
