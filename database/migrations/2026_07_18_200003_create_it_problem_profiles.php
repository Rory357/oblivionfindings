<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_problems', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('ticket_id')->unique()->constrained('it_tickets')->cascadeOnDelete();
            $table->text('impact_summary')->nullable();
            $table->longText('root_cause')->nullable();
            $table->longText('workaround')->nullable();
            $table->longText('corrective_action')->nullable();
            $table->timestamp('known_error_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'known_error_at'], 'it_problems_tenant_known_error_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_problems');
    }
};
