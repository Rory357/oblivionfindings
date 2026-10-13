<?php

namespace App\Http\Controllers;

use App\Http\Requests\OverviewLayoutRequest;
use App\Models\User;
use App\Services\Overview\OverviewAccess;
use App\Services\Overview\OverviewData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DashboardController extends Controller
{
    public function __construct(private readonly OverviewAccess $access) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('client', 'next_of_kin')) {
            $clients = $user->portalClients();

            return (clone $clients)->count() === 1
                ? redirect()->route('portal.clients.dashboard', ['client' => $clients->first()->id])
                : redirect()->route('portal.index');
        }
        if ($user->isExternalClinicianAccount()) {
            return redirect()->route('clinical-portal.index');
        }
        [$role, $range, $site] = $this->filters($request);

        return inertia('dashboard', [
            'overview' => app(OverviewData::class)->build($user, $role, $range, $site),
            'role' => $role, 'roles' => $this->access->roles($user), 'defaultRole' => $this->access->defaultRole($user),
            'sites' => $this->access->sites($user, $role), 'filters' => ['range' => $range, 'site' => $site],
            'preference' => $this->access->preference($user, $role),
        ]);
    }

    public function data(Request $request)
    {
        [$role, $range, $site] = $this->filters($request);

        return response()->json(app(OverviewData::class)->build($request->user(), $role, $range, $site))
            ->header('Cache-Control', 'private, no-store');
    }

    private function filters(Request $request): array
    {
        $data = $request->validate(['role' => ['sometimes', Rule::in(OverviewAccess::ROLES)], 'range' => ['sometimes', Rule::in(['today', 'week', 'month', 'quarter'])], 'site' => ['sometimes', 'string', 'regex:/^(all|[1-9][0-9]*)$/']]);
        $role = $data['role'] ?? $this->access->defaultRole($request->user());
        $this->access->authorize($request->user(), $role);
        $range = $data['range'] ?? $this->access->preference($request->user(), $role)['value']['range'];

        return [$role, $range, $data['site'] ?? 'all'];
    }

    public function saveLayout(OverviewLayoutRequest $request, string $role)
    {
        return DB::transaction(function () use ($request, $role) {
            // A user-row lock covers both first save and concurrent saves in other tabs.
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $previous = $request->user()->uiPreferences()->where('key', 'overview.workspace.'.$role)->first();
            $revision = $previous ? hash('sha256', json_encode($previous->value)) : null;
            if ($request->validated('revision') !== $revision) {
                // Send the newer layout so the editor can offer to keep the
                // draft (save over it) or use the other tab's layout.
                $latest = $this->access->preference($request->user(), $role);

                return response()->json([
                    'message' => 'This layout was changed in another tab. Your changes are still here.',
                    'revision' => $latest['revision'],
                    'value' => $latest['value'],
                ], 409);
            }
            $value = $request->validated('value');
            $stored = $request->user()->uiPreferences()->updateOrCreate(['key' => 'overview.workspace.'.$role], ['value' => $value]);

            // MySQL JSON normalises object-key order. Hash the persisted form,
            // exactly as the next read will, rather than the request's key order.
            return response()->json(['value' => $value, 'revision' => hash('sha256', json_encode($stored->fresh()->value))]);
        });
    }

    public function defaultRole(Request $request)
    {
        $role = $request->validate(['role' => ['required', Rule::in($this->access->roles($request->user()))]])['role'];
        $request->user()->uiPreferences()->updateOrCreate(['key' => 'overview.default-role'], ['value' => ['role' => $role]]);

        return response()->json(['role' => $role]);
    }
}
