<?php

namespace App\Jobs;

use App\Models\HsEvent;
use App\Models\User;
use App\Notifications\HsWorksafeNotToldNotification;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * O3 (Stephan, 6 Nov 2026: "Daily reminder + task"). Once a day, every open safety review marked WorkSafe-notifiable
 * where WorkSafe hasn't been told reminds its owner and the H&S officers at its Site, until the notification is
 * recorded. Each person gets at most one reminder per review per day, and only for a Site whose H&S they can read.
 */
class HsWorksafeNotToldReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(UserSiteAccessService $siteAccess): void
    {
        $events = HsEvent::query()->worksafeNotTold()->with(['site:id,name', 'owner'])->orderBy('id')->get();
        if ($events->isEmpty()) {
            return;
        }

        $officers = User::query()
            ->whereNotNull('approved_at')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'health_safety_officer'))
            ->get();
        $day = now('Pacific/Auckland')->toDateString();

        foreach ($events as $event) {
            $recipients = collect([$event->owner])->filter()->concat($officers)->unique('id')
                ->filter(fn (User $user) => $this->canReceive($user, (int) $event->site_id, $siteAccess));

            if ($recipients->isEmpty()) {
                Log::warning('A WorkSafe-notifiable safety review reached nobody; it will be tried again tomorrow.', [
                    'hs_event_id' => $event->id, 'site_id' => $event->site_id,
                ]);

                continue;
            }

            foreach ($recipients as $user) {
                // One reminder per person, per review, per NZ day, even if the job runs again.
                if (Cache::add("hs-worksafe-not-told:{$event->id}:{$user->id}:{$day}", true, now()->addDays(2))) {
                    $user->notify(new HsWorksafeNotToldNotification($event));
                }
            }
        }
    }

    private function canReceive(User $user, int $siteId, UserSiteAccessService $siteAccess): bool
    {
        return $user->approved_at !== null
            && $user->canDo('hazards.view')
            && $siteId > 0
            && in_array($siteId, $siteAccess->accessibleHealthSafetySiteIds($user), true);
    }
}
