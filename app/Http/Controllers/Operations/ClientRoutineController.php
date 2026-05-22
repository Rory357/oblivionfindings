<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientRoutine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientRoutineController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        return ClientRoutine::query()
            ->where('client_id', $client->id)
            ->with('updater:id,name')
            ->orderBy('display_order')
            ->get();
    }

    public function upsertBlock(Request $request, Client $client, string $block)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('clients.update'), 403);
        abort_unless(array_key_exists($block, ClientRoutine::BLOCKS), 404);

        $data = $request->validate([
            'body' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        ClientRoutine::query()->updateOrCreate(
            [
                'client_id' => $client->id,
                'time_block' => $block,
            ],
            [
                'organization_id' => $request->user()?->organization_id ?? $client->organization_id,
                'body' => $data['body'] ?? null,
                'display_order' => $data['display_order'] ?? ClientRoutine::BLOCKS[$block],
                'updated_by' => $request->user()?->id,
            ],
        );

        return back()->with('success', 'Routine updated.');
    }

    public function reorder(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('clients.update'), 403);

        $data = $request->validate([
            'blocks' => ['required', 'array'],
            'blocks.*.time_block' => ['required', 'string', Rule::in(array_keys(ClientRoutine::BLOCKS))],
            'blocks.*.display_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        foreach ($data['blocks'] as $block) {
            ClientRoutine::query()
                ->where('client_id', $client->id)
                ->where('time_block', $block['time_block'])
                ->update(['display_order' => $block['display_order'], 'updated_by' => $request->user()?->id]);
        }

        return back()->with('success', 'Routine order updated.');
    }
}
