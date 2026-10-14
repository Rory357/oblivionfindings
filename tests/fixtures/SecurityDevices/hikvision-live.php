<?php

// Synthetic original PHP issuer -> sealed collector adapter -> native worker
// fixture. No application boot/database, production identity or camera acceptance.
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionLiveContract;
use Carbon\CarbonImmutable;
use Oblivion\Collector\Media\HikvisionLiveExchange;
use Oblivion\Collector\Media\PrivateFrames;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
require $root.'/collector/vendor/autoload.php';
try {
    $input = json_decode(stream_get_contents(STDIN, 65537), true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($input) || ! in_array($input['source']['allowed_ip'], ['127.0.0.1', '::1'], true) || $input['source']['username'] !== 'fixture-reader-auth' || $input['source']['password'] !== 'fixture-secret') {
        throw new RuntimeException;
    }
    $issued = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $input['issued_at'], 'UTC');
    $server = sodium_crypto_sign_seed_keypair(str_repeat("\x35", 32));
    $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x46", 32));
    $collector = new MonitoringCollector;
    $collector->setRawAttributes(['collector_uuid' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    $binding = ['collector_uuid' => $collector->collector_uuid, 'site_id' => 1, 'camera_id' => 2, 'discovery_scope_id' => 3, 'source_id' => 4, 'source_revision' => 1, 'source_fingerprint' => str_repeat('a', 64), 'binding_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64), 'support_sha256' => str_repeat('d', 64), 'discovery_result_sha256' => str_repeat('e', 64), 'identity' => $input['identity'], 'channel' => $input['channel'], 'session_uuid' => '22222222-2222-4222-8222-222222222222', 'negotiation_uuid' => '33333333-3333-4333-8333-333333333333', 'generation' => 1, 'session_expires_at' => $issued->addMinute()->format('Y-m-d\TH:i:s\Z')];
    $request = '44444444-4444-4444-8444-444444444444';
    $operation = HikvisionLiveContract::issue($collector, $binding, $input['source'], $input['turn'], $input['offer_sdp'], $request, $issued, $issued->addSeconds(15), sodium_crypto_sign_secretkey($server));
    $identity = ['collector_id' => $collector->collector_uuid, 'site_id' => 1];
    $adapter = new HikvisionLiveExchange(new CredentialLeaseDecryptor(sodium_crypto_sign_secretkey($pair)), new EnvelopeVerifier(sodium_crypto_sign_publickey($server)));
    if ($input['mode'] === 'produce') {
        PrivateFrames::write(STDOUT, json_encode(['version' => 1, 'operation' => 'configure', 'collector_uuid' => $collector->collector_uuid, 'site_id' => 1, 'server_public_key' => base64_encode(sodium_crypto_sign_publickey($server))], JSON_THROW_ON_ERROR));
        $frame = $adapter->frame($operation, $identity, $issued->toDateTimeImmutable());
        try {
            PrivateFrames::write(STDOUT, $frame);
        } finally {
            sodium_memzero($frame);
        }
    } elseif ($input['mode'] === 'consume') {
        if ($adapter->result($input['result'], $operation) !== $input['result'] || $input['result']['status'] !== 'ready') {
            throw new RuntimeException;
        }
        CctvContract::relaySdp($input['result']['answer']['sdp'], $input['turn'], 'sendonly');
        echo "accepted\n";
    } else {
        throw new RuntimeException;
    }
} catch (Throwable) {
    fwrite(STDERR, "Synthetic live fixture unavailable.\n");
    exit(1);
}
