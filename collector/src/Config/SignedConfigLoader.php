<?php

namespace Oblivion\Collector\Config;

use DateTimeImmutable;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Exceptions\ScopeViolation;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;

final readonly class SignedConfigLoader
{
    public function __construct(
        private EnvelopeVerifier $verifier,
        private CheckpointFile $checkpoint,
        private string $collectorId,
        private int $siteId,
    ) {}

    public function load(
        string $envelope,
        ?DateTimeImmutable $at = null,
        bool $persistSequence = true,
    ): CollectorConfig {
        $at ??= new DateTimeImmutable('now');
        $config = CollectorConfig::fromPayload($this->verifier->verify($envelope));

        if (! in_array($config->version, [1, 2, 3], true)) {
            throw new ConfigurationRejected('Configuration contract version is unsupported.');
        }
        if (! hash_equals($this->collectorId, $config->collectorId)) {
            throw new ConfigurationRejected('Configuration collector identity does not match this collector.');
        }
        if ($config->siteId !== $this->siteId) {
            throw new ConfigurationRejected('Configuration Site does not match this collector.');
        }
        if ($config->revoked) {
            throw new ConfigurationRejected('Configuration states that this collector is revoked.');
        }
        if ($config->issuedAt > $at->modify('+5 minutes')) {
            throw new ConfigurationRejected('Configuration issue time is in the future.');
        }
        if ($config->expiresAt <= $at || $config->expiresAt <= $config->issuedAt) {
            throw new ConfigurationRejected('Configuration is expired.');
        }

        $checkpoint = $this->checkpoint->read();
        $configHash = hash('sha256', $envelope);
        if ($config->sequence < $checkpoint['config_sequence']
            || ($config->sequence === $checkpoint['config_sequence']
                && ! hash_equals((string) $checkpoint['config_hash'], $configHash))
        ) {
            throw new ConfigurationRejected('Configuration sequence is not newer than the accepted checkpoint.');
        }

        try {
            $guard = new ScopeGuard($config);
            foreach ($config->checks as $check) {
                $guard->assertCheck($check, $at);
            }
            foreach ($config->discoveryRuns as $run) {
                $guard->assertDiscoveryRun($run, $at);
            }
            foreach ($config->commands as $command) {
                $guard->assertCommand($command, $at);
            }
        } catch (ScopeViolation $exception) {
            throw new ConfigurationRejected($exception->getMessage(), previous: $exception);
        }

        if ($persistSequence) {
            $this->checkpoint->merge([
                'config_sequence' => $config->sequence,
                'config_hash' => $configHash,
            ]);
        }

        return $config;
    }
}
