<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('provider', 20);
            $table->string('external_group_id', 255);
            $table->string('external_group_name', 255);
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('auto_assign')->default(true);
            $table->boolean('auto_remove')->default(false);
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_group_mappings');
    }
};
