<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P11 Q3 + B2 Q4: Medication Settings owns who is told about medication
 * alerts; Control Room keeps its queue. For each alert type B2 chunk 1 wires
 * to the catalogue, the seeded Control Room rule stops notifying people
 * itself — the alert, its queue, SLA and playbook are unchanged — so nobody
 * is told twice.
 *
 * Cleared one type at a time, in the chunk that wires it (B2 Q4). Not here:
 * expired stock (its signal also carries orders reaching their end date,
 * which no alert covers yet), and the refusal-escalation, unsafe-correction
 * and transit rules (no catalogue alert). down() restores the exact seeded
 * values.
 */
return new class extends Migration
{
    /** Rule name => the recipients it was seeded with. */
    private const SEEDED = [
        'medication_controlled_discrepancy' => ['Medication: Controlled Drug Discrepancy', ['managers_core', 'coordinators']],
        'medication_controlled_loss' => ['Medication: Controlled Drug Loss', ['managers_core', 'coordinators']],
        'medication_prn_over_limit' => ['Medication: PRN Over Limit', ['managers_core']],
        'medication_stock_out' => ['Medication: Out of Stock', ['managers_core']],
        'medication_error' => ['Medication: Error Reported', ['managers_core']],
        'medication_overdue' => ['Medication: Overdue Doses', ['managers_core']],
    ];

    public function up(): void
    {
        $this->write(fn (array $seeded): array => []);
    }

    public function down(): void
    {
        $this->write(fn (array $seeded): array => $seeded);
    }

    /** @param callable(list<string>): list<string> $roles */
    private function write(callable $roles): void
    {
        if (! Schema::hasTable('control_room_signal_rules')) {
            return;
        }
        foreach (self::SEEDED as $code => [$name, $seeded]) {
            DB::table('control_room_signal_rules')
                ->where('signal_type_code', $code)
                ->where('name', $name)
                ->update(['notify_roles' => json_encode($roles($seeded)), 'updated_at' => now()]);
        }
    }
};
