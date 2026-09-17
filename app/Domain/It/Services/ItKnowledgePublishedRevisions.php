<?php

namespace App\Domain\It\Services;

use App\Models\ItKbArticle;
use App\Models\ItKbFile;
use App\Models\ItKbRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** A historical publication requires current parent access and its original audience. */
final class ItKnowledgePublishedRevisions
{
    public function query(User $actor, ItKbArticle $article): Builder
    {
        $access = app(ItKbAccessService::class);
        $allowed = $access->canReadPublished($actor, $article) || $access->canManage($actor, $article);

        return $this->audienceScope(ItKbRevision::query()->where('article_id', $article->id)
            ->whereIn('event', ['published', 'previous_publication_captured'])->whereNotNull('published_at')
            ->when(! $allowed, fn ($query) => $query->whereRaw('1 = 0')), $actor);
    }

    public function audienceScope(Builder $query, User $actor): Builder
    {
        $agent = $actor->canDo('it.view') || $actor->canDo('it.manage') || app(ItKbAccessService::class)->hasKnowledgeCapability($actor);
        $sites = app(ItWorkAccessService::class)->approvedSiteIds($actor);

        return $query->where(function ($scope) use ($actor, $agent, $sites): void {
            $scope->where('audience', 'all_staff');
            if ($agent) {
                $scope->orWhere('audience', 'it_agents');
            }
            if ($sites !== [] || ($agent && $actor->canDo('it.organisationWide'))) {
                $scope->orWhere(function ($specific) use ($actor, $agent, $sites): void {
                    $specific->where('audience', 'specific_sites')->whereJsonLength('site_scope', '>', 0);
                    if (! $agent || ! $actor->canDo('it.organisationWide')) {
                        $specific->where(function ($approved) use ($sites): void {
                            foreach ($sites as $id) {
                                $approved->orWhereJsonContains('site_scope', $id)->orWhereJsonContains('site_scope', (string) $id);
                            }
                        });
                    }
                });
            }
        })->when($actor->approved_at === null, fn ($query) => $query->whereRaw('1 = 0'));
    }

    public function find(User $actor, ItKbArticle $article, int $id): ItKbRevision
    {
        return $this->query($actor, $article)->findOrFail($id);
    }

    public function files(User $actor, ItKbArticle $article, ItKbRevision $revision): array
    {
        abort_unless($this->query($actor, $article)->whereKey($revision->id)->exists(), 404);
        if (! app(ItKnowledgeMedia::class)->ready()) {
            return [];
        }

        return $this->audienceScope(ItKbFile::query()->where('article_id', $article->id)->where('state', 'ready')
            ->whereKey($revision->snapshot['file_ids'] ?? []), $actor)->orderByDesc('id')->get()->map(fn ($file) => [
                'id' => $file->id, 'series_id' => $file->series_id, 'version' => $file->version, 'name' => $file->name,
                'size' => $file->size, 'created_at' => $file->created_at->toIso8601String(),
                'href' => '/it/knowledge/'.$article->id.'/files/'.$file->id.'?revision='.$revision->id,
            ])->all();
    }

    public function canOpenFile(User $actor, ItKbArticle $article, ItKbFile $file, int $revisionId): bool
    {
        $revision = $this->query($actor, $article)->find($revisionId);

        return $revision !== null && $file->article_id === $article->id && $file->state === 'ready'
            && in_array((int) $file->id, array_map('intval', $revision->snapshot['file_ids'] ?? []), true)
            && $this->audienceScope(ItKbFile::query()->whereKey($file->id), $actor)->exists();
    }
}
