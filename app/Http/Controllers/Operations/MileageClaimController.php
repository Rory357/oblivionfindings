<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\MileageClaim;
use Illuminate\Http\Request;

class MileageClaimController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('mileage_claims.viewAny'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected'],
        ]);

        $claims = MileageClaim::query()
            ->where('organization_id', $auth->organization_id)
            ->with(['staff:id,name'])
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/mileage/Index', [
            'claims' => $claims,
            'filters' => $request->only(['status']),
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('mileage_claims.create'), 403);

        return inertia('operations/mileage/Create');
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('mileage_claims.create'), 403);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_location' => ['required', 'string', 'max:255'],
            'to_location' => ['required', 'string', 'max:255'],
            'distance' => ['required', 'numeric', 'min:0'],
            'rate' => ['required', 'numeric', 'min:0'],
            'purpose' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        MileageClaim::create([
            'organization_id' => $auth->organization_id,
            'staff_id' => $auth->id,
            'date' => $data['date'],
            'from_location' => $data['from_location'],
            'to_location' => $data['to_location'],
            'distance' => $data['distance'],
            'rate' => $data['rate'],
            'amount' => $data['distance'] * $data['rate'],
            'purpose' => $data['purpose'],
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
        ]);

        return redirect()->back()->with('success', 'Mileage claim created.');
    }

    public function submit(Request $request, $claim)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('mileage_claims.create'), 403);

        $claim = MileageClaim::query()
            ->where('organization_id', $auth->organization_id)
            ->where('status', 'draft')
            ->findOrFail($claim);

        $claim->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Mileage claim submitted.');
    }

    public function approve(Request $request, $claim)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('mileage_claims.approve'), 403);

        $claim = MileageClaim::query()
            ->where('organization_id', $auth->organization_id)
            ->where('status', 'submitted')
            ->findOrFail($claim);

        $claim->update([
            'status' => 'approved',
            'approved_by' => $auth->id,
            'approved_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Mileage claim approved.');
    }
}
