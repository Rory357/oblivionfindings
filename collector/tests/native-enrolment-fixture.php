<?php

// Executed only by the native Go interoperability test. No network transport.
require dirname(__DIR__).'/vendor/autoload.php';

use Oblivion\Collector\CollectorApplication;
use Oblivion\Collector\Enrollment\RecoverableEnrollment;
use Oblivion\Collector\Exceptions\CentralApiFailure;

$fixture = getenv('OBLI_TEST_PAYLOAD_FILE');
if (! is_string($fixture) || ! is_file($fixture)) {
    exit(90);
}
$application = new CollectorApplication(output: function (string $message) use ($fixture): void {
    file_put_contents($fixture.'.output', $message, FILE_APPEND | LOCK_EX);
}, recoverableEnrollmentTransport: function ($origin, $pin, $token, $uuid, $public, $proof) use ($fixture): array {
    $data = json_decode(file_get_contents($fixture), true, 16, JSON_THROW_ON_ERROR);
    if ($data['origin'] !== $origin || $data['pin'] !== $pin || $data['payload']['collector_id'] !== $uuid
        || ! hash_equals($data['token_hash'], hash('sha256', $token))
        || ! sodium_crypto_sign_verify_detached($proof, RecoverableEnrollment::DOMAIN.$uuid."\n".hash('sha256', $token)."\n".base64_encode($public), $public)) {
        throw new RuntimeException('Fixture transport binding failed.');
    }
    file_put_contents($fixture.'.calls', "request\n", FILE_APPEND | LOCK_EX);
    if ($data['revoked']) {
        throw new CentralApiFailure('Fixture grant revoked.');
    }

    return [
        'version' => 2, 'collector_id' => $uuid, 'recipient_key_fingerprint' => hash('sha256', $public),
        'sealed_identity' => base64_encode(sodium_crypto_box_seal(json_encode($data['payload'], JSON_THROW_ON_ERROR), sodium_crypto_sign_ed25519_pk_to_curve25519($public))),
    ];
});
exit($application->run($argv));
