<?php

namespace App\Domain\It\Contracts;

interface TracksItEmailDelivery
{
    /** @return array{tenant_id: int, ticket_id?: int|null, provisioning_request_id?: int|null, comment_id?: int|null, audience?: string|null, type: string, subject: string, retry_context?: array<string, mixed>} */
    public function itEmailDeliveryContext(): array;
}
