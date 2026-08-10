<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItKbArticle;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * One serialized write boundary for the application Knowledge library. Article
 * lifecycle, destructive draft cleanup and requester interaction evidence all
 * lock the canonical row so counters, state and audit history cannot diverge.
 */
final class ItKbLifecycleService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ItKbArticle
    {
        return DB::transaction(function () use ($actor, $data): ItKbArticle {
            $this->guardActor($actor);
            $data = $this->normaliseOptions(null, $actor, $data);

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

            return $article->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItKbArticle $article, User $actor, array $data): ItKbArticle
    {
        return DB::transaction(function () use ($article, $actor, $data): ItKbArticle {
            $locked = $this->lockArticle($article);
            $this->guardActor($actor);
            $data = $this->normaliseOptions($locked, $actor, $data);
            $locked->fill(Arr::except($data, ['status', 'slug']));
            $changedFields = array_keys($locked->getDirty());
            if ($changedFields === []) {
                return $locked;
            }
            $locked->save();

            AuditLogger::logOrFail('it.knowledge.updated', $locked, [
                'actor_id' => $actor->id,
                'changed_fields' => $changedFields,
                'application_scope' => 'single_application',
            ]);

            return $locked->refresh();
        });
    }

    public function submitForReview(ItKbArticle $article, User $actor): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'in_review');
    }

    public function publish(ItKbArticle $article, User $actor): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'published');
    }

    public function retire(ItKbArticle $article, User $actor, string $reason): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'retired', $reason);
    }

    public function restore(ItKbArticle $article, User $actor): ItKbArticle
    {
        return $this->transitionAndAudit($article, $actor, 'draft');
    }

    public function deleteDraft(ItKbArticle $article, User $actor, string $reason): void
    {
        DB::transaction(function () use ($article, $actor, $reason): void {
            $locked = $this->lockArticle($article);
            $this->guardActor($actor);

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

            AuditLogger::logOrFail('it.knowledge.draft.deleted', $locked, [
                'actor_id' => $actor->id,
                'reason' => $reason,
                'application_scope' => 'single_application',
            ]);
            $locked->delete();
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
    public function recordHelpful(ItKbArticle $article, User $actor, bool $helpful): bool
    {
        return DB::transaction(function () use ($article, $actor, $helpful): bool {
            $locked = $this->lockArticle($article);
            $this->guardPublishedVisible($locked, $actor);

            $alreadyVoted = $locked->interactions()
                ->where('user_id', $actor->id)
                ->whereIn('event_type', ['helpful', 'not_helpful'])
                ->exists();
            if ($alreadyVoted) {
                return false;
            }

            $locked->forceFill([
                'helpful_yes' => (int) $locked->helpful_yes + ($helpful ? 1 : 0),
                'helpful_no' => (int) $locked->helpful_no + ($helpful ? 0 : 1),
                'deflection_count' => (int) $locked->deflection_count + ($helpful ? 1 : 0),
            ])->save();
            $locked->interactions()->create([
                'user_id' => $actor->id,
                'event_type' => $helpful ? 'helpful' : 'not_helpful',
                'source' => 'help_centre',
                'occurred_at' => now(),
            ]);

            return true;
        });
    }

    private function transitionAndAudit(
        ItKbArticle $article,
        User $actor,
        string $to,
        ?string $reason = null,
    ): ItKbArticle {
        return DB::transaction(function () use ($article, $actor, $to, $reason): ItKbArticle {
            $locked = $this->lockArticle($article);
            $this->guardActor($actor);
            $from = (string) $locked->status;
            if ($to === 'retired') {
                $locked->retirement_reason = trim((string) $reason);
                if ($locked->retirement_reason === '') {
                    throw new DomainException('Record why this article is being retired.');
                }
            }

            $this->transition($locked, $actor, $to);
            $locked->save();
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

    private function guardActor(User $actor): void
    {
        if ($actor->approved_at === null || ! $actor->canDo('it.manage')) {
            throw new DomainException('You are not allowed to manage this knowledge article.');
        }
    }

    private function guardPublishedVisible(ItKbArticle $article, User $actor): void
    {
        $hasEntryPermission = $actor->canDo('it.request')
            || $actor->canDo('it.view')
            || $actor->canDo('it.manage');
        $visible = match ($article->audience) {
            'all_staff' => $hasEntryPermission,
            'it_agents' => $actor->canDo('it.view') || $actor->canDo('it.manage'),
            'specific_sites' => $hasEntryPermission && array_intersect(
                array_map('intval', $article->site_scope ?? []),
                $this->workAccess->approvedSiteIds($actor),
            ) !== [],
            default => false,
        };

        if ($article->status !== 'published' || ! $visible) {
            throw (new ModelNotFoundException)->setModel(ItKbArticle::class, [$article->id]);
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normaliseOptions(?ItKbArticle $article, User $actor, array $data): array
    {
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
        $owner = ItStaffDirectory::agents()->firstWhere('id', $ownerId);
        if (! $owner instanceof User) {
            throw new DomainException('Choose an active IT article owner.');
        }
        if ($siteIds !== []
            && ! $owner->canDo('it.organisationWide')
            && array_diff($siteIds, $this->workAccess->approvedSiteIds($owner)) !== []) {
            throw new DomainException('The article owner must have access to every selected Site.');
        }

        $data['audience'] = $audience;
        $data['site_scope'] = $siteIds === [] ? null : $siteIds;
        $data['owner_user_id'] = $owner->id;

        return $data;
    }
}
