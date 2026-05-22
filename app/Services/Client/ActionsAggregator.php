<?php

namespace App\Services\Client;

use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientAssessment;
use App\Models\ClientDocument;
use App\Models\ClientNote;
use App\Models\ClientRisk;
use App\Models\ConsentRequest;
use App\Models\FamilyVisitRequest;
use App\Models\User;
use Illuminate\Support\Collection;

class ActionsAggregator
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forClient(Client $client, ?User $user): array
    {
        return collect()
            ->merge($this->followUps($client, $user))
            ->merge($this->flaggedNotes($client, $user))
            ->merge($this->expiringDocuments($client, $user))
            ->merge($this->riskReviews($client, $user))
            ->merge($this->carePlanReviews($client, $user))
            ->merge($this->assessmentReviews($client, $user))
            ->merge($this->pathPlanReviews($client, $user))
            ->merge($this->consentRequests($client, $user))
            ->merge($this->visitRequests($client, $user))
            ->sortBy([
                ['severity_rank', 'asc'],
                ['due_at', 'asc'],
            ])
            ->map(fn (array $item) => collect($item)->except('severity_rank')->all())
            ->values()
            ->all();
    }

    private function followUps(Client $client, ?User $user): Collection
    {
        if (! $user?->canDo('progress_notes.viewAny')) {
            return collect();
        }

        return ClientNote::query()
            ->where('client_id', $client->id)
            ->whereNotNull('follow_up_action')
            ->whereNull('follow_up_completed_at')
            ->orderBy('follow_up_due_at')
            ->limit(20)
            ->get()
            ->map(function (ClientNote $note) use ($client) {
                $overdue = $note->follow_up_due_at?->isPast() ?? false;

                return $this->item(
                    type: $overdue ? 'overdue_follow_up' : 'open_follow_up',
                    severity: $overdue ? 'critical' : 'warning',
                    dueAt: $note->follow_up_due_at,
                    summary: $note->follow_up_action,
                    deepLink: $this->tabLink($client, 'progress_notes', $note->id),
                    sourceId: $note->id,
                );
            });
    }

    private function flaggedNotes(Client $client, ?User $user): Collection
    {
        if (! $user?->canDo('progress_notes.review')) {
            return collect();
        }

        return ClientNote::query()
            ->where('client_id', $client->id)
            ->reviewQueue()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ClientNote $note) => $this->item(
                type: 'flagged_note_review',
                severity: 'warning',
                dueAt: $note->created_at,
                summary: $note->subject ?: str($note->body)->limit(90)->toString(),
                deepLink: $this->tabLink($client, 'progress_notes', $note->id, ['flagged' => 1, 'reviewed' => 0]),
                sourceId: $note->id,
            ));
    }

    private function expiringDocuments(Client $client, ?User $user): Collection
    {
        if (! $user?->canDo('clients.viewAny')) {
            return collect();
        }

        return ClientDocument::query()
            ->where('client_id', $client->id)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->orderBy('expiry_date')
            ->limit(20)
            ->get()
            ->map(fn (ClientDocument $document) => $this->item(
                type: 'document_expiring',
                severity: $document->expiry_date?->isPast() ? 'critical' : 'warning',
                dueAt: $document->expiry_date,
                summary: $document->title.' expires',
                deepLink: $this->tabLink($client, 'documents', $document->id),
                sourceId: $document->id,
            ));
    }

    private function riskReviews(Client $client, ?User $user): Collection
    {
        if (! ($user?->canDo('risks.viewAny') || $user?->canDo('risks.viewAssigned'))) {
            return collect();
        }

        return ClientRisk::query()
            ->where('client_id', $client->id)
            ->where('active', true)
            ->whereNotNull('review_date')
            ->whereDate('review_date', '<=', now()->addDays(7))
            ->orderBy('review_date')
            ->limit(20)
            ->get()
            ->map(fn (ClientRisk $risk) => $this->item(
                type: 'risk_review_due',
                severity: $risk->review_date?->isPast() ? 'critical' : 'warning',
                dueAt: $risk->review_date,
                summary: $risk->label.' risk review due',
                deepLink: $this->tabLink($client, 'risk_management', $risk->id),
                sourceId: $risk->id,
            ));
    }

    private function carePlanReviews(Client $client, ?User $user): Collection
    {
        if (! $user?->canDo('care_plans.viewAny')) {
            return collect();
        }

        return CarePlan::query()
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->whereNotNull('next_review_at')
            ->whereDate('next_review_at', '<=', now()->addDays(7))
            ->orderBy('next_review_at')
            ->limit(10)
            ->get()
            ->map(fn (CarePlan $plan) => $this->item(
                type: 'care_plan_review_due',
                severity: $plan->next_review_at?->isPast() ? 'critical' : 'warning',
                dueAt: $plan->next_review_at,
                summary: $plan->title.' review due',
                deepLink: $this->tabLink($client, 'care_plans', $plan->id),
                sourceId: $plan->id,
            ));
    }

    private function pathPlanReviews(Client $client, ?User $user): Collection
    {
        if (! $user?->canDo('clients.viewAny') && ! $user?->canDo('clients.viewAssigned')) {
            return collect();
        }

        return \App\Models\ClientPathPlan::query()
            ->where('client_id', $client->id)
            ->whereNotNull('next_review_at')
            ->whereDate('next_review_at', '<=', now()->addDays(30))
            ->orderBy('next_review_at')
            ->limit(5)
            ->get()
            ->map(fn ($plan) => $this->item(
                type: 'path_plan_review_due',
                severity: $plan->next_review_at?->isPast() ? 'critical' : 'warning',
                dueAt: $plan->next_review_at,
                summary: 'PATH plan review due',
                deepLink: $this->tabLink($client, 'goals_path', $plan->id),
                sourceId: $plan->id,
            ));
    }

    private function assessmentReviews(Client $client, ?User $user): Collection
    {
        if (! $user?->canDo('clients.viewAny')) {
            return collect();
        }

        return ClientAssessment::query()
            ->where('client_id', $client->id)
            ->whereNotNull('next_review_at')
            ->whereDate('next_review_at', '<=', now()->addDays(7))
            ->orderBy('next_review_at')
            ->limit(10)
            ->get()
            ->map(fn (ClientAssessment $assessment) => $this->item(
                type: 'assessment_due',
                severity: $assessment->next_review_at?->isPast() ? 'critical' : 'warning',
                dueAt: $assessment->next_review_at,
                summary: ucfirst((string) $assessment->type).' assessment due',
                deepLink: $this->tabLink($client, 'assessments', $assessment->id),
                sourceId: $assessment->id,
            ));
    }

    private function consentRequests(Client $client, ?User $user): Collection
    {
        if (! $user?->canDo('consents.viewAny')) {
            return collect();
        }

        return ConsentRequest::query()
            ->where('client_id', $client->id)
            ->where('status', ConsentRequest::STATUS_PENDING)
            ->orderBy('expires_at')
            ->limit(10)
            ->get()
            ->map(fn (ConsentRequest $request) => $this->item(
                type: 'pending_consent_request',
                severity: $request->expires_at?->isPast() ? 'critical' : 'info',
                dueAt: $request->expires_at,
                summary: str($request->purpose)->limit(90)->toString(),
                deepLink: "/operations/clients/{$client->id}/consent-requests/{$request->id}",
                sourceId: $request->id,
            ));
    }

    private function visitRequests(Client $client, ?User $user): Collection
    {
        if (! $user?->canDo('family_portal.viewAny')) {
            return collect();
        }

        return FamilyVisitRequest::query()
            ->where('client_id', $client->id)
            ->where('status', 'pending')
            ->orderBy('requested_date')
            ->limit(10)
            ->get()
            ->map(fn (FamilyVisitRequest $request) => $this->item(
                type: 'pending_visit_request',
                severity: 'info',
                dueAt: $request->requested_date,
                summary: 'Family visit request pending',
                deepLink: "/operations/clients/{$client->id}/visit-requests",
                sourceId: $request->id,
            ));
    }

    private function item(
        string $type,
        string $severity,
        mixed $dueAt,
        string $summary,
        string $deepLink,
        ?int $sourceId,
    ): array {
        return [
            'type' => $type,
            'severity' => $severity,
            'severity_rank' => match ($severity) {
                'critical' => 0,
                'warning' => 1,
                'info' => 2,
                default => 3,
            },
            'due_at' => $dueAt?->toISOString(),
            'summary' => $summary,
            'deep_link' => $deepLink,
            'source_id' => $sourceId,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function tabLink(Client $client, string $tab, ?int $item = null, array $params = []): string
    {
        $query = http_build_query(array_filter(['tab' => $tab, 'item' => $item, ...$params], fn ($value) => $value !== null && $value !== ''));

        return "/operations/clients/{$client->id}".($query ? "?{$query}" : '');
    }
}
