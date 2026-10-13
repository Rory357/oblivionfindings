<?php

// Original producer/collector/consumer fixture. All keys, credentials and device
// records are synthetic; this neither boots Laravel nor opens a database.
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionRecorderDiscoveryContract;
use Carbon\CarbonImmutable;
use Oblivion\Collector\Media\HikvisionRecorderDiscoveryExchange;
use Oblivion\Collector\Media\PrivateFrames;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
require $root.'/collector/vendor/autoload.php';
try {
    $input = json_decode(stream_get_contents(STDIN, 65537), true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($input)) {
        throw new RuntimeException;
    }
    $issued = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $input['issued_at'], 'UTC');
    $serverPair = sodium_crypto_sign_seed_keypair(str_repeat("\x35", 32));
    $collectorPair = sodium_crypto_sign_seed_keypair(str_repeat("\x46", 32));
    $identity = ['collector_id' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1];
    $collector = new MonitoringCollector;
    $collector->setRawAttributes(['collector_uuid' => $identity['collector_id'], 'site_id' => 1, 'public_key' => base64_encode(sodium_crypto_sign_publickey($collectorPair))]);
    $binding = ['collector_uuid' => $identity['collector_id'], 'site_id' => 1, 'device_id' => 2, 'discovery_scope_id' => 3, 'request_uuid' => '44444444-4444-4444-8444-444444444444', 'context_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64)];
    $adapter = new HikvisionRecorderDiscoveryExchange(new CredentialLeaseDecryptor(sodium_crypto_sign_secretkey($collectorPair)), new EnvelopeVerifier(sodium_crypto_sign_publickey($serverPair)));
    if (($input['mode'] ?? null) === 'produce') {
        if ($input['allowed_ip'] !== '127.0.0.1' && $input['allowed_ip'] !== '::1') {
            throw new RuntimeException;
        }
        $source = ['allowed_ip' => $input['allowed_ip'], 'allowed_port' => $input['allowed_port'], 'tls_server_name' => $input['tls_server_name'], 'tls_pin' => $input['tls_pin'], 'username' => 'fixture-isapi-user', 'password' => 'fixture-isapi-secret'];
        $operation = HikvisionRecorderDiscoveryContract::issue($collector, $binding, $source, $issued, $issued->addSeconds(10), sodium_crypto_sign_secretkey($serverPair));
        PrivateFrames::write(STDOUT, json_encode(['version' => 1, 'operation' => 'configure', 'collector_uuid' => $identity['collector_id'], 'site_id' => 1, 'server_public_key' => base64_encode(sodium_crypto_sign_publickey($serverPair))], JSON_THROW_ON_ERROR));
        $frame = $adapter->frame($operation, $identity, $issued->toDateTimeImmutable());
        try {
            PrivateFrames::write(STDOUT, $frame);
        } finally {
            sodium_memzero($frame);
        }
    } elseif (($input['mode'] ?? null) === 'consume') {
        $source = ['allowed_ip' => '127.0.0.1', 'allowed_port' => 443, 'tls_server_name' => 'recorder.example.test', 'tls_pin' => str_repeat('a', 64), 'username' => 'fixture-isapi-user', 'password' => 'fixture-isapi-secret'];
        $operation = HikvisionRecorderDiscoveryContract::issue($collector, $binding, $source, $issued, $issued->addSeconds(10), sodium_crypto_sign_secretkey($serverPair));
        $result = HikvisionRecorderDiscoveryContract::result($input['result']);
        if ($result !== $input['result'] || $result['inventory']['identity']['serial'] !== 'fixture-recorder-serial') {
            throw new RuntimeException;
        }
        $reply = fopen('php://memory', 'w+b');
        $wire = fopen('php://memory', 'w+b');
        try {
            PrivateFrames::write($reply, json_encode($result, JSON_THROW_ON_ERROR));
            rewind($reply);
            if ($adapter->exchange($operation, $identity, $reply, $wire, $issued->toDateTimeImmutable()) !== $result) {
                throw new RuntimeException;
            }
        } finally {
            fclose($reply);
            fclose($wire);
        }
        echo "accepted\n";
    } else {
        throw new RuntimeException;
    }
} catch (Throwable) {
    fwrite(STDERR, "Synthetic recorder discovery fixture unavailable.\n");
    exit(1);
}
