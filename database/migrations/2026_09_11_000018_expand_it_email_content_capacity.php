<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing canonical records must hold the full bounded email body.
        Schema::table('it_tickets', fn (Blueprint $table) => $table->mediumText('description')->nullable()->change());
        Schema::table('it_ticket_comments', fn (Blueprint $table) => $table->mediumText('body')->change());
        // A valid 250-character title gains the reference and notification prefix.
        Schema::table('it_email_deliveries', fn (Blueprint $table) => $table->text('subject')->change());
    }

    public function down(): void
    {
        if (DB::table('it_tickets')->whereRaw('OCTET_LENGTH(description) > 65535')->exists()
            || DB::table('it_ticket_comments')->whereRaw('OCTET_LENGTH(body) > 65535')->exists()
            || DB::table('it_email_deliveries')->whereRaw('CHAR_LENGTH(subject) > 255')->exists()) {
            throw new RuntimeException('Retain full ticket and conversation content before reducing storage capacity.');
        }
        Schema::table('it_tickets', fn (Blueprint $table) => $table->text('description')->nullable()->change());
        Schema::table('it_ticket_comments', fn (Blueprint $table) => $table->text('body')->change());
        Schema::table('it_email_deliveries', fn (Blueprint $table) => $table->string('subject')->change());
    }
};
