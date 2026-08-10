<?php

namespace App\Domain\Monitoring\Transports;

use App\Domain\Monitoring\Contracts\DnsTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\DnsTransportResult;
use RuntimeException;

final class NativeDnsTransport implements DnsTransport
{
    private const TYPE_CODES = ['A' => 1, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'AAAA' => 28];

    public function query(AuthorizedProbeTarget $target, string $name, string $type): DnsTransportResult
    {
        $typeCode = self::TYPE_CODES[$type] ?? throw new RuntimeException('Unsupported DNS record type.');
        $queryId = random_int(1, 65535);
        $packet = pack('nnnnnn', $queryId, 0x0100, 1, 0, 0, 0).$this->encodeName($name).pack('nn', $typeCode, 1);

        foreach ($target->addresses as $address) {
            $endpoint = sprintf('udp://%s:%d', str_contains($address, ':') ? "[{$address}]" : $address, $target->port);
            $socket = @stream_socket_client(
                $endpoint,
                $errorCode,
                $errorMessage,
                $target->connectTimeoutSeconds,
                STREAM_CLIENT_CONNECT,
            );
            if (! is_resource($socket)) {
                continue;
            }

            stream_set_timeout($socket, $target->responseTimeoutSeconds);
            $started = hrtime(true);
            $written = @fwrite($socket, $packet);
            $response = $written === strlen($packet)
                ? @fread($socket, min(4096, $target->maxResponseBytes + 1))
                : false;
            $metadata = stream_get_meta_data($socket);
            fclose($socket);
            $latency = max(0, (int) round((hrtime(true) - $started) / 1_000_000));

            if (($metadata['timed_out'] ?? false) === true) {
                continue;
            }
            if (! is_string($response) || strlen($response) < 12 || strlen($response) > $target->maxResponseBytes) {
                continue;
            }

            return $this->decode($response, $queryId, $typeCode, $latency);
        }

        return new DnsTransportResult(false, [], null, 'timeout');
    }

    private function encodeName(string $name): string
    {
        $encoded = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            $length = strlen($label);
            if ($length < 1 || $length > 63) {
                throw new RuntimeException('Invalid DNS label.');
            }
            $encoded .= chr($length).$label;
        }

        return $encoded."\0";
    }

    private function decode(string $packet, int $queryId, int $requestedType, int $latency): DnsTransportResult
    {
        $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($packet, 0, 12));
        if (! is_array($header) || $header['id'] !== $queryId || ($header['flags'] & 0x8000) === 0) {
            return new DnsTransportResult(false, [], $latency, 'malformed_response');
        }
        $rcode = $header['flags'] & 0x000F;
        if ($rcode !== 0) {
            return new DnsTransportResult(false, [], $latency, $rcode === 3 ? 'nxdomain' : 'server_failure');
        }

        $offset = 12;
        for ($question = 0; $question < $header['questions']; $question++) {
            $offset = $this->skipName($packet, $offset) + 4;
            if ($offset > strlen($packet)) {
                return new DnsTransportResult(false, [], $latency, 'malformed_response');
            }
        }

        $answers = [];
        for ($answer = 0; $answer < min(64, $header['answers']); $answer++) {
            $offset = $this->skipName($packet, $offset);
            if ($offset + 10 > strlen($packet)) {
                return new DnsTransportResult(false, [], $latency, 'malformed_response');
            }
            $record = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            if (! is_array($record) || $offset + $record['length'] > strlen($packet)) {
                return new DnsTransportResult(false, [], $latency, 'malformed_response');
            }
            $rdataOffset = $offset;
            $rdata = substr($packet, $offset, $record['length']);
            $offset += $record['length'];
            if ($record['class'] !== 1 || $record['type'] !== $requestedType) {
                continue;
            }

            $decoded = $this->decodeRdata($packet, $record['type'], $rdata, $rdataOffset);
            if ($decoded !== null && strlen($decoded) <= 1024) {
                $answers[] = $decoded;
            }
        }

        return new DnsTransportResult($answers !== [], array_values(array_unique($answers)), $latency, $answers === [] ? 'no_answer' : 'answer');
    }

    private function skipName(string $packet, int $offset): int
    {
        $length = strlen($packet);
        $labels = 0;
        while ($offset < $length && $labels++ < 128) {
            $size = ord($packet[$offset]);
            if ($size === 0) {
                return $offset + 1;
            }
            if (($size & 0xC0) === 0xC0) {
                return $offset + 2;
            }
            if (($size & 0xC0) !== 0 || $size > 63 || $offset + 1 + $size > $length) {
                throw new RuntimeException('Malformed DNS name.');
            }
            $offset += 1 + $size;
        }

        throw new RuntimeException('Malformed DNS name.');
    }

    private function decodeName(string $packet, int $offset): ?string
    {
        $labels = [];
        $visited = [];
        for ($count = 0; $count < 128; $count++) {
            if ($offset >= strlen($packet) || isset($visited[$offset])) {
                return null;
            }
            $visited[$offset] = true;
            $size = ord($packet[$offset]);
            if ($size === 0) {
                return implode('.', $labels);
            }
            if (($size & 0xC0) === 0xC0) {
                if ($offset + 1 >= strlen($packet)) {
                    return null;
                }
                $offset = (($size & 0x3F) << 8) | ord($packet[$offset + 1]);

                continue;
            }
            if (($size & 0xC0) !== 0 || $size > 63 || $offset + 1 + $size > strlen($packet)) {
                return null;
            }
            $labels[] = strtolower(substr($packet, $offset + 1, $size));
            $offset += 1 + $size;
        }

        return null;
    }

    private function decodeRdata(string $packet, int $type, string $rdata, int $offset): ?string
    {
        return match ($type) {
            1 => strlen($rdata) === 4 ? inet_ntop($rdata) ?: null : null,
            28 => strlen($rdata) === 16 ? inet_ntop($rdata) ?: null : null,
            5 => $this->decodeName($packet, $offset),
            15 => strlen($rdata) >= 3
                ? unpack('npreference', substr($rdata, 0, 2))['preference'].' '.$this->decodeName($packet, $offset + 2)
                : null,
            16 => $this->decodeTxt($rdata),
            default => null,
        };
    }

    private function decodeTxt(string $rdata): ?string
    {
        $offset = 0;
        $parts = [];
        while ($offset < strlen($rdata)) {
            $length = ord($rdata[$offset++]);
            if ($offset + $length > strlen($rdata)) {
                return null;
            }
            $parts[] = substr($rdata, $offset, $length);
            $offset += $length;
        }

        return implode('', $parts);
    }
}
