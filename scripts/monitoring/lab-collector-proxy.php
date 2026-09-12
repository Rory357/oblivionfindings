#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lab-only loopback mTLS reverse proxy for A03 collector enrolment against Herd.
 *
 * Binds 127.0.0.1 only. Forwards collector API routes to the Herd origin and
 * replaces X-Oblivion-Verified-Client-Certificate with the captured client
 * certificate. This is not a production proxy and is not mTLS release evidence.
 */

$root = dirname(__DIR__, 2);
$state = getenv('OBLIVION_LAB_STATE_DIR') ?: $root.DIRECTORY_SEPARATOR.'collector'.DIRECTORY_SEPARATOR.'lab-state';
$listen = getenv('OBLIVION_LAB_LISTEN') ?: '127.0.0.1:8443';
$origin = rtrim((string) (getenv('OBLIVION_LAB_CENTRAL_ORIGIN') ?: 'https://oblivionfindings.test'), '/');
$ca = $state.DIRECTORY_SEPARATOR.'ca.crt.pem';
$tlsCert = $state.DIRECTORY_SEPARATOR.'proxy.crt.pem';
$tlsKey = $state.DIRECTORY_SEPARATOR.'proxy.key.pem';
if (! is_file($ca) || ! is_file($tlsCert) || ! is_file($tlsKey)) {
    fwrite(STDERR, "lab-collector-proxy: run scripts/monitoring/lab-collector-bootstrap.php first.\n");
    exit(1);
}
if (! str_starts_with($origin, 'https://')) {
    fwrite(STDERR, "lab-collector-proxy: central origin must be HTTPS.\n");
    exit(1);
}

$ssl = [
    'local_cert' => $tlsCert,
    'local_pk' => $tlsKey,
    'cafile' => $ca,
    'verify_peer' => false,
    'verify_peer_name' => false,
    'allow_self_signed' => true,
    'capture_peer_cert' => true,
    'disable_compression' => true,
];
$server = stream_socket_server('tcp://'.$listen, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if ($server === false) {
    fwrite(STDERR, "lab-collector-proxy: bind failed ({$errno}) {$error}\n");
    exit(1);
}
fwrite(STDOUT, "lab-collector-proxy: listening on https://{$listen} -> {$origin}\n");
fwrite(STDOUT, "lab-collector-proxy: lab-only; verify-transport still does not prove production proxy mTLS\n");

while ($client = @stream_socket_accept($server, 60)) {
    foreach ($ssl as $name => $value) {
        stream_context_set_option($client, 'ssl', $name, $value);
    }
    try {
        if (! stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER)) {
            throw new RuntimeException('TLS handshake failed.');
        }
        handleClient($client, $origin);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'lab-collector-proxy: '.substr($exception->getMessage(), 0, 180)."\n");
        @fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/plain\r\nContent-Length: 11\r\nConnection: close\r\n\r\nbad gateway");
    }
    fclose($client);
}

function handleClient($client, string $origin): void
{
    stream_set_timeout($client, 15);
    $raw = readHttpMessage($client);
    [$method, $target, $headers, $body] = parseHttp($raw);
    $path = parse_url($target, PHP_URL_PATH) ?: $target;
    $allowed = [
        '/api/monitoring/collectors/enrol',
        '/api/monitoring/collectors/configuration',
        '/api/monitoring/collectors/observations',
        '/api/monitoring/collectors/heartbeat',
    ];
    if ($method !== 'POST' || ! in_array($path, $allowed, true)) {
        writeResponse($client, 404, '{"message":"Lab collector proxy rejects this path."}');
        return;
    }

    $options = stream_context_get_params($client)['options']['ssl']
        ?? stream_context_get_options($client)['ssl']
        ?? [];
    $peer = $options['peer_certificate'] ?? null;
    $clientPem = null;
    if (is_resource($peer) || $peer instanceof OpenSSLCertificate) {
        openssl_x509_export($peer, $clientPem);
    }
    if ($path !== '/api/monitoring/collectors/enrol' && ! is_string($clientPem)) {
        writeResponse($client, 403, '{"message":"Collector client certificate is required."}');
        return;
    }

    $forwardHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Oblivion-Client-Certificate-Fingerprint:',
        'X-Oblivion-Verified-Client-Certificate: '.(is_string($clientPem) ? rawurlencode($clientPem) : ''),
        'X-Forwarded-Proto: https',
        'X-Forwarded-For: 127.0.0.1',
    ];
    foreach (['authorization', 'x-oblivion-collector-timestamp', 'x-oblivion-collector-nonce', 'x-oblivion-collector-signature'] as $name) {
        if (isset($headers[$name]) && is_string($headers[$name]) && $headers[$name] !== '') {
            $forwardHeaders[] = headerName($name).': '.$headers[$name];
        }
    }

    $handle = curl_init($origin.$path);
    if ($handle === false) {
        writeResponse($client, 502, '{"message":"Lab collector proxy could not reach central."}');
        return;
    }
    $responseHeaders = '';
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $forwardHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
            $responseHeaders .= $header;
            return strlen($header);
        },
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $responseBody = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (! is_string($responseBody) || $status < 100) {
        writeResponse($client, 502, '{"message":"Lab collector proxy could not reach central."}');
        return;
    }
    $contentType = 'application/json';
    foreach (explode("\r\n", $responseHeaders) as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            $contentType = trim(substr($line, strlen('Content-Type:')));
        }
    }
    fwrite($client, 'HTTP/1.1 '.$status." \r\n");
    fwrite($client, 'Content-Type: '.$contentType."\r\n");
    fwrite($client, 'Content-Length: '.strlen($responseBody)."\r\n");
    fwrite($client, "Cache-Control: no-store\r\nConnection: close\r\n\r\n");
    fwrite($client, $responseBody);
}

function readHttpMessage($client): string
{
    $raw = '';
    while (! str_contains($raw, "\r\n\r\n") && strlen($raw) < 65_536) {
        $chunk = fread($client, 8192);
        if (! is_string($chunk) || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    $headerEnd = strpos($raw, "\r\n\r\n");
    if ($headerEnd === false) {
        throw new RuntimeException('Lab collector proxy request headers are incomplete.');
    }
    preg_match('/Content-Length:\s*(\d+)/i', substr($raw, 0, $headerEnd), $match);
    $expected = isset($match[1]) ? (int) $match[1] : 0;
    if ($expected > 2_097_152) {
        throw new RuntimeException('Lab collector proxy request is too large.');
    }
    $body = substr($raw, $headerEnd + 4);
    while (strlen($body) < $expected) {
        $chunk = fread($client, $expected - strlen($body));
        if (! is_string($chunk) || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    return substr($raw, 0, $headerEnd + 4).$body;
}

/** @return array{0: string, 1: string, 2: array<string, string>, 3: string} */
function parseHttp(string $raw): array
{
    [$headerBlock, $body] = explode("\r\n\r\n", $raw, 2);
    $lines = explode("\r\n", $headerBlock);
    $requestLine = array_shift($lines) ?? '';
    if (preg_match('/\A(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(\S+)\s+HTTP\/1\.[01]\z/', $requestLine, $match) !== 1) {
        throw new RuntimeException('Lab collector proxy request line is invalid.');
    }
    $headers = [];
    foreach ($lines as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($name))] = trim($value);
    }

    return [$match[1], $match[2], $headers, $body];
}

function headerName(string $name): string
{
    return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
}

function writeResponse($client, int $status, string $body): void
{
    fwrite($client, 'HTTP/1.1 '.$status." \r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
}
