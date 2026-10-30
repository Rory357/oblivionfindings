<?php

require __DIR__.'/inspect-delivery.php';

use Illuminate\Contracts\Console\Kernel;

$exit = app(Kernel::class)->call('migrate', ['--path' => 'database/migrations/2026_10_18_190000_add_client_contact_preferences.php', '--force' => true]);
echo app(Kernel::class)->output();
if ($exit !== 0) {
    throw new RuntimeException('Preview contact migration failed');
}
