<?php

namespace App\Domain\It\Services;

use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ItTicketMergeService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
    ) {}

    public function merge(
        ItTicket $source,
        ItTicket $target,
        User $actor,
        string $reason,
    ): ItTicket {
        return DB::transaction(function () use ($source, $target, $actor, $reason): ItTicket {
            $sourceId = (int) $source->getKey();
            $targetId = (int) $target->getKey();
            $ids = array_values(array_unique([$sourceId, $targetId]));
            sort($ids, SORT_NUMERIC);

            // A stable ascending lock order prevents opposite-direction merge
            // requests from deadlocking or creating a merge cycle.
            $locked = ItTicket::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $locked->get($sourceId);
            $target = $locked->get($targetId);
            if (! $source instanceof ItTicket || ! $target instanceof ItTicket) {
                throw (new ModelNotFoundException)->setModel(ItTicket::class, $ids);
            }

            $this->guard($source, $target, $actor);

            $source->comments()->update(['ticket_id' => $target->id]);

            $watcherIds = $source->watchers()->pluck('users.id')->all();
            if ($watcherIds !== []) {
                $target->watchers()->syncWithoutDetaching($watcherIds);
                $source->watchers()->detach();
            }

            $source->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'merged_into_ticket_id' => $target->id,
                'merged_at' => now(),
            ])->save();

            ItTicketEvent::record($source, 'merged', $actor->id, [
                'direction' => 'into',
                'target_id' => $target->id,
                'target_reference' => $target->reference,
                'reason' => $reason,
            ]);
            ItTicketEvent::record($target, 'merged', $actor->id, [
                'direction' => 'from',
                'source_id' => $source->id,
                'source_reference' => $source->reference,
                'reason' => $reason,
            ]);

            AuditLogger::logOrFail('it.ticket.merged', $source, [
                'actor_id' => $actor->id,
                'target_ticket_id' => $target->id,
                'reason_recorded' => true,
                'application_scope' => 'single_application',
            ]);

            return $target->refresh();
        });
    }

    public function sharesConversationAudience(ItTicket $source, ItTicket $target): bool
    {
        $sourceRequester = (int) $source->requester_user_id;
        $targetRequester = (int) $target->requester_user_id;
        $sourceSubject = (int) ($source->requested_for_user_id ?: $sourceRequester);
        $targetSubject = (int) ($target->requested_for_user_id ?: $targetRequester);

        return $sourceRequester > 0
            && $sourceRequester === $targetRequester
            && $sourceSubject > 0
            && $sourceSubject === $targetSubject;
    }

    private function guard(ItTicket $source, ItTicket $target, User $actor): void
    {
        if (! $actor->canDo('it.manage')) {
            throw new AuthorizationException('You are not allowed to merge tickets.');
        }
        if (! $this->workAccess->canWork($actor, $source)
            || ! $this->workAccess->canWork($actor, $target)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class, [$source->id, $target->id]);
        }
        if ((int) $source->id === (int) $target->id) {
            throw new DomainException('A ticket cannot be merged into itself.');
        }
        if ($source->merged_into_ticket_id !== null) {
            throw new DomainException('This ticket has already been merged.');
        }
        if ($target->merged_into_ticket_id !== null) {
            throw new DomainException('The target ticket has already been merged.');
        }
        if ($source->status === 'closed') {
            throw new DomainException('A closed ticket cannot be merged.');
        }
        if ($target->status === 'closed') {
            throw new DomainException('The target ticket is closed. Choose an open ticket.');
        }
        if (! $this->sharesConversationAudience($source, $target)) {
            throw new DomainException('Tickets with different requesters cannot be merged because their conversations are private.');
        }
    }
}
