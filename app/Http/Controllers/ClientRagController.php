<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Rag\ClientRagIndexer;
use App\Services\Rag\OpenAiVectorStoreClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientRagController extends Controller
{
    public function ask(Request $request, Client $client, OpenAiVectorStoreClient $openai, ClientRagIndexer $indexer)
    {
        $this->authorize('view', $client);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        if (!$openai->isEnabled()) {
            return back()->withErrors(['question' => 'LLM is not configured. Set OPENAI_API_KEY.']);
        }

        // Ensure per-client vector store exists
        if (!$client->openai_vector_store_id) {
            $vsId = $openai->createVectorStore('client_' . $client->id);
            if (!$vsId) {
                return back()->withErrors(['question' => 'Unable to create vector store.']);
            }
            $client->forceFill(['openai_vector_store_id' => $vsId])->save();
        }

        // Build a fresh knowledge snapshot for this client (rolling)
        $md = $indexer->buildMarkdown($client, 120);
        $path = 'rag/client_' . $client->id . '_latest.md';
        Storage::disk('local')->put($path, $md);
        $abs = Storage::disk('local')->path($path);

        // Upload & attach
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

        if (($result['text'] ?? null) === null && ($result['error'] ?? null)) {
            // Show a clear, actionable error in the UI while still allowing the page to reload.
            $detail = null;
            if (is_array($result['raw'] ?? null)) {
                $detail = data_get($result, 'raw.error.message');
            }
            return back()->withErrors([
                'question' => trim(($result['error'] ?? 'LLM request failed.') . ($detail ? ' ' . $detail : '')),
            ]);
        }

        return back()->with('rag_answer', [
            'text' => $result['text'] ?? null,
            'sources' => $result['sources'] ?? [],
        ]);
    }
}
