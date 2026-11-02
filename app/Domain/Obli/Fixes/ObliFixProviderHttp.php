<?php

namespace App\Domain\Obli\Fixes;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

/**
 * Anthropic Messages API for Obli's fixes (the same API and key Obli chat uses, through Guzzle as
 * ObliProviderHttp does). Planning asks for strict JSON with no tools; a working turn sends the
 * computer-use toolset (computer_toolset_20260801, no beta header) plus Obli's own tools, with
 * one tool call at most. Errors never carry the response body, prompt, picture or key.
 */
final class ObliFixProviderHttp implements ObliFixProvider
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    private readonly ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client;
    }

    public function plan(#[\SensitiveParameter] array $configuration, string $system, #[\SensitiveParameter] string $message): array
    {
        $json = $this->send($configuration, [
            'model' => $configuration['model'],
            'max_tokens' => (int) $configuration['max_output_tokens'],
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => ObliFixInstructions::planSchema()]],
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => $message]]]],
        ]);
        $text = '';
        foreach ($json['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        try {
            $plan = json_decode(trim($text), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('The AI provider response could not be confirmed.');
        }
        if (! is_array($plan) || ($json['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('The AI provider response could not be confirmed.');
        }
        [$input, $output] = $this->usage($json);

        return ['json' => $plan, 'input_tokens' => $input, 'output_tokens' => $output];
    }

    public function act(#[\SensitiveParameter] array $configuration, string $system, #[\SensitiveParameter] string $message, #[\SensitiveParameter] array $picture): array
    {
        $json = $this->send($configuration, [
            'model' => $configuration['model'],
            'max_tokens' => (int) $configuration['max_output_tokens'],
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'tools' => ObliFixInstructions::tools(),
            'tool_choice' => ['type' => 'auto', 'disable_parallel_tool_use' => true],
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $message],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $picture['jpeg_base64']]],
            ]]],
        ]);
        $text = '';
        $tool = null;
        foreach ($json['content'] ?? [] as $part) {
            $type = $part['type'] ?? '';
            if ($type === 'text' && is_string($part['text'] ?? null)) {
                $text .= ($text === '' ? '' : "\n").$part['text'];
            }
            if ($type === 'tool_use' && $tool === null && is_string($part['name'] ?? null) && is_array($part['input'] ?? null)) {
                $tool = ['name' => $part['name'], 'toolset' => is_string($part['toolset_name'] ?? null) ? $part['toolset_name'] : null, 'input' => $part['input']];
            }
        }
        [$input, $output] = $this->usage($json);
        $stop = is_string($json['stop_reason'] ?? null) ? $json['stop_reason'] : 'unknown';
        if (strlen($text) > 8000 || ! mb_check_encoding($text, 'UTF-8')) {
            $text = '';
        }

        return ['text' => trim($text), 'tool' => $tool, 'stop_reason' => $stop, 'input_tokens' => $input, 'output_tokens' => $output];
    }

    /** @return array<string, mixed> */
    private function send(#[\SensitiveParameter] array $configuration, #[\SensitiveParameter] array $body): array
    {
        $started = hrtime(true);
        try {
            $response = $this->http->request('POST', self::URL, [
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'x-api-key' => $configuration['api_key'], 'anthropic-version' => '2023-06-01'],
                'json' => $body, 'http_errors' => false, 'allow_redirects' => false,
                'connect_timeout' => 5, 'timeout' => 90, 'read_timeout' => 90, 'stream' => true,
            ]);
            if ($response->getStatusCode() !== 200 || ! str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'application/json')) {
                $response->getBody()->close();
                throw new RuntimeException;
            }
            $stream = $response->getBody();
            $bytes = '';
            try {
                while (! $stream->eof()) {
                    if ((hrtime(true) - $started) > 90_000_000_000) {
                        throw new RuntimeException;
                    }
                    $bytes .= $stream->read(8192);
                    if (strlen($bytes) > 524288) {
                        throw new RuntimeException;
                    }
                }
            } finally {
                $stream->close();
            }
            $json = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($json)) {
                throw new RuntimeException;
            }

            return $json;
        } catch (Throwable) {
            // Never attach a provider exception, response body, prompt, picture or credentials.
            throw new RuntimeException('The AI provider response could not be confirmed.');
        }
    }

    /** @return array{0: int, 1: int} */
    private function usage(array $json): array
    {
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        if (! is_int($input) || ! is_int($output) || $input < 0 || $output < 0) {
            throw new RuntimeException('The AI provider response could not be confirmed.');
        }
        foreach (['cache_creation_input_tokens', 'cache_read_input_tokens'] as $field) {
            if (isset($usage[$field])) {
                if (! is_int($usage[$field]) || $usage[$field] < 0) {
                    throw new RuntimeException('The AI provider response could not be confirmed.');
                }
                $input += $usage[$field];
            }
        }
        if ($input > 2_000_000 || $output > 1_000_000) {
            throw new RuntimeException('The AI provider response could not be confirmed.');
        }

        return [$input, $output];
    }
}
