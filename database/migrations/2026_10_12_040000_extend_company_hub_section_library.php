<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_section_presets', function (Blueprint $table): void {
            $table->string('scope', 20)->default('personal')->index();
            $table->foreignId('source_page_id')->nullable()->constrained('hub_pages')->restrictOnDelete();
            $table->json('source_audience')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('hub_section_presets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_page_id');
            $table->dropColumn(['scope', 'source_audience', 'version', 'deleted_at']);
        });
    }
};
