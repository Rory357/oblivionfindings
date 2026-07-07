<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §P.6 — per-tenant, per-priority SLA targets. Rows are OPTIONAL: the
 * engine falls back to the §G defaults in code (ItSlaPolicy::DEFAULTS)
 * when a tenant hasn't customised, so deploys that skip seeders still
 * stamp every ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('priority'); // low | normal | high | urgent
            $table->unsignedInteger('first_response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->timestamps();

            $table->unique(['tenant_id', 'priority'], 'it_sla_policies_tenant_priority_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_sla_policies');
    }
};
