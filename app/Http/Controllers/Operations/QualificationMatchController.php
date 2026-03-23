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
        abort_unless($auth && $auth->canDo('qualifications.viewAny'), 403);

        $requirements = StaffQualificationRequirement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->orderBy('client_id')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/qualifications/Index', [
            'requirements' => $requirements,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('qualifications.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'qualification_name' => ['required', 'string', 'max:255'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        StaffQualificationRequirement::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'qualification_name' => $data['qualification_name'],
            'is_mandatory' => $data['is_mandatory'] ?? true,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Qualification requirement added.');
    }

    public function update(Request $request, $requirement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('qualifications.edit'), 403);

        $requirement = StaffQualificationRequirement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($requirement);

        $data = $request->validate([
            'qualification_name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $requirement->update($data);

        return redirect()->back()->with('success', 'Qualification requirement updated.');
    }

    public function destroy(Request $request, $requirement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('qualifications.delete'), 403);

        $requirement = StaffQualificationRequirement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($requirement);

        $requirement->delete();

        return redirect()->back()->with('success', 'Qualification requirement removed.');
    }

    public function checkShift(Request $request, $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('qualifications.viewAny'), 403);

        $shift = Shift::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['staff.trainingRecords', 'staff.credentials', 'client'])
            ->findOrFail($shift);

        $requirements = StaffQualificationRequirement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('client_id', $shift->client_id)
            ->get();

        $results = [];
        foreach ($requirements as $req) {
            $met = false;
            if ($shift->staff) {
                $met = $shift->staff->credentials()
                    ->where('name', $req->qualification_name)
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
}
