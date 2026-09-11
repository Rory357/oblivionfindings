<?php

// One synthetic loopback response, no application bootstrap, records or credentials.
$mode = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || ! in_array($mode, ['exact', 'declared', 'chunked', 'unknown', 'gzip', 'truncated'], true)) {
    exit(2);
}
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (! is_resource($server)) {
    exit(3);
}
echo stream_socket_get_name($server, false)."\n";
flush();
$client = stream_socket_accept($server, 10);
if (! is_resource($client)) {
    fclose($server);
    exit(4);
}
stream_set_timeout($client, 5);
for ($bytes = 0; $bytes < 16384;) {
    $line = fgets($client, 8192);
    if ($line === false) {
        break;
    }
    $bytes += strlen($line);
    if ($line === "\r\n") {
        break;
    }
}
$content = str_repeat('x', $mode === 'exact' ? 64 : 1024);
$headers = "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Type: text/plain\r\n";
if ($mode === 'declared') {
    $headers .= "Content-Length: 999999999999999999999999\r\n";
    $content = '';
} elseif ($mode === 'gzip') {
    $content = gzencode($content);
    $headers .= "Content-Encoding: gzip\r\nContent-Length: ".strlen($content)."\r\n";
} elseif ($mode === 'chunked') {
    $headers .= "Transfer-Encoding: chunked\r\n";
    $content = '400'."\r\n".$content."\r\n0\r\n\r\n";
} elseif ($mode === 'truncated') {
    $headers .= "Content-Length: 64\r\n";
    $content = '{"ok":true}';
} elseif ($mode === 'exact') {
    $headers .= "Content-Length: 64\r\n";
}
@fwrite($client, $headers."\r\n".$content);
fclose($client);
fclose($server);
