<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\It\Services\ItRemoteSupportAccess;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;

final class EndpointRemoteAccess
{
    public function actor(int $actorId, Device $device, string $permission): User
    {
        $actor = app(ObliStaffAccess::class)->actor($actorId);
        abort_unless(app(ItRemoteSupportAccess::class)->canOpen($actor) && $actor->canDo($permission), 403);
        abort_unless(app(ItRemoteSupportAccess::class)->isComputer($device), 404);
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $device): void {
            $access = app(SecurityDevicesAccessService::class)->forCurrentEvidence($reads, (int) $device->id);
            $access->assertCanViewDevice($actor, $device);
            $access->assertCanManageActiveAssignment($actor, $device, true);
        });

        return $actor;
    }

    public function ticket(User $actor, Device $device, ?int $ticketId): void
    {
        if ($ticketId === null) {
            return;
        }
        $ticket = ItTicket::query()->lockForUpdate()->findOrFail($ticketId);
        abort_unless(! $ticket->isMerged() && app(ItWorkAccessService::class)->canWork($actor, $ticket, true), 404);
        abort_unless($ticket->links()->where('relationship', 'affected_device')
            ->where('linkable_type', $device->getMorphClass())->where('linkable_id', $device->id)
            ->lockForUpdate()->first(), 404);
    }
}
