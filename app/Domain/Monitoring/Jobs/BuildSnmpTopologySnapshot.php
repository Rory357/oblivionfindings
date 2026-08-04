<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Topology\Services\NativeSnmpTopologyProjector;
use App\Support\SafeOperationalData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class BuildSnmpTopologySnapshot implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /**
     * @param  list<array<string, mixed>>  $observations
     * @param  list<string>  $completedSources
     */
    public function __construct(
        public readonly int $siteId,
        public readonly int $deviceId,
        public readonly string $checkpoint,
        public readonly array $observations,
        public readonly array $completedSources,
    ) {
        if ($siteId < 1 || $deviceId < 1 || $checkpoint === '' || strlen($checkpoint) > 2048
            || ! array_is_list($observations) || count($observations) > 2000
            || ! array_is_list($completedSources)) {
            throw new InvalidArgumentException('Native SNMP topology job input is invalid.');
        }
        $this->onConnection('redis');
        $this->onQueue((string) config('monitoring.queues.topology', 'monitoring-topology'));
    }

    public function handle(NativeSnmpTopologyProjector $projector): void
    {
        try {
            $projector->project(
                $this->siteId,
                $this->deviceId,
                $this->checkpoint,
                $this->observations,
                $this->completedSources,
            );
        } catch (Throwable $exception) {
            Log::error('Native SNMP topology projection failed.', SafeOperationalData::logContext([
                'site_id' => $this->siteId,
                'device_id' => $this->deviceId,
                'error_category' => SafeOperationalData::failureCategory($exception),
            ]));

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->deviceId.':'.hash('sha256', $this->checkpoint);
    }
}
