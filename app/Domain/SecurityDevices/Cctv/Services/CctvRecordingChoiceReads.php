<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Source-owned, lexical metadata-choice proof; never grants playback, creation or incident access. */
final class CctvRecordingChoiceReads
{
    public const LIMIT = 64;
    private bool $active = true;
    private array $original = [];

    private function __construct(private readonly int $actorId, private readonly CurrentAuthorizationReads $reads,
        private readonly object $connection, private readonly int $transactionLevel, private readonly array $actorIdentity) {}

    /** The consumer finishes all its other reads before the fixed owner verifier executes. */
    public static function consume(int $actorId, CurrentAuthorizationReads $reads, callable $consumer): array
    {
        $reads->assertActive();
        $actor = app(CctvAuthority::class)->actor($actorId, false, true);
        abort_unless($actor->canDo(CctvRecordingReferences::VIEW), 404);
        $scope = new self($actorId, $reads, DB::connection(), DB::transactionLevel(), self::actorIdentity($actor));
        try {
            $result = $consumer($scope);
            if (!is_array($result)) { throw new LogicException('Choice consumers return detached arrays only.'); }
            self::detached($result);
            // Initial reads retain normal model events. This final read-only owner block cannot
            // invoke retrieved observers that revoke an earlier tuple while a later one is checked.
            // No caller callback, write or foreign authorization predicate executes in this block.
            $scope->verifyWithoutObservers();
            return $result;
        } finally { $scope->active = false; }
    }

    /** Exact original tuple, never a caller-provided grant, model, query or copied proof. */
    public function tuple(int $cameraId, int $recorderId, int $siteId): array
    {
        $this->assertActive();
        if (min($cameraId, $recorderId, $siteId) < 1 || $cameraId === $recorderId) { abort(404); }
        $key = $cameraId.':'.$recorderId.':'.$siteId;
        if (isset($this->original[$key])) { return $this->original[$key]['dto']; }
        if (count($this->original) >= self::LIMIT) { throw new LogicException('The explicit metadata-choice inventory exceeds its bound.'); }
        $original = $this->capture($cameraId, $recorderId, $siteId);
        $this->original[$key] = $original;
        return $original['dto'];
    }

    private function capture(int $cameraId, int $recorderId, int $siteId): array
    {
        $this->assertActive(); $authority = app(CctvAuthority::class);
        $actor = $authority->actor($this->actorId, false, true);
        abort_unless($actor->canDo(CctvRecordingReferences::VIEW)
            && self::actorIdentity($actor) === $this->actorIdentity, 404);
        [$camera, $site] = $authority->referenceDevice($actor, $cameraId);
        [$recorder, $recorderSite] = $authority->referenceDevice($actor, $recorderId, true);
        abort_unless($site === $siteId && $recorderSite === $siteId, 404);
        $source = $this->reads->query(CctvSource::query()->where('camera_id', $cameraId)->orderByDesc('revision'))->first();
        $matches = false;
        if ($source !== null) {
            try {
                [, $currentRecorder, $currentSite] = $authority->referenceSource($actor, $source, false);
                $matches = (int) $currentRecorder->id === $recorderId && $currentSite === $siteId;
            } catch (HttpExceptionInterface $error) {
                if (!in_array($error->getStatusCode(), [403, 404, 409, 503], true)) { throw $error; }
            }
        }
        return ['camera' => $camera->getRawOriginal(), 'recorder' => $recorder->getRawOriginal(),
            'latest_source' => $source?->getRawOriginal(),
            'dto' => ['camera' => ['id' => $cameraId, 'name' => $camera->name],
                'recorder' => ['id' => $recorderId, 'name' => $recorder->name], 'site_id' => $siteId,
                'references_href' => '/security-devices/devices/'.$cameraId.'/recording-references',
                'current_source_matches' => $matches]];
    }

    private function verifyOriginal(): void
    {
        $this->assertActive();
        // Empty inventories still retain original actor identity; no inventory is silently rebased.
        $actor = app(CctvAuthority::class)->actor($this->actorId, false, true);
        abort_unless($actor->canDo(CctvRecordingReferences::VIEW) && self::actorIdentity($actor) === $this->actorIdentity, 404);
        foreach ($this->original as $original) {
            $dto = $original['dto'];
            abort_unless($this->capture($dto['camera']['id'], $dto['recorder']['id'], $dto['site_id']) === $original, 404);
        }
        $this->assertActive();
    }

    private function verifyWithoutObservers(): void
    {
        $this->assertActive();
        // Connection callbacks run outside the Eloquent dispatcher. Never silently discard
        // beforeExecuting hooks: this narrow verifier supports only the canonical fixed SQL owner.
        $hooks = new \ReflectionProperty(\Illuminate\Database\Connection::class, 'beforeExecutingCallbacks');
        if ($hooks->getValue($this->connection) !== []) {
            throw new LogicException('Recording choice final proof cannot execute connection before-query callbacks.');
        }
        $events = $this->connection->getEventDispatcher();
        $this->connection->unsetEventDispatcher();
        try {
            // QueryExecuted, StatementPrepared and query-duration event listeners cannot mutate
            // an earlier choice during a later canonical check. No consumer callback runs here.
            Model::withoutEvents(fn () => $this->verifyOriginal());
        } finally {
            if ($events === null) { $this->connection->unsetEventDispatcher(); }
            else { $this->connection->setEventDispatcher($events); }
        }
    }

    private function assertActive(): void
    {
        if (!$this->active || $this->connection !== DB::connection() || DB::transactionLevel() !== $this->transactionLevel) {
            throw new LogicException('Recording choices left their original lexical transaction.');
        }
        $this->reads->assertActive();
    }

    private static function actorIdentity(User $actor): array
    {
        // The canonical current loader supplies the entire current catalogue/roles/overrides.
        return ['row' => $actor->getRawOriginal(), 'relations' => $actor->getRelations() === [] ? [] : $actor->relationsToArray()];
    }

    private static function detached(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $item) { self::detached($item); } return; }
        if ($value !== null && !is_scalar($value)) { throw new LogicException('Recording choices cannot export models, proof handles or callbacks.'); }
    }
}
