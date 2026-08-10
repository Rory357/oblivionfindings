<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->foreignId('requester_user_id')->nullable()->change();
            $table->string('work_type')->default('incident')->after('source');
            $table->string('impact')->default('individual')->after('priority');
            $table->string('urgency')->default('normal')->after('impact');
            $table->string('status_reason')->nullable()->after('status');
            $table->string('waiting_reason')->nullable()->after('status_reason');
            $table->string('resolution_code')->nullable()->after('resolved_at');
            $table->text('resolution_summary')->nullable()->after('resolution_code');
            $table->timestamp('monitoring_recovered_at')->nullable()->after('resolution_summary');

            $table->index(['tenant_id', 'work_type', 'status'], 'it_tickets_tenant_type_status_idx');
        });

        Schema::create('it_ticket_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->string('relationship');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->json('context')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['ticket_id', 'relationship', 'linkable_type', 'linkable_id'],
                'it_ticket_links_target_uq',
            );
            $table->index(
                ['tenant_id', 'linkable_type', 'linkable_id'],
                'it_ticket_links_tenant_target_idx',
            );
        });
    }

    public function down(): void
    {
        if (DB::table('it_tickets')->whereNull('requester_user_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore requester_user_id to non-null while requester-less system tickets exist.',
            );
        }

        Schema::dropIfExists('it_ticket_links');

        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->dropIndex('it_tickets_tenant_type_status_idx');
            $table->dropColumn([
                'work_type',
                'impact',
                'urgency',
                'status_reason',
                'waiting_reason',
                'resolution_code',
                'resolution_summary',
                'monitoring_recovered_at',
            ]);
        });

        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->foreignId('requester_user_id')->nullable(false)->change();
        });
    }
};
