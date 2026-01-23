<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedication;
use App\Models\ClientMedicalProfile;
use Illuminate\Http\Request;

class ClientMedicalController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $client->load(['medicalProfile', 'medications', 'conditions', 'emergencyContacts']);

        return inertia('clients/medical', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'can_edit' => $request->user()?->canDo('clients.update') ?? false,
            'profile' => $client->medicalProfile,
            'medications' => $client->medications,
            'conditions' => $client->conditions,
            'emergency_contacts' => $client->emergencyContacts,
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

        return back()->with('status', 'Medical profile saved.');
    }

    public function storeMedication(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'prescriber' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
        ]);

        $m = new ClientMedication();
        $m->client_id = $client->id;
        $m->fill($data);
        $m->save();

        return back()->with('status', 'Medication added.');
    }

    public function updateMedication(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('update', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'prescriber' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string'],
        ]);

        $medication->fill($data);
        $medication->save();

        return back()->with('status', 'Medication updated.');
    }

    public function destroyMedication(Request $request, Client $client, ClientMedication $medication)
    {
        $this->authorize('update', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $medication->delete();

        return back()->with('status', 'Medication removed.');
    }

    public function storeCondition(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'severity' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ]);

        $c = new ClientCondition();
        $c->client_id = $client->id;
        $c->fill($data);
        $c->save();

        return back()->with('status', 'Condition added.');
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

        $condition->fill($data);
        $condition->save();

        return back()->with('status', 'Condition updated.');
    }

    public function destroyCondition(Request $request, Client $client, ClientCondition $condition)
    {
        $this->authorize('update', $client);
        abort_unless($condition->client_id === $client->id, 404);

        $condition->delete();

        return back()->with('status', 'Condition removed.');
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

        $e = new ClientEmergencyContact();
        $e->client_id = $client->id;
        $e->fill($data);
        $e->save();

        return back()->with('status', 'Emergency contact added.');
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

        $contact->fill($data);
        $contact->save();

        return back()->with('status', 'Emergency contact updated.');
    }

    public function destroyEmergencyContact(Request $request, Client $client, ClientEmergencyContact $contact)
    {
        $this->authorize('update', $client);
        abort_unless($contact->client_id === $client->id, 404);

        $contact->delete();

        return back()->with('status', 'Emergency contact removed.');
    }
}
