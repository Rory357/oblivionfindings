<?php

namespace Oblivion\Collector;

use DateTimeImmutable;
use JsonException;
use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Exceptions\CentralApiFailure;
use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Runtime\CollectorCommandExecutor;
use Oblivion\Collector\Runtime\CommandJournal;
use Oblivion\Collector\Runtime\HeartbeatReporter;
use Oblivion\Collector\Runtime\NativeCollectorCommandTransport;
use Oblivion\Collector\Runtime\ProbeRunner;
use Oblivion\Collector\Runtime\RemoteDiscoveryRunner;
use Oblivion\Collector\Runtime\UnifiAccessCommandRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;
use Oblivion\Collector\Spool\EncryptedSpool;
use RuntimeException;

final class CollectorApplication
{
    public const string VERSION = '0.1.0';

    /**
     * @param  null|\Closure(string, string, string, string, string): array<string, mixed>  $enrolmentTransport
     * @param  null|\Closure(string, mixed): void  $output
     */
    public function __construct(
        private readonly ?\Closure $enrolmentTransport = null,
        private readonly ?\Closure $output = null,
    ) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $command = $arguments[1] ?? null;
        $options = $this->options(array_slice($arguments, 2));

        try {
            return match ($command) {
                'version' => $this->version(),
                'doctor' => $this->doctor($options),
                'enrol' => $this->enrol($options),
                'run' => $this->collect($options),
                'verify-transport' => $this->verifyTransport($options),
                default => $this->usage(),
            };
        } catch (\Throwable $exception) {
            $this->write('collector_error: '.$this->boundedMessage($exception).PHP_EOL, STDERR);

            return 1;
        }
    }

    private function version(): int
    {
        $this->write('Oblivion Collector '.self::VERSION.PHP_EOL);

        return 0;
    }

    /** @param array<string, string|bool> $options */
    private function doctor(array $options): int
    {
        $configPath = $this->requiredPath($options, 'config');
        $identityPath = isset($options['identity']) && is_string($options['identity'])
            ? $options['identity']
            : dirname($configPath).DIRECTORY_SEPARATOR.'collector.identity.json';
        $identity = $this->identity($identityPath);
        $stateDirectory = $this->stateDirectory($identity, $identityPath);
        $checkpoint = new CheckpointFile($stateDirectory.DIRECTORY_SEPARATOR.'checkpoint.json');
        $config = (new SignedConfigLoader(
            new EnvelopeVerifier($identity['central_signing_public_key']),
            $checkpoint,
            $identity['collector_id'],
            $identity['site_id'],
        ))->load($this->readBounded($configPath), new DateTimeImmutable('now'), false);
        $spool = new EncryptedSpool($stateDirectory, $checkpoint);
        $scope = new ScopeGuard($config);
        foreach ($config->checks as $check) {
            $scope->assertCheck($check, new DateTimeImmutable('now'));
        }
        foreach ($config->discoveryRuns as $run) {
            $scope->assertDiscoveryRun($run, new DateTimeImmutable('now'));
        }

        $this->write("database: absent\n");
        $this->write("signature: valid\n");
        $this->write("scope: valid\n");
        $this->write('spool: '.$spool->status()['state']."\n");

        return $spool->status()['state'] === 'writable' ? 0 : 2;
    }

    /** @param array<string, string|bool> $options */
    private function enrol(array $options): int
    {
        $identityPath = $this->requiredString($options, 'identity');
        $collectorId = $this->requiredString($options, 'collector-id');
        $centralUrl = $this->requiredString($options, 'central-url');
        $tlsPin = $this->requiredString($options, 'tls-public-key-pin');
        $stateDirectory = $this->requiredString($options, 'state-directory');
        $stateDirectory = $this->stateDirectory(['state_directory' => $stateDirectory], $identityPath);
        $token = $this->enrolmentToken($options);
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        try {
            $response = $this->enrolmentTransport === null
                ? (new HttpsCentralApi($centralUrl, $tlsPin))->enrol($token, $collectorId, $publicKey)
                : ($this->enrolmentTransport)($centralUrl, $tlsPin, $token, $collectorId, $publicKey);
        } finally {
            sodium_memzero($token);
        }
        $siteId = $response['site_id'] ?? null;
        $centralSigningKey = $response['central_signing_public_key'] ?? null;
        $clientCertificate = $response['client_certificate'] ?? null;
        $clientPrivateKey = $response['client_private_key'] ?? null;
        $certificateFingerprint = $response['client_certificate_fingerprint'] ?? null;
        $acknowledgedSequence = $response['acknowledged_source_sequence'] ?? null;
        if (! is_int($siteId) || $siteId < 1 || ! is_string($centralSigningKey)
            || ! is_string($clientCertificate) || strlen($clientCertificate) > 262_144
            || ! is_string($clientPrivateKey) || strlen($clientPrivateKey) > 262_144
            || ! is_string($certificateFingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $certificateFingerprint) !== 1
            || ! is_int($acknowledgedSequence) || $acknowledgedSequence < 0
        ) {
            sodium_memzero($secretKey);
            throw new CentralApiFailure('Collector enrolment response is invalid.');
        }
        $certificate = openssl_x509_read($clientCertificate);
        $privateKey = openssl_pkey_get_private($clientPrivateKey);
        $actualFingerprint = $certificate === false ? false : openssl_x509_fingerprint($certificate, 'sha256');
        if ($certificate === false || $privateKey === false || ! is_string($actualFingerprint)
            || ! openssl_x509_check_private_key($certificate, $privateKey)
            || ! hash_equals($certificateFingerprint, strtolower($actualFingerprint))) {
            sodium_memzero($secretKey);
            throw new CentralApiFailure('Collector mTLS enrolment identity is invalid.');
        }
        $certificatePath = rtrim($stateDirectory, '\\/').DIRECTORY_SEPARATOR.'collector.crt.pem';
        $privateKeyPath = rtrim($stateDirectory, '\\/').DIRECTORY_SEPARATOR.'collector.key.pem';
        $this->writePrivateFile($certificatePath, $clientCertificate);
        $this->writePrivateFile($privateKeyPath, $clientPrivateKey);
        $checkpoint = new CheckpointFile(rtrim($stateDirectory, '\\/').DIRECTORY_SEPARATOR.'checkpoint.json');
        $checkpoint->merge(['acknowledged_source_sequence' => $acknowledgedSequence]);

        $this->writeIdentity($identityPath, [
            'collector_id' => $collectorId,
            'site_id' => $siteId,
            'central_url' => $centralUrl,
            'tls_public_key_pin' => $tlsPin,
            'central_signing_public_key' => $centralSigningKey,
            'request_signing_secret_key' => base64_encode($secretKey),
            'state_directory' => $stateDirectory,
            'client_certificate_file' => $certificatePath,
            'client_private_key_file' => $privateKeyPath,
            'client_certificate_fingerprint' => $certificateFingerprint,
        ]);
        sodium_memzero($secretKey);
        $this->write("enrolment: complete\n");

        return 0;
    }

    /** @param array<string, string|bool> $options */
    private function collect(array $options): int
    {
        $configPath = $this->requiredString($options, 'config');
        $identityPath = $this->requiredPath($options, 'identity');
        $identity = $this->identity($identityPath, requireRuntime: true);
        $stateDirectory = $this->stateDirectory($identity, $identityPath);
        $checkpoint = new CheckpointFile($stateDirectory.DIRECTORY_SEPARATOR.'checkpoint.json');
        $loader = new SignedConfigLoader(
            new EnvelopeVerifier($identity['central_signing_public_key']),
            $checkpoint,
            $identity['collector_id'],
            $identity['site_id'],
        );
        $secretKey = base64_decode($identity['request_signing_secret_key'], true);
        if (! is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Collector request signing key is invalid.');
        }
        $central = new HttpsCentralApi(
            $identity['central_url'],
            $identity['tls_public_key_pin'],
            $secretKey,
            $identity['client_certificate_file'],
            $identity['client_private_key_file'],
        );
        $configurationError = null;
        try {
            $envelope = $central->configuration(
                $identity['collector_id'],
                $checkpoint->read()['config_sequence'],
            );
            $config = $loader->load($envelope);
            $this->writePrivateFile($configPath, $envelope);
        } catch (CentralApiFailure $exception) {
            if (! is_file($configPath)) {
                throw $exception;
            }
            $configurationError = $this->boundedMessage($exception);
            $config = $loader->load($this->readBounded($configPath));
        }
        $spool = new EncryptedSpool($stateDirectory, $checkpoint);
        $uploadError = $this->flush($central, $identity['collector_id'], $spool);
        $uploadError ??= $configurationError;

        $checksExecuted = 0;
        $discoveryTargetsExecuted = 0;
        $commandsExecuted = 0;
        if ($spool->status()['state'] !== 'buffer_full') {
            $scope = new ScopeGuard($config);
            $credentialDecryptor = new CredentialLeaseDecryptor($secretKey);
            $runner = new ProbeRunner(
                $scope,
                $credentialDecryptor,
            );
            $commandExecutor = new CollectorCommandExecutor(
                new CommandJournal($stateDirectory),
                new UnifiAccessCommandRunner(
                    $scope,
                    $credentialDecryptor,
                    new NativeCollectorCommandTransport,
                ),
            );
            foreach ($config->commands as $command) {
                if ($spool->status()['state'] === 'buffer_full') {
                    break;
                }
                if ($commandExecutor->execute($command, $spool)) {
                    $commandsExecuted++;
                }
            }
            $minimumProbeIntervalNanoseconds = intdiv(
                1_000_000_000,
                $config->scope['rate_limits']['packets_per_second'],
            );
            $nextProbeAt = hrtime(true);
            foreach (array_slice($config->checks, 0, $config->scope['rate_limits']['max_checks_per_run']) as $check) {
                if ($spool->status()['state'] === 'buffer_full') {
                    break;
                }
                $remainingNanoseconds = $nextProbeAt - hrtime(true);
                if ($remainingNanoseconds > 0) {
                    usleep((int) ceil($remainingNanoseconds / 1000));
                }
                $observedAt = new DateTimeImmutable('now');
                $result = $runner->run($check, $observedAt);
                $nextProbeAt = hrtime(true) + $minimumProbeIntervalNanoseconds;
                $interval = max(30, min(86_400, (int) ($check['interval_seconds'] ?? 60)));
                $executionBucket = intdiv($observedAt->getTimestamp(), $interval);
                $itemId = hash('sha256', $config->collectorId.'|'.$check['id'].'|'.$executionBucket);
                if ($spool->append($itemId, $spool->nextSourceSequence(), $result, $observedAt)) {
                    $checksExecuted++;
                }
            }

            $discovery = new RemoteDiscoveryRunner($scope);
            $completedIds = $checkpoint->read()['acknowledged_ids'];
            foreach ($config->discoveryRuns as $run) {
                foreach ($discovery->run($run, $config->collectorId, $completedIds) as $result) {
                    if ($spool->status()['state'] === 'buffer_full') {
                        break 2;
                    }
                    $observedAt = new DateTimeImmutable($result['payload']['observed_at']);
                    if ($spool->append(
                        $result['item_id'],
                        $spool->nextSourceSequence(),
                        $result['payload'],
                        $observedAt,
                    )) {
                        $discoveryTargetsExecuted++;
                    }
                }
            }

        }

        $uploadError ??= $this->flush($central, $identity['collector_id'], $spool);
        try {
            (new HeartbeatReporter($central, $identity['collector_id'], $spool))->report([
                'checks_executed' => $checksExecuted,
                'discovery_targets_executed' => $discoveryTargetsExecuted,
                'commands_executed' => $commandsExecuted,
                'config_sequence' => $config->sequence,
                'upload_state' => $uploadError === null ? 'current' : 'deferred',
            ]);
        } catch (CentralApiFailure $exception) {
            $uploadError ??= $this->boundedMessage($exception);
        }
        sodium_memzero($secretKey);
        $this->write('collection: '.($uploadError === null ? 'complete' : 'buffered').PHP_EOL);

        return $uploadError === null ? 0 : 2;
    }

    /** @param array<string, string|bool> $options */
    private function verifyTransport(array $options): int
    {
        $identityPath = $this->requiredPath($options, 'identity');
        $expectedState = $this->requiredString($options, 'expect');
        if (! in_array($expectedState, ['active', 'revoked'], true)) {
            throw new RuntimeException('Collector --expect must be active or revoked.');
        }
        $samplesOption = $options['samples'] ?? '5';
        if (! is_string($samplesOption) || ! ctype_digit($samplesOption)) {
            throw new RuntimeException('Collector --samples must be an integer between 1 and 20.');
        }
        $samples = (int) $samplesOption;
        if ($samples < 1 || $samples > 20) {
            throw new RuntimeException('Collector --samples must be an integer between 1 and 20.');
        }
        $identity = $this->identity($identityPath, requireRuntime: true);
        $secretKey = base64_decode($identity['request_signing_secret_key'], true);
        if (! is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Collector request signing key is invalid.');
        }
        try {
            $evidence = (new HttpsCentralApi(
                $identity['central_url'],
                $identity['tls_public_key_pin'],
                $secretKey,
                $identity['client_certificate_file'],
                $identity['client_private_key_file'],
            ))->verifyTransport($identity['collector_id'], $expectedState, $samples);
        } finally {
            sodium_memzero($secretKey);
        }

        $this->write(json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return 0;
    }

    private function flush(HttpsCentralApi $central, string $collectorId, EncryptedSpool $spool): ?string
    {
        try {
            while (($batch = $spool->readBatch(250)) !== []) {
                $acknowledgement = $central->upload($collectorId, $batch);
                $batchIds = array_column($batch, 'id');
                $acknowledgedIds = $acknowledgement['acknowledged_ids'];
                if ($acknowledgedIds === []) {
                    throw new CentralApiFailure('Central did not advance the ordered upload checkpoint.');
                }
                if ($acknowledgedIds !== array_slice($batchIds, 0, count($acknowledgedIds))) {
                    throw new CentralApiFailure('Central acknowledgement is not an ordered prefix of the uploaded batch.');
                }
                $expectedSequence = $batch[count($acknowledgedIds) - 1]['source_sequence'];
                if ($acknowledgement['acknowledged_source_sequence'] !== $expectedSequence) {
                    throw new CentralApiFailure('Central acknowledgement does not match the ordered source checkpoint.');
                }
                $spool->acknowledge(
                    $acknowledgedIds,
                    $acknowledgement['acknowledged_source_sequence'],
                );
            }
        } catch (CentralApiFailure $exception) {
            return $this->boundedMessage($exception);
        }

        return null;
    }

    /** @param list<string> $arguments @return array<string, string|bool> */
    private function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--')) {
                throw new RuntimeException('Collector options must use --name=value syntax.');
            }
            $parts = explode('=', substr($argument, 2), 2);
            $name = $parts[0];
            if ($name === '' || isset($options[$name])) {
                throw new RuntimeException('Collector option is invalid or duplicated.');
            }
            $options[$name] = count($parts) === 2 ? $parts[1] : true;
        }

        return $options;
    }

    /** @param array<string, string|bool> $options */
    private function requiredString(array $options, string $name): string
    {
        $value = $options[$name] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Collector --{$name} is required.");
        }

        return $value;
    }

    /** @param array<string, string|bool> $options */
    private function requiredPath(array $options, string $name): string
    {
        $path = $this->requiredString($options, $name);
        if (! is_file($path)) {
            throw new RuntimeException("Collector --{$name} file is unavailable.");
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function identity(string $path, bool $requireRuntime = false): array
    {
        try {
            $identity = json_decode($this->readBounded($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Collector identity file is invalid.', previous: $exception);
        }
        if (! is_array($identity)
            || ! is_string($identity['collector_id'] ?? null)
            || ! is_int($identity['site_id'] ?? null)
            || ! is_string($identity['central_signing_public_key'] ?? null)
            || ! is_string($identity['state_directory'] ?? null)
        ) {
            throw new RuntimeException('Collector identity file is invalid.');
        }
        if ($requireRuntime && (! is_string($identity['central_url'] ?? null)
            || ! is_string($identity['tls_public_key_pin'] ?? null)
            || ! is_string($identity['request_signing_secret_key'] ?? null)
            || ! is_string($identity['client_certificate_file'] ?? null)
            || ! is_file($identity['client_certificate_file'])
            || ! is_string($identity['client_private_key_file'] ?? null)
            || ! is_file($identity['client_private_key_file']))) {
            throw new RuntimeException('Collector runtime identity is incomplete.');
        }

        return $identity;
    }

    /** @param array<string, mixed> $identity */
    private function stateDirectory(array $identity, string $identityPath): string
    {
        $path = $identity['state_directory'];
        if (! preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path)) {
            $path = dirname($identityPath).DIRECTORY_SEPARATOR.$path;
        }

        return $path;
    }

    /** @param array<string, mixed> $identity */
    private function writeIdentity(string $path, array $identity): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Collector identity directory is unavailable.');
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        $json = json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            @unlink($temporary);
            throw new RuntimeException('Collector identity could not be staged.');
        }
        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Collector identity could not be replaced atomically.');
        }
    }

    private function writePrivateFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Collector private-state directory is unavailable.');
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);
            throw new RuntimeException('Collector private-state file could not be staged.');
        }
        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Collector private-state file could not be replaced atomically.');
        }
    }

    /** @param array<string, string|bool> $options */
    private function enrolmentToken(array $options): string
    {
        $descriptor = $options['token-fd'] ?? null;
        if (is_string($descriptor) && ctype_digit($descriptor)) {
            $token = file_get_contents('php://fd/'.(int) $descriptor);
        } else {
            $token = getenv('OBLIVION_COLLECTOR_ENROLMENT_TOKEN');
        }
        if (! is_string($token) || trim($token) === '' || strlen($token) > 4096) {
            throw new RuntimeException('One-time enrolment token is unavailable.');
        }

        return trim($token);
    }

    private function readBounded(string $path): string
    {
        if (! is_file($path) || filesize($path) > 2_097_152) {
            throw new RuntimeException('Collector file is unavailable or too large.');
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('Collector file is unreadable.');
        }

        return $contents;
    }

    private function usage(): int
    {
        $this->write("Usage: oblivion-collector enrol|run|doctor|verify-transport|version [--name=value]\n", STDERR);

        return 64;
    }

    private function boundedMessage(\Throwable $exception): string
    {
        $message = preg_replace('/[\r\n\x00-\x1f]+/', ' ', $exception->getMessage());

        return substr(trim((string) $message), 0, 240);
    }

    /** @param resource $stream */
    private function write(string $message, $stream = STDOUT): void
    {
        if ($this->output !== null) {
            ($this->output)($message, $stream);

            return;
        }

        fwrite($stream, $message);
    }
}
