<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────
        // Client Ledger Entries — personal financial transactions
        //
        // These capture money movements that relate to a specific client
        // but may not originate from the GL (e.g. petty cash purchases,
        // resident contributions, pocket money). Entries with financial
        // impact are mirrored to GL via the observer + FinancialEventService.
        //
        // This is NOT a duplicate ledger — it's the operational source for
        // client-level transactions that then flow into the central GL.
        // ──────────────────────────────────────────────────────────
        Schema::create('client_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            // Classification
            $table->enum('type', [
                'contribution',   // Client/family pays towards costs
                'funding',        // Funding body payment attributed to client
                'purchase',       // Personal purchase on client's behalf
                'reimbursement',  // Money returned to client
                'adjustment',     // Correction or balance adjustment
                'transfer',       // Internal movement (no GL impact)
            ]);
            $table->string('category', 50)->nullable(); // groceries, clothing, activities, etc.
            $table->enum('direction', ['inflow', 'outflow']);

            // Financial
            $table->decimal('amount', 10, 2);
            $table->string('description');
            $table->string('reference')->nullable(); // Receipt number, funding ref, etc.

            // GL linkage
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->boolean('posts_to_gl')->default(true); // false = informational only

            // Audit
            $table->date('entry_date');
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->datetime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'entry_date']);
            $table->index(['tenant_id', 'entry_date']);
            $table->index(['client_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_ledger_entries');
    }
};
