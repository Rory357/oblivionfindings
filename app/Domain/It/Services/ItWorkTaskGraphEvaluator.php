<?php

namespace App\Domain\It\Services;

/**
 * Pure current-generation evaluation of one already-authorized ticket graph.
 * Unknown historical provenance is a separate result, never an invented pass
 * or a policy decision to invalidate old work. This class performs no writes.
 */
final class ItWorkTaskGraphEvaluator
{
    /**
     * @param  array<int, array{id:int,status:string,dependency_ids:list<int>,current_completion_id:?int,approval_id:?int}>  $tasks
     * @param  array<int, array{id:int,task_id:int,source:string,prerequisite_completions:?array,approval_id:?int}>  $completions
     * @param  array<int, array{id:int,status:string}>  $approvals  Exact same-ticket request generations only.
     * @return array<int, array{prerequisites:string,completion:string,blockers:array,warnings:array}>
     */
    public function evaluate(array $tasks, array $completions, array $approvals): array
    {
        $tasks = array_column($tasks, null, 'id');
        $completions = array_column($completions, null, 'id');
        $approvals = array_column($approvals, null, 'id');
        $remaining = [];
        $dependents = [];
        $ready = [];
        foreach ($tasks as $id => $task) {
            $remaining[$id] = 0;
            foreach (array_unique($task['dependency_ids']) as $dependencyId) {
                if (isset($tasks[$dependencyId])) {
                    $remaining[$id]++;
                    $dependents[$dependencyId][] = $id;
                }
            }
            if ($remaining[$id] === 0) {
                $ready[] = $id;
            }
        }

        $verdicts = [];
        // Iterative topological evaluation avoids stack growth on long chains.
        for ($index = 0; $index < count($ready); $index++) {
            $id = $ready[$index];
            $verdicts[$id] = $this->evaluateTask($tasks[$id], $tasks, $completions, $approvals, $verdicts);
            foreach ($dependents[$id] ?? [] as $dependentId) {
                if (--$remaining[$dependentId] === 0) {
                    $ready[] = $dependentId;
                }
            }
        }
        foreach ($tasks as $id => $task) {
            if (! isset($verdicts[$id])) {
                $verdicts[$id] = [
                    'prerequisites' => 'blocked',
                    'completion' => $task['status'] === 'completed' ? 'invalid' : 'none',
                    'blockers' => [$this->issue('dependency_cycle', $id)],
                    'warnings' => [],
                ];
            }
        }
        ksort($verdicts);

        return $verdicts;
    }

    /** @param array<int, array{id:int,dependency_ids:list<int>}> $tasks @return list<int> */
    public function affectedDescendants(array $tasks, int $taskId): array
    {
        $dependents = [];
        foreach ($tasks as $task) {
            foreach ($task['dependency_ids'] as $dependencyId) {
                $dependents[$dependencyId][] = $task['id'];
            }
        }
        $visited = [$taskId => true];
        $pending = [$taskId];
        $result = [];
        for ($index = 0; $index < count($pending); $index++) {
            foreach ($dependents[$pending[$index]] ?? [] as $id) {
                if (! isset($visited[$id])) {
                    $visited[$id] = true;
                    $pending[] = $id;
                    $result[] = $id;
                }
            }
        }
        sort($result);

        return $result;
    }

    private function evaluateTask(array $task, array $tasks, array $completions, array $approvals, array $verdicts): array
    {
        $blockers = [];
        $warnings = [];
        foreach (array_unique($task['dependency_ids']) as $id) {
            if (! isset($tasks[$id])) {
                // Never return a corrective identifier for an unavailable record.
                $blockers[] = $this->issue('dependency_unavailable');
            } elseif ($tasks[$id]['status'] !== 'completed') {
                $blockers[] = $this->issue($tasks[$id]['status'] === 'cancelled' ? 'dependency_cancelled' : 'dependency_incomplete', $id);
            } elseif ($verdicts[$id]['completion'] === 'invalid') {
                $blockers[] = $this->issue('dependency_completion_invalid', $id);
            } elseif ($verdicts[$id]['completion'] === 'unknown') {
                $warnings[] = $this->issue('dependency_history_unknown', $id);
            }
        }
        if ($task['approval_id'] !== null) {
            $approval = $approvals[$task['approval_id']] ?? null;
            if ($approval === null) {
                $blockers[] = $this->issue('approval_unavailable');
            } elseif ($approval['status'] !== 'approved') {
                $code = in_array($approval['status'], ['pending', 'rejected', 'expired', 'cancelled'], true)
                    ? 'approval_'.$approval['status'] : 'approval_unavailable';
                $blockers[] = $this->issue($code, approvalId: $approval['id']);
            }
        }

        $prerequisites = $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'unknown' : 'ready');
        $completion = 'none';
        if ($task['status'] === 'completed') {
            $record = $task['current_completion_id'] === null ? null : ($completions[$task['current_completion_id']] ?? null);
            if ($task['current_completion_id'] === null) {
                $warnings[] = $this->issue('completion_history_unknown', $task['id']);
            } elseif ($record === null || $record['task_id'] !== $task['id']) {
                $blockers[] = $this->issue('completion_unavailable', $task['id']);
            } elseif ($record['source'] === 'legacy_snapshot') {
                $warnings[] = $this->issue('completion_history_unknown', $task['id']);
            } elseif ($record['source'] !== 'command' || ! is_array($record['prerequisite_completions'])) {
                $blockers[] = $this->issue('completion_provenance_invalid', $task['id']);
            } else {
                $bindings = [];
                foreach ($record['prerequisite_completions'] as $binding) {
                    if (! is_array($binding) || ! is_int($binding['task_id'] ?? null)
                        || ! is_int($binding['completion_id'] ?? null) || $binding['completion_id'] < 1
                        || isset($bindings[$binding['task_id']])) {
                        $blockers[] = $this->issue('completion_provenance_invalid', $task['id']);

                        continue;
                    }
                    $bindings[$binding['task_id']] = $binding['completion_id'];
                }
                $expectedIds = array_values(array_unique($task['dependency_ids']));
                $recordedIds = array_keys($bindings);
                sort($expectedIds);
                sort($recordedIds);
                if ($expectedIds !== $recordedIds) {
                    $blockers[] = $this->issue('dependency_definition_changed', $task['id']);
                }
                foreach ($bindings as $dependencyId => $completionId) {
                    $dependency = $tasks[$dependencyId] ?? null;
                    $prior = $completions[$completionId] ?? null;
                    if ($dependency === null || $prior === null || $prior['task_id'] !== $dependencyId
                        || $dependency['current_completion_id'] !== $completionId) {
                        $blockers[] = $this->issue('dependency_completion_changed', $dependency === null ? null : $dependencyId);
                    }
                }
                if ($record['approval_id'] !== $task['approval_id']) {
                    $blockers[] = $this->issue('approval_changed', $task['id']);
                }
            }
            $completion = $blockers !== [] ? 'invalid' : ($warnings !== [] ? 'unknown' : 'valid');
        }

        return ['prerequisites' => $prerequisites, 'completion' => $completion,
            'blockers' => $this->uniqueIssues($blockers), 'warnings' => $this->uniqueIssues($warnings)];
    }

    private function issue(string $code, ?int $taskId = null, ?int $approvalId = null): array
    {
        return ['code' => $code, 'task_id' => $taskId, 'approval_id' => $approvalId];
    }

    private function uniqueIssues(array $issues): array
    {
        $result = [];
        foreach ($issues as $issue) {
            $result[$issue['code'].':'.$issue['task_id'].':'.$issue['approval_id']] = $issue;
        }

        return array_values($result);
    }
}
