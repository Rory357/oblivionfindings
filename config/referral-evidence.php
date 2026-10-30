<?php

return [
    'malware_scanner' => [
        'binary' => env('REFERRAL_EVIDENCE_SCANNER_BINARY'),
        'name' => env('REFERRAL_EVIDENCE_SCANNER_NAME', 'clamav'),
        'fd_pass' => (bool) env('REFERRAL_EVIDENCE_SCANNER_FD_PASS', false),
        'timeout_seconds' => (int) env('REFERRAL_EVIDENCE_SCANNER_TIMEOUT', 30),
    ],
];
