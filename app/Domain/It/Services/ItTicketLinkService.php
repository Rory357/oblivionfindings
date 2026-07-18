<?php

namespace App\Domain\It\Services;

use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final class ItTicketLinkService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function link(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        array $context = [],
        ?int $actorUserId = null,
    ): ItTicketLink {
        $targetTenantId = $this->targetTenantId($target);

        if ($targetTenantId === null) {
            throw new DomainException('The linked record tenant could not be resolved.');
        }

        if ($targetTenantId !== (int) $ticket->tenant_id) {
            throw new DomainException('Ticket links must remain in the same tenant.');
        }

        return $ticket->links()->firstOrCreate([
            'relationship' => $relationship,
            'linkable_type' => $target->getMorphClass(),
            'linkable_id' => $target->getKey(),
        ], [
            'tenant_id' => $ticket->tenant_id,
            'context' => $context,
            'created_by_user_id' => $actorUserId,
        ]);
    }

    private function targetTenantId(Model $target): ?int
    {
        if (is_numeric($target->getAttribute('tenant_id'))) {
            return (int) $target->getAttribute('tenant_id');
        }

        if ($target instanceof ControlRoomAlert) {
            $target->loadMissing(['site:id,tenant_id', 'device.canonicalDevice:id,tenant_id']);

            return is_numeric($target->site?->tenant_id)
                ? (int) $target->site->tenant_id
                : (is_numeric($target->device?->canonicalDevice?->tenant_id)
                    ? (int) $target->device->canonicalDevice->tenant_id
                    : null);
        }

        return null;
    }
}
