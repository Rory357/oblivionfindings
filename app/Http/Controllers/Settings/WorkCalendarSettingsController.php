<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SyncWorkCalendarsJob;
use App\Models\WorkCalendarEventLink;
use App\Services\WorkCalendar\WorkCalendarProvider;
use App\Services\WorkCalendar\WorkCalendarSettings;
use App\Services\WorkCalendar\WorkCalendarSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class WorkCalendarSettingsController extends Controller
{
    public function update(Request $request, WorkCalendarSettings $settings, WorkCalendarProvider $provider)
    {
        abort_unless($request->user()?->canDo('integrations.manage_secrets'), 403);
        $data = $request->validate([
            'provider' => ['required', 'in:google,microsoft'],
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'enabled' => ['required', 'boolean'],
        ]);
        $data['domain'] = strtolower($data['domain']);
        $saved = Cache::lock(WorkCalendarSyncService::LOCK, 60)->get(function () use ($data, $settings, $provider): bool {
            $current = $settings->load();
            $changed = $current['provider'] !== $data['provider'] || $current['domain'] !== $data['domain'];
            if ($changed && WorkCalendarEventLink::exists()) {
                throw ValidationException::withMessages(['work_calendar' => 'Existing synced calendars need to be reconciled before changing provider or domain. Pause sync and contact your administrator.']);
            }
            if ($data['enabled'] && (! $provider->configured($data['provider'])
                || $current['checked_fingerprint'] !== $provider->fingerprint($data['provider'], $data['domain'])
                || ! $current['checked_at'] || Carbon::parse($current['checked_at'])->lt(now()->subDay()))) {
                throw ValidationException::withMessages(['work_calendar' => 'Save the setup with sync paused, then check a staff work calendar before enabling automatic sync.']);
            }
            $settings->save(array_merge($data, $changed ? ['checked_at' => null, 'checked_fingerprint' => null, 'last_synced_at' => null, 'last_error' => null] : []));

            return true;
        });
        abort_unless($saved, 409, 'Calendar sync is running. Try again when it finishes.');

        return back()->with('success', $data['enabled'] ? 'Staff work calendar sync enabled. It runs every 15 minutes.' : 'Staff work calendar sync paused. Existing calendar copies are kept.');
    }

    public function check(Request $request, WorkCalendarSettings $settings, WorkCalendarProvider $provider)
    {
        abort_unless($request->user()?->canDo('integrations.manage_secrets'), 403);
        $request->validate(['user_id' => ['required', 'integer']]);
        $checked = Cache::lock(WorkCalendarSyncService::LOCK, 60)->get(function () use ($request, $settings, $provider): bool {
            $current = $settings->load();
            $mailbox = $settings->recipients($current['domain'])->get($request->integer('user_id'));
            abort_unless($mailbox, 422, 'Choose a current staff member with a unique work email in the saved domain.');
            try {
                $provider->check($current['provider'], $mailbox);
            } catch (\Throwable) {
                $settings->save(['checked_at' => null, 'checked_fingerprint' => null, 'last_error' => 'Calendar access check failed. Check organisation credentials, permissions and the staff work email.']);
                throw ValidationException::withMessages(['work_calendar' => 'Calendar access check failed. Check organisation credentials, permissions and the staff work email.']);
            }
            $settings->save(['checked_at' => now()->toIso8601String(), 'checked_fingerprint' => $provider->fingerprint($current['provider'], $current['domain']), 'last_error' => null]);

            return true;
        });
        abort_unless($checked, 409, 'Calendar sync is running. Try again when it finishes.');

        return back()->with('success', 'Staff calendar read access checked. No events were added. Write permissions are confirmed by the first sync.');
    }

    public function sync(Request $request, WorkCalendarSettings $settings)
    {
        abort_unless($request->user()?->canDo('integrations.manage_secrets'), 403);
        abort_unless($settings->load()['enabled'], 422, 'Enable staff work calendar sync first.');
        SyncWorkCalendarsJob::dispatch();

        return back()->with('success', 'Staff work calendar sync queued.');
    }
}
