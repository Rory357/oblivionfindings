<?php

namespace App\Services\Tasks\Providers;

use App\Domain\It\Services\ItTicketApprovalResponsibilityService;
use App\Domain\It\Services\ItTicketApprovalService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

/** Read-only pending decision projection; the canonical approval remains the only actionable record. */
final class ItApprovalTaskProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'it_approval';
    }

    public function label(): string
    {
        return 'IT approvals';
    }

    public function modelClass(): string
    {
        return ItTicketApproval::class;
    }

    public function canView(User $user): bool
    {
        return $user->approved_at !== null && $user->canDo('it.manage');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user) || ! app(ItTicketApprovalService::class)->storageReady()) {
            return [];
        }
        $responsibility = app(ItTicketApprovalResponsibilityService::class);
        $query = ItTicketApproval::query()->with('ticket.site:id,name')
            ->where('status', 'pending')->where(fn ($dates) => $dates->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when(isset($filters['id']), fn ($approvals) => $approvals->whereKey((int) $filters['id']))->orderBy('id');

        return app(TaskProviderAuthorization::class)->siteScoped($user, true, $query,
            fn ($approvals, User $actor) => $approvals->whereHas('ticket', function ($tickets) use ($actor): void {
                app(ItWorkAccessService::class)->applyWorkScope($tickets, $actor);
                $tickets->whereNull('merged_into_ticket_id')->whereIn('status', ItTicket::OPEN_STATUSES);
            }),
            function (ItTicketApproval $approval) use ($responsibility): TaskItem {
                $ticket = $approval->ticket;
                $owner = $responsibility->effectiveOwner($approval, $ticket);
                $person = $owner['user'];
                $covered = str_starts_with($owner['basis'], 'cover_');

                return new TaskItem(id: 'it_approval-'.$approval->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: $ticket->reference, title: 'Approval · '.$ticket->title, status: 'pending', bucket: TaskItem::BUCKET_OPEN,
                    severity: match ($ticket->priority) {
                        'urgent' => 'critical', 'high' => 'high', 'normal' => 'medium', default => 'low'
                    },
                    assignee: $person ? ['id' => (int) $person->id, 'name' => $person->name] : null,
                    site: $ticket->site ? ['id' => (int) $ticket->site->id, 'name' => $ticket->site->name] : null,
                    dueAt: $approval->expires_at?->toIso8601String(), createdAt: $approval->created_at?->toIso8601String(),
                    link: '/it/tickets/'.$ticket->id.'?tab=approvals#approval-'.$approval->id, type: 'IT approval', restricted: true,
                    sourceContext: $ticket->title, actionLabel: 'Review IT approval',
                    displayState: $person ? ($covered ? 'Awaiting cover approver' : 'Awaiting approver') : 'Approver assignment needs review',
                    actionHelp: $person ? 'Review the canonical request before recording a decision.' : 'No named eligible approver is currently available. Review the request and its responsibility.');
            });
    }
}
