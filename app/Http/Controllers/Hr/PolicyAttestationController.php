<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Services\PolicyAttestationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyAttestationController extends Controller
{
    public function __construct(private readonly PolicyAttestationService $attestations) {}

    /**
     * Show attestation status overview.
     *
     * Lists policies requiring attestation and the compliance status
     * of staff members.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.attest'), 403);

        $attestations = HrPolicyAttestation::with(['user:id,name', 'policy:id,title', 'policyVersion'])
            ->when(! $user->canDo('hr.policies.manage'), fn ($query) => $query->where('user_id', $user->id))
            ->when($request->query('policy_id'), fn ($q, $id) => $q->where('policy_id', $id))
            ->when($request->query('q'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('policy', fn ($p) => $p->where('title', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('attested_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/documents/policies/attestations', [
            'attestations' => $attestations,
            'filters' => [
                'policy_id' => $request->query('policy_id'),
                'q' => $request->query('q'),
            ],
        ]);
    }

    /**
     * Record a policy attestation.
     *
     * Captures IP address and user agent for audit purposes.
     */
    public function store(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.policies.attest'), 403);

        $data = $request->validate([
            'attestation_method' => ['sometimes', 'string', 'in:checkbox,signature,digital'],
        ]);

        $this->attestations->attest(
            $user,
            $policy,
            $request->ip(),
            $request->userAgent(),
            $data['attestation_method'] ?? 'checkbox',
        );

        return redirect()->back()->with('success', 'Policy attestation recorded.');
    }
}
