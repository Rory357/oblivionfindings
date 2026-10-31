<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;

/** Storage/key availability only. Never a Site, role, current-source or write grant. */
final class OperationsHandoverReadiness
{
    public static function available(): bool
    {
        try {
            $pdo = DB::connection()->getPdo();
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { return false; }
            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            if (stripos($version, 'mariadb') !== false || ! preg_match('/^(\d+)\./', $version, $match) || (int) $match[1] < 8) { return false; }
            $keys = config('app.previous_keys', []);
            if (! is_array($keys)) { return false; }
            foreach ([(string) config('app.key'), ...$keys] as $key) {
                if (! is_string($key)) { return false; }
                $secret = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
                if (! is_string($secret) || strlen($secret) !== 32) { return false; }
            }
            $tables = ['operations_handover_attempts', 'operations_handover_completions', 'operations_handover_witnesses',
                'shift_handovers', 'timeline_events', 'audit_logs', 'hr_payroll_run_mutexes', 'shifts', 'clients', 'sites',
                'users', 'hr_employee_profiles', 'role_user', 'roles', 'role_permission', 'permission_user', 'permissions',
                'clinical_observations', 'shift_tasks', 'client_incidents', 'client_medications', 'service_contexts',
                'medication_competency_assessments', 'medication_competency_exemptions', 'hr_attendance_sessions', 'app_settings'];
            $rows = DB::table('information_schema.tables')->whereRaw('table_schema = database()')->whereIn('table_name', $tables)
                ->get(['table_name as table_name', 'engine as engine'])->keyBy('table_name');
            if ($rows->count() !== count($tables) || $rows->contains(fn ($row) => strtoupper((string) $row->engine) !== 'INNODB')) { return false; }
            $columns = [
                'operations_handover_attempts' => ['id' => 'bigint', 'actor_id' => 'bigint', 'request_id' => 'char', 'action' => 'varchar',
                    'shift_id' => 'bigint', 'requested_handover_id' => 'bigint', 'expected_version' => 'int', 'expected_source' => 'char',
                    'intent_hash' => 'char', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'],
                'operations_handover_completions' => ['id' => 'bigint', 'attempt_id' => 'bigint', 'handover_id' => 'bigint', 'receipt' => 'json',
                    'intended_after_hash' => 'char', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'],
                'operations_handover_witnesses' => ['id' => 'bigint', 'attempt_id' => 'bigint', 'key_id' => 'char', 'signature' => 'char',
                    'created_at' => 'timestamp', 'updated_at' => 'timestamp'],
            ];
            foreach ($columns as $table => $expected) {
                $actual = DB::table('information_schema.columns')->whereRaw('table_schema = database()')->where('table_name', $table)
                    ->get(['column_name as column_name', 'data_type as data_type', 'character_maximum_length as character_maximum_length',
                        'is_nullable as is_nullable', 'column_type as column_type'])->keyBy('column_name');
                foreach ($expected as $name => $type) {
                    if (! $actual->has($name) || strtolower((string) $actual[$name]->data_type) !== $type) { return false; }
                    if (in_array($name, ['expected_source', 'intent_hash', 'intended_after_hash', 'key_id', 'signature'], true)
                        && (int) $actual[$name]->character_maximum_length !== 64) { return false; }
                    if ($name === 'request_id' && (int) $actual[$name]->character_maximum_length !== 36) { return false; }
                    if ($type === 'bigint' || $type === 'int') {
                        if (! str_contains(strtolower((string) $actual[$name]->column_type), 'unsigned')) { return false; }
                    }
                    $nullable = in_array($name, ['requested_handover_id', 'expected_version', 'created_at', 'updated_at'], true);
                    if (($actual[$name]->is_nullable === 'YES') !== $nullable) { return false; }
                }
                $indexes = DB::table('information_schema.statistics')->whereRaw('table_schema = database()')->where('table_name', $table)
                    ->where('non_unique', 0)->orderBy('seq_in_index')->get(['index_name as index_name', 'column_name as column_name', 'sub_part as sub_part'])->groupBy('index_name');
                $wanted = $table === 'operations_handover_attempts' ? ['actor_id', 'request_id'] : ['attempt_id'];
                if (! $indexes->contains(fn ($rows) => $rows->pluck('column_name')->all() === $wanted && $rows->every(fn ($row) => $row->sub_part === null))) { return false; }
            }
            return true;
        } catch (\Throwable) { return false; }
    }
}
