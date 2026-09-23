<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_compliance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('kind', 32);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'kind'], 'fleet_vcr_asset_kind_uq');
        });

        Schema::create('fleet_vehicle_compliance_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('fleet_vehicle_compliance_records')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('supersedes_version_id')->nullable()->constrained('fleet_vehicle_compliance_versions')->restrictOnDelete();
            $table->string('applicability', 24);
            $table->text('applicability_basis')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->string('outcome', 32);
            $table->string('evidence_reference', 255)->nullable();
            $table->foreignId('asset_document_id')->nullable()->constrained('asset_documents')->restrictOnDelete();
            $table->string('document_trust', 32)->nullable();
            $table->date('effective_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('ruc_start_km', 12, 1)->nullable();
            $table->decimal('ruc_end_km', 12, 1)->nullable();
            $table->dateTime('observed_at', 6)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->char('content_sha256', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['record_id', 'version'], 'fleet_vcv_record_version_uq');
            $table->unique(['record_id', 'request_key'], 'fleet_vcv_record_request_uq');
            $table->index(['record_id', 'created_at'], 'fleet_vcv_record_created_idx');
        });

        Schema::table('fleet_vehicle_compliance_records', function (Blueprint $table): void {
            $table->foreign('current_version_id', 'fleet_vcr_current_version_fk')
                ->references('id')->on('fleet_vehicle_compliance_versions')->restrictOnDelete();
        });

        Schema::create('fleet_vehicle_odometer_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->decimal('value_km', 12, 1);
            $table->dateTime('observed_at', 6);
            $table->string('source_kind', 32);
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('corrects_observation_id')->nullable();
            $table->foreign('corrects_observation_id', 'fleet_voo_corrects_fk')->references('id')->on('fleet_vehicle_odometer_observations')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['asset_id', 'request_key'], 'fleet_voo_asset_request_uq');
            $table->index(['asset_id', 'observed_at', 'id'], 'fleet_voo_asset_observed_idx');
        });

        Schema::table('fleet_vehicle_bookings', function (Blueprint $table): void {
            $table->string('approval_route', 24)->default('required')->after('status');
            $table->text('approval_not_required_reason')->nullable()->after('approval_route');
            $table->string('approval_not_required_evidence', 255)->nullable()->after('approval_not_required_reason');
            $table->foreignId('approval_authority_recorded_by')->nullable()->after('approval_not_required_evidence')->constrained('users')->restrictOnDelete();
            $table->dateTime('approval_authority_recorded_at')->nullable()->after('approval_authority_recorded_by');
            $table->dateTime('approved_at')->nullable()->after('approved_by_user_id');
        });

        $dateFields = [
            'registration' => 'registration_expires_at',
            'wof' => 'wof_expires_at',
            'cof' => 'cof_expires_at',
        ];
        DB::table('assets')->where(function ($vehicle): void {
            $vehicle->where('category', 'vehicle')->orWhereExists(fn ($q) => $q->selectRaw('1')->from('asset_categories')
                ->whereColumn('asset_categories.id', 'assets.asset_category_id')->where('asset_categories.slug', 'vehicle'));
        })->where(function ($query) use ($dateFields): void {
            foreach ($dateFields as $field) {
                $query->orWhereNotNull($field);
            }
        })->orderBy('id')->chunkById(200, function ($assets) use ($dateFields): void {
            foreach ($assets as $asset) {
                foreach ($dateFields as $kind => $field) {
                    if (! $asset->{$field}) continue;
                    $recordId = DB::table('fleet_vehicle_compliance_records')->insertGetId([
                        'asset_id' => $asset->id, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $content = ['applicability' => 'unknown', 'outcome' => 'needs_assessment', 'expires_on' => $asset->{$field}];
                    $versionId = DB::table('fleet_vehicle_compliance_versions')->insertGetId([
                        'record_id' => $recordId, 'version' => 1, 'applicability' => 'unknown',
                        'outcome' => 'needs_assessment', 'expires_on' => $asset->{$field},
                        'source_type' => 'legacy_asset_field', 'source_reference' => $field,
                        'request_key' => "legacy-{$asset->id}-{$kind}",
                        'request_fingerprint' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)),
                        'content_sha256' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)),
                        'created_at' => now(),
                    ]);
                    DB::table('fleet_vehicle_compliance_records')->where('id', $recordId)->update(['current_version_id' => $versionId]);
                }
            }
        });

        DB::table('assets')->where(function ($vehicle): void {
            $vehicle->where('category', 'vehicle')->orWhereExists(fn ($q) => $q->selectRaw('1')->from('asset_categories')
                ->whereColumn('asset_categories.id', 'assets.asset_category_id')->where('asset_categories.slug', 'vehicle'));
        })->whereNotNull('odometer_km')->orderBy('id')->chunkById(200, function ($assets): void {
            foreach ($assets as $asset) {
                $payload = ['value_km' => (string) $asset->odometer_km, 'source_kind' => 'legacy_unverified'];
                DB::table('fleet_vehicle_odometer_observations')->insert([
                    'asset_id' => $asset->id, 'value_km' => $asset->odometer_km,
                    'observed_at' => $asset->updated_at ?? $asset->created_at ?? now(),
                    'source_kind' => 'legacy_unverified', 'source_type' => 'legacy_asset_field',
                    'source_reference' => 'assets.odometer_km', 'request_key' => "legacy-{$asset->id}-odometer",
                    'request_fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        $changedCompliance = DB::table('fleet_vehicle_compliance_records as record')
            ->leftJoin('fleet_vehicle_compliance_versions as version', 'version.id', '=', 'record.current_version_id')
            ->where(fn ($q) => $q->whereNull('version.id')->orWhere('version.source_type', '!=', 'legacy_asset_field')
                ->orWhere('version.version', '!=', 1)->orWhereNotNull('version.supersedes_version_id')
                ->orWhereNotNull('version.recorded_by_user_id'))
            ->exists()
            || DB::table('fleet_vehicle_compliance_versions')
                ->where(fn ($q) => $q->where('source_type', '!=', 'legacy_asset_field')->orWhereNull('source_type')
                    ->orWhere('version', '!=', 1)->orWhereNotNull('supersedes_version_id')->orWhereNotNull('recorded_by_user_id'))
                ->exists();
        $changedOdometer = DB::table('fleet_vehicle_odometer_observations')
            ->where(fn ($q) => $q->where('source_kind', '!=', 'legacy_unverified')
                ->orWhere('source_type', '!=', 'legacy_asset_field')->orWhereNull('source_type')
                ->orWhereNotNull('recorded_by_user_id')->orWhereNotNull('corrects_observation_id'))
            ->exists();
        if ($changedCompliance || $changedOdometer
            || DB::table('fleet_vehicle_bookings')->where(fn ($q) => $q->where('approval_route', '!=', 'required')
                ->orWhereNotNull('approval_not_required_reason')->orWhereNotNull('approval_not_required_evidence')
                ->orWhereNotNull('approval_authority_recorded_by')->orWhereNotNull('approval_authority_recorded_at')
                ->orWhereNotNull('approved_at'))->exists()) {
            throw new RuntimeException('PKG-02B readiness evidence exists; preserve it instead of rolling back the source contract.');
        }
        Schema::table('fleet_vehicle_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approval_authority_recorded_by');
            $table->dropColumn(['approval_route', 'approval_not_required_reason', 'approval_not_required_evidence', 'approval_authority_recorded_at', 'approved_at']);
        });
        Schema::dropIfExists('fleet_vehicle_odometer_observations');
        Schema::table('fleet_vehicle_compliance_records', fn (Blueprint $table) => $table->dropForeign('fleet_vcr_current_version_fk'));
        Schema::dropIfExists('fleet_vehicle_compliance_versions');
        Schema::dropIfExists('fleet_vehicle_compliance_records');
    }
};
