<?php

namespace App\Services\Incidents;

final class IncidentJourneyReconciliationResult
{
    public const ISSUE_KEYS = [
        'missing_hs',
        'link_mismatch',
        'duplicate_alert',
        'missing_reference',
        'worksafe_drift',
        'missing_site',
        'dismissed_active',
        'acceptance_backfill',
        'ambiguous',
    ];

    /** @var array<string, int> */
    public array $issues;

    /** @var array<string, int> */
    public array $repairs;

    /** @var list<array{incident_id: int, issue: string, detail: string}> */
    public array $details = [];

    /** @var list<array{incident_id: int, error: string}> */
    public array $fatal = [];

    public int $scanned = 0;

    public function __construct(public readonly bool $apply)
    {
        $this->issues = array_fill_keys(self::ISSUE_KEYS, 0);
        $this->repairs = array_fill_keys(self::ISSUE_KEYS, 0);
    }

    public function issue(int $incidentId, string $issue, string $detail): void
    {
        $this->issues[$issue]++;
        $this->details[] = [
            'incident_id' => $incidentId,
            'issue' => $issue,
            'detail' => $detail,
        ];
    }

    public function repaired(string $issue): void
    {
        $this->repairs[$issue]++;
    }

    public function ambiguous(int $incidentId, string $detail): void
    {
        $this->issue($incidentId, 'ambiguous', $detail);
    }

    public function failed(int $incidentId, \Throwable $error): void
    {
        $this->fatal[] = [
            'incident_id' => $incidentId,
            'error' => $error->getMessage(),
        ];
    }

    public function totalIssues(): int
    {
        return array_sum($this->issues);
    }

    public function totalRepairs(): int
    {
        return array_sum($this->repairs);
    }

    public function hasFatalErrors(): bool
    {
        return $this->fatal !== [];
    }
}
