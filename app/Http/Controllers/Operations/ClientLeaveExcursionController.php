<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientExcursionRequest;
use App\Models\ClientLeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientLeaveExcursionController extends Controller
{
    public function storeLeave(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'destination' => ['nullable', 'string', 'max:255'],
            'support_required' => ['nullable', 'string'],
            'risks_and_mitigations' => ['nullable', 'string'],
            'emergency_contact' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['requested', 'approved', 'declined', 'completed', 'cancelled'])],
            'approval_notes' => ['nullable', 'string'],
        ]);

        $leave = ClientLeaveRequest::create(array_merge(
            $data,
            [
                'client_id' => $client->id,
                'organization_id' => $client->organization_id,
                'requested_by' => $request->user()?->id,
                'status' => $data['status'] ?? 'requested',
            ],
        ));

        return back()->with('success', "Leave request #{$leave->id} captured.");
    }

    public function updateLeave(Request $request, Client $client, ClientLeaveRequest $leave)
    {
        abort_unless($leave->client_id === $client->id, 404);
        $this->authorize('update', $client);

        $data = $request->validate([
            'status' => ['required', Rule::in(['requested', 'approved', 'declined', 'completed', 'cancelled'])],
            'approval_notes' => ['nullable', 'string'],
        ]);

        $leave->fill($data);
        if (in_array($data['status'], ['approved', 'declined'], true)) {
            $leave->approved_by = $request->user()?->id;
            $leave->approved_at = now();
        }
        $leave->save();

        return back()->with('success', "Leave request #{$leave->id} updated.");
    }

    public function destroyLeave(Request $request, Client $client, ClientLeaveRequest $leave)
    {
        abort_unless($leave->client_id === $client->id, 404);
        $this->authorize('update', $client);

        $leave->delete();

        return back()->with('success', "Leave request #{$leave->id} removed.");
    }

    public function storeExcursion(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'destination' => ['nullable', 'string', 'max:255'],
            'activity_description' => ['nullable', 'string'],
            'transport_method' => ['nullable', 'string', 'max:100'],
            'staff_assignments' => ['nullable', 'array'],
            'risk_assessment' => ['nullable', 'string'],
            'outcome_notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['proposed', 'approved', 'declined', 'completed', 'cancelled'])],
            'approval_notes' => ['nullable', 'string'],
        ]);

        $excursion = ClientExcursionRequest::create(array_merge(
            $data,
            [
                'client_id' => $client->id,
                'organization_id' => $client->organization_id,
                'requested_by' => $request->user()?->id,
                'status' => $data['status'] ?? 'proposed',
            ],
        ));

        return back()->with('success', "Excursion #{$excursion->id} captured.");
    }

    public function updateExcursion(Request $request, Client $client, ClientExcursionRequest $excursion)
    {
        abort_unless($excursion->client_id === $client->id, 404);
        $this->authorize('update', $client);

        $data = $request->validate([
            'status' => ['required', Rule::in(['proposed', 'approved', 'declined', 'completed', 'cancelled'])],
            'approval_notes' => ['nullable', 'string'],
            'outcome_notes' => ['nullable', 'string'],
        ]);

        $excursion->fill($data);
        if (in_array($data['status'], ['approved', 'declined'], true)) {
            $excursion->approved_by = $request->user()?->id;
            $excursion->approved_at = now();
        }
        $excursion->save();

        return back()->with('success', "Excursion #{$excursion->id} updated.");
    }

    public function destroyExcursion(Request $request, Client $client, ClientExcursionRequest $excursion)
    {
        abort_unless($excursion->client_id === $client->id, 404);
        $this->authorize('update', $client);

        $excursion->delete();

        return back()->with('success', "Excursion #{$excursion->id} removed.");
    }
}
