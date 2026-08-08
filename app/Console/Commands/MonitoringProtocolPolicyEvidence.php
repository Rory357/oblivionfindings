<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Services\ProtocolPolicyEvidenceService;
use Illuminate\Console\Command;

final class MonitoringProtocolPolicyEvidence extends Command
{
    protected $signature = 'monitoring:protocol-policy-evidence
        {--window-minutes=60 : Bounded live evidence window from 5 to 10080 minutes}
        {--json : Emit one value-free JSON report}';

    protected $description = 'Read-only proof of live monitoring protocol and policy behavior evidence';

    public function handle(ProtocolPolicyEvidenceService $evidence): int
    {
        $window = filter_var($this->option('window-minutes'), FILTER_VALIDATE_INT);
        if ($window === false || $window < 5 || $window > 10_080) {
            $this->error('window-minutes must be an integer from 5 to 10080.');

            return self::INVALID;
        }

        $report = $evidence->report((int) $window);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $rows = [];
            foreach (['protocols', 'policy'] as $group) {
                foreach ($report[$group] as $name => $result) {
                    $rows[] = [$group, $name, $result['state']];
                }
            }
            $this->table(['Group', 'Evidence', 'State'], $rows);
            $this->line($report['all_verified']
                ? 'Every required protocol and policy evidence check is verified.'
                : 'One or more protocol or policy evidence checks are not verified.');
        }

        return $report['all_verified'] ? self::SUCCESS : self::FAILURE;
    }
}
