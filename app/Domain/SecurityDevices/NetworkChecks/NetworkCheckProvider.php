<?php

namespace App\Domain\SecurityDevices\NetworkChecks;

use Illuminate\Container\Attributes\Bind;

/**
 * The AI behind "Obli checks <Site>'s network". It stays on the server: hubs and browsers never talk
 * to it. Tests bind a fake.
 *
 * Every call is one fresh message with one tool the model must use (tool use, not computer use):
 * the server keeps the session's state itself and describes it in the message, so earlier model
 * output is never replayed as instructions.
 */
#[Bind(NetworkCheckProviderHttp::class)]
interface NetworkCheckProvider
{
    /**
     * @param  array{provider: string, api_key: string, model: string, max_output_tokens: int}  $configuration
     * @param  array{name: string, description: string, schema: array<string, mixed>}  $tool
     * @return array{input: array<string, mixed>, input_tokens: int, output_tokens: int}
     */
    public function call(array $configuration, string $system, string $message, array $tool): array;
}
