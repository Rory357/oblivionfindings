<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('client_notes', 'is_flagged')) {
                $table->boolean('is_flagged')->default(false);
            }
            if (! Schema::hasColumn('client_notes', 'flagged_reason')) {
                $table->string('flagged_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('client_notes', 'reviewed_at')) {
                $table->dateTime('reviewed_at')->nullable();
            }
            if (! Schema::hasColumn('client_notes', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('client_notes', 'is_private')) {
                $table->boolean('is_private')->default(false);
            }
            if (! Schema::hasColumn('client_notes', 'attachments')) {
                $table->json('attachments')->nullable();
            }
            if (! Schema::hasColumn('client_notes', 'mood_rating')) {
                $table->tinyInteger('mood_rating')->nullable();
            }
            if (! Schema::hasColumn('client_notes', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            if (Schema::hasColumn('client_notes', 'reviewed_by')) {
                $table->dropForeign(['reviewed_by']);
            }
            $columns = [
                'is_flagged', 'flagged_reason', 'reviewed_at', 'reviewed_by',
                'is_private', 'attachments', 'mood_rating', 'organization_id',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('client_notes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
