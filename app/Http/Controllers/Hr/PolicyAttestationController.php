<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyAttestationController extends Controller
{
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

        return Inertia::render('hr/policies/attestations', [
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

        // Ensure the policy requires attestation
        if (! $policy->requires_attestation) {
            return redirect()->back()->withErrors(['policy' => 'This policy does not require attestation.']);
        }

        $currentVersion = $policy->currentVersion;

        if (! $currentVersion) {
            return redirect()->back()->withErrors(['policy' => 'This policy has no published version to attest.']);
        }

        // Check for existing attestation on this version
        $existing = HrPolicyAttestation::where('user_id', $user->id)
            ->where('policy_id', $policy->id)
            ->where('policy_version_id', $currentVersion->id)
            ->first();

        if ($existing) {
            return redirect()->back()->withErrors(['policy' => 'You have already attested to this version of the policy.']);
        }

        $data = $request->validate([
            'attestation_method' => ['sometimes', 'string', 'in:checkbox,signature,digital'],
        ]);

        HrPolicyAttestation::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'policy_id' => $policy->id,
            'policy_version_id' => $currentVersion->id,
            'attested_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'attestation_method' => $data['attestation_method'] ?? 'checkbox',
        ]);

        return redirect()->back()->with('success', 'Policy attestation recorded.');
    }
}
