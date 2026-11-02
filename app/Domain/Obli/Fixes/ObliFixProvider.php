<?php

namespace App\Domain\Obli\Fixes;

use Illuminate\Container\Attributes\Bind;

/**
 * The AI behind Obli's fixes. The server holds it; the tray never talks to it. Tests bind a fake.
 *
 * Every call is a fresh, single-message request: the server keeps the session's state itself and
 * describes it in the message, so no earlier model output (or screen picture) is ever replayed.
 */
#[Bind(ObliFixProviderHttp::class)]
interface ObliFixProvider
{
    /**
     * Ask for a plan only (no tools): strict JSON matching ObliFixInstructions::planSchema().
     *
     * @param  array{api_key: string, model: string, max_output_tokens: int}  $configuration
     * @return array{json: array<string, mixed>, input_tokens: int, output_tokens: int}
     */
    public function plan(array $configuration, string $system, string $message): array;

    /**
     * One working turn: the session so far and the current screen picture, with the computer-use
     * toolset and Obli's own tools. Returns at most one tool call.
     *
     * @param  array{api_key: string, model: string, max_output_tokens: int}  $configuration
     * @param  array{jpeg_base64: string, width: int, height: int}  $picture
     * @return array{text: string, tool: array{name: string, toolset: ?string, input: array<string, mixed>}|null, stop_reason: string, input_tokens: int, output_tokens: int}
     */
    public function act(array $configuration, string $system, string $message, array $picture): array;
}
