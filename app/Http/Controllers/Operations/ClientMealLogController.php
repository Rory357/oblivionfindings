<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMealLog;
use App\Support\WorkerClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientMealLogController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $this->authorizeMealWrite($request);

        $data = $this->validatedPayload($request, creating: true);

        ClientMealLog::query()->create([
            ...$data,
            'client_id' => $client->id,
            'organization_id' => $request->user()?->organization_id ?? $client->organization_id,
            'occurred_at' => WorkerClock::toUtc($data['occurred_at'] ?? null) ?? now(),
            'recorded_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Meal logged.');
    }

    public function update(Request $request, Client $client, ClientMealLog $mealLog)
    {
        $this->authorize('view', $client);
        abort_unless((int) $mealLog->client_id === (int) $client->id, 404);
        $this->authorizeMealWrite($request);

        $data = $this->validatedPayload($request, creating: false);
        if (array_key_exists('occurred_at', $data)) {
            $data['occurred_at'] = WorkerClock::toUtc($data['occurred_at']);
        }

        $mealLog->update($data);

        return back()->with('success', 'Meal log updated.');
    }

    public function destroy(Request $request, Client $client, ClientMealLog $mealLog)
    {
        $this->authorize('view', $client);
        abort_unless((int) $mealLog->client_id === (int) $client->id, 404);
        $this->authorizeMealWrite($request);

        $mealLog->delete();

        return back()->with('success', 'Meal log removed.');
    }

    private function authorizeMealWrite(Request $request): void
    {
        abort_unless(
            $request->user()?->canDo('clients.update')
                || $request->user()?->canDo('medications.administer.record'),
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
            'meal_type' => [$required, Rule::in(['breakfast', 'lunch', 'dinner', 'snack'])],
            'status' => [$required, Rule::in(['eaten', 'partial', 'refused', 'declined'])],
            'occurred_at' => ['nullable', 'date'],
            'portion_note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
