<?php

namespace App\Console\Commands;

use App\Support\Release\ItSecurityDesktopReleaseFixtureManager;
use App\Support\Release\ItSecurityDesktopReleaseFixtureMutationGuard;
use Illuminate\Console\Command;
use Throwable;

final class ManageItSecurityDesktopReleaseFixtures extends Command
{
    protected $signature = 'it-security:desktop-release-fixtures
        {action : prepare, cleanup, reset, or withdraw-tracking-consent}
        {--revision= : Exact 40-character origin/main release revision}
        {--confirm= : Exact action-and-revision confirmation token}
        {--execute : Apply the planned database mutation}
        {--json : Emit one value-free JSON object}';

    protected $description = 'Plan or manage the owned non-production IT/Security desktop release fixture pack';

    public function handle(
        ItSecurityDesktopReleaseFixtureMutationGuard $guard,
        ItSecurityDesktopReleaseFixtureManager $manager,
    ): int {
        $action = (string) $this->argument('action');
        $revision = (string) $this->option('revision');
        $confirmation = (string) $this->option('confirm');
        $guardReport = $guard->assess($action, $revision, $confirmation);

        if (! ($guardReport['fixture_write_authorized'] ?? false)) {
            return $this->finish([
                ...$guardReport,
                'mode' => $this->option('execute') ? 'execute' : 'dry_run',
                'fixture_mutation_applied' => false,
            ], self::FAILURE);
        }

        try {
            $report = $this->option('execute')
                ? $manager->execute($action, $revision)
                : $manager->plan($action, $revision);
        } catch (Throwable) {
            return $this->finish([
                'schema_version' => 1,
                'evidence_class' => 'it_security_desktop_release_fixture_management_v1',
                'state' => 'failed',
                'action' => $action,
                'release_revision' => preg_match('/\A[0-9a-f]{40}\z/', $revision) === 1 ? $revision : null,
                'mode' => $this->option('execute') ? 'execute' : 'dry_run',
                'gap_codes' => ['release_fixture_management_failed'],
                'fixture_mutation_applied' => false,
                'v10_release_evidence' => false,
            ], self::FAILURE);
        }

        return $this->finish($report, ($report['state'] ?? null) === 'ready' ? self::SUCCESS : self::FAILURE);
    }

    /** @param array<string, mixed> $report */
    private function finish(array $report, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('State', (string) ($report['state'] ?? 'failed'));
            $this->components->twoColumnDetail('Mode', (string) ($report['mode'] ?? 'unknown'));
            $this->components->twoColumnDetail('Action', (string) ($report['action'] ?? 'unknown'));
            foreach ((array) ($report['gap_codes'] ?? []) as $gap) {
                $this->components->warn((string) $gap);
            }
        }

        return $exitCode;
    }
}
