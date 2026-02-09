<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteHouseRoom;
use App\Models\SiteHoResource;
use App\Models\SiteFacilityZone;
use Illuminate\Http\Request;

class SiteOnboardingController extends Controller
{
    public function wizard(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $progress = $site->onboarding_progress ?? [];
        $steps = $this->getSteps($site->type);
        
        // Calculate the last incomplete step to resume from
        $lastCompletedStep = 0;
        foreach ($steps as $index => $stepData) {
            if (isset($progress[$stepData['key']]['completed']) && $progress[$stepData['key']]['completed']) {
                $lastCompletedStep = $index + 1;
            }
        }
        
        // Use URL step param if provided, otherwise resume from last incomplete step
        $requestedStep = $request->input('step');
        if ($requestedStep !== null) {
            $step = (int) $requestedStep;
        } else {
            // Resume from next incomplete step, or step 1 if nothing completed
            $step = $lastCompletedStep > 0 ? min($lastCompletedStep + 1, count($steps)) : 1;
        }

        // Get type-specific data
        $typeSpecificData = match ($site->type) {
            'house' => [
                'rooms' => SiteHouseRoom::where('site_id', $site->id)->get(),
            ],
            'head_office' => [
                'resources' => SiteHoResource::where('site_id', $site->id)->get(),
                'settings' => $site->hoSettings()->first(),
            ],
            'facility' => [
                'zones' => SiteFacilityZone::where('site_id', $site->id)->get(),
            ],
            default => [],
        };

        // Get available checklist templates for this site type
        $checklistTemplates = SiteChecklistTemplate::active()
            ->forType($site->type)
            ->get();

        return inertia('sites/onboarding/wizard', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'phone' => $site->phone,
                'email' => $site->email,
                'manager_name' => $site->manager_name,
                'manager_phone' => $site->manager_phone,
                'after_hours_phone' => $site->after_hours_phone,
                'emergency_plan_location' => $site->emergency_plan_location,
                'medication_storage_location' => $site->medication_storage_location,
                'onboarding_progress' => $progress,
                'onboarding_completed_at' => $site->onboarding_completed_at?->toDateTimeString(),
            ],
            'currentStep' => $step,
            'typeSpecificData' => $typeSpecificData,
            'checklistTemplates' => $checklistTemplates,
            'steps' => $steps,
        ]);
    }

    public function saveStep(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $step = $request->input('step');
        $data = $request->input('data', []);

        $progress = $site->onboarding_progress ?? [];
        $progress[$step] = [
            'completed' => true,
            'data' => $data,
            'completed_at' => now()->toDateTimeString(),
        ];

        $site->update(['onboarding_progress' => $progress]);

        // Handle type-specific saves
        match ($step) {
            'basic' => $this->saveBasicInfo($site, $data),
            'rooms' => $this->saveHouseRooms($site, $data),
            'resources' => $this->saveHoResources($site, $data),
            'zones' => $this->saveFacilityZones($site, $data),
            'checklists' => $this->saveChecklistAssignments($site, $data),
            default => null,
        };

        return response()->json(['success' => true]);
    }

    public function complete(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $site->update([
            'onboarding_completed_at' => now(),
            'is_active' => true,
        ]);

        return redirect()
            ->route('sites.show', $site)
            ->with('success', 'Site onboarding completed successfully.');
    }

    private function getSteps(string $type): array
    {
        $commonSteps = [
            ['key' => 'basic', 'label' => 'Basic Information', 'required' => true],
            ['key' => 'contacts', 'label' => 'Contacts & Vendors', 'required' => false],
            ['key' => 'assets', 'label' => 'Initial Assets', 'required' => false],
            ['key' => 'documents', 'label' => 'Key Documents', 'required' => false],
            ['key' => 'checklists', 'label' => 'Checklist Scheduling', 'required' => false],
        ];

        $typeSpecificStep = match ($type) {
            'house' => ['key' => 'rooms', 'label' => 'Bedrooms', 'required' => true],
            'head_office' => ['key' => 'resources', 'label' => 'Rooms & Resources', 'required' => false],
            'facility' => ['key' => 'zones', 'label' => 'Areas & Zones', 'required' => false],
            default => null,
        };

        if ($typeSpecificStep) {
            // Insert after basic info
            array_splice($commonSteps, 1, 0, [$typeSpecificStep]);
        }

        return $commonSteps;
    }

    private function saveBasicInfo(Site $site, array $data): void
    {
        $site->update([
            'phone' => $data['phone'] ?? $site->phone,
            'email' => $data['email'] ?? $site->email,
            'manager_name' => $data['manager_name'] ?? $site->manager_name,
            'manager_phone' => $data['manager_phone'] ?? $site->manager_phone,
            'after_hours_phone' => $data['after_hours_phone'] ?? $site->after_hours_phone,
            'emergency_plan_location' => $data['emergency_plan_location'] ?? $site->emergency_plan_location,
            'medication_storage_location' => $data['medication_storage_location'] ?? $site->medication_storage_location,
        ]);
    }

    private function saveHouseRooms(Site $site, array $data): void
    {
        if (!empty($data['rooms'])) {
            foreach ($data['rooms'] as $roomData) {
                if (!empty($roomData['id'])) {
                    SiteHouseRoom::where('id', $roomData['id'])->update([
                        'name' => $roomData['name'],
                        'notes' => $roomData['notes'] ?? null,
                    ]);
                } else {
                    SiteHouseRoom::create([
                        'site_id' => $site->id,
                        'name' => $roomData['name'],
                        'notes' => $roomData['notes'] ?? null,
                        'is_active' => true,
                    ]);
                }
            }
        }
    }

    private function saveHoResources(Site $site, array $data): void
    {
        if (!empty($data['resources'])) {
            foreach ($data['resources'] as $resourceData) {
                if (!empty($resourceData['id'])) {
                    SiteHoResource::where('id', $resourceData['id'])->update($resourceData);
                } else {
                    SiteHoResource::create([
                        'site_id' => $site->id,
                        ...$resourceData,
                        'is_active' => true,
                    ]);
                }
            }
        }
    }

    private function saveFacilityZones(Site $site, array $data): void
    {
        if (!empty($data['zones'])) {
            foreach ($data['zones'] as $zoneData) {
                if (!empty($zoneData['id'])) {
                    SiteFacilityZone::where('id', $zoneData['id'])->update($zoneData);
                } else {
                    SiteFacilityZone::create([
                        'site_id' => $site->id,
                        ...$zoneData,
                        'is_active' => true,
                    ]);
                }
            }
        }
    }

    private function saveChecklistAssignments(Site $site, array $data): void
    {
        if (!empty($data['assignments'])) {
            foreach ($data['assignments'] as $assignment) {
                if ($assignment['enabled'] ?? false) {
                    SiteChecklistAssignment::updateOrCreate(
                        [
                            'site_id' => $site->id,
                            'template_id' => $assignment['template_id'],
                        ],
                        [
                            'frequency' => $assignment['frequency'],
                            'start_date' => $assignment['start_date'],
                            'assigned_to_user_id' => $assignment['assigned_to_user_id'] ?? null,
                            'is_active' => true,
                        ]
                    );
                }
            }
        }
    }
}
