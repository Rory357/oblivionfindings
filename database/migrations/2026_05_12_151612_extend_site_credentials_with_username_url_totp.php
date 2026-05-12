<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_credentials', function (Blueprint $table) {
            $table->string('username', 255)->nullable()->after('label');
            $table->string('url', 2048)->nullable()->after('username');
            $table->boolean('is_shareable')->default(false)->after('requires_reauth');
            $table->unsignedTinyInteger('password_strength')->nullable()->after('is_shareable');
            $table->text('totp_secret_encrypted')->nullable()->after('password_strength');
            $table->string('totp_issuer', 255)->nullable()->after('totp_secret_encrypted');
            $table->string('totp_account', 255)->nullable()->after('totp_issuer');
        });
    }

    public function down(): void
    {
        Schema::table('site_credentials', function (Blueprint $table) {
            $table->dropColumn([
                'username',
                'url',
                'is_shareable',
                'password_strength',
                'totp_secret_encrypted',
                'totp_issuer',
                'totp_account',
            ]);
        });
    }
};
