<?php

namespace App\Infrastructure\Monitoring;

use App\Domain\Monitoring\Contracts\CollectorCertificateIssuer;
use App\Domain\Monitoring\Data\CollectorCertificateBundle;
use Carbon\CarbonImmutable;
use RuntimeException;

final class OpenSslCollectorCertificateIssuer implements CollectorCertificateIssuer
{
    public function issue(string $collectorUuid): CollectorCertificateBundle
    {
        $certificatePath = config('monitoring.collector.ca_certificate_path');
        $privateKeyPath = config('monitoring.collector.ca_private_key_path');
        if (! is_string($certificatePath) || ! is_file($certificatePath)
            || ! is_string($privateKeyPath) || ! is_file($privateKeyPath)) {
            throw new RuntimeException('Collector certificate authority is unavailable.');
        }

        $caCertificatePem = $this->readBounded($certificatePath, 262_144);
        $caPrivateKeyPem = $this->readBounded($privateKeyPath, 262_144);
        $passphrase = config('monitoring.collector.ca_private_key_passphrase');
        $caCertificate = openssl_x509_read($caCertificatePem);
        $caPrivateKey = openssl_pkey_get_private(
            $caPrivateKeyPem,
            is_string($passphrase) ? $passphrase : '',
        );
        if ($caCertificate === false || $caPrivateKey === false) {
            throw new RuntimeException('Collector certificate authority is invalid.');
        }

        $privateKey = openssl_pkey_new([
            'config' => base_path('resources/monitoring/collector-openssl.cnf'),
            'private_key_bits' => 3072,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ]);
        if ($privateKey === false) {
            throw new RuntimeException('Collector private key generation failed.');
        }
        $request = openssl_csr_new([
            'commonName' => 'oblivion-collector-'.$collectorUuid,
            'organizationName' => 'Oblivion Findings',
            'organizationalUnitName' => 'Monitoring Collectors',
        ], $privateKey, [
            'config' => base_path('resources/monitoring/collector-openssl.cnf'),
            'digest_alg' => 'sha256',
        ]);
        if ($request === false) {
            throw new RuntimeException('Collector certificate request generation failed.');
        }
        $days = max(1, min(397, (int) config('monitoring.collector.certificate_lifetime_days', 90)));
        $certificate = openssl_csr_sign(
            $request,
            $caCertificate,
            $caPrivateKey,
            $days,
            [
                'config' => base_path('resources/monitoring/collector-openssl.cnf'),
                'digest_alg' => 'sha256',
                'x509_extensions' => 'v3_collector',
            ],
            random_int(1, PHP_INT_MAX),
        );
        if ($certificate === false
            || ! openssl_x509_export($certificate, $certificatePem)
            || ! openssl_pkey_export($privateKey, $privateKeyPem)) {
            throw new RuntimeException('Collector certificate export failed.');
        }
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new RuntimeException('Collector certificate fingerprint is invalid.');
        }

        return new CollectorCertificateBundle(
            certificatePem: $certificatePem,
            privateKeyPem: $privateKeyPem,
            fingerprint: strtolower($fingerprint),
            expiresAt: CarbonImmutable::now('UTC')->addDays($days),
        );
    }

    private function readBounded(string $path, int $maximumBytes): string
    {
        $size = filesize($path);
        $contents = $size !== false && $size <= $maximumBytes ? file_get_contents($path) : false;
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Collector certificate authority material is unreadable.');
        }

        return $contents;
    }
}
