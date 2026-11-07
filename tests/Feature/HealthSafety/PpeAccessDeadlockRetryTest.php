<?php

namespace Tests\Feature\HealthSafety;

use App\Services\HealthSafety\PpeAccessService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Audit of Gemini's PPE retry (5 Nov): a command may re-run after a deadlock only when it opts in (no files written
 * inside its transaction), only at the outermost level, and never after a lock-wait timeout.
 */
final class PpeAccessDeadlockRetryTest extends PpeCommandTestCase
{
    // The retry happens only when no outer transaction is open, so these tests commit like production does.
    protected bool $physicalCommits = true;

    public function test_an_opted_in_command_re_runs_once_after_a_deadlock(): void
    {
        $calls = 0;
        $result = app(PpeAccessService::class)->withCurrentViewer($this->manager, function () use (&$calls) {
            if (++$calls === 1) {
                throw $this->databaseFailure(1213, '40001', 'Deadlock found when trying to get lock');
            }

            return 'saved';
        }, true);

        $this->assertSame('saved', $result);
        $this->assertSame(2, $calls);
    }

    public function test_a_command_that_did_not_opt_in_is_not_re_run(): void
    {
        $this->assertNotRetried(1213, '40001', false);
    }

    public function test_a_lock_wait_timeout_is_never_re_run(): void
    {
        $this->assertNotRetried(1205, 'HY000', true);
    }

    public function test_a_deadlock_inside_an_outer_transaction_is_not_re_run(): void
    {
        $calls = 0;
        try {
            DB::transaction(function () use (&$calls) {
                return app(PpeAccessService::class)->withCurrentViewer($this->manager, function () use (&$calls) {
                    $calls++;
                    throw $this->databaseFailure(1213, '40001', 'Deadlock found when trying to get lock');
                }, true);
            });
            $this->fail('The deadlock must reach the outer transaction.');
        } catch (DeadlockException $failure) {
            // Laravel hands a nested deadlock to the outer transaction as a DeadlockException.
            $this->assertStringContainsString('Deadlock found', $failure->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    private function assertNotRetried(int $code, string $state, bool $optIn): void
    {
        $calls = 0;
        try {
            app(PpeAccessService::class)->withCurrentViewer($this->manager, function () use (&$calls, $code, $state) {
                $calls++;
                throw $this->databaseFailure($code, $state, 'Simulated database failure');
            }, $optIn);
            $this->fail('The database failure must be reported.');
        } catch (QueryException $failure) {
            $this->assertSame($code, $failure->errorInfo[1]);
        }
        $this->assertSame(1, $calls);
    }

    private function databaseFailure(int $code, string $state, string $message): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$state}]: {$message}");
        $pdo->errorInfo = [$state, $code, $message];

        return new QueryException('mysql', 'select 1', [], $pdo);
    }
}
