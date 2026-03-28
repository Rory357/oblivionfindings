<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\FirstAidRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class FirstAidController extends Controller
{
    /**
     * List first aid records with stats.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $filters = $request->only(['site_id', 'treated_person_type', 'injury_illness_type', 'from', 'to', 'q']);

        $query = DB::table('first_aid_records')
            ->join('sites', 'first_aid_records.site_id', '=', 'sites.id')
            ->join('users as first_aider', 'first_aid_records.first_aider_id', '=', 'first_aider.id')
            ->whereNull('first_aid_records.deleted_at')
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('first_aid_records.site_id', $filters['site_id']))
            ->when(!empty($filters['treated_person_type']), fn ($q) => $q->where('first_aid_records.treated_person_type', $filters['treated_person_type']))
            ->when(!empty($filters['injury_illness_type']), fn ($q) => $q->where('first_aid_records.injury_illness_type', $filters['injury_illness_type']))
            ->when(!empty($filters['from']), fn ($q) => $q->where('first_aid_records.treatment_date', '>=', $filters['from']))
            ->when(!empty($filters['to']), fn ($q) => $q->where('first_aid_records.treatment_date', '<=', $filters['to']))
            ->when(!empty($filters['q']), fn ($q) => $q->where(function ($sub) use ($filters) {
                $sub->where('first_aid_records.treated_person_name', 'like', "%{$filters['q']}%")
                    ->orWhere('first_aid_records.injury_illness_description', 'like', "%{$filters['q']}%");
            }));

        $records = (clone $query)
            ->select(
                'first_aid_records.*',
                'sites.name as site_name',
                'first_aider.name as first_aider_name'
            )
            ->orderByDesc('first_aid_records.treatment_date')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $records30d = DB::table('first_aid_records')
            ->whereNull('deleted_at')
            ->where('treatment_date', '>=', $thirtyDaysAgo)
            ->count();

        $ambulanceCalls30d = DB::table('first_aid_records')
            ->whereNull('deleted_at')
            ->where('treatment_date', '>=', $thirtyDaysAgo)
            ->where('ambulance_called', true)
            ->count();

        $incidentLinked = DB::table('first_aid_records')
            ->whereNull('deleted_at')
            ->where('treatment_date', '>=', $thirtyDaysAgo)
            ->where('incident_reported', true)
            ->count();

        $sites = DB::table('sites')
            ->whereNull('deleted_at')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $users = DB::table('users')
            ->whereNull('deleted_at')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('health-safety/first-aid/index', [
            'records' => $records,
            'filters' => $filters,
            'stats' => [
                'records_30d' => $records30d,
                'ambulance_calls_30d' => $ambulanceCalls30d,
                'linked_to_incidents' => $incidentLinked,
            ],
            'sites' => $sites,
            'staff' => $users,
        ]);
    }

    /**
     * Store a new first aid record.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'treated_person_id' => 'nullable|exists:users,id',
            'treated_person_name' => 'required|string|max:255',
            'treated_person_type' => 'required|in:staff,client,visitor,contractor',
            'treatment_date' => 'required|date',
            'injury_illness_type' => 'required|in:cut,burn,sprain,fall,allergic_reaction,breathing_difficulty,chest_pain,seizure,fainting,other',
            'injury_illness_description' => 'required|string',
            'body_part' => 'nullable|string|max:255',
            'treatment_given' => 'required|string',
            'treatment_outcome' => 'required|in:returned_to_work,sent_home,sent_to_medical,sent_to_hospital,refused_treatment',
            'ambulance_called' => 'boolean',
            'first_aider_id' => 'required|exists:users,id',
            'first_aider_notes' => 'nullable|string',
            'incident_reported' => 'boolean',
            'related_incident_id' => 'nullable|exists:client_incidents,id',
        ]);

        $validated['created_by'] = $user->id;

        FirstAidRecord::create($validated);

        return redirect()->route('health-safety.first-aid.index')
            ->with('success', 'First aid record created.');
    }
}
