<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetShiftHandover;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class HandoverController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('fleet_shift_handovers')) {
            $vehicles = Asset::query()
                ->where('category', 'vehicle')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
                ->values();

            return Inertia::render('fleet-assets/handovers/index', [
                'handovers' => collect(),
                'vehicles' => $vehicles,
                'filters' => $request->only(['vehicle_id', 'status', 'date_from', 'date_to']),
            ]);
        }

        $query = FleetShiftHandover::query()
            ->with([
                'asset:id,name,registration_number',
                'outgoingUser:id,name',
                'incomingUser:id,name',
            ]);

        if ($request->filled('vehicle_id')) {
            $query->where('asset_id', $request->input('vehicle_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('handed_over_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('handed_over_at', '<=', $request->input('date_to'));
        }

        $handovers = $query
            ->latest('handed_over_at')
            ->limit(100)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'asset' => $h->asset ? [
                    'id' => $h->asset->id,
                    'name' => $h->asset->name,
                    'registration_number' => $h->asset->registration_number,
                ] : null,
                'outgoing_user' => $h->outgoingUser ? ['id' => $h->outgoingUser->id, 'name' => $h->outgoingUser->name] : null,
                'incoming_user' => $h->incomingUser ? ['id' => $h->incomingUser->id, 'name' => $h->incomingUser->name] : null,
                'odometer_km' => $h->odometer_km,
                'fuel_level' => $h->fuel_level,
                'exterior_condition' => $h->exterior_condition,
                'interior_condition' => $h->interior_condition,
                'status' => $h->status,
                'handed_over_at' => optional($h->handed_over_at)->toISOString(),
                'accepted_at' => optional($h->accepted_at)->toISOString(),
            ])
            ->values();

        $vehicles = Asset::query()
            ->where('category', 'vehicle')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
            ->values();

        return Inertia::render('fleet-assets/handovers/index', [
            'handovers' => $handovers,
            'vehicles' => $vehicles,
            'filters' => $request->only(['vehicle_id', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function create(Request $request)
    {
        $vehicles = Asset::query()
            ->where('category', 'vehicle')
            ->orderBy('name')
            ->get(['id', 'name', 'registration_number'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'registration_number' => $a->registration_number,
            ])
            ->values();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        return Inertia::render('fleet-assets/handovers/create', [
            'vehicles' => $vehicles,
            'users' => $users,
            'current_user_id' => $request->user()->id,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'incoming_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'odometer_km' => ['nullable', 'integer', 'min:0'],
            'fuel_level' => ['nullable', 'string', 'in:full,3/4,1/2,1/4,empty'],
            'exterior_condition' => ['required', 'string', 'in:good,minor_damage,significant_damage'],
            'interior_condition' => ['required', 'string', 'in:clean,acceptable,needs_cleaning'],
            'keys_present' => ['boolean'],
            'documents_present' => ['boolean'],
            'first_aid_kit' => ['boolean'],
            'fire_extinguisher' => ['boolean'],
            'damage_notes' => ['nullable', 'array'],
            'damage_notes.*.area' => ['required_with:damage_notes', 'string'],
            'damage_notes.*.description' => ['required_with:damage_notes', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $handover = FleetShiftHandover::create([
            'asset_id' => $data['asset_id'],
            'outgoing_user_id' => $request->user()->id,
            'incoming_user_id' => $data['incoming_user_id'] ?? null,
            'odometer_km' => $data['odometer_km'] ?? null,
            'fuel_level' => $data['fuel_level'] ?? null,
            'exterior_condition' => $data['exterior_condition'],
            'interior_condition' => $data['interior_condition'],
            'keys_present' => $data['keys_present'] ?? true,
            'documents_present' => $data['documents_present'] ?? true,
            'first_aid_kit' => $data['first_aid_kit'] ?? true,
            'fire_extinguisher' => $data['fire_extinguisher'] ?? true,
            'damage_notes' => $data['damage_notes'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        AuditLogger::log('fleet.handover.create', $handover, [
            'asset_id' => $data['asset_id'],
            'outgoing_user_id' => $request->user()->id,
            'incoming_user_id' => $data['incoming_user_id'] ?? null,
        ]);

        return redirect()
            ->route('fleet-assets.handovers.show', $handover)
            ->with('success', 'Shift handover created successfully.');
    }

    public function show(FleetShiftHandover $handover)
    {
        $handover->load([
            'asset:id,name,registration_number',
            'outgoingUser:id,name',
            'incomingUser:id,name',
        ]);

        return Inertia::render('fleet-assets/handovers/show', [
            'handover' => [
                'id' => $handover->id,
                'asset' => $handover->asset ? [
                    'id' => $handover->asset->id,
                    'name' => $handover->asset->name,
                    'registration_number' => $handover->asset->registration_number,
                ] : null,
                'outgoing_user' => $handover->outgoingUser ? [
                    'id' => $handover->outgoingUser->id,
                    'name' => $handover->outgoingUser->name,
                ] : null,
                'incoming_user' => $handover->incomingUser ? [
                    'id' => $handover->incomingUser->id,
                    'name' => $handover->incomingUser->name,
                ] : null,
                'odometer_km' => $handover->odometer_km,
                'fuel_level' => $handover->fuel_level,
                'exterior_condition' => $handover->exterior_condition,
                'interior_condition' => $handover->interior_condition,
                'keys_present' => $handover->keys_present,
                'documents_present' => $handover->documents_present,
                'first_aid_kit' => $handover->first_aid_kit,
                'fire_extinguisher' => $handover->fire_extinguisher,
                'damage_notes' => $handover->damage_notes,
                'notes' => $handover->notes,
                'status' => $handover->status,
                'handed_over_at' => optional($handover->handed_over_at)->toISOString(),
                'accepted_at' => optional($handover->accepted_at)->toISOString(),
                'created_at' => optional($handover->created_at)->toISOString(),
            ],
            'current_user_id' => request()->user()->id,
        ]);
    }

    public function accept(Request $request, FleetShiftHandover $handover)
    {
        if ($handover->status !== 'pending_acceptance') {
            return back()->with('error', 'This handover has already been processed.');
        }

        $handover->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        AuditLogger::log('fleet.handover.accept', $handover, [
            'accepted_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Handover accepted.');
    }

    public function dispute(Request $request, FleetShiftHandover $handover)
    {
        if ($handover->status !== 'pending_acceptance') {
            return back()->with('error', 'This handover has already been processed.');
        }

        $data = $request->validate([
            'dispute_reason' => ['required', 'string', 'max:2000'],
        ]);

        $handover->update([
            'status' => 'disputed',
            'notes' => ($handover->notes ? $handover->notes . "\n\n" : '') . 'DISPUTE: ' . $data['dispute_reason'],
        ]);

        AuditLogger::log('fleet.handover.dispute', $handover, [
            'disputed_by' => $request->user()->id,
            'reason' => $data['dispute_reason'],
        ]);

        return back()->with('success', 'Handover disputed. Management has been notified.');
    }
}
