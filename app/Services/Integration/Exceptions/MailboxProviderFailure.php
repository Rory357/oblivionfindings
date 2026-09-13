<?php

namespace App\Services\Integration\Exceptions;

use RuntimeException;

/** Safe failure vocabulary: never retain provider bodies, URLs or token exceptions. */
final class MailboxProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'configuration' => 'Mailbox OAuth client configuration is missing. Review the provider setup before retrying.',
            'authentication' => 'Mailbox authorization expired or was revoked. Reconnect the approved account.',
            'permission' => 'The provider denied mailbox access. Review the approved account and delegated permissions.',
            'unavailable' => 'The mailbox provider is temporarily unavailable. The next permitted poll can retry.',
            'invalid_response' => 'The mailbox provider returned an incomplete or invalid response. No successful poll was recorded.',
            'response_too_large' => 'The mailbox response exceeded the permitted download size. Review the affected message or provider response before retrying.',
            default => 'The mailbox provider rejected the request. Review the connection before retrying.',
        });
    }
}
