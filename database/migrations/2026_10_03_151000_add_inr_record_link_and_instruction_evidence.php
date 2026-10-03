<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_inr_records', function (Blueprint $table) {
            $table->text('unlinked_reason')->nullable();
            $table->text('instruction')->nullable();
            $table->string('instruction_source', 255)->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('disabled_reason')->nullable();
        });
    }
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('client_inr_records')->whereNotNull('instruction')->orWhereNotNull('unlinked_reason')->orWhereNotNull('linked_at')->orWhereNotNull('disabled_reason')->exists()) throw new LogicException('INR evidence must be retained.');
        Schema::table('client_inr_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_by');
            $table->dropColumn(['unlinked_reason', 'instruction', 'instruction_source', 'linked_at', 'disabled_reason']);
        });
    }
};
