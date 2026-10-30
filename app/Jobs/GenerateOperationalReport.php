<?php

namespace App\Jobs;

use App\Models\OperationalReportRun;
use App\Models\User;
use App\Notifications\OperationalReportReady;
use App\Services\Reporting\ReportRuns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateOperationalReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $runId, public readonly bool $notify = false) {}

    public function handle(ReportRuns $runs): void
    {
        if ($run = OperationalReportRun::find($this->runId)) {
            $runs->execute($run);
            if ($this->notify && $run->fresh()->status === 'ready') {
                $actor = User::find($run->user_id);
                if ($actor) {
                    $runs->result($actor, $run->fresh());
                    $actor->notify(new OperationalReportReady(config('operational-reports.sources.'.$run->definition['source'].'.domain')));
                }
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        OperationalReportRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'failure_code' => 'generation_failed', 'payload' => null]);
    }
}
