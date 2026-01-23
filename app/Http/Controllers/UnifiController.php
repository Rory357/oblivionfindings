<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\UnifiConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class UnifiController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('unifi.manage'), 403);

        $sites = Site::query()->orderBy('name')->get(['id', 'name']);
        $connections = UnifiConnection::query()->get()->keyBy('site_id');

        return inertia('integrations/unifi', [
            'sites' => $sites->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'connection' => $connections->has($s->id) ? [
                    'id' => $connections[$s->id]->id,
                    'base_url' => $connections[$s->id]->base_url,
                    'controller_type' => $connections[$s->id]->controller_type,
                    'verify_tls' => $connections[$s->id]->verify_tls,
                    'status' => $connections[$s->id]->status,
                    'last_synced_at' => optional($connections[$s->id]->last_synced_at)->toISOString(),
                    'last_error' => $connections[$s->id]->last_error,
                ] : null,
            ])->values(),
        ]);
    }

    public function upsert(Request $request, Site $site)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('unifi.manage'), 403);

        $data = $request->validate([
            'base_url' => ['required', 'string', 'max:255'],
            'controller_type' => ['required', 'in:unifi_os,network_application'],
            'verify_tls' => ['required', 'in:0,1'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string'],
        ]);

        $conn = UnifiConnection::query()->updateOrCreate(
            ['site_id' => $site->id],
            [
                'base_url' => $data['base_url'],
                'controller_type' => $data['controller_type'],
                'verify_tls' => $data['verify_tls'],
                'username' => $data['username'] ?? null,
                'password_encrypted' => isset($data['password']) && $data['password'] !== '' ? Crypt::encryptString($data['password']) : null,
                'api_token_encrypted' => isset($data['api_token']) && $data['api_token'] !== '' ? Crypt::encryptString($data['api_token']) : null,
                'status' => 'inactive',
                'created_by' => $user->id,
            ]
        );

        return back()->with('status', 'UniFi settings saved.');
    }

    public function sync(Request $request, Site $site)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('unifi.manage'), 403);

        $conn = UnifiConnection::query()->where('site_id', $site->id)->first();
        abort_unless($conn, 404);

        // MVP: mark as synced and create a timeline event. Replace with real polling later.
        $conn->forceFill([
            'last_synced_at' => now(),
            'status' => 'ok',
            'last_error' => null,
        ])->save();

        TimelineEvent::create([
            'type' => 'unifi_sync',
            'occurred_at' => now(),
            'site_id' => $site->id,
            'actor_user_id' => $user->id,
            'subject' => 'UniFi sync completed',
            'body' => 'Sync was triggered manually from Integrations → UniFi.',
            'meta' => ['site_id' => $site->id],
            'visibility' => 'internal',
            'created_by' => $user->id,
        ]);

        return back()->with('status', 'UniFi sync triggered (MVP).');
    }
}
