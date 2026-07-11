<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Http\Request;

class PortalHealthController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $sectionAccess = app(PortalClientSectionAccess::class)->for($user, $client);
        $permissions = [
            'can_view_medical' => $sectionAccess['can_view_medical'],
            'can_view_medications' => $sectionAccess['can_view_medications'],
            'show_care_plans' => $sectionAccess['show_care_plans'],
            'show_medication_status' => $sectionAccess['show_medication_status'],
        ];

        $clientRelations = [];
        if ($permissions['show_care_plans']) {
            $clientRelations[] = 'supportPlan';
        }
        if ($permissions['can_view_medical']) {
            $clientRelations[] = 'medicalProfile';
            $clientRelations[] = 'conditions';
        }
        if ($permissions['can_view_medications']) {
            $clientRelations['medications'] = fn ($query) => $query->where('active', true);
        }
        $client->load($clientRelations);

        return inertia('portal/health', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
                'dietary_requirements' => $permissions['can_view_medical']
                    ? $client->dietary_requirements
                    : null,
                'mobility_needs' => $permissions['can_view_medical']
                    ? $client->mobility_needs
                    : null,
            ],
            'medicalProfile' => $permissions['can_view_medical'] ? $client->medicalProfile : null,
            'medications' => $permissions['can_view_medications']
                ? $client->medications->values()
                : [],
            'conditions' => $permissions['can_view_medical']
                ? $client->conditions->values()
                : [],
            'carePlan' => $permissions['show_care_plans'] && $client->supportPlan ? [
                'goals' => $client->supportPlan->goals,
                'important_to_me' => $client->supportPlan->important_to_me,
            ] : null,
            'permissions' => $permissions,
        ]);
    }
}
