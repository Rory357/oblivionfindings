<?php

namespace App\Domain\Monitoring\Protocols\Syslog;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

final class SyslogDecoder
{
    public const int MAX_DATAGRAM_BYTES = 8192;

    public const int MAX_MESSAGE_BYTES = 4096;

    private const int MAX_FUTURE_SKEW_SECONDS = 300;

    private const int MAX_PAST_SKEW_SECONDS = 86_400;

    public function decode(string $datagram, ?CarbonInterface $receivedAt = null): SyslogMessage
    {
        if ($datagram === '' || strlen($datagram) > self::MAX_DATAGRAM_BYTES) {
            throw new RuntimeException('Syslog datagram exceeds the configured limit.');
        }
        $rawHash = hash('sha256', $datagram);
        $receivedAt = CarbonImmutable::instance($receivedAt ?? CarbonImmutable::now('UTC'))->utc();
        $text = mb_scrub($datagram, 'UTF-8');

        if (preg_match('/^<(\d{1,3})>(\d{1,3}) ([^ ]+) ([^ ]+) ([^ ]+) ([^ ]+) ([^ ]+) (.*)$/s', $text, $matches) === 1) {
            return $this->rfc5424($matches, $receivedAt, $rawHash);
        }
        if (preg_match('/^<(\d{1,3})>([A-Z][a-z]{2}\s+[ 0-9]\d\s+\d{2}:\d{2}:\d{2})\s+(\S+)\s+([^:\s\[]+)(?:\[([^\]]+)\])?:\s?(.*)$/s', $text, $matches) === 1) {
            return $this->rfc3164($matches, $receivedAt, $rawHash);
        }

        throw new RuntimeException('Syslog datagram format is unsupported.');
    }

    /** @param array<int, string> $matches */
    private function rfc5424(array $matches, CarbonImmutable $receivedAt, string $rawHash): SyslogMessage
    {
        [$facility, $severity] = $this->priority($matches[1]);
        if ($matches[2] !== '1') {
            throw new RuntimeException('Syslog RFC5424 version is unsupported.');
        }
        try {
            $occurredAt = CarbonImmutable::parse($matches[3])->utc();
        } catch (Throwable $exception) {
            throw new RuntimeException('Syslog timestamp is invalid.', previous: $exception);
        }
        $this->validateTimestamp($occurredAt, $receivedAt);
        [$structuredData, $message] = $this->structuredDataAndMessage($matches[8]);

        return new SyslogMessage(
            format: 'rfc5424',
            facility: $facility,
            severityCode: $severity,
            occurredAt: $occurredAt,
            hostname: $this->headerValue($matches[4], 255),
            app: $this->headerValue($matches[5], 48),
            processId: $this->headerValue($matches[6], 128),
            messageId: $this->headerValue($matches[7], 32),
            structuredData: $structuredData,
            message: $this->message($message),
            rawHash: $rawHash,
        );
    }

    /** @param array<int, string> $matches */
    private function rfc3164(array $matches, CarbonImmutable $receivedAt, string $rawHash): SyslogMessage
    {
        [$facility, $severity] = $this->priority($matches[1]);
        $timestamp = preg_replace('/\s+/', ' ', trim($matches[2]));
        try {
            $occurredAt = CarbonImmutable::createFromFormat(
                'Y M j H:i:s',
                $receivedAt->year.' '.$timestamp,
                $receivedAt->timezone,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Syslog timestamp is invalid.', previous: $exception);
        }
        if (! $occurredAt instanceof CarbonImmutable) {
            throw new RuntimeException('Syslog timestamp is invalid.');
        }
        if ($occurredAt->greaterThan($receivedAt->addMonths(6))) {
            $occurredAt = $occurredAt->subYear();
        } elseif ($occurredAt->lessThan($receivedAt->subMonths(6))) {
            $occurredAt = $occurredAt->addYear();
        }
        $occurredAt = $occurredAt->utc();
        $this->validateTimestamp($occurredAt, $receivedAt);

        return new SyslogMessage(
            format: 'rfc3164',
            facility: $facility,
            severityCode: $severity,
            occurredAt: $occurredAt,
            hostname: $this->headerValue($matches[3], 255),
            app: $this->headerValue($matches[4], 48),
            processId: $this->headerValue($matches[5] ?? '-', 128),
            messageId: null,
            structuredData: [],
            message: $this->message($matches[6]),
            rawHash: $rawHash,
        );
    }

    /** @return array{int, int} */
    private function priority(string $value): array
    {
        $priority = ctype_digit($value) ? (int) $value : -1;
        if ($priority < 0 || $priority > 191) {
            throw new RuntimeException('Syslog priority is invalid.');
        }

        return [intdiv($priority, 8), $priority % 8];
    }

    private function validateTimestamp(CarbonImmutable $occurredAt, CarbonImmutable $receivedAt): void
    {
        if ($occurredAt->greaterThan($receivedAt->addSeconds(self::MAX_FUTURE_SKEW_SECONDS))
            || $occurredAt->lessThan($receivedAt->subSeconds(self::MAX_PAST_SKEW_SECONDS))) {
            throw new RuntimeException('Syslog timestamp is outside the accepted window.');
        }
    }

    private function headerValue(string $value, int $maximum): ?string
    {
        if ($value === '-') {
            return null;
        }
        $value = mb_scrub($value, 'UTF-8');
        if ($value === '' || strlen($value) > $maximum
            || preg_match('/[\x00-\x20\x7f]/u', $value) === 1) {
            throw new RuntimeException('Syslog header field is invalid.');
        }

        return $value;
    }

    /** @return array{array<string, array<string, string>>, string} */
    private function structuredDataAndMessage(string $value): array
    {
        if ($value === '-') {
            return [[], ''];
        }
        if (str_starts_with($value, '- ')) {
            return [[], substr($value, 2)];
        }
        if (! str_starts_with($value, '[')) {
            throw new RuntimeException('Syslog structured data is invalid.');
        }

        $elements = [];
        $offset = 0;
        $length = strlen($value);
        while ($offset < $length && $value[$offset] === '[') {
            if (count($elements) >= 32) {
                throw new RuntimeException('Syslog structured data exceeds the element limit.');
            }
            $end = $this->structuredDataEnd($value, $offset + 1);
            $content = substr($value, $offset + 1, $end - $offset - 1);
            [$id, $parameters] = $this->structuredDataElement($content);
            if (array_key_exists($id, $elements)) {
                throw new RuntimeException('Syslog structured data element is duplicated.');
            }
            $elements[$id] = $parameters;
            $offset = $end + 1;
        }
        ksort($elements, SORT_STRING);
        $message = substr($value, $offset);
        if (str_starts_with($message, ' ')) {
            $message = substr($message, 1);
        } elseif ($message !== '') {
            throw new RuntimeException('Syslog structured data delimiter is invalid.');
        }

        return [$elements, $message];
    }

    private function structuredDataEnd(string $value, int $offset): int
    {
        $escaped = false;
        $length = strlen($value);
        for ($index = $offset; $index < $length; $index++) {
            $character = $value[$index];
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($character === '\\') {
                $escaped = true;

                continue;
            }
            if ($character === ']') {
                return $index;
            }
        }

        throw new RuntimeException('Syslog structured data is incomplete.');
    }

    /** @return array{string, array<string, string>} */
    private function structuredDataElement(string $content): array
    {
        if (preg_match('/^([A-Za-z0-9@_.-]{1,32})(.*)$/s', $content, $matches) !== 1) {
            throw new RuntimeException('Syslog structured data identifier is invalid.');
        }
        $id = $matches[1];
        $remaining = $matches[2];
        $parameters = [];
        while ($remaining !== '') {
            if (count($parameters) >= 64
                || preg_match('/^ ([A-Za-z0-9@_.-]{1,32})="((?:[^"\\\\]|\\\\.)*)"(.*)$/s', $remaining, $parameter) !== 1) {
                throw new RuntimeException('Syslog structured data parameter is invalid.');
            }
            $name = $parameter[1];
            $decoded = preg_replace('/\\\\([\\\\"\]])/', '$1', $parameter[2]);
            if (! is_string($decoded) || strlen($decoded) > 255 || array_key_exists($name, $parameters)) {
                throw new RuntimeException('Syslog structured data parameter is invalid.');
            }
            $parameters[$name] = $this->message($decoded, 255);
            $remaining = $parameter[3];
        }
        ksort($parameters, SORT_STRING);

        return [$id, $parameters];
    }

    private function message(string $value, int $maximum = self::MAX_MESSAGE_BYTES): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '';
        $value = trim($value);
        if (strlen($value) > $maximum) {
            $value = mb_strcut($value, 0, $maximum, 'UTF-8');
        }

        return $value;
    }
}
