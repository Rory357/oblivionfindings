<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\SiteProfileData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SiteProfileController extends Controller
{
    public function __invoke(Request $request, Site $site, SiteProfileData $profile): Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);

        $this->authorize('view', $site);

        $shell = null;
        $resolveShell = function () use (&$shell, $profile, $user, $site): array {
            return $shell ??= $profile->shell($user, $site);
        };

        return Inertia::render('sites/show', [
            'site' => fn () => $resolveShell()['site'],
            'hero' => fn () => $resolveShell()['hero'],
            'permissions' => fn () => $resolveShell()['permissions'],
            'attention' => fn () => $resolveShell()['attention'],
            'overview' => fn () => $resolveShell()['overview'],
            'readiness' => fn () => $resolveShell()['readiness'],
            'uiPreferences' => fn () => $resolveShell()['uiPreferences'],
            'peopleData' => Inertia::optional(fn () => $profile->people($user, $site)),
            'safetyData' => Inertia::optional(fn () => $profile->safety($user, $site)),
            'operationsData' => Inertia::optional(fn () => $profile->operations($user, $site)),
            'adminData' => Inertia::optional(fn () => $profile->admin($user, $site)),
        ]);
    }
}
