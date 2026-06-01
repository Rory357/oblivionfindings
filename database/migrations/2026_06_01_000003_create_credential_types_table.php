<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-scoped registry that powers the credential-type tile picker.
        // Built-in types are merged in at read time (see CredentialType::
        // effectiveForTenant), so this table only stores per-tenant overrides
        // and custom types — no seeder is required for the defaults to appear.
        Schema::create('credential_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('key', 50);
            $table->string('label', 100);
            $table->string('icon', 50)->default('lock');
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_types');
    }
};
