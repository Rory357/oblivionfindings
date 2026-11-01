<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase C (Setup): organisation-wide Security & devices switches, such as whether hubs
 * scan every day and whether the Oblivion server may scan networks itself. One row per
 * switch; a switch with no row uses its default. Every change is in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            // Null is a value too: "IT for that Site" instead of a named role.
            $table->json('value')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_settings');
    }
};
