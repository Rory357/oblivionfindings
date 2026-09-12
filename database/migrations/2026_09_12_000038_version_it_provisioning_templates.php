<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_provisioning_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provisioning_template_id');
            $table->foreign('provisioning_template_id', 'it_prov_tpl_ver_template_fk')
                ->references('id')
                ->on('it_provisioning_templates')
                ->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('contract');
            $table->string('provenance', 40);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['provisioning_template_id', 'version'], 'it_provisioning_template_version_unique');
        });
        Schema::table('it_provisioning_templates', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('current_version_id')->nullable()->constrained('it_provisioning_template_versions')->restrictOnDelete();
        });
        Schema::table('it_provisioning_workflows', function (Blueprint $table): void {
            $table->foreignId('template_version_id')->nullable()->constrained('it_provisioning_template_versions')->restrictOnDelete();
        });
        // Existing workflows have no provable historical template contract.
        // Keep their version absent. Legacy templates are captured under lock
        // on their next governed edit or launch, without inventing past review.
    }

    public function down(): void
    {
        if (DB::table('it_provisioning_template_versions')->exists()) {
            throw new RuntimeException('Preserve provisioning template history before removing version enforcement.');
        }
        Schema::table('it_provisioning_workflows', fn (Blueprint $table) => $table->dropConstrainedForeignId('template_version_id'));
        Schema::table('it_provisioning_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
            $table->dropColumn('lock_version');
        });
        Schema::dropIfExists('it_provisioning_template_versions');
    }
};
