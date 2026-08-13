<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\AlertDiscussion;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\AlertWatcher;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\TimeEntry;
use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Canonical parent-first resolver for every alert-owned Control Room record.
 *
 * Route child parameters stay scalar so Laravel never discloses a globally
 * bound child before the supplied alert has passed the Site access boundary.
 * Every child is then queried through that freshly authorized parent.
 */
final class ControlRoomAlertNestedResourceResolver
{
    public function __construct(
        private ControlRoomAlertAccessService $alertAccess,
    ) {}

    public function alert(User $user, ControlRoomAlert $alert, bool $lockForUpdate = false): ControlRoomAlert
    {
        $this->alertAccess->assertCanView($alert, $user);

        $query = ControlRoomAlert::query()->whereKey($alert->getKey());
        $this->alertAccess->applyReadableScope($query, $user);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $resolved = $query->first();
        if (! $resolved) {
            throw new HttpException(404, 'Control Room alert not found.');
        }

        return $resolved;
    }

    /**
     * Resolve a mixed/bulk selection atomically through the same parent Site scope.
     *
     * @param  iterable<int|string>  $alertIds
     * @return Collection<int, ControlRoomAlert>
     */
    public function alerts(User $user, iterable $alertIds, bool $lockForUpdate = false): Collection
    {
        $ids = collect($alertIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $query = ControlRoomAlert::query()
            ->whereKey($ids)
            ->orderBy('id');
        $this->alertAccess->applyReadableScope($query, $user);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $alerts = $query->get()->keyBy('id');
        abort_if(
            $alerts->count() !== $ids->count(),
            403,
            'You are not authorized to access one or more selected alerts.',
        );

        return $alerts;
    }

    public function task(
        User $user,
        ControlRoomAlert $alert,
        int $taskId,
        bool $lockForUpdate = false,
    ): AlertTask {
        return $this->child($user, $alert, 'tasks', $taskId, $lockForUpdate);
    }

    public function discussion(
        User $user,
        ControlRoomAlert $alert,
        int $discussionId,
        bool $lockForUpdate = false,
    ): AlertDiscussion {
        return $this->child($user, $alert, 'discussions', $discussionId, $lockForUpdate);
    }

    public function evidencePack(
        User $user,
        ControlRoomAlert $alert,
        int $packId,
        bool $lockForUpdate = false,
    ): EvidencePack {
        return $this->child($user, $alert, 'evidencePacks', $packId, $lockForUpdate);
    }

    public function evidenceItem(
        User $user,
        ControlRoomAlert $alert,
        int $packId,
        int $itemId,
        bool $lockForUpdate = false,
    ): EvidenceItem {
        $pack = $this->evidencePack($user, $alert, $packId, $lockForUpdate);
        $query = $pack->evidenceItems()->whereKey($itemId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function watcher(
        User $user,
        ControlRoomAlert $alert,
        int $watcherId,
        bool $lockForUpdate = false,
    ): AlertWatcher {
        return $this->child($user, $alert, 'watchers', $watcherId, $lockForUpdate);
    }

    public function timeEntry(
        User $user,
        ControlRoomAlert $alert,
        int $entryId,
        bool $lockForUpdate = false,
    ): TimeEntry {
        return $this->child($user, $alert, 'timeEntries', $entryId, $lockForUpdate);
    }

    public function playbookRun(
        User $user,
        ControlRoomAlert $alert,
        int $runId,
        bool $lockForUpdate = false,
    ): PlaybookRun {
        $parent = $this->alert($user, $alert, $lockForUpdate);
        $query = $parent->playbookRun()
            ->whereKey($runId)
            ->where('alert_id', $parent->getKey());

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /**
     * @template TChild of Model
     *
     * @param  non-empty-string  $relationship
     * @return TChild
     */
    private function child(
        User $user,
        ControlRoomAlert $alert,
        string $relationship,
        int $childId,
        bool $lockForUpdate,
    ): Model {
        $parent = $this->alert($user, $alert, $lockForUpdate);
        $query = $parent->{$relationship}()->whereKey($childId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
