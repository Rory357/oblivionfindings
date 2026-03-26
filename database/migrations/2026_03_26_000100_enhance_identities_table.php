<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            if (!Schema::hasColumn('identities', 'access_token')) {
                $table->text('access_token')->nullable()->after('email');
            }
            if (!Schema::hasColumn('identities', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('access_token');
            }
            if (!Schema::hasColumn('identities', 'token_expires_at')) {
                $table->dateTime('token_expires_at')->nullable()->after('refresh_token');
            }
            if (!Schema::hasColumn('identities', 'scopes')) {
                $table->json('scopes')->nullable()->after('token_expires_at');
            }
            if (!Schema::hasColumn('identities', 'avatar_url')) {
                $table->string('avatar_url', 500)->nullable()->after('scopes');
            }
            if (!Schema::hasColumn('identities', 'raw_profile')) {
                $table->json('raw_profile')->nullable()->after('avatar_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            $columns = ['access_token', 'refresh_token', 'token_expires_at', 'scopes', 'avatar_url', 'raw_profile'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('identities', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
