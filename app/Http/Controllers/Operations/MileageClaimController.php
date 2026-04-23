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
        abort_unless($auth && $this->canViewClaims($auth), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));

        $claims = MileageClaim::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['user:id,name'])
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested
                        ->where('purpose', 'like', '%'.$search.'%')
                        ->orWhere('origin', 'like', '%'.$search.'%')
                        ->orWhere('destination', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (MileageClaim $claim) => [
                'id' => $claim->id,
                'reference' => $claim->reference
                    ?? sprintf('MC-%s-%d', optional($claim->claim_date)->format('Ymd') ?? $claim->id, $claim->id),
                'status' => $claim->status,
                'origin' => $claim->origin,
                'destination' => $claim->destination,
                'distance_km' => (float) ($claim->distance_km ?? 0),
                'amount' => (float) ($claim->amount ?? 0),
                'claimed_at' => optional($claim->claim_date)->toDateString(),
                'worker' => $claim->user ? [
                    'id' => $claim->user->id,
                    'name' => $claim->user->name,
                ] : null,
            ])
            ->withQueryString();

        return inertia('operations/mileage/Index', [
            'claims' => $claims,
            'filters' => $request->only(['status', 'q']),
            'stats' => [
                'total' => MileageClaim::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->count(),
                'pending_approval' => MileageClaim::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->where('status', 'submitted')
                    ->count(),
                'total_km' => (float) MileageClaim::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->sum('distance_km'),
                'total_amount' => (float) MileageClaim::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->sum('amount'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canCreateClaims($auth), 403);

        return inertia('operations/mileage/Create');
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canCreateClaims($auth), 403);

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
            'user_id' => $auth->id,
            'claim_date' => $data['date'],
            'origin' => $data['from_location'],
            'destination' => $data['to_location'],
            'distance_km' => $data['distance'],
            'rate_per_km' => $data['rate'],
            'amount' => $data['distance'] * $data['rate'],
            'purpose' => $data['purpose'],
            'status' => 'draft',
        ]);

        return redirect()->back()->with('success', 'Mileage claim created.');
    }

    public function submit(Request $request, $claim)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canCreateClaims($auth), 403);

        $claim = MileageClaim::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
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
        abort_unless($auth && $this->canApproveClaims($auth), 403);

        $claim = MileageClaim::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('status', 'submitted')
            ->findOrFail($claim);

        $claim->update([
            'status' => 'approved',
            'approved_by' => $auth->id,
            'approved_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Mileage claim approved.');
    }

    private function canViewClaims($auth): bool
    {
        return $auth->canDo('mileage_claims.viewAny')
            || $auth->canDo('mileage.viewAny')
            || $auth->canDo('mileage.viewOwn');
    }

    private function canCreateClaims($auth): bool
    {
        return $auth->canDo('mileage_claims.create')
            || $auth->canDo('mileage.create');
    }

    private function canApproveClaims($auth): bool
    {
        return $auth->canDo('mileage_claims.approve')
            || $auth->canDo('mileage.approve');
    }
}
