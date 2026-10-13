<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItChange;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CommandChangeEligibilityService
{
    private ?CurrentAuthorizationReads $currentReads = null;

    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    public function forCurrentEvidence(CurrentAuthorizationReads $reads): self
    {
        $reads->assertActive();
        $current = new self($this->workAccess->forCurrentEvidence($reads));
        $current->currentReads = $reads;

        return $current;
    }

    /** @return Collection<int, ItChange> */
    public function eligibleFor(
        User $actor,
        Device $device,
        int $siteId,
        ?CarbonImmutable $at = null,
    ): Collection {
        if (! $actor->canDo('it.manage')) {
            return collect();
        }

        $at ??= CarbonImmutable::now('UTC')->startOfSecond();
        $deviceMorph = $device->getMorphClass();

        return ($this->currentReads ? $this->currentReads->query(ItChange::query()) : ItChange::query())
            ->with($this->currentReads ? ['ticket' => fn ($query) => $this->currentReads->query($query->getQuery()), 'ticket.approvals' => fn ($query) => $this->currentReads->query($query->getQuery())] : ['ticket.approvals'])
            ->whereNotNull('maintenance_starts_at')
            ->whereNotNull('maintenance_ends_at')
            ->where('maintenance_starts_at', '<=', $at)
            ->where('maintenance_ends_at', '>=', $at->addSeconds(30))
            ->whereHas('ticket', function ($ticket) use ($device, $deviceMorph, $siteId): void {
                $ticket->where('work_type', 'change')
                    ->where('site_id', $siteId)
                    ->whereIn('workflow_state', ['scheduled', 'implementing'])
                    ->whereHas('links', fn ($links) => $links
                        ->where('relationship', 'affected_device')
                        ->where('linkable_type', $deviceMorph)
                        ->where('linkable_id', $device->id));
            })
            ->orderBy('maintenance_ends_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (ItChange $change): bool => $this->passesEligibilityRules($change, $actor, $siteId, $at))
            ->values();
    }

    public function isEligible(
        int $changeId,
        User $actor,
        Device $device,
        int $siteId,
        ?CarbonImmutable $at = null,
    ): bool {
        return $this->eligibleFor($actor, $device, $siteId, $at)
            ->contains('id', $changeId);
    }

    public function assertEligible(
        int $changeId,
        User $actor,
        Device $device,
        int $siteId,
        ?CarbonImmutable $at = null,
    ): ItChange {
        $change = $this->eligibleFor($actor, $device, $siteId, $at)
            ->firstWhere('id', $changeId);
        if (! $change instanceof ItChange) {
            throw ValidationException::withMessages([
                'it_change_id' => 'Choose a current approved IT Change that is linked to this Device and Site.',
            ]);
        }

        return $change;
    }

    private function passesEligibilityRules(
        ItChange $change,
        User $actor,
        int $siteId,
        CarbonImmutable $at,
    ): bool {
        $ticket = $change->ticket;
        if (! $ticket instanceof ItTicket
            || ! $this->workAccess->canWork($actor, $ticket)
            || (int) $ticket->site_id !== $siteId
            || ! in_array($ticket->workflow_state, ['scheduled', 'implementing'], true)
            || $change->maintenance_starts_at === null
            || $change->maintenance_ends_at === null
            || $at->lessThan($change->maintenance_starts_at)
            || $at->greaterThan($change->maintenance_ends_at)
            || ($change->is_restricted && ! $actor->canDo('it.viewSensitive'))) {
            return false;
        }

        return ! $change->needsApproval() || ($this->currentReads
            ? $this->currentReads->query($ticket->approvals()->getQuery())->first()?->effectiveStatus()
            : $ticket->approvalState()) === 'approved';
    }
}
