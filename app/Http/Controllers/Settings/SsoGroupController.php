<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\Role;
use App\Models\SsoGroupMapping;
use App\Services\AzureAdGroupService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SsoGroupController extends Controller
{
    public function index()
    {
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
        // Find an admin user's Microsoft identity to query groups
        $identity = Identity::where('provider', 'microsoft')
            ->whereNotNull('access_token')
            ->first();

        if (!$identity) {
            return back()->with('error', 'No Microsoft identity found. Please connect a Microsoft account first.');
        }

        if ($identity->isExpired()) {
            return back()->with('error', 'Microsoft token has expired. Please reconnect your Microsoft account.');
        }

        $groups = $service->getGroups($identity);

        return back()->with('groups', $groups);
    }

    public function store(Request $request)
    {
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
        $data = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'auto_assign' => 'boolean',
            'auto_remove' => 'boolean',
        ]);

        $mapping->update($data);

        return back()->with('success', 'Group mapping updated.');
    }

    public function destroy(SsoGroupMapping $mapping)
    {
        $mapping->delete();

        return back()->with('success', 'Group mapping deleted.');
    }
}
