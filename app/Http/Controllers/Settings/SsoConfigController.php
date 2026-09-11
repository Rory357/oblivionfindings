<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SsoGroupMapping;
use App\Services\SsoConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SsoConfigController extends Controller
{
    public function index(Request $request, SsoConfigurationService $settings): Response
    {
        $this->authorizeAccess($request);

        return Inertia::render('settings/sso-config', [
            'providers' => collect(SsoConfigurationService::PROVIDERS)->mapWithKeys(fn (string $provider): array => [$provider => $settings->presentProvider($provider)]),
            'provisioning' => $settings->provisioning(),
            'mappings' => SsoGroupMapping::with('role:id,name,label')->orderBy('provider')->get(),
            'roles' => Role::select('id', 'name', 'label')->orderBy('label')->get(),
            'stats' => [
                'total' => SsoGroupMapping::count(),
                'microsoft' => SsoGroupMapping::where('provider', 'microsoft')->count(),
                'google' => SsoGroupMapping::where('provider', 'google')->count(),
            ],
        ]);
    }

    public function updateProvider(Request $request, string $provider, SsoConfigurationService $settings): JsonResponse
    {
        $this->authorizeAccess($request);
        $saved = $settings->saveProvider($request->user(), $provider, $request->all());

        return response()->json(['status' => 'saved', 'configuration' => $saved])->header('Cache-Control', 'no-store, private');
    }

    public function updateProvisioning(Request $request, SsoConfigurationService $settings): JsonResponse
    {
        $this->authorizeAccess($request);
        $saved = $settings->saveProvisioning($request->user(), $request->all());

        return response()->json(['status' => 'saved', 'configuration' => $saved])->header('Cache-Control', 'no-store, private');
    }

    public function showProvider(Request $request, string $provider, SsoConfigurationService $settings): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json(['configuration' => $provider === 'provisioning' ? $settings->provisioning() : $settings->presentProvider($provider)])->header('Cache-Control', 'no-store, private');
    }

    public function check(Request $request, string $provider, SsoConfigurationService $settings): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json($settings->check($provider))->header('Cache-Control', 'no-store, private');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
    }
}
