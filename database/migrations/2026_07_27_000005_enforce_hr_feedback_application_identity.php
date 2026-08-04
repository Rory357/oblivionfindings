<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collisions = [
            'feedback template name' => DB::table('hr_feedback_templates')
                ->select('name', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('name')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('name')
                ->first(),
            'feedback response question' => DB::table('hr_feedback_responses')
                ->select('feedback_request_id', 'question_key', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('feedback_request_id', 'question_key')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('feedback_request_id')
                ->orderBy('question_key')
                ->first(),
        ];
        foreach ($collisions as $identity => $collision) {
            if ($collision !== null) {
                throw new RuntimeException(sprintf(
                    'Cannot enforce application %s identity: duplicate rows exist.',
                    $identity,
                ));
            }
        }

        foreach ($this->applicationIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type, $columns]) {
                $this->addIndex($table, $name, function (Blueprint $table) use ($name, $type, $columns): void {
                    $type === 'unique'
                        ? $table->unique($columns, $name)
                        : $table->index($columns, $name);
                });
            }
        }

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name]) {
                $this->dropIndex($table, $name);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $columns]) {
                $this->addIndex(
                    $table,
                    $name,
                    fn (Blueprint $table) => $table->index($columns, $name),
                );
            }
        }

        foreach ($this->applicationIndexes() as $table => $indexes) {
            foreach (array_reverse($indexes) as [$name, $type]) {
                $this->dropIndex($table, $name, $type === 'unique');
            }
        }
    }

    /** @return array<string, list<array{string, string, list<string>}>> */
    private function applicationIndexes(): array
    {
        return [
            'hr_feedback_templates' => [
                ['hr_feedback_templates_name_uq', 'unique', ['name']],
                ['hr_feedback_templates_active_default_idx', 'index', ['is_active', 'is_default', 'name']],
            ],
            'hr_feedback_requests' => [
                ['hr_feedback_requests_reviewer_status_due_idx', 'index', ['reviewer_user_id', 'status', 'due_date']],
                ['hr_feedback_requests_subject_status_created_idx', 'index', ['subject_user_id', 'status', 'created_at']],
            ],
            'hr_feedback_responses' => [
                ['hr_feedback_responses_request_question_uq', 'unique', ['feedback_request_id', 'question_key']],
            ],
        ];
    }

    /** @return array<string, list<array{string, list<string>}>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_feedback_templates' => [
                ['hr_feedback_templates_tenant_id_index', ['tenant_id']],
            ],
            'hr_feedback_requests' => [
                ['hr_feedback_requests_tenant_id_index', ['tenant_id']],
                [
                    'hr_feedback_requests_tenant_id_reviewer_user_id_status_index',
                    ['tenant_id', 'reviewer_user_id', 'status'],
                ],
            ],
        ];
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, bool $unique = false): void
    {
        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $unique): void {
            $unique
                ? $table->dropUnique($name)
                : $table->dropIndex($name);
        });
    }
};
