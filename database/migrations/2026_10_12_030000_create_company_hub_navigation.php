<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_navigation', function (Blueprint $table): void {
            // One shared menu for this operating organisation.
            $table->unsignedTinyInteger('id')->primary();
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        DB::table('hub_navigation')->insert(['id' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_navigation');
    }
};
