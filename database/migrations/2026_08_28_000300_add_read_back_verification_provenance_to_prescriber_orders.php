<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('medication_prescriber_orders')
            ->where('status', 'pending')
            ->where(function ($pending): void {
                $pending->where('requires_countersign', true)
                    ->orWhereIn('order_type', ['verbal', 'telephone'])
                    ->orWhereNotNull('countersigned_at')
                    ->orWhereNotNull('countersigned_by')
                    ->orWhereNotNull('countersign_method');
            })
            ->exists()) {
            throw new RuntimeException(
                'Cannot add verified read-back provenance while legacy pending countersign or inconsistent pending/countersigned orders exist. Resolve or cancel every affected legacy pending order before deploying this migration.',
            );
        }

        Schema::table('medication_prescriber_orders', function (Blueprint $table): void {
            $table->timestamp('read_back_verified_at')
                ->nullable()
                ->after('read_back_witnessed_by');
            $table->string('read_back_verification_method', 32)
                ->nullable()
                ->after('read_back_verified_at');
        });
    }

    public function down(): void
    {
        if (DB::table('medication_prescriber_orders')
            ->where(function ($query): void {
                $query->whereNotNull('read_back_verified_at')
                    ->orWhereNotNull('read_back_verification_method');
            })
            ->exists()) {
            throw new RuntimeException(
                'Cannot remove verified read-back provenance while retained verification evidence exists.',
            );
        }

        Schema::table('medication_prescriber_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'read_back_verification_method',
                'read_back_verified_at',
            ]);
        });
    }
};
