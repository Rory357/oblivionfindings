<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P11 Q3 + B2 Q4: Medication Settings owns who is told about medication
 * alerts; Control Room keeps its queue. For each alert type B2 chunk 1 wires
 * to the catalogue, the seeded Control Room rule stops notifying people
 * itself — the alert, its queue, SLA and playbook are unchanged — so nobody
 * is told twice about these.
 *
 * Refusal escalation goes too (B2 C1 review): it fires on the same refusal
 * policy and rows as "Repeated refusals", which tells people within the
 * 15-minute check.
 *
 * Still told by Control Room as well (qualified, B2 C1 review): expired stock
 * — its signal also carries orders reaching their end date, which no alert
 * covers yet — so "Out of stock" says so in its row; and the unsafe-correction
 * and transit rules (no catalogue alert).
 *
 * Exactly reversible (B2 C1 review): only rules still holding their seeded
 * recipients are cleared, each rule's previous value is kept as it was
 * stored, and down() puts it back only where the rule still has none.
 */
return new class extends Migration
{
    private const SNAPSHOTS = 'medication_alert_cr_rule_snapshots';

    /** Rule name => the recipients it was seeded with. */
    private const SEEDED = [
        'medication_controlled_discrepancy' => ['Medication: Controlled Drug Discrepancy', ['managers_core', 'coordinators']],
        'medication_controlled_loss' => ['Medication: Controlled Drug Loss', ['managers_core', 'coordinators']],
        'medication_prn_over_limit' => ['Medication: PRN Over Limit', ['managers_core']],
        'medication_stock_out' => ['Medication: Out of Stock', ['managers_core']],
        'medication_error' => ['Medication: Error Reported', ['managers_core']],
        'medication_overdue' => ['Medication: Overdue Doses', ['managers_core']],
        'medication_refusal_escalation' => ['Medication: Refusal Escalation', ['managers_core', 'coordinators']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::SNAPSHOTS)) {
            Schema::create(self::SNAPSHOTS, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('signal_rule_id')->unique();
                $table->string('signal_type_code', 100);
                $table->text('notify_roles_before');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('control_room_signal_rules')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::SEEDED as $code => [$name, $seeded]) {
                $rules = DB::table('control_room_signal_rules')
                    ->where('signal_type_code', $code)
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->get(['id', 'notify_roles']);
                foreach ($rules as $rule) {
                    if (! $this->holds($rule->notify_roles, $seeded)) {
                        continue;
                    }
                    DB::table(self::SNAPSHOTS)->insert([
                        'signal_rule_id' => $rule->id,
                        'signal_type_code' => $code,
                        'notify_roles_before' => (string) $rule->notify_roles,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('control_room_signal_rules')
                        ->where('id', $rule->id)
                        ->update(['notify_roles' => json_encode([]), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::SNAPSHOTS)) {
            return;
        }
        $this->restoreSnapshots();
        Schema::drop(self::SNAPSHOTS);
    }

    /** Each cleared rule gets its previous recipients back, as stored — where it still has none. */
    private function restoreSnapshots(): void
    {
        if (Schema::hasTable('control_room_signal_rules')) {
            DB::transaction(function (): void {
                foreach (DB::table(self::SNAPSHOTS)->orderBy('id')->get() as $snapshot) {
                    $current = DB::table('control_room_signal_rules')
                        ->where('id', $snapshot->signal_rule_id)
                        ->lockForUpdate()
                        ->value('notify_roles');
                    // Someone chose new recipients since: theirs stay.
                    if ($current === null || json_decode((string) $current, true) !== []) {
                        continue;
                    }
                    DB::table('control_room_signal_rules')
                        ->where('id', $snapshot->signal_rule_id)
                        ->update(['notify_roles' => $snapshot->notify_roles_before, 'updated_at' => now()]);
                }
            });
        }
    }

    /**
     * Still exactly the seeded recipients, in any order.
     *
     * @param  list<string>  $seeded
     */
    private function holds(mixed $stored, array $seeded): bool
    {
        $roles = is_string($stored) ? json_decode($stored, true) : null;
        if (! is_array($roles) || ! array_is_list($roles)) {
            return false;
        }
        sort($roles);
        sort($seeded);

        return $roles === $seeded;
    }
};
