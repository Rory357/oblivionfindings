<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\UserPushSubscription;
use App\Services\Chat\ChatBrowserProtocol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PushSubscriptionController extends Controller
{
    public function status(Request $request)
    {
        $data = $request->validate([
            'actor_id' => ['required', 'integer'],
            'token' => ['required', 'string', 'max:512'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);
        $this->assertBrowserActor($request, $data);
        $subscription = UserPushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', 'webpush')
            ->where('token', $data['token'])->first();

        $enabled = $subscription?->enabled === true && $this->sameKeys($subscription, $data);

        return response()->json([
            'enabled' => $enabled,
            'chat_ready' => $enabled && ChatBrowserProtocol::supports($subscription),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', Rule::in(['expo', 'webpush'])],
            'actor_id' => ['required_if:provider,webpush', 'integer'],
            'token' => ['required', 'string', 'max:512'],
            'keys' => ['nullable', 'array'],
            'keys.p256dh' => ['required_if:provider,webpush', 'string'],
            'keys.auth' => ['required_if:provider,webpush', 'string'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:32'],
            'chat_protocol_version' => ['sometimes', 'integer', Rule::in([0, ChatBrowserProtocol::VERSION])],
        ]);

        $provider = $data['provider'] ?? 'expo';
        if ($provider === 'webpush') {
            $this->assertBrowserActor($request, $data);
        }

        $subscription = UserPushSubscription::query()->updateOrCreate(
            [
                'provider' => $provider,
                'token' => $data['token'],
            ],
            [
                'user_id' => $request->user()->id,
                'keys' => $provider === 'webpush' ? [
                    'p256dh' => $data['keys']['p256dh'] ?? null,
                    'auth' => $data['keys']['auth'] ?? null,
                ] : null,
                'device_id' => $data['device_id'] ?? null,
                'platform' => $data['platform'] ?? null,
                'enabled' => true,
                'last_used_at' => now(),
                'chat_protocol_version' => $provider === 'webpush' ? ($data['chat_protocol_version'] ?? 0) : 0,
                // Every explicit registration invalidates earlier queued/displayed Chat receipts,
                // including downgrade followed by upgrade with the same endpoint and keys.
                'chat_registration_id' => $provider === 'webpush' ? (string) Str::uuid() : null,
            ],
        );

        return response()->json([
            'id' => $subscription->id,
            'provider' => $subscription->provider,
            'enabled' => $subscription->enabled,
        ], 201)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request)
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', Rule::in(['expo', 'webpush'])],
            'actor_id' => ['required_if:provider,webpush', 'integer'],
            'token' => ['required', 'string', 'max:512'],
            'keys.p256dh' => ['required_if:provider,webpush', 'string'],
            'keys.auth' => ['required_if:provider,webpush', 'string'],
        ]);

        if (($data['provider'] ?? 'expo') === 'webpush') {
            $this->assertBrowserActor($request, $data);
            DB::transaction(function () use ($request, $data): void {
                $subscription = UserPushSubscription::query()
                    ->where('user_id', $request->user()->id)
                    ->where('provider', 'webpush')->where('token', $data['token'])
                    ->lockForUpdate()->first();
                if ($subscription) {
                    abort_unless($this->sameKeys($subscription, $data), 409, 'This browser subscription has changed. Refresh this page.');
                    $subscription->update(['enabled' => false]);
                }
            });

            return response()->noContent()->header('Cache-Control', 'private, no-store');
        }

        UserPushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', $data['provider'] ?? 'expo')
            ->where('token', $data['token'])
            ->update(['enabled' => false]);

        return response()->noContent();
    }

    private function assertBrowserActor(Request $request, array $data): void
    {
        // The expected actor is a stale-tab guard, never an authority supplied by the browser.
        abort_unless((string) $data['actor_id'] === (string) $request->user()->id, 409,
            'The signed-in account has changed. Refresh this page.');
    }

    private function sameKeys(UserPushSubscription $subscription, array $data): bool
    {
        return $subscription->keys === [
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
        ];
    }
}
