<?php

namespace App\Support\Monitoring;

use Throwable;

final class LoadSoakReleaseCheckoutVerifier
{
    public function __construct(
        private readonly string $gitBinary = '/usr/bin/git',
    ) {}

    public function verify(string $checkout, string $expectedRevision): bool
    {
        if (preg_match('/\A[0-9a-f]{40}\z/', $expectedRevision) !== 1) {
            return false;
        }

        $resolvedCheckout = realpath($checkout);
        if (! is_string($resolvedCheckout) || ! is_dir($resolvedCheckout)) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Linux' && ! $this->protectedGitBinary()) {
            return false;
        }

        $inside = $this->git($resolvedCheckout, ['rev-parse', '--is-inside-work-tree']);
        $topLevel = $this->git($resolvedCheckout, ['rev-parse', '--show-toplevel']);
        $head = $this->git($resolvedCheckout, ['rev-parse', '--verify', 'HEAD']);
        $originMain = $this->git($resolvedCheckout, ['rev-parse', '--verify', 'refs/remotes/origin/main']);
        $status = $this->git($resolvedCheckout, ['status', '--porcelain=v1', '--untracked-files=all']);

        $resolvedTopLevel = is_string($topLevel) ? realpath($topLevel) : false;

        return $inside === 'true'
            && is_string($resolvedTopLevel)
            && $this->samePath($resolvedCheckout, $resolvedTopLevel)
            && $head === $expectedRevision
            && $originMain === $expectedRevision
            && $status === '';
    }

    /** @param list<string> $arguments */
    private function git(string $checkout, array $arguments): ?string
    {
        $process = null;
        $pipes = [];

        try {
            $process = proc_open(
                [
                    $this->gitBinary,
                    '--no-optional-locks',
                    '-c',
                    'core.fsmonitor=false',
                    '-c',
                    'core.untrackedCache=false',
                    '-C',
                    $checkout,
                    ...$arguments,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                $this->gitProcessEnvironment(),
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
                return null;
            }

            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $exitCode = null;
            $deadline = microtime(true) + 10;

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
                            return null;
                        }
                        if ($pipe === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                } else {
                    usleep(10_000);
                }

                $status = proc_get_status($process);
                if (! is_array($status)) {
                    return null;
                }
                if (($status['running'] ?? false) !== true) {
                    $exitCode = $status['exitcode'] ?? null;

                    break;
                }
                if (microtime(true) >= $deadline) {
                    @proc_terminate($process);

                    return null;
                }
            }

            $remainingOutput = stream_get_contents($pipes[1]);
            $remainingError = stream_get_contents($pipes[2]);
            if (! is_string($remainingOutput) || ! is_string($remainingError)) {
                return null;
            }
            $stdout .= $remainingOutput;
            $stderr .= $remainingError;

            if ($exitCode !== 0 || trim($stderr) !== '') {
                return null;
            }

            return trim($stdout);
        } catch (Throwable) {
            return null;
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

    /**
     * proc_open receives a complete environment, so ambient Git controls are
     * removed before the fixed binary inspects the repository selected by -C.
     *
     * @return array<string, string|false>
     */
    private function gitProcessEnvironment(): array
    {
        $environment = [];
        $ambient = getenv();
        if (is_array($ambient)) {
            foreach ($ambient as $key => $value) {
                if (is_string($key)
                    && is_string($value)
                    && ! str_starts_with(strtoupper($key), 'GIT_')) {
                    $environment[$key] = $value;
                }
            }
        }

        foreach ([
            'GIT_ALTERNATE_OBJECT_DIRECTORIES',
            'GIT_COMMON_DIR',
            'GIT_CONFIG',
            'GIT_CONFIG_COUNT',
            'GIT_CONFIG_GLOBAL',
            'GIT_CONFIG_KEY_0',
            'GIT_CONFIG_NOSYSTEM',
            'GIT_CONFIG_SYSTEM',
            'GIT_CONFIG_VALUE_0',
            'GIT_DIR',
            'GIT_INDEX_FILE',
            'GIT_OBJECT_DIRECTORY',
            'GIT_REPLACE_REF_BASE',
            'GIT_SHALLOW_FILE',
            'GIT_WORK_TREE',
        ] as $key) {
            unset($environment[$key]);
        }
        $environment['GIT_OPTIONAL_LOCKS'] = '0';

        return $environment;
    }

    private function samePath(string $left, string $right): bool
    {
        $normalise = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($normalise($left), $normalise($right)) === 0
            : $normalise($left) === $normalise($right);
    }

    private function protectedGitBinary(): bool
    {
        if ($this->gitBinary !== '/usr/bin/git'
            || is_link($this->gitBinary)
            || ! is_file($this->gitBinary)
            || ! is_executable($this->gitBinary)) {
            return false;
        }

        $metadata = @lstat($this->gitBinary);
        $mode = is_array($metadata) ? ($metadata['mode'] ?? null) : null;

        return is_int($mode)
            && ($mode & 0170000) === 0100000
            && ($mode & 0022) === 0
            && ($metadata['uid'] ?? null) === 0;
    }
}
