<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\FirstAidRecord;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FirstAidController extends Controller
{
    /**
     * List first aid records with stats.
     */
    public function index(Request $request): \Inertia\Response
    {
        $filters = $request->only(['site_id', 'treated_person_type', 'injury_illness_type', 'from', 'to', 'q']);

        $records = FirstAidRecord::with(['site:id,name', 'firstAider:id,name'])
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(!empty($filters['treated_person_type']), fn ($q) => $q->where('treated_person_type', $filters['treated_person_type']))
            ->when(!empty($filters['injury_illness_type']), fn ($q) => $q->where('injury_illness_type', $filters['injury_illness_type']))
            ->when(!empty($filters['from']), fn ($q) => $q->where('treatment_date', '>=', $filters['from']))
            ->when(!empty($filters['to']), fn ($q) => $q->where('treatment_date', '<=', $filters['to']))
            ->when(!empty($filters['q']), fn ($q) => $q->where(function ($sub) use ($filters) {
                $sub->where('treated_person_name', 'like', "%{$filters['q']}%")
                    ->orWhere('injury_illness_description', 'like', "%{$filters['q']}%");
            }))
            ->orderByDesc('treatment_date')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $records30d = FirstAidRecord::where('treatment_date', '>=', $thirtyDaysAgo)->count();
        $ambulanceCalls30d = FirstAidRecord::where('treatment_date', '>=', $thirtyDaysAgo)
            ->where('ambulance_called', true)->count();
        $incidentLinked = FirstAidRecord::where('treatment_date', '>=', $thirtyDaysAgo)
            ->where('incident_reported', true)->count();

        return Inertia::render('health-safety/first-aid/index', [
            'records' => $records,
            'filters' => $filters,
            'stats' => [
                'records_30d' => $records30d,
                'ambulance_calls_30d' => $ambulanceCalls30d,
                'linked_to_incidents' => $incidentLinked,
            ],
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'can_create' => $this->canCreate($request),
        ]);
    }

    /**
     * Store a new first aid record.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'treated_person_id' => 'nullable|exists:users,id',
            'treated_person_name' => 'required|string|max:255',
            'treated_person_type' => 'required|in:staff,client,visitor,contractor',
            'treatment_date' => 'required|date',
            'injury_illness_type' => 'required|in:cut,burn,bruise,sprain,fracture,fall,head_injury,eye_injury,allergic_reaction,breathing_difficulty,chest_pain,seizure,fainting,nausea,sting,choking,other',
            'injury_illness_description' => 'required|string',
            'body_part' => 'nullable|string|max:255',
            'treatment_given' => 'required|string',
            'treatment_outcome' => 'required|in:returned_to_activity,returned_to_work,sent_home,medical_centre,sent_to_medical,hospital,sent_to_hospital,ambulance_called,ongoing_monitoring,refused_treatment,other',
            'ambulance_called' => 'boolean',
            'first_aider_id' => 'required|exists:users,id',
            'first_aider_notes' => 'nullable|string',
            'incident_reported' => 'boolean',
            'related_incident_id' => 'nullable|exists:client_incidents,id',
        ]);

        $validated['created_by'] = $request->user()->id;

        FirstAidRecord::create($validated);

        return redirect()->route('health-safety.first-aid.index')
            ->with('success', 'First aid record created.');
    }

    private function canCreate(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('hazards.manage') || $user?->canDo('hazards.create'));
    }
}
