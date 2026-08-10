<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSleepEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientSleepChartController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('medications.view'), 403);

        return ClientSleepEntry::query()
            ->where('client_id', $client->id)
            ->with('recorder:id,name')
            ->orderByDesc('slept_at')
            ->limit(120)
            ->get();
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $this->authorizeSleepWrite($request);

        $data = $this->validatedPayload($request, creating: true);

        ClientSleepEntry::query()->create([
            ...$data,
            'client_id' => $client->id,
            'recorded_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Sleep chart entry added.');
    }

    public function update(Request $request, Client $client, ClientSleepEntry $entry)
    {
        $this->authorize('view', $client);
        abort_unless((int) $entry->client_id === (int) $client->id, 404);
        $this->authorizeSleepWrite($request);

        $entry->update($this->validatedPayload($request, creating: false));

        return back()->with('success', 'Sleep chart entry updated.');
    }

    public function destroy(Request $request, Client $client, ClientSleepEntry $entry)
    {
        $this->authorize('view', $client);
        abort_unless((int) $entry->client_id === (int) $client->id, 404);
        $this->authorizeSleepWrite($request);

        $entry->delete();

        return back()->with('success', 'Sleep chart entry removed.');
    }

    private function authorizeSleepWrite(Request $request): void
    {
        abort_unless(
            $request->user()?->canDo('medications.administer.record')
                || $request->user()?->canDo('clients.update'),
            403
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'slept_at' => [$required, 'date'],
            'hours_slept' => [$required, 'numeric', 'min:0', 'max:24'],
            'quality' => ['nullable', Rule::in(['good', 'fair', 'poor'])],
            'interruptions' => ['nullable', 'integer', 'min:0', 'max:50'],
            'settled_by' => ['nullable', 'date_format:H:i'],
            'woke_at' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
