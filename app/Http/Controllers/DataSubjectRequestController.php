<?php

namespace App\Http\Controllers;

use App\Models\DataSubjectRequest;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DataSubjectRequestController extends Controller
{
    /**
     * Display a listing of privacy requests.
     */
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, 'privacy.viewRequests');

        $query = DataSubjectRequest::query()
            ->with(['assignedTo', 'client', 'user']);

        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('reference_number', 'like', "%{$request->q}%")
                    ->orWhere('subject_name', 'like', "%{$request->q}%")
                    ->orWhere('subject_email', 'like', "%{$request->q}%");
            });
        }

        if ($request->filled('request_type')) {
            $query->where('request_type', $request->request_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('overdue') && $request->overdue === '1') {
            $query->overdue();
        }

        $query->orderByDesc('created_at');

        $requests = $query->paginate(20)->withQueryString();

        return Inertia::render('privacy/requests', [
            'requests' => $requests,
            'filters' => $request->only(['q', 'request_type', 'status', 'overdue']),
            'stats' => [
                'open' => DataSubjectRequest::open()->count(),
                'overdue' => DataSubjectRequest::overdue()->count(),
                'completed_30_days' => DataSubjectRequest::where('status', 'completed')
                    ->where('completed_at', '>=', now()->subDays(30))
                    ->count(),
                'pending_verification' => DataSubjectRequest::whereIn('status', ['received', 'identity_verification'])->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new request.
     */
    public function create(Request $request): Response
    {
        $this->authorizePermission($request, 'privacy.processRequests');

        return Inertia::render('privacy/requests/create', [
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created request.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'privacy.processRequests');

        $validated = $request->validate([
            'request_type' => 'required|in:access,rectification,erasure,restriction,portability,objection,automated_decision',
            'subject_name' => 'required|string|max:255',
            'subject_email' => 'required|email|max:255',
            'request_details' => 'nullable|string',
            'specific_data_requested' => 'nullable|array',
            'assigned_to_user_id' => 'nullable|exists:users,id',
        ]);

        $validated['created_by'] = auth()->id();
        $validated['status'] = 'identity_verification';

        $dsr = DataSubjectRequest::create($validated);

        return redirect()
            ->route('privacy.requests.show', $dsr)
            ->with('success', 'Data subject request created with reference: '.$dsr->reference_number);
    }

    /**
     * Display the specified request.
     */
    public function show(Request $request, DataSubjectRequest $dsRequest): Response
    {
        $this->authorizePermission($request, 'privacy.viewRequests');

        $dsRequest->load([
            'client',
            'user',
            'verifiedBy',
            'assignedTo',
            'completedBy',
        ]);

        return Inertia::render('privacy/requests/show', [
            'request' => $dsRequest,
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified request.
     */
    public function update(Request $request, DataSubjectRequest $dsRequest): RedirectResponse
    {
        $this->authorizePermission($request, 'privacy.processRequests');

        $validated = $request->validate([
            'status' => 'sometimes|in:received,under_review,identity_verification,in_progress,completed,rejected,withdrawn',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'completion_notes' => 'nullable|string',
        ]);

        $validated['updated_by'] = auth()->id();

        if (isset($validated['assigned_to_user_id']) && ! $dsRequest->assigned_at) {
            $validated['assigned_at'] = now();
        }

        $dsRequest->update($validated);

        return back()->with('success', 'Request updated successfully.');
    }

    /**
     * Verify the identity of the requester.
     */
    public function verifyIdentity(Request $request, DataSubjectRequest $dsRequest): RedirectResponse
    {
        $this->authorizePermission($request, 'privacy.processRequests');

        $request->validate([
            'verification_method' => 'required|string|max:255',
        ]);

        $dsRequest->update([
            'identity_verified' => 'verified',
            'identity_verified_at' => now(),
            'verified_by_user_id' => auth()->id(),
            'verification_method' => $request->verification_method,
            'status' => 'in_progress',
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Identity verified successfully.');
    }

    /**
     * Extend the deadline.
     */
    public function extend(Request $request, DataSubjectRequest $dsRequest): RedirectResponse
    {
        $this->authorizePermission($request, 'privacy.processRequests');

        $request->validate([
            'extension_reason' => 'required|string',
            'extended_due_date' => 'required|date|after:today',
        ]);

        $dsRequest->update([
            'extension_requested' => true,
            'extension_reason' => $request->extension_reason,
            'extended_due_date' => $request->extended_due_date,
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Deadline extended successfully.');
    }

    /**
     * Complete the request.
     */
    public function complete(Request $request, DataSubjectRequest $dsRequest): RedirectResponse
    {
        $this->authorizePermission($request, 'privacy.processRequests');

        $request->validate([
            'completion_notes' => 'nullable|string',
        ]);

        $dsRequest->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => auth()->id(),
            'completion_notes' => $request->completion_notes,
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Request marked as completed.');
    }

    /**
     * Refuse the request.
     */
    public function refuse(Request $request, DataSubjectRequest $dsRequest): RedirectResponse
    {
        $this->authorizePermission($request, 'privacy.processRequests');

        $request->validate([
            'rejection_reason' => 'required|string',
            'rejection_legal_basis' => 'required|string',
        ]);

        $dsRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'rejection_legal_basis' => $request->rejection_legal_basis,
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Request refused.');
    }

    /**
     * Export the request data.
     */
    public function export(Request $request, DataSubjectRequest $dsRequest)
    {
        $this->authorizePermission($request, 'privacy.viewRequests');

        $dsRequest->load(['client', 'user']);

        $data = [
            'export_metadata' => [
                'reference_number' => $dsRequest->reference_number,
                'request_type' => $dsRequest->request_type,
                'generated_at' => now()->toIso8601String(),
                'generated_by' => auth()->user()->name ?? 'System',
            ],
        ];

        // Collect data based on whether this is a client or user request
        if ($dsRequest->client_id && $dsRequest->client) {
            $client = $dsRequest->client;

            $data['personal_information'] = [
                'name' => $client->first_name.' '.$client->last_name,
                'preferred_name' => $client->preferred_name,
                'date_of_birth' => $client->date_of_birth?->format('Y-m-d'),
                'gender' => $client->gender,
                'nhi_number' => $client->nhi_number,
                'email' => $client->email,
                'phone' => $client->phone,
                'address' => implode(', ', array_filter([
                    $client->address_line_1,
                    $client->address_line_2,
                    $client->suburb,
                    $client->city,
                    $client->postcode,
                ])),
                'ethnicity' => $client->ethnicity,
                'languages' => $client->languages,
                'preferred_pronouns' => $client->preferred_pronouns,
                'religion' => $client->religion,
                'status' => $client->status,
                'service_start_date' => $client->service_start_date?->format('Y-m-d'),
            ];

            // Care/support plans
            $client->loadMissing(['supportPlan', 'notes', 'assessments', 'incidents', 'medications']);

            if ($client->supportPlan) {
                $data['support_plan'] = [
                    'id' => $client->supportPlan->id,
                    'created_at' => $client->supportPlan->created_at?->format('Y-m-d'),
                    'updated_at' => $client->supportPlan->updated_at?->format('Y-m-d'),
                ];
            }

            // Notes (titles/dates only)
            $data['notes'] = $client->notes->map(fn ($note) => [
                'id' => $note->id,
                'title' => $note->title ?? $note->subject ?? null,
                'type' => $note->type ?? $note->note_type ?? null,
                'created_at' => $note->created_at?->format('Y-m-d'),
            ])->toArray();

            // Assessments (titles/dates only)
            $data['assessments'] = $client->assessments->map(fn ($assessment) => [
                'id' => $assessment->id,
                'title' => $assessment->title ?? $assessment->assessment_type ?? null,
                'created_at' => $assessment->created_at?->format('Y-m-d'),
            ])->toArray();

            // Incidents (titles/dates only)
            $data['incidents'] = $client->incidents->map(fn ($incident) => [
                'id' => $incident->id,
                'title' => $incident->title ?? $incident->incident_type ?? null,
                'date' => $incident->incident_date?->format('Y-m-d') ?? $incident->created_at?->format('Y-m-d'),
            ])->toArray();

            // Medications (titles/dates only)
            $data['medications'] = $client->medications->map(fn ($med) => [
                'id' => $med->id,
                'name' => $med->medication_name ?? $med->name ?? null,
                'created_at' => $med->created_at?->format('Y-m-d'),
            ])->toArray();

            // Consent records
            $consents = \App\Models\ClientConsent::where('client_id', $client->id)
                ->with('consentType')
                ->get();

            $data['consent_records'] = $consents->map(fn ($consent) => [
                'id' => $consent->id,
                'type' => $consent->consentType?->name ?? null,
                'status' => $consent->status,
                'given_at' => $consent->given_at?->format('Y-m-d'),
                'withdrawn_at' => $consent->withdrawn_at?->format('Y-m-d'),
                'expires_at' => $consent->expires_at?->format('Y-m-d'),
            ])->toArray();

            $data['respite_records'] = $this->respiteExportRecords($client->id);

        } elseif ($dsRequest->user_id && $dsRequest->user) {
            $user = $dsRequest->user;

            $data['personal_information'] = [
                'name' => $user->name,
                'email' => $user->email,
            ];
        } else {
            $data['personal_information'] = [
                'subject_name' => $dsRequest->subject_name,
                'subject_email' => $dsRequest->subject_email,
                'note' => 'No linked client or user record found. Only request metadata is available.',
            ];
        }

        $data['request_details'] = [
            'request_type' => $dsRequest->request_type,
            'request_details' => $dsRequest->request_details,
            'specific_data_requested' => $dsRequest->specific_data_requested,
            'status' => $dsRequest->status,
            'received_at' => $dsRequest->received_at?->format('Y-m-d'),
            'due_date' => $dsRequest->due_date?->format('Y-m-d'),
        ];

        $filename = 'dsr-'.$dsRequest->reference_number.'-'.now()->format('Y-m-d').'.json';
        $path = 'private/dsr-exports/'.$filename;
        Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT));

        $dsRequest->update([
            'export_path' => $path,
            'export_generated_at' => now(),
        ]);

        return back()->with('success', 'Data export generated successfully.');
    }

    private function respiteExportRecords(int $clientId): array
    {
        $referrals = RespiteReferral::query()
            ->where('client_id', $clientId)
            ->orderByDesc('received_at')
            ->get();
        $requests = RespiteBookingRequest::query()
            ->where('client_id', $clientId)
            ->orderByDesc('requested_start')
            ->get();
        $bookings = RespiteBooking::query()
            ->where('client_id', $clientId)
            ->orderByDesc('start_at')
            ->get();
        $stays = RespiteStay::query()
            ->where('client_id', $clientId)
            ->with(['handovers', 'communications'])
            ->orderByDesc('actual_start')
            ->get();

        return [
            'referrals' => $referrals->map(fn (RespiteReferral $referral) => [
                'id' => $referral->id,
                'status' => $referral->status,
                'urgency' => $referral->urgency,
                'received_at' => $referral->received_at?->format('Y-m-d'),
                'referrer_type' => $referral->referrer_type,
                'referrer_name' => $referral->referrer_name,
                'funding_source' => $referral->funding_source,
                'funding_reference' => $referral->funding_reference,
            ])->toArray(),
            'booking_requests' => $requests->map(fn (RespiteBookingRequest $request) => [
                'id' => $request->id,
                'referral_id' => $request->referral_id,
                'status' => $request->status,
                'requested_start' => $request->requested_start?->format('Y-m-d'),
                'requested_end' => $request->requested_end?->format('Y-m-d'),
                'funding_source' => $request->funding_source,
                'funding_reference' => $request->funding_reference,
                'funding_status' => $request->funding_status,
            ])->toArray(),
            'bookings' => $bookings->map(fn (RespiteBooking $booking) => [
                'id' => $booking->id,
                'booking_request_id' => $booking->booking_request_id,
                'status' => $booking->status,
                'start_at' => $booking->start_at?->format('Y-m-d'),
                'end_at' => $booking->end_at?->format('Y-m-d'),
                'funding_source' => $booking->funding_source,
                'funding_reference' => $booking->funding_reference,
                'funding_status' => $booking->funding_status,
            ])->toArray(),
            'stays' => $stays->map(fn (RespiteStay $stay) => [
                'id' => $stay->id,
                'booking_id' => $stay->booking_id,
                'status' => $stay->status,
                'actual_start' => $stay->actual_start?->format('Y-m-d'),
                'actual_end' => $stay->actual_end?->format('Y-m-d'),
                'discharge_summary' => $stay->discharge_summary,
            ])->toArray(),
            'handovers' => $stays->flatMap(fn (RespiteStay $stay) => $stay->handovers->map(fn ($handover) => [
                'id' => $handover->id,
                'stay_id' => $stay->id,
                'type' => $handover->handover_type,
                'created_at' => $handover->created_at?->format('Y-m-d'),
                'sensitive' => (bool) $handover->sensitive_flag,
            ]))->values()->toArray(),
            'communications' => $stays->flatMap(fn (RespiteStay $stay) => $stay->communications->map(fn ($communication) => [
                'id' => $communication->id,
                'stay_id' => $stay->id,
                'channel' => $communication->channel,
                'summary' => $communication->summary,
                'occurred_at' => $communication->occurred_at?->format('Y-m-d'),
            ]))->values()->toArray(),
        ];
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->canDo($permission), 403);
    }
}
