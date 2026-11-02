<?php

namespace App\Domain\SecurityDevices\NetworkChecks;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

/**
 * Obli's network checks over the AI provider set in Settings › Obli (Anthropic Messages or OpenAI
 * Responses), through Guzzle as ObliProviderHttp does. One forced tool per call. Errors never carry
 * the response body, the prompt or the key.
 */
final class NetworkCheckProviderHttp implements NetworkCheckProvider
{
    private readonly ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client;
    }

    public function call(#[\SensitiveParameter] array $configuration, string $system, #[\SensitiveParameter] string $message, array $tool): array
    {
        $anthropic = ($configuration['provider'] ?? null) === 'anthropic';
        if (! $anthropic && ($configuration['provider'] ?? null) !== 'openai') {
            throw new RuntimeException('Unsupported AI provider.');
        }
        $body = $anthropic
            ? [
                'model' => $configuration['model'],
                'max_tokens' => (int) $configuration['max_output_tokens'],
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $message]],
                'tools' => [['name' => $tool['name'], 'description' => $tool['description'], 'input_schema' => $tool['schema']]],
                'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
                'stream' => false,
            ]
            : [
                'model' => $configuration['model'],
                'instructions' => $system,
                'input' => [['role' => 'user', 'content' => $message]],
                'tools' => [['type' => 'function', 'name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['schema'], 'strict' => false]],
                'tool_choice' => ['type' => 'function', 'name' => $tool['name']],
                'max_output_tokens' => (int) $configuration['max_output_tokens'],
                'store' => false,
                'stream' => false,
            ];
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($anthropic) {
            $headers['x-api-key'] = $configuration['api_key'];
            $headers['anthropic-version'] = '2023-06-01';
        } else {
            $headers['Authorization'] = 'Bearer '.$configuration['api_key'];
        }
        $started = hrtime(true);
        try {
            $response = $this->http->request('POST', $anthropic ? 'https://api.anthropic.com/v1/messages' : 'https://api.openai.com/v1/responses', [
                'headers' => $headers, 'json' => $body, 'http_errors' => false, 'allow_redirects' => false,
                'connect_timeout' => 5, 'timeout' => 60, 'read_timeout' => 60, 'stream' => true,
            ]);
            if ($response->getStatusCode() !== 200 || ! str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'application/json')) {
                $response->getBody()->close();
                throw new RuntimeException;
            }
            $stream = $response->getBody();
            $bytes = '';
            try {
                while (! $stream->eof()) {
                    if ((hrtime(true) - $started) > 60_000_000_000) {
                        throw new RuntimeException;
                    }
                    $bytes .= $stream->read(8192);
                    if (strlen($bytes) > 262_144) {
                        throw new RuntimeException;
                    }
                }
            } finally {
                $stream->close();
            }
            $json = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            $input = null;
            if ($anthropic) {
                foreach (is_array($json['content'] ?? null) ? $json['content'] : [] as $part) {
                    if (($part['type'] ?? '') === 'tool_use' && ($part['name'] ?? '') === $tool['name'] && is_array($part['input'] ?? null)) {
                        $input = $part['input'];
                        break;
                    }
                }
            } else {
                foreach (is_array($json['output'] ?? null) ? $json['output'] : [] as $item) {
                    if (($item['type'] ?? '') === 'function_call' && ($item['name'] ?? '') === $tool['name'] && is_string($item['arguments'] ?? null)) {
                        $decoded = json_decode($item['arguments'], true, 16, JSON_THROW_ON_ERROR);
                        $input = is_array($decoded) ? $decoded : null;
                        break;
                    }
                }
            }
            $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
            $in = $usage['input_tokens'] ?? null;
            $out = $usage['output_tokens'] ?? null;
            if (! is_array($input) || ! is_int($in) || ! is_int($out) || $in < 0 || $out < 0) {
                throw new RuntimeException;
            }
            foreach (['cache_creation_input_tokens', 'cache_read_input_tokens'] as $field) {
                if ($anthropic && isset($usage[$field])) {
                    if (! is_int($usage[$field]) || $usage[$field] < 0) {
                        throw new RuntimeException;
                    }
                    $in += $usage[$field];
                }
            }
            if ($in > 1_000_000 || $out > 1_000_000) {
                throw new RuntimeException;
            }

            return ['input' => $input, 'input_tokens' => $in, 'output_tokens' => $out];
        } catch (Throwable) {
            // Never attach a provider exception, response body, prompt or credentials.
            throw new RuntimeException('The AI provider response could not be confirmed.');
        }
    }
}
