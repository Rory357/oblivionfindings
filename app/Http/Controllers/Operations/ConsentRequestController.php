<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Services\ConsentRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Staff-side consent request workflow. Lives on the client profile.
 *
 *   GET  /operations/clients/{client}/consent-requests              index
 *   GET  /operations/clients/{client}/consent-requests/create       create form
 *   POST /operations/clients/{client}/consent-requests              store
 *   GET  /operations/clients/{client}/consent-requests/{request}    show
 *   POST /operations/clients/{client}/consent-requests/{request}/cancel  cancel
 */
class ConsentRequestController extends Controller
{
    public function __construct(
        private readonly ConsentRequestService $service,
    ) {}

    public function index(Request $request, Client $client)
    {
        Gate::authorize('viewAny', ConsentRequest::class);

        $requests = ConsentRequest::forClient($client->id)
            ->with(['consentType:id,name,category', 'requestedBy:id,name', 'recipient:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => $this->toSummary($r));

        return inertia('operations/clients/consent-requests/Index', [
            'client' => ['id' => $client->id, 'full_name' => $client->full_name ?? $client->name ?? ('Client #'.$client->id)],
            'requests' => $requests,
            'stats' => [
                'total' => $requests->count(),
                'pending' => $requests->where('status', ConsentRequest::STATUS_PENDING)->count(),
                'approved' => $requests->where('status', ConsentRequest::STATUS_APPROVED)->count(),
                'declined' => $requests->where('status', ConsentRequest::STATUS_DECLINED)->count(),
            ],
        ]);
    }

    public function create(Request $request, Client $client)
    {
        Gate::authorize('create', ConsentRequest::class);

        $consentTypes = ConsentType::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'description', 'purpose', 'validity_period_days']);

        // Family-portal users linked to this client, with their relationship.
        $portalUsers = $client->portalUsers()
            ->select('users.id', 'users.name', 'users.email')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'relationship' => $u->pivot->relation ?? null,
            ]);

        return inertia('operations/clients/consent-requests/Create', [
            'client' => ['id' => $client->id, 'full_name' => $client->full_name ?? $client->name ?? ('Client #'.$client->id)],
            'consent_types' => $consentTypes,
            'portal_users' => $portalUsers,
            'relationship_options' => [
                ConsentRequest::RELATION_WELFARE_GUARDIAN => 'Welfare Guardian (PPPR Act)',
                ConsentRequest::RELATION_EPOA_PERSONAL_CARE => 'EPOA — Personal Care & Welfare',
                ConsentRequest::RELATION_PARENT_GUARDIAN => 'Parent / Guardian (under 16)',
                ConsentRequest::RELATION_COURT_APPOINTED => 'Court-appointed',
                ConsentRequest::RELATION_NEXT_OF_KIN => 'Next of Kin (informational only)',
                ConsentRequest::RELATION_SELF => 'Client themselves',
            ],
        ]);
    }

    public function store(Request $request, Client $client)
    {
        Gate::authorize('create', ConsentRequest::class);

        $data = $request->validate([
            'consent_type_id' => ['required', 'exists:consent_types,id'],
            'recipient_user_id' => ['required', 'exists:users,id'],
            'recipient_relationship' => ['required', 'string', 'max:80'],
            'purpose' => ['required', 'string', 'min:10', 'max:2000'],
            'least_restrictive_justification' => ['nullable', 'string', 'max:2000'],
            'data_scope' => ['nullable', 'string', 'max:1000'],
            'retention_period_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'withdrawal_method_text' => ['nullable', 'string', 'max:1000'],
            'staff_notes' => ['nullable', 'string', 'max:2000'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'triggering_subject_type' => ['nullable', 'string', 'max:120'],
            'triggering_subject_id' => ['nullable', 'integer', 'min:1'],
        ]);

        // Verify recipient is actually linked to this client via the portal pivot.
        $linked = $client->portalUsers()
            ->where('users.id', $data['recipient_user_id'])
            ->exists();

        if (! $linked) {
            return back()->withErrors([
                'recipient_user_id' => 'The recipient must be a family-portal user linked to this client.',
            ]);
        }

        $expiresInDays = $data['expires_in_days'] ?? null;
        unset($data['expires_in_days']);
        $data['client_id'] = $client->id;

        $this->service->create($data, $request->user(), $expiresInDays);

        return redirect()
            ->route('operations.clients.consent-requests.index', $client->id)
            ->with('success', 'Consent request sent to the family portal.');
    }

    public function show(Request $request, Client $client, ConsentRequest $consentRequest)
    {
        Gate::authorize('view', $consentRequest);
        abort_unless($consentRequest->client_id === $client->id, 404);

        $consentRequest->load([
            'consentType',
            'requestedBy:id,name,email',
            'recipient:id,name,email',
            'resultingConsent',
            'cancelledBy:id,name',
        ]);

        return inertia('operations/clients/consent-requests/Show', [
            'client' => ['id' => $client->id, 'full_name' => $client->full_name ?? $client->name ?? ('Client #'.$client->id)],
            'request' => $this->toDetail($consentRequest),
        ]);
    }

    public function cancel(Request $request, Client $client, ConsentRequest $consentRequest)
    {
        Gate::authorize('cancel', $consentRequest);
        abort_unless($consentRequest->client_id === $client->id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->service->cancel($consentRequest, $request->user(), $data['reason']);

        return back()->with('success', 'Consent request cancelled.');
    }

    /** @return array<string, mixed> */
    private function toSummary(ConsentRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'consent_type' => $r->consentType?->only(['id', 'name', 'category']),
            'requested_by' => $r->requestedBy?->only(['id', 'name']),
            'recipient' => $r->recipient?->only(['id', 'name']),
            'recipient_relationship' => $r->recipient_relationship,
            'authority_to_consent' => $r->authorityToConsent(),
            'sent_at' => $r->sent_at?->toIso8601String(),
            'expires_at' => $r->expires_at?->toIso8601String(),
            'responded_at' => $r->responded_at?->toIso8601String(),
            'is_expired' => $r->isExpired(),
            'resulting_consent_id' => $r->resulting_consent_id,
        ];
    }

    /** @return array<string, mixed> */
    private function toDetail(ConsentRequest $r): array
    {
        return array_merge($this->toSummary($r), [
            'purpose' => $r->purpose,
            'least_restrictive_justification' => $r->least_restrictive_justification,
            'data_scope' => $r->data_scope,
            'retention_period_days' => $r->retention_period_days,
            'withdrawal_method_text' => $r->withdrawal_method_text,
            'staff_notes' => $r->staff_notes,
            'response_notes' => $r->response_notes,
            'viewed_at' => $r->viewed_at?->toIso8601String(),
            'cancelled_by' => $r->cancelledBy?->only(['id', 'name']),
            'cancellation_reason' => $r->cancellation_reason,
            'audit_trail' => $r->audit_trail ?? [],
            'resulting_consent' => $r->resultingConsent?->only(['id', 'status', 'given_at', 'expires_at']),
            'can_cancel' => $r->isPending(),
        ]);
    }
}
