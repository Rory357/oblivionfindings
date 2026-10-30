<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;

/** Public metadata comes only from authorised published content and current work names. */
class HubReaderMetadata
{
    public function names(User $viewer, array $ids): array
    {
        app(HubAccess::class)->assertEnter($viewer);

        return app(UserSiteAccessService::class)->applyStaffScope(app(HrCurrentStaffService::class)->currentUsersQuery(), $viewer)
            ->whereIn('users.id', array_values(array_unique(array_filter($ids))))->pluck('users.name', 'users.id')->all();
    }

    public static function fields(HubPage $page, HubRevision $revision, array $content, array $teams, array $names): array
    {
        return [
            'owner' => $names[$page->owner_id] ?? 'Company team',
            'owner_url' => isset($names[$page->owner_id]) ? '/company-hub/people/'.$page->owner_id : null,
            'author' => $names[$revision->created_by] ?? 'Company team',
            'topics' => array_values(array_intersect($content['topics'] ?? [], HubDiscovery::TOPICS)),
            'team_ids' => array_values(array_intersect($content['team_ids'] ?? [], array_column($teams, 'id'))),
            'team_names' => collect($teams)->whereIn('id', $content['team_ids'] ?? [])->pluck('name')->values()->all(),
            'read_minutes' => self::readMinutes($content), 'comments_enabled' => (bool) ($content['comments_enabled'] ?? false),
            'review_overdue' => self::overdue($content['review_on'] ?? null),
            'governance_warnings' => $page->kind === 'knowledge' || ! empty($content['resource_type']) || HubContent::visibleBlocks($content)->contains('type', 'file')
                ? HubReadiness::governanceWarnings($content, isset($names[$page->owner_id])) : [],
            'show_in_news' => $page->kind === 'news' && ($content['show_in_news'] ?? true),
        ];
    }

    /** Entries are the caller's server-authorised current releases, not request IDs. */
    public function forPages(User $viewer, array $entries, array $teams): array
    {
        if ($entries === []) {
            return [];
        }
        $names = $this->names($viewer, collect($entries)->flatMap(fn ($entry) => [$entry['page']->owner_id, $entry['revision']->created_by])->all());
        $ids = array_keys($entries);
        $bookmarks = DB::table('hub_bookmarks')->where('user_id', $viewer->id)->where('subject_type', 'page')->whereIn('subject_id', $ids)->pluck('subject_id')->all();
        $comments = $this->commentCounts($ids);

        return collect($entries)->mapWithKeys(fn ($entry, $id) => [$id => [
            ...self::fields($entry['page'], $entry['revision'], $entry['content'], $teams, $names),
            'comment_count' => empty($entry['content']['comments_enabled']) ? 0 : (int) ($comments[$id] ?? 0),
            'bookmarked' => in_array((int) $id, $bookmarks),
        ]])->all();
    }

    private function commentCounts(array $ids): array
    {
        $flags = ['hidden_at', 'author_removed_at', 'moderator_hidden_at', 'parent_hidden_at', 'legacy_hidden_at'];
        $query = DB::table('hub_interactions as comment')->leftJoin('hub_interactions as parent', function ($join) {
            $join->on('parent.id', '=', 'comment.parent_id')->on('parent.hub_page_id', '=', 'comment.hub_page_id')
                ->where('parent.kind', 'comment')->whereNull('parent.parent_id');
        })->whereIn('comment.hub_page_id', $ids)->where('comment.kind', 'comment');
        foreach ($flags as $flag) {
            $query->whereNull('comment.'.$flag);
        }
        $query->where(function ($thread) use ($flags) {
            $thread->whereNull('comment.parent_id')->orWhere(function ($parent) use ($flags) {
                $parent->whereNotNull('parent.id');
                foreach ($flags as $flag) {
                    $parent->whereNull('parent.'.$flag);
                }
            });
        });

        return $query->select('comment.hub_page_id')->selectRaw('count(*) as total')->groupBy('comment.hub_page_id')->pluck('total', 'comment.hub_page_id')->all();
    }

    public static function overdue(?string $date, ?string $today = null): bool
    {
        return $date !== null && $date !== '' && $date < ($today ?? now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString());
    }

    public static function readMinutes(array $content): int
    {
        // A linked feature/feed is a different article; count only this page's visible prose.
        $body = HubContent::visibleBlocks($content)->whereIn('type', ['text', 'notice', 'image', 'button'])->pluck('body')->implode(' ');
        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return max(1, (int) ceil(count($words) / 180));
    }

    public static function body(array $content): string
    {
        $body = HubContent::visibleBlocks($content)->pluck('body')->implode(' ');
        if (HubKnowledge::supportsPersonalSteps($content)) {
            $body .= ' '.collect($content['checklist'] ?? [])->pluck('title')->implode(' ');
        }

        return strip_tags($body);
    }

    public static function matches(array $summary, string $query, string $body = ''): bool
    {
        $text = implode(' ', [
            $summary['title'] ?? '', $summary['summary'] ?? '', $summary['category'] ?? '',
            $summary['owner'] ?? '', $summary['author'] ?? '', $summary['collection'] ?? '', $summary['resource_type'] ?? '',
            implode(' ', $summary['topics'] ?? []), implode(' ', $summary['team_names'] ?? []), $body,
        ]);

        return trim($query) === '' || str_contains(mb_strtolower($text), mb_strtolower(trim($query)));
    }
}
