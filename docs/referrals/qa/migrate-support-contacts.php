<?php

require __DIR__.'/inspect-delivery.php';

use Illuminate\Contracts\Console\Kernel;

$exit = app(Kernel::class)->call('migrate', ['--path' => 'database/migrations/2026_10_18_201000_create_client_support_contacts.php', '--force' => true]);
echo app(Kernel::class)->output();
if ($exit !== 0) {
    throw new RuntimeException('Preview support contact migration failed');
}
