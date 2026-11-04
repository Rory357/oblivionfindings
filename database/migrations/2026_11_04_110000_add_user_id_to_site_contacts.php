<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Site contact can now be someone who works here (to-do #10, 4 Nov 2026):
 * the row links to their staff record, and its name, role, phone and email are
 * read from that record so they stay current. The text columns stay as they
 * are and keep the last saved details, which show again if the staff record
 * is deleted (the link is cleared, not the contact). Additive and nullable:
 * every existing contact stays a typed-in contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_contacts') || Schema::hasColumn('site_contacts', 'user_id')) {
            return;
        }

        Schema::table('site_contacts', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('site_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_contacts') || ! Schema::hasColumn('site_contacts', 'user_id')) {
            return;
        }

        Schema::table('site_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
