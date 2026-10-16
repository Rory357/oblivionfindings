<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_media', function (Blueprint $table): void {
            $table->text('caption')->nullable();
            $table->unsignedTinyInteger('focal_x')->default(50);
            $table->unsignedTinyInteger('focal_y')->default(50);
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['hub_page_id', 'removed_at'], 'hub_media_library_availability_index');
        });
    }

    public function down(): void
    {
        Schema::table('hub_media', function (Blueprint $table): void {
            $table->dropIndex('hub_media_library_availability_index');
            $table->dropConstrainedForeignId('removed_by');
            $table->dropColumn(['caption', 'focal_x', 'focal_y', 'removed_at']);
        });
    }
};
