<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItKnowledgeVersionConflict;
use App\Models\ItKbArticle;
use App\Models\ItKbFile;
use App\Models\ItKbRevision;
use App\Models\ItKbWorkingCopy;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One serialized write boundary for the application Knowledge library. Article
 * lifecycle, destructive draft cleanup and requester interaction evidence all
 * lock the canonical row so counters, state and audit history cannot diverge.
 */
final class ItKbLifecycleService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItKbAccessService $knowledgeAccess,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ItKbArticle
    {
        return DB::transaction(function () use ($actor, $data): ItKbArticle {
            $actor = $this->guardActor($actor);
            $source = app(ItKnowledgeResolutionSources::class)->lockSource($actor, $data);
            $data = $this->normaliseOptions(null, $actor, $data);
            if (! app(ItKbRevisionService::class)->ready()) {
                $data = Arr::except($data, ['document_type', 'structured_content', 'related_records']);
            }

            $article = ItKbArticle::query()->create([
                ...Arr::only($data, [
                    'title',
                    'category',
                    'body',
                    'audience',
                    'site_scope',
                    'owner_user_id',
                    'related_service_id',
                    'review_due_at',
                    'document_type',
                    'structured_content',
                    'related_records',
                    'diagrams',
                    'file_ids',
                    'tags',
                ]),
                'slug' => ItKbArticle::uniqueSlug((string) $data['title']),
                'status' => 'draft',
                'author_user_id' => $actor->id,
                'published_at' => null,
                'reviewed_by_user_id' => null,
            ]);

            AuditLogger::logOrFail('it.knowledge.created', $article, [
                'actor_id' => $actor->id,
                'status' => 'draft',
                'audience' => $article->audience,
                'application_scope' => 'single_application',
            ]);

            if (! empty($data['diagrams'])) {
                app(ItKbRevisionService::class)->recordSnapshot($article, $actor, 'media_draft_saved', app(ItKbRevisionService::class)->content($article));
            }
            if ($source) {
                app(ItKnowledgeResolutionSources::class)->record($article, $source, $actor);
            }

            return $article->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItKbArticle $article, User $actor, array $data): ItKbArticle
    {
        return DB::transaction(function () use ($article, $actor, $data): ItKbArticle {
            $locked = $this->lockArticle($article);
            $actor = $this->guardActor($actor);
            $this->guardArticle($locked, $actor);
            $revisions = app(ItKbRevisionService::class);
            $this->assertVersion($locked, $data);
            if ($locked->status === 'published' && $revisions->ready()) {
                $copy = $revisions->workingCopy($locked);
                if ($copy && ! $revisions->canAccessScope($actor, $copy->audience, $copy->site_scope)) {
                    throw (new ModelNotFoundException)->setModel(ItKbArticle::class, [$locked->id]);
                }
                if ($copy?->status === 'in_review') {
                    throw new DomainException('Return the proposed revision to draft before editing it.');
                }
                $previous = $copy?->snapshot ?? $revisions->content($locked);
                $candidate = array_replace($previous, Arr::only($data, ItKbRevisionService::CONTENT_FIELDS));
                $candidate = $revisions->normaliseContentDates($candidate);
                $candidate = $this->normaliseOptions($locked, $actor, $candidate);
                if ($copy && $candidate === $copy->snapshot) {
                    return $locked;
                }
                $copy ??= new ItKbWorkingCopy(['article_id' => $locked->id, 'lock_version' => 0]);
                $copy->fill(['status' => 'draft', 'audience' => $candidate['audience'], 'site_scope' => $candidate['site_scope'], 'snapshot' => $candidate,
                    'author_user_id' => $actor->id, 'lock_version' => $copy->lock_version + 1, 'submitted_at' => null])->saveOrFail();
                $locked->forceFill(['lock_version' => $locked->lock_version + 1])->saveOrFail();
                if (($previous['diagrams'] ?? []) != ($candidate['diagrams'] ?? []) || ($previous['file_ids'] ?? []) != ($candidate['file_ids'] ?? [])) {
                    if (! ItKbRevision::query()->where('article_id', $locked->id)->whereIn('event', ['published', 'previous_publication_captured'])->exists()) {
                        $revisions->record($locked, $actor, 'previous_publication_captured');
                    }
                    $revisions->recordSnapshot($locked, $actor, 'media_draft_saved', $candidate);
                }
                AuditLogger::logOrFail('it.knowledge.revision.drafted', $locked, ['actor_id' => $actor->id, 'lock_version' => $locked->lock_version]);

                return $locked->refresh();
            }
            if ($locked->status !== 'draft') {
                throw new DomainException('Return this article to draft before editing its content.');
            }
            $data = $this->normaliseOptions($locked, $actor, $data);
            if (! $revisions->ready()) {
                $data = Arr::except($data, ['document_type', 'structured_content', 'related_records']);
            }
            $locked->fill(Arr::only($data, ItKbRevisionService::CONTENT_FIELDS));
            $changedFields = array_keys($locked->getDirty());
            if ($changedFields === []) {
                return $locked;
            }
            if ($revisions->ready()) {
                $locked->lock_version++;
            }
            $locked->saveOrFail();

            if (array_intersect($changedFields, ['diagrams', 'file_ids']) !== []) {
                $revisions->recordSnapshot($locked, $actor, 'media_draft_saved', $revisions->content($locked));
            }

            AuditLogger::logOrFail('it.knowledge.updated', $locked, [
                'actor_id' => $actor->id,
                'changed_fields' => $changedFields,
                'application_scope' => 'single_application',
            ]);

            return $locked->refresh();
        });
    }

    public function submitForReview(ItKbArticle $article, User $actor, array $version = []): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'in_review', version: $version);
    }

    public function publish(ItKbArticle $article, User $actor, array $version = []): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'published', version: $version);
    }

    public function retire(ItKbArticle $article, User $actor, string $reason, array $version = []): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'retired', $reason, $version);
    }

    public function restore(ItKbArticle $article, User $actor, array $version = []): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'draft', version: $version);
    }

    public function restoreRevision(ItKbArticle $article, User $actor, int $revisionId, int $version): ItKbArticle
    {
        return DB::transaction(function () use ($article, $actor, $revisionId, $version): ItKbArticle {
            $locked = $this->lockArticle($article);
            $actor = $this->guardActor($actor);
            $this->guardArticle($locked, $actor);
            $revisions = app(ItKbRevisionService::class);
            $revision = ItKbRevision::query()->where('article_id', $locked->id)->findOrFail($revisionId);
            if (! $revisions->canAccessScope($actor, $revision->audience, $revision->site_scope)) {
                throw (new ModelNotFoundException)->setModel(ItKbRevision::class, [$revisionId]);
            }
            $snapshot = $revision->snapshot;
            if (Schema::hasColumn('it_kb_articles', 'tags')) {
                $snapshot['tags'] ??= [];
            }
            if (app(ItKnowledgeMedia::class)->ready()) {
                $snapshot['diagrams'] ??= [];
                $snapshot['file_ids'] ??= [];
            }
            $result = $this->update($locked, $actor, ['lock_version' => $version, ...$snapshot]);
            AuditLogger::logOrFail('it.knowledge.revision.restored', $locked, ['actor_id' => $actor->id, 'revision_id' => $revisionId]);

            return $result;
        });
    }

    public function discardWorkingCopy(ItKbArticle $article, User $actor, int $version, string $reason): void
    {
        DB::transaction(function () use ($article, $actor, $version, $reason): void {
            $locked = $this->lockArticle($article);
            $actor = $this->guardActor($actor);
            $this->guardArticle($locked, $actor);
            $this->assertVersion($locked, ['lock_version' => $version]);
            $revisions = app(ItKbRevisionService::class);
            $copy = $revisions->workingCopy($locked);
            if (! $copy || ! $revisions->canAccessScope($actor, $copy->audience, $copy->site_scope)) {
                throw (new ModelNotFoundException)->setModel(ItKbArticle::class, [$locked->id]);
            }
            if (trim($reason) === '') {
                throw new DomainException('Record why the proposed revision is being discarded.');
            }
            $copy->delete();
            $locked->forceFill(['lock_version' => $locked->lock_version + 1])->saveOrFail();
            AuditLogger::logOrFail('it.knowledge.revision.discarded', $locked, ['actor_id' => $actor->id, 'reason' => $reason]);
        });
    }

    public function deleteDraft(ItKbArticle $article, User $actor, string $reason, array $version = []): bool
    {
        return DB::transaction(function () use ($article, $actor, $reason, $version): bool {
            $locked = $this->lockArticle($article);
            $actor = $this->guardActor($actor);
            $this->guardArticle($locked, $actor);
            $this->assertVersion($locked, $version);

            if ($locked->status === 'in_review') {
                throw new DomainException('Return this article to draft before deleting it.');
            }
            if ($locked->status === 'published') {
                throw new DomainException('Retire published knowledge so its history remains available.');
            }
            if ($locked->status === 'retired') {
                throw new DomainException('Retired knowledge preserves its history and cannot be deleted.');
            }
            if ($locked->status !== 'draft') {
                throw new DomainException('Only draft articles can be deleted.');
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw new DomainException('Record why this draft is being deleted.');
            }

            $revisions = app(ItKbRevisionService::class);
            $retain = ($revisions->ready() && ItKbRevision::query()->where('article_id', $locked->id)->exists())
                || (app(ItKnowledgeMedia::class)->ready() && ItKbFile::query()->where('article_id', $locked->id)->exists());
            if ($retain) {
                $locked->forceFill(['status' => 'retired', 'retired_at' => now(), 'retirement_reason' => $reason,
                    'lock_version' => $locked->lock_version + 1])->saveOrFail();
                $revisions->record($locked, $actor, 'draft_archived');
                AuditLogger::logOrFail('it.knowledge.draft.archived', $locked, ['actor_id' => $actor->id, 'reason' => $reason]);

                return true;
            }

            AuditLogger::logOrFail('it.knowledge.draft.deleted', $locked, [
                'actor_id' => $actor->id,
                'reason' => $reason,
                'application_scope' => 'single_application',
            ]);
            $locked->delete();

            return false;
        });
    }

    public function recordView(ItKbArticle $article, User $actor): ItKbArticle
    {
        return DB::transaction(function () use ($article, $actor): ItKbArticle {
            $locked = $this->lockArticle($article);
            $this->guardPublishedVisible($locked, $actor);

            $locked->forceFill(['view_count' => (int) $locked->view_count + 1])->save();
            $locked->interactions()->create([
                'user_id' => $actor->id,
                'event_type' => 'viewed',
                'source' => 'help_centre',
                'occurred_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Record the user's first answer only. The article lock serializes a forged
     * double-submit and keeps the aggregate counters equal to interaction truth.
     */
    public function recordHelpful(ItKbArticle $article, User $actor, bool $helpful, bool $solved = false, ?int $expectedVersion = null): bool
    {
        return DB::transaction(function () use ($article, $actor, $helpful, $solved, $expectedVersion): bool {
            $locked = $this->lockArticle($article);
            $actor = app(ItTicketVersionService::class)->currentActor($actor);
            $this->guardPublishedVisible($locked, $actor);
            if ($expectedVersion !== null) {
                $this->assertVersion($locked, ['lock_version' => $expectedVersion]);
            }

            $recorded = false;
            $context = ['article_version' => (int) ($locked->lock_version ?? 1)];
            if (app(ItKbRevisionService::class)->ready()) {
                $context['revision_id'] = ItKbRevision::query()->where('article_id', $locked->id)
                    ->whereIn('event', ['published', 'previous_publication_captured'])->latest('revision_number')->value('id');
            }

            $alreadyVoted = $locked->interactions()
                ->where('user_id', $actor->id)
                ->whereIn('event_type', ['helpful', 'not_helpful'])
                ->exists();
            if (! $alreadyVoted) {
                $locked->forceFill([
                    'helpful_yes' => (int) $locked->helpful_yes + ($helpful ? 1 : 0),
                    'helpful_no' => (int) $locked->helpful_no + ($helpful ? 0 : 1),
                ])->save();
                $locked->interactions()->create([
                    'user_id' => $actor->id,
                    'event_type' => $helpful ? 'helpful' : 'not_helpful',
                    'source' => 'help_centre',
                    'context' => $context,
                    'occurred_at' => now(),
                ]);
                $recorded = true;
            }

            // A useful article is not evidence of an avoided ticket. Only an
            // explicit response records a solved issue; retries remain harmless.
            if ($solved && ! $locked->interactions()->where('user_id', $actor->id)->where('event_type', 'solved')->exists()) {
                $locked->interactions()->create([
                    'user_id' => $actor->id, 'event_type' => 'solved', 'source' => 'help_centre',
                    'context' => $context, 'occurred_at' => now(),
                ]);
                $recorded = true;
            }

            return $recorded;
        });
    }

    private function transitionAndAudit(
        ItKbArticle $article,
        User $actor,
        string $to,
        ?string $reason = null,
        array $version = [],
    ): ItKbArticle {
        return DB::transaction(function () use ($article, $actor, $to, $reason, $version): ItKbArticle {
            $locked = $this->lockArticle($article);
            $actor = $this->guardActor($actor, in_array($to, ['published', 'retired'], true) ? ItKbAccessService::REVIEW : ItKbAccessService::AUTHOR);
            $this->guardArticle($locked, $actor);
            $this->assertVersion($locked, $version);
            $revisions = app(ItKbRevisionService::class);
            $copy = $revisions->workingCopy($locked);
            if ($copy && ! $revisions->canAccessScope($actor, $copy->audience, $copy->site_scope)) {
                throw (new ModelNotFoundException)->setModel(ItKbArticle::class, [$locked->id]);
            }
            if ($locked->status === 'published' && $revisions->ready() && ! $copy && $to === 'in_review') {
                throw new DomainException('Save a proposed revision before sending it for review. The current publication stays available.');
            }
            if ($locked->status === 'published' && $copy && $to === 'retired') {
                throw new DomainException('Publish or discard the proposed revision before retiring this article.');
            }
            if ($locked->status === 'published' && $copy && in_array($to, ['draft', 'in_review', 'published'], true)) {
                $requiredState = $to === 'in_review' ? 'draft' : 'in_review';
                if ($copy->status !== $requiredState) {
                    throw new DomainException('The proposed revision changed state. Reload it before continuing.');
                }
                if ($to === 'in_review') {
                    $this->normaliseOptions($locked, $actor, $copy->snapshot);
                    app(ItKnowledgeDocumentDefinition::class)->assertReviewable($copy->snapshot);
                }
                if ($to === 'published') {
                    $candidate = $this->normaliseOptions($locked, $actor, $copy->snapshot);
                    app(ItKnowledgeDocumentDefinition::class)->assertReviewable($candidate);
                    if (! ItKbRevision::query()->where('article_id', $locked->id)->exists()) {
                        $revisions->record($locked, $actor, 'previous_publication_captured');
                    }
                    $locked->fill($candidate);
                    $locked->forceFill(['published_at' => now(), 'reviewed_by_user_id' => $actor->id, 'review_started_at' => null]);
                    $copy->delete();
                } else {
                    $copy->fill(['status' => $to, 'lock_version' => $copy->lock_version + 1, 'submitted_at' => $to === 'in_review' ? now() : null])->saveOrFail();
                }
                $locked->forceFill(['lock_version' => $locked->lock_version + 1])->saveOrFail();
                if ($to === 'published') {
                    $revisions->record($locked, $actor, 'published');
                }
                AuditLogger::logOrFail('it.knowledge.revision.'.$to, $locked, ['actor_id' => $actor->id, 'lock_version' => $locked->lock_version]);

                return $locked->refresh();
            }
            $from = (string) $locked->status;
            if ($to === 'retired') {
                $locked->retirement_reason = trim((string) $reason);
                if ($locked->retirement_reason === '') {
                    throw new DomainException('Record why this article is being retired.');
                }
            }

            if (in_array($to, ['in_review', 'published'], true)) {
                if (! $locked->owner_user_id) {
                    throw new DomainException('Assign a documentation owner before sending this article for review or publishing it.');
                }
                $this->normaliseOptions($locked, $actor, $revisions->content($locked));
                app(ItKnowledgeDocumentDefinition::class)->assertReviewable($revisions->content($locked));
            }
            $this->transition($locked, $actor, $to);
            if ($revisions->ready()) {
                $locked->lock_version++;
            }
            $locked->saveOrFail();
            if ($to === 'published') {
                $revisions->record($locked, $actor, 'published');
            }
            AuditLogger::logOrFail("it.knowledge.{$to}", $locked, [
                'actor_id' => $actor->id,
                'from' => $from,
                'to' => $to,
                'reason_recorded' => $reason !== null,
                'application_scope' => 'single_application',
            ]);

            return $locked->refresh();
        });
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

    private function lockArticle(ItKbArticle $article): ItKbArticle
    {
        return ItKbArticle::query()->lockForUpdate()->findOrFail($article->getKey());
    }

    private function assertVersion(ItKbArticle $article, array $data): void
    {
        if (app(ItKbRevisionService::class)->ready() && array_key_exists('lock_version', $data)
            && (int) $data['lock_version'] !== (int) $article->lock_version) {
            throw new ItKnowledgeVersionConflict;
        }
    }

    private function guardActor(User $actor, string $capability = ItKbAccessService::AUTHOR): User
    {
        $actor = app(ItTicketVersionService::class)->currentActor($actor);
        if ($actor->approved_at === null || ! $actor->canDo($capability)) {
            throw new AuthorizationException('You do not have the required knowledge capability for this action.');
        }

        return $actor;
    }

    private function guardPublishedVisible(ItKbArticle $article, User $actor): void
    {
        if (! $this->knowledgeAccess->canReadPublished($actor, $article)) {
            throw (new ModelNotFoundException)->setModel(ItKbArticle::class, [$article->id]);
        }
    }

    private function guardArticle(ItKbArticle $article, User $actor): void
    {
        if (! $this->knowledgeAccess->canManage($actor, $article)) {
            throw (new ModelNotFoundException)->setModel(ItKbArticle::class, [$article->id]);
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normaliseOptions(?ItKbArticle $article, User $actor, array $data): array
    {
        $data = app(ItKnowledgeMedia::class)->validate($article, $actor, $data);
        if (array_key_exists('tags', $data)) {
            if (! Schema::hasColumn('it_kb_articles', 'tags')) {
                throw new DomainException('Tags need the Knowledge storage update before they can be saved.');
            }
            $data['tags'] = app(ItKnowledgeDocumentDefinition::class)->normaliseTags((array) $data['tags']);
        }
        $audience = (string) ($data['audience'] ?? $article?->audience ?? 'all_staff');
        $siteIds = $audience === 'specific_sites'
            ? array_values(array_unique(array_map(
                'intval',
                (array) ($data['site_scope'] ?? $article?->site_scope ?? []),
            )))
            : [];
        if ($siteIds !== [] && ! $actor->canDo('it.organisationWide')) {
            if (array_diff($siteIds, $this->workAccess->approvedSiteIds($actor)) !== []) {
                throw new DomainException('You can publish knowledge only to approved Sites.');
            }
        }

        $ownerId = (int) ($data['owner_user_id'] ?? $article?->owner_user_id ?? $actor->id);
        $owner = $this->knowledgeAccess->owners()->firstWhere('id', $ownerId);
        if (! $owner instanceof User) {
            throw new DomainException('Choose an active knowledge author or reviewer as the article owner.');
        }
        if ($siteIds !== []
            && ! $owner->canDo('it.organisationWide')
            && array_diff($siteIds, $this->workAccess->approvedSiteIds($owner)) !== []) {
            throw new DomainException('The article owner must have access to every selected Site.');
        }

        $data['audience'] = $audience;
        $data['site_scope'] = $siteIds === [] ? null : $siteIds;
        $data['owner_user_id'] = $owner->id;
        if ($audience === 'specific_sites' && ($siteIds === [] || Site::query()->whereKey($siteIds)->where('is_active', true)->where('archived', false)->whereNull('archived_at')->count() !== count($siteIds))) {
            throw new DomainException('Choose currently active, approved Sites for this document.');
        }
        if (array_key_exists('related_records', $data)) {
            $data['related_records'] = app(ItKnowledgeRelationships::class)->normalise($actor, (array) $data['related_records'], $article?->id);
        }

        return $data;
    }
}
