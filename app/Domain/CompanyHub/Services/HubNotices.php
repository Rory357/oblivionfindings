<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Notifications\HubNotice;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HubNotices
{
    public function __construct(private readonly HubAccess $access, private readonly HrCurrentStaffService $staff) {}

    public function deliver(): int
    {
        $sent = 0;
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        HubPage::with('currentRelease')->whereNull('archived_at')->chunkById(100, function ($pages) use (&$sent, $today): void {
            foreach ($pages as $page) {
                $revision = $page->liveRevision();
                if ($page->kind === 'news' && $revision && $revision->publish_at->gte(now()->subDays(7))) {
                    $this->staff->currentUsersQuery()->chunkById(100, function ($users) use ($page, $revision, &$sent): void {
                        foreach ($users as $user) {
                            if ($this->access->visibleRevision($user, $page) && $this->enabled($user, 'news')) {
                                $sent += $this->sendOnce($user, $page, 'news_notice', (string) $revision->id, '/company-hub/read/'.$page->slug);
                            }
                        }
                    });
                }
                $review = $page->draft['review_on'] ?? null;
                $owner = $review && $review <= $today ? User::find($page->owner_id) : null;
                if ($owner && $this->access->canEdit($owner, $page) && $this->enabled($owner, 'reviews')) {
                    $sent += $this->sendOnce($owner, $page, 'review_notice', $review, '/company-hub/manage/'.$page->id.'/edit', true);
                }
            }
        });

        return $sent;
    }

    private function enabled(User $user, string $key): bool
    {
        $preferences = json_decode(DB::table('hub_preferences')->where('user_id', $user->id)->value('notifications') ?? '{}', true);

        return (bool) ($preferences[$key] ?? true);
    }

    private function sendOnce(User $user, HubPage $page, string $kind, string $key, string $url, bool $review = false): int
    {
        return DB::transaction(function () use ($user, $page, $kind, $key, $url, $review): int {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            if ($page->archived_at || ! $this->enabled($user, $review ? 'reviews' : 'news')) {
                return 0;
            }
            if ($review ? ! $this->access->canEdit($user, $page) || (int) $page->owner_id !== (int) $user->id || ($page->draft['review_on'] ?? null) !== $key
                : (string) $this->access->visibleRevision($user, $page)?->id !== $key) {
                return 0;
            }
            $match = ['hub_page_id' => $page->id, 'user_id' => $user->id, 'kind' => $kind, 'body' => $key];
            if (DB::table('hub_interactions')->where($match)->exists()) {
                return 0;
            }
            $user->notifyNow(new HubNotice($url, $review));
            DB::table('hub_interactions')->insert([...$match, 'created_at' => now(), 'updated_at' => now()]);

            return 1;
        });
    }
}
