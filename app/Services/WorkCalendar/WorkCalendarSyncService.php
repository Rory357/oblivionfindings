<?php

namespace App\Services\WorkCalendar;

use App\Models\Shift;
use App\Models\User;
use App\Models\WorkCalendarEventLink;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WorkCalendarSyncService
{
    public const LOCK = 'work-calendar-sync';

    public function __construct(
        private WorkCalendarSettings $settings,
        private WorkCalendarProvider $provider,
        private UserSiteAccessService $siteAccess,
    ) {}

    public function run(): void
    {
        Cache::lock(self::LOCK, 3600)->get(function (): void {
            $settings = $this->settings->load();
            if (! $settings['enabled']) {
                return;
            }
            if ($settings['checked_fingerprint'] !== $this->provider->fingerprint($settings['provider'], $settings['domain'])) {
                $this->settings->save(['last_error' => 'Organisation credentials changed. Check access again in settings.']);

                return;
            }
            $recipients = $this->settings->recipients($settings['domain']);
            $userIds = $recipients->keys()->merge(WorkCalendarEventLink::where('provider', $settings['provider'])->pluck('user_id'))->unique();
            $failed = 0;
            foreach ($userIds as $userId) {
                try {
                    $this->syncWorker((int) $userId, $recipients->get($userId), $settings['provider']);
                } catch (\Throwable) {
                    $failed++;
                }
            }
            $this->settings->save($failed > 0
                ? ['last_error' => "Calendar sync needs attention for $failed staff account(s). Check calendar access and try again."]
                : ['last_synced_at' => now()->toIso8601String(), 'last_error' => null]);
        });
    }

    private function syncWorker(int $userId, ?string $mailbox, string $provider): void
    {
        $user = User::find($userId);
        $shifts = $mailbox && $user
            ? $this->siteAccess->applyShiftScope(Shift::query(), $user)
                ->where('user_id', $userId)->visibleToFrontline()
                ->whereIn('status', ['scheduled', 'in_progress', 'completed'])
                ->where('ends_at', '>', now()->subDays(7))
                ->where('starts_at', '<', now()->addDays(90))->get()->keyBy('id')
            : collect();

        // Only remote IDs created by this integration are touched.
        foreach (WorkCalendarEventLink::where('provider', $provider)->where('user_id', $userId)->get() as $link) {
            if ($link->mailbox !== $mailbox || ! $shifts->has($link->shift_id)) {
                // Keep historical copies; no longer maintain them after seven days.
                if ($link->ends_at->gt(now()->subDays(7))) {
                    $this->provider->remove($link);
                }
                $link->delete();
            }
        }

        foreach ($shifts as $shift) {
            $link = WorkCalendarEventLink::firstOrCreate([
                'user_id' => $userId, 'shift_id' => $shift->id, 'provider' => $provider, 'mailbox' => $mailbox,
            ], ['operation_id' => (string) Str::uuid(), 'ends_at' => $shift->ends_at]);
            $event = $this->eventPayload($shift, $provider);
            $hash = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
            if ($link->external_id && $link->payload_hash === $hash) {
                continue;
            }
            try {
                $id = $this->provider->upsert($link, $event);
                $link->update(['external_id' => $id, 'payload_hash' => $hash, 'ends_at' => $shift->ends_at, 'last_synced_at' => now(), 'last_error' => null]);
            } catch (\Throwable $error) {
                $link->update(['last_error' => 'Could not sync this shift. The next run will retry.']);
                throw $error;
            }
        }
    }

    public function eventPayload(Shift $shift, string $provider): array
    {
        $description = 'View your shift details in Oblivion Findings: '.url('/my-calendar');
        if ($provider === 'google') {
            return [
                'summary' => 'Work shift', 'description' => $description, 'visibility' => 'private',
                'start' => ['dateTime' => $shift->starts_at->toIso8601String()],
                'end' => ['dateTime' => $shift->ends_at->toIso8601String()],
            ];
        }

        return [
            'subject' => 'Work shift', 'body' => ['contentType' => 'text', 'content' => $description],
            'sensitivity' => 'private', 'showAs' => 'busy', 'isReminderOn' => false,
            'start' => ['dateTime' => $shift->starts_at->copy()->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $shift->ends_at->copy()->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        ];
    }
}
