<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('site_hazards', function (Blueprint $table) {
            if (!Schema::hasColumn('site_hazards', 'warning_sent_at')) {
                $table->timestamp('warning_sent_at')->nullable()->after('review_date');
            }

            if (!Schema::hasColumn('site_hazards', 'overdue_notified_at')) {
                $table->timestamp('overdue_notified_at')->nullable()->after('warning_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_hazards', function (Blueprint $table) {
            if (Schema::hasColumn('site_hazards', 'overdue_notified_at')) {
                $table->dropColumn('overdue_notified_at');
            }

            if (Schema::hasColumn('site_hazards', 'warning_sent_at')) {
                $table->dropColumn('warning_sent_at');
            }
        });
    }
};
