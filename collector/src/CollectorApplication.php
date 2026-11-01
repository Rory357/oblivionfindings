<?php

namespace Oblivion\Collector;

use DateTimeImmutable;
use JsonException;
use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Discovery\LanDiscoveryScanner;
use Oblivion\Collector\Enrollment\InstallationProfile;
use Oblivion\Collector\Enrollment\RecoverableEnrollment;
use Oblivion\Collector\Exceptions\CentralApiFailure;
use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Exceptions\SpoolFull;
use Oblivion\Collector\Media\MediaLane;
use Oblivion\Collector\Mqtt\MqttRelayServer;
use Oblivion\Collector\Mqtt\RelayInbox;
use Oblivion\Collector\Runtime\CameraSnapshotRunner;
use Oblivion\Collector\Runtime\CollectorCommandExecutor;
use Oblivion\Collector\Runtime\CommandJournal;
use Oblivion\Collector\Runtime\HeartbeatReporter;
use Oblivion\Collector\Runtime\NativeCollectorCommandTransport;
use Oblivion\Collector\Runtime\ProbeRunner;
use Oblivion\Collector\Runtime\RemoteDiscoveryRunner;
use Oblivion\Collector\Runtime\SshConfigBackupRunner;
use Oblivion\Collector\Runtime\AlarmAreaCommandRunner;
use Oblivion\Collector\Runtime\UnifiAccessCommandRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\HubVault;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;
use Oblivion\Collector\Spool\EncryptedSpool;
use RuntimeException;

final class CollectorApplication
{
    public const string VERSION = '0.2.0';

    /** Largest release file a hub will download (matches the signed release list's limit). */
    private const int MAX_UPDATE_BYTES = 536_870_912;

    /**
     * @param  null|\Closure(string, string, string, string, string): array<string, mixed>  $enrolmentTransport
     * @param  null|\Closure(string, string, int, string, string): array<string, mixed>  $transportEvidenceTransport
     * @param  null|\Closure(string, mixed): void  $output
     * @param  null|\Closure(string, string, string, list<string>, bool): array{transport_ok: bool, status: int, body: string}  $updateTransport  test transport for update-fetch and update-report
     */
    public function __construct(
        private readonly ?\Closure $enrolmentTransport = null,
        private readonly ?\Closure $transportEvidenceTransport = null,
        private readonly ?\Closure $output = null,
        private readonly ?\Closure $recoverableEnrollmentTransport = null,
        private readonly ?\Closure $updateTransport = null,
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
                'enrol-recoverable' => $this->enrolRecoverable($options),
                'enrol-profile' => $this->enrolProfile($options),
                'runtime-check' => $this->runtimeCheck($options),
                'media-run' => $this->mediaRun($options),
                'run' => $this->collect($options),
                'update-fetch' => $this->updateFetch($options),
                'update-report' => $this->updateReport($options),
                'deregister' => $this->deregister($options),
                'relay' => $this->relay($options),
                'verify-transport' => $this->verifyTransport($options),
                default => $this->usage(),
            };
        } catch (\Throwable $exception) {
            $this->write('collector_error: '.$this->boundedMessage($exception).PHP_EOL, STDERR);

            return 1;
        }
    }

    /** Local-only startup check; does not contact central or run network probes. */
    private function runtimeCheck(array $options): int
    {
        if (array_keys($options) !== ['identity'] || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 90000) {
            throw new RuntimeException('Collector runtime options or PHP version are unsupported.');
        }
        foreach (['curl', 'json', 'openssl', 'sodium', 'sockets'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new RuntimeException('Collector runtime extensions are incomplete.');
            }
        }
        (new RecoverableEnrollment)->verifyInstalled($this->requiredPath($options, 'identity'));
        $this->write("runtime: locally ready; central monitoring health is not confirmed\n");

        return 0;
    }

    private function version(): int
    {
        $this->write('Oblivion Collector '.self::buildVersion().PHP_EOL);

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
        $parsedCertificate = $certificate === false ? false : openssl_x509_parse($certificate, true);
        $now = time();
        if ($certificate === false || $privateKey === false || ! is_string($actualFingerprint)
            || ! is_array($parsedCertificate)
            || ! openssl_x509_check_private_key($certificate, $privateKey)
            || ! hash_equals($certificateFingerprint, strtolower($actualFingerprint))
            || ($parsedCertificate['subject']['CN'] ?? null) !== 'oblivion-collector-'.$collectorId
            || ! is_int($parsedCertificate['validFrom_time_t'] ?? null)
            || ! is_int($parsedCertificate['validTo_time_t'] ?? null)
            || $now < $parsedCertificate['validFrom_time_t']
            || $now >= $parsedCertificate['validTo_time_t']) {
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
    private function enrolProfile(array $options): int
    {
        if (array_diff(array_keys($options), ['profile', 'state-directory', 'confirm-server', 'confirm-collector-server']) !== []) {
            throw new RuntimeException('Profile enrolment contains an unknown option.');
        }
        $platform = match (PHP_OS_FAMILY) {
            'Windows' => 'windows', 'Linux' => 'linux',
            default => throw new RuntimeException('Site collector platform is unsupported.'),
        };
        $profile = InstallationProfile::read($this->requiredPath($options, 'profile'), $platform);
        try {
            if ($this->requiredString($options, 'confirm-server') !== $profile['deployment_url']
                || ($profile['server_url'] !== $profile['deployment_url']
                    && ($options['confirm-collector-server'] ?? null) !== $profile['server_url'])
                || (isset($options['confirm-collector-server']) && $options['confirm-collector-server'] !== $profile['server_url'])) {
                throw new RuntimeException('Confirm the exact deployment and collector server from the intended organisation.');
            }
            $root = $this->requiredString($options, 'state-directory');
            (new RecoverableEnrollment($this->recoverableEnrollmentTransport))->enrol(
                $root.DIRECTORY_SEPARATOR.'collector.identity.json', $profile['collector_uuid'], $profile['site_id'],
                $profile['server_url'], $profile['tls_public_key_pin'], $root, $profile['enrolment_token'],
                true, $profile['server_public_key'],
            );
        } finally {
            sodium_memzero($profile['enrolment_token']);
        }
        $this->write("enrolment: identity saved; runtime connectivity still requires verification\n");

        return 0;
    }

    /** @param array<string, string|bool> $options */
    private function enrolRecoverable(array $options): int
    {
        $allowed = ['identity', 'collector-id', 'site-id', 'central-url', 'tls-public-key-pin', 'state-directory', 'token-fd', 'confirm-current', 'expected-server-key'];
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new RuntimeException('Recoverable enrolment contains an unknown option.');
        }
        if (isset($options['confirm-current']) && $options['confirm-current'] !== true) {
            throw new RuntimeException('Use --confirm-current without a value.');
        }
        $site = $this->requiredString($options, 'site-id');
        if (! ctype_digit($site) || strlen($site) > 15 || (int) $site < 1) {
            throw new RuntimeException('Collector --site-id must identify the approved Site.');
        }
        $token = $this->enrolmentToken($options);
        try {
            (new RecoverableEnrollment($this->recoverableEnrollmentTransport))->enrol(
                $this->requiredString($options, 'identity'),
                $this->requiredString($options, 'collector-id'),
                (int) $site,
                $this->requiredString($options, 'central-url'),
                $this->requiredString($options, 'tls-public-key-pin'),
                $this->requiredString($options, 'state-directory'),
                $token,
                ($options['confirm-current'] ?? false) === true,
                isset($options['expected-server-key']) ? $this->requiredString($options, 'expected-server-key') : null,
            );
        } finally {
            sodium_memzero($token);
        }
        $this->write("enrolment: identity saved; runtime connectivity still requires verification\n");

        return 0;
    }

    /** @param array<string, string|bool> $options */
    private function mediaRun(array $options): int
    {
        if (array_keys($options) !== ['identity']) {
            throw new RuntimeException('Media identity unavailable.');
        }
        $identity = $this->identity($this->requiredPath($options, 'identity'), requireRuntime: true);
        $secret = base64_decode($identity['request_signing_secret_key'], true);
        if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Media identity unavailable.');
        }
        try {
            $central = new HttpsCentralApi($identity['central_url'], $identity['tls_public_key_pin'], $secret, $identity['client_certificate_file'], $identity['client_private_key_file']);

            return (new MediaLane($central, new CredentialLeaseDecryptor($secret), new EnvelopeVerifier($identity['central_signing_public_key'])))->run($identity, STDIN, STDOUT);
        } finally {
            sodium_memzero($secret);
        }
    }

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
        // S&D Phase B: the login-store key. Handed out only to an approved hub that hasn't been
        // removed; held in memory, never written down.
        $vault = null;
        $approval = 'approved';
        try {
            $vault = HubVault::fromSealed($this->hostSealedVaultKey($options) ?? $central->vaultKey($identity['collector_id']), $secretKey);
        } catch (CentralApiFailure $exception) {
            if ($exception->httpStatus() === 410) {
                return $this->wipeRemovedHub($configPath, $stateDirectory, $secretKey);
            }
            if ($exception->httpStatus() === 409) {
                $approval = 'waiting';
            }
        }
        $configurationError = null;
        $config = null;
        try {
            $envelope = $central->configuration(
                $identity['collector_id'],
                $checkpoint->read()['config_sequence'],
            );
            $config = $loader->load($envelope);
            if ($vault !== null) {
                $this->writePrivateFile($configPath, $vault->encrypt($envelope));
            } elseif (is_file($configPath)) {
                // Never keep saved logins on disk unless they're in the encrypted store.
                @unlink($configPath);
            }
        } catch (CentralApiFailure $exception) {
            if ($exception->httpStatus() === 410) {
                return $this->wipeRemovedHub($configPath, $stateDirectory, $secretKey);
            }
            $stored = is_file($configPath) ? $this->readBounded($configPath) : null;
            if ($stored !== null && ! HubVault::isSealed($stored)) {
                // A work list from before the encrypted store: its logins go, a fresh one comes next.
                @unlink($configPath);
                $stored = null;
            }
            if ($stored !== null && $vault !== null && $exception->httpStatus() !== 409) {
                $configurationError = $this->boundedMessage($exception);
                $config = $loader->load($vault->decrypt($stored));
            } elseif ($exception->httpStatus() !== 409 && $stored === null && $vault !== null) {
                throw $exception;
            }
        }
        if ($config !== null) {
            // S&D Phase B (D21): the relay works from this file, so it keeps taking readings offline.
            $this->writeRelayLogins($stateDirectory, $config);
        }
        $spool = new EncryptedSpool($stateDirectory, $checkpoint);
        $this->moveRelayedReadings($stateDirectory, $spool, $secretKey);
        if ($config !== null) {
            // S&D Phase E (D21): once a day, check Oblivion's public address refuses MQTT.
            $this->checkBrokerExposure($stateDirectory, $spool, (string) $identity['central_url']);
        }
        $uploadError = $this->flush($central, $identity['collector_id'], $spool);
        $uploadError ??= $configurationError;

        $checksExecuted = 0;
        $discoveryTargetsExecuted = 0;
        $commandsExecuted = 0;
        if ($config !== null && $spool->status()['state'] !== 'buffer_full') {
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
                // S&D Phase B: settings backups and camera pictures run here, through the hub, not from the server.
                new SshConfigBackupRunner($scope, $credentialDecryptor),
                new CameraSnapshotRunner($scope, $credentialDecryptor),
                // S&D Phase E (R6): arming and disarming Inception / Gallagher areas.
                new AlarmAreaCommandRunner($scope, $credentialDecryptor),
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

            $discovery = new RemoteDiscoveryRunner(
                $scope,
                null,
                $credentialDecryptor,
                new LanDiscoveryScanner,
            );
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
                'version' => self::buildVersion(),
                'platform' => PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux',
                'checks_executed' => $checksExecuted,
                'discovery_targets_executed' => $discoveryTargetsExecuted,
                'commands_executed' => $commandsExecuted,
                // The job types this hub can run: the server sends a new kind of job only to hubs that list it.
                'command_adapters' => implode(',', ScopeGuard::commandAdapters()),
                'config_sequence' => $config !== null ? $config->sequence : (int) $checkpoint->read()['config_sequence'],
                'upload_state' => $uploadError === null ? 'current' : 'deferred',
            ]);
            $this->writeHostInstructions($stateDirectory, $central->lastHeartbeat());
        } catch (CentralApiFailure $exception) {
            if ($exception->httpStatus() === 410) {
                return $this->wipeRemovedHub($configPath, $stateDirectory, $secretKey);
            }
            $uploadError ??= $this->boundedMessage($exception);
        }
        sodium_memzero($secretKey);
        if ($approval === 'waiting') {
            $this->write('collection: waiting for approval'.PHP_EOL);

            return 4;
        }
        $this->write('collection: '.($uploadError === null ? 'complete' : 'buffered').PHP_EOL);

        return $uploadError === null ? 0 : 2;
    }

    /**
     * The hub has been removed in Oblivion: wipe the saved logins it held (the encrypted work
     * list and anything the host keeps for it), leave a note, and stop.
     */
    /**
     * S&D Phase B (D21): the sensor relay. A long-running process beside the normal cycle, kept
     * running by the Windows service or the Linux launcher. It listens on the house network only
     * while the signed config names gateway logins.
     *
     * @param  array<string, string|bool>  $options
     */
    private function relay(array $options): int
    {
        if (array_keys($options) !== ['identity']) {
            throw new RuntimeException('Relay takes only --identity.');
        }
        $identityPath = $this->requiredPath($options, 'identity');
        $identity = $this->identity($identityPath, requireRuntime: true);
        $stateDirectory = $this->stateDirectory($identity, $identityPath);
        $secretKey = base64_decode($identity['request_signing_secret_key'], true);
        if (! is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Collector request signing key is invalid.');
        }
        $inbox = new RelayInbox($stateDirectory.DIRECTORY_SEPARATOR.'relay-inbox', RelayInbox::keyFor($secretKey));
        sodium_memzero($secretKey);
        $loginsFile = $stateDirectory.DIRECTORY_SEPARATOR.self::RELAY_LOGINS_FILE;
        $relay = null;
        $readAt = 0;
        $logins = function () use (&$relay, &$readAt, $loginsFile): ?array {
            if (time() - $readAt >= 30) {
                $readAt = time();
                $relay = $this->readRelayLogins($loginsFile);
            }

            return $relay;
        };
        $server = new MqttRelayServer($inbox, $logins, log: fn (string $line) => $this->write($line.PHP_EOL));
        $listening = null;
        while (! is_file($stateDirectory.DIRECTORY_SEPARATOR.'removed.json')) {
            $port = $logins()['listen_port'] ?? null;
            if ($port !== $listening) {
                $server->close();
                $listening = null;
                if (is_int($port)) {
                    try {
                        // The house network only reaches it through the installer's firewall rule.
                        $server->listen('0.0.0.0', $port);
                        $listening = $port;
                        $this->write("relay: listening on {$port}".PHP_EOL);
                    } catch (RuntimeException $exception) {
                        $this->write('relay: '.$exception->getMessage().PHP_EOL, STDERR);
                        sleep(10);
                    }
                }
            }
            $server->poll($listening === null ? 5.0 : 1.0);
        }
        $server->close();

        return 3;
    }

    private const string RELAY_LOGINS_FILE = 'sensor-relay.json';

    private function writeRelayLogins(string $stateDirectory, CollectorConfig $config): void
    {
        $path = $stateDirectory.DIRECTORY_SEPARATOR.self::RELAY_LOGINS_FILE;
        if ($config->sensorRelay === null) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        $this->writePrivateFile($path, json_encode([
            'version' => 1,
            'allowed_site_ids' => $config->allowedSiteIds(),
            'relay' => $config->sensorRelay,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** @return array{listen_port: int, max_payload_bytes: int, logins: list<array{username: string, password_hash: string, site_id: int, topic_prefix: string}>}|null */
    private function readRelayLogins(string $path): ?array
    {
        try {
            $stored = is_file($path) ? json_decode($this->readBounded($path), true, 16, JSON_THROW_ON_ERROR) : null;
            if (! is_array($stored) || ($stored['version'] ?? null) !== 1 || ! is_array($stored['allowed_site_ids'] ?? null)) {
                return null;
            }

            return CollectorConfig::sensorRelay($stored['relay'] ?? null, array_values(array_filter($stored['allowed_site_ids'], 'is_int')));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Readings the relay took since the last cycle go into the upload spool, with their real times. */
    private function moveRelayedReadings(string $stateDirectory, EncryptedSpool $spool, string $secretKey): void
    {
        $directory = $stateDirectory.DIRECTORY_SEPARATOR.'relay-inbox';
        if (! is_dir($directory)) {
            return;
        }
        $inbox = new RelayInbox($directory, RelayInbox::keyFor($secretKey));
        for ($round = 0; $round < 20; $round++) {
            $taken = $inbox->take(500);
            if ($taken === []) {
                return;
            }
            foreach ($taken as $held) {
                if ($spool->status()['state'] === 'buffer_full') {
                    return; // They stay in the inbox until there's room.
                }
                try {
                    $receivedAt = new DateTimeImmutable((string) ($held['item']['received_at'] ?? 'now'));
                    $spool->append('sensor-reading:'.$held['file'], $spool->nextSourceSequence(), $held['item'], $receivedAt);
                } catch (SpoolFull) {
                    return;
                }
                $inbox->remove($held['file']);
            }
        }
    }

    /**
     * S&D Phase E (D21): sensor readings must never cross the public internet, so once a day the
     * hub tries Oblivion's public address on ports 1883 and 8883. Both must refuse. The result goes
     * up with the next batch; Setup › Sensor broker shows it and an answer alerts IT.
     */
    private function checkBrokerExposure(string $stateDirectory, EncryptedSpool $spool, string $centralUrl): void
    {
        $stamp = $stateDirectory.DIRECTORY_SEPARATOR.'broker-exposure-check.json';
        $last = is_file($stamp) ? json_decode((string) file_get_contents($stamp), true) : null;
        $now = new DateTimeImmutable('now');
        if (is_array($last) && is_int($last['checked_at'] ?? null) && $last['checked_at'] > $now->getTimestamp() - 86_400) {
            return;
        }
        $host = parse_url($centralUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '' || $spool->status()['state'] === 'buffer_full') {
            return;
        }
        $ports = [];
        foreach ([1883, 8883] as $port) {
            $target = 'tcp://'.(str_contains($host, ':') ? "[{$host}]" : $host).':'.$port;
            $socket = @stream_socket_client($target, $errorNumber, $errorText, 3);
            $ports[(string) $port] = is_resource($socket) ? 'answered' : 'refused';
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        try {
            $spool->append('broker-exposure:'.$now->format('Ymd'), $spool->nextSourceSequence(), [
                'item_type' => 'broker_exposure_check',
                'host' => substr($host, 0, 253),
                'ports' => $ports,
                'checked_at' => $now->format(DATE_ATOM),
            ], $now);
        } catch (SpoolFull) {
            return;
        }
        $this->writePrivateFile($stamp, json_encode(['checked_at' => $now->getTimestamp()], JSON_THROW_ON_ERROR));
    }

    private function wipeRemovedHub(string $configPath, string $stateDirectory, string &$secretKey): int
    {
        foreach ([$configPath, $stateDirectory.DIRECTORY_SEPARATOR.'hub-instructions.json', $stateDirectory.DIRECTORY_SEPARATOR.self::RELAY_LOGINS_FILE] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        // Sensor readings still waiting to upload go too: a removed hub keeps nothing.
        foreach (glob($stateDirectory.DIRECTORY_SEPARATOR.'relay-inbox'.DIRECTORY_SEPARATOR.'*') ?: [] as $held) {
            @unlink($held);
        }
        $this->writePrivateFile($stateDirectory.DIRECTORY_SEPARATOR.'removed.json', json_encode([
            'removed' => true,
            'noticed_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'message' => 'This hub was removed in Oblivion. Its saved logins have been wiped. Uninstall Oblivion from this computer.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        sodium_memzero($secretKey);
        $this->write('collection: removed'.PHP_EOL);

        return 3;
    }

    /**
     * What the Windows service or Linux launcher should do next: approval state and the update
     * it was told to install (window, ring, hold, roll-back time).
     *
     * @param  array<string, mixed>|null  $heartbeat
     */
    private function writeHostInstructions(string $stateDirectory, ?array $heartbeat): void
    {
        if ($heartbeat === null) {
            return;
        }
        $this->writePrivateFile($stateDirectory.DIRECTORY_SEPARATOR.'hub-instructions.json', json_encode([
            'approval' => in_array($heartbeat['approval'] ?? null, ['waiting', 'approved'], true) ? $heartbeat['approval'] : 'approved',
            'update' => is_array($heartbeat['update'] ?? null) ? $heartbeat['update'] : null,
            'received_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'running_version' => self::buildVersion(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * The service hands over the sealed login-store key it fetched at start, on standard
     * input, so a restart while offline doesn't need the server. --vault-stdin turns it on.
     *
     * @param  array<string, string|bool>  $options
     */
    private function hostSealedVaultKey(array $options): ?string
    {
        if (($options['vault-stdin'] ?? false) !== true) {
            return null;
        }
        $line = fgets(STDIN, 1024);
        $line = is_string($line) ? trim($line) : '';

        return $line === '' ? null : $line;
    }

    /** The version stamped by the build (collector/VERSION), else the source default. */
    public static function buildVersion(): string
    {
        $file = dirname(__DIR__).DIRECTORY_SEPARATOR.'VERSION';
        $stamped = is_file($file) ? trim((string) file_get_contents($file, false, null, 0, 64)) : '';

        return preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/', $stamped) === 1 ? $stamped : self::VERSION;
    }

    /**
     * update-fetch (S&D Phase B, D3): downloads the release file this hub was told to install,
     * on the signed mTLS path, into a file inside the state folder. It refuses any file not
     * named in hub-instructions.json's current target, checks size and SHA-256 while it
     * streams, and renames the file into place only once both match. The Go host then checks
     * the package's own signature before it installs anything.
     *
     * @param  array<string, string|bool>  $options
     */
    private function updateFetch(array $options): int
    {
        if (array_diff(array_keys($options), ['identity', 'artifact', 'output']) !== []) {
            throw new RuntimeException('Update download contains an unknown option.');
        }
        $identityPath = $this->requiredPath($options, 'identity');
        $identity = $this->identity($identityPath, requireRuntime: true);
        $stateDirectory = $this->stateDirectory($identity, $identityPath);
        $artifact = $this->instructedArtifact($stateDirectory, $this->requiredString($options, 'artifact'));
        $output = $this->updateOutputPath($stateDirectory, $this->requiredString($options, 'output'));
        $secretKey = $this->requestSigningKey($identity);
        $temporary = $output.'.part-'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        try {
            if ($handle === false) {
                throw new RuntimeException('The update file could not be created.');
            }
            $hash = hash_init('sha256');
            $size = 0;
            $this->central($identity, $secretKey)->downloadUpdate(
                $identity['collector_id'],
                $artifact['id'],
                $artifact['sha256'],
                function (string $chunk) use ($handle, $hash, &$size, $artifact): bool {
                    $size += strlen($chunk);
                    if ($size > $artifact['size']) {
                        return false;
                    }
                    hash_update($hash, $chunk);

                    return $this->writeAll($handle, $chunk);
                },
            );
            if ($size !== $artifact['size'] || ! hash_equals($artifact['sha256'], hash_final($hash))) {
                throw new RuntimeException('The downloaded update does not match its size and SHA-256.');
            }
            $synced = fflush($handle) && fsync($handle);
            $closed = fclose($handle);
            $handle = null;
            if (! $synced || ! $closed || ! rename($temporary, $output)) {
                throw new RuntimeException('The update file could not be saved.');
            }
        } finally {
            sodium_memzero($secretKey);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        $this->write("update-fetch: downloaded and checked\n");

        return 0;
    }

    /**
     * update-report (S&D Phase B, D3): tells Oblivion how an update went, so the Go host needs
     * no signing code of its own. A roll-back or failure raises an alert for IT.
     *
     * @param  array<string, string|bool>  $options
     */
    private function updateReport(array $options): int
    {
        if (array_diff(array_keys($options), ['identity', 'outcome', 'from', 'to', 'detail']) !== []) {
            throw new RuntimeException('Update report contains an unknown option.');
        }
        $version = '/\A(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})\z/';
        $outcome = $this->requiredString($options, 'outcome');
        $to = $this->requiredString($options, 'to');
        $from = $options['from'] ?? null;
        $detail = $options['detail'] ?? null;
        if (! in_array($outcome, ['installed', 'rolled_back', 'failed'], true) || preg_match($version, $to) !== 1
            || ($from !== null && (! is_string($from) || preg_match($version, $from) !== 1))
            || ($detail !== null && ! is_string($detail))) {
            throw new RuntimeException('Update report options are invalid.');
        }
        if (is_string($detail)) {
            // Plain text only, at most 500 characters (the server's limit).
            $detail = substr(trim((string) preg_replace('/[^\x20-\x7e]+/', ' ', $detail)), 0, 500);
        }
        $identityPath = $this->requiredPath($options, 'identity');
        $identity = $this->identity($identityPath, requireRuntime: true);
        $secretKey = $this->requestSigningKey($identity);
        try {
            $this->central($identity, $secretKey)->updateReport($identity['collector_id'], [
                'outcome' => $outcome,
                'from_version' => $from,
                'to_version' => $to,
                'detail' => $detail === '' ? null : $detail,
            ]);
        } finally {
            sodium_memzero($secretKey);
        }
        $this->write("update-report: recorded\n");

        return 0;
    }

    /**
     * Uninstalling the hub tells Oblivion, which removes it and stops trusting it (windows tab,
     * E). Exit 0 when Oblivion confirms, or says the hub was already removed (410); 1 otherwise,
     * so the uninstaller can say it couldn't tell Oblivion.
     *
     * @param  array<string, string|bool>  $options
     */
    private function deregister(array $options): int
    {
        if (array_keys($options) !== ['identity']) {
            throw new RuntimeException('Deregister takes only --identity.');
        }
        $identityPath = $this->requiredPath($options, 'identity');
        $identity = $this->identity($identityPath, requireRuntime: true);
        $secretKey = $this->requestSigningKey($identity);
        try {
            $this->central($identity, $secretKey)->deregister($identity['collector_id']);
        } catch (CentralApiFailure $failure) {
            if ($failure->httpStatus() !== 410) {
                $this->write("deregister: not confirmed\n");

                return 1;
            }
        } finally {
            sodium_memzero($secretKey);
        }
        $this->write("deregister: removed\n");

        return 0;
    }

    /**
     * The file named --artifact in the current update target of hub-instructions.json.
     *
     * @return array{id: string, size: int, sha256: string}
     */
    private function instructedArtifact(string $stateDirectory, string $artifactId): array
    {
        try {
            $instructions = json_decode($this->readBounded($stateDirectory.DIRECTORY_SEPARATOR.'hub-instructions.json'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The hub update instructions are unreadable.', previous: $exception);
        }
        $update = is_array($instructions) ? ($instructions['update'] ?? null) : null;
        $target = is_array($update) ? ($update['target'] ?? null) : null;
        if (! is_array($update) || ($update['held'] ?? null) !== false || ! is_array($target) || ! is_array($target['artifacts'] ?? null)) {
            throw new RuntimeException('No update is waiting for this hub.');
        }
        foreach ($target['artifacts'] as $artifact) {
            if (! is_array($artifact) || ($artifact['id'] ?? null) !== $artifactId) {
                continue;
            }
            if (preg_match('/\A[a-z0-9][a-z0-9-]{1,63}\z/', $artifactId) !== 1
                || ! is_int($artifact['size'] ?? null) || $artifact['size'] < 1 || $artifact['size'] > self::MAX_UPDATE_BYTES
                || ! is_string($artifact['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) !== 1) {
                throw new RuntimeException('The update instructions for that file are invalid.');
            }

            return ['id' => $artifactId, 'size' => $artifact['size'], 'sha256' => $artifact['sha256']];
        }
        throw new RuntimeException('That file is not in this hub\'s update instructions.');
    }

    /** The update file goes straight into an existing folder inside the state folder. */
    private function updateOutputPath(string $stateDirectory, string $output): string
    {
        $fold = fn (string $path): string => PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        $state = realpath($stateDirectory);
        $folder = realpath(dirname($output));
        $name = basename($output);
        if ($state === false || $folder === false || ! is_dir($folder)
            || ($fold($folder) !== $fold($state) && ! str_starts_with($fold($folder), $fold(rtrim($state, '\\/').DIRECTORY_SEPARATOR)))
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,100}\z/', $name) !== 1) {
            throw new RuntimeException('The update file must be saved inside the hub\'s state folder.');
        }
        $target = $folder.DIRECTORY_SEPARATOR.$name;
        if (is_link($target) || is_dir($target)) {
            throw new RuntimeException('The update file must be saved inside the hub\'s state folder.');
        }

        return $target;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $chunk): bool
    {
        $offset = 0;
        while ($offset < strlen($chunk)) {
            $written = fwrite($handle, substr($chunk, $offset));
            if (! is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    /** @param array<string, mixed> $identity */
    private function requestSigningKey(array $identity): string
    {
        $secretKey = base64_decode($identity['request_signing_secret_key'], true);
        if (! is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Collector request signing key is invalid.');
        }

        return $secretKey;
    }

    /** @param array<string, mixed> $identity */
    private function central(array $identity, string $secretKey): HttpsCentralApi
    {
        return new HttpsCentralApi(
            $identity['central_url'],
            $identity['tls_public_key_pin'],
            $secretKey,
            $identity['client_certificate_file'],
            $identity['client_private_key_file'],
            $this->updateTransport,
        );
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
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        $certificate = $this->readBounded($identity['client_certificate_file']);
        $signingKeyReference = hash('sha256', $publicKey);
        $identityGenerationReference = hash(
            'sha256',
            strtolower($identity['collector_id'])."\0".hash('sha256', $certificate)."\0".$signingKeyReference,
        );
        try {
            $evidence = $this->transportEvidenceTransport !== null
                ? ($this->transportEvidenceTransport)(
                    $identity['collector_id'],
                    $expectedState,
                    $samples,
                    $signingKeyReference,
                    $identityGenerationReference,
                )
                : (new HttpsCentralApi(
                    $identity['central_url'],
                    $identity['tls_public_key_pin'],
                    $secretKey,
                    $identity['client_certificate_file'],
                    $identity['client_private_key_file'],
                ))->verifyTransport(
                    $identity['collector_id'],
                    $expectedState,
                    $samples,
                    $signingKeyReference,
                    $identityGenerationReference,
                );
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
            $stream = fopen('php://fd/'.(int) $descriptor, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('One-time enrolment token is unavailable.');
            }
            try {
                $token = stream_get_contents($stream, 4097);
            } finally {
                fclose($stream);
            }
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
        $this->write("Usage: oblivion-collector enrol|enrol-profile|enrol-recoverable|runtime-check|run|update-fetch|update-report|deregister|doctor|verify-transport|version [--name=value]\n", STDERR);

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
