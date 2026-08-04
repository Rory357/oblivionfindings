<?php

namespace Oblivion\Collector\Runtime;

use DateTimeImmutable;
use Oblivion\Collector\Spool\EncryptedSpool;

final readonly class CollectorCommandExecutor
{
    public function __construct(
        private CommandJournal $journal,
        private UnifiAccessCommandRunner $runner,
    ) {}

    /** @param array<string, mixed> $command */
    public function execute(array $command, EncryptedSpool $spool, ?DateTimeImmutable $at = null): bool
    {
        $at ??= new DateTimeImmutable('now');
        $entry = $this->journal->begin($command);
        if ($entry['state'] === 'complete') {
            return false;
        }
        if ($entry['state'] === 'result_ready') {
            $result = $entry['result'];
        } elseif ($entry['created']) {
            $result = $this->runner->run($command, $at);
            $this->journal->resultReady($command, $result);
        } else {
            $result = $this->runner->interrupted($command, $at);
            $this->journal->resultReady($command, $result);
        }

        $itemId = 'command-result:'.strtolower((string) $command['attempt_uuid']);
        $appended = $spool->append(
            $itemId,
            $spool->nextSourceSequence(),
            $result,
            new DateTimeImmutable((string) $result['completed_at']),
        );
        $this->journal->complete($command);

        return $appended;
    }
}
