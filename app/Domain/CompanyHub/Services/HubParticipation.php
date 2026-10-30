<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HubParticipation
{
    public const REACTIONS = ['heart', 'applause', 'celebrate'];

    public function __construct(private readonly HubAccess $access, private readonly HubCommentModeration $moderation) {}

    public static function pollKey(array $block): string
    {
        // Editing the question or answers starts a new poll, never reassigns old votes.
        return hash('sha256', json_encode([$block['id'], $block['question'], $block['options']]));
    }

    public static function stepKey(array $step): string
    {
        return hash('sha256', json_encode([$step['id'], $step['title'], $step['url'] ?? '']));
    }

    public function read(User $user, HubPage $page): array
    {
        $user = $user->fresh();
        $page = $page->fresh();
        abort_unless($user, 403);
        abort_unless($page, 404);
        $revision = $this->access->assertRead($user, $page);
        $content = $revision->content;
        $pollService = app(HubPolls::class);
        if (HubContent::visibleBlocks($content)->contains('type', 'poll')) {
            $user = $pollService->actor($user);
            $revision = $pollService->revision($user, $page);
            $content = $revision->content;
        }
        $items = DB::table('hub_interactions')->where('hub_page_id', $page->id)->whereNull('hidden_at')
            ->whereNull('author_removed_at')->whereNull('moderator_hidden_at')->whereNull('parent_hidden_at')->whereNull('legacy_hidden_at')->get();
        $polls = [];
        foreach (HubContent::visibleBlocks($content)->where('type', 'poll') as $block) {
            $polls[$block['id']] = $pollService->project($user, $page, $revision, $block);
        }
        $visibleComments = $items->where('kind', 'comment');
        $visibleComments = $visibleComments->filter(fn ($item) => ! $item->parent_id || $visibleComments->contains(fn ($parent) => (int) $parent->id === (int) $item->parent_id && ! $parent->parent_id))->sortBy('id');
        $comments = $content['comments_enabled'] ? $visibleComments : collect();
        $canModerate = $user->canDo('company_hub.moderate');
        $steps = collect($content['campaign']['steps'] ?? [])->map(function ($step) use ($items, $user) {
            $key = self::stepKey($step);
            $completed = $items->where('kind', 'campaign')->where('body', $key);

            return [...$step, 'key' => $key, 'done' => $completed->contains('user_id', $user->id), 'count' => $completed->count()];
        })->all();
        $names = User::whereIn('id', $comments->pluck('user_id'))->pluck('name', 'id');
        $appreciationReady = Schema::hasTable('hub_comment_appreciations');
        $appreciations = $appreciationReady ? DB::table('hub_comment_appreciations')->whereIn('comment_id', $comments->pluck('id'))
            ->get(['comment_id', 'user_id'])->groupBy('comment_id') : collect();

        return [
            'actor_id' => (int) $user->id, 'page_id' => $page->id, 'revision_id' => $revision->id,
            'can_moderate' => $canModerate,
            'hidden_count' => $canModerate ? DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'comment')->count() - $visibleComments->count() : 0,
            'polls' => $polls, 'campaign' => $steps,
            'helpful' => ['count' => $items->where('kind', 'helpful')->count(), 'mine' => $items->where('kind', 'helpful')->contains('user_id', $user->id)],
            'reactions' => $content['reactions_enabled'] ? collect(self::REACTIONS)->mapWithKeys(fn ($reaction) => [$reaction => [
                'count' => $items->where('kind', 'reaction')->where('body', $reaction)->count(),
                'mine' => $items->where('kind', 'reaction')->where('body', $reaction)->contains('user_id', $user->id),
            ]])->all() : [],
            'comments' => $comments->map(fn ($item) => [
                'id' => $item->id, 'name' => $names[$item->user_id] ?? 'Former colleague', 'body' => $item->body,
                'parent_id' => $item->parent_id,
                'created_at' => Carbon::parse($item->created_at)->toIso8601String(),
                'version' => (int) $item->moderation_version,
                'can_remove' => (int) $item->user_id === (int) $user->id,
                'can_hide' => $canModerate,
                'can_appreciate' => $appreciationReady,
                'appreciation' => ['count' => ($appreciations[$item->id] ?? collect())->count(),
                    'mine' => ($appreciations[$item->id] ?? collect())->contains('user_id', $user->id)],
            ])->values()->all(),
        ];
    }

    public function write(User $user, HubPage $page, array $data): void
    {
        DB::transaction(function () use ($user, $page, $data): void {
            // Serialize per-page writes, including duplicate votes and reactions.
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $kind = $data['kind'];
            if ($kind === 'vote') {
                $user = app(HubPolls::class)->actor($user);
            } elseif ($kind === 'comment') {
                $user = $user->fresh();
                abort_unless($user, 403);
            }
            $revision = $kind === 'vote' ? app(HubPolls::class)->revision($user, $page) : $this->access->assertRead($user, $page);
            $content = $revision->content;
            $query = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('user_id', $user->id)->where('kind', $kind);
            if ($kind === 'comment') {
                abort_if(array_key_exists('actor_id', $data) && (int) $data['actor_id'] !== (int) $user->id, 409, 'Your signed-in account changed. Reload the conversation.');
                abort_if(array_key_exists('revision_id', $data) && (int) $data['revision_id'] !== (int) $revision->id, 409, 'This page has changed. Reload the conversation.');
                abort_unless($content['comments_enabled'], 403);
                $body = trim($data['body'] ?? '');
                abort_if($body === '', 422, 'Write a comment first.');
                if (! empty($data['parent_id'])) {
                    $parent = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('id', $data['parent_id'])->where('kind', 'comment')->whereNull('hidden_at')
                        ->whereNull('author_removed_at')->whereNull('moderator_hidden_at')->whereNull('parent_hidden_at')->whereNull('legacy_hidden_at')->first();
                    abort_unless($parent && ! $parent->parent_id, 422, 'Reply to a current comment on this page.');
                }
            } elseif ($kind === 'helpful') {
                abort_unless(in_array($page->kind, ['page', 'knowledge'], true), 422);
                $query->delete();
                if (! $data['active']) {
                    return;
                }
                $body = 'yes';
            } elseif ($kind === 'campaign') {
                $step = collect($content['campaign']['steps'] ?? [])->firstWhere('id', $data['step_id']);
                abort_unless($step, 404);
                $body = self::stepKey($step);
                abort_unless(hash_equals($body, $data['step_key']), 409, 'This activity has changed. Reload to see the latest version.');
                abort_if(! empty($content['campaign']['ends_on']) && Carbon::parse($content['campaign']['ends_on'], config('app.worker_timezone', 'Pacific/Auckland'))->endOfDay()->isPast(), 422, 'This campaign has ended.');
                $query->where('body', $body)->delete();
                if (! $data['active']) {
                    return;
                }
            } elseif ($kind === 'reaction') {
                abort_unless($content['reactions_enabled'], 403);
                $query->delete();
                if (! $data['active']) {
                    return;
                }
                $body = $data['reaction'];
            } else {
                $block = HubContent::visibleBlocks($content)->firstWhere('id', $data['block_id']);
                abort_unless($block && $block['type'] === 'poll', 404);
                $key = self::pollKey($block);
                app(HubPolls::class)->assertVote($user, $page, $revision, $block, $data);
                foreach ($query->get() as $vote) {
                    if ((json_decode($vote->body, true)['key'] ?? '') === $key) {
                        DB::table('hub_interactions')->where('id', $vote->id)->delete();
                    }
                }
                $body = json_encode(['key' => $key, 'choice' => (int) $data['choice']]);
            }
            $interactionId = DB::table('hub_interactions')->insertGetId([
                'hub_page_id' => $page->id, 'user_id' => $user->id, 'kind' => $kind, 'body' => $body,
                'parent_id' => $kind === 'comment' ? ($data['parent_id'] ?? null) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($kind === 'comment' && ! empty($data['parent_id'])) {
                DB::afterCommit(fn () => app(HubConversations::class)->dispatch('page', $interactionId));
            }
        });
    }

    public function remove(User $user, HubPage $page, int $id, array $context = []): void
    {
        $this->moderation->remove($user, $page, $id, $context);
    }

    public function appreciate(User $viewer, HubPage $page, int $commentId, array $data): array
    {
        return DB::transaction(function () use ($viewer, $page, $commentId, $data): array {
            $viewer = $viewer->fresh();
            abort_unless($viewer, 403);
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $access = new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService);
            $revision = $access->assertRead($viewer, $page);
            abort_unless($revision->content['comments_enabled'], 403);
            abort_if((int) $data['actor_id'] !== (int) $viewer->id, 409, 'Your signed-in account changed. Reload the conversation.');
            abort_if((int) $data['revision_id'] !== (int) $revision->id, 409, 'This page has changed. Reload the conversation.');
            $comment = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'comment')->where('id', $commentId)->first();
            abort_unless($comment && $comment->body !== null && ! HubCommentModeration::hidden($comment), 404);
            if ($comment->parent_id) {
                $parent = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'comment')->where('id', $comment->parent_id)->first();
                abort_unless($parent && ! $parent->parent_id && ! HubCommentModeration::hidden($parent), 404);
            }
            abort_if((int) $data['version'] !== (int) $comment->moderation_version, 409, 'This comment has changed. Reload the conversation.');
            if (! Schema::hasTable('hub_comment_appreciations')) {
                throw ValidationException::withMessages(['comment' => 'Comment appreciation is not available yet.']);
            }
            $rows = DB::table('hub_comment_appreciations')->where('comment_id', $commentId);
            if ($data['active']) {
                DB::table('hub_comment_appreciations')->insertOrIgnore([
                    'comment_id' => $commentId, 'user_id' => $viewer->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                (clone $rows)->where('user_id', $viewer->id)->delete();
            }

            return ['saved' => true, 'comment_id' => $commentId, 'version' => (int) $comment->moderation_version,
                'appreciation' => ['count' => $rows->count(), 'mine' => (clone $rows)->where('user_id', $viewer->id)->exists()]];
        });
    }
}
