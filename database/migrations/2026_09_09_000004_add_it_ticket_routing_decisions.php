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
            $table->json('priority_decision')->nullable();
            $table->json('routing_decision')->nullable();
            $table->json('routing_override')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('it_tickets')->whereNotNull('priority_decision')
            ->orWhereNotNull('routing_decision')->orWhereNotNull('routing_override')->exists()) {
            throw new RuntimeException('Retain recorded IT triage decisions; export and review a recovery plan before removing them.');
        }

        Schema::table('it_tickets', fn (Blueprint $table) => $table->dropColumn([
            'priority_decision', 'routing_decision', 'routing_override',
        ]));
    }
};
