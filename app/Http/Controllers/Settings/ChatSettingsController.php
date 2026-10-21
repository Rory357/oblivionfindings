<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Chat\ChatResponseSchedule;
use App\Services\Chat\ChatServiceHealth;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\ChatUrgentContact;
use App\Services\Chat\SelfHostedCalling;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ChatSettingsController extends Controller
{
    public function serviceHealth(Request $request, ChatServiceHealth $health)
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);

        return response()->json($health->snapshot())->header('Cache-Control', 'private, no-store');
    }

    public function configuration(Request $request, ChatSettings $settings)
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);

        return response()->json(['settings' => $settings->read()])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, ChatSettings $settings)
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);

        return inertia('settings/chat', [
            'chatSettings' => $settings->read(),
            'chatSites' => Site::query()->where('is_active', true)->where('archived', false)->orderBy('name')->get(['id', 'name']),
            'callingReady' => $this->callingReady(),
            // Settings access alone never authorises private chat inspection.
            'chatInvestigator' => app(\App\Services\Chat\ChatInvestigations::class)->isInvestigator($request->user()),
        ]);
    }

    public function update(Request $request, ChatSettings $settings)
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
        $rules = [
            'version' => ['required', 'integer', 'min:0'],
            'enabled' => ['required', 'boolean'],
            'family_enabled' => ['required', 'boolean'],
            'files_enabled' => ['required', 'boolean'],
            'voice_notes_enabled' => ['sometimes', 'boolean'],
            'max_file_mb' => ['required', 'integer', 'between:1,20'],
            'max_files' => ['required', 'integer', 'between:1,5'],
            'message_change_window_minutes' => ['present', 'nullable', 'integer', 'between:0,10080'],
            'calls_enabled' => ['required', 'boolean'],
            'family_calls_enabled' => ['required', 'boolean'],
            'max_call_participants' => ['required', 'integer', 'between:2,8'],
            'response_expectation' => ['present', 'nullable', 'string', 'max:500'],
            'response_schedule' => ['sometimes', 'nullable', 'array'],
            'urgent_contact' => ['sometimes', 'nullable', 'array'],
            'site_overrides' => ['present', 'array', 'max:200'],
            'site_overrides.*' => ['array:'.implode(',', ChatSettings::OVERRIDABLE)],
        ];
        foreach (ChatSettings::OVERRIDABLE as $field) {
            $rules['site_overrides.*.'.$field] = ['sometimes', ...array_values(array_diff($rules[$field], ['required', 'present', 'sometimes']))];
        }
        $data = $request->validate($rules);
        $current = $settings->read();
        foreach (array_keys($data['site_overrides']) as $siteId) {
            $unchanged = ($current['site_overrides'][$siteId] ?? null) === $data['site_overrides'][$siteId];
            abort_unless(ctype_digit((string) $siteId) && ($unchanged || Site::whereKey($siteId)->where('is_active', true)->where('archived', false)->exists()), 422, 'Choose an active site for each changed override.');
        }
        $schedule = app(ChatResponseSchedule::class);
        if (array_key_exists('response_schedule', $data)) {
            $data['response_schedule'] = $schedule->validate($data['response_schedule']);
        }
        if (array_key_exists('urgent_contact', $data)) {
            $data['urgent_contact'] = app(ChatUrgentContact::class)->validate($data['urgent_contact']);
        }
        foreach ($data['site_overrides'] as $siteId => &$override) {
            if (array_key_exists('urgent_contact', $override)) {
                try {
                    $override['urgent_contact'] = app(ChatUrgentContact::class)->validate($override['urgent_contact']);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ["site_overrides.$siteId.$field" => $messages])->all());
                }
            }
            if (array_key_exists('response_expectation', $override)) {
                $override['response_expectation'] ??= '';
            }
            if (array_key_exists('response_schedule', $override)) {
                // Validate in a separate root; remap field paths for the settings form.
                try {
                    $override['response_schedule'] = $schedule->validate($override['response_schedule']);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ["site_overrides.$siteId.$field" => $messages])->all());
                }
            }
        }
        unset($override);
        foreach ([null, ...array_keys($data['site_overrides'])] as $siteId) {
            $effective = ChatSettings::effective($data, $siteId === null ? null : (int) $siteId);
            $previous = ChatSettings::effective($current, $siteId === null ? null : (int) $siteId);
            $enabling = ($effective['calls_enabled'] && ! $previous['calls_enabled'])
                || ($effective['family_calls_enabled'] && ! $previous['family_calls_enabled'])
                || ($effective['enabled'] && ! $previous['enabled'] && $effective['calls_enabled']);
            abort_if($enabling && ! $this->callingReady(), 422, 'The self-hosted calling service must be configured and verified before calls can be enabled.');
            abort_if($effective['family_calls_enabled'] && (! $effective['calls_enabled'] || ! $effective['family_enabled']), 422, 'Enable staff calling and family Chat in each affected scope before enabling family calls.');
        }
        $data['response_expectation'] = $data['response_expectation'] ?? '';

        return response()->json(['settings' => $settings->save($request->user(), $data)]);
    }

    private function callingReady(): bool
    {
        return app(SelfHostedCalling::class)->ready();
    }

    public function callingHealth(Request $request, SelfHostedCalling $calling)
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
        $configured = $calling->configured();

        return response()->json([
            'configured' => $configured,
            'reachable' => $configured && $calling->health(),
            'verified' => $calling->ready(),
            'checked_at' => now()->toISOString(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
