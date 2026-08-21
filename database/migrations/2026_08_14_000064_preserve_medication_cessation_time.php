<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medications', function (Blueprint $table): void {
            $table->dateTime('ceased_at')->nullable()->change();
        });

        Schema::table('medication_order_versions', function (Blueprint $table): void {
            $table->dateTime('ceased_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('client_medications', function (Blueprint $table): void {
            $table->date('ceased_at')->nullable()->change();
        });

        Schema::table('medication_order_versions', function (Blueprint $table): void {
            $table->date('ceased_at')->nullable()->change();
        });
    }
};
