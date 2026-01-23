<?php

namespace App\Providers;

use App\Services\Llm\LlmClient;
use App\Services\Llm\OpenAiResponsesClient;
use Illuminate\Support\ServiceProvider;

class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LlmClient::class, function () {
            $driver = config('llm.driver', 'local');

            if ($driver === 'openai') {
                return new OpenAiResponsesClient();
            }

            // Default: no-op client (disabled)
            return new class implements LlmClient {
                public function isEnabled(): bool { return false; }
                public function modelName(): string { return 'local'; }
                public function summarizeTimeline(string $scopeType, int $scopeId, \Carbon\Carbon $periodStart, \Carbon\Carbon $periodEnd, \Illuminate\Support\Collection $events): ?string { return null; }
            };
        });
    }
}
