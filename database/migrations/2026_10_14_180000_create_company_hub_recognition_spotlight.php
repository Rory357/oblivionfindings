<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_recognition_spotlight', function (Blueprint $table): void {
            // One editorial selection for this operating organisation.
            $table->unsignedTinyInteger('id')->primary();
            // Retain the pointer after canonical deletion so an editor can clear
            // an unavailable selection without exposing its old id to readers.
            $table->unsignedBigInteger('kudos_id')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('hub_recognition_spotlight')->insert(['id' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_recognition_spotlight');
    }
};
