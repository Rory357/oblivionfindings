<?php

namespace App\Services\Medication\PharmacyConnect;

final readonly class PharmacyTransportResult
{
    public function __construct(public string $state, public string $code, public ?int $httpStatus = null) {}

    /** Only statuses the real partner guarantees mean no order was accepted may be retried. */
    public static function fromHttp(int $status, array $definitiveFailures = []): self
    {
        if ($status >= 200 && $status < 300) {
            return new self('sent', 'transport_received', $status);
        }
        if ($status >= 400 && $status < 500 && ! in_array($status, [408, 409, 425, 429], true)
            && in_array($status, $definitiveFailures, true)) {
            return new self('failed', 'partner_declined_transport', $status);
        }

        return new self('unknown', 'delivery_not_confirmed', $status);
    }
}
