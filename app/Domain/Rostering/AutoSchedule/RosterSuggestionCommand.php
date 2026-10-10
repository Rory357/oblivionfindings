<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Domain\Hr\Services\AttendanceTimeEntryProjector;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One-request current command boundary; UUID is correlation, never idempotence. */
class RosterSuggestionCommand
{
    public function __construct(private readonly RosterSuggestionEvidence $evidence, private readonly RosterSuggestionApplier $applier, private readonly RosterSuggestionReceipt $receipts) {}

    public function execute(Request $request, string $action, RosterSuggestion|RosterSuggestionRun $bound): array
    {
        $root = $this->receipts->begin($request);
        $actor = $request->user();
        abort_unless($actor, 403);
        $modern = $request->header('X-Roster-Suggestion-Result') === 'committed-v1';
        [$expected, $requestId] = $this->requestIntent($request, $bound instanceof RosterSuggestion, $modern);
        try {
            if (in_array($action, ['accept', 'dismiss'], true) && $bound instanceof RosterSuggestion) {
                $result = $this->decide($action, $bound, $actor, $expected, $requestId);
            } elseif ($action === 'apply' && $bound instanceof RosterSuggestion) {
                $runHint = $this->runHint($bound);
                $currentActor = null;
                $source = null;
                $result = null;
                $this->applier->applyOne($bound, $actor,
                    beforeApply: function ($row, $run, $lockedActor) use ($runHint, $expected, &$currentActor, &$source): void {
                        abort_unless((int) $run->site_id === (int) $runHint->getRawOriginal('site_id'), 409, 'This suggestion run changed Site. Reload before acting.');
                        $currentActor = $this->evidence->assertRun($run, $lockedActor);
                        $source = RosterSuggestionSource::single($run, $row, $row->getRelation('shift'));
                        $this->evidence->assertExpected($expected, $source);
                    },
                    capture: function ($row) use ($requestId, &$result, &$currentActor, &$source): void {
                        $run = $row->getRelation('run');
                        $result = new RosterSuggestionCommandResult('apply', (int) $currentActor->id, (int) $run->id, (int) $run->site_id,
                            (int) $row->id, $requestId, $source, 'applied', true, 'single',
                            ['selected' => 1, 'applied' => 1, 'stale' => 0, 'failed' => 0], RosterSuggestionSource::suggestion($row),
                            [RosterSuggestionSource::assignment($row)], $row);
                    });
                if (! $result) {
                    throw new \LogicException('The applied suggestion did not return current persisted evidence.');
                }
            } elseif ($action === 'apply_accepted' && $bound instanceof RosterSuggestionRun) {
                $result = null;
                $currentActor = null;
                $source = null;
                $this->applier->applyAccepted($bound, $actor,
                    beforeApply: function ($run, $lockedActor) use ($expected, &$currentActor, &$source): void {
                        $currentActor = $this->evidence->assertRun($run, $lockedActor);
                        $source = ['run_id' => (int) $run->id, 'site_id' => (int) $run->site_id];
                        $this->evidence->assertExpected($expected, $source);
                    },
                    capture: function ($run, Collection $selected, Collection $stored, array $counts) use ($requestId, &$result, &$currentActor, &$source): void {
                        $applied = $stored->count();
                        if ($counts['applied'] !== $applied || ($applied > 0 && $applied !== $selected->count())) {
                            throw new \LogicException('Applied suggestion counts do not match persisted assignments.');
                        }
                        $disposition = $applied > 0 ? 'applied' : ($selected->isEmpty() ? 'empty' : 'preflight_no_change');
                        $result = new RosterSuggestionCommandResult('apply_accepted', (int) $currentActor->id,
                            (int) $run->id, (int) $run->site_id, null, $requestId, $source,
                            $applied > 0 ? 'applied' : ($selected->isEmpty() ? 'unchanged' : 'not_applied'), $applied > 0, $disposition,
                            ['selected' => $selected->count(), ...$counts], null,
                            $stored->map(fn ($row) => RosterSuggestionSource::assignment($row))->values()->all());
                    });
                if (! $result) {
                    throw new \LogicException('The accepted run did not return current persisted evidence.');
                }
            } else {
                throw new \LogicException('Unsupported suggestion command.');
            }
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            throw ValidationException::withMessages(['suggestion' => 'This suggestion is being updated. Reload before trying again.']);
        }

        return ['result' => $result, 'receipt' => $this->receipts->committed($root, $result), 'modern' => $modern];
    }

    /** Shared by the public accept/dismiss service, with no HTTP dependency. */
    public function decide(string $action, RosterSuggestion $bound, User $actor, ?array $expected = null, ?string $requestId = null): RosterSuggestionCommandResult
    {
        if (! in_array($action, ['accept', 'dismiss'], true)) {
            throw new \LogicException('Unsupported planning decision.');
        }
        $runHint = $this->runHint($bound);

        return DB::transaction(function () use ($action, $bound, $runHint, $actor, $expected, $requestId): RosterSuggestionCommandResult {
            app(AttendanceTimeEntryProjector::class)->lockApplicationPayrollMutex();
            $inputs = $this->evidence->lockSingle($bound, $runHint, $actor);
            $row = $inputs['suggestion'];
            $run = $inputs['run'];
            $current = $inputs['actor'];
            $source = RosterSuggestionSource::single($run, $row, $inputs['shift']);
            $this->evidence->assertExpected($expected, $source);
            $expired = $action === 'accept' && $run->isExpired();
            $attributes = $expired ? ['status' => RosterSuggestion::STATUS_STALE]
                : ($action === 'accept' ? ['status' => RosterSuggestion::STATUS_ACCEPTED, 'accepted_by' => $current->id,
                    'accepted_at' => now()->startOfSecond(), 'dismissed_by' => null, 'dismissed_at' => null]
                    : ['status' => RosterSuggestion::STATUS_DISMISSED, 'dismissed_by' => $current->id, 'dismissed_at' => now()->startOfSecond()]);
            $before = $row->getRawOriginal();
            $rowId = (int) $row->id;
            if ($row->forceFill($attributes)->save() !== true) {
                throw ValidationException::withMessages(['suggestion' => 'This planning decision could not be saved.']);
            }
            $stored = RosterSuggestion::query()->whereKey($rowId)->lockForUpdate()->firstOrFail();
            if ((int) $row->id !== $rowId) {
                throw ValidationException::withMessages(['suggestion' => 'The saved planning decision changed identity.']);
            }
            foreach ($row->getFillable() as $field) {
                if (array_key_exists($field, $attributes)) {
                    $intended = $attributes[$field];
                    $actual = $stored->getAttribute($field);
                    $same = str_ends_with($field, '_at') ? ($intended === null ? $actual === null : $actual?->equalTo($intended))
                        : (str_ends_with($field, '_by') ? RosterSuggestionSource::id($actual) === RosterSuggestionSource::id($intended) : $actual === $intended);
                } else {
                    $same = $stored->getRawOriginal($field) === ($before[$field] ?? null);
                }
                if (! $same) {
                    throw ValidationException::withMessages(['suggestion' => 'The saved planning decision did not match this command.']);
                }
            }
            $changed = collect($row->getFillable())->contains(fn ($field) => $stored->getRawOriginal($field) !== ($before[$field] ?? null));
            $stored->setRelation('run', $run)->setRelation('shift', $inputs['shift']);

            return new RosterSuggestionCommandResult($action, (int) $current->id, (int) $run->id, (int) $run->site_id,
                (int) $stored->id, $requestId, $source,
                $expired ? 'expired_marked_stale' : ($changed ? ($action === 'accept' ? 'accepted' : 'dismissed') : 'unchanged'),
                $changed, $expired ? 'expired' : 'single', ['selected' => 1, 'applied' => 0, 'stale' => $expired ? 1 : 0, 'failed' => 0],
                RosterSuggestionSource::suggestion($stored), model: $stored);
        });
    }

    private function runHint(RosterSuggestion $bound): RosterSuggestionRun
    {
        return $bound->relationLoaded('run') && $bound->run instanceof RosterSuggestionRun
            ? $bound->run : RosterSuggestionRun::query()->findOrFail($bound->getRawOriginal('roster_suggestion_run_id'));
    }

    private function requestIntent(Request $request, bool $single, bool $modern): array
    {
        if (! $modern && ! $request->exists('expected_source')) {
            return [null, null];
        }
        $keys = $single ? 'run_id,site_id,suggestion_id,shift_id,candidate_user_id,status,source_revision' : 'run_id,site_id';
        $rules = ['expected_source' => ['required', 'array:'.$keys],
            'expected_source.run_id' => ['required', 'integer', 'min:1'], 'expected_source.site_id' => ['required', 'integer', 'min:1']];
        if ($single) {
            $rules += ['expected_source.suggestion_id' => ['required', 'integer', 'min:1'],
                'expected_source.shift_id' => ['required', 'integer', 'min:1'],
                'expected_source.candidate_user_id' => ['present', 'nullable', 'integer', 'min:1'],
                'expected_source.status' => ['required', 'string', 'max:40'],
                'expected_source.source_revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']];
        }
        if ($modern) {
            $rules['request_id'] = ['required', 'uuid'];
        }
        $data = $request->validate($rules);
        $source = $data['expected_source'];
        $expected = ['run_id' => (int) $source['run_id'], 'site_id' => (int) $source['site_id']];
        if ($single) {
            $expected += ['suggestion_id' => (int) $source['suggestion_id'], 'shift_id' => (int) $source['shift_id'],
                'candidate_user_id' => RosterSuggestionSource::id($source['candidate_user_id']), 'status' => $source['status'],
                'source_revision' => $source['source_revision']];
        }

        return [$expected, $modern ? $data['request_id'] : null];
    }
}
