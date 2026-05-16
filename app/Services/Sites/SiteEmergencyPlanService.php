<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteTypePlan;
use App\Models\SiteTypePlanPin;
use App\Support\OrgBranding;

class SiteEmergencyPlanService
{
    public function __construct(
        private readonly SiteTypePlanService $plans,
    ) {}

    public function readyToExport(SiteTypePlan $plan): bool
    {
        return $this->plans->hasEmergencyLayer($plan);
    }

    public function viewModel(Site $site, SiteTypePlan $plan): array
    {
        $plan->loadMissing('pins');
        $emergencyPins = $plan->pins
            ->whereIn('kind', SiteTypePlanPin::EMERGENCY_KINDS)
            ->values();

        return [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'address' => $site->address,
                'phone' => $site->phone,
            ],
            'organisation' => [
                'name' => OrgBranding::name(),
                'logo_url' => OrgBranding::logoUrl(),
            ],
            'plan' => $this->plans->serializePlan($plan, true),
            'ready' => $this->readyToExport($plan),
            'svg' => $this->plans->renderLayoutSvg($plan, $emergencyPins),
            'legend' => $this->legend($emergencyPins),
            'assembly_points' => $emergencyPins->where('kind', SiteTypePlanPin::KIND_ASSEMBLY_POINT)->map(fn ($pin) => $this->plans->serializePin($pin))->values()->all(),
            'contacts' => $this->contacts($site),
            'procedures' => $this->standardProcedures(),
            'support_notes' => $plan->notes,
            'footer' => 'Generated from '.OrgBranding::name().' - '.$this->plans->tabLabel($site),
        ];
    }

    public function contacts(Site $site): array
    {
        $contacts = [[
            'name' => 'Emergency services',
            'role' => 'Police / Fire / Ambulance',
            'phone' => '111',
            'email' => null,
        ]];

        $siteContacts = $site->contacts()
            ->whereIn('type', ['emergency', 'manager', 'site_lead', 'after_hours'])
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        foreach ($siteContacts as $contact) {
            $contacts[] = [
                'name' => $contact->name,
                'role' => $contact->role ?: str_replace('_', ' ', (string) $contact->type),
                'phone' => $contact->phone,
                'email' => $contact->email,
            ];
        }

        return $contacts;
    }

    public function standardProcedures(): array
    {
        return [
            'Raise the alarm and alert everyone nearby.',
            'Call 111 and give the site name and address.',
            'Evacuate using the nearest safe exit.',
            'Move to the assembly point shown on the plan.',
            'Account for residents, staff, and visitors.',
            'Do not re-enter until emergency services say it is safe.',
        ];
    }

    private function legend(iterable $pins): array
    {
        return collect($pins)
            ->groupBy('kind')
            ->map(fn ($items, $kind) => [
                'kind' => $kind,
                'label' => str($kind)->replace('_', ' ')->title()->toString(),
                'count' => $items->count(),
            ])
            ->values()
            ->all();
    }
}

