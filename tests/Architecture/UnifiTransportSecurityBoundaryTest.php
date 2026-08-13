<?php

it('keeps every UniFi HTTP request behind explicit certificate and hostname verification', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $adapter = file_get_contents($root.'/app/Services/Integration/Adapters/UnifiAdapter.php');
    $transport = file_get_contents($root.'/app/Services/Integration/UnifiTransportSecurity.php');
    $config = file_get_contents($root.'/config/integration-capabilities.php');
    $sources = $adapter."\n".$transport."\n".$config;

    expect($adapter)
        ->toContain('private readonly UnifiTransportSecurity $transport')
        ->toContain('return $this->transport->request($headers);')
        ->not->toContain('Http::');
    expect($transport)
        ->toContain('Http::withoutRedirecting()')
        ->toContain("'verify' => \$this->verificationOption()")
        ->toContain('is_file($resolved)')
        ->toContain('is_readable($resolved)')
        ->toContain('openssl_x509_read($certificate)');
    expect($config)
        ->toContain("'ca_bundle' => env('UNIFI_CA_BUNDLE')")
        ->not->toContain('UNIFI_VERIFY')
        ->not->toContain('UNIFI_INSECURE');
    expect($sources)->not->toMatch(
        '/withoutVerifying\s*\(|[\'\"]verify[\'\"]\s*=>\s*false|CURLOPT_SSL_VERIFYPEER\s*=>\s*(?:false|0)|CURLOPT_SSL_VERIFYHOST\s*=>\s*(?:false|0)/',
    );
});
