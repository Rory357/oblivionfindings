<?php

namespace App\Domain\It\Exceptions;

use RuntimeException;

/** Retryable local storage/scanner failure, with no file content or executable output. */
final class ItInboundAttachmentUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'scanner_unavailable', 'scanner_failed', 'scanner_timeout' => 'Attachment scanning could not complete. Email remains pending. Check the scanner service, then retry after the recorded delay.',
            'file_unavailable', 'attachment_storage_unavailable', 'attachment_storage_changed' => 'An email attachment could not be read or stored safely. Email remains pending. Check private storage, then retry after the recorded delay.',
            'attachment_cleanup_pending' => 'Email was received, but temporary file cleanup is pending. Processing will retry cleanup before acknowledgement.',
            default => 'Email attachments could not be prepared safely. Processing will retry.',
        });
    }
}
