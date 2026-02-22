<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteDamage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SiteDamageController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('viewAny', SiteDamage::class);

        $damages = $site->damages()
            ->with(['reportedBy:id,name', 'assignedTo:id,name'])
            ->orderByDesc('damage_date')
            ->get();

        return Inertia::render('sites/damages/index', [
            'site' => $site,
            'damages' => $damages,
            'canCreate' => $request->user()->canDo('sites.damages.create'),
            'canManage' => $request->user()->canDo('sites.damages.manage'),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('create', SiteDamage::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'location_in_site' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'string', 'in:minor,moderate,major,critical'],
            'damage_date' => ['required', 'date'],
            'discovered_date' => ['required', 'date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'insurance_claim_ref' => ['nullable', 'string', 'max:255'],
            'insurance_status' => ['nullable', 'string', 'in:not_applicable,pending,submitted,approved,declined'],
            'photos' => ['nullable', 'array'],
        ]);

        $data['tenant_id'] = $site->tenant_id;
        $data['site_id'] = $site->id;
        $data['reported_by'] = $request->user()->id;
        $data['status'] = 'reported';
        $data['insurance_status'] = $data['insurance_status'] ?? 'not_applicable';

        SiteDamage::create($data);

        return redirect()->back()->with('success', 'Damage report created.');
    }

    public function update(Request $request, Site $site, SiteDamage $damage)
    {
        abort_unless($damage->site_id === $site->id, 404);
        $this->authorize('update', $damage);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'location_in_site' => ['nullable', 'string', 'max:255'],
            'severity' => ['sometimes', 'string', 'in:minor,moderate,major,critical'],
            'status' => ['sometimes', 'string', 'in:reported,assessed,repair_scheduled,repair_in_progress,repaired,closed'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'insurance_claim_ref' => ['nullable', 'string', 'max:255'],
            'insurance_status' => ['nullable', 'string', 'in:not_applicable,pending,submitted,approved,declined'],
            'repair_notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('insurance_status', $data) && $data['insurance_status'] === null) {
            $data['insurance_status'] = 'not_applicable';
        }

        // If marking as repaired, set repaired_at and repaired_by
        if (($data['status'] ?? null) === 'repaired' && !$damage->repaired_at) {
            $data['repaired_at'] = now();
            $data['repaired_by'] = $request->user()->id;
        }

        $damage->update($data);

        return redirect()->back()->with('success', 'Damage report updated.');
    }

    public function destroy(Request $request, Site $site, SiteDamage $damage)
    {
        abort_unless($damage->site_id === $site->id, 404);
        $this->authorize('delete', $damage);

        $damage->delete();

        return redirect()->back()->with('success', 'Damage report removed.');
    }
}
