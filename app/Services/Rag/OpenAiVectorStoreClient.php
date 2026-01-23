<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;

class OpenAiVectorStoreClient
{
    public function isEnabled(): bool
    {
        return (bool) config('llm.openai.api_key');
    }

    public function createVectorStore(string $name): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $resp = Http::baseUrl('https://api.openai.com')
            ->withToken(config('llm.openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post('/v1/vector_stores', [
                'name' => $name,
            ]);

        if (!$resp->ok()) {
            return null;
        }

        return $resp->json('id');
    }

    public function uploadFile(string $absolutePath, string $filename): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $resp = Http::baseUrl('https://api.openai.com')
            ->withToken(config('llm.openai.api_key'))
            ->acceptJson()
            ->timeout(120)
            ->attach('file', file_get_contents($absolutePath), $filename)
            ->post('/v1/files', [
                'purpose' => 'assistants',
            ]);

        if (!$resp->ok()) {
            return null;
        }

        return $resp->json('id');
    }

    public function attachFileToVectorStore(string $vectorStoreId, string $fileId): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $resp = Http::baseUrl('https://api.openai.com')
            ->withToken(config('llm.openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post("/v1/vector_stores/{$vectorStoreId}/files", [
                'file_id' => $fileId,
            ]);

        return $resp->ok();
    }

    /**
     * Ask a question grounded in the given vector store.
     * Returns: ['text' => string|null, 'raw' => array]
     */
    public function askWithFileSearch(string $model, string $question, array $vectorStoreIds): array
    {
        if (!$this->isEnabled()) {
            return [
                'text' => null,
                'sources' => [],
                'raw' => [],
                'error' => 'LLM is not configured (missing OPENAI_API_KEY).',
            ];
        }

        $payload = [
            'model' => $model,
            'input' => $question,
            'tools' => [
                [
                    'type' => 'file_search',
                    'vector_store_ids' => array_values($vectorStoreIds),
                ],
            ],
            'include' => ['file_search_call.results'],
            'store' => false,
        ];

        $resp = Http::baseUrl('https://api.openai.com')
            ->withToken(config('llm.openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(90)
            ->post('/v1/responses', $payload);

        if (!$resp->ok()) {
            // Bubble up details so the UI can show a helpful message (e.g., bad model name).
            $body = $resp->json();
            if (!is_array($body)) {
                $body = ['body' => $resp->body()];
            }

            return [
                'text' => null,
                'sources' => [],
                'raw' => $body,
                'error' => 'OpenAI /v1/responses failed (HTTP ' . $resp->status() . ').',
            ];
        }

        $json = (array) $resp->json();
        return [
            'text' => $this->extractOutputText($json),
            'sources' => $this->extractFileSearchSources($json),
            'raw' => $json,
            'error' => null,
        ];
    }

    private function extractFileSearchSources(array $json): array
    {
        $sources = [];
        $output = $json['output'] ?? null;
        if (!is_array($output)) {
            return $sources;
        }

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) !== 'file_search_call') {
                continue;
            }
            $results = $item['results'] ?? null;
            if (!is_array($results)) {
                continue;
            }
            foreach ($results as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $text = $r['text'] ?? null;
                if (!is_string($text) || trim($text) === '') {
                    continue;
                }
                $sources[] = [
                    'file_id' => $r['file_id'] ?? null,
                    'filename' => $r['filename'] ?? null,
                    'score' => $r['score'] ?? null,
                    'text' => $text,
                ];
            }
        }

        return array_slice($sources, 0, 5);
    }

    private function extractOutputText(array $json): ?string
    {
        $output = $json['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }

        $chunks = [];
        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $c) {
                if (is_array($c) && ($c['type'] ?? null) === 'output_text') {
                    $chunks[] = (string) ($c['text'] ?? '');
                }
            }
        }

        $text = trim(implode("\n", array_filter($chunks)));
        return $text !== '' ? $text : null;
    }
}
