<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\Role;
use App\Models\SsoGroupMapping;
use App\Services\AzureAdGroupService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

class SsoGroupController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        $mappings = SsoGroupMapping::with('role')->orderBy('provider')->orderBy('external_group_name')->get();
        $roles = Role::orderBy('name')->get(['id', 'name', 'label']);

        return Inertia::render('settings/sso-groups', [
            'mappings' => $mappings,
            'roles' => $roles,
            'stats' => [
                'total' => $mappings->count(),
                'microsoft' => $mappings->where('provider', 'microsoft')->count(),
                'google' => $mappings->where('provider', 'google')->count(),
            ],
        ]);
    }

    public function fetchGroups(Request $request, AzureAdGroupService $service)
    {
        $this->authorizeAccess($request);

        $identity = Identity::query()
            ->where('user_id', $request->user()?->id)
            ->where('provider', 'microsoft')
            ->whereNotNull('access_token')
            ->first();

        if (!$identity) {
            return back()->with('error', 'No Microsoft identity found for your account. Please connect a Microsoft account first.');
        }

        if ($identity->isExpired()) {
            return back()->with('error', 'Microsoft token has expired. Please reconnect your Microsoft account.');
        }

        try {
            $groups = $service->getGroups($identity);
        } catch (Throwable) {
            return back()->with('error', 'Could not fetch Microsoft groups. Please try again or reconnect your Microsoft account.');
        }

        return back()->with('groups', $groups);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'provider' => 'required|in:microsoft,google',
            'external_group_id' => 'required|string|max:255',
            'external_group_name' => 'required|string|max:255',
            'role_id' => 'required|exists:roles,id',
            'auto_assign' => 'boolean',
            'auto_remove' => 'boolean',
        ]);

        SsoGroupMapping::create($data);

        return back()->with('success', 'Group mapping created.');
    }

    public function update(Request $request, SsoGroupMapping $mapping)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'auto_assign' => 'boolean',
            'auto_remove' => 'boolean',
        ]);

        $mapping->update($data);

        return back()->with('success', 'Group mapping updated.');
    }

    public function destroy(Request $request, SsoGroupMapping $mapping)
    {
        $this->authorizeAccess($request);

        $mapping->delete();

        return back()->with('success', 'Group mapping deleted.');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
    }
}
