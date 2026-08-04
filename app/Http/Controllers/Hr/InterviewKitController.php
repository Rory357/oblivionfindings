<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrInterviewKit;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InterviewKitController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $kits = HrInterviewKit::query()
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (HrInterviewKit $kit) => [
                'id' => $kit->id,
                'name' => $kit->name,
                'role' => $kit->role,
                'criteria' => $kit->criteria ?? [],
                'guidance' => $kit->guidance,
                'is_active' => (bool) $kit->is_active,
                'created_at' => optional($kit->created_at)->toDateString(),
            ]);

        return Inertia::render('hr/recruitment/kits', [
            'kits' => $kits,
            'roles' => ['support_worker', 'team_lead', 'coordinator', 'provider_manager', 'admin'],
            'can' => [
                'manage' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_interview_kits', 'name')],
            'role' => ['nullable', 'string', 'max:100'],
            'criteria' => ['nullable', 'array'],
            'criteria.*.label' => ['required_with:criteria', 'string', 'max:255'],
            'criteria.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'guidance' => ['nullable', 'string', 'max:10000'],
        ]);

        HrInterviewKit::create([
            'name' => $validated['name'],
            'role' => $validated['role'] ?? null,
            'criteria' => $validated['criteria'] ?? [],
            'guidance' => $validated['guidance'] ?? null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Interview kit created.');
    }

    public function update(Request $request, HrInterviewKit $kit)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('hr_interview_kits', 'name')->ignore($kit->id)],
            'role' => ['nullable', 'string', 'max:100'],
            'criteria' => ['nullable', 'array'],
            'criteria.*.label' => ['required_with:criteria', 'string', 'max:255'],
            'criteria.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'guidance' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['boolean'],
        ]);

        $kit->update([
            ...$validated,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Interview kit updated.');
    }

    public function toggleActive(Request $request, HrInterviewKit $kit)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);
        $kit->update([
            'is_active' => ! $kit->is_active,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Interview kit status updated.');
    }
}
