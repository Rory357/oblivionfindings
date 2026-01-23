<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Rag\ClientRagIndexer;
use App\Services\Rag\OpenAiVectorStoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RagController extends Controller
{
    public function clients(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        // Portal users (client / next-of-kin) must use the per-client query UI
        // on the client profile page, not the global query modal.
        abort_if($user->hasRole('client', 'next_of_kin'), 403);

        // UI can always show the query bar, but the dropdown should only show
        // clients this user can actually view.
        $clients = Client::query();

        if ($user->hasRole('support_worker')) {
            $clients->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id));
        } elseif ($user->hasRole('client', 'next_of_kin')) {
            $clients->whereIn('id', $user->portalClients()->pluck('clients.id'));
        } else {
            // admin/manager: all clients (still guarded by ClientPolicy on ask)
        }

        $list = $clients
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => trim($c->first_name . ' ' . $c->last_name),
            ])
            ->values();

        return response()->json([
            'clients' => $list,
        ]);
    }

    public function ask(Request $request, OpenAiVectorStoreClient $openai, ClientRagIndexer $indexer)
    {
        $user = $request->user();
        abort_unless($user, 401);

        // Portal users: enforce per-client query UI only.
        abort_if($user->hasRole('client', 'next_of_kin'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'question' => ['required', 'string', 'max:2000'],
        ]);

        // Role/permission gating for asking
        if ($user->hasRole('admin', 'provider_manager')) {
            abort_unless($user->canDo('rag.ask.any'), 403);
        } elseif ($user->hasRole('support_worker')) {
            abort_unless($user->canDo('rag.ask.assigned'), 403);
        } elseif ($user->hasRole('client', 'next_of_kin')) {
            abort_unless($user->canDo('rag.ask.self'), 403);
        } else {
            abort_unless(
                $user->canDo('rag.ask.any') || $user->canDo('rag.ask.assigned') || $user->canDo('rag.ask.self'),
                403
            );
        }

        $client = Client::query()->findOrFail($data['client_id']);
        $this->authorize('view', $client);

        if (!$openai->isEnabled()) {
            return response()->json([
                'error' => 'LLM is not configured. Set OPENAI_API_KEY.',
            ], 422);
        }

        // Ensure per-client vector store exists
        if (!$client->openai_vector_store_id) {
            $vsId = $openai->createVectorStore('client_' . $client->id);
            if (!$vsId) {
                return response()->json(['error' => 'Unable to create vector store.'], 422);
            }
            $client->forceFill(['openai_vector_store_id' => $vsId])->save();
        }

        // Build a fresh knowledge snapshot for this client (rolling)
        $md = $indexer->buildMarkdown($client, 120);
        $path = 'rag/client_' . $client->id . '_latest.md';
        Storage::disk('local')->put($path, $md);
        $abs = Storage::disk('local')->path($path);

        // Upload & attach snapshot (best-effort)
        $fileId = $openai->uploadFile($abs, basename($path));
        if ($fileId) {
            $openai->attachFileToVectorStore($client->openai_vector_store_id, $fileId);
        }

        $systemHint = "You are an assistant for supported living operations. "
            . "Answer using only the retrieved client context. "
            . "If the answer is not in the context, say you don't know. "
            . "Be concise, factual, and avoid speculation.";

        $question = $systemHint . "\n\n" . $data['question'];

        $model = (string) (config('llm.openai.model') ?: 'gpt-5');
        $result = $openai->askWithFileSearch($model, $question, [$client->openai_vector_store_id]);

        return response()->json([
            'text' => $result['text'] ?? null,
            'sources' => $result['sources'] ?? [],
        ]);
    }
}
