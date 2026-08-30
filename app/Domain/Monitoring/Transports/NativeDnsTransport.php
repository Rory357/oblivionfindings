<?php

namespace App\Domain\Monitoring\Transports;

use App\Domain\Monitoring\Contracts\DnsTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\DnsTransportResult;
use RuntimeException;

final class NativeDnsTransport implements DnsTransport
{
    private const MAX_ANSWERS = 64;

    private const MAX_NAME_STEPS = 256;

    private const TYPE_CODES = ['A' => 1, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'AAAA' => 28];

    public function query(AuthorizedProbeTarget $target, string $name, string $type): DnsTransportResult
    {
        $typeCode = self::TYPE_CODES[$type] ?? throw new RuntimeException('Unsupported DNS record type.');
        $question = $this->encodeName($name).pack('nn', $typeCode, 1);

        foreach ($target->addresses as $address) {
            $queryId = random_int(1, 65535);
            $packet = pack('nnnnnn', $queryId, 0x0100, 1, 0, 0, 0).$question;
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

            return $this->decode($response, $queryId, $name, $typeCode, $latency);
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

    private function decode(
        string $packet,
        int $queryId,
        string $requestedName,
        int $requestedType,
        int $latency,
    ): DnsTransportResult {
        if (strlen($packet) < 12) {
            return $this->malformed($latency);
        }

        $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($packet, 0, 12));
        if (
            ! is_array($header)
            || $header['id'] !== $queryId
            || ($header['flags'] & 0x8000) === 0
            || ($header['flags'] & 0x7800) !== 0
            || ($header['flags'] & 0x0200) !== 0
            || $header['questions'] !== 1
            || $header['answers'] > self::MAX_ANSWERS
        ) {
            return $this->malformed($latency);
        }

        $questionName = $this->readName($packet, 12, allowCompression: false);
        if ($questionName === null || strlen($packet) < $questionName['nextOffset'] + 4) {
            return $this->malformed($latency);
        }

        $question = unpack('ntype/nclass', substr($packet, $questionName['nextOffset'], 4));
        if (
            ! is_array($question)
            || $questionName['key'] !== $this->nameKey($requestedName)
            || $question['type'] !== $requestedType
            || $question['class'] !== 1
        ) {
            return $this->malformed($latency);
        }

        $offset = $questionName['nextOffset'] + 4;

        /** @var list<array{ownerKey: string, type: int, class: int, value: ?string, targetKey: ?string}> $records */
        $records = [];
        for ($answer = 0; $answer < $header['answers']; $answer++) {
            $owner = $this->readName($packet, $offset);
            if ($owner === null) {
                return $this->malformed($latency);
            }

            $offset = $owner['nextOffset'];
            if ($offset + 10 > strlen($packet)) {
                return $this->malformed($latency);
            }

            $record = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            if (! is_array($record) || $offset + $record['length'] > strlen($packet)) {
                return $this->malformed($latency);
            }

            $rdataOffset = $offset;
            $offset += $record['length'];

            $decoded = null;
            if ($record['class'] === 1 && in_array($record['type'], self::TYPE_CODES, true)) {
                $decoded = $this->decodeRdata($packet, $record['type'], $rdataOffset, $record['length']);
                if ($decoded === null) {
                    return $this->malformed($latency);
                }
            }

            $records[] = [
                'ownerKey' => $owner['key'],
                'type' => $record['type'],
                'class' => $record['class'],
                'value' => $decoded['value'] ?? null,
                'targetKey' => $decoded['nameKey'] ?? null,
            ];
        }

        for ($record = 0; $record < $header['authority'] + $header['additional']; $record++) {
            $owner = $this->readName($packet, $offset);
            if ($owner === null || strlen($packet) < $owner['nextOffset'] + 10) {
                return $this->malformed($latency);
            }

            $envelope = unpack('ntype/nclass/Nttl/nlength', substr($packet, $owner['nextOffset'], 10));
            if (! is_array($envelope)) {
                return $this->malformed($latency);
            }

            $offset = $owner['nextOffset'] + 10 + $envelope['length'];
            if ($offset > strlen($packet)) {
                return $this->malformed($latency);
            }
        }

        if ($offset !== strlen($packet)) {
            return $this->malformed($latency);
        }

        $rcode = $header['flags'] & 0x000F;
        if ($rcode !== 0) {
            return new DnsTransportResult(false, [], $latency, $rcode === 3 ? 'nxdomain' : 'server_failure');
        }

        $reachable = $this->reachableAnswerOwners($records, $this->nameKey($requestedName));
        if ($reachable === null) {
            return $this->malformed($latency);
        }

        $answers = [];
        foreach ($records as $record) {
            if (
                $record['class'] === 1
                && $record['type'] === $requestedType
                && $record['value'] !== null
                && isset($reachable[$record['ownerKey']])
                && strlen($record['value']) <= 1024
            ) {
                $answers[] = $record['value'];
            }
        }

        return new DnsTransportResult($answers !== [], array_values(array_unique($answers)), $latency, $answers === [] ? 'no_answer' : 'answer');
    }

    /**
     * @return array{name: string, key: string, nextOffset: int}|null
     */
    private function readName(string $packet, int $offset, bool $allowCompression = true): ?array
    {
        $length = strlen($packet);
        $labels = [];
        $visited = [];
        $nextOffset = null;
        $expandedLength = 1;

        for ($count = 0; $count < self::MAX_NAME_STEPS; $count++) {
            if ($offset < 0 || $offset >= $length || isset($visited[$offset])) {
                return null;
            }

            $visited[$offset] = true;
            $size = ord($packet[$offset]);
            if ($size === 0) {
                return [
                    'name' => $this->presentName($labels),
                    'key' => $this->labelsKey($labels),
                    'nextOffset' => $nextOffset ?? $offset + 1,
                ];
            }

            if (($size & 0xC0) === 0xC0) {
                if (! $allowCompression || $offset + 1 >= $length) {
                    return null;
                }

                $nextOffset ??= $offset + 2;
                $pointer = (($size & 0x3F) << 8) | ord($packet[$offset + 1]);
                if ($pointer < 12 || $pointer >= $offset || $pointer >= $length) {
                    return null;
                }

                $offset = $pointer;

                continue;
            }

            if (($size & 0xC0) !== 0 || $size > 63 || $offset + 1 + $size > $length) {
                return null;
            }

            $expandedLength += $size + 1;
            if ($expandedLength > 255) {
                return null;
            }

            $labels[] = strtolower(substr($packet, $offset + 1, $size));
            $offset += 1 + $size;
        }

        return null;
    }

    /** @return array{value: string, nameKey: ?string}|null */
    private function decodeRdata(string $packet, int $type, int $offset, int $length): ?array
    {
        $rdata = substr($packet, $offset, $length);

        if ($type === self::TYPE_CODES['CNAME']) {
            return $this->decodeNameRdata($packet, $offset, $length);
        }

        $value = match ($type) {
            1 => strlen($rdata) === 4 ? inet_ntop($rdata) ?: null : null,
            28 => strlen($rdata) === 16 ? inet_ntop($rdata) ?: null : null,
            15 => $this->decodeMxRdata($packet, $offset, $length),
            16 => $this->decodeTxt($rdata),
            default => null,
        };

        return is_string($value) ? ['value' => $value, 'nameKey' => null] : null;
    }

    /** @return array{value: string, nameKey: string}|null */
    private function decodeNameRdata(string $packet, int $offset, int $length): ?array
    {
        $name = $this->readName($packet, $offset);

        return $name !== null && $name['nextOffset'] === $offset + $length
            ? ['value' => $name['name'], 'nameKey' => $name['key']]
            : null;
    }

    private function decodeMxRdata(string $packet, int $offset, int $length): ?string
    {
        if ($length < 3) {
            return null;
        }

        $preference = unpack('nvalue', substr($packet, $offset, 2));
        $exchange = $this->readName($packet, $offset + 2);
        if (! is_array($preference) || $exchange === null || $exchange['nextOffset'] !== $offset + $length) {
            return null;
        }

        return $preference['value'].' '.$exchange['name'];
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

    /**
     * @param  list<array{ownerKey: string, type: int, class: int, value: ?string, targetKey: ?string}>  $records
     * @return array<string, true>|null
     */
    private function reachableAnswerOwners(array $records, string $requestedKey): ?array
    {
        $cnameTargets = [];
        foreach ($records as $record) {
            if (
                $record['class'] !== 1
                || $record['type'] !== self::TYPE_CODES['CNAME']
                || $record['value'] === null
                || $record['targetKey'] === null
            ) {
                continue;
            }

            if (
                isset($cnameTargets[$record['ownerKey']])
                && $cnameTargets[$record['ownerKey']]['key'] !== $record['targetKey']
            ) {
                return null;
            }

            $cnameTargets[$record['ownerKey']] = [
                'key' => $record['targetKey'],
                'name' => $record['value'],
            ];
        }

        $reachable = [$requestedKey => true];
        $visited = [];
        $currentKey = $requestedKey;
        for ($hop = 0; $hop <= self::MAX_ANSWERS; $hop++) {
            if (! isset($cnameTargets[$currentKey])) {
                return $reachable;
            }
            if (isset($visited[$currentKey])) {
                return null;
            }

            $visited[$currentKey] = true;
            $target = $cnameTargets[$currentKey];
            if ($target['name'] === '' || isset($visited[$target['key']])) {
                return null;
            }

            $reachable[$target['key']] = true;
            $currentKey = $target['key'];
        }

        return null;
    }

    private function nameKey(string $name): string
    {
        $name = strtolower(rtrim($name, '.'));

        return $this->labelsKey($name === '' ? [] : explode('.', $name));
    }

    /** @param list<string> $labels */
    private function labelsKey(array $labels): string
    {
        $key = 'dns-name:';
        foreach ($labels as $label) {
            $key .= pack('n', strlen($label)).$label;
        }

        return $key;
    }

    /** @param list<string> $labels */
    private function presentName(array $labels): string
    {
        return implode('.', array_map(function (string $label): string {
            $presented = '';
            for ($offset = 0; $offset < strlen($label); $offset++) {
                $character = $label[$offset];
                $byte = ord($character);
                if ($character === '.' || $character === '\\') {
                    $presented .= '\\'.$character;
                } elseif ($byte < 33 || $byte > 126) {
                    $presented .= sprintf('\\%03d', $byte);
                } else {
                    $presented .= $character;
                }
            }

            return $presented;
        }, $labels));
    }

    private function malformed(int $latency): DnsTransportResult
    {
        return new DnsTransportResult(false, [], $latency, 'malformed_response');
    }
}
