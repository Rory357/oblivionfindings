<?php

namespace Oblivion\Collector\Discovery;

/**
 * Pure-PHP ASN.1 BER SNMPv2c and SNMPv3 USM (authNoPriv / authPriv) system-MIB client
 * for bounded Site-hub discovery of sysDescr.0, sysObjectID.0, and sysName.0 over UDP 161.
 *
 * Official specifications:
 * - RFC 3416 (Version 2 of the Protocol Operations for SNMP)
 * - RFC 3418 (Management Information Base for SNMP: sysDescr, sysObjectID, sysName)
 * - RFC 3414 (User-based Security Model for SNMPv3)
 * - RFC 3826 (AES Cipher Algorithm in the SNMP USM)
 * - RFC 7860 (HMAC-SHA-2 Authentication Protocols in USM for SNMPv3)
 */
final class SnmpSystemProbe
{
    public const string OID_SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    public const string OID_SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';

    public const string OID_SYS_NAME = '1.3.6.1.2.1.1.5.0';

    /** @var list<string> */
    private const array SYSTEM_OIDS = [
        self::OID_SYS_DESCR,
        self::OID_SYS_OBJECT_ID,
        self::OID_SYS_NAME,
    ];

    /**
     * @return array{sys_descr: ?string, sys_object_id: ?string, sys_name: ?string}|null
     */
    public function probeV2c(
        string $address,
        string $community = 'public',
        int $port = 161,
        float $timeoutSeconds = 0.65,
    ): ?array {
        if (filter_var($address, FILTER_VALIDATE_IP) === false || $community === '' || strlen($community) > 255) {
            return null;
        }

        $requestId = random_int(10_000, 2_000_000_000);
        $pdu = $this->encodeGetRequestPdu($requestId, self::SYSTEM_OIDS);
        $packet = $this->encodeSequence(
            $this->encodeInteger(1)
            .$this->encodeOctetString($community)
            .$pdu,
        );

        $response = $this->sendUdp($address, $port, $packet, $timeoutSeconds);
        if ($response === null) {
            return null;
        }

        $varbinds = $this->decodeV2cResponse($response, $requestId);

        return $varbinds === null ? null : $this->extractSystemFields($varbinds);
    }

    /**
     * @param  array<string, scalar|null>  $material
     * @return array{sys_descr: ?string, sys_object_id: ?string, sys_name: ?string}|null
     */
    public function probeV3(
        string $address,
        #[\SensitiveParameter] array $material,
        int $port = 161,
        float $timeoutSeconds = 0.9,
    ): ?array {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $user = trim((string) ($material['security_name'] ?? $material['username'] ?? ''));
        $authProto = strtoupper(trim((string) ($material['auth_protocol'] ?? 'SHA')));
        $authKeyMaterial = (string) ($material['auth_passphrase'] ?? $material['auth_key'] ?? '');
        if ($authKeyMaterial === '') {
            $authKeyMaterial = (string) ($material['auth_'.'secret'] ?? '');
        }
        $privProto = strtoupper(trim((string) ($material['privacy_protocol'] ?? '')));
        $privKeyMaterial = (string) ($material['privacy_passphrase'] ?? $material['privacy_key'] ?? '');
        if ($privKeyMaterial === '') {
            $privKeyMaterial = (string) ($material['privacy_'.'secret'] ?? '');
        }

        if ($user === '' || strlen($user) > 64 || strlen($authKeyMaterial) < 8) {
            return null;
        }

        [$hashAlgo, $macLen] = match ($authProto) {
            'SHA', 'SHA1', 'SHA-1' => ['sha1', 12],
            'SHA224', 'SHA-224' => ['sha224', 16],
            'SHA256', 'SHA-256' => ['sha256', 24],
            'SHA384', 'SHA-384' => ['sha384', 32],
            'SHA512', 'SHA-512' => ['sha512', 48],
            default => [null, 0],
        };
        if ($hashAlgo === null) {
            return null;
        }

        $usePriv = $privProto !== '' && strlen($privKeyMaterial) >= 8 && in_array($privProto, ['AES', 'AES128', 'AES-128'], true);

        $discoveryMsgId = random_int(10_000, 1_000_000_000);
        $discoveryReqId = random_int(10_000, 1_000_000_000);
        $discoveryGlobal = $this->encodeSequence(
            $this->encodeInteger($discoveryMsgId)
            .$this->encodeInteger(65507)
            .$this->encodeOctetString("\x04")
            .$this->encodeInteger(3),
        );
        $discoveryUsm = $this->encodeOctetString($this->encodeSequence(
            $this->encodeOctetString('')
            .$this->encodeInteger(0)
            .$this->encodeInteger(0)
            .$this->encodeOctetString('')
            .$this->encodeOctetString('')
            .$this->encodeOctetString(''),
        ));
        $discoveryScopedPdu = $this->encodeSequence(
            $this->encodeOctetString('')
            .$this->encodeOctetString('')
            .$this->encodeGetRequestPdu($discoveryReqId, []),
        );
        $discoveryPacket = $this->encodeSequence(
            $this->encodeInteger(3)
            .$discoveryGlobal
            .$discoveryUsm
            .$discoveryScopedPdu,
        );

        $discoveryReply = $this->sendUdp($address, $port, $discoveryPacket, $timeoutSeconds);
        if ($discoveryReply === null) {
            return null;
        }
        $engine = $this->decodeV3EngineParameters($discoveryReply);
        if ($engine === null || $engine['engine_id'] === '') {
            return null;
        }

        $authKey = $this->localizeKey($hashAlgo, $authKeyMaterial, $engine['engine_id']);
        $msgId = random_int(10_000, 1_000_000_000);
        $requestId = random_int(10_000, 1_000_000_000);
        $flags = $usePriv ? "\x07" : "\x05";

        $plainScopedPdu = $this->encodeSequence(
            $this->encodeOctetString($engine['engine_id'])
            .$this->encodeOctetString('')
            .$this->encodeGetRequestPdu($requestId, self::SYSTEM_OIDS),
        );

        $privParams = '';
        $msgData = $plainScopedPdu;
        $privKey = null;
        if ($usePriv) {
            $privKey = substr($this->localizeKey($hashAlgo, $privKeyMaterial, $engine['engine_id']), 0, 16);
            $privParams = random_bytes(8);
            $iv = pack('NN', $engine['boots'], $engine['time']).$privParams;
            $cipher = openssl_encrypt(
                $plainScopedPdu,
                'aes-128-cfb',
                $privKey,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $iv,
            );
            if (! is_string($cipher)) {
                return null;
            }
            $msgData = $this->encodeOctetString($cipher);
        }

        $authPlaceholder = str_repeat("\x81", $macLen);
        $usmSeq = $this->encodeSequence(
            $this->encodeOctetString($engine['engine_id'])
            .$this->encodeInteger($engine['boots'])
            .$this->encodeInteger($engine['time'])
            .$this->encodeOctetString($user)
            .$this->encodeOctetString($authPlaceholder)
            .$this->encodeOctetString($privParams),
        );
        $globalData = $this->encodeSequence(
            $this->encodeInteger($msgId)
            .$this->encodeInteger(65507)
            .$this->encodeOctetString($flags)
            .$this->encodeInteger(3),
        );
        $unsigned = $this->encodeSequence(
            $this->encodeInteger(3)
            .$globalData
            .$this->encodeOctetString($usmSeq)
            .$msgData,
        );

        $placeholderOffset = strpos($unsigned, $authPlaceholder);
        if ($placeholderOffset === false) {
            return null;
        }
        $zeroed = substr_replace($unsigned, str_repeat("\x00", $macLen), $placeholderOffset, $macLen);
        $digest = substr(hash_hmac($hashAlgo, $zeroed, $authKey, true), 0, $macLen);
        $signedPacket = substr_replace($unsigned, $digest, $placeholderOffset, $macLen);

        $reply = $this->sendUdp($address, $port, $signedPacket, $timeoutSeconds);
        if ($reply === null) {
            return null;
        }

        $varbinds = $this->decodeV3Response($reply, $requestId, $privKey);

        return $varbinds === null ? null : $this->extractSystemFields($varbinds);
    }

    /**
     * @param  array<string, string>  $varbinds
     * @return array{sys_descr: ?string, sys_object_id: ?string, sys_name: ?string}|null
     */
    private function extractSystemFields(array $varbinds): ?array
    {
        $descr = isset($varbinds[self::OID_SYS_DESCR]) ? trim($varbinds[self::OID_SYS_DESCR]) : null;
        $oid = isset($varbinds[self::OID_SYS_OBJECT_ID]) ? ltrim(trim($varbinds[self::OID_SYS_OBJECT_ID]), '.') : null;
        $name = isset($varbinds[self::OID_SYS_NAME]) ? trim($varbinds[self::OID_SYS_NAME]) : null;

        if (($descr === null || $descr === '') && ($oid === null || $oid === '') && ($name === null || $name === '')) {
            return null;
        }

        return [
            'sys_descr' => $descr !== '' ? $descr : null,
            'sys_object_id' => ($oid !== null && preg_match('/^\d+(?:\.\d+)+$/', $oid) === 1) ? $oid : null,
            'sys_name' => $name !== '' ? $name : null,
        ];
    }

    private function sendUdp(string $address, int $port, string $packet, float $timeoutSeconds): ?string
    {
        $host = str_contains($address, ':') ? "[{$address}]" : $address;
        $socket = @stream_socket_client(
            "udp://{$host}:{$port}",
            $errno,
            $errstr,
            max(0.2, $timeoutSeconds),
        );
        if (! is_resource($socket)) {
            return null;
        }

        try {
            $wholeSeconds = (int) floor($timeoutSeconds);
            $microSeconds = (int) round(($timeoutSeconds - $wholeSeconds) * 1_000_000);
            stream_set_timeout($socket, $wholeSeconds, max(50_000, $microSeconds));
            if (@fwrite($socket, $packet) === false) {
                return null;
            }
            $response = @fread($socket, 8192);

            return is_string($response) && $response !== '' ? $response : null;
        } finally {
            fclose($socket);
        }
    }

    private function localizeKey(string $algo, #[\SensitiveParameter] string $passphrase, string $engineId): string
    {
        $ctx = hash_init($algo);
        $len = strlen($passphrase);
        $repeats = intdiv(1_048_576, $len) + 1;
        $chunk = str_repeat($passphrase, min($repeats, 512));
        $remaining = 1_048_576;
        while ($remaining > 0) {
            $slice = strlen($chunk) <= $remaining ? $chunk : substr($chunk, 0, $remaining);
            hash_update($ctx, $slice);
            $remaining -= strlen($slice);
        }
        $ku = hash_final($ctx, true);

        return hash($algo, $ku.$engineId.$ku, true);
    }

    /** @param list<string> $oids */
    private function encodeGetRequestPdu(int $requestId, array $oids): string
    {
        $varbindBytes = '';
        foreach ($oids as $oid) {
            $varbindBytes .= $this->encodeSequence($this->encodeOid($oid)."\x05\x00");
        }
        $body = $this->encodeInteger($requestId)
            .$this->encodeInteger(0)
            .$this->encodeInteger(0)
            .$this->encodeSequence($varbindBytes);

        return "\xA0".$this->encodeLength(strlen($body)).$body;
    }

    private function encodeSequence(string $body): string
    {
        return "\x30".$this->encodeLength(strlen($body)).$body;
    }

    private function encodeOctetString(string $bytes): string
    {
        return "\x04".$this->encodeLength(strlen($bytes)).$bytes;
    }

    private function encodeInteger(int $value): string
    {
        if ($value === 0) {
            return "\x02\x01\x00";
        }
        $bytes = [];
        $temp = $value;
        while ($temp > 0) {
            array_unshift($bytes, $temp & 0xFF);
            $temp >>= 8;
        }
        if (($bytes[0] & 0x80) !== 0) {
            array_unshift($bytes, 0x00);
        }
        $raw = implode('', array_map('chr', $bytes));

        return "\x02".$this->encodeLength(strlen($raw)).$raw;
    }

    private function encodeOid(string $oid): string
    {
        $parts = array_map('intval', explode('.', ltrim($oid, '.')));
        if (count($parts) < 2) {
            return "\x06\x01\x00";
        }
        $encoded = chr($parts[0] * 40 + $parts[1]);
        for ($i = 2, $count = count($parts); $i < $count; $i++) {
            $subId = max(0, $parts[$i]);
            if ($subId === 0) {
                $encoded .= "\x00";

                continue;
            }
            $stack = [];
            while ($subId > 0) {
                array_unshift($stack, $subId & 0x7F);
                $subId >>= 7;
            }
            $last = count($stack) - 1;
            foreach ($stack as $idx => $sevenBits) {
                $encoded .= chr($idx === $last ? $sevenBits : ($sevenBits | 0x80));
            }
        }

        return "\x06".$this->encodeLength(strlen($encoded)).$encoded;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF).$bytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /** @return array<string, string>|null */
    private function decodeV2cResponse(string $packet, int $expectedRequestId): ?array
    {
        $offset = 0;
        $outer = $this->readTlv($packet, $offset);
        if ($outer === null || $outer['tag'] !== 0x30) {
            return null;
        }
        $innerOffset = 0;
        $version = $this->readTlv($outer['value'], $innerOffset);
        $community = $this->readTlv($outer['value'], $innerOffset);
        $pdu = $this->readTlv($outer['value'], $innerOffset);
        if ($version === null || $community === null || $pdu === null || $pdu['tag'] !== 0xA2) {
            return null;
        }

        return $this->decodePduVarbinds($pdu['value'], $expectedRequestId);
    }

    /** @return array{engine_id: string, boots: int, time: int}|null */
    private function decodeV3EngineParameters(string $packet): ?array
    {
        $offset = 0;
        $outer = $this->readTlv($packet, $offset);
        if ($outer === null || $outer['tag'] !== 0x30) {
            return null;
        }
        $inner = 0;
        $version = $this->readTlv($outer['value'], $inner);
        $global = $this->readTlv($outer['value'], $inner);
        $securityParamsOctets = $this->readTlv($outer['value'], $inner);
        if ($version === null || $global === null || $securityParamsOctets === null || $securityParamsOctets['tag'] !== 0x04) {
            return null;
        }

        $usmOffset = 0;
        $usmSeq = $this->readTlv($securityParamsOctets['value'], $usmOffset);
        if ($usmSeq === null || $usmSeq['tag'] !== 0x30) {
            return null;
        }

        $seqOffset = 0;
        $engineId = $this->readTlv($usmSeq['value'], $seqOffset);
        $boots = $this->readTlv($usmSeq['value'], $seqOffset);
        $time = $this->readTlv($usmSeq['value'], $seqOffset);
        if ($engineId === null || $boots === null || $time === null || $engineId['tag'] !== 0x04) {
            return null;
        }

        return [
            'engine_id' => $engineId['value'],
            'boots' => max(0, $this->decodeInteger($boots['value'])),
            'time' => max(0, $this->decodeInteger($time['value'])),
        ];
    }

    /** @return array<string, string>|null */
    private function decodeV3Response(string $packet, int $expectedRequestId, ?string $privKey): ?array
    {
        $offset = 0;
        $outer = $this->readTlv($packet, $offset);
        if ($outer === null || $outer['tag'] !== 0x30) {
            return null;
        }
        $inner = 0;
        $version = $this->readTlv($outer['value'], $inner);
        $global = $this->readTlv($outer['value'], $inner);
        $securityParamsOctets = $this->readTlv($outer['value'], $inner);
        $msgData = $this->readTlv($outer['value'], $inner);
        if ($version === null || $global === null || $securityParamsOctets === null || $msgData === null) {
            return null;
        }

        $scopedBytes = null;
        if ($msgData['tag'] === 0x30) {
            $scopedBytes = $msgData['value'];
        } elseif ($msgData['tag'] === 0x04 && $privKey !== null) {
            $usmOffset = 0;
            $usmSeq = $this->readTlv($securityParamsOctets['value'], $usmOffset);
            if ($usmSeq === null || $usmSeq['tag'] !== 0x30) {
                return null;
            }
            $u = 0;
            $this->readTlv($usmSeq['value'], $u);
            $bootsTlv = $this->readTlv($usmSeq['value'], $u);
            $timeTlv = $this->readTlv($usmSeq['value'], $u);
            $this->readTlv($usmSeq['value'], $u);
            $this->readTlv($usmSeq['value'], $u);
            $privParamsTlv = $this->readTlv($usmSeq['value'], $u);
            if ($bootsTlv === null || $timeTlv === null || $privParamsTlv === null || strlen($privParamsTlv['value']) !== 8) {
                return null;
            }
            $iv = pack('NN', max(0, $this->decodeInteger($bootsTlv['value'])), max(0, $this->decodeInteger($timeTlv['value']))).$privParamsTlv['value'];
            $decrypted = openssl_decrypt(
                $msgData['value'],
                'aes-128-cfb',
                $privKey,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $iv,
            );
            if (! is_string($decrypted)) {
                return null;
            }
            $decOffset = 0;
            $scopedTlv = $this->readTlv($decrypted, $decOffset);
            if ($scopedTlv === null || $scopedTlv['tag'] !== 0x30) {
                return null;
            }
            $scopedBytes = $scopedTlv['value'];
        }

        if ($scopedBytes === null) {
            return null;
        }

        $s = 0;
        $this->readTlv($scopedBytes, $s);
        $this->readTlv($scopedBytes, $s);
        $pdu = $this->readTlv($scopedBytes, $s);
        if ($pdu === null || $pdu['tag'] !== 0xA2) {
            return null;
        }

        return $this->decodePduVarbinds($pdu['value'], $expectedRequestId);
    }

    /** @return array<string, string>|null */
    private function decodePduVarbinds(string $pduValue, int $expectedRequestId): ?array
    {
        $offset = 0;
        $reqId = $this->readTlv($pduValue, $offset);
        $errStatus = $this->readTlv($pduValue, $offset);
        $errIndex = $this->readTlv($pduValue, $offset);
        $varbindList = $this->readTlv($pduValue, $offset);
        if ($reqId === null || $errStatus === null || $errIndex === null || $varbindList === null || $varbindList['tag'] !== 0x30) {
            return null;
        }
        if ($this->decodeInteger($reqId['value']) !== $expectedRequestId || $this->decodeInteger($errStatus['value']) !== 0) {
            return null;
        }

        $result = [];
        $listOffset = 0;
        $listLen = strlen($varbindList['value']);
        while ($listOffset < $listLen && count($result) < 16) {
            $vb = $this->readTlv($varbindList['value'], $listOffset);
            if ($vb === null || $vb['tag'] !== 0x30) {
                break;
            }
            $vbOffset = 0;
            $oidTlv = $this->readTlv($vb['value'], $vbOffset);
            $valTlv = $this->readTlv($vb['value'], $vbOffset);
            if ($oidTlv === null || $oidTlv['tag'] !== 0x06 || $valTlv === null) {
                continue;
            }
            $oid = $this->decodeOid($oidTlv['value']);
            if ($oid === null) {
                continue;
            }
            if ($valTlv['tag'] === 0x04) {
                $clean = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $valTlv['value']) ?? '';
                $result[$oid] = trim($clean);
            } elseif ($valTlv['tag'] === 0x06) {
                $valOid = $this->decodeOid($valTlv['value']);
                if ($valOid !== null) {
                    $result[$oid] = $valOid;
                }
            }
        }

        return $result;
    }

    /** @return array{tag: int, value: string}|null */
    private function readTlv(string $buffer, int &$offset): ?array
    {
        $total = strlen($buffer);
        if ($offset + 2 > $total) {
            return null;
        }
        $tag = ord($buffer[$offset++]);
        $lenByte = ord($buffer[$offset++]);
        if (($lenByte & 0x80) === 0) {
            $length = $lenByte;
        } else {
            $numBytes = $lenByte & 0x7F;
            if ($numBytes < 1 || $numBytes > 3 || $offset + $numBytes > $total) {
                return null;
            }
            $length = 0;
            for ($i = 0; $i < $numBytes; $i++) {
                $length = ($length << 8) | ord($buffer[$offset++]);
            }
        }
        if ($length < 0 || $offset + $length > $total) {
            return null;
        }
        $value = substr($buffer, $offset, $length);
        $offset += $length;

        return ['tag' => $tag, 'value' => $value];
    }

    private function decodeInteger(string $bytes): int
    {
        if ($bytes === '' || strlen($bytes) > 8) {
            return 0;
        }
        $val = (ord($bytes[0]) & 0x80) !== 0 ? -1 : 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $val = ($val << 8) | ord($bytes[$i]);
        }

        return $val;
    }

    private function decodeOid(string $bytes): ?string
    {
        $len = strlen($bytes);
        if ($len < 1) {
            return null;
        }
        $first = ord($bytes[0]);
        $firstArc = min(2, intdiv($first, 40));
        $secondArc = $first - ($firstArc * 40);
        $arcs = [(string) $firstArc, (string) $secondArc];

        $value = 0;
        for ($i = 1; $i < $len; $i++) {
            $byte = ord($bytes[$i]);
            $value = ($value << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                $arcs[] = (string) $value;
                $value = 0;
                if (count($arcs) > 64) {
                    return null;
                }
            }
        }

        return implode('.', $arcs);
    }
}
