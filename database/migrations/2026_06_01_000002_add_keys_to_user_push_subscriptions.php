<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_push_subscriptions', function (Blueprint $table) {
            $table->json('keys')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('user_push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('keys');
        });
    }
};
