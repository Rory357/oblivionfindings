<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\AuditLogger;
use App\Services\Fleet\FleetNotificationPreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsNotificationController extends Controller
{
    public function __construct(private readonly FleetNotificationPreferences $preferences) {}

    public function show(Request $request)
    {
        return response()->json($this->preferences->snapshot($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request)
    {
        $keys = array_keys(FleetNotificationPreferences::EVENTS);
        $data = $request->validate([
            'revision' => ['required', 'string', 'size:64'],
            'overrides' => ['present', 'array'],
        ]);
        // Event keys contain dots. Validate literal keys before Laravel's dotted-path expansion.
        $data['overrides'] = $request->input('overrides');
        foreach ($data['overrides'] as $key => $values) {
            if (! in_array($key, $keys, true) || ! is_array($values) || array_diff(array_keys($values), ['inapp', 'email'])) {
                throw ValidationException::withMessages(['overrides' => 'Choose a supported optional event and channel.']);
            }
            foreach ($values as $value) {
                if (! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    throw ValidationException::withMessages(['overrides' => 'Each channel choice must be on or off.']);
                }
            }
        }

        return DB::transaction(function () use ($request, $data, $keys) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->canDo('fleet.viewAny') || $actor->canDo('assets.viewAny'), 403);
            $before = $this->preferences->snapshot($actor);
            if (! hash_equals($before['revision'], $data['revision'])) {
                return response()->json(['message' => 'Your saved choices or role defaults changed. Review the latest values before saving.', 'latest' => $before], 409);
            }
            foreach ($keys as $key) {
                $values = array_map(fn ($value) => (bool) $value, $data['overrides'][$key] ?? []);
                if ($values === []) {
                    UserNotificationPreference::where('user_id', $actor->id)->where('key', $key)->delete();

                    continue;
                }
                $event = collect($before['events'])->firstWhere('key', $key);
                $effective = array_replace($event['defaults'], $values);
                UserNotificationPreference::updateOrCreate(['user_id' => $actor->id, 'key' => $key], [
                    'enabled' => $effective['inapp'] || $effective['email'],
                    'channel_inapp' => $effective['inapp'], 'channel_email' => $effective['email'],
                    'channel_push' => false, 'channel_overrides' => $values,
                ]);
            }
            $after = $this->preferences->snapshot($actor);
            if ($before['revision'] !== $after['revision']) {
                AuditLogger::logOrFail('fleet.settings.notifications.updated', $actor, ['actor_id' => $actor->id, 'before' => $before['overrides'], 'after' => $after['overrides']], $request);
            }

            return response()->json($after)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function check(Request $request)
    {
        $data = $request->validate(['key' => ['required', Rule::in(array_keys(FleetNotificationPreferences::EVENTS))]]);
        $snapshot = $this->preferences->snapshot($request->user());
        $event = collect($snapshot['events'])->firstWhere('key', $data['key']);
        $result = [
            'event' => $event['title'], 'revision' => $snapshot['revision'], 'checked_at' => now()->toISOString(),
            'mode' => 'dry_run', 'source' => 'Synthetic example; no real source record or recipient was evaluated.',
            'inapp' => $event['effective']['inapp'] ? 'Would create an in-app copy if the source authorises you.' : 'Skipped by your saved preferences.',
            'email' => ! $event['effective']['email'] ? 'Skipped by your saved preferences.' : ($snapshot['channels']['email']['available'] ? 'Email is configured; delivery and receipt were not tested.' : 'External email is not configured.'),
        ];
        AuditLogger::logOrFail('fleet.settings.notifications.checked', $request->user(), ['result' => $result], $request);

        return response()->json($result);
    }

    public function checks(Request $request)
    {
        return response()->json(AuditLog::where('user_id', $request->user()->id)->where('action', 'fleet.settings.notifications.checked')->latest('id')->limit(5)->get()->map(fn ($log) => ['id' => $log->id, ...$log->meta['result']]))->header('Cache-Control', 'private, no-store');
    }
}
