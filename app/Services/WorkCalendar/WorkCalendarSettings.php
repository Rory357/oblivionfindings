<?php

namespace App\Services\WorkCalendar;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Collection;

class WorkCalendarSettings
{
    public const KEY = 'calendar.work_sync';

    public function load(): array
    {
        $stored = AppSetting::where('key', self::KEY)->value('value');

        return array_merge([
            'enabled' => false, 'provider' => 'microsoft', 'domain' => '',
            'checked_at' => null, 'checked_fingerprint' => null,
            'last_synced_at' => null, 'last_error' => null,
        ], is_array($stored) ? $stored : []);
    }

    public function save(array $changes): void
    {
        AppSetting::updateOrCreate(['key' => self::KEY], ['value' => array_merge($this->load(), $changes)]);
    }

    /** Only canonical, current staff work addresses in the approved domain. */
    public function recipients(string $domain): Collection
    {
        $users = app(HrCurrentStaffService::class)->currentUsersQuery()
            ->with('hrEmployeeProfile')->get();
        $users = $users->filter(function (User $user) use ($domain): bool {
            $email = strtolower(trim((string) $user->hrEmployeeProfile?->work_email));

            return $domain !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
                && substr(strrchr($email, '@'), 1) === $domain;
        });
        // Ambiguous mailbox ownership must never export another person's shifts.
        $counts = $users->countBy(fn (User $user) => strtolower(trim($user->hrEmployeeProfile->work_email)));

        return $users->filter(fn (User $user) => $counts[strtolower(trim($user->hrEmployeeProfile->work_email))] === 1)
            ->mapWithKeys(fn (User $user) => [$user->id => strtolower(trim($user->hrEmployeeProfile->work_email))]);
    }

    public function workerStatus(User $user): string
    {
        $settings = $this->load();
        if (! $settings['enabled']) {
            return 'Work calendar sync is managed by your admin';
        }
        if (! $this->recipients($settings['domain'])->has($user->id)) {
            return 'Ask your admin to check your work email for calendar sync';
        }
        if ($settings['last_error']) {
            return 'Work calendar sync needs your admin’s attention';
        }

        return $settings['last_synced_at'] ? 'Work calendar sync enabled' : 'Work calendar sync awaiting its first run';
    }
}
