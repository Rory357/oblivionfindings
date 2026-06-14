<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyFinanceChart extends Command
{
    protected $signature = 'finance:verify-chart
        {organization_id? : Organization ID whose chart should be checked. Defaults to the FinanceSeeder chart org 0.}';

    protected $description = 'Verify that every GL account code referenced by finance config exists and is active in the chart of accounts.';

    public function handle(): int
    {
        $organizationId = $this->resolveOrganizationId();
        if ($organizationId === null) {
            return self::INVALID;
        }

        $references = $this->financeConfigAccountCodeReferences();
        $requiredCodes = array_keys($references);

        /** @var array<string, string> $nameAssertions code => required name keyword */
        $nameAssertions = config('finance.account_names', []);

        $lookupCodes = array_values(array_unique(array_merge($requiredCodes, array_keys($nameAssertions))));

        $accounts = DB::table('fin_accounts')
            ->where('organization_id', $organizationId)
            ->whereIn('code', $lookupCodes)
            ->whereNull('deleted_at')
            ->get(['code', 'name', 'is_active'])
            ->keyBy('code');

        $missingCodes = array_values(array_diff($requiredCodes, $accounts->keys()->all()));
        $inactiveCodes = collect($requiredCodes)
            ->filter(fn (string $code): bool => isset($accounts[$code]) && ! (bool) $accounts[$code]->is_active)
            ->values()
            ->all();

        // Name parity: a seeded account whose name contradicts the configured
        // intent silently mis-posts (e.g. leave_provision debiting an account
        // named "ACC Employer Levy"). Only checked for codes that exist.
        $nameMismatches = [];
        foreach ($nameAssertions as $code => $keyword) {
            $code = (string) $code;
            if (! isset($accounts[$code])) {
                continue;
            }
            if (stripos((string) $accounts[$code]->name, (string) $keyword) === false) {
                $nameMismatches[$code] = [$accounts[$code]->name, $keyword];
            }
        }

        $this->line("Finance chart check for organization #{$organizationId}");
        $this->line(sprintf('Finance config account codes checked: %d', count($requiredCodes)));
        $this->line(sprintf('Account name assertions checked: %d', count($nameAssertions)));

        if ($missingCodes !== [] || $inactiveCodes !== [] || $nameMismatches !== []) {
            if ($missingCodes !== []) {
                $this->error('Missing required finance GL accounts:');
                $this->table(
                    ['Code', 'Config references'],
                    $this->rowsForCodes($missingCodes, $references),
                );
            }

            if ($inactiveCodes !== []) {
                $this->error('Inactive finance GL accounts referenced by config:');
                $this->table(
                    ['Code', 'Config references'],
                    $this->rowsForCodes($inactiveCodes, $references),
                );
            }

            if ($nameMismatches !== []) {
                $this->error('Finance GL account names contradict their configured intent:');
                $this->table(
                    ['Code', 'Seeded name', 'Expected to contain'],
                    array_map(
                        fn (string $code): array => [$code, $nameMismatches[$code][0], $nameMismatches[$code][1]],
                        array_keys($nameMismatches),
                    ),
                );
            }

            return self::FAILURE;
        }

        $this->info('Finance chart verified: every finance config account code exists, is active, and is named as intended.');

        return self::SUCCESS;
    }

    private function resolveOrganizationId(): ?int
    {
        $argument = $this->argument('organization_id');

        if ($argument === null || $argument === '') {
            return 0;
        }

        if (! ctype_digit((string) $argument)) {
            $this->error('The organization_id argument must be a non-negative integer.');

            return null;
        }

        return (int) $argument;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function financeConfigAccountCodeReferences(): array
    {
        $references = [];

        $this->collectAccountCodeReferences(config('finance', []), 'finance', $references);

        ksort($references, SORT_NATURAL);

        return $references;
    }

    /**
     * @param  array<string, array<int, string>>  $references
     */
    private function collectAccountCodeReferences(mixed $value, string $path, array &$references): void
    {
        if (is_string($value) && preg_match('/^\d{3,6}$/', $value) === 1) {
            $references[$value][] = $path;

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $childValue) {
            $this->collectAccountCodeReferences($childValue, "{$path}.{$key}", $references);
        }
    }

    /**
     * @param  array<int, string>  $codes
     * @param  array<string, array<int, string>>  $references
     * @return array<int, array{string, string}>
     */
    private function rowsForCodes(array $codes, array $references): array
    {
        sort($codes, SORT_NATURAL);

        return array_map(
            fn (string $code): array => [$code, implode(', ', $references[$code] ?? [])],
            $codes,
        );
    }
}
