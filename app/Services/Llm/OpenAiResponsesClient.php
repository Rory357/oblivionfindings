<?php

namespace App\Services\Llm;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenAiResponsesClient implements LlmClient
{
    public function isEnabled(): bool
    {
        return (bool) config('llm.openai.api_key');
    }

    public function modelName(): string
    {
        return (string) (config('llm.openai.model') ?: 'gpt-5');
    }

    public function summarizeTimeline(
        string $scopeType,
        int $scopeId,
        Carbon $periodStart,
        Carbon $periodEnd,
        Collection $events,
    ): ?string {
        if (!$this->isEnabled()) {
            return null;
        }

        $eventLines = $events->map(function ($e) {
            $when = $e->occurred_at ? $e->occurred_at->toDateTimeString() : '';
            $client = $e->client_id ? "client_id={$e->client_id}" : '';
            $site = $e->site_id ? "site_id={$e->site_id}" : '';
            $meta = is_array($e->meta) ? json_encode($e->meta) : '';
            $body = is_string($e->body) ? Str::limit(trim($e->body), 220) : '';

            $parts = array_filter([
                $when,
                "type={$e->type}",
                $client,
                $site,
                "subject=" . trim((string) $e->subject),
                $body ? "body={$body}" : null,
                $meta ? "meta={$meta}" : null,
            ]);

            return '- ' . implode(' | ', $parts);
        })->values()->all();

        $system = "You are a helpful operations assistant for a supported living provider. "
            . "Write a concise, factual summary suitable for a staff handover. "
            . "Avoid sensitive medical speculation. Use bullet points.";

        $user = "Summarize timeline activity for {$scopeType} #{$scopeId} "
            . "from {$periodStart->toDateString()} to {$periodEnd->toDateString()}.\n\n"
            . "Timeline events:\n" . implode("\n", $eventLines) . "\n\n"
            . "Output format:\n"
            . "- Highlights (3-6 bullets)\n"
            . "- Risks / follow-ups (0-5 bullets)\n"
            . "- Next actions (0-5 bullets)";

        $payload = [
            'model' => $this->modelName(),
            'input' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'store' => false,
        ];

        $resp = Http::baseUrl('https://api.openai.com')
            ->withToken(config('llm.openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post('/v1/responses', $payload);

        if (!$resp->ok()) {
            return null;
        }

        $json = $resp->json();
        $text = $this->extractOutputText($json);

        return $text ? trim($text) : null;
    }

    private function extractOutputText(array $json): ?string
    {
        // Responses API returns an array under `output` containing items.
        // We look for message items and concatenate any output_text segments.
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
