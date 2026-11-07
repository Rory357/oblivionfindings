<?php

namespace Oblivion\Collector\NetworkChecks;

use Closure;
use Symfony\Component\Process\Process;

/**
 * S&D Phase I (pop-up 97): the delay check. Ten echo requests from the hub to one numeric address,
 * with the system ping and a fixed argument list (no shell), bounded to 20 seconds. Only replies
 * that really came from that address count. "measured" is false when ping didn't run or its own
 * summary line isn't there (missing ping, no permission, another language, timed out): then the
 * check is "Not available", never "10 of 10 lost".
 */
final class DelayProbe
{
    public const int COUNT = 10;

    /** @param (Closure(list<string>, int): string)|null $runner test seam: argv and timeout in, stdout out */
    public function __construct(private readonly ?Closure $runner = null) {}

    /** @return array{sent: int, received: int, min_ms: ?float, median_ms: ?float, max_ms: ?float, measured: bool} */
    public function measure(string $address, int $timeoutSeconds = 20): array
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return ['sent' => self::COUNT, 'received' => 0, 'min_ms' => null, 'median_ms' => null, 'max_ms' => null, 'measured' => false];
        }
        $ipv6 = str_contains($address, ':');
        $argv = PHP_OS_FAMILY === 'Windows'
            ? ['ping', $ipv6 ? '-6' : '-4', '-n', (string) self::COUNT, '-w', '1000', $address]
            : ['ping', ...($ipv6 ? ['-6'] : []), '-n', '-c', (string) self::COUNT, '-W', '1', '-i', '0.5', $address];
        $timeout = max(5, min(20, $timeoutSeconds));
        if ($this->runner !== null) {
            $output = ($this->runner)($argv, $timeout);
        } else {
            $process = new Process($argv);
            $process->setTimeout($timeout);
            try {
                $process->run();
                $output = $process->getOutput();
            } catch (\Throwable) {
                $output = '';
            }
        }

        return self::parse($address, substr($output, 0, 65_536));
    }

    /** @return array{sent: int, received: int, min_ms: ?float, median_ms: ?float, max_ms: ?float, measured: bool} */
    public static function parse(string $address, string $output): array
    {
        // ping's own summary: "10 packets transmitted, 0 received" (Linux, BSD) or "Packets: Sent = 10, Received = 0" (Windows).
        $summary = preg_match('/\b\d+\s+packets?\s+transmitted,\s*\d+\s+(?:packets?\s+)?received\b|\bPackets:\s*Sent\s*=\s*\d+,\s*Received\s*=\s*\d+/i', $output) === 1;
        $target = @inet_pton(trim($address, '[]'));
        $times = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $from = null;
            $time = null;
            if (preg_match('/^\s*Reply from\s+(.+?):\s*(?:bytes=\d+\s+)?time([=<])\s*([0-9]+(?:\.[0-9]+)?)\s*ms\b/i', $line, $match) === 1) {
                // "time<1ms": recorded as 1 ms, the bound ping gives, never a smaller number nobody measured.
                [$from, $time] = [$match[1], $match[2] === '<' ? max(1.0, (float) $match[3]) : (float) $match[3]];
            } elseif (preg_match('/^\s*\d+\s+bytes from\s+(.+?):\s+icmp_[sr]eq=\d+\b.*?\btime([=<])\s*([0-9]+(?:\.[0-9]+)?)\s*ms\b/i', $line, $match) === 1) {
                [$from, $time] = [$match[1], (float) $match[3]];
            }
            if ($from === null || $target === false) {
                continue;
            }
            $from = trim($from);
            if (preg_match('/\(([^()]+)\)\s*$/', $from, $inner) === 1) {
                $from = $inner[1];
            }
            $from = trim(explode('%', trim($from, '[]'))[0]);
            if (@inet_pton($from) === $target && count($times) < self::COUNT) {
                $times[] = min(60_000.0, $time);
            }
        }
        sort($times);
        $count = count($times);

        return [
            'sent' => self::COUNT,
            'received' => $count,
            'min_ms' => $count > 0 ? round($times[0], 1) : null,
            'median_ms' => $count > 0 ? round($count % 2 === 1 ? $times[intdiv($count, 2)] : ($times[$count / 2 - 1] + $times[$count / 2]) / 2, 1) : null,
            'max_ms' => $count > 0 ? round($times[$count - 1], 1) : null,
            'measured' => $count > 0 || $summary,
        ];
    }
}
