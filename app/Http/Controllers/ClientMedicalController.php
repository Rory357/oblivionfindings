<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ClientMedicalProfile;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientDocument;
use App\Models\ServiceContext;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ClientMedicalController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);

        $client->load([
            'medicalProfile',
            'medications.stock',
            'conditions',
            'emergencyContacts',
        ]);

        // Step 14: medication chart attachments (stored as client documents with category = "med_chart")
        $medCharts = ClientDocument::query()
            ->where('client_id', $client->id)
            ->where('category', 'med_chart')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['id', 'title', 'category', 'version', 'effective_date', 'expiry_date', 'portal_visible']);

        $user = $request->user();
        $canEdit = $user?->canDo('clients.update') ?? false;
        $canRecord = $canEdit || ($user?->canDo('medications.administer.record') ?? false);
        $canStock = $canEdit || ($user?->canDo('medications.stock.update') ?? false);

        $canControlledView = $canEdit || ($user?->canDo('medications.controlled.view') ?? false);
        $canControlledRecord = $canEdit || ($user?->canDo('medications.controlled.record') ?? false);
        $canControlledWitness = $canEdit || ($user?->canDo('medications.controlled.witness') ?? false);

        $witnesses = User::staff()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->filter(fn (User $u) => $u->canDo('medications.controlled.witness'))
            ->values()
            ->map(fn (User $u) => $u->only(['id', 'name', 'email']));

        $administrations = ClientMedicationAdministration::query()
            ->where('client_id', $client->id)
            ->with([
                'medication:id,client_id,name,dosage,frequency',
                'administeredBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function (ClientMedicationAdministration $a) {
                $lateMinutes = null;
                if ($a->scheduled_for && $a->administered_at) {
                    $diff = $a->scheduled_for->diffInMinutes($a->administered_at, false);
                    $lateMinutes = $diff > 0 ? $diff : 0;
                }

                return [
                    'id' => $a->id,
                    'status' => $a->status,
                    'reason' => $a->reason,
                    'dose_given' => $a->dose_given,
                    'scheduled_for' => $a->scheduled_for,
                    'administered_at' => $a->administered_at,
                    'notes' => $a->notes,
                    'late_minutes' => $lateMinutes,
                    'shift_id' => $a->shift_id,
                    'medication' => $a->medication,
                    'administeredBy' => $a->administeredBy,
                    'serviceContext' => $a->serviceContext ? [
                        'id' => $a->serviceContext->id,
                        'name' => $a->serviceContext->name,
                        'type' => (string) ($a->serviceContext->type?->value ?? $a->serviceContext->type),
                    ] : null,
                ];
            })
            ->values();

        $controlledEntries = collect();
        if ($canControlledView) {
            $controlledEntries = ClientControlledDrugEntry::query()
                ->where('client_id', $client->id)
                ->with([
                    'medication:id,client_id,name,controlled_drug',
                    'recordedBy:id,name,email',
                    'witnessedBy:id,name,email',
                    'serviceContext:id,name,type',
                ])
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(function (ClientControlledDrugEntry $e) {
                    return [
                        'id' => $e->id,
                        'entry_type' => $e->entry_type,
                        'quantity' => $e->quantity,
                        'unit' => $e->unit,
                        'on_hand_before' => $e->on_hand_before,
                        'on_hand_after' => $e->on_hand_after,
                        'reason' => $e->reason,
                        'notes' => $e->notes,
                        'recorded_at' => $e->recorded_at,
                        'medication' => $e->medication,
                        'recordedBy' => $e->recordedBy,
                        'witnessedBy' => $e->witnessedBy,
                        'serviceContext' => $e->serviceContext ? [
                            'id' => $e->serviceContext->id,
                            'name' => $e->serviceContext->name,
                            'type' => (string) ($e->serviceContext->type?->value ?? $e->serviceContext->type),
                        ] : null,
                    ];
                })
                ->values();
        }

        $controlledDiscrepancies = collect();
        if ($canControlledView) {
            $controlledDiscrepancies = ClientControlledDrugDiscrepancy::query()
                ->where('client_id', $client->id)
                ->with([
                    'medication:id,client_id,name,controlled_drug',
                    'reportedBy:id,name,email',
                    'witnessedBy:id,name,email',
                    'resolvedBy:id,name,email',
                    'serviceContext:id,name,type',
                ])
                ->orderByRaw("status = 'open' desc")
                ->orderByDesc('reported_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(function (ClientControlledDrugDiscrepancy $d) {
                    return [
                        'id' => $d->id,
                        'status' => $d->status,
                        'difference' => $d->difference,
                        'on_hand_before' => $d->on_hand_before,
                        'on_hand_after' => $d->on_hand_after,
                        'reason' => $d->reason,
                        'notes' => $d->notes,
                        'reported_at' => $d->reported_at,
                        'resolved_at' => $d->resolved_at,
                        'resolution_notes' => $d->resolution_notes,
                        'medication' => $d->medication,
                        'reportedBy' => $d->reportedBy,
                        'witnessedBy' => $d->witnessedBy,
                        'resolvedBy' => $d->resolvedBy,
                        'serviceContext' => $d->serviceContext ? [
                            'id' => $d->serviceContext->id,
                            'name' => $d->serviceContext->name,
                            'type' => (string) ($d->serviceContext->type?->value ?? $d->serviceContext->type),
                        ] : null,
                    ];
                })
                ->values();
        }

        return inertia('operations/clients/medical', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'can_edit' => $canEdit,
            'can_record' => $canRecord,
            'can_stock' => $canStock,
            'profile' => $client->medicalProfile,
            'medications' => $client->medications,
            'conditions' => $client->conditions,
            'emergency_contacts' => $client->emergencyContacts,
            'administrations' => $administrations,
            'can_controlled_view' => $canControlledView,
            'can_controlled_record' => $canControlledRecord,
            'can_controlled_witness' => $canControlledWitness,
            'witnesses' => $witnesses,
            'controlled_entries' => $controlledEntries,
            'controlled_discrepancies' => $controlledDiscrepancies,
            'med_charts' => $medCharts,
            'has_open_controlled_discrepancy' => ClientControlledDrugDiscrepancy::query()
                ->where('client_id', $client->id)
                ->whereIn('status', ['open', 'under_review'])
                ->exists(),
            'disability_options' => ClientMedicalProfile::DISABILITY_OPTIONS,
            'allergen_options' => ClientMedicalProfile::ALLERGEN_OPTIONS,
        ]);
    }

    public function updateProfile(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'medical_history' => ['nullable', 'string'],
            'disabilities' => ['nullable', 'array'],
            'disabilities.*' => ['string', 'max:255'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $profile = ClientMedicalProfile::firstOrNew(['client_id' => $client->id]);
        $profile->fill($data);
        $profile->client_id = $client->id;
        $profile->save();

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'medical profile', $profile, $client, [
                'title' => 'Medical profile updated',
                'url' => url("/clients/{$client->id}/medical"),
            ]);

        return back()->with('success', 'Medical profile saved successfully.');
    }

    public function storeMedication(Request $request, Client $client)
    {
        $this->authorize('viewMedications', $client);
        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.orders.manage') ?? false), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'dose_times' => ['nullable', 'array'],
            'dose_times.*' => ['string', 'regex:/^\d{2}:\d{2}$/'],
            'is_prn' => ['sometimes', 'boolean'],
            'controlled_drug' => ['sometimes', 'boolean'],
            'prn_reason' => ['nullable', 'string', 'max:255'],
            'max_per_day' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:255'],
            'prescriber' => ['nullable', 'string', 'max:255'],
            'pharmacy' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'ceased_at' => ['nullable', 'date'],
            'ceased_reason' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'in:active,paused,ceased'],
            'paused_at' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Normalize state/active
        if (!empty($data['state'])) {
            $data['active'] = $data['state'] === 'active';
        } else {
            $data['state'] = ($data['active'] ?? true) ? 'active' : 'ceased';
        }

        try {
            $m = new ClientMedication();
            $m->client_id = $client->id;
            $m->fill($data);
            $m->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'medication', $m, $client, [
                'title' => 'Medication added: ' . $m->name,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Medication added successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to add medication: ' . $e->getMessage());
        }
    }

    public function updateMedication(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.orders.manage') ?? false), 403);
        abort_unless($medication->client_id === $client->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'dose_times' => ['nullable', 'array'],
            'dose_times.*' => ['string', 'regex:/^\d{2}:\d{2}$/'],
            'is_prn' => ['sometimes', 'boolean'],
            'controlled_drug' => ['sometimes', 'boolean'],
            'prn_reason' => ['nullable', 'string', 'max:255'],
            'max_per_day' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:255'],
            'prescriber' => ['nullable', 'string', 'max:255'],
            'pharmacy' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'ceased_at' => ['nullable', 'date'],
            'ceased_reason' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'in:active,paused,ceased'],
            'paused_at' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (!empty($data['state'])) {
            $data['active'] = $data['state'] === 'active';
        } else {
            $data['state'] = ($data['active'] ?? true) ? 'active' : 'ceased';
        }

        try {
            $medication->fill($data);
            $medication->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'medication', $medication, $client, [
                'title' => 'Medication updated: ' . $medication->name,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Medication updated successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to update medication: ' . $e->getMessage());
        }
    }

    public function updateMedicationStock(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.stock.update') ?? false), 403);

        $data = $request->validate([
            'on_hand' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'last_counted_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'witnessed_by' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $stock = ClientMedicationStock::firstOrNew(['client_medication_id' => $medication->id]);
        $beforeOnHand = $stock->exists ? $stock->on_hand : null;
        $stock->fill($data);
        $stock->client_medication_id = $medication->id;
        if (isset($data['last_counted_at']) && $data['last_counted_at']) {
            $stock->last_counted_at = $data['last_counted_at'];
        }

        // Controlled drug stock counts/adjustments: require permissions, a witness and a reason.
        if ($medication->controlled_drug) {
            abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.controlled.record') ?? false), 403);

            // Step 13: optional governance - block further controlled stock edits when an open discrepancy exists
            $hasOpen = ClientControlledDrugDiscrepancy::query()
                ->where('client_id', $client->id)
                ->where('client_medication_id', $medication->id)
                ->whereIn('status', ['open', 'under_review'])
                ->exists();
            if ($hasOpen && !($user?->canDo('medications.controlled.override') ?? false) && !($user?->canDo('clients.update') ?? false)) {
                return back()->withInput()->with('error', 'There is an open controlled-drug discrepancy. Further stock edits are blocked unless you have override permission.');
            }
            if (empty($data['witnessed_by'])) {
                return back()->withInput()->with('error', 'A witness is required when updating controlled drug stock.');
            }
            if ((int) $data['witnessed_by'] === (int) $user->id) {
                return back()->withInput()->with('error', 'The witness must be a different user.');
            }
            if (empty($data['reason'])) {
                return back()->withInput()->with('error', 'Please provide a reason for the controlled drug stock update.');
            }

            $witness = User::query()->find($data['witnessed_by']);
            if (!$witness || $witness->hasRole('client', 'next_of_kin') || in_array($witness->role, ['client', 'next_of_kin'], true) || !$witness->canDo('medications.controlled.witness')) {
                return back()->withInput()->with('error', 'Selected witness is not authorised to witness controlled drug actions.');
            }
        }
        try {
            $stock->save();
            if ($medication->controlled_drug) {
                ClientControlledDrugEntry::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'shift_id' => null,
                    'service_context_id' => $client->service_context_id ?: ServiceContext::defaultId(),
                    'entry_type' => 'stock_count',
                    'quantity' => null,
                    'unit' => $stock->unit,
                    'on_hand_before' => $beforeOnHand,
                    'on_hand_after' => $stock->on_hand,
                    'reason' => $data['reason'],
                    'notes' => $data['notes'] ?? null,
                    'recorded_at' => now(),
                    'recorded_by' => $user->id,
                    'witnessed_by' => (int) $data['witnessed_by'],
                ]);

                // If the counted stock differs from the last known on-hand, flag a discrepancy for review.
                if ($beforeOnHand !== null && $stock->on_hand !== null && (int) $stock->on_hand !== (int) $beforeOnHand) {
                    ClientControlledDrugDiscrepancy::create([
                        'client_id' => $client->id,
                        'client_medication_id' => $medication->id,
                        'service_context_id' => $client->service_context_id ?: ServiceContext::defaultId(),
                        'on_hand_before' => $beforeOnHand,
                        'on_hand_after' => $stock->on_hand,
                        'difference' => (int) $stock->on_hand - (int) $beforeOnHand,
                        'reason' => $data['reason'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'reported_at' => now(),
                        'reported_by' => $user->id,
                        'witnessed_by' => (int) $data['witnessed_by'],
                        'status' => 'open',
                    ]);
                }
            }
            app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'medication stock', $stock, $client, [
                'title' => 'Medication stock updated: ' . ($medication->name ?? 'Medication'),
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Medication stock updated successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to update medication stock: ' . $e->getMessage());
        }
    }

    public function storeAdministration(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.administer.record') ?? false), 403);

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed,withheld'],
            'reason' => ['nullable', 'string', 'max:255'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'scheduled_for' => ['nullable', 'date'],
            'administered_at' => ['nullable', 'date'],
            'shift_id' => ['nullable', 'integer'],
            'witnessed_by' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        // Require a reason whenever the outcome is not "given".
        if (($data['status'] ?? 'given') !== 'given' && empty($data['reason'])) {
            return back()->withInput()->with('error', 'Please provide a reason when the medication is not given.');
        }

        // For PRN (as-needed) medication, require an indication/reason even when "given".
        if ($medication->is_prn && (($data['status'] ?? 'given') === 'given') && empty($data['reason'])) {
            return back()->withInput()->with('error', 'Please provide the PRN indication (reason) for as-needed medication.');
        }

        // Step 8: time window logic. If a scheduled dose is administered outside the safe window,
        // require a reason even for "given".
        if (($data['status'] ?? 'given') === 'given' && !empty($data['scheduled_for'])) {
            try {
                $scheduled = \Carbon\Carbon::parse($data['scheduled_for']);
                $adminAt = !empty($data['administered_at']) ? \Carbon\Carbon::parse($data['administered_at']) : now();

                $lateAfterMinutes = 30;
                $earlyBeforeMinutes = 60;
                $diff = $scheduled->diffInMinutes($adminAt, false); // adminAt - scheduled

                if (($diff < -$earlyBeforeMinutes || $diff > $lateAfterMinutes) && empty($data['reason'])) {
                    return back()->withInput()->with('error', 'Please provide a reason when administering outside the scheduled time window.');
                }
            } catch (\Throwable $e) {
                // ignore parse errors
            }
        }

        // Controlled drugs: require permission + witness when recording a "given" administration.
        if ($medication->controlled_drug && (($data['status'] ?? 'given') === 'given')) {
            abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.controlled.record') ?? false), 403);
            if (empty($data['witnessed_by'])) {
                return back()->withInput()->with('error', 'A witness is required when administering a controlled drug.');
            }
            if ((int) $data['witnessed_by'] === (int) $user->id) {
                return back()->withInput()->with('error', 'The witness must be a different user.');
            }

            $witness = User::query()->find($data['witnessed_by']);
            if (!$witness || $witness->hasRole('client', 'next_of_kin') || in_array($witness->role, ['client', 'next_of_kin'], true) || !$witness->canDo('medications.controlled.witness')) {
                return back()->withInput()->with('error', 'Selected witness is not authorised to witness controlled drug actions.');
            }
        }

        $medication->loadMissing('stock');

        $a = new ClientMedicationAdministration();
        $a->client_id = $client->id;
        $a->client_medication_id = $medication->id;
        $a->administered_by = $user->id;
        $a->shift_id = $data['shift_id'] ?? null;
        $a->service_context_id = null;
        if ($a->shift_id) {
            $shift = \App\Models\Shift::query()->find($a->shift_id);
            $a->service_context_id = $shift?->service_context_id;
        }
        if (!$a->service_context_id) {
            $a->service_context_id = $client->service_context_id ?: ServiceContext::defaultId();
        }
        $a->status = $data['status'];
        $a->reason = $data['reason'] ?? null;
        $a->dose_given = $data['dose_given'] ?? null;
        $a->scheduled_for = $data['scheduled_for'] ?? null;
        $a->administered_at = $data['administered_at'] ?? now();
        $a->notes = $data['notes'] ?? null;
        try {
            $a->save();

            // Controlled drug register entry (double-sign).
            if ($medication->controlled_drug && $a->status === 'given') {
                $medication->loadMissing('stock');
                ClientControlledDrugEntry::create([
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'shift_id' => $a->shift_id,
                    'service_context_id' => $a->service_context_id,
                    'entry_type' => 'administered',
                    'quantity' => null,
                    'unit' => $medication->stock?->unit,
                    'on_hand_before' => $medication->stock?->on_hand,
                    'on_hand_after' => $medication->stock?->on_hand,
                    'reason' => $a->reason,
                    'notes' => $a->notes,
                    'recorded_at' => $a->administered_at,
                    'recorded_by' => $user->id,
                    'witnessed_by' => (int) $data['witnessed_by'],
                ]);
            }
            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'medication administration', $a, $client, [
                'title' => 'Medication administration recorded',
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Medication administration recorded.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to record administration: ' . $e->getMessage());
        }
    }

    public function closeControlledDiscrepancy(Request $request, Client $client, ClientControlledDrugDiscrepancy $discrepancy)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($discrepancy->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.controlled.record') ?? false), 403);

        $data = $request->validate([
            'resolution_notes' => ['nullable', 'string'],
        ]);

        if ($discrepancy->status === 'closed') {
            return back()->with('success', 'Discrepancy already closed.');
        }

        $discrepancy->status = 'closed';
        $discrepancy->resolved_at = now();
        $discrepancy->resolved_by = $user?->id;
        $discrepancy->resolution_notes = $data['resolution_notes'] ?? null;
        $discrepancy->save();

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'controlled drug discrepancy', $discrepancy, $client, [
            'title' => 'Controlled drug discrepancy closed',
            'url' => url("/clients/{$client->id}/medical"),
        ]);

        return back()->with('success', 'Discrepancy closed.');
    }

    public function destroyMedication(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('viewMedications', $client);
        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.orders.manage') ?? false), 403);
        abort_unless($medication->client_id === $client->id, 404);

        try {
            $medication->delete();
            app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'medication', $medication, $client, [
                'title' => 'Medication removed: ' . ($medication->name ?? 'Medication'),
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Medication removed successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Failed to remove medication: ' . $e->getMessage());
        }
    }

    public function storeCondition(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'severity' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $c = new ClientCondition();
            $c->client_id = $client->id;
            $c->fill($data);
            $c->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'condition', $c, $client, [
                'title' => 'Condition added: ' . $c->label,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Condition added successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to add condition: ' . $e->getMessage());
        }
    }

    public function updateCondition(Request $request, Client $client, ClientCondition $condition)
    {
        $this->authorize('update', $client);
        abort_unless($condition->client_id === $client->id, 404);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'severity' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $condition->fill($data);
            $condition->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'condition', $condition, $client, [
                'title' => 'Condition updated: ' . $condition->label,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Condition updated successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to update condition: ' . $e->getMessage());
        }
    }

    public function destroyCondition(Request $request, Client $client, ClientCondition $condition)
    {
        $this->authorize('update', $client);
        abort_unless($condition->client_id === $client->id, 404);

        try {
            $condition->delete();
            app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'condition', $condition, $client, [
                'title' => 'Condition removed: ' . ($condition->label ?? 'Condition'),
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Condition removed successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Failed to remove condition: ' . $e->getMessage());
        }
    }

    public function storeEmergencyContact(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $e = new ClientEmergencyContact();
            $e->client_id = $client->id;
            $e->fill($data);
            $e->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'emergency contact', $e, $client, [
                'title' => 'Emergency contact added: ' . $e->name,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Emergency contact added successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to add emergency contact: ' . $e->getMessage());
        }
    }

    public function updateEmergencyContact(Request $request, Client $client, ClientEmergencyContact $contact)
    {
        $this->authorize('update', $client);
        abort_unless($contact->client_id === $client->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $contact->fill($data);
            $contact->save();

            app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'emergency contact', $contact, $client, [
                'title' => 'Emergency contact updated: ' . $contact->name,
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Emergency contact updated successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->withInput()->with('error', 'Failed to update emergency contact: ' . $e->getMessage());
        }
    }

    public function destroyEmergencyContact(Request $request, Client $client, ClientEmergencyContact $contact)
    {
        $this->authorize('update', $client);
        abort_unless($contact->client_id === $client->id, 404);

        try {
            $contact->delete();
            app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'emergency contact', $contact, $client, [
                'title' => 'Emergency contact removed: ' . ($contact->name ?? 'Contact'),
                'url' => url("/clients/{$client->id}/medical"),
            ]);

            return back()->with('success', 'Emergency contact removed successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Failed to remove emergency contact: ' . $e->getMessage());
        }
    }
}
