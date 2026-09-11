<?php

use App\Domain\It\Services\ItWorkTaskGraphEvaluator;

function workGraphTask(int $id, string $status = 'pending', array $dependencies = [], ?int $completion = null, ?int $approval = null): array
{
    return ['id' => $id, 'status' => $status, 'dependency_ids' => $dependencies,
        'current_completion_id' => $completion, 'approval_id' => $approval];
}

function workGraphCompletion(int $id, int $taskId, ?array $dependencies = [], ?int $approval = null, string $source = 'command'): array
{
    return ['id' => $id, 'task_id' => $taskId, 'source' => $source,
        'prerequisite_completions' => $dependencies, 'approval_id' => $approval];
}

test('a reopened prerequisite invalidates completed descendants until each is explicitly recompleted', function () {
    $graph = new ItWorkTaskGraphEvaluator;
    $tasks = [workGraphTask(1, 'completed', [], 10), workGraphTask(2, 'completed', [1], 20), workGraphTask(3, 'completed', [2], 30)];
    $records = [workGraphCompletion(10, 1), workGraphCompletion(20, 2, [['task_id' => 1, 'completion_id' => 10]]), workGraphCompletion(30, 3, [['task_id' => 2, 'completion_id' => 20]])];
    $originalRecords = $records;
    expect(array_column($graph->evaluate($tasks, $records, []), 'completion'))->toBe(['valid', 'valid', 'valid']);

    $tasks[0] = workGraphTask(1);
    $verdict = $graph->evaluate($tasks, $records, []);
    expect($verdict[2]['completion'])->toBe('invalid')
        ->and($verdict[3]['completion'])->toBe('invalid')
        ->and(array_column($verdict[3]['blockers'], 'code'))->toContain('dependency_completion_invalid');

    $tasks[0] = workGraphTask(1, 'completed', [], 11);
    $records[] = workGraphCompletion(11, 1);
    expect($graph->evaluate($tasks, $records, [])[2]['completion'])->toBe('invalid');
    $tasks[1] = workGraphTask(2, 'completed', [1], 21);
    $records[] = workGraphCompletion(21, 2, [['task_id' => 1, 'completion_id' => 11]]);
    $verdict = $graph->evaluate($tasks, $records, []);
    expect($verdict[2]['completion'])->toBe('valid')->and($verdict[3]['completion'])->toBe('invalid');
    $tasks[2] = workGraphTask(3, 'completed', [2], 31);
    $records[] = workGraphCompletion(31, 3, [['task_id' => 2, 'completion_id' => 21]]);
    expect(array_column($graph->evaluate($tasks, $records, []), 'completion'))->toBe(['valid', 'valid', 'valid'])
        ->and(array_slice($records, 0, 3))->toBe($originalRecords)
        ->and($graph->affectedDescendants($tasks, 1))->toBe([2, 3]);
});

test('cancelled and unavailable prerequisites block work without returning foreign corrective identifiers', function () {
    $verdict = (new ItWorkTaskGraphEvaluator)->evaluate([workGraphTask(1, 'cancelled'), workGraphTask(2, dependencies: [1, 999])], [], []);
    expect($verdict[2]['prerequisites'])->toBe('blocked')
        ->and($verdict[2]['blockers'])->toContain(['code' => 'dependency_cancelled', 'task_id' => 1, 'approval_id' => null])
        ->and($verdict[2]['blockers'])->toContain(['code' => 'dependency_unavailable', 'task_id' => null, 'approval_id' => null])
        ->and(json_encode($verdict))->not->toContain('999');
});

test('cycles and their descendants are blocked without recursion or changes to stored status', function () {
    $tasks = [workGraphTask(1, dependencies: [2]), workGraphTask(2, dependencies: [1]), workGraphTask(3, dependencies: [2]), workGraphTask(4)];
    $graph = new ItWorkTaskGraphEvaluator;
    $verdict = $graph->evaluate($tasks, [], []);
    expect(array_column($verdict, 'prerequisites'))->toBe(['blocked', 'blocked', 'blocked', 'ready'])
        ->and($graph->affectedDescendants($tasks, 1))->toBe([2, 3])
        ->and(array_column($tasks, 'status'))->toBe(['pending', 'pending', 'pending', 'pending']);
});

test('unknown legacy provenance remains distinct from a known empty completion history', function () {
    $tasks = [workGraphTask(1, 'completed'), workGraphTask(2, 'completed', [], 20), workGraphTask(3, 'completed', [], 30), workGraphTask(4, dependencies: [1])];
    $records = [workGraphCompletion(20, 2, null, source: 'legacy_snapshot'), workGraphCompletion(30, 3)];
    $verdict = (new ItWorkTaskGraphEvaluator)->evaluate($tasks, $records, []);
    expect(array_column($verdict, 'completion'))->toBe(['unknown', 'unknown', 'valid', 'none'])
        ->and($verdict[4]['prerequisites'])->toBe('unknown')
        ->and($verdict[1]['blockers'])->toBe([])
        ->and($tasks[0]['current_completion_id'])->toBeNull()
        ->and($records[0]['prerequisite_completions'])->toBeNull();
});

test('only the exact linked approval request can unlock a task', function (string $state, string $expected) {
    $verdict = (new ItWorkTaskGraphEvaluator)->evaluate([workGraphTask(1, approval: 7)], [], [['id' => 6, 'status' => 'approved'], ['id' => 7, 'status' => $state]]);
    expect($verdict[1]['prerequisites'])->toBe($expected);
    if ($expected === 'blocked') {
        expect($verdict[1]['blockers'][0]['approval_id'])->toBe(7);
    }
})->with([['pending', 'blocked'], ['rejected', 'blocked'], ['expired', 'blocked'], ['cancelled', 'blocked'], ['approved', 'ready']]);

test('cross-task completion pointers and replaced prerequisite or approval bindings cannot validate old work', function () {
    $graph = new ItWorkTaskGraphEvaluator;
    $records = [workGraphCompletion(10, 1), workGraphCompletion(20, 2, [], 5)];
    expect($graph->evaluate([workGraphTask(2, 'completed', [], 10)], $records, [])[2]['completion'])->toBe('invalid');
    $verdict = $graph->evaluate([workGraphTask(1, 'completed', [], 10), workGraphTask(2, 'completed', [1], 20, 6)], $records, [['id' => 6, 'status' => 'approved']]);
    expect($verdict[2]['completion'])->toBe('invalid')
        ->and(array_column($verdict[2]['blockers'], 'code'))->toContain('dependency_definition_changed', 'approval_changed');
});

test('long dependency chains are evaluated iteratively and unrelated pending optional work does not contaminate completion verdicts', function () {
    $tasks = [];
    $records = [];
    for ($id = 1; $id <= 1000; $id++) {
        $tasks[] = workGraphTask($id, 'completed', $id === 1 ? [] : [$id - 1], $id);
        $records[] = workGraphCompletion($id, $id, $id === 1 ? [] : [['task_id' => $id - 1, 'completion_id' => $id - 1]]);
    }
    $tasks[] = workGraphTask(1001);
    $verdict = (new ItWorkTaskGraphEvaluator)->evaluate($tasks, $records, []);
    expect($verdict[1000]['completion'])->toBe('valid')->and($verdict[1001]['completion'])->toBe('none');
});
