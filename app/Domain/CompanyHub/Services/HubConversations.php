<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Notifications\HubConversationReply;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Notifications bind to visible conversation records, never copied discussion text. */
class HubConversations
{
    public function dispatch(string $kind, int $commentId): void
    {
        $source = $this->source($kind, $commentId);
        if (! $source) {
            return;
        }
        foreach ($source['participants'] as $userId) {
            $user = User::find($userId);
            if (! $user || ! $this->context($user, $kind, $commentId)) {
                continue;
            }
            try {
                $user->notify(new HubConversationReply($kind, $commentId, (int) $userId));
            } catch (\Throwable $error) {
                Log::warning('company_hub.conversation_notice_failed', ['kind' => $kind, 'comment_id' => $commentId, 'user_id' => $userId, 'error_type' => $error::class]);
            }
        }
    }

    public function context(User $viewer, string $kind, int $commentId): ?array
    {
        $preferences = app(HubNotificationPreferences::class);
        $viewer = $preferences->currentUser($viewer);
        $source = $this->source($kind, $commentId);
        if (! $viewer || ! $source || ! $preferences->allows($viewer, 'replies')
            || ! in_array((int) $viewer->id, $source['participants'], true)) {
            return null;
        }
        $author = User::find($source['author_id']);
        if (! $author || ! $preferences->currentUser($author)) {
            return null;
        }
        if ($kind === 'page') {
            $page = HubPage::find($source['subject_id']);
            $access = new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService);
            $revision = $page ? $access->visibleRevision($viewer, $page) : null;
            if (! $revision || ! ($revision->content['comments_enabled'] ?? false) || ! $access->visibleRevision($author, $page)) {
                return null;
            }

            return ['url' => '/company-hub/read/'.$page->slug.'#hub-conversation'];
        }

        return ['url' => '/company-hub?view=ideas'];
    }

    private function source(string $kind, int $id): ?array
    {
        if ($kind === 'page') {
            $comment = DB::table('hub_interactions')->where('id', $id)->where('kind', 'comment')->first();
            if (! $comment || ! $comment->parent_id || $comment->body === null || HubCommentModeration::hidden($comment)) {
                return null;
            }
            $parent = DB::table('hub_interactions')->where('id', $comment->parent_id)->where('hub_page_id', $comment->hub_page_id)->where('kind', 'comment')->first();
            if (! $parent || $parent->parent_id || $parent->body === null || HubCommentModeration::hidden($parent)) {
                return null;
            }
            $participants = DB::table('hub_interactions')->where('hub_page_id', $comment->hub_page_id)->where('kind', 'comment')->where('id', '<', $id)
                ->where(fn ($query) => $query->where('id', $parent->id)->orWhere('parent_id', $parent->id))
                ->whereNotNull('body')->whereNull('hidden_at')->whereNull('author_removed_at')->whereNull('moderator_hidden_at')->whereNull('parent_hidden_at')->whereNull('legacy_hidden_at')->pluck('user_id')->all();

            return $this->identities($comment->hub_page_id, $comment->user_id, $participants);
        }
        if ($kind === 'idea') {
            $comment = DB::table('hub_idea_comments')->where('id', $id)->whereNull('hidden_at')->first();
            $idea = $comment ? DB::table('hub_ideas')->where('id', $comment->hub_idea_id)->whereNull('hidden_at')->first() : null;
            if (! $idea) {
                return null;
            }
            $participants = DB::table('hub_idea_comments')->where('hub_idea_id', $idea->id)->where('id', '<', $id)->whereNull('hidden_at')->pluck('user_id')->all();

            return $this->identities($idea->id, $comment->user_id, [...$participants, $idea->user_id]);
        }

        return null;
    }

    private function identities(int $subject, int $author, array $participants): array
    {
        return ['subject_id' => $subject, 'author_id' => $author,
            'participants' => array_values(array_unique(array_filter(array_map('intval', $participants), fn ($id) => $id !== $author)))];
    }
}
