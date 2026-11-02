<?php

namespace App\Console\Commands;

use App\Domain\Obli\Fixes\ObliFixSettings;
use App\Domain\Obli\Fixes\ObliScreenPictures;
use App\Domain\Obli\ObliAiSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase I, daily: Obli's screen pictures go after 7 days (file and row, D17); help sessions
 * the tray left open end; what people typed and Obli said is scrubbed when the chat retention ends
 * (Settings › Obli, 7 days by default); the bare records and offline reports go after 90 days.
 */
final class ObliFixesPrune extends Command
{
    protected $signature = 'obli:fixes-prune';

    protected $description = 'Delete Obli screen pictures after 7 days, end abandoned fix sessions and scrub old fix records';

    public function handle(ObliScreenPictures $pictures, ObliFixSettings $settings, ObliAiSettings $ai): int
    {
        if (! $settings->schemaReady()) {
            return self::SUCCESS;
        }
        $deleted = 0;
        do {
            $batch = $pictures->prune(500);
            $deleted += $batch;
        } while ($batch === 500);

        // Sessions the tray never finished: the 15 minutes (or the hour waiting for IT) are long gone.
        $ended = DB::table('obli_fix_sessions')
            ->whereIn('state', ['confirming', 'planning', 'planned', 'working', 'paused_password', 'fix_running'])
            ->where('expires_at', '<', now()->subMinutes(10))
            ->update(['state' => 'expired', 'outcome' => 'expired', 'stop_reason' => 'time', 'ended_at' => now(), 'pending_turn' => null, 'updated_at' => now()]);
        $ended += DB::table('obli_fix_sessions')->where('state', 'waiting_it')
            ->where('waiting_since', '<', now()->subMinutes(ObliFixSettings::WAIT_MINUTES + 10))
            ->update(['state' => 'expired', 'outcome' => 'expired', 'stop_reason' => 'time', 'ended_at' => now(), 'pending_turn' => null, 'waiting_since' => null, 'updated_at' => now()]);
        DB::table('obli_fix_requests')->where('state', 'requested')
            ->whereIn('session_id', DB::table('obli_fix_sessions')->whereIn('state', ['expired', 'stopped', 'done', 'handed_to_it', 'refused', 'needs_it'])->select('id'))
            ->update(['state' => 'expired', 'updated_at' => now()]);

        // Words people typed and Obli said: scrubbed with the chat retention.
        $days = max(1, min(90, (int) $ai->current()['chat_retention_days']));
        $scrubbed = 0;
        DB::table('obli_fix_sessions')->whereNull('scrubbed_at')->whereNotNull('ended_at')->where('created_at', '<', now()->subDays($days))
            ->orderBy('id')->limit(1000)->pluck('id')
            ->each(function (int $id) use (&$scrubbed): void {
                DB::transaction(function () use ($id): void {
                    DB::table('obli_fix_steps')->where('session_id', $id)->update(['narration_encrypted' => null, 'text_encrypted' => null, 'result_encrypted' => null]);
                    DB::table('obli_fix_requests')->where('session_id', $id)->update(['what_encrypted' => null, 'commands_encrypted' => null, 'note_encrypted' => null]);
                    DB::table('obli_fix_sessions')->where('id', $id)->update([
                        'problem_encrypted' => null, 'checks_encrypted' => null, 'plan_encrypted' => null, 'outcome_encrypted' => null,
                        'last_response_encrypted' => null, 'scrubbed_at' => now(), 'updated_at' => now(),
                    ]);
                });
                $scrubbed++;
            });

        // The bare records (who, which computer, when, how it ended): 90 days.
        $old = DB::table('obli_fix_sessions')->where('created_at', '<', now()->subDays(ObliFixSettings::RECORD_DAYS))->orderBy('id')->limit(1000)->pluck('id');
        foreach ($old as $id) {
            $pictures->deleteForSession((int) $id);
            DB::table('obli_fix_sessions')->where('id', $id)->delete();
        }
        if (Schema::hasTable('obli_offline_reports')) {
            DB::table('obli_offline_reports')->where('created_at', '<', now()->subDays(ObliFixSettings::RECORD_DAYS))->delete();
        }

        $this->info("Pictures deleted: {$deleted}. Sessions ended: {$ended}. Records scrubbed: {$scrubbed}. Records removed: {$old->count()}.");

        return self::SUCCESS;
    }
}
