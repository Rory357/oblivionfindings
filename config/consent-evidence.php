<?php

return [
    /*
    | Consent evidence is fail closed: uploads are unavailable until a
    | ClamAV-compatible scanner binary is configured on the application host.
    | The binary is invoked directly (never through a shell).
    */
    'malware_scanner' => [
        'binary' => env('CONSENT_EVIDENCE_SCANNER_BINARY'),
        'name' => env('CONSENT_EVIDENCE_SCANNER_NAME', 'clamav'),
        'fd_pass' => (bool) env('CONSENT_EVIDENCE_SCANNER_FD_PASS', false),
        'timeout_seconds' => (int) env('CONSENT_EVIDENCE_SCANNER_TIMEOUT', 30),
    ],
];
