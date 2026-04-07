<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropClientsNhiIndexIfExists();

        Schema::table('clients', function (Blueprint $table) {
            $table->text('phone')->nullable()->change();
            $table->text('email')->nullable()->change();
            $table->text('nhi_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('nhi_number', 10)->nullable()->change();
        });

        $this->createClientsNhiIndexIfMissing();
    }

    protected function dropClientsNhiIndexIfExists(): void
    {
        collect(DB::select("SHOW INDEX FROM `clients` WHERE Column_name = 'nhi_number'"))
            ->pluck('Key_name')
            ->filter(fn ($keyName) => $keyName !== 'PRIMARY')
            ->unique()
            ->each(function (string $keyName): void {
                Schema::table('clients', function (Blueprint $table) use ($keyName) {
                    $table->dropIndex($keyName);
                });
            });
    }

    protected function createClientsNhiIndexIfMissing(): void
    {
        $indexExists = collect(DB::select("SHOW INDEX FROM `clients` WHERE Key_name = 'clients_nhi_number_index'"))
            ->isNotEmpty();

        if ($indexExists) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->index('nhi_number');
        });
    }
};
