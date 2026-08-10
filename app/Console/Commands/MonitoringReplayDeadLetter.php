<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Services\MonitoringReplayService;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

final class MonitoringReplayDeadLetter extends Command
{
    protected $signature = 'monitoring:replay-dead-letter
        {letter : Dead-letter record ID}
        {--actor= : User ID of the accountable operator}
        {--reason= : Required operational reason}
        {--discard : Resolve without replaying}';

    protected $description = 'Replay or discard a monitoring dead letter with explicit operator attribution';

    public function handle(MonitoringReplayService $replay): int
    {
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        $reason = trim((string) $this->option('reason'));

        if ($actorId === false || $actorId < 1 || $reason === '') {
            $this->error('Both --actor and --reason are required.');

            return self::FAILURE;
        }

        $actor = User::query()->find($actorId);
        $letter = MonitoringDeadLetter::query()->find($this->argument('letter'));

        if ($actor === null || $letter === null) {
            $this->error('The actor or dead-letter record was not found.');

            return self::FAILURE;
        }

        try {
            if ((bool) $this->option('discard')) {
                $replay->discard($actor, $letter, $reason);
                $this->info('Monitoring dead letter discarded.');
            } else {
                $replay->replay($actor, $letter, $reason);
                $this->info('Monitoring dead letter replay requested.');
            }
        } catch (Throwable) {
            $this->error('Monitoring dead-letter operation was denied or failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
