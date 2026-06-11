<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\ClientRespiteAllocation;
use Illuminate\Http\Request;

class ClientRespiteAllocationController extends Controller
{
    public function store(Request $request)
    {
        $this->authorizeAllocationWrite($request);

        $data = $this->validatedPayload($request);

        ClientRespiteAllocation::query()->updateOrCreate(
            [
                'client_id' => $data['client_id'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
            ],
            [
                'organization_id' => $request->user()?->organization_id,
                'nights_allocated' => $data['nights_allocated'],
                'funding_source' => $data['funding_source'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );

        return back()->with('success', 'Respite allocation saved.');
    }

    public function update(Request $request, ClientRespiteAllocation $allocation)
    {
        $this->authorizeAllocationWrite($request);

        $allocation->update($this->validatedPayload($request));

        return back()->with('success', 'Respite allocation updated.');
    }

    public function destroy(Request $request, ClientRespiteAllocation $allocation)
    {
        $this->authorizeAllocationWrite($request);

        $allocation->delete();

        return back()->with('success', 'Respite allocation removed.');
    }

    private function authorizeAllocationWrite(Request $request): void
    {
        abort_unless($request->user()?->canDo('respite.resources.manage'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'nights_allocated' => ['required', 'integer', 'min:0', 'max:366'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
