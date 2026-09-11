<?php

/** Read-only reconciliation for exact synthetic browser records. No secrets/body output. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local')
    || strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') !== 0
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'oblivion_findings_codex_test'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('mail.default') !== 'array' || config('queue.default') !== 'sync'
    || app()->configurationIsCached()) {
    throw new RuntimeException('Expected isolated local browser runtime is not active.');
}

DB::statement('SET TRANSACTION READ ONLY');
DB::beginTransaction();
try {
    $result = [
        'read_at' => now()->toIso8601String(),
        'database_mutations_performed' => false,
        'teams' => DB::table('it_teams')->where('name', 'IT Support W03 synthetic desk')
            ->get(['id', 'name', 'manager_user_id', 'is_active']),
        'queues' => DB::table('it_queues')->where('key', 'it-support-w03-synthetic-fallback')
            ->get(['id', 'key', 'name', 'team_id', 'is_active', 'filter_rules']),
        'articles' => DB::table('it_kb_articles')->whereIn('id', [8, 9])
            ->where('slug', 'like', 'it-support-w01-fine-20260909%')
            ->get(['id', 'title', 'status', 'audience', 'site_scope', 'author_user_id', 'owner_user_id', 'review_started_at', 'published_at', 'retired_at']),
    ];
    DB::rollBack();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    DB::rollBack();
    throw $error;
}
