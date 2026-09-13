<?php

namespace App\Domain\It\Presenters;

use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Current delivery evidence from the canonical outbox, without recipient/private transport data. */
final class ItTicketCommentDeliveryPresenter
{
    public function __construct(private readonly ItWorkAccessService $access) {}

    /** @return array<int, array<string, mixed>> */
    public function presentMany(ItTicket $ticket, User $viewer, Collection $comments): array
    {
        $actor = User::query()->find($viewer->id);
        $canonical = ItTicket::query()->find($ticket->id);
        if (! $actor || ! $actor->approved_at || ! $canonical || ! $this->access->canView($actor, $canonical)) {
            return [];
        }
        $canWork = $this->access->canWork($actor, $canonical);
        // A participant gets delivery evidence for their own public submissions;
        // another person's recipient/transport activity is for the IT workspace.
        $public = ItTicketComment::query()->where('ticket_id', $canonical->id)
            ->whereKey($comments->pluck('id'))->where('is_internal', false)
            ->when(! $canWork, fn ($query) => $query->where('author_user_id', $actor->id))
            ->get(['id']);
        if ($public->isEmpty()) {
            return [];
        }
        $ids = $public->modelKeys();
        $trackingReady = Schema::hasColumn('it_email_deliveries', 'it_ticket_comment_id');
        // Comments may have moved in a governed merge. Their canonical identity,
        // not the outbox's historical ticket FK, identifies the same attempts.
        $statuses = $trackingReady ? ItEmailDelivery::query()->whereIn('it_ticket_comment_id', $ids)
            ->whereDoesntHave('retryAttempt')
            ->selectRaw('it_ticket_comment_id, status, COUNT(*) AS aggregate')
            ->groupBy('it_ticket_comment_id', 'status')->get()->groupBy('it_ticket_comment_id') : collect();
        $receipted = Schema::hasColumn('it_ticket_command_receipts', 'it_ticket_comment_id')
            ? ItTicketCommandReceipt::query()->whereIn('it_ticket_comment_id', $ids)
                ->where('operation', ItTicketCommandReceipt::COMMENT_OPERATION)->whereNotNull('committed_at')
                ->pluck('it_ticket_comment_id')->flip() : collect();
        $reviewable = $trackingReady && $canWork
            ? app(ItEmailDeliveryService::class)->visibleQuery($actor)->whereIn('it_ticket_comment_id', $ids)
                ->distinct()->pluck('it_ticket_comment_id')->flip() : collect();
        $checkedAt = now()->toIso8601String();

        return $public->mapWithKeys(function (ItTicketComment $comment) use ($statuses, $receipted, $reviewable, $trackingReady, $checkedAt): array {
            $rows = $statuses->get($comment->id, collect());
            $supported = $rows->every(fn ($row) => in_array($row->status, ItEmailDelivery::STATUSES, true));
            $recorded = $trackingReady && $supported && ($rows->isNotEmpty() || $receipted->has($comment->id));

            return [$comment->id => [
                'tracking' => $recorded ? 'recorded' : 'unrecorded',
                'requested' => $rows->isNotEmpty(),
                'attempt_statuses' => (object) ($supported ? $rows->mapWithKeys(fn ($row) => [$row->status => (int) $row->aggregate])->all() : []),
                'checked_at' => $checkedAt,
                'review_url' => $reviewable->has($comment->id)
                    ? '/it/setup?tab=operations&delivery_comment_id='.$comment->id : null,
            ]];
        })->all();
    }
}
