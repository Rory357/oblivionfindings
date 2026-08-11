<?php

namespace App\Console\Commands;

use App\Support\Release\ItSecurityDesktopReleaseFixtureReadiness;
use Illuminate\Console\Command;

final class VerifyItSecurityDesktopReleaseFixtures extends Command
{
    protected $signature = 'it-security:verify-desktop-release-fixtures
        {--json : Emit the value-free machine-readable readiness report}';

    protected $description = 'Verify that the fixed non-Admin actors and canonical desktop release fixtures are ready.';

    public function handle(ItSecurityDesktopReleaseFixtureReadiness $readiness): int
    {
        $report = $readiness->assess(requireRuntimePack: true);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info('IT & Security desktop release fixture readiness: '.$report['state']);
            foreach ($report['sections'] as $name => $section) {
                $this->line(sprintf(
                    '%s: %d/%d ready',
                    str_replace('_', ' ', (string) $name),
                    $section['ready'],
                    $section['required'],
                ));
            }
            foreach ($report['gap_codes'] as $gapCode) {
                $this->warn((string) $gapCode);
            }
        }

        return $report['state'] === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
