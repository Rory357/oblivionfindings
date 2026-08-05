<?php

use App\Models\HsEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hs_events', function (Blueprint $table) {
            $table->boolean('worksafe_notifiable')
                ->nullable()
                ->default(null)
                ->change();
            $table->timestamp('worksafe_decided_at')
                ->nullable()
                ->after('worksafe_notifiable');
            $table->foreignId('worksafe_decided_by_user_id')
                ->nullable()
                ->after('worksafe_decided_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('worksafe_decision_reason')
                ->nullable()
                ->after('worksafe_decided_by_user_id');
            $table->string('worksafe_decision_source', 32)
                ->nullable()
                ->after('worksafe_decision_reason');
        });

        DB::table('hs_events')
            ->where(function ($query): void {
                $query->where('worksafe_notifiable', true)
                    ->orWhereNotNull('worksafe_status')
                    ->orWhereNotNull('worksafe_notified_at')
                    ->orWhereNotNull('worksafe_acknowledged_at');
            })
            ->update([
                'worksafe_notifiable' => true,
                'worksafe_decided_at' => DB::raw(
                    'COALESCE(worksafe_acknowledged_at, worksafe_notified_at, updated_at, created_at)'
                ),
                'worksafe_decided_by_user_id' => DB::raw('created_by'),
                'worksafe_decision_reason' => 'Existing notifiable or notification state preserved during migration.',
                'worksafe_decision_source' => 'migration',
            ]);

        DB::table('hs_events')
            ->where('worksafe_notifiable', false)
            ->where('status', '!=', HsEvent::STATUS_CLOSED)
            ->whereNull('worksafe_decided_at')
            ->update([
                'worksafe_notifiable' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('hs_events', function (Blueprint $table) {
            $table->dropForeign(['worksafe_decided_by_user_id']);
            $table->dropColumn([
                'worksafe_decided_at',
                'worksafe_decided_by_user_id',
                'worksafe_decision_reason',
                'worksafe_decision_source',
            ]);
        });

        DB::table('hs_events')
            ->whereNull('worksafe_notifiable')
            ->update(['worksafe_notifiable' => false]);

        Schema::table('hs_events', function (Blueprint $table) {
            $table->boolean('worksafe_notifiable')
                ->default(false)
                ->nullable(false)
                ->change();
        });
    }
};
