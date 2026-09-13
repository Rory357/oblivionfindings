<?php

namespace App\Domain\It\Services;

use App\Mail\GoogleGmailTransport;
use App\Mail\MailNotSubmitted;
use App\Mail\MicrosoftGraphTransport;
use App\Services\EmailConfiguration;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/** Build per-send mailers without changing the application's shared mail configuration. */
final class ItOutboundMailer
{
    public function __construct(private readonly EmailConfiguration $settings) {}

    public function captureMode(): ?string
    {
        $driver = (string) config('mail.default');

        return in_array($driver, ['array', 'log'], true) ? $driver : null;
    }

    public function make(array $configuration): Mailer
    {
        try {
            return $this->build($configuration);
        } catch (\Throwable) {
            // Construction never submits mail. Do not expose SMTP credentials in exceptions.
            throw new MailNotSubmitted('The outgoing email settings could not be prepared. Review the saved provider and support connection.');
        }
    }

    private function build(array $configuration): Mailer
    {
        $connection = $this->settings->supportConnection($configuration);
        $address = $connection->mailboxEmail();
        $capture = $this->captureMode();
        // Explicit local sinks remain effective even when a saved provider is selected.
        $transport = $capture !== null ? Mail::mailer($capture)->getSymfonyTransport() : match ($configuration['provider']) {
            'google' => new GoogleGmailTransport($connection),
            'microsoft' => new MicrosoftGraphTransport($connection),
            'smtp' => app('mail.manager')->createSymfonyTransport([
                'transport' => 'smtp', 'scheme' => $configuration['smtp_encryption'] === 'ssl' ? 'smtps' : 'smtp',
                'host' => $configuration['smtp_host'], 'port' => $configuration['smtp_port'],
                'username' => $configuration['smtp_username'], 'password' => $this->settings->smtpPassword($configuration['configuration_version']),
                'auto_tls' => $configuration['smtp_encryption'] !== 'none',
                'require_tls' => $configuration['smtp_encryption'] === 'tls', 'timeout' => 20,
            ]),
            default => throw new \DomainException('The saved outgoing provider is not supported.'),
        };
        $mailer = new Mailer('it-support', app('view'), $transport, app('events'));
        $mailer->alwaysFrom($address, $configuration['from_name']);
        $mailer->alwaysReplyTo($address, $configuration['from_name']);

        return $mailer;
    }
}
