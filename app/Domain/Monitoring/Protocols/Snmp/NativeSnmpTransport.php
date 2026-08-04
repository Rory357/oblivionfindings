<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use RuntimeException;
use Throwable;

final class NativeSnmpTransport implements SnmpTransport
{
    public function poll(
        AuthorizedProbeTarget $target,
        CredentialLease $lease,
        SnmpQuery $query,
    ): SnmpTransportResult {
        if (! class_exists(\SNMP::class)) {
            return SnmpTransportResult::failure('transport_unavailable');
        }
        if ($target->scheme !== 'snmp' || $target->port !== 161 || $target->addresses === []) {
            throw new RuntimeException('SNMP target is invalid.');
        }
        foreach ($target->addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new RuntimeException('SNMP target must use an authorised numeric address.');
            }
        }

        $material = $lease->material();
        try {
            $this->validateMaterial($query->version, $material);

            $lastStatus = 'timeout';
            foreach ($target->addresses as $address) {
                $started = hrtime(true);
                $session = null;
                try {
                    $session = $this->session($address, $target, $query->version, $material);
                    $varbinds = [];
                    $responseBytes = 0;
                    $maxResponseBytes = min($target->maxResponseBytes, 1_048_576);
                    $partial = false;
                    $completedOptionalWalkRoots = [];

                    $scalars = @$session->get($query->scalarOids, true);
                    if (! is_array($scalars)) {
                        $partial = true;
                    } else {
                        $this->append($varbinds, $responseBytes, $scalars, $query, $maxResponseBytes);
                    }

                    foreach ($query->walkRoots as $root) {
                        $walk = @$session->walk($root, true);
                        if (! is_array($walk)) {
                            $partial = true;

                            continue;
                        }
                        $this->append($varbinds, $responseBytes, $walk, $query, $maxResponseBytes);
                    }

                    foreach ($query->optionalWalkRoots as $root) {
                        $walk = @$session->walk($root, true);
                        if (! is_array($walk)) {
                            continue;
                        }
                        $this->append($varbinds, $responseBytes, $walk, $query, $maxResponseBytes);
                        $completedOptionalWalkRoots[] = $root;
                    }

                    $latency = (int) round((hrtime(true) - $started) / 1_000_000);
                    if ($varbinds !== []) {
                        return SnmpTransportResult::success(
                            $varbinds,
                            max(0, $latency),
                            $partial,
                            $completedOptionalWalkRoots,
                        );
                    }

                    $lastStatus = $this->failureStatus((string) $session->getError());
                } catch (SnmpWalkLimitExceeded) {
                    return SnmpTransportResult::failure('walk_limit_exceeded');
                } catch (Throwable $exception) {
                    $lastStatus = $this->failureStatus($exception->getMessage());
                } finally {
                    if ($session instanceof \SNMP) {
                        @$session->close();
                    }
                }
            }

            return SnmpTransportResult::failure($lastStatus);
        } finally {
            $this->clear($material);
        }
    }

    /** @param array<string, scalar|null> $material */
    private function session(
        string $address,
        AuthorizedProbeTarget $target,
        string $version,
        array $material,
    ): \SNMP {
        $host = str_contains($address, ':') ? "udp6:[{$address}]:{$target->port}" : "udp:{$address}:{$target->port}";
        $timeout = max(1, $target->responseTimeoutSeconds) * 1_000_000;
        $snmpVersion = match ($version) {
            'v1' => \SNMP::VERSION_1,
            'v2c' => \SNMP::VERSION_2C,
            'v3' => \SNMP::VERSION_3,
            default => throw new RuntimeException('SNMP version is invalid.'),
        };
        $identity = $version === 'v3'
            ? (string) $material['security_name']
            : (string) $material['community'];
        $session = new \SNMP($snmpVersion, $host, $identity, $timeout, 1);
        $session->valueretrieval = SNMP_VALUE_PLAIN;
        $session->quick_print = true;

        if ($version === 'v3') {
            $session->setSecurity(
                'authPriv',
                $this->extensionAuthProtocol((string) $material['auth_protocol']),
                (string) $material['auth_secret'],
                $this->extensionPrivacyProtocol((string) $material['privacy_protocol']),
                (string) $material['privacy_secret'],
            );
        }

        return $session;
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $varbinds
     * @param  array<mixed, mixed>  $incoming
     */
    private function append(
        array &$varbinds,
        int &$responseBytes,
        array $incoming,
        SnmpQuery $query,
        int $maxResponseBytes,
    ): void {
        foreach ($incoming as $oid => $value) {
            $normalisedOid = ltrim((string) $oid, '.');
            if (preg_match('/^\d+(?:\.\d+)+$/', $normalisedOid) !== 1) {
                continue;
            }
            if (! array_key_exists($normalisedOid, $varbinds)
                && count($varbinds) >= $query->maxVarbinds) {
                throw new SnmpWalkLimitExceeded;
            }

            $bounded = $this->boundedValue($value);
            $prospectiveBytes = strlen($normalisedOid)
                + (is_string($bounded) ? strlen($bounded) : 32)
                + 16;
            $replacedBytes = 0;
            if (array_key_exists($normalisedOid, $varbinds)) {
                $existingValue = $varbinds[$normalisedOid];
                $replacedBytes = strlen($normalisedOid)
                    + (is_string($existingValue) ? strlen($existingValue) : 32)
                    + 16;
            }
            if ($responseBytes - $replacedBytes + $prospectiveBytes > $maxResponseBytes) {
                throw new SnmpWalkLimitExceeded;
            }

            $varbinds[$normalisedOid] = $bounded;
            $responseBytes = $responseBytes - $replacedBytes + $prospectiveBytes;
        }
    }

    private function boundedValue(mixed $value): int|float|string|bool|null
    {
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        $value = (string) $value;
        if (strlen($value) > 2048) {
            throw new SnmpWalkLimitExceeded;
        }
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            if (strlen($value) > 64) {
                throw new SnmpWalkLimitExceeded;
            }

            return 'hex:'.bin2hex($value);
        }

        return trim($value);
    }

    /** @param array<string, scalar|null> $material */
    private function validateMaterial(string $version, array $material): void
    {
        if ($version === 'v3') {
            $securityName = $material['security_name'] ?? null;
            $authProtocol = strtoupper((string) ($material['auth_protocol'] ?? ''));
            $authSecret = $material['auth_secret'] ?? null;
            $privacyProtocol = strtoupper((string) ($material['privacy_protocol'] ?? ''));
            $privacySecret = $material['privacy_secret'] ?? null;

            if (! is_string($securityName) || $securityName === '' || strlen($securityName) > 64
                || ! in_array($authProtocol, ['SHA', 'SHA1', 'SHA224', 'SHA256', 'SHA384', 'SHA512'], true)
                || ! is_string($authSecret) || strlen($authSecret) < 8 || strlen($authSecret) > 1024
                || ! in_array($privacyProtocol, ['AES', 'AES128', 'AES192', 'AES256'], true)
                || ! is_string($privacySecret) || strlen($privacySecret) < 8 || strlen($privacySecret) > 1024) {
                throw new RuntimeException('SNMPv3 authPriv credential material is invalid.');
            }

            return;
        }

        $community = $material['community'] ?? null;
        if (! is_string($community) || $community === '' || strlen($community) > 255) {
            throw new RuntimeException('SNMP compatibility credential material is invalid.');
        }
    }

    private function extensionAuthProtocol(string $protocol): string
    {
        return match (strtoupper($protocol)) {
            'SHA', 'SHA1' => 'SHA',
            'SHA224' => 'SHA-224',
            'SHA256' => 'SHA-256',
            'SHA384' => 'SHA-384',
            'SHA512' => 'SHA-512',
            default => throw new RuntimeException('SNMPv3 authentication protocol is unsupported.'),
        };
    }

    private function extensionPrivacyProtocol(string $protocol): string
    {
        return match (strtoupper($protocol)) {
            'AES', 'AES128' => 'AES',
            'AES192' => 'AES192',
            'AES256' => 'AES256',
            default => throw new RuntimeException('SNMPv3 privacy protocol is unsupported.'),
        };
    }

    private function failureStatus(string $message): string
    {
        $message = strtolower($message);

        return match (true) {
            str_contains($message, 'authentication'), str_contains($message, 'unknown user') => 'authentication_failed',
            str_contains($message, 'privacy'), str_contains($message, 'decrypt') => 'privacy_failed',
            str_contains($message, 'extension'), str_contains($message, 'unsupported') => 'transport_unavailable',
            default => 'timeout',
        };
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
}

final class SnmpWalkLimitExceeded extends RuntimeException {}
