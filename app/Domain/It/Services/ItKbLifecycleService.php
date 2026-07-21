<?php

namespace App\Domain\It\Services;

use App\Models\ItKbArticle;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;

class ItKbLifecycleService
{
    /** @param array<string, mixed> $data */
    public function update(ItKbArticle $article, User $actor, array $data): ItKbArticle
    {
        $this->guard($article, $actor);
        $status = $data['status'] ?? null;
        $article->fill(Arr::except($data, ['status']));
        if ($status !== null && $status !== $article->status) {
            $this->transition($article, $actor, (string) $status);
        }
        $article->save();

        AuditLogger::logOrFail('it.knowledge.updated', $article, [
            'application_scope' => 'single_installation',
            'changed_fields' => array_keys($article->getChanges()),
        ]);

        return $article->fresh();
    }

    public function submitForReview(ItKbArticle $article, User $actor): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'in_review');
    }

    public function publish(ItKbArticle $article, User $actor): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'published');
    }

    public function retire(ItKbArticle $article, User $actor, ?string $reason): ItKbArticle
    {
        $article->retirement_reason = $reason;

        return $this->transitionAndAudit($article, $actor, 'retired');
    }

    public function restore(ItKbArticle $article, User $actor): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'draft');
    }

    private function transitionAndAudit(ItKbArticle $article, User $actor, string $to): ItKbArticle
    {
        $this->guard($article, $actor);
        $from = $article->status;
        $this->transition($article, $actor, $to);
        $article->save();
        AuditLogger::logOrFail("it.knowledge.{$to}", $article, [
            'application_scope' => 'single_installation',
            'from' => $from,
            'to' => $to,
        ]);

        return $article->fresh();
    }

    private function transition(ItKbArticle $article, User $actor, string $to): void
    {
        if (! in_array($to, ItKbArticle::STATUSES, true)) {
            throw new DomainException('Unknown knowledge lifecycle state.');
        }

        $allowed = [
            'draft' => ['in_review'],
            'in_review' => ['draft', 'published'],
            'published' => ['in_review', 'retired'],
            'retired' => ['draft'],
        ];
        if (! in_array($to, $allowed[$article->status] ?? [], true)) {
            throw new DomainException("Knowledge cannot move from {$article->status} to {$to}.");
        }

        $article->status = $to;
        if ($to === 'in_review') {
            $article->review_started_at = now();
        }
        if ($to === 'published') {
            $article->published_at = now();
            $article->retired_at = null;
            $article->reviewed_by_user_id = $actor->id;
        }
        if ($to === 'retired') {
            $article->retired_at = now();
        }
        if ($to === 'draft') {
            $article->retired_at = null;
            $article->retirement_reason = null;
            $article->review_started_at = null;
            $article->published_at = null;
            $article->reviewed_by_user_id = null;
        }
    }

    private function guard(ItKbArticle $article, User $actor): void
    {
        if ($actor->approved_at === null
            || ! $actor->canDo('it.manage')
            || ! ItKbArticle::query()->whereKey($article->getKey())->exists()) {
            throw new DomainException('You are not allowed to manage this knowledge article.');
        }
    }
}
