<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\MedicationAdminRule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Facility medication administration rules (1CHART §6.1).
 *
 * Lets clinical managers define rules so that any medication whose name / route /
 * NZULM code matches a keyword will, at the point of administration, prompt for a
 * countersignature and/or require a clinical observation (BSL, pulse, BP) — without
 * a code change. Enforcement lives in MedicationRuleService + EnhancedMarService;
 * this controller is the authoring surface the plan (PR 4) was missing.
 */
class MedicationSettingsController extends Controller
{
    /**
     * Observation tokens must match EnhancedMarService::validateRequiredObservations().
     */
    public const OBSERVATION_OPTIONS = [
        ['value' => 'blood_glucose', 'label' => 'Blood glucose (BSL)'],
        ['value' => 'pulse', 'label' => 'Pulse rate'],
        ['value' => 'blood_pressure', 'label' => 'Blood pressure'],
    ];

    public const MATCH_TYPES = [
        ['value' => 'medicine_name', 'label' => 'Medicine name'],
        ['value' => 'route', 'label' => 'Route'],
        ['value' => 'nzulm_code', 'label' => 'NZULM code'],
    ];

    public function index(Request $request)
    {
        $rules = MedicationAdminRule::query()
            ->with(['site:id,name', 'creator:id,name'])
            ->orderByDesc('active')
            ->orderBy('match_type')
            ->orderBy('match_value')
            ->get()
            ->map(fn (MedicationAdminRule $rule) => [
                'id' => $rule->id,
                'site_id' => $rule->site_id,
                'site_name' => $rule->site?->name,
                'match_type' => $rule->match_type,
                'match_value' => $rule->match_value,
                'requires_countersign' => $rule->requires_countersign,
                'required_observations' => $rule->required_observations ?? [],
                'active' => $rule->active,
                'created_by' => $rule->creator?->name,
                'created_at' => $rule->created_at?->toDateString(),
            ]);

        return Inertia::render('emar/Settings', [
            'rules' => $rules,
            'sites' => Site::orderBy('name')->get(['id', 'name']),
            'observationOptions' => self::OBSERVATION_OPTIONS,
            'matchTypes' => self::MATCH_TYPES,
            'can' => [
                'manage' => $this->canManageSettings($request->user()),
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManageSettings($request->user()), 403);

        $validated = $this->validateRule($request);

        MedicationAdminRule::create([
            ...$validated,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()->back()->with('success', 'Medication administration rule added.');
    }

    public function update(Request $request, MedicationAdminRule $rule)
    {
        abort_unless($this->canManageSettings($request->user()), 403);

        $rule->update($this->validateRule($request));

        return redirect()->back()->with('success', 'Medication administration rule updated.');
    }

    public function destroy(Request $request, MedicationAdminRule $rule)
    {
        abort_unless($this->canManageSettings($request->user()), 403);

        $rule->delete();

        return redirect()->back()->with('success', 'Medication administration rule removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request): array
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'match_type' => ['required', 'string', Rule::in(['medicine_name', 'route', 'nzulm_code'])],
            'match_value' => ['required', 'string', 'max:255'],
            'requires_countersign' => ['nullable', 'boolean'],
            'required_observations' => ['nullable', 'array'],
            'required_observations.*' => ['string', Rule::in(['blood_glucose', 'pulse', 'blood_pressure'])],
            'active' => ['nullable', 'boolean'],
        ]);

        return [
            'site_id' => $validated['site_id'] ?? null,
            'match_type' => $validated['match_type'],
            'match_value' => trim($validated['match_value']),
            'requires_countersign' => (bool) ($validated['requires_countersign'] ?? false),
            'required_observations' => array_values(array_unique($validated['required_observations'] ?? [])),
            'active' => (bool) ($validated['active'] ?? true),
        ];
    }

    private function canManageSettings(?User $user): bool
    {
        return (bool) $user && (
            $user->canDo('medications.settings.manage')
            || $user->canDo('medications.orders.manage')
            || $user->canDo('clients.update')
        );
    }
}
