<?php

require __DIR__.'/inspect-delivery.php';

use Illuminate\Contracts\Console\Kernel;

$exit = app(Kernel::class)->call('migrate', ['--path' => 'database/migrations/2026_10_18_193000_link_referral_evidence_to_client_documents.php', '--force' => true]);
echo app(Kernel::class)->output();
if ($exit !== 0) {
    throw new RuntimeException('Preview document handover migration failed');
}
