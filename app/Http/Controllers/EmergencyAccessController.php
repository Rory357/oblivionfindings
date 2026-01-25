<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;

class EmergencyAccessController extends Controller
{
    /**
     * Break-glass discovery flow.
     *
     * This endpoint intentionally returns only minimal client identity fields.
     * It exists so authorised staff can request emergency access without
     * broadening the normal Clients list permissions.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.breakglass'), 403);

        $q = trim((string) $request->get('q', ''));

        $results = collect();
        if (mb_strlen($q) >= 2) {
            $results = Client::query()
                ->with('site:id,name')
                ->where(function ($query) use ($q) {
                    $query
                        ->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhereRaw("concat(first_name, ' ', last_name) like ?", ["%{$q}%"]);
                })
                ->orderBy('last_name')
                ->limit(25)
                ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'status', 'site_id'])
                ->map(fn($c) => [
                    'id' => $c->id,
                    'first_name' => $c->first_name,
                    'last_name' => $c->last_name,
                    'date_of_birth' => optional($c->date_of_birth)->format('Y-m-d'),
                    'status' => $c->status,
                    'site' => $c->site?->only(['id', 'name']),
                ]);
        }

        $activeAccesses = $user
            ? $user->breakGlassAccesses()
                ->with('client:id,first_name,last_name')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn($a) => [
                    'id' => $a->id,
                    'client' => $a->client?->only(['id', 'first_name', 'last_name']),
                    'reason' => $a->reason,
                    'expires_at' => $a->expires_at?->toIso8601String(),
                    'created_at' => $a->created_at?->toIso8601String(),
                ])
            : collect();

        return inertia('emergency/access', [
            'query' => $q,
            'results' => $results,
            'activeAccesses' => $activeAccesses,
        ]);
    }
}
