<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'med_round_templates_retired_active_idx';

    public function up(): void
    {
        Schema::table('medication_round_templates', function (Blueprint $table): void {
            $table->timestamp('retired_at')->nullable()->after('active');
            // Keep immutable actor provenance even if the User is later removed.
            $table->unsignedBigInteger('retired_by_user_id')->nullable()->after('retired_at');
            $table->index(['retired_at', 'active'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (DB::table('medication_round_templates')
            ->where(function ($retirement): void {
                $retirement->whereNotNull('retired_at')
                    ->orWhereNotNull('retired_by_user_id');
            })
            ->exists()) {
            throw new RuntimeException(
                'Cannot remove medication round-template retirement fields while retained retirement evidence exists.',
            );
        }

        Schema::table('medication_round_templates', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
            $table->dropColumn(['retired_at', 'retired_by_user_id']);
        });
    }
};
