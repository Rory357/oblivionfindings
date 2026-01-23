<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ClientMedicalProfile;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ClientMedicalController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $client->load([
            'medicalProfile',
            'medications.stock',
            'conditions',
            'emergencyContacts',
        ]);

        $user = $request->user();
        $canEdit = $user?->canDo('clients.update') ?? false;
        $canRecord = $canEdit || ($user?->canDo('medications.administer.record') ?? false);
        $canStock = $canEdit || ($user?->canDo('medications.stock.update') ?? false);

        $administrations = ClientMedicationAdministration::query()
            ->where('client_id', $client->id)
            ->with([
                'medication:id,client_id,name,dosage,frequency',
                'administeredBy:id,name,email',
            ])
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return inertia('clients/medical', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'can_edit' => $canEdit,
            'can_record' => $canRecord,
            'can_stock' => $canStock,
            'profile' => $client->medicalProfile,
            'medications' => $client->medications,
            'conditions' => $client->conditions,
            'emergency_contacts' => $client->emergencyContacts,
            'administrations' => $administrations,
        ]);
    }

    public function updateProfile(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'medical_history' => ['nullable', 'string'],
            'disabilities' => ['nullable', 'string'],
            'allergies' => ['nullable', 'string'],
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
        $this->authorize('update', $client);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'is_prn' => ['sometimes', 'boolean'],
            'prn_reason' => ['nullable', 'string', 'max:255'],
            'max_per_day' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'prescriber' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

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
        $this->authorize('update', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'is_prn' => ['sometimes', 'boolean'],
            'prn_reason' => ['nullable', 'string', 'max:255'],
            'max_per_day' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'prescriber' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

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
        $this->authorize('view', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.stock.update') ?? false), 403);

        $data = $request->validate([
            'on_hand' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'last_counted_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $stock = ClientMedicationStock::firstOrNew(['client_medication_id' => $medication->id]);
        $stock->fill($data);
        $stock->client_medication_id = $medication->id;
        if (isset($data['last_counted_at']) && $data['last_counted_at']) {
            $stock->last_counted_at = $data['last_counted_at'];
        }
        try {
            $stock->save();
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
        $this->authorize('view', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.administer.record') ?? false), 403);

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'scheduled_for' => ['nullable', 'date'],
            'administered_at' => ['nullable', 'date'],
            'shift_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        $a = new ClientMedicationAdministration();
        $a->client_id = $client->id;
        $a->client_medication_id = $medication->id;
        $a->administered_by = $user->id;
        $a->shift_id = $data['shift_id'] ?? null;
        $a->status = $data['status'];
        $a->dose_given = $data['dose_given'] ?? null;
        $a->scheduled_for = $data['scheduled_for'] ?? null;
        $a->administered_at = $data['administered_at'] ?? now();
        $a->notes = $data['notes'] ?? null;
        try {
            $a->save();
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

    public function destroyMedication(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('update', $client);
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

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'emergency contact', $c, $client, [
                'title' => 'Emergency contact added: ' . $c->name,
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
