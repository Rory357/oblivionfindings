<?php

require __DIR__.'/inspect-delivery.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$before = DB::table('clients')->orderBy('id')->get(['id', 'status', 'service_start_date'])->toJson();
$exit = app(Kernel::class)->call('migrate', ['--path' => 'database/migrations/2026_10_18_180000_default_new_clients_to_onboarding.php', '--force' => true]);
if ($exit !== 0 || $before !== DB::table('clients')->orderBy('id')->get(['id', 'status', 'service_start_date'])->toJson()) {
    throw new RuntimeException('Migration failed or changed an existing person.');
}
echo json_encode(['passed' => true, 'existing_people_unchanged' => true, 'status_column' => collect(Schema::getColumns('clients'))->firstWhere('name', 'status')]).PHP_EOL;
