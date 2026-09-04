<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteTypePlan;
use App\Support\SiteRecommendedDocuments;

class SiteReadinessService
{
    /**
     * Per-request memo of each site's current published plan. evaluate()
     * needs it for both the emergency and medication items, and the sites
     * index evaluates the same site twice (row payload + saved-view
     * counts) — without this, that was up to 4 plan queries per site.
     *
     * @var array<int, SiteTypePlan|null>
     */
    private array $planMemo = [];

    public function evaluate(Site $site): array
    {
        $critical = [
            $this->item('contact_phone', 'Site phone', 'Sites', filled($site->phone), 'add_phone'),
            $this->item('contact_email', 'Site email', 'Sites', filled($site->email), 'add_email'),
            $this->item('site_lead', 'Site lead / manager', 'Sites', filled($site->primary_contact_user_id) || $this->typedContactsCount($site, 'site_lead_contacts_count', ['site_lead', 'manager']) > 0, 'assign_lead'),
            $this->item('after_hours', 'After-hours / on-call line', 'Sites', $this->typedContactsCount($site, 'after_hours_contacts_count', ['emergency']) > 0, 'add_after_hours'),
            $this->item('emergency_plan', 'Emergency plan & assembly point', 'Sites', filled($site->emergency_plan_location) || $this->planEmergencyReady($site), 'add_emergency_plan'),
            $this->item('med_storage', 'Medication storage details', 'Sites', filled($site->medication_storage_location) || $this->planMedicationReady($site), 'add_med_storage'),
            $this->item('emergency_contact', 'At least one emergency / maintenance contact', 'Sites', $this->emergencyContactsCount($site) > 0, 'add_contact'),
        ];

        $recommendedDocuments = SiteRecommendedDocuments::forType($site->type);
        $recommended = [
            $this->item('required_docs', $recommendedDocuments[0]['label'] ?? 'Required documents uploaded', 'Sites', $this->count($site, 'documents_count', 'documents') > 0, 'upload_doc'),
            $this->item('rooms_configured', 'Capacity / rooms configured', 'Sites', $this->roomsConfigured($site), 'configure_rooms'),
            $this->item('hazards_reviewed', 'Hazards reviewed in last 90 days', 'Sites', $this->hazardsReviewed($site), 'review_hazards'),
            $this->item('checklists_scheduled', 'At least one checklist scheduled', 'Sites', $this->count($site, 'checklist_assignments_count', 'checklistAssignments') > 0, 'schedule_checklist'),
            $this->item('geofence', 'Geofence configured', 'Sites', $this->count($site, 'active_geofences_count', 'geofences', fn () => $site->geofences()->where('is_active', true)->count()) > 0, 'configure_geofence'),
        ];

        $criticalDone = collect($critical)->where('done', true)->count();
        $recommendedDone = collect($recommended)->where('done', true)->count();
        $criticalTotal = count($critical);
        $recommendedTotal = count($recommended);

        return [
            'critical' => $critical,
            'recommended' => $recommended,
            'critical_done' => $criticalDone,
            'critical_total' => $criticalTotal,
            'recommended_done' => $recommendedDone,
            'recommended_total' => $recommendedTotal,
            'missing_critical' => collect($critical)->where('done', false)->pluck('key')->values()->all(),
            'score' => (int) round(($criticalDone * 2 + $recommendedDone) / max(1, ($criticalTotal * 2 + $recommendedTotal)) * 100),
            'is_active' => (bool) $site->is_active,
            'is_active_but_incomplete' => (bool) $site->is_active && $criticalDone < $criticalTotal,
            'recommended_documents' => $recommendedDocuments,
        ];
    }

    public function slim(Site $site): array
    {
        $readiness = $this->evaluate($site);

        return [
            'critical_done' => $readiness['critical_done'],
            'critical_total' => $readiness['critical_total'],
            'recommended_done' => $readiness['recommended_done'],
            'recommended_total' => $readiness['recommended_total'],
            'missing_critical' => $readiness['missing_critical'],
            'score' => $readiness['score'],
            'is_active_but_incomplete' => $readiness['is_active_but_incomplete'],
        ];
    }

    private function item(string $key, string $label, string $area, bool $done, string $action): array
    {
        return compact('key', 'label', 'area', 'done', 'action');
    }

    private function roomsConfigured(Site $site): bool
    {
        return match ($site->type) {
            'house', 'residential' => $this->count($site, 'rooms_total', 'houseRooms', fn () => $site->houseRooms()->active()->count()) > 0,
            'head_office' => $this->count($site, 'ho_resources_count', 'hoResources', fn () => $site->hoResources()->active()->count()) > 0,
            'facility' => $this->count($site, 'facility_zones_count', 'facilityZones', fn () => $site->facilityZones()->active()->count()) > 0,
            default => true,
        };
    }

    private function planEmergencyReady(Site $site): bool
    {
        $plan = $this->currentPlanFor($site);

        return $plan ? app(SiteTypePlanService::class)->hasEmergencyLayer($plan) : false;
    }

    private function planMedicationReady(Site $site): bool
    {
        $plan = $this->currentPlanFor($site);

        return $plan ? app(SiteTypePlanService::class)->hasMedicationPin($plan) : false;
    }

    private function currentPlanFor(Site $site): ?SiteTypePlan
    {
        if (! array_key_exists($site->id, $this->planMemo)) {
            $this->planMemo[$site->id] = app(SiteTypePlanService::class)->currentPublished($site);
        }

        return $this->planMemo[$site->id];
    }

    private function hazardsReviewed(Site $site): bool
    {
        if (array_key_exists('recent_hazards_count', $site->getAttributes())) {
            return (int) $site->getAttribute('recent_hazards_count') > 0 || (int) $site->getAttribute('open_hazards_count') > 0;
        }

        return $site->hazards()->where('updated_at', '>=', now()->subDays(90))->exists()
            || $site->hazards()->exists();
    }

    private function emergencyContactsCount(Site $site): int
    {
        if (array_key_exists('emergency_contacts_count', $site->getAttributes())) {
            return (int) $site->getAttribute('emergency_contacts_count');
        }

        return $site->contacts()
            ->whereIn('type', ['emergency', 'maintenance', 'manager'])
            ->count();
    }

    private function typedContactsCount(Site $site, string $countAttribute, array $types): int
    {
        if (array_key_exists($countAttribute, $site->getAttributes())) {
            return (int) $site->getAttribute($countAttribute);
        }

        if ($site->relationLoaded('contacts')) {
            return $site->contacts
                ->whereIn('type', $types)
                ->count();
        }

        return $site->contacts()
            ->whereIn('type', $types)
            ->count();
    }

    private function count(Site $site, string $countAttribute, string $relation, ?callable $fallback = null): int
    {
        if (array_key_exists($countAttribute, $site->getAttributes())) {
            return (int) $site->getAttribute($countAttribute);
        }

        if ($site->relationLoaded($relation)) {
            return $site->getRelation($relation)->count();
        }

        return $fallback ? (int) $fallback() : (int) $site->{$relation}()->count();
    }
}
