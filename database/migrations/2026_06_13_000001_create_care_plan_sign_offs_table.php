<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plan_sign_offs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('care_plan_id')->constrained('care_plans')->cascadeOnDelete();
            // Who agreed: the client, whānau, an EOR/welfare guardian, a key worker, NASC, or other.
            $table->string('party_role', 40);
            $table->string('party_name');
            $table->string('relationship', 120)->nullable();
            $table->date('agreed_on');
            // How the agreement was reached / recorded.
            $table->string('method', 40)->nullable();
            $table->text('acknowledgement')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['care_plan_id', 'party_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_sign_offs');
    }
};
