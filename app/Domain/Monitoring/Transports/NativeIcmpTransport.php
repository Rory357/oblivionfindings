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
            $ipv6 = str_contains($address, ':');
            $command = PHP_OS_FAMILY === 'Windows'
                ? ['ping', ...($ipv6 ? ['-6'] : ['-4']), '-n', '1', '-w', (string) ($target->responseTimeoutSeconds * 1000), $address]
                : ['ping', ...($ipv6 ? ['-6'] : []), '-n', '-c', '1', '-W', (string) $target->responseTimeoutSeconds, $address];
            $process = new Process($command);
            $process->setTimeout($target->responseTimeoutSeconds + 1);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                $lastReason = 'timeout';

                continue;
            }

            $output = $process->getOutput()."\n".$process->getErrorOutput();
            if (! $process->isSuccessful()) {
                continue;
            }

            $latency = preg_match('/time[=<]\s*([0-9]+(?:\.[0-9]+)?)\s*ms/i', $output, $match) === 1
                ? max(0, (int) round((float) $match[1]))
                : 0;

            return new IcmpTransportResult(true, $latency, 0, 'reply');
        }

        return new IcmpTransportResult(false, null, 100, $lastReason);
    }
}
