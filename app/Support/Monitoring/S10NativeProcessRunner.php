<?php

namespace App\Support\Monitoring;

use InvalidArgumentException;
use Throwable;

final class S10NativeProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return array{successful: bool, stdout: string, stderr: string}
     */
    public function run(
        array $command,
        ?string $workingDirectory,
        array $environment,
        int $timeoutSeconds,
        int $maximumOutputBytes = 65_536,
        string $standardInput = '',
    ): array {
        if ($command === []
            || array_any($command, static fn (mixed $part): bool => ! is_string($part) || str_contains($part, "\0"))
            || $timeoutSeconds < 1
            || $maximumOutputBytes < 1
            || strlen($standardInput) > 65_536) {
            throw new InvalidArgumentException('The native process contract is invalid.');
        }

        $process = null;
        $pipes = [];

        try {
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $workingDirectory,
                $environment,
                [
                    'bypass_shell' => true,
                    'suppress_errors' => true,
                ],
            );
            if (! is_resource($process)
                || ! isset($pipes[0], $pipes[1], $pipes[2])
                || ! is_resource($pipes[0])
                || ! is_resource($pipes[1])
                || ! is_resource($pipes[2])) {
                return $this->failed();
            }

            $inputOffset = 0;
            $inputLength = strlen($standardInput);
            while ($inputOffset < $inputLength) {
                $written = fwrite($pipes[0], substr($standardInput, $inputOffset));
                if ($written === false || $written === 0) {
                    return $this->failed();
                }
                $inputOffset += $written;
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $exitCode = null;
            $deadline = microtime(true) + $timeoutSeconds;

            while (true) {
                $read = array_values(array_filter(
                    [$pipes[1], $pipes[2]],
                    static fn ($pipe): bool => is_resource($pipe) && ! feof($pipe),
                ));
                if ($read !== []) {
                    $write = null;
                    $except = null;
                    @stream_select($read, $write, $except, 0, 200_000);

                    foreach ($read as $pipe) {
                        $chunk = fread($pipe, 8192);
                        if (! is_string($chunk)) {
                            return $this->failed();
                        }
                        if ($pipe === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                        if (strlen($stdout) > $maximumOutputBytes
                            || strlen($stderr) > $maximumOutputBytes) {
                            @proc_terminate($process);

                            return $this->failed();
                        }
                    }
                } else {
                    usleep(10_000);
                }

                $status = proc_get_status($process);
                if (! is_array($status)) {
                    return $this->failed();
                }
                if (($status['running'] ?? false) !== true) {
                    $exitCode = $status['exitcode'] ?? null;

                    break;
                }
                if (microtime(true) >= $deadline) {
                    @proc_terminate($process);

                    return $this->failed();
                }
            }

            $remainingOutput = stream_get_contents($pipes[1]);
            $remainingError = stream_get_contents($pipes[2]);
            if (! is_string($remainingOutput) || ! is_string($remainingError)) {
                return $this->failed();
            }
            $stdout .= $remainingOutput;
            $stderr .= $remainingError;
            if (strlen($stdout) > $maximumOutputBytes || strlen($stderr) > $maximumOutputBytes) {
                return $this->failed();
            }

            return [
                'successful' => $exitCode === 0,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (Throwable) {
            return $this->failed();
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }

    /** @return array{successful: false, stdout: string, stderr: string} */
    private function failed(): array
    {
        return [
            'successful' => false,
            'stdout' => '',
            'stderr' => '',
        ];
    }
}
