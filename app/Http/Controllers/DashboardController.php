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
        $preference = $this->access->preference($user, $role);
        // Only the blocks on this person's layout are worked out on page load;
        // the editor fetches the rest of the library when it opens.
        $defaultLayout = $this->access->defaultBlockIds($user, $role);
        $layout = $preference['value']['blocks'] !== null ? array_column($preference['value']['blocks'], 'id') : $defaultLayout;

        return inertia('dashboard', [
            'overview' => app(OverviewData::class)->build($user, $role, $range, $site, $layout),
            'role' => $role, 'roles' => $this->access->roles($user), 'defaultRole' => $this->access->defaultRole($user),
            'sites' => $this->access->sites($user, $role), 'filters' => ['range' => $range, 'site' => $site],
            'preference' => $preference, 'defaultLayout' => $defaultLayout,
        ]);
    }

    public function data(Request $request)
    {
        [$role, $range, $site] = $this->filters($request);
        // blocks=all (or no value) works out every library block; a list limits
        // the optional blocks to those named (e.g. the ones pinned to a site).
        $blocks = $request->validate(['blocks' => ['sometimes', 'string', 'max:2000', 'regex:/^(all|[a-z0-9-]+(,[a-z0-9-]+)*)$/']])['blocks'] ?? 'all';
        $only = $blocks === 'all' ? null : array_values(array_intersect(explode(',', $blocks), array_column($this->access->catalog($role)['blocks'], 'id')));

        return response()->json(app(OverviewData::class)->build($request->user(), $role, $range, $site, $only))
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
