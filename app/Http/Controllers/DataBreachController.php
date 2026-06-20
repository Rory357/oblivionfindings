<?php

namespace App\Http\Controllers;

use App\Models\DataBreachLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataBreachController extends Controller
{
    /**
     * Display a listing of data breaches.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canDo('privacy.reportBreaches'), 403);

        $query = DataBreachLog::query()
            ->with(['discoveredBy', 'creator']);

        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('breach_reference', 'like', "%{$request->q}%")
                    ->orWhere('nature_of_breach', 'like', "%{$request->q}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('requires_notification') && $request->requires_notification === '1') {
            $query->where('requires_authority_notification', true)
                ->whereNull('authority_notified_at');
        }

        $query->orderByDesc('discovered_at');

        $breaches = $query->paginate(20)->withQueryString();

        return Inertia::render('privacy/breaches', [
            'breaches' => $breaches,
            'filters' => $request->only(['q', 'status', 'requires_notification']),
            'stats' => [
                'total' => DataBreachLog::count(),
                'open' => DataBreachLog::where('status', '!=', 'resolved')->count(),
                'requiring_notification' => DataBreachLog::where('requires_authority_notification', true)
                    ->whereNull('authority_notified_at')
                    ->count(),
                'resolved_30_days' => DataBreachLog::where('status', 'resolved')
                    ->where('resolved_at', '>=', now()->subDays(30))
                    ->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new breach record.
     */
    public function create(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.reportBreaches'), 403);

        return redirect('/privacy/dashboard?new=breach');
    }

    /**
     * Store a newly created breach record.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.reportBreaches'), 403);

        $validated = $request->validate([
            'nature_of_breach' => 'required|string',
            'discovered_at' => 'required|date',
            'affected_data_categories' => 'nullable|array',
            'approximate_individuals_affected' => 'nullable|integer|min:0',
            'likely_consequences' => 'nullable|string',
            'measures_taken' => 'nullable|string',
            'requires_authority_notification' => 'boolean',
            'requires_subject_notification' => 'boolean',
        ]);

        $validated['breach_reference'] = 'BR-' . now()->year . '-' . str_pad(
            DataBreachLog::whereYear('created_at', now()->year)->count() + 1,
            4,
            '0',
            STR_PAD_LEFT
        );
        $validated['discovered_by_user_id'] = auth()->id();
        $validated['created_by'] = auth()->id();
        $validated['status'] = 'discovered';

        $breach = DataBreachLog::create($validated);

        $message = 'Data breach recorded with reference: ' . $breach->breach_reference;

        if ($request->boolean('_modal')) {
            return back()->with('success', $message);
        }

        return redirect()
            ->route('privacy.breaches.show', $breach)
            ->with('success', $message);
    }

    /**
     * Display the specified breach.
     */
    public function show(Request $request, DataBreachLog $breach): Response
    {
        abort_unless($request->user()?->canDo('privacy.reportBreaches'), 403);

        $breach->load(['discoveredBy', 'creator']);

        return Inertia::render('privacy/breaches/show', [
            'breach' => $breach,
        ]);
    }

    /**
     * Update the specified breach.
     */
    public function update(Request $request, DataBreachLog $breach): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.reportBreaches'), 403);

        $validated = $request->validate([
            'nature_of_breach' => 'sometimes|string',
            'affected_data_categories' => 'nullable|array',
            'approximate_individuals_affected' => 'nullable|integer|min:0',
            'likely_consequences' => 'nullable|string',
            'measures_taken' => 'nullable|string',
            'requires_authority_notification' => 'boolean',
            'requires_subject_notification' => 'boolean',
            'status' => 'sometimes|in:discovered,under_investigation,contained,notified,resolved',
        ]);

        $breach->update($validated);

        return back()->with('success', 'Breach record updated.');
    }

    /**
     * Notify the OPC (as soon as practicable Privacy Act 2020 requirement).
     */
    public function notifyOPC(Request $request, DataBreachLog $breach): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.reportBreaches'), 403);

        $request->validate([
            'authority_reference' => 'nullable|string|max:255',
        ]);

        $attributes = [
            'authority_notified_at' => now(),
            'authority_reference' => $request->authority_reference,
        ];

        if ($breach->status !== 'resolved') {
            $attributes['status'] = 'notified';
        }

        $breach->update($attributes);

        return back()->with('success', 'OPC notification recorded.');
    }

    /**
     * Notify affected people affected.
     */
    public function notifySubjects(Request $request, DataBreachLog $breach): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.reportBreaches'), 403);

        $request->validate([
            'notification_method' => 'required|string|max:255',
        ]);

        $attributes = [
            'subjects_notified_at' => now(),
            'notification_method' => $request->notification_method,
        ];

        if ($breach->status !== 'resolved') {
            $attributes['status'] = 'notified';
        }

        $breach->update($attributes);

        return back()->with('success', 'Subject notification recorded.');
    }

    /**
     * Resolve the breach.
     */
    public function resolve(Request $request, DataBreachLog $breach): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.reportBreaches'), 403);

        $request->validate([
            'resolution_notes' => 'required|string',
        ]);

        $breach->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => $request->resolution_notes,
        ]);

        return back()->with('success', 'Breach marked as resolved.');
    }
}
