<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_ticket_comments', function (Blueprint $table): void {
            // Current permissions cannot establish historical speaker roles.
            // Only a canonical interaction with captured evidence writes these.
            $table->string('speaker_side', 16)->nullable();
            $table->string('source_channel', 20)->nullable();
        });
        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->foreignId('last_public_comment_id')->nullable()->constrained('it_ticket_comments')->nullOnDelete();
            $table->timestamp('last_public_commented_at')->nullable();
            $table->string('last_public_speaker_side', 16)->nullable();
            $table->string('next_response_party', 16)->nullable();
            $table->index(['next_response_party', 'status'], 'it_tickets_response_party_status_index');
        });
        Schema::table('it_ticket_command_receipts', function (Blueprint $table): void {
            $table->foreignId('it_ticket_comment_id')->nullable()->constrained('it_ticket_comments')->restrictOnDelete();
            $table->unsignedBigInteger('committed_ticket_version')->nullable();
            // Opaque command outcomes/draft identities; never message or file content.
            $table->json('result_metadata')->nullable();
            $table->unique('it_ticket_comment_id', 'it_ticket_command_comment_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('it_ticket_comments')->whereNotNull('speaker_side')->orWhereNotNull('source_channel')->exists()
            || DB::table('it_tickets')->whereNotNull('last_public_comment_id')->orWhereNotNull('next_response_party')->exists()
            || DB::table('it_ticket_command_receipts')->where('operation', 'ticket.comment')
                ->orWhereNotNull('it_ticket_comment_id')->orWhereNotNull('result_metadata')
                ->orWhereNotNull('committed_ticket_version')->exists()) {
            throw new RuntimeException('Retain recorded conversation responsibility and command receipts. Repair forward instead of removing committed evidence.');
        }
        Schema::table('it_ticket_command_receipts', function (Blueprint $table): void {
            $table->dropForeign(['it_ticket_comment_id']);
            $table->dropUnique('it_ticket_command_comment_unique');
            $table->dropColumn(['it_ticket_comment_id', 'committed_ticket_version', 'result_metadata']);
        });
        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->dropForeign(['last_public_comment_id']);
            $table->dropIndex('it_tickets_response_party_status_index');
            $table->dropColumn(['last_public_comment_id', 'last_public_commented_at', 'last_public_speaker_side', 'next_response_party']);
        });
        Schema::table('it_ticket_comments', function (Blueprint $table): void {
            $table->dropColumn(['speaker_side', 'source_channel']);
        });
    }
};
