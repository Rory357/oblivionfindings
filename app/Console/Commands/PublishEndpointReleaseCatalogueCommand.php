<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class PublishEndpointReleaseCatalogueCommand extends Command
{
    protected $signature = 'endpoint:publish-catalogue
        {source : Directory containing downloads.json and signed catalogue artifacts}
        {--public-key= : Override ENDPOINT_RELEASE_PUBLIC_KEY when verifying before copy}';

    protected $description = 'Verify a signed endpoint/site-hub release catalogue directory and publish it into storage';

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        $resolvedSource = realpath($source);
        if (! is_string($resolvedSource) || ! is_dir($resolvedSource) || is_link($source)) {
            $this->error('Source directory does not exist or is a symbolic link.');

            return self::FAILURE;
        }

        $publicKey = (string) ($this->option('public-key') ?: config('endpoint_management.release_public_key'));
        if ($publicKey === '') {
            $this->error('ENDPOINT_RELEASE_PUBLIC_KEY is not configured.');

            return self::FAILURE;
        }

        $minimumRevision = (int) config('endpoint_management.minimum_catalogue_revision', 1);
        $verifier = new EndpointReleaseCatalogue($resolvedSource, $publicKey, $minimumRevision);

        try {
            $catalogue = $verifier->catalogue();
            foreach ($catalogue['artifacts'] as $artifact) {
                $download = $verifier->download($artifact['id'], $artifact['sha256']);
                fclose($download['stream']);
            }
        } catch (EndpointReleaseUnavailable $exception) {
            $this->error('Catalogue verification failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $target = (string) config('endpoint_management.release_directory');
        if ($target === '') {
            $this->error('ENDPOINT_RELEASE_DIRECTORY is not configured.');

            return self::FAILURE;
        }

        $parent = dirname($target);
        File::ensureDirectoryExists($parent);
        $staging = $parent.DIRECTORY_SEPARATOR.'.endpoint-releases-staging-'.bin2hex(random_bytes(8));
        $backup = $parent.DIRECTORY_SEPARATOR.'.endpoint-releases-previous-'.bin2hex(random_bytes(8));

        File::ensureDirectoryExists($staging, 0750);
        try {
            foreach ($catalogue['artifacts'] as $artifact) {
                File::copy(
                    $resolvedSource.DIRECTORY_SEPARATOR.$artifact['filename'],
                    $staging.DIRECTORY_SEPARATOR.$artifact['filename'],
                );
            }
            File::copy(
                $resolvedSource.DIRECTORY_SEPARATOR.'downloads.json',
                $staging.DIRECTORY_SEPARATOR.'downloads.json',
            );

            $stagedVerifier = new EndpointReleaseCatalogue($staging, $publicKey, $minimumRevision);
            $stagedVerifier->catalogue();

            $hadExisting = is_dir($target);
            if ($hadExisting && ! @rename($target, $backup)) {
                throw new \RuntimeException('Could not move existing release directory aside.');
            }
            if (! @rename($staging, $target)) {
                if ($hadExisting && is_dir($backup)) {
                    @rename($backup, $target);
                }
                throw new \RuntimeException('Could not activate staged release directory.');
            }
            if ($hadExisting && is_dir($backup)) {
                File::deleteDirectory($backup);
            }
        } catch (\Throwable $exception) {
            if (is_dir($staging)) {
                File::deleteDirectory($staging);
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Published catalogue revision %d (%d artifacts, expires %s) to %s.',
            $catalogue['revision'],
            count($catalogue['artifacts']),
            $catalogue['expires_at'],
            $target,
        ));

        return self::SUCCESS;
    }
}
