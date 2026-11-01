<?php

namespace Oblivion\Collector\Runtime;

use DateTimeImmutable;
use Oblivion\Collector\Exceptions\ScopeViolation;
use Oblivion\Collector\Spool\EncryptedSpool;

final readonly class CollectorCommandExecutor
{
    public function __construct(
        private CommandJournal $journal,
        private UnifiAccessCommandRunner $runner,
        private ?SshConfigBackupRunner $sshBackups = null,
        private ?CameraSnapshotRunner $cameraSnapshots = null,
        private ?AlarmAreaCommandRunner $alarmAreas = null,
    ) {}

    /** @param array<string, mixed> $command */
    public function execute(array $command, EncryptedSpool $spool, ?DateTimeImmutable $at = null): bool
    {
        $at ??= new DateTimeImmutable('now');
        $runner = $this->runnerFor($command);
        $entry = $this->journal->begin($command);
        if ($entry['state'] === 'complete') {
            return false;
        }
        if ($entry['state'] === 'result_ready') {
            $result = $entry['result'];
        } elseif ($entry['created']) {
            $result = $runner->run($command, $at);
            $this->journal->resultReady($command, $result);
        } else {
            $result = $runner->interrupted($command, $at);
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

    /** @param array<string, mixed> $command */
    private function runnerFor(array $command): UnifiAccessCommandRunner|SshConfigBackupRunner|CameraSnapshotRunner|AlarmAreaCommandRunner
    {
        return match ($command['adapter'] ?? null) {
            'unifi_access_timed_unlock_v1' => $this->runner,
            'ssh_config_backup_v1' => $this->sshBackups ?? throw new ScopeViolation('This hub build cannot run settings backups.'),
            'camera_snapshot_v1' => $this->cameraSnapshots ?? throw new ScopeViolation('This hub build cannot take camera pictures.'),
            'alarm_area_control_v1' => $this->alarmAreas ?? throw new ScopeViolation('This hub build cannot arm or disarm alarms.'),
            default => throw new ScopeViolation('Command adapter is not supported by this hub.'),
        };
    }
}
