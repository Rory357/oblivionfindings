<?php

namespace App\Support\Monitoring;

use Symfony\Component\Process\Process;
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
        try {
            $process = new Process(
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
                null,
                $this->gitProcessEnvironment(),
            );
            $process->setTimeout(10);
            $process->run();

            if (! $process->isSuccessful() || trim($process->getErrorOutput()) !== '') {
                return null;
            }

            return trim($process->getOutput());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * False values tell Symfony Process not to inherit ambient Git controls.
     * The release gate must inspect only the repository selected by -C.
     *
     * @return array<string, string|false>
     */
    private function gitProcessEnvironment(): array
    {
        $environment = [];
        $ambient = getenv();
        if (is_array($ambient)) {
            foreach (array_keys($ambient) as $key) {
                if (is_string($key) && str_starts_with(strtoupper($key), 'GIT_')) {
                    $environment[$key] = false;
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
            $environment[$key] = false;
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
