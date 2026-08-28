<?php

it('registers bounded medication idempotency pruning with distributed schedule guards', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $schedule = (string) file_get_contents($root.'/routes/console.php');
    $model = (string) file_get_contents($root.'/app/Models/MedicationIdempotencyResult.php');

    $command = "->command('model:prune', ['--model' => MedicationIdempotencyResult::class])";
    $commandOffset = strpos($schedule, $command);

    expect($commandOffset)->not->toBeFalse()
        ->and(substr_count($schedule, $command))->toBe(1)
        ->and($model)->toContain(
            'use Prunable;',
            "where('expires_at', '<=', now())",
        );

    $event = substr($schedule, (int) $commandOffset, strpos($schedule, ';', (int) $commandOffset) - (int) $commandOffset);

    expect($event)->toContain(
        "->timezone('Pacific/Auckland')",
        "->dailyAt('02:35')",
        '->onOneServer()',
        '->withoutOverlapping()',
        "->name('medication.idempotency.prune')",
    );
});
