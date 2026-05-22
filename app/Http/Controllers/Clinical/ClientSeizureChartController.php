<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSeizureEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClientSeizureChartController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('medications.view'), 403);

        return ClientSeizureEntry::query()
            ->where('client_id', $client->id)
            ->with('recorder:id,name')
            ->orderByDesc('occurred_at')
            ->limit(120)
            ->get();
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('medications.administer.record') || $request->user()?->canDo('clients.update'), 403);

        $data = $this->validatedPayload($request);

        $duration = (int) ($data['duration_seconds'] ?? 0);

        ClientSeizureEntry::query()->create([
            ...$data,
            'client_id' => $client->id,
            'organization_id' => $request->user()?->organization_id ?? $client->organization_id,
            'occurred_at' => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now(),
            'escalated' => (bool) ($data['escalated'] ?? false) || $duration > 300,
            'recorded_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Seizure chart entry added.');
    }

    public function update(Request $request, Client $client, ClientSeizureEntry $entry)
    {
        $this->authorize('view', $client);
        abort_unless((int) $entry->client_id === (int) $client->id, 404);
        abort_unless($request->user()?->canDo('medications.administer.record') || $request->user()?->canDo('clients.update'), 403);

        $data = $this->validatedPayload($request);
        if (array_key_exists('occurred_at', $data) && $data['occurred_at'] !== null) {
            $data['occurred_at'] = Carbon::parse($data['occurred_at']);
        }
        $duration = (int) ($data['duration_seconds'] ?? $entry->duration_seconds ?? 0);
        if (($data['escalated'] ?? false) || $duration > 300) {
            $data['escalated'] = true;
        }

        $entry->update($data);

        return back()->with('success', 'Seizure chart entry updated.');
    }

    public function destroy(Request $request, Client $client, ClientSeizureEntry $entry)
    {
        $this->authorize('view', $client);
        abort_unless((int) $entry->client_id === (int) $client->id, 404);
        abort_unless($request->user()?->canDo('medications.administer.record') || $request->user()?->canDo('clients.update'), 403);

        $entry->delete();

        return back()->with('success', 'Seizure chart entry removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'occurred_at' => ['nullable', 'date'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'seizure_type' => ['nullable', 'string', 'max:120'],
            'trigger' => ['nullable', 'string', 'max:255'],
            'response_taken' => ['nullable', 'string'],
            'recovery_notes' => ['nullable', 'string'],
            'escalated' => ['nullable', 'boolean'],
            'follow_up_action' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
