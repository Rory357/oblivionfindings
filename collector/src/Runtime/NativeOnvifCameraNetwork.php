<?php

namespace Oblivion\Collector\Runtime;

/**
 * cURL behind OnvifCameraNetwork. The certificate reading is the camera job's, so a key trusted on
 * the server is the same key here. https accepts only the pinned key (or a publicly valid
 * certificate when there is no pin); http is allowed only because the runner never sends a plain
 * password over it (WS-Security and HTTP Digest only).
 */
final class NativeOnvifCameraNetwork implements OnvifCameraNetwork
{
    public function __construct(private readonly NativeCameraHubNetwork $certificates = new NativeCameraHubNetwork) {}

    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array
    {
        return $this->certificates->inspectCertificate($address, $port, $timeoutSeconds);
    }

    public function request(string $method, string $url, array $headers, ?string $body, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array
    {
        $https = str_starts_with($url, 'https://');
        $handle = curl_init($url);
        if ($handle === false || ! in_array($method, ['GET', 'POST'], true)) {
            return ['status' => 0, 'body' => '', 'truncated' => false, 'challenge' => ''];
        }
        $received = '';
        $truncated = false;
        $challenge = '';
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => $https ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => max(1, min(10, $connectTimeoutSeconds)),
            CURLOPT_TIMEOUT => max(1, min(30, $responseTimeoutSeconds)),
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name.': '.$value, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$challenge): int {
                if (stripos($line, 'WWW-Authenticate:') === 0 && $challenge === '') {
                    $challenge = substr(trim(substr($line, 17)), 0, 1024);
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
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($https && $pin !== null) {
            // The pin is the check: cURL refuses any other public key before sending a byte.
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
            $options[CURLOPT_PINNEDPUBLICKEY] = $pin;
        } elseif ($https) {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($truncated) {
            return ['status' => $status, 'body' => '', 'truncated' => true, 'challenge' => ''];
        }

        return $ok === false
            ? ['status' => 0, 'body' => '', 'truncated' => false, 'challenge' => '']
            : ['status' => $status, 'body' => $received, 'truncated' => false, 'challenge' => $status === 401 ? $challenge : ''];
    }
}
