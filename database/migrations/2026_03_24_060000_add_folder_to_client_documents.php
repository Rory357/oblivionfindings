<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_documents', 'folder')) {
            Schema::table('client_documents', function (Blueprint $table) {
                $table->string('folder', 255)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->dropColumn('folder');
        });
    }
};
