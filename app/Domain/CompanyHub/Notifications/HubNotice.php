<?php

namespace App\Domain\CompanyHub\Notifications;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Notifications\Notification;

class HubNotice extends Notification
{
    private ?int $pageId = null;

    private ?string $sourceKey = null;

    public function __construct(private readonly string $url, private readonly bool $review = false, ?int $pageId = null, ?string $sourceKey = null)
    {
        $this->pageId = $pageId;
        $this->sourceKey = $sourceKey;
    }

    public function via(object $notifiable): array
    {
        return $this->shouldSend($notifiable, 'database') ? ['database'] : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User || ! app(HubNotificationPreferences::class)->allows($notifiable, $this->review ? 'reviews' : 'news')) {
            return false;
        }
        if ($this->pageId === null) {
            return true;
        }
        $user = app(HubNotificationPreferences::class)->currentUser($notifiable);
        $page = HubPage::find($this->pageId);
        if (! $user || ! $page || $page->archived_at) {
            return false;
        }
        $access = new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService);
        if ($this->review) {
            return (int) $page->owner_id === (int) $user->id && ($page->draft['review_on'] ?? null) === $this->sourceKey && $access->canEdit($user, $page);
        }
        $revision = $access->visibleRevision($user, $page);

        return $page->kind === 'news' && $revision && (string) $revision->id === $this->sourceKey
            && ($revision->content['show_in_news'] ?? true) && app(HubNotificationPreferences::class)->wantsNews($user, $revision);
    }

    public function toArray(object $notifiable): array
    {
        // Keep restricted page titles and body text out of retained notifications.
        return [
            'type' => 'company_hub',
            'title' => $this->review ? 'A hub page is ready for review' : 'New in Company Hub',
            'message' => $this->review ? 'A page you own has reached its review date.' : 'There is a new company story for you to read.',
            'url' => $this->url, 'action_url' => $this->url,
        ];
    }
}
