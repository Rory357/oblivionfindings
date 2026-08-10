<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Data\CredentialLease;
use RuntimeException;

final class SnmpTrapDecoder
{
    private const int MAX_DATAGRAM_BYTES = 65_507;

    private const int MAX_VARBINDS = 128;

    public function version(string $datagram): string
    {
        $message = $this->messageReader($datagram);
        $version = $this->integer($message->read(0x02));

        return match ($version) {
            0 => 'v1',
            1 => 'v2c',
            3 => 'v3',
            default => throw new RuntimeException('SNMP trap version is unsupported.'),
        };
    }

    public function decode(string $datagram, CredentialLease $lease): SnmpTrap
    {
        $message = $this->messageReader($datagram);
        $version = $this->integer($message->read(0x02));

        return match ($version) {
            0 => $this->decodeCommunity($message, $lease, 'v1'),
            1 => $this->decodeCommunity($message, $lease, 'v2c'),
            3 => $this->decodeV3($datagram, $message, $lease),
            default => throw new RuntimeException('SNMP trap version is unsupported.'),
        };
    }

    private function messageReader(string $datagram): BerReader
    {
        if ($datagram === '' || strlen($datagram) > self::MAX_DATAGRAM_BYTES) {
            throw new RuntimeException('SNMP trap datagram is invalid.');
        }
        $outerReader = new BerReader($datagram);
        $outer = $outerReader->read(0x30);
        $outerReader->assertFinished();

        return new BerReader($outer->value, $outer->absoluteValueOffset);
    }

    private function decodeCommunity(BerReader $message, CredentialLease $lease, string $version): SnmpTrap
    {
        $communityElement = $message->read(0x04);
        $material = $lease->material();
        $expectedCommunity = null;
        try {
            $expectedCommunity = $material['community'] ?? null;
            if (! is_string($expectedCommunity) || $expectedCommunity === ''
                || ! hash_equals($expectedCommunity, $communityElement->value)) {
                throw new RuntimeException('SNMP compatibility trap authentication failed.');
            }

            $pdu = $message->read();
            $message->assertFinished();
            if ($version === 'v1') {
                return $this->decodeV1Pdu($pdu);
            }
            if ($pdu->tag !== 0xA7) {
                throw new RuntimeException('SNMPv2c trap PDU is invalid.');
            }

            return $this->decodeV2Pdu($pdu, 'v2c');
        } finally {
            $this->wipe($expectedCommunity);
            $this->clear($material);
        }
    }

    private function decodeV3(string $datagram, BerReader $message, CredentialLease $lease): SnmpTrap
    {
        $headerElement = $message->read(0x30);
        $header = new BerReader($headerElement->value, $headerElement->absoluteValueOffset);
        $messageId = $this->integer($header->read(0x02));
        $maxSize = $this->integer($header->read(0x02));
        $flags = $header->read(0x04)->value;
        $securityModel = $this->integer($header->read(0x02));
        $header->assertFinished();
        if ($messageId < 0 || $maxSize < 484 || $maxSize > self::MAX_DATAGRAM_BYTES
            || strlen($flags) !== 1 || $securityModel !== 3
            || (ord($flags) & 0x03) !== 0x03) {
            throw new RuntimeException('SNMPv3 trap requires authenticated privacy.');
        }

        $securityOctets = $message->read(0x04);
        $securityWrapper = new BerReader($securityOctets->value, $securityOctets->absoluteValueOffset);
        $securityElement = $securityWrapper->read(0x30);
        $securityWrapper->assertFinished();
        $security = new BerReader($securityElement->value, $securityElement->absoluteValueOffset);
        $engineId = $security->read(0x04)->value;
        $engineBoots = $this->integer($security->read(0x02));
        $engineTime = $this->integer($security->read(0x02));
        $securityName = $security->read(0x04)->value;
        $authentication = $security->read(0x04);
        $privacyParameters = $security->read(0x04)->value;
        $security->assertFinished();
        $encryptedPdu = $message->read(0x04)->value;
        $message->assertFinished();
        if ($engineId === '' || strlen($engineId) > 64 || $engineBoots < 0 || $engineTime < 0
            || strlen($securityName) < 1 || strlen($securityName) > 64
            || strlen($privacyParameters) !== 8 || $encryptedPdu === '') {
            throw new RuntimeException('SNMPv3 security parameters are invalid.');
        }

        $material = $lease->material();
        $authSecret = null;
        $privacySecret = null;
        $authKey = null;
        $privacyKey = null;
        try {
            $expectedName = $material['security_name'] ?? null;
            $authProtocol = strtoupper((string) ($material['auth_protocol'] ?? ''));
            $authSecret = $material['auth_secret'] ?? null;
            $privacyProtocol = strtoupper((string) ($material['privacy_protocol'] ?? ''));
            $privacySecret = $material['privacy_secret'] ?? null;
            if (! is_string($expectedName) || ! hash_equals($expectedName, $securityName)
                || ! is_string($authSecret) || strlen($authSecret) < 8
                || ! is_string($privacySecret) || strlen($privacySecret) < 8) {
                throw new RuntimeException('SNMPv3 trap authentication failed.');
            }

            [$algorithm, $authenticationBytes] = $this->authenticationAlgorithm($authProtocol);
            if (strlen($authentication->value) !== $authenticationBytes) {
                throw new RuntimeException('SNMPv3 trap authentication failed.');
            }
            $zeroed = substr_replace(
                $datagram,
                str_repeat("\0", $authenticationBytes),
                $authentication->absoluteValueOffset,
                $authenticationBytes,
            );
            $authKey = $this->localisedKey($authSecret, $engineId, $algorithm);
            $expectedAuthentication = substr(hash_hmac($algorithm, $zeroed, $authKey, true), 0, $authenticationBytes);
            if (! hash_equals($expectedAuthentication, $authentication->value)) {
                throw new RuntimeException('SNMPv3 trap authentication failed.');
            }

            [$cipher, $privacyKeyBytes] = $this->privacyCipher($privacyProtocol);
            $privacyKey = substr($this->localisedKey($privacySecret, $engineId, $algorithm), 0, $privacyKeyBytes);
            if (strlen($privacyKey) !== $privacyKeyBytes) {
                throw new RuntimeException('SNMPv3 trap privacy failed.');
            }
            $iv = pack('N', $engineBoots).pack('N', $engineTime).$privacyParameters;
            $plaintext = openssl_decrypt($encryptedPdu, $cipher, $privacyKey, OPENSSL_RAW_DATA, $iv);
            if (! is_string($plaintext) || $plaintext === '') {
                throw new RuntimeException('SNMPv3 trap privacy failed.');
            }
            $scopedWrapper = new BerReader($plaintext);
            $scopedElement = $scopedWrapper->read(0x30);
            $scopedWrapper->assertFinished();
            $scoped = new BerReader($scopedElement->value);
            $contextEngineId = $scoped->read(0x04)->value;
            $scoped->read(0x04);
            $pdu = $scoped->read(0xA7);
            $scoped->assertFinished();
            if ($contextEngineId !== '' && ! hash_equals($engineId, $contextEngineId)) {
                throw new RuntimeException('SNMPv3 scoped engine identity is invalid.');
            }

            $trap = $this->decodeV2Pdu($pdu, 'v3');

            return new SnmpTrap(
                version: 'v3',
                requestId: $trap->requestId,
                trapOid: $trap->trapOid,
                uptimeTicks: $trap->uptimeTicks,
                systemName: $trap->systemName,
                ifIndex: $trap->ifIndex,
                ifName: $trap->ifName,
                varbindCount: $trap->varbindCount,
                engineId: $engineId,
                engineBoots: $engineBoots,
                engineTime: $engineTime,
            );
        } finally {
            $this->wipe($authSecret);
            $this->wipe($privacySecret);
            $this->wipe($authKey);
            $this->wipe($privacyKey);
            $this->clear($material);
        }
    }

    private function decodeV2Pdu(BerElement $pdu, string $version): SnmpTrap
    {
        $reader = new BerReader($pdu->value, $pdu->absoluteValueOffset);
        $requestId = $this->integer($reader->read(0x02));
        $errorStatus = $this->integer($reader->read(0x02));
        $errorIndex = $this->integer($reader->read(0x02));
        $varbinds = $reader->read(0x30);
        $reader->assertFinished();
        if ($requestId < 0 || $errorStatus !== 0 || $errorIndex !== 0) {
            throw new RuntimeException('SNMP trap PDU status is invalid.');
        }

        $parsed = $this->varbinds($varbinds);
        if ($parsed['trap_oid'] === null) {
            throw new RuntimeException('SNMP trap OID is missing.');
        }

        return new SnmpTrap(
            version: $version,
            requestId: $requestId,
            trapOid: $parsed['trap_oid'],
            uptimeTicks: $parsed['uptime_ticks'],
            systemName: $parsed['system_name'],
            ifIndex: $parsed['if_index'],
            ifName: $parsed['if_name'],
            varbindCount: $parsed['count'],
            engineId: null,
            engineBoots: null,
            engineTime: null,
        );
    }

    private function decodeV1Pdu(BerElement $pdu): SnmpTrap
    {
        if ($pdu->tag !== 0xA4) {
            throw new RuntimeException('SNMPv1 trap PDU is invalid.');
        }
        $reader = new BerReader($pdu->value, $pdu->absoluteValueOffset);
        $enterprise = $this->oid($reader->read(0x06));
        $agentAddress = $reader->read(0x40)->value;
        $generic = $this->integer($reader->read(0x02));
        $specific = $this->integer($reader->read(0x02));
        $uptime = $this->unsigned($reader->read(0x43));
        $varbinds = $reader->read(0x30);
        $reader->assertFinished();
        if (strlen($agentAddress) !== 4 || $generic < 0 || $generic > 6 || $specific < 0) {
            throw new RuntimeException('SNMPv1 trap fields are invalid.');
        }
        $parsed = $this->varbinds($varbinds);
        $standard = [
            0 => '1.3.6.1.6.3.1.1.5.1',
            1 => '1.3.6.1.6.3.1.1.5.2',
            2 => '1.3.6.1.6.3.1.1.5.3',
            3 => '1.3.6.1.6.3.1.1.5.4',
            4 => '1.3.6.1.6.3.1.1.5.5',
            5 => '1.3.6.1.6.3.1.1.5.6',
        ];
        $trapOid = $generic === 6 ? "{$enterprise}.0.{$specific}" : $standard[$generic];

        return new SnmpTrap(
            version: 'v1',
            requestId: 0,
            trapOid: $trapOid,
            uptimeTicks: $uptime,
            systemName: $parsed['system_name'],
            ifIndex: $parsed['if_index'],
            ifName: $parsed['if_name'],
            varbindCount: $parsed['count'],
            engineId: null,
            engineBoots: null,
            engineTime: null,
        );
    }

    /** @return array{trap_oid: ?string, uptime_ticks: ?int, system_name: ?string, if_index: ?int, if_name: ?string, count: int} */
    private function varbinds(BerElement $sequence): array
    {
        $reader = new BerReader($sequence->value, $sequence->absoluteValueOffset);
        $result = [
            'trap_oid' => null,
            'uptime_ticks' => null,
            'system_name' => null,
            'if_index' => null,
            'if_name' => null,
            'count' => 0,
        ];
        while (! $reader->finished()) {
            if (++$result['count'] > self::MAX_VARBINDS) {
                throw new RuntimeException('SNMP trap varbind limit exceeded.');
            }
            $bindingElement = $reader->read(0x30);
            $binding = new BerReader($bindingElement->value, $bindingElement->absoluteValueOffset);
            $oid = $this->oid($binding->read(0x06));
            $value = $binding->read();
            $binding->assertFinished();

            match ($oid) {
                '1.3.6.1.2.1.1.3.0' => $result['uptime_ticks'] = $value->tag === 0x43 ? $this->unsigned($value) : null,
                '1.3.6.1.6.3.1.1.4.1.0' => $result['trap_oid'] = $value->tag === 0x06 ? $this->oid($value) : null,
                '1.3.6.1.2.1.1.5.0' => $result['system_name'] = $value->tag === 0x04 ? $this->string($value->value, 255) : null,
                '1.3.6.1.2.1.2.2.1.1.0' => $result['if_index'] = $value->tag === 0x02 ? $this->integer($value) : null,
                '1.3.6.1.2.1.31.1.1.1.1.0' => $result['if_name'] = $value->tag === 0x04 ? $this->string($value->value, 255) : null,
                default => null,
            };
        }

        return $result;
    }

    private function integer(BerElement $element): int
    {
        if ($element->value === '' || strlen($element->value) > 5 || (ord($element->value[0]) & 0x80) !== 0) {
            throw new RuntimeException('SNMP BER integer is invalid.');
        }

        return $this->unsigned($element);
    }

    private function unsigned(BerElement $element): int
    {
        if ($element->value === '' || strlen($element->value) > 8) {
            throw new RuntimeException('SNMP BER unsigned value is invalid.');
        }
        $value = 0;
        foreach (str_split($element->value) as $byte) {
            if ($value > intdiv(PHP_INT_MAX - ord($byte), 256)) {
                throw new RuntimeException('SNMP BER unsigned value exceeds platform bounds.');
            }
            $value = ($value * 256) + ord($byte);
        }

        return $value;
    }

    private function oid(BerElement $element): string
    {
        if ($element->value === '' || strlen($element->value) > 256) {
            throw new RuntimeException('SNMP BER OID is invalid.');
        }
        $first = ord($element->value[0]);
        $parts = [min(2, intdiv($first, 40)), $first < 80 ? $first % 40 : $first - 80];
        $value = 0;
        $continued = false;
        foreach (str_split(substr($element->value, 1)) as $byte) {
            $octet = ord($byte);
            if ($value > intdiv(PHP_INT_MAX - ($octet & 0x7F), 128)) {
                throw new RuntimeException('SNMP BER OID is invalid.');
            }
            $value = ($value * 128) + ($octet & 0x7F);
            $continued = ($octet & 0x80) !== 0;
            if (! $continued) {
                $parts[] = $value;
                $value = 0;
            }
        }
        if ($continued || count($parts) < 3 || count($parts) > 128) {
            throw new RuntimeException('SNMP BER OID is invalid.');
        }

        return implode('.', $parts);
    }

    /** @return array{string, int} */
    private function authenticationAlgorithm(string $protocol): array
    {
        return match ($protocol) {
            'SHA', 'SHA1' => ['sha1', 12],
            'SHA224' => ['sha224', 16],
            'SHA256' => ['sha256', 24],
            'SHA384' => ['sha384', 32],
            'SHA512' => ['sha512', 48],
            default => throw new RuntimeException('SNMPv3 trap authentication protocol is unsupported.'),
        };
    }

    /** @return array{string, int} */
    private function privacyCipher(string $protocol): array
    {
        return match ($protocol) {
            'AES', 'AES128' => ['aes-128-cfb', 16],
            'AES192' => ['aes-192-cfb', 24],
            'AES256' => ['aes-256-cfb', 32],
            default => throw new RuntimeException('SNMPv3 trap privacy protocol is unsupported.'),
        };
    }

    private function localisedKey(string $passphrase, string $engineId, string $algorithm): string
    {
        $length = strlen($passphrase);
        if ($length < 8 || $length > 1024) {
            throw new RuntimeException('SNMPv3 passphrase is invalid.');
        }
        $context = hash_init($algorithm);
        $position = 0;
        $remaining = 1_048_576;
        while ($remaining > 0) {
            $chunkBytes = min(16_384, $remaining);
            $chunk = '';
            while (strlen($chunk) < $chunkBytes) {
                $take = min($length - $position, $chunkBytes - strlen($chunk));
                $chunk .= substr($passphrase, $position, $take);
                $position = ($position + $take) % $length;
            }
            hash_update($context, $chunk);
            $remaining -= $chunkBytes;
        }
        $key = hash_final($context, true);

        return hash($algorithm, $key.$engineId.$key, true);
    }

    private function string(string $value, int $maximum): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /** @param array<string, scalar|null> $material */
    private function clear(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                if (function_exists('sodium_memzero')) {
                    sodium_memzero($value);
                } else {
                    $value = str_repeat("\0", strlen($value));
                }
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }

    private function wipe(mixed &$value): void
    {
        if (is_string($value) && $value !== '') {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($value);
            } else {
                $value = str_repeat("\0", strlen($value));
            }
        }
        $value = null;
    }
}
