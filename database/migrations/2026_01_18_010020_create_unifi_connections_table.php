<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unifi_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();

            $table->string('base_url'); // e.g. https://unifi.example.com:8443
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->text('api_token_encrypted')->nullable();

            $table->string('controller_type')->default('unifi_os'); // unifi_os|network_application
            $table->string('verify_tls')->default('1');

            $table->dateTime('last_synced_at')->nullable();
            $table->string('status')->default('inactive')->index();
            $table->text('last_error')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unifi_connections');
    }
};
