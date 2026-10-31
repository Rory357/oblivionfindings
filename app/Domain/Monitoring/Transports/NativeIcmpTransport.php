<?php

namespace App\Domain\Monitoring\Transports;

use App\Domain\Monitoring\Contracts\IcmpTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\IcmpTransportResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class NativeIcmpTransport implements IcmpTransport
{
    public function probe(AuthorizedProbeTarget $target): IcmpTransportResult
    {
        $lastReason = 'packet_loss';
        foreach ($target->addresses as $address) {
            $result = $this->probeAddress($address, $target->responseTimeoutSeconds);

            if ($result->reachable) {
                return $result;
            }

            $lastReason = $result->reasonCode;
        }

        return new IcmpTransportResult(false, null, 100.0, $lastReason);
    }

    public function probeLoopback(int $timeoutSeconds = 1): IcmpTransportResult
    {
        return $this->probeAddress('127.0.0.1', max(1, $timeoutSeconds));
    }

    private function probeAddress(string $address, int $timeoutSeconds): IcmpTransportResult
    {
        $ipv6 = str_contains($address, ':');
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['ping', ...($ipv6 ? ['-6'] : ['-4']), '-n', '1', '-w', (string) ($timeoutSeconds * 1000), $address]
            : ['ping', ...($ipv6 ? ['-6'] : []), '-n', '-c', '1', '-W', (string) $timeoutSeconds, $address];
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds + 1);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new IcmpTransportResult(false, null, 100.0, 'timeout');
        }

        return $this->parseOutput(
            $address,
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }

    public function parseOutput(string $targetAddress, int $exitCode, string $stdout, string $stderr = ''): IcmpTransportResult
    {
        $combined = trim($stdout."\n".$stderr);

        if ($this->isPermissionDenied($combined)) {
            return new IcmpTransportResult(false, null, 100.0, 'probe_unavailable');
        }

        if ($exitCode === 0) {
            $reply = $this->extractTargetEchoReply($targetAddress, $stdout);
            if ($reply !== null) {
                return new IcmpTransportResult(true, $reply, 0.0, 'reply');
            }
        }

        return new IcmpTransportResult(false, null, 100.0, $this->classifyFailureReason($combined));
    }

    private function extractTargetEchoReply(string $targetAddress, string $stdout): ?int
    {
        $targetBinary = $this->normalizeIpBinary($targetAddress);
        if ($targetBinary === null) {
            return null;
        }

        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $responder = null;
            $operator = '=';
            $latencyRaw = null;

            if (preg_match('/^\s*Reply from\s+(.+?):\s*(?:bytes=\d+\s+)?time([=<])\s*([0-9]+(?:\.[0-9]+)?)\s*ms\b/i', $line, $winMatch) === 1) {
                $responder = $winMatch[1];
                $operator = $winMatch[2];
                $latencyRaw = $winMatch[3];
            } elseif (preg_match('/^\s*\d+\s+bytes from\s+(.+?):\s+icmp_[sr]eq=\d+\b.*?\btime([=<])\s*([0-9]+(?:\.[0-9]+)?)\s*ms\b/i', $line, $unixMatch) === 1) {
                $responder = $unixMatch[1];
                $operator = $unixMatch[2];
                $latencyRaw = $unixMatch[3];
            }

            if ($responder === null || $latencyRaw === null) {
                continue;
            }

            if ($this->normalizeIpBinary($responder) !== $targetBinary) {
                continue;
            }

            if ($operator === '<' && (float) $latencyRaw <= 1.0) {
                return 0;
            }

            return max(0, (int) round((float) $latencyRaw));
        }

        return null;
    }

    private function normalizeIpBinary(string $rawAddress): ?string
    {
        $candidate = trim($rawAddress);
        if (preg_match('/\(([^()]+)\)\s*$/', $candidate, $parenMatch) === 1) {
            $candidate = trim($parenMatch[1]);
        }

        $candidate = trim($candidate, '[]');
        $zonePos = strpos($candidate, '%');
        if ($zonePos !== false) {
            $candidate = substr($candidate, 0, $zonePos);
        }

        $binary = @inet_pton($candidate);

        return $binary === false ? null : $binary;
    }

    private function isPermissionDenied(string $output): bool
    {
        return preg_match(
            '/\b(operation not permitted|permission denied|socket:\s*permission denied|socket type not supported|cap_net_raw|access is denied|must be run as root)\b/i',
            $output,
        ) === 1;
    }

    private function classifyFailureReason(string $output): string
    {
        if (preg_match('/\b(destination\s+(?:host|net|network|port|protocol)\s+unreachable|unreachable|ttl expired|time to live exceeded|general failure|transmit failed|no route to host)\b/i', $output) === 1) {
            return 'unreachable';
        }

        if (preg_match('/\b(request timed out|timed out|100(?:\.0+)?%\s*packet loss|0\s+received|0\s+packets\s+received)\b/i', $output) === 1) {
            return 'timeout';
        }

        return 'packet_loss';
    }
}
