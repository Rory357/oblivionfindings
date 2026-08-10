<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientFluidEntry;
use App\Support\WorkerClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientFluidChartController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('medications.view'), 403);

        return ClientFluidEntry::query()
            ->where('client_id', $client->id)
            ->with('recorder:id,name')
            ->orderByDesc('occurred_at')
            ->limit(160)
            ->get();
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('medications.administer.record') || $request->user()?->canDo('clients.update'), 403);

        $data = $this->validatedPayload($request, true);

        ClientFluidEntry::query()->create([
            ...$data,
            'client_id' => $client->id,
            'occurred_at' => WorkerClock::toUtc($data['occurred_at'] ?? null) ?? now(),
            'recorded_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Fluid chart entry added.');
    }

    public function update(Request $request, Client $client, ClientFluidEntry $entry)
    {
        $this->authorize('view', $client);
        abort_unless((int) $entry->client_id === (int) $client->id, 404);
        abort_unless($request->user()?->canDo('medications.administer.record') || $request->user()?->canDo('clients.update'), 403);

        $data = $this->validatedPayload($request, false);
        if (array_key_exists('occurred_at', $data) && $data['occurred_at'] !== null) {
            $data['occurred_at'] = WorkerClock::toUtc($data['occurred_at']);
        }

        $entry->update($data);

        return back()->with('success', 'Fluid chart entry updated.');
    }

    public function destroy(Request $request, Client $client, ClientFluidEntry $entry)
    {
        $this->authorize('view', $client);
        abort_unless((int) $entry->client_id === (int) $client->id, 404);
        abort_unless($request->user()?->canDo('medications.administer.record') || $request->user()?->canDo('clients.update'), 403);

        $entry->delete();

        return back()->with('success', 'Fluid chart entry removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'occurred_at' => ['nullable', 'date'],
            'direction' => [$required, Rule::in(['in', 'out'])],
            'fluid_type' => ['nullable', 'string', 'max:120'],
            'volume_ml' => [$required, 'integer', 'min:1', 'max:10000'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
