<?php

return [
    // Configure an absolute, owner-managed official qpdf executable. No shell or PATH lookup.
    'qpdf_path' => env('EMAR_BACKUP_QPDF_PATH'),
    // Deployment owners must separately enable a reviewed transport; building never sends mail.
    'send_enabled' => (bool) env('EMAR_BACKUP_SEND_ENABLED', false),
    'maximum_pdf_bytes' => 20 * 1024 * 1024,
];
