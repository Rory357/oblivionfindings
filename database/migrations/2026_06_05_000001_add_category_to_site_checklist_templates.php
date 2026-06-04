<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('site_checklist_templates', function (Blueprint $table) {
            // Groups the (now much larger) template library by supported-living
            // domain. Keys are defined in config/checklists.php.
            $table->string('category', 40)->nullable()->after('description')->index();
        });
    }

    public function down(): void
    {
        Schema::table('site_checklist_templates', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
