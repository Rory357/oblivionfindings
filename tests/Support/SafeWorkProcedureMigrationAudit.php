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
$source = 'oblivion_hs_preview_20261007_2118';
$schema = 'oblivion_hs_migaudit_20261008_'.bin2hex(random_bytes(4));
$artifact = $argv[1] ?? 'storage/logs/hs-procedure-migration-audit-r1.json';
if (! preg_match('~^storage/logs/hs-procedure-migration-audit-r[0-9]+\.json$~D', $artifact)) {
    throw new RuntimeException('Use an approved migration audit artifact path.');
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
    if (! $created || ! preg_match('/^oblivion_hs_migaudit_20261008_[a-f0-9]{8}$/D', $schema) || ! $connection || $connection->getDatabaseName() !== $schema || $connection->selectOne('SELECT DATABASE() AS name')->name !== $schema || DB::getDefaultConnection() !== 'mysql') {
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
$insert = function (string $table, array $data) use (&$connection) {
    foreach ($connection->select('SHOW COLUMNS FROM `'.$table.'`') as $column) {
        if (array_key_exists($column->Field, $data) || $column->Null === 'YES' || $column->Default !== null || str_contains($column->Extra, 'auto_increment')) {
            continue;
        }
        $type = strtolower($column->Type);
        $data[$column->Field] = preg_match("/^enum\\('([^']*)'/", $type, $match) ? $match[1] : (str_contains($type, 'int') || str_contains($type, 'decimal') ? 1 : (str_contains($type, 'date') || str_contains($type, 'time') ? '2026-10-08 00:00:00' : ($type === 'json' ? '[]' : 'synthetic')));
    }
    $connection->table($table)->insert($data);
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
    $tables = ['users', 'sites', 'hs_events', 'hs_corrective_actions', 'audit_logs', 'safe_work_procedures', 'safe_work_procedure_versions', 'hs_attachments', 'procedure_acknowledgements', 'procedure_acknowledgement_receipts', 'procedure_review_requests', 'procedure_review_command_receipts', 'procedure_review_deliveries'];
    $auditPdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $definition = $sourcePdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $report['definition_sha256'][$table] = hash('sha256', $definition);
        $auditPdo->exec($definition);
        $check((int) $auditPdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() === 0, $table.'_clone_empty');
    }
    $auditPdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_USERNAME' => $username, 'DB_PASSWORD' => $password, 'DB_DATABASE' => $schema, 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require 'bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $schema]);
    DB::purge('mysql');
    Schema::clearResolvedInstance('db.schema');
    $connection = DB::connection('mysql');
    $publication = require 'database/migrations/2026_10_08_180000_add_controlled_procedure_publications.php';
    $delivery = require 'database/migrations/2026_10_08_180100_add_procedure_review_deliveries.php';
    $run($delivery, 'down');
    $run($publication, 'down');
    $guard();
    $connection->statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $insert('users', ['id' => 1, 'name' => 'Synthetic migration auditor', 'email' => 'migration-audit@example.invalid', 'password' => 'synthetic-not-a-login', 'role' => 'support_worker']);
        $insert('sites', ['id' => 1, 'name' => 'Synthetic migration Site']);
        foreach ([1 => 'approved', 2 => 'archived'] as $id => $status) {
            $insert('safe_work_procedures', ['id' => $id, 'title' => 'Current synthetic instructions '.$id, 'reference_number' => 'SWP-MIGRATION-AUDIT-'.$id, 'category' => 'manual_handling', 'status' => $status, 'current_version' => 1, 'created_by' => 1, 'approved_by' => 1, 'approved_at' => '2020-01-02 03:04:05', 'review_date' => '2027-01-01', 'review_frequency_months' => 12, 'applicable_roles' => json_encode(['support_worker']), 'applicable_sites' => '[1]', 'steps' => json_encode([['description' => 'Current synthetic step']]), 'ppe_required' => '[]', 'hazards_addressed' => '[]', 'emergency_procedures' => '[]', 'related_training' => '[]']);
            $insert('safe_work_procedure_versions', ['id' => $id, 'safe_work_procedure_id' => $id, 'version_number' => 1, 'content_snapshot' => json_encode(['title' => 'Original historical synthetic snapshot '.$id]), 'changed_by' => 1, 'change_summary' => 'Untouched historical snapshot', 'created_at' => '2020-01-01 01:02:03']);
        }
        $insert('hs_attachments', ['id' => 1, 'attachable_type' => 'App\\Models\\SafeWorkProcedure', 'attachable_id' => 1, 'uploaded_by' => 1, 'original_name' => 'synthetic-audit-only.pdf', 'path' => 'synthetic/audit-only.pdf', 'disk' => 'private', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'version_at_upload' => 1]);
        $insert('procedure_acknowledgements', ['id' => 1, 'safe_work_procedure_id' => 1, 'user_id' => 1, 'version_acknowledged' => 1, 'acknowledged_at' => '2020-02-01 01:02:03']);
    } finally {
        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }
    $original = (array) $connection->table('safe_work_procedure_versions')->find(1);
    $stamp = (array) $connection->table('procedure_acknowledgements')->find(1);
    $run($publication, 'up');
    $guard();
    $publication->captureLegacyPublications();
    $legacy = $connection->table('safe_work_procedures')->find(1);
    $capture = $connection->table('safe_work_procedure_versions')->find($legacy->published_version_id);
    $report['capture_id'] = $capture->id;
    $report['captured_metadata'] = ['document_manifest' => json_decode($capture->document_manifest, true), 'provenance' => $capture->provenance, 'captured_at' => $capture->captured_at, 'legacy_approved_by' => $capture->legacy_approved_by, 'legacy_approved_at' => $capture->legacy_approved_at, 'content_digest' => $capture->content_digest];
    $check($capture->provenance === 'legacy_current_capture' && $capture->approved_at === null, 'capture_does_not_manufacture_approval');
    $check($report['captured_metadata']['document_manifest'] === [1], 'capture_exact_manifest');
    $check($connection->table('safe_work_procedure_versions')->where('safe_work_procedure_id', 1)->count() === 2, 'backfill_idempotent');
    $check($connection->table('safe_work_procedures')->find(2)->published_version_id === null, 'archive_never_published');
    foreach ($original as $key => $value) {
        $check($connection->table('safe_work_procedure_versions')->find(1)->{$key} === $value, 'original_'.$key.'_unchanged');
    }
    try {
        $run($publication, 'down');
        $guard();
        $downgraded = $connection->table('safe_work_procedure_versions')->find($capture->id);
        $archivedMetadata = json_decode($downgraded->content_snapshot, true)['_publication_capture_archive'] ?? null;
        $check($archivedMetadata !== null, 'downgrade_archives_exact_capture_metadata');
        foreach ($report['captured_metadata'] as $key => $value) {
            $check(($archivedMetadata[$key] ?? null) === $value, 'downgrade_preserves_'.$key);
        }
        $run($publication, 'up');
        $guard();
        $restored = $connection->table('safe_work_procedure_versions')->find($capture->id);
        foreach ($report['captured_metadata'] as $key => $value) {
            $actual = $key === 'document_manifest' ? json_decode($restored->{$key}, true) : $restored->{$key};
            $check($actual === $value, 'upgrade_restores_'.$key);
        }
        $check(json_decode($restored->content_snapshot, true) === json_decode($capture->content_snapshot, true), 'capture_content_roundtrip_exact');
        $check((int) $connection->table('safe_work_procedures')->find(1)->published_version_id === (int) $capture->id, 'unchanged_capture_pointer_roundtrip_exact');
        $check($restored->approved_by === null && $restored->approved_at === null, 'roundtrip_does_not_reverify_legacy_approval');
        foreach ($original as $key => $value) {
            $check($connection->table('safe_work_procedure_versions')->find(1)->{$key} === $value, 'original_'.$key.'_roundtrip_exact');
        }
        $check($connection->table('safe_work_procedures')->find(1)->approved_by === 1 && $connection->table('safe_work_procedures')->find(1)->approved_at === '2020-01-02 03:04:05', 'legacy_master_approval_stamp_roundtrip_exact');
        $check((array) $connection->table('procedure_acknowledgements')->find(1) === $stamp, 'legacy_reading_stamp_roundtrip_exact');
        $check($connection->table('safe_work_procedures')->find(2)->published_version_id === null, 'archive_roundtrip_never_published');
        $report['roundtrip_result'] = 'passed';
    } catch (Throwable $error) {
        $report['roundtrip_result'] = 'failed';
        $report['roundtrip_error'] = $error->getMessage();
        $guard();
        if (! Schema::hasColumn('safe_work_procedures', 'published_version_id')) {
            $run($publication, 'up');
        }
    }
    if ($report['roundtrip_result'] === 'passed') {
        $guard();
        $connection->table('safe_work_procedure_versions')->where('id', $capture->id)->update(['content_digest' => str_repeat('0', 64)]);
        try {
            $run($publication, 'down');
            throw new RuntimeException('Corrupt capture downgrade unexpectedly succeeded.');
        } catch (RuntimeException $error) {
            $check(str_contains($error->getMessage(), 'digest mismatch'), 'corrupt_capture_digest_downgrade_refused');
            $check(Schema::hasColumn('safe_work_procedure_versions', 'document_manifest'), 'corrupt_capture_refusal_keeps_schema');
            $check(json_decode($connection->table('safe_work_procedure_versions')->find($capture->id)->content_snapshot, true) === json_decode($capture->content_snapshot, true), 'corrupt_capture_refusal_keeps_content');
        } finally {
            $connection->table('safe_work_procedure_versions')->where('id', $capture->id)->update(['content_digest' => $capture->content_digest]);
        }
    }
    $run($delivery, 'up');
    $guard();
    $connection->statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        $insert('hs_events', ['id' => 1, 'site_id' => 1, 'reference_number' => 'HS-MIG-1']);
    } finally {
        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }
    $current = $connection->table('safe_work_procedures')->find(1);
    $insert('procedure_review_requests', ['id' => 1, 'safe_work_procedure_id' => 1, 'hs_event_id' => 1, 'source_site_id' => 1, 'requested_version_id' => $current->published_version_id, 'requested_by_user_id' => 1, 'assigned_to_user_id' => 1, 'note' => 'Synthetic migration evidence', 'idempotency_key' => '7b50c990-5fba-4e64-891e-675f9e4c4f6a', 'fingerprint' => str_repeat('a', 64), 'requested_at' => '2026-10-08 00:00:00']);
    $deliveryId = '6301f1df-3012-4d74-9b22-bbcb61f47e7d';
    $insert('procedure_review_deliveries', ['id' => $deliveryId, 'procedure_review_request_id' => 1, 'recipient_user_id' => 1, 'initiated_by_user_id' => 1, 'assignment_revision' => 1, 'status' => 'failed', 'attempts' => 1, 'failure_code' => 'delivery_failed', 'next_attempt_at' => '2026-10-08 01:00:00']);
    $failedDelivery = (array) $connection->table('procedure_review_deliveries')->find($deliveryId);
    try {
        $run($delivery, 'down');
        throw new RuntimeException('Delivery downgrade unexpectedly discarded evidence.');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'Preserve procedure review delivery evidence'), 'failed_delivery_downgrade_refused');
    }
    $check((array) $connection->table('procedure_review_deliveries')->find($deliveryId) === $failedDelivery, 'failed_delivery_row_preserved');
    try {
        $run($publication, 'down');
        throw new RuntimeException('Source downgrade unexpectedly discarded evidence.');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'Export and preserve'), 'request_evidence_downgrade_refused');
    }
    $report['delivery_downgrade_result'] = 'passed';
    $report['result'] = ($report['roundtrip_result'] ?? 'failed') === 'passed' ? 'passed' : 'failed';
} catch (Throwable $error) {
    $report['result'] = 'failed';
    $report['error'] = $error->getMessage();
} finally {
    $report['finished_at'] = gmdate('c');
    file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
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
echo json_encode(['result' => $report['result'], 'schema' => $schema, 'roundtrip' => $report['roundtrip_result'] ?? null, 'delivery_downgrade' => $report['delivery_downgrade_result'] ?? null, 'cleanup' => $report['cleanup'], 'artifact' => $artifact], JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($report['result'] === 'passed' ? 0 : 1);
