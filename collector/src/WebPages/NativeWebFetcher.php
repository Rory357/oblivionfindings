<?php

namespace Oblivion\Collector\WebPages;

/**
 * cURL behind WebFetcher: no redirects, no proxy, bounded size and time. https accepts only the
 * device's trusted public key (the pin is the check, as for cameras).
 */
final class NativeWebFetcher implements WebFetcher
{
    public function fetch(string $url, string $method, array $headers, string $body, ?string $pin, int $maxBytes): array
    {
        $https = str_starts_with($url, 'https://');
        $handle = curl_init($url);
        if ($handle === false || ($https && $pin === null)) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'truncated' => false];
        }
        $received = '';
        $truncated = false;
        $responseHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => $https ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name.': '.$value, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $at = strpos($line, ':');
                if ($at !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $at)))][] = trim(substr($line, $at + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$received, &$truncated, $maxBytes): int {
                if (strlen($received) + strlen($chunk) > $maxBytes) {
                    $truncated = true;

                    return 0;
                }
                $received .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($https) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
            $options[CURLOPT_PINNEDPUBLICKEY] = $pin;
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($truncated) {
            return ['status' => $status, 'headers' => [], 'body' => '', 'truncated' => true];
        }

        return $ok === false
            ? ['status' => 0, 'headers' => [], 'body' => '', 'truncated' => false]
            : ['status' => $status, 'headers' => $responseHeaders, 'body' => $received, 'truncated' => false];
    }
}
