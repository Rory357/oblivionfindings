<?php

namespace App\Domain\It\Services;

use App\Models\ItKbArticle;
use App\Models\ItKbInteraction;
use App\Models\ItKbRevision;
use App\Models\ItTicket;
use App\Models\User;
use DomainException;

/** Canonical source references; ticket conversation and private verification are never copied. */
final class ItKnowledgeResolutionSources
{
    public function prepare(User $actor, ItTicket $ticket): array
    {
        $this->guard($actor, $ticket);

        return [
            'title' => 'Guide for a resolved '.$ticket->category.' issue',
            'category' => $ticket->category, 'body' => '',
            'source_ticket_id' => (int) $ticket->id,
            'source_ticket_version' => (int) $ticket->lock_version,
            'source_reference' => $ticket->reference,
        ];
    }

    /** Called under the article creation transaction, before creating the document. */
    public function lockSource(User $actor, array $data): ?ItTicket
    {
        if (empty($data['source_ticket_id'])) {
            return null;
        }
        $ticket = ItTicket::query()->lockForUpdate()->findOrFail((int) $data['source_ticket_id']);
        $this->guard($actor, $ticket);
        if ((int) ($data['source_ticket_version'] ?? 0) !== (int) $ticket->lock_version) {
            throw new DomainException('The source ticket changed. Reopen its resolution before creating this guide. Your document text has been retained.');
        }

        return $ticket;
    }

    public function record(ItKbArticle $article, ItTicket $ticket, User $actor): void
    {
        $article->interactions()->create([
            'user_id' => $actor->id, 'it_ticket_id' => $ticket->id,
            'event_type' => 'resolution_source', 'source' => 'ticket_resolution',
            'context' => ['ticket_version' => (int) $ticket->lock_version, 'resolved_at' => $ticket->resolved_at?->toIso8601String()],
            'occurred_at' => now(),
        ]);
    }

    public function recordPublication(ItKbArticle $article, ItKbRevision $revision): void
    {
        foreach ($article->interactions()->where('event_type', 'resolution_source')->get() as $source) {
            if (empty($source->context['published_revision_id'])) {
                $source->update(['context' => [...($source->context ?? []), 'published_revision_id' => (int) $revision->id]]);
            }
        }
    }

    public function forArticle(User $actor, ItKbArticle $article): array
    {
        abort_unless(app(ItKbAccessService::class)->applyViewScope(ItKbArticle::query(), $actor)->whereKey($article->id)->exists(), 404);
        $sources = $article->interactions()->where('event_type', 'resolution_source')->get();
        $tickets = app(ItWorkAccessService::class)->applyViewScope(ItTicket::query(), $actor)
            ->whereKey($sources->pluck('it_ticket_id'))->get(['id', 'reference'])->keyBy('id');

        return $sources->filter(fn ($source) => $tickets->has($source->it_ticket_id))->map(fn ($source) => [
            'reference' => $tickets[$source->it_ticket_id]->reference,
            'href' => '/it/tickets/'.$source->it_ticket_id,
            'ticket_version' => $source->context['ticket_version'] ?? null,
        ])->values()->all();
    }

    public function forTicket(User $actor, ItTicket $ticket): array
    {
        abort_unless($actor->approved_at && app(ItWorkAccessService::class)->canView($actor, $ticket), 404);
        $sources = ItKbInteraction::query()->where('it_ticket_id', $ticket->id)->where('event_type', 'resolution_source')->get();
        $articles = app(ItKbAccessService::class)->applyViewScope(ItKbArticle::query(), $actor)
            ->whereKey($sources->pluck('it_kb_article_id'))->get(['id', 'title', 'status', 'audience', 'site_scope'])->keyBy('id');
        $records = [];
        foreach ($sources as $source) {
            $article = $articles->get($source->it_kb_article_id);
            if (! $article) {
                continue;
            }
            $revisionId = $source->context['published_revision_id'] ?? null;
            if ($revisionId && ! app(ItKnowledgePublishedRevisions::class)->query($actor, $article)->whereKey($revisionId)->exists()) {
                continue;
            }
            $records[] = ['id' => $article->id, 'title' => $article->title, 'status' => $article->status,
                'href' => '/it/knowledge/'.$article->id.($revisionId ? '?revision='.$revisionId : ''),
                'published_revision' => $revisionId !== null];
        }

        return ['actor_user_id' => (int) $actor->id, 'ticket_id' => (int) $ticket->id,
            'source_version' => (int) $ticket->lock_version,
            'can_draft' => app(ItKbAccessService::class)->canAuthorRecords($actor) && ! $ticket->is_sensitive
                && ! $ticket->isMerged() && in_array($ticket->status, ['resolved', 'closed'], true),
            'records' => $records];
    }

    private function guard(User $actor, ItTicket $ticket): void
    {
        abort_unless(app(ItKbAccessService::class)->canAuthorRecords($actor)
            && app(ItWorkAccessService::class)->canView($actor, $ticket), 404);
        if ($ticket->is_sensitive || $ticket->isMerged() || ! in_array($ticket->status, ['resolved', 'closed'], true)) {
            throw new DomainException('Reusable guidance can start from an accessible, resolved ticket without a sensitive classification.');
        }
    }
}
