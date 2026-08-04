<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Exceptions\ProviderTopologyDeferred;
use App\Domain\Monitoring\Topology\Services\ProviderTopologyCollector;
use App\Domain\Monitoring\Topology\Services\TopologySnapshotBuilder;
use App\Models\Site;
use App\Services\Integration\Exceptions\CapabilityUnavailable;
use App\Support\SafeOperationalData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class BuildTopologySnapshot implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /**
     * @param  list<array<string, mixed>>  $evidence
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $source,
        public readonly string $checkpoint,
        public readonly array $evidence = [],
        public readonly ?string $provider = null,
        public readonly ?string $sourceEnvelopeId = null,
    ) {
        if ($siteId < 1
            || preg_match('/^[a-z0-9][a-z0-9:_-]{0,127}$/', $source) !== 1
            || $checkpoint === ''
            || strlen($checkpoint) > 2048
            || count($evidence) > 5000
            || ($provider !== null && (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider) !== 1 || $evidence !== []))
            || ($sourceEnvelopeId !== null && ! Str::isUuid($sourceEnvelopeId))) {
            throw new InvalidArgumentException('Topology snapshot job input is invalid.');
        }
        $this->onConnection('redis');
        $this->onQueue((string) config('monitoring.queues.topology', 'monitoring-topology'));
    }

    public function handle(
        TopologySnapshotBuilder $builder,
        ProviderTopologyCollector $providers,
        MonitoringOutboxPublisher $outbox,
    ): void {
        $site = Site::query()
            ->whereKey($this->siteId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->first();
        if ($site === null) {
            Log::info('Topology snapshot Site is unavailable.', ['site_id' => $this->siteId]);

            return;
        }

        try {
            $evidence = $this->provider === null
                ? array_map(fn (array $item): TopologyEvidence => TopologyEvidence::fromArray($item), $this->evidence)
                : $providers->collect($this->siteId, $this->provider);
            $snapshot = $builder->build(
                $site,
                $evidence,
                source: $this->source,
                sourceCheckpoint: $this->checkpoint,
                sourceEnvelopeId: $this->sourceEnvelopeId,
            );
            $outbox->stage(
                type: RuntimeMessageType::Projection,
                stream: (string) config('monitoring.queues.topology', 'monitoring-topology'),
                source: "topology:site:{$this->siteId}:".hash('sha256', $this->source),
                idempotencyKey: "topology-snapshot:{$snapshot->snapshot_uuid}",
                payload: [
                    'projection_family' => 'topology_snapshot',
                    'site_id' => $this->siteId,
                    'snapshot_id' => $snapshot->id,
                    'snapshot_uuid' => $snapshot->snapshot_uuid,
                    'source' => $snapshot->source,
                    'checkpoint_hash' => $snapshot->source_checkpoint_hash,
                    'node_count' => $snapshot->node_count,
                    'edge_count' => $snapshot->edge_count,
                    'change_count' => $snapshot->change_count,
                ],
            );
        } catch (ProviderTopologyDeferred $exception) {
            if ($this->job !== null) {
                $this->release(max(1, $exception->retryAfterSeconds));
            }
        } catch (CapabilityUnavailable $exception) {
            Log::info('Topology provider capability is unavailable.', [
                'site_id' => $this->siteId,
                'provider' => $this->provider,
            ]);
        } catch (Throwable $exception) {
            Log::error('Topology snapshot build failed.', SafeOperationalData::logContext([
                'site_id' => $this->siteId,
                'source' => $this->source,
                'provider' => $this->provider,
                'error_category' => SafeOperationalData::failureCategory($exception),
            ]));

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.hash('sha256', $this->source.':'.$this->checkpoint);
    }
}
