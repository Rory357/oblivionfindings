<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hazardous_substances', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('common_name')->nullable();
            $table->string('un_number')->nullable();
            $table->string('hsno_approval')->nullable();
            $table->string('hsno_classification')->nullable();
            $table->json('hazard_classifications')->nullable();
            $table->json('ghs_pictograms')->nullable();
            $table->string('signal_word')->nullable();
            $table->text('hazard_statements')->nullable();
            $table->text('precautionary_statements')->nullable();
            $table->string('physical_form')->nullable();
            $table->text('first_aid_measures')->nullable();
            $table->text('firefighting_measures')->nullable();
            $table->text('spill_procedures')->nullable();
            $table->text('handling_precautions')->nullable();
            $table->text('storage_requirements')->nullable();
            $table->text('ppe_required')->nullable();
            $table->string('exposure_limit_type')->nullable();
            $table->string('exposure_limit_value')->nullable();
            $table->boolean('requires_tracking')->default(true);
            $table->boolean('is_controlled_substance')->default(false);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('name');
        });

        Schema::create('safety_data_sheets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hazardous_substance_id');
            $table->string('version');
            $table->date('issue_date');
            $table->date('review_date')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_contact')->nullable();
            $table->string('document_path');
            $table->string('status')->default('current');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hazardous_substance_id')->references('id')->on('hazardous_substances')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
        });

        Schema::create('substance_storage_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hazardous_substance_id');
            $table->unsignedBigInteger('site_id');
            $table->string('location_description');
            $table->decimal('current_quantity', 10, 2)->nullable();
            $table->string('quantity_unit')->nullable();
            $table->decimal('maximum_quantity', 10, 2)->nullable();
            $table->string('container_type')->nullable();
            $table->boolean('properly_labelled')->default(true);
            $table->boolean('segregation_compliant')->default(true);
            $table->date('last_audit_date')->nullable();
            $table->text('storage_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hazardous_substance_id')->references('id')->on('hazardous_substances')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['hazardous_substance_id', 'site_id']);
        });

        Schema::create('substance_exposure_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hazardous_substance_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->dateTime('exposed_at');
            $table->string('exposure_type');
            $table->string('exposure_duration')->nullable();
            $table->text('circumstances')->nullable();
            $table->text('symptoms')->nullable();
            $table->text('first_aid_given')->nullable();
            $table->boolean('medical_attention_sought')->default(false);
            $table->text('medical_outcome')->nullable();
            $table->boolean('incident_reported')->default(false);
            $table->unsignedBigInteger('related_incident_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hazardous_substance_id')->references('id')->on('hazardous_substances')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('related_incident_id')->references('id')->on('client_incidents')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('exposed_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substance_exposure_records');
        Schema::dropIfExists('substance_storage_locations');
        Schema::dropIfExists('safety_data_sheets');
        Schema::dropIfExists('hazardous_substances');
    }
};
