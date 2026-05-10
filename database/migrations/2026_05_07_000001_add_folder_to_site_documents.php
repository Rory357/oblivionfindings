<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_documents') && ! Schema::hasColumn('site_documents', 'folder')) {
            Schema::table('site_documents', function (Blueprint $table) {
                $table->string('folder', 255)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('site_documents') && Schema::hasColumn('site_documents', 'folder')) {
            Schema::table('site_documents', function (Blueprint $table) {
                $table->dropColumn('folder');
            });
        }
    }
};
