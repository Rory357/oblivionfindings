<?php

namespace App\Services\Integration;

use App\Services\Integration\Exceptions\MailboxProviderFailure;

/** One bounded provider discovery page. No message bodies or credentials. */
final readonly class MailboxMessagePage
{
    /** @param list<string> $remoteIds */
    public function __construct(public array $remoteIds, public ?string $continuation)
    {
        if (! array_is_list($remoteIds) || count($remoteIds) > 100
            || ($continuation !== null && ($continuation === '' || strlen($continuation) > 16384))) {
            throw new MailboxProviderFailure('invalid_response');
        }
        foreach ($remoteIds as $id) {
            if (! is_string($id) || $id === '' || strlen($id) > 2048) {
                throw new MailboxProviderFailure('invalid_response');
            }
        }
    }
}
