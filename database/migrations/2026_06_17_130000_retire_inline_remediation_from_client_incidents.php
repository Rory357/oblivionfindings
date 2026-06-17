<?php

use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Incidents redesign — Step 7c (Option B, §6.6).
 *
 * Retire the incident's inline remediation. Remediation is now governed in the
 * Health & Safety register, so move any LEGACY inline data into HsInvestigation
 * (root cause / contributing factors / lessons) + HsCorrectiveAction (each JSON
 * action), then drop the duplicate columns from client_incidents.
 *
 * ⚠️ DESTRUCTIVE: the up() drops 5 columns. The data-move runs first and is
 * fail-safe per row, but down() only re-adds the (empty) columns — dropped data
 * is not restored. Review against production data before deploying.
 */
return new class extends Migration {
    private array $columns = [
        'corrective_actions',
        'root_cause_category',
        'root_cause_description',
        'contributing_factors',
        'lessons_learned',
    ];

    public function up(): void
    {
        $this->migrateInlineRemediation();

        Schema::table('client_incidents', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('client_incidents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            // Columns return empty — the migrated data lives in H&S now.
            $table->json('corrective_actions')->nullable();
            $table->text('root_cause_category')->nullable();
            $table->text('root_cause_description')->nullable();
            $table->text('contributing_factors')->nullable();
            $table->text('lessons_learned')->nullable();
        });
    }

    /**
     * Move legacy inline remediation into the H&S register. Read raw (not via the
     * model, whose casts/fillable no longer list these) and create H&S records via
     * their models. Each incident is independent + fail-safe — a bad row is logged
     * and skipped, never aborting the migration.
     */
    private function migrateInlineRemediation(): void
    {
        $rows = DB::table('client_incidents')
            ->where(function ($q) {
                $q->whereNotNull('corrective_actions')
                    ->orWhereNotNull('root_cause_category')
                    ->orWhereNotNull('root_cause_description')
                    ->orWhereNotNull('contributing_factors')
                    ->orWhereNotNull('lessons_learned');
            })
            ->get(['id', 'corrective_actions', 'root_cause_category', 'root_cause_description', 'contributing_factors', 'lessons_learned']);

        foreach ($rows as $row) {
            try {
                $hsEvent = HsEvent::query()
                    ->where('source_type', ClientIncident::class)
                    ->where('source_id', $row->id)
                    ->first();

                if (! $hsEvent) {
                    continue; // no governance wrapper to attach to
                }

                $investigation = $this->migrateInvestigation($hsEvent, $row);
                $this->migrateCorrectiveActions($hsEvent, $investigation, $row->corrective_actions);
            } catch (\Throwable $e) {
                Log::warning('retire_inline_remediation: skipped incident', [
                    'incident_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function migrateInvestigation(HsEvent $hsEvent, object $row): ?HsInvestigation
    {
        $hasData = $row->root_cause_category || $row->root_cause_description || $row->contributing_factors || $row->lessons_learned;
        if (! $hasData) {
            return null;
        }

        // Don't duplicate if the event already has an investigation.
        $existing = HsInvestigation::where('hs_event_id', $hsEvent->id)->first();
        if ($existing) {
            return $existing;
        }

        return HsInvestigation::create([
            'hs_event_id' => $hsEvent->id,
            'organization_id' => $hsEvent->organization_id,
            'reference_number' => HsInvestigation::generateReferenceNumber(),
            'investigation_type' => 'standard',
            'status' => 'completed', // legacy data was recorded after the fact
            'root_causes' => $row->root_cause_description
                ? [array_filter(['description' => $row->root_cause_description, 'category' => $row->root_cause_category])]
                : null,
            'contributing_factors' => $row->contributing_factors ? [['description' => $row->contributing_factors]] : null,
            'lessons_learned' => $row->lessons_learned,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function migrateCorrectiveActions(HsEvent $hsEvent, ?HsInvestigation $investigation, ?string $json): void
    {
        $actions = json_decode($json ?? '[]', true);
        if (! is_array($actions)) {
            return;
        }

        foreach ($actions as $action) {
            $description = $action['description'] ?? null;
            if (! $description) {
                continue;
            }

            HsCorrectiveAction::create([
                'hs_event_id' => $hsEvent->id,
                'hs_investigation_id' => $investigation?->id,
                'organization_id' => $hsEvent->organization_id,
                'reference_number' => HsCorrectiveAction::generateReferenceNumber(),
                'action_type' => 'corrective',
                'priority' => 'medium',
                'title' => Str::limit($description, 250, ''),
                'description' => $description,
                'status' => ! empty($action['completed_at']) ? 'completed' : 'open',
                'due_date' => $action['due_date'] ?? null,
                'completed_at' => $action['completed_at'] ?? null,
            ]);
        }
    }
};
