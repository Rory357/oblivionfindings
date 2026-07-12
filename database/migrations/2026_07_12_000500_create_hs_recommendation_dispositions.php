<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_recommendation_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hs_investigation_id');
            $table->unsignedSmallInteger('recommendation_index');
            $table->string('disposition', 30);
            $table->text('reason')->nullable();
            $table->foreignId('hs_corrective_action_id')->nullable();
            $table->foreignId('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['hs_investigation_id', 'recommendation_index'],
                'hs_rec_disp_investigation_recommendation_unique'
            );

            $table->foreign('hs_investigation_id', 'hs_rec_disp_investigation_fk')
                ->references('id')
                ->on('hs_investigations')
                ->cascadeOnDelete();
            $table->foreign('hs_corrective_action_id', 'hs_rec_disp_corrective_action_fk')
                ->references('id')
                ->on('hs_corrective_actions')
                ->nullOnDelete();
            $table->foreign('decided_by_user_id', 'hs_rec_disp_decided_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_recommendation_dispositions');
    }
};
