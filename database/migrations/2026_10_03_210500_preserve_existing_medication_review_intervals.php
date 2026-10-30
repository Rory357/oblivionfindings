<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve explicit legacy intervals without changing booked dates or
        // inventing clinical approval evidence. Three was the schema default.
        DB::table('clients')
            ->whereNull('medication_review_interval_months')
            ->whereBetween('chart_review_interval_months', [1, 12])
            ->where('chart_review_interval_months', '!=', 3)
            ->whereNotExists(function (Builder $query): void {
                // A real decision, including clearing the override, wins over
                // legacy data on a retry even if its actor was later removed.
                $query->selectRaw('1')->from('medication_review_events')
                    ->whereColumn('medication_review_events.client_id', 'clients.id')
                    ->where('event', 'interval_changed');
            })
            ->update(['medication_review_interval_months' => DB::raw('chart_review_interval_months')]);
    }

    public function down(): void
    {
        // Carried-forward person policy is authoritative data. Never erase it.
    }
};
