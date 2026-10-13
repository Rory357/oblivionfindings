<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PruneObliSessions extends Command
{
    protected $signature = 'obli:prune-sessions';

    protected $description = 'Remove expired native helper sign-in material after one day.';

    public function handle(): int
    {
        if (Schema::hasTable('obli_computer_contexts')) {
            $cutoff = now();
            for ($batch = 0; $batch < 10; $batch++) {
                $ids = DB::table('obli_computer_contexts')->where('expires_at', '<=', $cutoff)->whereNotNull('challenge_encrypted')->orderBy('id')->limit(100)->pluck('id');
                if ($ids->isEmpty()) {
                    break;
                }
                DB::table('obli_computer_contexts')->whereIn('id', $ids)->where('expires_at', '<=', $cutoff)->update(['challenge_encrypted' => null]);
            }
            for ($batch = 0; $batch < 10; $batch++) {
                $ids = DB::table('obli_computer_contexts')->whereNull('ticket_id')->where('expires_at', '<', $cutoff->copy()->subDay())->orderBy('id')->limit(100)->pluck('id');
                if ($ids->isEmpty()) {
                    break;
                }
                DB::table('obli_computer_contexts')->whereIn('id', $ids)->whereNull('ticket_id')->where('expires_at', '<', $cutoff->copy()->subDay())->delete();
            }
        }
        foreach (['obli_authorization_codes', 'obli_staff_sessions'] as $table) {
            DB::table($table)->where('expires_at', '<', now()->subDay())->delete();
        }
        DB::table('obli_ai_requests')->where('status', 'pending')->where('created_at', '<', now()->subMinutes(2))
            ->update(['status' => 'unconfirmed']);
        DB::table('obli_ai_requests')->where('expires_at', '<=', now())->delete();
        DB::table('obli_ai_budget_periods')->where('period', 'like', 'day:%')->where('period', '<', 'day:'.now('UTC')->subDays(400)->format('Y-m-d'))->delete();
        DB::table('obli_ai_budget_periods')->where('period', 'like', 'month:%')->where('period', '<', 'month:'.now('UTC')->subMonths(14)->format('Y-m'))->delete();
        $this->info('Expired Obli sign-in and chat material removed.');

        return self::SUCCESS;
    }
}
