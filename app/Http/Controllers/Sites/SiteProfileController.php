<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\Profile\SiteProfileAdminPresenter;
use App\Services\Sites\Profile\SiteProfileOperationsPresenter;
use App\Services\Sites\Profile\SiteProfilePeoplePresenter;
use App\Services\Sites\Profile\SiteProfileSafetyPresenter;
use App\Services\Sites\SiteProfileData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SiteProfileController extends Controller
{
    public function __invoke(
        Request $request,
        Site $site,
        SiteProfileData $profile,
        SiteProfilePeoplePresenter $people,
        SiteProfileSafetyPresenter $safety,
        SiteProfileOperationsPresenter $operations,
        SiteProfileAdminPresenter $admin,
    ): Response {
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
            'clientsData' => Inertia::optional(fn () => $people->clients($user, $site)),
            'contactsData' => Inertia::optional(fn () => $people->contacts($user, $site)),
            'staffRequirementsData' => Inertia::optional(fn () => $people->staffRequirements($user, $site)),
            'shiftCoverageData' => Inertia::optional(fn () => $people->shiftCoverage($user, $site)),
            'hazardsData' => Inertia::optional(fn () => $safety->hazards($user, $site)),
            'riskAssessmentsData' => Inertia::optional(fn () => $safety->riskAssessments($user, $site)),
            'inspectionsData' => Inertia::optional(fn () => $safety->inspections($user, $site)),
            'drillsData' => Inertia::optional(fn () => $safety->drills($user, $site)),
            'firstAidData' => Inertia::optional(fn () => $safety->firstAid($user, $site)),
            'ppeData' => Inertia::optional(fn () => $safety->ppe($user, $site)),
            'emergencyPlanData' => Inertia::optional(fn () => $safety->emergencyPlan($user, $site)),
            'calendarData' => Inertia::optional(fn () => $operations->calendar($user, $site)),
            'checklistsData' => Inertia::optional(fn () => $operations->checklists($user, $site)),
            'mealPlannerData' => Inertia::optional(fn () => $operations->mealPlanner($user, $site)),
            'assetsData' => Inertia::optional(fn () => $operations->assets($user, $site)),
            'fleetData' => Inertia::optional(fn () => $operations->fleet($user, $site)),
            'hardwareData' => Inertia::optional(fn () => $operations->hardware($user, $site)),
            'planData' => Inertia::optional(fn () => $operations->plan($user, $site)),
            'documentsData' => Inertia::optional(fn () => $admin->documents($user, $site)),
            'financialsData' => Inertia::optional(fn () => $admin->financials($user, $site)),
            'vendorsCredentialsData' => Inertia::optional(fn () => $admin->vendorsCredentials($user, $site)),
            'servicesData' => Inertia::optional(fn () => $admin->services($user, $site)),
        ]);
    }
}
