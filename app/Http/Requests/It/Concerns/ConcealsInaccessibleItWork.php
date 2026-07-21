<?php

namespace App\Http\Requests\It\Concerns;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItChange;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Conceal canonical IT parents before FormRequest validation can disclose a
 * route object's existence through a 403 or 422 response.
 */
trait ConcealsInaccessibleItWork
{
    protected function visibleTicketOrNotFound(): ItTicket
    {
        $ticket = $this->route('ticket');
        $user = $this->user();
        abort_unless(
            $ticket instanceof ItTicket
                && $user instanceof User
                && app(ItWorkAccessService::class)->canView($user, $ticket),
            404,
        );

        return $ticket;
    }

    protected function workableTicketOrNotFound(): ItTicket
    {
        $ticket = $this->route('ticket');
        $user = $this->user();
        abort_unless(
            $ticket instanceof ItTicket
                && $user instanceof User
                && app(ItWorkAccessService::class)->canWork($user, $ticket),
            404,
        );

        return $ticket;
    }

    protected function workableProblemOrNotFound(): ItProblem
    {
        return $this->workableChildOrNotFound('problem', ItProblem::class);
    }

    protected function workableChangeOrNotFound(): ItChange
    {
        return $this->workableChildOrNotFound('change', ItChange::class);
    }

    protected function workableMajorIncidentOrNotFound(): ItMajorIncident
    {
        return $this->workableChildOrNotFound('majorIncident', ItMajorIncident::class);
    }

    protected function workableTaskOrNotFound(): ItWorkTask
    {
        $ticket = $this->workableTicketOrNotFound();
        $task = $this->route('task');
        abort_unless(
            $task instanceof ItWorkTask
                && (int) $task->ticket_id === (int) $ticket->id,
            404,
        );

        return $task;
    }

    protected function workableApprovalOrNotFound(): ItTicketApproval
    {
        $approval = $this->route('approval');
        $user = $this->user();
        if ($approval instanceof ItTicketApproval) {
            $approval->loadMissing('ticket');
        }
        abort_unless(
            $approval instanceof ItTicketApproval
                && $approval->ticket instanceof ItTicket
                && $user instanceof User
                && app(ItWorkAccessService::class)->canWork($user, $approval->ticket),
            404,
        );

        return $approval;
    }

    /** @return array{0: ItTicket, 1: ItTicket|null} */
    protected function workableMergeParentsOrNotFound(): array
    {
        $source = $this->workableTicketOrNotFound();
        $targetId = $this->integer('target_ticket_id');
        if ($targetId < 1) {
            return [$source, null];
        }

        $target = ItTicket::query()->find($targetId);
        $user = $this->user();
        abort_unless(
            $target instanceof ItTicket
                && $user instanceof User
                && app(ItWorkAccessService::class)->canWork($user, $target),
            404,
        );

        return [$source, $target];
    }

    /** @template T of Model @param class-string<T> $class @return T */
    private function workableChildOrNotFound(string $routeKey, string $class): Model
    {
        $child = $this->route($routeKey);
        $user = $this->user();
        if ($child instanceof $class) {
            $child->loadMissing('ticket');
        }
        abort_unless(
            $child instanceof $class
                && $child->ticket instanceof ItTicket
                && $user instanceof User
                && app(ItWorkAccessService::class)->canWork($user, $child->ticket),
            404,
        );

        return $child;
    }
}
