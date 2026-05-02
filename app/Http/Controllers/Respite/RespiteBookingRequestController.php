<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteBooking;
use App\Models\ServiceContext;
use App\Events\Respite\RespiteEvent;
use App\Services\Respite\RespiteShiftSync;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RespiteBookingRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $query = RespiteBookingRequest::query()
            ->with(['client', 'serviceContext', 'approvedBy'])
            ->where('status', '!=', 'approved');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('funding_reference', 'like', "%{$request->q}%")
                    ->orWhere('preference_notes', 'like', "%{$request->q}%");
            });
        }

        $query->orderByDesc('requested_start');
        $requests = $query->paginate(20)->withQueryString();

        return Inertia::render('respite/requests/index', [
            'requests' => $requests,
            'filters' => $request->only(['status', 'q']),
            'stats' => [
                'submitted' => RespiteBookingRequest::where('status', 'submitted')->count(),
                'approved' => RespiteBookingRequest::where('status', 'approved')->count(),
                'rejected' => RespiteBookingRequest::where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('respite/requests/create', [
            'clients' => Client::select('id', 'first_name', 'last_name')->orderBy('last_name')->orderBy('first_name')->get(),
            'serviceContexts' => ServiceContext::select('id', 'name')->orderBy('name')->get(),
            'defaultClientId' => $request->query('client_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'service_context_id' => 'nullable|exists:service_contexts,id',
            'requested_start' => 'required|date',
            'requested_end' => 'required|date|after:requested_start',
            'requirements' => 'nullable|array',
            'preference_notes' => 'nullable|string',
            'funding_reference' => 'nullable|string|max:255',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $this->authorize('view', $client);

        $validated['status'] = 'submitted';
        $validated['created_by'] = auth()->id();

        $requestModel = RespiteBookingRequest::create($validated);

        event(new RespiteEvent('respite.booking_request.submitted', [
            'id' => $requestModel->id,
            'client_id' => $requestModel->client_id,
            'status' => $requestModel->status,
        ]));

        return redirect()
            ->route('respite.requests.show', $requestModel)
            ->with('success', 'Respite booking request submitted.');
    }

    public function show(RespiteBookingRequest $request): Response
    {
        $request->load(['client', 'serviceContext', 'approvedBy']);
        $this->authorize('view', $request->client);

        $booking = RespiteBooking::where('booking_request_id', $request->id)->first();

        return Inertia::render('respite/requests/show', [
            'request' => $request,
            'booking' => $booking,
        ]);
    }

    public function update(Request $httpRequest, RespiteBookingRequest $request): RedirectResponse
    {
        $request->loadMissing('client');
        $this->authorize('view', $request->client);

        $validated = $httpRequest->validate([
            'requested_start' => 'sometimes|date',
            'requested_end' => 'sometimes|date|after:requested_start',
            'requirements' => 'nullable|array',
            'preference_notes' => 'nullable|string',
            'funding_reference' => 'nullable|string|max:255',
            'status' => 'sometimes|in:draft,submitted,under_review,approved,rejected,waitlisted',
            'decision_notes' => 'nullable|string',
        ]);

        $validated['updated_by'] = auth()->id();
        $request->update($validated);

        event(new RespiteEvent('respite.booking_request.updated', [
            'id' => $request->id,
            'client_id' => $request->client_id,
            'status' => $request->status,
        ]));

        return back()->with('success', 'Booking request updated.');
    }

    public function approve(RespiteBookingRequest $request): RedirectResponse
    {
        $request->loadMissing('client');
        $this->authorize('view', $request->client);

        $booking = DB::transaction(function () use ($request) {
            $request->update([
                'status' => 'approved',
                'approved_by_user_id' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            $booking = RespiteBooking::firstOrCreate(
                ['booking_request_id' => $request->id],
                [
                    'client_id' => $request->client_id,
                    'start_at' => $request->requested_start,
                    'end_at' => $request->requested_end,
                    'status' => 'pending',
                    'created_by' => auth()->id(),
                ]
            );

            $serviceContextId = $request->service_context_id
                ?: Client::query()->whereKey($request->client_id)->value('service_context_id');

            if (empty($serviceContextId)) {
                $serviceContextId = ServiceContext::defaultId();
            }

            app(RespiteShiftSync::class)->ensureShiftForBooking($booking, auth()->id(), $serviceContextId);

            return $booking;
        });

        event(new RespiteEvent('respite.booking_request.approved', [
            'id' => $request->id,
            'client_id' => $request->client_id,
            'status' => $request->status,
        ]));

        event(new RespiteEvent('respite.booking.created', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return back()->with('success', 'Booking request approved.');
    }
}
