<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Comment recovery uses current staff, page audience, permission and canonical authorship. */
class HubCommentModeration
{
    public function __construct(private readonly HubAccess $access) {}

    public static function hidden(object $comment): bool
    {
        return (bool) ($comment->hidden_at || $comment->author_removed_at || $comment->moderator_hidden_at || $comment->parent_hidden_at || $comment->legacy_hidden_at);
    }

    public function review(User $viewer, HubPage $page, array $context): array
    {
        return DB::transaction(function () use ($viewer, $page, $context): array {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $revision = $this->access->assertRead($viewer, $page);
            abort_unless($viewer->canDo('company_hub.moderate'), 403);
            $this->assertContext($viewer, $revision, $context);

            return $this->projection($viewer, $page, $revision);
        });
    }

    public function update(User $viewer, HubPage $page, int $id, array $data): array
    {
        return DB::transaction(function () use ($viewer, $page, $id, $data): array {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $revision = $this->access->assertRead($viewer, $page);
            abort_unless($viewer->canDo('company_hub.moderate'), 403);
            $this->assertContext($viewer, $revision, $data);
            $item = $this->comment($page, $id);
            $this->assertVersion($item, $data);
            if ($data['hidden']) {
                $this->hide($viewer, $page, $item, $revision);
            } else {
                abort_unless($item->moderator_hidden_at && ! $item->author_removed_at && ! $this->legacyHidden($item), 422, 'This comment cannot be restored.');
                abort_if($this->parentHidden($page, $item), 422, 'Restore the parent comment before restoring this reply.');
                DB::table('hub_interactions')->where('id', $item->id)->update([
                    'hidden_at' => null, 'moderator_hidden_at' => null, 'moderator_hidden_by' => null,
                    'parent_hidden_at' => null, 'updated_at' => now(), 'moderation_version' => $item->moderation_version + 1,
                ]);
                $this->inherit($page, $item->id, false);
                $this->audit($viewer, $page, $revision, $item, 'restored', 'moderator_hidden');
            }

            return $this->projection($viewer, $page, $revision);
        });
    }

    public function remove(User $viewer, HubPage $page, int $id, array $context = []): void
    {
        DB::transaction(function () use ($viewer, $page, $id, $context): void {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $revision = $this->access->assertRead($viewer, $page);
            $item = $this->comment($page, $id);
            $author = (int) $item->user_id === (int) $viewer->id;
            abort_unless($author || $viewer->canDo('company_hub.moderate'), 403);
            $this->assertContext($viewer, $revision, $context);
            $this->assertVersion($item, $context);
            if (! $author) {
                $this->hide($viewer, $page, $item, $revision);

                return;
            }
            if ($item->author_removed_at) {
                return;
            }
            DB::table('hub_interactions')->where('id', $id)->update([
                'hidden_at' => $item->hidden_at ?? now(), 'author_removed_at' => now(),
                'updated_at' => now(), 'moderation_version' => $item->moderation_version + 1,
            ]);
            if (Schema::hasTable('hub_comment_appreciations')) {
                $withdrawn = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'comment')
                    ->where(fn ($comments) => $comments->where('id', $id)->orWhere('parent_id', $id))->pluck('id');
                DB::table('hub_comment_appreciations')->whereIn('comment_id', $withdrawn)->delete();
            }
            $this->inherit($page, $id, true);
            $this->audit($viewer, $page, $revision, $item, 'removed', 'author_removed');
        });
    }

    private function hide(User $viewer, HubPage $page, object $item, HubRevision $revision): void
    {
        abort_if($item->author_removed_at || $this->legacyHidden($item), 422, 'This comment cannot be changed by moderation.');
        if ($item->moderator_hidden_at) {
            return;
        }
        DB::table('hub_interactions')->where('id', $item->id)->update([
            'hidden_at' => $item->hidden_at ?? now(), 'moderator_hidden_at' => now(), 'moderator_hidden_by' => $viewer->id,
            'updated_at' => now(), 'moderation_version' => $item->moderation_version + 1,
        ]);
        $this->inherit($page, $item->id, true);
        $this->audit($viewer, $page, $revision, $item, 'hidden', 'moderator_hidden');
    }

    private function inherit(HubPage $page, int $parentId, bool $hidden): void
    {
        foreach (DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'comment')->where('parent_id', $parentId)->get() as $child) {
            // Preserve even legacy rows inserted after migration without provenance.
            $legacy = $this->legacyHidden($child) ? ($child->legacy_hidden_at ?? $child->hidden_at) : null;
            $independent = $child->author_removed_at || $child->moderator_hidden_at || $legacy;
            DB::table('hub_interactions')->where('id', $child->id)->update([
                'hidden_at' => $hidden || $independent ? ($child->hidden_at ?? now()) : null,
                'parent_hidden_at' => $hidden ? now() : null, 'legacy_hidden_at' => $legacy,
                'updated_at' => now(), 'moderation_version' => $child->moderation_version + 1,
            ]);
        }
    }

    private function projection(User $viewer, HubPage $page, HubRevision $revision): array
    {
        $items = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'comment')->orderBy('id')->get();
        $byId = $items->keyBy('id');
        $hidden = $items->filter(fn ($item) => self::hidden($item) || ($item->parent_id && (! $byId->has($item->parent_id) || self::hidden($byId[$item->parent_id]))));
        $names = User::whereIn('id', $hidden->pluck('user_id'))->pluck('name', 'id');

        return [
            'actor_id' => (int) $viewer->id, 'page_id' => $page->id, 'revision_id' => $revision->id,
            'comments_enabled' => (bool) $revision->content['comments_enabled'],
            'hidden_comments' => $hidden->map(function ($item) use ($byId, $names): array {
                $parentHidden = (bool) ($item->parent_id && (! $byId->has($item->parent_id) || self::hidden($byId[$item->parent_id])));
                $reason = $item->author_removed_at ? 'author_removed' : ($this->legacyHidden($item) ? 'legacy_hidden' : ($item->moderator_hidden_at ? 'moderator_hidden' : 'parent_hidden'));

                return [
                    'id' => $item->id, 'parent_id' => $item->parent_id, 'name' => $names[$item->user_id] ?? 'Former colleague',
                    'body' => in_array($reason, ['author_removed', 'legacy_hidden'], true) ? null : $item->body,
                    'created_at' => Carbon::parse($item->created_at)->toIso8601String(), 'version' => (int) $item->moderation_version,
                    'hidden_reason' => $reason, 'parent_hidden' => $parentHidden || (bool) $item->parent_hidden_at,
                    'hidden_at' => $item->hidden_at ? Carbon::parse($item->hidden_at)->toIso8601String() : null,
                    'can_restore' => $reason === 'moderator_hidden' && ! $parentHidden,
                ];
            })->values()->all(),
        ];
    }

    private function comment(HubPage $page, int $id): object
    {
        $item = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'comment')->where('id', $id)->first();
        abort_unless($item, 404);

        return $item;
    }

    private function parentHidden(HubPage $page, object $item): bool
    {
        if (! $item->parent_id) {
            return false;
        }
        $parent = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'comment')->where('id', $item->parent_id)->first();

        return ! $parent || self::hidden($parent);
    }

    private function legacyHidden(object $item): bool
    {
        return (bool) ($item->legacy_hidden_at || ($item->hidden_at && ! $item->author_removed_at && ! $item->moderator_hidden_at && ! $item->parent_hidden_at));
    }

    private function assertContext(User $viewer, HubRevision $revision, array $data): void
    {
        abort_if(isset($data['actor_id']) && (int) $data['actor_id'] !== (int) $viewer->id, 409, 'Your signed-in account changed. Reload the conversation.');
        abort_if(isset($data['revision_id']) && (int) $data['revision_id'] !== (int) $revision->id, 409, 'This page has changed. Reload the conversation.');
    }

    private function assertVersion(object $item, array $data): void
    {
        abort_if(isset($data['version']) && (int) $data['version'] !== (int) $item->moderation_version, 409, 'This comment has changed. Reload the moderation view.');
    }

    private function audit(User $viewer, HubPage $page, HubRevision $revision, object $item, string $action, string $reason): void
    {
        AuditLog::create([
            'user_id' => $viewer->id, 'action' => 'company_hub.comment_'.$action,
            'auditable_type' => HubPage::class, 'auditable_id' => $page->id,
            'meta' => ['comment_id' => (int) $item->id, 'parent_id' => $item->parent_id, 'revision_id' => $revision->id,
                'reason' => $reason, 'previous_version' => (int) $item->moderation_version, 'version' => $item->moderation_version + 1],
        ]);
    }
}
