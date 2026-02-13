<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\Alert;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlertController extends Controller
{
    /**
     * List alerts with filters.
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $query = Alert::forTenant($tenantId)
            ->with([
                'site:id,name',
                'assignedTo:id,name',
                'hardware:id,name,category',
            ]);

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->input('site_id'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }

        $alerts = $query->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $sites = Site::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('control-room/alerts/index', [
            'alerts' => $alerts,
            'filters' => $request->only(['site_id', 'severity', 'status', 'provider']),
            'sites' => $sites,
        ]);
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(Request $request, Alert $alert)
    {
        $alert->acknowledge(auth()->id());

        return redirect()->back();
    }

    /**
     * Assign an alert to a user.
     */
    public function assign(Request $request, Alert $alert)
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $alert->status = Alert::STATUS_ASSIGNED;
        $alert->assigned_to_user_id = $request->input('user_id');
        $alert->save();

        return redirect()->back();
    }

    /**
     * Close an alert.
     */
    public function close(Request $request, Alert $alert)
    {
        $request->validate([
            'close_reason' => ['nullable', 'string'],
        ]);

        $alert->close(auth()->id(), $request->input('close_reason'));

        return redirect()->back();
    }

    /**
     * Placeholder for incident creation from an alert.
     */
    public function createIncident(Request $request, Alert $alert)
    {
        return redirect()->back()->with('info', 'Incident linking will be available in a future update');
    }
}
