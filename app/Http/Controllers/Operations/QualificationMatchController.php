<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Shift;
use App\Models\StaffQualificationRequirement;
use Illuminate\Http\Request;

class QualificationMatchController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $requirements = StaffQualificationRequirement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('qualification_name', 'like', '%'.$search.'%')
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                            ->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%'));
                });
            })
            ->orderBy('client_id')
            ->paginate(20)
            ->through(fn (StaffQualificationRequirement $requirement) => [
                'id' => $requirement->id,
                'qualification_name' => $requirement->qualification_name,
                'is_mandatory' => (bool) $requirement->is_mandatory,
                'match_status' => 'unmet',
                'matched_workers' => 0,
                'total_workers' => 0,
                'client' => $requirement->client ? [
                    'id' => $requirement->client->id,
                    'first_name' => $requirement->client->first_name,
                    'last_name' => $requirement->client->last_name,
                ] : null,
            ])
            ->withQueryString();

        return inertia('operations/qualifications/Index', [
            'requirements' => $requirements,
            'filters' => [
                'q' => $filters['q'] ?? null,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'qualification_name' => ['required', 'string', 'max:255'],
            'qualification_type' => ['nullable', 'string', 'max:100'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        StaffQualificationRequirement::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'qualification_name' => $data['qualification_name'],
            'qualification_type' => $data['qualification_type'] ?? 'certification',
            'is_mandatory' => $data['is_mandatory'] ?? true,
            'description' => $data['description'] ?? $data['notes'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Qualification requirement added.');
    }

    public function update(Request $request, $requirement)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $requirement = StaffQualificationRequirement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($requirement);

        $data = $request->validate([
            'qualification_name' => ['sometimes', 'required', 'string', 'max:255'],
            'qualification_type' => ['nullable', 'string', 'max:100'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $requirement->update(array_filter([
            'qualification_name' => $data['qualification_name'] ?? null,
            'qualification_type' => $data['qualification_type'] ?? null,
            'is_mandatory' => $data['is_mandatory'] ?? null,
            'description' => $data['description'] ?? $data['notes'] ?? null,
        ], fn ($value) => $value !== null));

        return redirect()->back()->with('success', 'Qualification requirement updated.');
    }

    public function destroy(Request $request, $requirement)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $requirement = StaffQualificationRequirement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($requirement);

        $requirement->delete();

        return redirect()->back()->with('success', 'Qualification requirement removed.');
    }

    public function checkShift(Request $request, $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $shift = Shift::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['staff.staffTrainingRecords', 'staff.staffCredentials', 'client'])
            ->findOrFail($shift);

        $requirements = StaffQualificationRequirement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('client_id', $shift->client_id)
            ->get();

        $results = [];
        foreach ($requirements as $req) {
            $met = false;
            if ($shift->staff) {
                $met = $shift->staff->staffCredentials()
                    ->where('type', $req->qualification_name)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->exists();
            }

            $results[] = [
                'requirement' => $req,
                'met' => $met,
                'is_mandatory' => $req->is_mandatory,
            ];
        }

        $allMandatoryMet = collect($results)
            ->where('is_mandatory', true)
            ->every('met', true);

        return inertia('operations/qualifications/CheckShift', [
            'shift' => $shift,
            'results' => $results,
            'allMandatoryMet' => $allMandatoryMet,
        ]);
    }

    private function canAccessQualifications($auth): bool
    {
        return $auth->canDo('qualifications.viewAny')
            || $auth->canDo('qualifications.create')
            || $auth->canDo('qualifications.edit')
            || $auth->canDo('qualifications.delete')
            || $auth->canDo('rostering.viewAny');
    }
}
