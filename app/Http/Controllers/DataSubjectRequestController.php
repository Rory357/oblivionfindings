<?php

namespace App\Http\Controllers;

use App\Models\DataSubjectRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataSubjectRequestController extends Controller
{
    /**
     * Display a listing of data subject requests.
     */
    public function index(Request $request): Response
    {
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
                'pending_verification' => DataSubjectRequest::where('status', 'pending_verification')->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new request.
     */
    public function create(): Response
    {
        return Inertia::render('privacy/requests/create', [
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created request.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'request_type' => 'required|in:access,rectification,erasure,restriction,portability,objection,automated_decision',
            'subject_name' => 'required|string|max:255',
            'subject_email' => 'required|email|max:255',
            'request_details' => 'nullable|string',
            'specific_data_requested' => 'nullable|array',
            'assigned_to_user_id' => 'nullable|exists:users,id',
        ]);

        $validated['created_by'] = auth()->id();
        $validated['status'] = 'pending_verification';

        $dsr = DataSubjectRequest::create($validated);

        return redirect()
            ->route('privacy.requests.show', $dsr)
            ->with('success', 'Data subject request created with reference: ' . $dsr->reference_number);
    }

    /**
     * Display the specified request.
     */
    public function show(DataSubjectRequest $request): Response
    {
        $request->load([
            'client',
            'user',
            'verifiedBy',
            'assignedTo',
            'completedBy',
        ]);

        return Inertia::render('privacy/requests/show', [
            'request' => $request,
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified request.
     */
    public function update(Request $request, DataSubjectRequest $dsRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:pending_verification,in_progress,completed,rejected,withdrawn',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'completion_notes' => 'nullable|string',
        ]);

        $validated['updated_by'] = auth()->id();

        if (isset($validated['assigned_to_user_id']) && !$dsRequest->assigned_at) {
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
        $request->validate([
            'verification_method' => 'required|string|max:255',
        ]);

        $dsRequest->update([
            'identity_verified' => true,
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
    public function export(DataSubjectRequest $request)
    {
        // TODO: Implement data export
        return back()->with('info', 'Data export functionality coming soon.');
    }
}
