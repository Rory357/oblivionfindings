<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resolutions')) {
            Schema::table('resolutions', function (Blueprint $table) {
                if (!Schema::hasColumn('resolutions', 'version_number')) {
                    $table->unsignedInteger('version_number')->default(1)->after('status');
                }
                if (!Schema::hasColumn('resolutions', 'exact_motion')) {
                    $table->text('exact_motion')->nullable()->after('title');
                }
                if (!Schema::hasColumn('resolutions', 'purpose')) {
                    $table->string('purpose')->default('decision')->after('exact_motion');
                }
                if (!Schema::hasColumn('resolutions', 'single_option_reason')) {
                    $table->text('single_option_reason')->nullable()->after('options');
                }
                if (!Schema::hasColumn('resolutions', 'service_user_implications')) {
                    $table->text('service_user_implications')->nullable()->after('risk_impact');
                }
                if (!Schema::hasColumn('resolutions', 'risk_equity_implications')) {
                    $table->text('risk_equity_implications')->nullable()->after('service_user_implications');
                }
                if (!Schema::hasColumn('resolutions', 'paper_snapshot')) {
                    $table->json('paper_snapshot')->nullable()->after('vote_summary');
                }
                if (!Schema::hasColumn('resolutions', 'published_at')) {
                    $table->timestamp('published_at')->nullable()->after('paper_snapshot');
                }
                if (!Schema::hasColumn('resolutions', 'published_by')) {
                    $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete()->after('published_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('resolutions')) {
            Schema::table('resolutions', function (Blueprint $table) {
                if (Schema::hasColumn('resolutions', 'published_by')) {
                    $table->dropConstrainedForeignId('published_by');
                }
                $columns = [
                    'version_number',
                    'exact_motion',
                    'purpose',
                    'single_option_reason',
                    'service_user_implications',
                    'risk_equity_implications',
                    'paper_snapshot',
                    'published_at',
                ];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('resolutions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
