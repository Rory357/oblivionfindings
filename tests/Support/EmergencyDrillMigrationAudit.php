<?php

// Standalone CLI audit, deliberately separate from the native feature suite.
require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$workspace = 'C:/Users/steph/.codex/worktrees/health-safety-workspace/oblivionfindings';
if (PHP_SAPI !== 'cli' || strtolower(str_replace('\\', '/', realpath(getcwd()))) !== strtolower($workspace)) {
    throw new RuntimeException('Run only from the approved isolated worktree.');
}
if (($argv[1] ?? '') !== '--execute-approved-shadow-audit') {
    throw new RuntimeException('Execution requires root-reviewed --execute-approved-shadow-audit; lint does not execute this helper.');
}
$source = 'oblivion_hs_preview_20261007_2118';
$schema = 'oblivion_hs_drillmigaudit_20261008_'.bin2hex(random_bytes(4));
if (! preg_match('/^oblivion_hs_drillmigaudit_20261008_[a-f0-9]{8}$/D', $schema)) {
    throw new RuntimeException('Invalid owned shadow identifier.');
}
$artifact = $argv[2] ?? 'storage/logs/hs-drill-migration-audit-r1.json';
if (! preg_match('~^storage/logs/hs-drill-migration-audit-r[0-9]+\.json$~D', $artifact)) {
    throw new RuntimeException('Use an approved migration audit artifact path.');
}
if (file_exists($artifact)) {
    throw new RuntimeException('Refuse to overwrite retained audit evidence; choose a fresh numbered artifact.');
}
$xml = simplexml_load_file('phpunit.xml');
$environment = [];
foreach ($xml->php->env as $node) {
    $environment[(string) $node['name']] = (string) $node['value'];
}
$host = $environment['DB_HOST'] ?? '127.0.0.1';
if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Only the approved local MySQL host is permitted.');
}
$port = $environment['DB_PORT'] ?? '3306';
$username = $environment['DB_USERNAME'] ?? 'root';
$password = $environment['DB_PASSWORD'] ?? '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$schema]);
if ($exists->fetchColumn() !== false) {
    throw new RuntimeException('Refuse a pre-existing audit schema.');
}
$report = ['schema' => $schema, 'source_schema' => $source, 'workspace' => $workspace, 'provenance' => 'Table definitions only; newly created empty synthetic shadow schema. Raw fixtures verify migration preservation, not business authorization.', 'started_at' => gmdate('c'), 'checks' => [], 'result' => 'pending'];
$created = false;
$connection = null;
$guard = function () use (&$created, &$connection, $schema) {
    if (! $created || ! preg_match('/^oblivion_hs_drillmigaudit_20261008_[a-f0-9]{8}$/D', $schema) || ! $connection || $connection->getDatabaseName() !== $schema || $connection->selectOne('SELECT DATABASE() AS name')->name !== $schema || DB::getDefaultConnection() !== 'mysql') {
        throw new RuntimeException('Audit database guard failed; refuse migration or cleanup.');
    }
};
$check = function (bool $condition, string $label) use (&$report) {
    $report['checks'][$label] = $condition;
    if (! $condition) {
        throw new RuntimeException($label);
    }
};
$run = function ($migration, string $direction) use ($guard, &$report) {
    $guard();
    $report['operations'][] = get_class($migration).':'.$direction;
    $migration->{$direction}();
};
$insert = function (string $table, array $data) use (&$connection, $guard) {
    $guard();
    foreach ($connection->select('SHOW COLUMNS FROM `'.$table.'`') as $column) {
        if (array_key_exists($column->Field, $data) || $column->Null === 'YES' || $column->Default !== null || str_contains($column->Extra, 'auto_increment')) {
            continue;
        }
        $type = strtolower($column->Type);
        $data[$column->Field] = preg_match("/^enum\\('([^']*)'/", $type, $match) ? $match[1] : (str_contains($type, 'int') || str_contains($type, 'decimal') ? 1 : (str_contains($type, 'date') || str_contains($type, 'time') ? '2026-10-08 00:00:00' : ($type === 'json' ? '[]' : 'synthetic')));
    }
    $connection->table($table)->insert($data);
};
// Only the final additive drill migration is applied; no full migrator/seeder/jobs.
$tables = ['users', 'sites', 'hs_events', 'hs_corrective_actions', 'control_room_signals', 'audit_logs', 'emergency_drills', 'emergency_drill_participants', 'emergency_drill_findings', 'emergency_drill_attachments', 'emergency_drill_command_receipts', 'emergency_drill_failure_deliveries'];
$legacyTables = ['emergency_drills', 'emergency_drill_participants', 'emergency_drill_findings', 'emergency_drill_attachments', 'audit_logs'];
$snapshot = function (array $names) use (&$connection, $guard): array {
    $guard();
    $result = [];
    foreach ($names as $name) {
        $result[$name] = array_map(fn ($row) => (array) $row, $connection->table($name)->orderBy('id')->get()->all());
    }

    return $result;
};
$legacyUnchanged = function (array $baseline, string $phase) use (&$connection, $guard, $check): void {
    $guard();
    foreach ($baseline as $table => $rows) {
        $check($connection->table($table)->count() === count($rows), $phase.'_'.$table.'_row_count');
        foreach ($rows as $original) {
            $actual = (array) $connection->table($table)->find($original['id']);
            foreach ($original as $field => $value) {
                $check(array_key_exists($field, $actual) && $actual[$field] === $value, $phase.'_'.$table.'_'.$original['id'].'_'.$field.'_exact');
            }
        }
    }
};
try {
    file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    $server->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $sourcePdo = new PDO("mysql:host={$host};port={$port};dbname={$source};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($sourcePdo->query('SELECT DATABASE()')->fetchColumn() !== $source) {
        throw new RuntimeException('Source definition guard failed.');
    }
    $auditPdo = new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $auditPdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $definition = $sourcePdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $report['definition_sha256'][$table] = hash('sha256', $definition);
        $auditPdo->exec($definition);
        $check((int) $auditPdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() === 0, $table.'_definitions_only_empty');
    }
    $auditPdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_USERNAME' => $username, 'DB_PASSWORD' => $password, 'DB_DATABASE' => $schema, 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require 'bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.database' => $schema]);
    DB::purge('mysql');
    Schema::clearResolvedInstance('db.schema');
    $connection = DB::connection('mysql');
    $migration = require 'database/migrations/2026_10_08_180200_add_emergency_drill_lifecycle.php';
    $report['migration_sha256'] = hash_file('sha256', 'database/migrations/2026_10_08_180200_add_emergency_drill_lifecycle.php');
    // Reconstruct the pre-180200 schema from current definitions, with no rows.
    $run($migration, 'down');
    $guard();
    $connection->statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $insert('users', ['id' => 1, 'name' => 'Synthetic drill audit staff', 'email' => 'drill-audit@example.invalid', 'password' => 'not-a-login', 'role' => 'support_worker']);
        $insert('sites', ['id' => 1, 'name' => 'Synthetic drill audit Site']);
        $insert('hs_events', ['id' => 1, 'site_id' => 1, 'reference_number' => 'HS-DRILL-MIG-1']);
        $insert('hs_corrective_actions', ['id' => 1, 'hs_event_id' => 1, 'reference_number' => 'CA-DRILL-MIG-1']);
        $insert('control_room_signals', ['id' => 1]);
        foreach ([1 => ['scheduled', null], 2 => ['completed', 'failed'], 3 => ['cancelled', null]] as $id => [$status, $outcome]) {
            $insert('emergency_drills', ['id' => $id, 'site_id' => 1, 'drill_type' => 'fire', 'title' => 'Synthetic legacy drill '.$id, 'description' => 'Legacy description retained', 'scenario_description' => 'Legacy scenario retained', 'scheduled_at' => '2020-01-01 01:02:03', 'started_at' => $id === 2 ? '2020-01-01 01:03:04' : null, 'completed_at' => $id === 2 ? '2020-01-01 01:04:05' : null, 'status' => $status, 'outcome' => $outcome, 'total_participants' => 9, 'residents_evacuated' => 4, 'roll_call_completed' => false, 'all_areas_checked' => true, 'observer_notes' => 'Legacy notes retained', 'created_by' => 1]);
        }
        $insert('emergency_drill_participants', ['id' => 1, 'emergency_drill_id' => 2, 'user_id' => 1, 'role' => 'warden', 'attended' => true, 'notes' => 'Legacy attendance note']);
        foreach ([1 => 'open', 2 => 'resolved', 3 => 'closed', 4 => 'accepted'] as $id => $status) {
            $insert('emergency_drill_findings', ['id' => $id, 'emergency_drill_id' => 2, 'finding_type' => 'observation', 'description' => 'Synthetic legacy finding '.$id, 'status' => $status, 'severity' => 'medium', 'due_date' => '2020-01-01', 'resolved_at' => $status === 'open' ? null : '2020-01-02', 'resolution_notes' => $status === 'open' ? null : 'Legacy decision retained', 'created_by' => 1]);
        }
        $insert('emergency_drill_attachments', ['id' => 1, 'emergency_drill_id' => 2, 'uploaded_by' => 1, 'disk' => 'public', 'path' => 'synthetic/no-file-created.pdf', 'original_name' => 'synthetic-legacy.pdf', 'mime' => 'application/pdf', 'size' => 10, 'notes' => 'Legacy evidence metadata retained']);
        $insert('audit_logs', ['id' => 1, 'user_id' => 1, 'action' => 'emergencydrill.update', 'auditable_type' => 'App\\Models\\EmergencyDrill', 'auditable_id' => 2, 'meta' => json_encode(['legacy_note' => 'Original legacy history retained']), 'created_at' => '2020-01-01 01:04:05']);
    } finally {
        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }
    $baseline = $snapshot($legacyTables);
    $report['legacy_snapshot_sha256'] = hash('sha256', json_encode($baseline, JSON_THROW_ON_ERROR));
    $run($migration, 'up');
    $legacyUnchanged($baseline, 'additive_up');
    $check($connection->table('emergency_drills')->where('lock_version', 1)->count() === 3, 'legacy_default_lock_version_only');
    $check($connection->table('emergency_drill_command_receipts')->count() === 0 && $connection->table('emergency_drill_failure_deliveries')->count() === 0, 'no_manufactured_command_or_delivery_receipts');
    $run($migration, 'down');
    $legacyUnchanged($baseline, 'empty_new_evidence_down');
    $check(! Schema::hasColumn('emergency_drills', 'lock_version') && ! Schema::hasTable('emergency_drill_command_receipts'), 'empty_new_evidence_down_removes_only_additions');
    $run($migration, 'up');
    $legacyUnchanged($baseline, 'empty_new_evidence_up_again');
    $report['roundtrip_result'] = 'passed';
    $cases = [
        'creation_key' => ['emergency_drills', 1, ['creation_key' => '79f63c8e-48c8-4f47-8d06-7d8cde0ad144']],
        'creation_fingerprint_alone' => ['emergency_drills', 1, ['creation_fingerprint' => str_repeat('a', 64)]],
        'previous_drill_link' => ['emergency_drills', 2, ['previous_drill_id' => 1]],
        'cancelled_at' => ['emergency_drills', 3, ['cancelled_at' => '2026-10-08 00:00:00']],
        'cancelled_by_alone' => ['emergency_drills', 3, ['cancelled_by' => 1]],
        'cancellation_reason_alone' => ['emergency_drills', 3, ['cancellation_reason' => 'Synthetic retained cancellation reason']],
        'advanced_lock_version' => ['emergency_drills', 1, ['lock_version' => 2]],
        'participant_accommodations' => ['emergency_drill_participants', 1, ['accommodations' => 'Synthetic recorded participation support']],
        'participant_empty_accommodations' => ['emergency_drill_participants', 1, ['accommodations' => '']],
        'canonical_remedy_pointer' => ['emergency_drill_findings', 1, ['hs_corrective_action_id' => 1]],
        'selected_resolution_evidence' => ['emergency_drill_findings', 2, ['resolution_evidence_ids' => '[1]']],
        'recorded_empty_resolution_evidence' => ['emergency_drill_findings', 2, ['resolution_evidence_ids' => '[]']],
        'command_receipt' => ['emergency_drill_command_receipts', null, ['id' => 1, 'emergency_drill_id' => 1, 'user_id' => 1, 'audit_log_id' => 1, 'idempotency_key' => 'f1f604ac-98c9-419c-b4d3-b431b992b319', 'fingerprint' => str_repeat('b', 64), 'confirmed_result' => json_encode(['record' => ['id' => 1, 'lock_version' => 2]])]],
    ];
    foreach (['pending', 'failed', 'delivered', 'suppressed', 'skipped'] as $state) {
        $cases['delivery_'.$state] = ['emergency_drill_failure_deliveries', null, ['id' => 1, 'emergency_drill_id' => 2, 'initiated_by_user_id' => 1, 'signal_key' => str_repeat('c', 64), 'event_status' => $state === 'suppressed' ? 'delivered' : $state, 'signal_status' => $state, 'hs_event_id' => $state === 'delivered' ? 1 : null, 'signal_id' => $state === 'delivered' ? 1 : null, 'attempts' => $state === 'pending' ? 0 : 1, 'failure_code' => $state === 'failed' ? 'signal_delivery_failed' : null, 'next_attempt_at' => $state === 'failed' ? '2026-10-08 01:00:00' : null]];
    }
    $report['refusal_cases'] = array_keys($cases);
    foreach ($cases as $label => [$table, $id, $values]) {
        $report['active_refusal_case'] = $label;
        $guard();
        $before = $snapshot($tables);
        if ($id === null) {
            $insert($table, $values);
        } else {
            $connection->table($table)->where('id', $id)->update($values);
        }
        $evidence = $snapshot($tables);
        $refused = false;
        try {
            $run($migration, 'down');
        } catch (RuntimeException $error) {
            $refused = str_contains($error->getMessage(), 'Preserve drill lifecycle, remedy and delivery evidence');
            $report['refusal_messages'][$label] = $error->getMessage();
        }
        $check($refused, $label.'_destructive_down_refused');
        $check(Schema::hasColumn('emergency_drills', 'lock_version') && Schema::hasColumn('emergency_drill_findings', 'resolution_evidence_ids') && Schema::hasColumn('emergency_drill_participants', 'accommodations') && Schema::hasTable('emergency_drill_command_receipts') && Schema::hasTable('emergency_drill_failure_deliveries'), $label.'_schema_preserved');
        $check($snapshot($tables) === $evidence, $label.'_all_synthetic_rows_preserved');
        if ($id === null) {
            $connection->table($table)->where('id', $values['id'])->delete();
        } else {
            $original = collect($before[$table])->firstWhere('id', $id);
            $connection->table($table)->where('id', $id)->update(array_intersect_key($original, $values));
        }
        $check($snapshot($tables) === $before, $label.'_isolated_fixture_reset');
    }
    $legacyUnchanged($baseline, 'after_refusal_cases');
    unset($report['active_refusal_case']);
    $report['rollback_refusals_result'] = 'passed';
    $report['result'] = 'passed';
} catch (Throwable $error) {
    $report['result'] = 'failed';
    $report['error'] = $error->getMessage();
} finally {
    $report['finished_at'] = gmdate('c');
    if ($created && $connection) {
        try {
            $guard();
            $server->exec('DROP DATABASE `'.$schema.'`');
            $report['cleanup'] = 'exact_created_schema_dropped';
        } catch (Throwable $error) {
            $report['cleanup'] = 'refused_or_failed';
            $report['cleanup_error'] = $error->getMessage();
        }
    } else {
        $report['cleanup'] = $created ? 'retained_after_bootstrap_failure' : 'not_created';
    }
    file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}
echo json_encode(['result' => $report['result'], 'schema' => $schema, 'roundtrip' => $report['roundtrip_result'] ?? null, 'rollback_refusals' => $report['rollback_refusals_result'] ?? null, 'cleanup' => $report['cleanup'], 'artifact' => $artifact], JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($report['result'] === 'passed' ? 0 : 1);
