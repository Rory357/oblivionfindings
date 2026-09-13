<?php

namespace App\Mail;

use App\Models\ItMailboxConnection;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\MessageConverter;

/** Bind a transport to one approved canonical mailbox configuration, never a copied token. */
final class SupportMailboxSender
{
    private readonly int $id;

    private readonly int $version;

    private readonly string $scopeHash;

    public function __construct(ItMailboxConnection $connection)
    {
        $this->id = (int) $connection->getKey();
        $this->version = $connection->configuration_version;
        $this->scopeHash = $connection->mailboxScopeHash();
    }

    public function resolve(SentMessage $message, string $provider): ItMailboxConnection
    {
        $connection = ItMailboxConnection::query()->useWritePdo()->find($this->id);
        if (! $connection || ! $connection->isConnected() || $connection->provider !== $provider
            || $connection->configuration_version !== $this->version
            || ! hash_equals($this->scopeHash, $connection->mailboxScopeHash())) {
            throw new MailNotSubmitted('The support mailbox changed or disconnected. Review the delivery settings before sending.');
        }

        if (($issue = self::configurationIssue($connection, $provider)) !== null) {
            throw new MailNotSubmitted($issue);
        }
        $account = strtolower(trim((string) $connection->account_email));
        $mailbox = strtolower(trim((string) $connection->mailboxEmail()));
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $addresses = static fn (array $values): array => array_map(
            static fn ($address): string => strtolower($address->getAddress()), $values,
        );
        if ($addresses($email->getFrom()) !== [$mailbox] || $addresses($email->getReplyTo()) !== [$mailbox]
            || strtolower($message->getEnvelope()->getSender()->getAddress()) !== $mailbox
            || ($email->getSender() && ! in_array(strtolower($email->getSender()->getAddress()), [$account, $mailbox], true))) {
            throw new MailNotSubmitted('The message sender and reply address must match the approved support mailbox.');
        }

        return $connection;
    }

    /** Recorded eligibility only; the provider still enforces actual sending rights. */
    public static function configurationIssue(ItMailboxConnection $connection, string $provider): ?string
    {
        if (! in_array($provider, ['google', 'microsoft'], true) || $connection->provider !== $provider
            || ! $connection->isConnected()) {
            return 'Select a connected support mailbox for the chosen provider.';
        }
        $account = strtolower(trim((string) $connection->account_email));
        $mailbox = strtolower(trim((string) $connection->mailboxEmail()));
        $scopes = $connection->scopes ?? [];
        $allowed = $provider === 'google'
            ? ['https://mail.google.com/', 'https://www.googleapis.com/auth/gmail.modify',
                'https://www.googleapis.com/auth/gmail.compose', 'https://www.googleapis.com/auth/gmail.send']
            : ($account === $mailbox ? ['Mail.Send', 'https://graph.microsoft.com/Mail.Send',
                'Mail.Send.Shared', 'https://graph.microsoft.com/Mail.Send.Shared']
                : ['Mail.Send.Shared', 'https://graph.microsoft.com/Mail.Send.Shared']);
        if (! filter_var($account, FILTER_VALIDATE_EMAIL) || ! filter_var($mailbox, FILTER_VALIDATE_EMAIL)
            || ($provider === 'google' && $account !== $mailbox)
            || ! is_array($scopes) || array_intersect($allowed, $scopes) === []) {
            return 'The approved support account does not have the required sending configuration or consent.';
        }

        return null;
    }
}
