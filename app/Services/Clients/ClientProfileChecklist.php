<?php

namespace App\Services\Clients;

use App\Models\Client;

/** Record-presence checklist only; never evidence of approval or permission to start care. */
class ClientProfileChecklist
{
    public const COUNTS = [
        'portalUsers', 'nextOfKins', 'medications', 'conditions', 'emergencyContacts',
        'assessments', 'supportPlan', 'carePlans', 'documents', 'personalAssets',
    ];

    public const LABELS = [
        'profile' => 'Profile details',
        'next_of_kin' => 'Next of kin / portal contacts',
        'medications' => 'Medications',
        'conditions' => 'Medical conditions',
        'emergency_contacts' => 'Emergency contacts',
        'history' => 'Assessments or care / support plans',
        'documents' => 'Documents saved to this profile',
        'personal_assets' => 'Personal belongings registered',
    ];

    /** Caller must authorize profile/onboarding access. Counts reveal no record contents. */
    public function build(Client $client): array
    {
        $overrides = $client->onboardingOverrides->keyBy('key');
        $present = [
            'profile' => (bool) ($client->first_name && $client->last_name)
                && (bool) $client->date_of_birth
                && (bool) ($client->phone || $client->email)
                && (bool) ($client->address_line_1 || $client->city || $client->postcode),
            'next_of_kin' => $client->portal_users_count > 0 || $client->next_of_kins_count > 0,
            'medications' => $client->medications_count > 0,
            'conditions' => $client->conditions_count > 0,
            'emergency_contacts' => $client->emergency_contacts_count > 0,
            'history' => $client->assessments_count > 0 || $client->support_plan_count > 0 || $client->care_plans_count > 0,
            'documents' => $client->documents_count > 0,
            'personal_assets' => $client->personal_assets_count > 0,
        ];
        $items = [];
        foreach (self::LABELS as $key => $label) {
            $override = (bool) ($overrides->get($key)?->value ?? false);
            $items[] = ['key' => $key, 'label' => $label, 'has_data' => $present[$key],
                'override' => $override, 'complete' => $present[$key] || $override];
        }
        $completed = count(array_filter($items, fn ($item) => $item['complete']));
        $total = count($items);

        return ['items' => $items, 'completed' => $completed, 'total' => $total,
            'percent' => (int) round($completed / $total * 100),
            'status' => $completed === $total ? 'complete' : 'incomplete'];
    }
}
