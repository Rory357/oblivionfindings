<?php

namespace App\Domain\Monitoring\Services;

use InvalidArgumentException;

final class CidrMatcher
{
    /**
     * Expand a CIDR without materialising it and jump over excluded address ranges.
     *
     * @param  list<string>  $exclusions
     * @return iterable<string>
     */
    public function expand(string $cidr, int $limit, array $exclusions = []): iterable
    {
        if ($limit < 0 || $limit > 65536) {
            throw new InvalidArgumentException('CIDR expansion limit is invalid.');
        }
        if ($limit === 0) {
            return;
        }

        [$start, $end] = $this->cidrRange($cidr);
        $excludedRanges = [];
        foreach ($exclusions as $exclusion) {
            if (! is_string($exclusion) || trim($exclusion) === '') {
                continue;
            }
            try {
                if (str_contains($exclusion, '/')) {
                    $range = $this->cidrRange(trim($exclusion));
                } else {
                    $address = $this->packedAddress(trim($exclusion));
                    $range = [$address, $address];
                }
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($range !== null && strlen($range[0]) === strlen($start)) {
                $excludedRanges[] = $range;
            }
        }

        $cursor = $start;
        $yielded = 0;
        while ($cursor !== null && strcmp($cursor, $end) <= 0 && $yielded < $limit) {
            $skipTo = null;
            foreach ($excludedRanges as [$excludedStart, $excludedEnd]) {
                if (strcmp($cursor, $excludedStart) >= 0 && strcmp($cursor, $excludedEnd) <= 0
                    && ($skipTo === null || strcmp($excludedEnd, $skipTo) > 0)) {
                    $skipTo = $excludedEnd;
                }
            }
            if ($skipTo !== null) {
                $cursor = $this->increment($skipTo);

                continue;
            }

            yield strtolower((string) inet_ntop($cursor));
            $yielded++;
            $cursor = $this->increment($cursor);
        }
    }

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

    /** @return array{string, string} */
    private function cidrRange(string $cidr): array
    {
        [$network, $prefix] = $this->parseCidr($cidr);

        return [
            $this->masked($network, $prefix, false),
            $this->masked($network, $prefix, true),
        ];
    }

    private function increment(string $address): ?string
    {
        for ($index = strlen($address) - 1; $index >= 0; $index--) {
            $value = ord($address[$index]);
            if ($value < 255) {
                $address[$index] = chr($value + 1);

                return $address;
            }
            $address[$index] = "\0";
        }

        return null;
    }
}
