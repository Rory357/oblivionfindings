<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_documents', function (Blueprint $table) {
            $table->date('expires_at')->nullable()->after('signed_document_path');
            $table->boolean('expiry_reminder_sent')->default(false)->after('expires_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_documents', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'expiry_reminder_sent']);
        });
    }
};
