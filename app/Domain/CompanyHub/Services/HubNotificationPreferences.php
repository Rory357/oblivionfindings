<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubRevision;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Personal opt-outs supplement canonical access; they never grant delivery or channels. */
class HubNotificationPreferences
{
    public const KEYS = ['recognition', 'replies', 'events', 'news', 'reviews'];

    public function ready(): bool
    {
        return Schema::hasColumn('hub_preferences', 'notifications_version');
    }

    public function currentUser(User $user): ?User
    {
        $current = User::find($user->id);
        $sites = new UserSiteAccessService;
        $access = new HubAccess(app(HrCurrentStaffService::class), $sites);

        return $current && $access->canEnter($current) && $sites->applyStaffScope(User::query()->whereKey($current->id), $current)->exists() ? $current : null;
    }

    public function read(User $user): array
    {
        $current = $this->currentUser($user);
        abort_unless($current, 403);
        $row = DB::table('hub_preferences')->where('user_id', $current->id)->first();
        $ready = $this->ready();

        return ['ready' => $ready, 'actor_id' => (int) $current->id,
            'version' => $ready ? (int) ($row?->notifications_version ?? 1) : null,
            'preferences' => $this->normalise($row?->notifications)];
    }

    public function save(User $user, array $data, bool $guarded): array
    {
        return DB::transaction(function () use ($user, $data, $guarded): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = $this->currentUser($user);
            abort_unless($current, 403);
            if (isset($data['actor_id'])) {
                abort_unless((int) $data['actor_id'] === (int) $current->id, 409, 'Your signed-in account changed. Reopen notification preferences.');
            }
            $ready = $this->ready();
            abort_if($guarded && ! $ready, 503, 'Notification preference editing is not available until the database update is installed.');
            $row = DB::table('hub_preferences')->where('user_id', $current->id)->lockForUpdate()->first();
            $version = (int) ($row?->notifications_version ?? 1);
            abort_if($guarded && (int) $data['version'] !== $version, 409, 'Your notification preferences changed. Reload the saved choices before trying again.');
            $preferences = $this->normalise($row?->notifications);
            foreach (self::KEYS as $key) {
                if (array_key_exists($key, $data)) {
                    $preferences[$key] = (bool) $data[$key];
                }
            }
            DB::table('hub_preferences')->updateOrInsert(['user_id' => $current->id], [
                'notifications' => json_encode($preferences, JSON_THROW_ON_ERROR),
                ...($ready ? ['notifications_version' => $version + 1] : []), 'updated_at' => now(),
            ]);

            return ['saved' => true, ...$this->read($current)];
        });
    }

    public function allows(User $user, string $category): bool
    {
        return in_array($category, self::KEYS, true)
            && $this->normalise(DB::table('hub_preferences')->where('user_id', $user->id)->value('notifications'))[$category];
    }

    public function allowsEvent(User $user, HrCalendarEvent $event): bool
    {
        // Every canonical work event can appear in Hub; origin does not change this choice.
        // Leave, shift, compliance and milestone layers use other canonical records.
        return $this->allows($user, 'events');
    }

    public function wantsNews(User $user, HubRevision $revision): bool
    {
        $current = $this->currentUser($user);
        if (! $current || ! $this->allows($current, 'news')) {
            return false;
        }
        $stored = json_decode(DB::table('hub_preferences')->where('user_id', $current->id)->value('interests') ?? '{}', true);
        $topics = is_array($stored['topics'] ?? null) ? array_intersect($stored['topics'], HubDiscovery::TOPICS) : [];
        $available = (new HrCalendarAccessService(new UserSiteAccessService, app(HrCurrentStaffService::class)))->visibleDepartmentsQuery($current)->pluck('id')->all();
        $teams = is_array($stored['teams'] ?? null) ? array_intersect($stored['teams'], $available) : [];

        return array_intersect($revision->content['topics'] ?? [], $topics) !== []
            || array_intersect($revision->content['team_ids'] ?? [], $teams) !== [];
    }

    private function normalise(?string $json): array
    {
        $stored = json_decode($json ?? '{}', true);
        $stored = is_array($stored) ? $stored : [];

        return collect(self::KEYS)->mapWithKeys(function ($key) use ($stored): array {
            $value = $stored[$key] ?? true;

            return [$key => in_array($value, [true, false, 0, 1, '0', '1'], true) ? (bool) $value : true];
        })->all();
    }
}
