<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->string('version')->nullable()->after('category');
            $table->date('effective_date')->nullable()->after('version');
            $table->date('expiry_date')->nullable()->after('effective_date');

            // Portal controls: only documents explicitly shared should appear in the portal.
            $table->boolean('portal_visible')->default(false)->after('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->dropColumn(['version', 'effective_date', 'expiry_date', 'portal_visible']);
        });
    }
};
