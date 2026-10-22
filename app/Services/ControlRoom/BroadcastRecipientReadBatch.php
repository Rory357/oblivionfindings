<?php

namespace App\Services\ControlRoom;

use DateTimeImmutable;
use Illuminate\Support\Collection;

/** Opaque source-owned original batch. Construction alone never creates an admitted batch. */
final class BroadcastRecipientReadBatch
{
    public function __construct(public readonly BroadcastRecipientReadContext $context) {}
    public function records(): Collection { return $this->context->owner->batchRecords($this); }
    public function exportReferences(): array { return $this->context->owner->batchReferences($this); }
    public function expectReadAt(DateTimeImmutable $serverAt): void { $this->context->owner->expectReadAt($this, $serverAt); }
}
