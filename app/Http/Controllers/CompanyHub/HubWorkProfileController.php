<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubDirectory;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubWorkProfiles;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HubWorkProfileController extends Controller
{
    public function show(Request $request, int $person, HubDirectory $directory, HubFeed $feed)
    {
        $profile = $directory->person($request->user(), $person);

        return Inertia::render('company-hub/index', [
            ...$feed->forUser($request->user()), 'view' => 'people', 'selectedPage' => null, 'selectedPerson' => $profile,
            'people' => $directory->people($request->user()),
            'canViewOrg' => $request->user()->canDo('hr.orgchart.view') || $request->user()->canDo('hr.employees.viewAny'),
        ]);
    }

    public function update(Request $request, int $person, HubWorkProfiles $profiles)
    {
        $data = $request->validate([
            'expected_version' => 'required|integer|min:0|max:2147483646',
            'about' => 'present|nullable|string|max:1600',
            'help_with' => 'present|array|max:10',
            'help_with.*' => 'required|string|min:2|max:60',
            'user_id' => 'prohibited', 'bio' => 'prohibited', 'date_of_birth' => 'prohibited',
        ]);

        return response()->json(['person' => $profiles->save($request->user(), $person, $data)], 200, ['Cache-Control' => 'no-store, private']);
    }
}
