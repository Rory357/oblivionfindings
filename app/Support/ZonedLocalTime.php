<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/** Clock conversion only: callers own policy, scope and authorisation. */
final class ZonedLocalTime
{
    /** @return list<CarbonImmutable> Exact UTC instants, ordered first to last. */
    public function candidates(string $local, string $timezone): array
    {
        [$wall, $nominal] = $this->wall($local);
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('invalid_timezone');
        }
        $zone = new DateTimeZone($timezone);
        $offsets = array_unique(array_column($zone->getTransitions($nominal->getTimestamp() - 172800, $nominal->getTimestamp() + 172800) ?: [], 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $nominal->modify(($offset >= 0 ? '-' : '+').abs($offset).' seconds');
            if ($candidate->setTimezone($zone)->format('Y-m-d\TH:i:s.u') === $wall) {
                $matches[$candidate->format('U.u')] = CarbonImmutable::instance($candidate)->utc();
            }
        }
        $matches = array_values($matches);
        usort($matches, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $matches;
    }

    public function parse(DateTimeInterface|string $value, string $timezone, string $overlap = 'reject'): CarbonImmutable
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('invalid_timezone');
        }
        if (! in_array($overlap, ['reject', 'earlier', 'later'], true)) {
            throw new InvalidArgumentException('invalid_overlap_policy');
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (! preg_match('/^(?<local>\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)(?<offset>Z|[+-]\d{2}:\d{2})?$/D', $value, $parts)) {
            throw new InvalidArgumentException('invalid_local_time');
        }
        [$wall] = $this->wall($parts['local']);
        $offset = $parts['offset'] ?? '';
        if ($offset === 'Z') {
            return CarbonImmutable::instance(new DateTimeImmutable($wall.'Z'))->utc();
        }
        $candidates = $this->candidates($parts['local'], $timezone);
        if ($offset !== '') {
            try {
                $chosen = new DateTimeImmutable($wall.$offset);
            } catch (\Exception) {
                throw new InvalidArgumentException('invalid_local_offset');
            }
            $candidates = array_values(array_filter($candidates, fn (CarbonImmutable $candidate): bool => $candidate->format('U.u') === $chosen->format('U.u')));
            if ($candidates === []) {
                throw new InvalidArgumentException('invalid_local_offset');
            }
        }
        if ($candidates === []) {
            throw new InvalidArgumentException('nonexistent_local_time');
        }
        if (count($candidates) > 1 && $overlap === 'reject') {
            throw new InvalidArgumentException('ambiguous_local_time');
        }

        return $overlap === 'later' ? $candidates[array_key_last($candidates)] : $candidates[0];
    }

    /** @return array{string, DateTimeImmutable} */
    private function wall(string $value): array
    {
        if (! preg_match('/^(?<minute>\d{4}-\d{2}-\d{2})[T ](?<time>\d{2}:\d{2})(?::(?<second>\d{2})(?:\.(?<fraction>\d{1,6}))?)?$/D', $value, $parts)) {
            throw new InvalidArgumentException('invalid_local_time');
        }
        $wall = $parts['minute'].'T'.$parts['time'].':'.(($parts['second'] ?? '') !== '' ? $parts['second'] : '00').'.'.str_pad($parts['fraction'] ?? '', 6, '0');
        $nominal = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u', $wall, new DateTimeZone('UTC'));
        if ($nominal === false || $nominal->format('Y-m-d\TH:i:s.u') !== $wall) {
            throw new InvalidArgumentException('invalid_local_time');
        }

        return [$wall, $nominal];
    }
}
