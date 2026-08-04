<?php

it('keeps direct transports pinned bounded and shell free', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $transportRoot = $root.'/app/Domain/Monitoring/Transports';
    $files = [
        'NativeIcmpTransport.php',
        'NativeTcpTransport.php',
        'NativeDnsTransport.php',
        'NativeHttpTransport.php',
        'NativeTlsTransport.php',
    ];

    foreach ($files as $file) {
        $source = file_get_contents($transportRoot.'/'.$file);

        expect($source)
            ->toContain('AuthorizedProbeTarget $target')
            ->not->toContain('shell_exec(', 'exec(', 'system(', 'passthru(', 'proc_open(', 'Process::fromShellCommandline');
    }

    $snmp = file_get_contents($root.'/app/Domain/Monitoring/Protocols/Snmp/NativeSnmpTransport.php');
    expect($snmp)
        ->toContain('AuthorizedProbeTarget $target', 'class_exists(\\SNMP::class)', 'SNMP_VALUE_PLAIN')
        ->not->toContain('shell_exec(', 'exec(', 'system(', 'passthru(', 'proc_open(', 'Process::fromShellCommandline');

    $icmp = file_get_contents($transportRoot.'/NativeIcmpTransport.php');
    $http = file_get_contents($transportRoot.'/NativeHttpTransport.php');
    $tls = file_get_contents($transportRoot.'/NativeTlsTransport.php');

    expect($icmp)->toContain('new Process($command)')
        ->and($http)->toContain("'allow_redirects' => false", "'proxy' => ''", "'verify' => true", 'CURLOPT_RESOLVE')
        ->and($tls)->toContain("'verify_peer' => true", "'verify_peer_name' => true", "'peer_name' => \$target->host");
});

it('keeps direct probe evidence scalar and rejects sensitive field names', function () {
    $source = file_get_contents(
        str_replace('\\', '/', dirname(__DIR__, 2)).'/app/Domain/Monitoring/Data/ProtocolObservation.php',
    );

    expect($source)
        ->toContain('! is_scalar($evidenceValue)')
        ->toContain('body|authorization|cookie|credential|password|secret|token|certificate|raw_');
});
