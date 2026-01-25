<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_incident_attachments', function (Blueprint $table) {
            $table->string('disk')->default('public')->after('uploaded_by');
            $table->string('mime_type')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('client_incident_attachments', function (Blueprint $table) {
            $table->dropColumn(['disk', 'mime_type']);
        });
    }
};
