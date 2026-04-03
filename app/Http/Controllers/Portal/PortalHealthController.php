<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyPortalSetting;
use App\Models\NextOfKin;
use Illuminate\Http\Request;

class PortalHealthController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $client->load([
            'medicalProfile',
            'medications' => fn ($q) => $q->where('active', true),
            'conditions',
            'supportPlan',
        ]);

        $nok = NextOfKin::where('user_id', $user->id)
            ->where('client_id', $client->id)
            ->first();

        $portalSettings = FamilyPortalSetting::where('client_id', $client->id)->first();

        $permissions = [
            'can_view_medical' => (bool) $nok?->can_view_medical,
            'can_view_medications' => (bool) $nok?->can_view_medications,
            'show_care_plans' => (bool) $portalSettings?->show_care_plans,
            'show_medication_status' => (bool) $portalSettings?->show_medication_status,
        ];

        return inertia('portal/health', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
                'dietary_requirements' => $client->dietary_requirements,
                'mobility_needs' => $client->mobility_needs,
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
