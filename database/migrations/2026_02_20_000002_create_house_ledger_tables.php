<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('house_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('current_balance', 12, 2)->default(0);
            $table->string('currency', 10)->default('NZD');
            $table->datetime('last_reconciled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('house_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('house_ledger_id')->constrained('house_ledgers')->cascadeOnDelete();
            $table->enum('entry_type', ['income', 'expense', 'adjustment', 'transfer']);
            $table->string('category', 50);
            $table->string('description', 255);
            $table->string('reference')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('running_balance', 12, 2);
            $table->date('entry_date');
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->datetime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_ledger_entries');
        Schema::dropIfExists('house_ledgers');
    }
};
