<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_job_requisitions', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_job_requisitions', 'posting_channels')) {
                $table->json('posting_channels')->nullable()->after('hiring_manager_user_id');
            }

            if (! Schema::hasColumn('hr_job_requisitions', 'external_posting_status')) {
                $table->string('external_posting_status')->default('not_posted')->index()->after('posting_channels');
            }

            if (! Schema::hasColumn('hr_job_requisitions', 'external_reference')) {
                $table->json('external_reference')->nullable()->after('external_posting_status');
            }

            if (! Schema::hasColumn('hr_job_requisitions', 'external_posted_at')) {
                $table->timestamp('external_posted_at')->nullable()->after('external_reference');
            }

            if (! Schema::hasColumn('hr_job_requisitions', 'external_sync_at')) {
                $table->timestamp('external_sync_at')->nullable()->after('external_posted_at');
            }

            if (! Schema::hasColumn('hr_job_requisitions', 'external_sync_error')) {
                $table->text('external_sync_error')->nullable()->after('external_sync_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_job_requisitions', function (Blueprint $table) {
            $columns = [
                'posting_channels',
                'external_posting_status',
                'external_reference',
                'external_posted_at',
                'external_sync_at',
                'external_sync_error',
            ];

            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('hr_job_requisitions', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
