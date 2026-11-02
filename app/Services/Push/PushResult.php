<?php

namespace App\Services\Push;

/**
 * What a push service said about one message, in the terms Oblivion acts on.
 *
 * Accepted means the push service took the message, not that a device showed it.
 */
enum PushResult: string
{
    case Accepted = 'accepted';

    /** The subscription is gone (404 or 410): stop using it. */
    case Expired = 'expired';

    /** No answer, a timeout, rate limiting or a push-service fault: try again later. */
    case Retry = 'retry';

    /** Oblivion's own push configuration is missing or refused (401, 403): fixable without losing the notice. */
    case Unavailable = 'unavailable';

    /** The message or subscription can't be delivered as it is. */
    case Rejected = 'rejected';

    /** Maps an RFC 8030 response status; null means no response arrived. */
    public static function fromStatus(?int $status): self
    {
        if ($status === null || $status === 408 || $status === 429 || $status >= 500) {
            return self::Retry;
        }
        if ($status >= 200 && $status < 300) {
            return self::Accepted;
        }
        if ($status === 404 || $status === 410) {
            return self::Expired;
        }
        if ($status === 401 || $status === 403) {
            return self::Unavailable;
        }

        return self::Rejected;
    }
}
