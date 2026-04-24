<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\UserPushSubscription;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushSubscriptionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', Rule::in(['expo'])],
            'token' => ['required', 'string', 'max:512'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:32'],
        ]);

        $subscription = UserPushSubscription::query()->updateOrCreate(
            [
                'provider' => $data['provider'] ?? 'expo',
                'token' => $data['token'],
            ],
            [
                'user_id' => $request->user()->id,
                'device_id' => $data['device_id'] ?? null,
                'platform' => $data['platform'] ?? null,
                'enabled' => true,
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'id' => $subscription->id,
            'provider' => $subscription->provider,
            'enabled' => $subscription->enabled,
        ], 201);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', Rule::in(['expo'])],
            'token' => ['required', 'string', 'max:512'],
        ]);

        UserPushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', $data['provider'] ?? 'expo')
            ->where('token', $data['token'])
            ->update(['enabled' => false]);

        return response()->noContent();
    }
}
