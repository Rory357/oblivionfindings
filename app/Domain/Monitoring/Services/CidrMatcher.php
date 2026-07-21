<?php

namespace App\Domain\Monitoring\Services;

use InvalidArgumentException;

final class CidrMatcher
{
    public function contains(string $cidr, string $address): bool
    {
        [$network, $prefix] = $this->parseCidr($cidr);
        $candidate = $this->packedAddress($address);

        if (strlen($network) !== strlen($candidate)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && ! hash_equals(substr($network, 0, $wholeBytes), substr($candidate, 0, $wholeBytes))) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($network[$wholeBytes]) & $mask) === (ord($candidate[$wholeBytes]) & $mask);
    }

    public function canonicalAddress(string $address): string
    {
        $packed = $this->packedAddress($address);

        return strtolower((string) inet_ntop($packed));
    }

    public function assertValidCidr(string $cidr): void
    {
        $this->parseCidr($cidr);
    }

    public function isIpv4NetworkOrBroadcast(string $cidr, string $address): bool
    {
        [$network, $prefix] = $this->parseCidr($cidr);
        $candidate = $this->packedAddress($address);

        if (strlen($network) !== 4 || strlen($candidate) !== 4 || $prefix > 30 || ! $this->contains($cidr, $address)) {
            return false;
        }

        $maskedNetwork = $this->masked($network, $prefix, false);
        $broadcast = $this->masked($network, $prefix, true);

        return hash_equals($candidate, $maskedNetwork) || hash_equals($candidate, $broadcast);
    }

    /** @return array{string, int} */
    private function parseCidr(string $cidr): array
    {
        if (substr_count($cidr, '/') !== 1) {
            throw new InvalidArgumentException('CIDR is invalid.');
        }

        [$address, $prefixText] = explode('/', $cidr, 2);
        if ($prefixText === '' || preg_match('/^(0|[1-9][0-9]*)$/', $prefixText) !== 1) {
            throw new InvalidArgumentException('CIDR is invalid.');
        }

        $packed = $this->rawPackedAddress($address);
        $prefix = (int) $prefixText;

        if ($this->isMappedIpv4($packed)) {
            if ($prefix < 96 || $prefix > 128) {
                throw new InvalidArgumentException('Mapped IPv4 CIDR is invalid.');
            }

            $packed = substr($packed, 12);
            $prefix -= 96;
        }

        $maximum = strlen($packed) * 8;
        if ($prefix > $maximum) {
            throw new InvalidArgumentException('CIDR prefix is invalid.');
        }

        return [$packed, $prefix];
    }

    private function packedAddress(string $address): string
    {
        $packed = $this->rawPackedAddress($address);

        return $this->isMappedIpv4($packed) ? substr($packed, 12) : $packed;
    }

    private function rawPackedAddress(string $address): string
    {
        if ($address === '' || str_contains($address, '%')) {
            throw new InvalidArgumentException('IP address is invalid.');
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException('IP address is invalid.');
        }

        return $packed;
    }

    private function isMappedIpv4(string $packed): bool
    {
        return strlen($packed) === 16
            && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff";
    }

    private function masked(string $network, int $prefix, bool $hostBits): string
    {
        $result = '';

        for ($index = 0; $index < strlen($network); $index++) {
            $remaining = $prefix - ($index * 8);
            $mask = $remaining >= 8
                ? 0xFF
                : ($remaining <= 0 ? 0 : (0xFF << (8 - $remaining)) & 0xFF);
            $value = ord($network[$index]) & $mask;

            if ($hostBits) {
                $value |= (~$mask) & 0xFF;
            }

            $result .= chr($value);
        }

        return $result;
    }
}
