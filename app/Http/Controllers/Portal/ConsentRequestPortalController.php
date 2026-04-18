<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ConsentRequest;
use App\Services\ConsentRequestService;
use Illuminate\Http\Request;

/**
 * Family-portal side of the consent-request workflow.
 *
 *   GET  /portal/clients/{client}/consent-requests/{request}     show detail
 *   POST /portal/clients/{client}/consent-requests/{request}/approve
 *   POST /portal/clients/{client}/consent-requests/{request}/decline
 *
 * The family-portal dashboard surfaces a card listing pending requests for
 * this client (added in FamilyDashboardController).
 */
class ConsentRequestPortalController extends Controller
{
    public function __construct(
        private readonly ConsentRequestService $service,
    ) {}

    public function show(Request $request, Client $client, ConsentRequest $consentRequest)
    {
        $this->authoriseRespondent($request, $client, $consentRequest);

        $this->service->markViewed($consentRequest);

        $consentRequest->load([
            'consentType',
            'requestedBy:id,name,email',
        ]);

        return inertia('portal/consent-requests/Show', [
            'client' => [
                'id' => $client->id,
                'full_name' => $client->full_name ?? $client->name ?? ('Client #' . $client->id),
            ],
            'request' => [
                'id' => $consentRequest->id,
                'status' => $consentRequest->status,
                'consent_type' => $consentRequest->consentType?->only([
                    'id', 'name', 'category', 'description', 'purpose', 'legal_basis',
                    'validity_period_days',
                ]),
                'requested_by' => $consentRequest->requestedBy?->only(['id', 'name', 'email']),
                'recipient_relationship' => $consentRequest->recipient_relationship,
                'authority_to_consent' => $consentRequest->authorityToConsent(),
                'purpose' => $consentRequest->purpose,
                'least_restrictive_justification' => $consentRequest->least_restrictive_justification,
                'data_scope' => $consentRequest->data_scope,
                'retention_period_days' => $consentRequest->retention_period_days,
                'withdrawal_method_text' => $consentRequest->withdrawal_method_text,
                'expires_at' => $consentRequest->expires_at?->toIso8601String(),
                'sent_at' => $consentRequest->sent_at?->toIso8601String(),
                'viewed_at' => $consentRequest->viewed_at?->toIso8601String(),
                'responded_at' => $consentRequest->responded_at?->toIso8601String(),
                'is_expired' => $consentRequest->isExpired(),
                'is_actionable' => $consentRequest->isActionable(),
            ],
        ]);
    }

    public function approve(Request $request, Client $client, ConsentRequest $consentRequest)
    {
        $this->authoriseRespondent($request, $client, $consentRequest);

        $data = $request->validate([
            'response_notes' => ['nullable', 'string', 'max:2000'],
            'acknowledge_authority' => ['required', 'accepted'],
        ]);

        $this->service->approve(
            $consentRequest,
            $request->user(),
            $request,
            $data['response_notes'] ?? null,
        );

        return redirect()
            ->route('portal.clients.dashboard', $client->id)
            ->with('success', 'Consent recorded. Thank you.');
    }

    public function decline(Request $request, Client $client, ConsentRequest $consentRequest)
    {
        $this->authoriseRespondent($request, $client, $consentRequest);

        $data = $request->validate([
            'response_notes' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $this->service->decline(
            $consentRequest,
            $request->user(),
            $request,
            $data['response_notes'],
        );

        return redirect()
            ->route('portal.clients.dashboard', $client->id)
            ->with('success', 'The care team has been notified of your response.');
    }

    private function authoriseRespondent(Request $request, Client $client, ConsentRequest $consentRequest): void
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($consentRequest->client_id === $client->id, 404);
        abort_unless($user->canAccessClientPortal($client), 403);
        abort_unless($consentRequest->recipient_user_id === $user->id, 403);
    }
}
