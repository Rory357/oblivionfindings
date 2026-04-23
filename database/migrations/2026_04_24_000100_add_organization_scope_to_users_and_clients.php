<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'organization_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')
                    ->nullable()
                    ->default(1)
                    ->after('id')
                    ->index();
            });
        }

        if (Schema::hasTable('clients') && ! Schema::hasColumn('clients', 'organization_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')
                    ->nullable()
                    ->default(1)
                    ->after('id')
                    ->index();
            });
        }

        if (Schema::hasColumn('users', 'organization_id')) {
            DB::table('users')->whereNull('organization_id')->update(['organization_id' => 1]);
        }

        if (Schema::hasColumn('clients', 'organization_id')) {
            DB::table('clients')->whereNull('organization_id')->update(['organization_id' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'organization_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('organization_id');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'organization_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('organization_id');
            });
        }
    }
};
